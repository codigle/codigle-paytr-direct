<?php

declare(strict_types=1);

namespace Codigle\PaytrDirect\Subscription;

use Codigle\PaytrDirect\Database\Repository;
use Codigle\PaytrDirect\Paytr\CapiClient;
use Codigle\PaytrDirect\Paytr\RecurringClient;
use Codigle\PaytrDirect\Support\Config;
use RuntimeException;
use Throwable;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use WP_Error;

final class UpgradeService
{
    public function __construct(
        private readonly Config $config,
        private readonly Repository $repository,
        private readonly CapiClient $capi,
        private readonly RecurringClient $recurring,
        private readonly UpgradeQuoteService $quotes
    ) {
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function process(
        int $subscriptionId,
        int $userId,
        int $targetProductId,
        int $managementEventId,
        array $confirmedQuote
    ): array|WP_Error {
        if ((float) ($confirmedQuote['amount_due'] ?? 0) < 0.01) {
            return new WP_Error(
                'codigle_upgrade_amount_invalid',
                'The immediate upgrade amount must be positive.',
                ['status' => 409]
            );
        }

        if (! $this->repository->acquireRenewalLock($subscriptionId)) {
            return new WP_Error(
                'codigle_subscription_locked',
                'Another subscription operation is already running.',
                ['status' => 409]
            );
        }

        try {
            $subscription = $this->repository->subscriptionForUser(
                $subscriptionId,
                $userId
            );

            if ($subscription === []) {
                return new WP_Error(
                    'codigle_subscription_missing',
                    'Subscription was not found.',
                    ['status' => 404]
                );
            }

            if (
                (int) $subscription['cancel_at_period_end'] === 1
                || ! in_array(
                    (string) $subscription['status'],
                    ['active', 'past_due'],
                    true
                )
            ) {
                return new WP_Error(
                    'codigle_upgrade_subscription_ineligible',
                    'Reactivate the subscription before upgrading.',
                    ['status' => 409]
                );
            }

            $refresh = $this->capi->refreshForUser($userId);

            if ($refresh instanceof WP_Error) {
                return $refresh;
            }

            $subscription = $this->repository->subscriptionForUser(
                $subscriptionId,
                $userId
            );
            $cardId = (int) (
                $subscription['payment_card_id']
                ?? 0
            );
            $card = $this->repository->cardById($cardId, $userId);

            if ($card === []) {
                return new WP_Error(
                    'codigle_upgrade_card_missing',
                    'No active saved card is linked to this subscription.',
                    ['status' => 409]
                );
            }

            if ((int) $card['require_cvv'] === 1) {
                return new WP_Error(
                    'codigle_upgrade_cvv_required',
                    'This saved card requires CVV and cannot be used for an automatic upgrade charge.',
                    ['status' => 409]
                );
            }

            $utoken = $this->repository->userToken($userId);
            $ctoken = $this->repository->cardToken($cardId, $userId);

            if ($utoken === '' || $ctoken === '') {
                return new WP_Error(
                    'codigle_upgrade_tokens_missing',
                    'Saved card tokens are unavailable.',
                    ['status' => 409]
                );
            }

            // Rebuild the quote after the provider card refresh and while
            // holding the subscription lock. Never charge a different offer
            // from the one the customer explicitly confirmed.
            $quote = $this->quotes->quote(
                $subscriptionId,
                $userId,
                $targetProductId
            );

            if ($quote instanceof WP_Error) {
                return $quote;
            }

            if (! hash_equals(
                (string) ($confirmedQuote['quote_hash'] ?? ''),
                (string) ($quote['quote_hash'] ?? '')
            )) {
                return new WP_Error(
                    'codigle_upgrade_quote_stale',
                    'The upgrade offer changed or expired. Review the refreshed offer before continuing.',
                    [
                        'status' => 409,
                        'quote' => $quote,
                    ]
                );
            }

            $order = $this->upgradeOrder(
                $subscription,
                $quote,
                $managementEventId
            );
            if ($order->is_paid()) {
                return new WP_Error(
                    'codigle_upgrade_paid_not_applied',
                    'A paid upgrade order already exists and requires review.',
                    ['status' => 409]
                );
            }

            $attempt = $this->repository->ensureAttempt(
                $order,
                'upgrade',
                $subscriptionId
            );

            if (
                in_array(
                    (string) $attempt['status'],
                    ['submitted', 'wait_callback', 'processing'],
                    true
                )
            ) {
                return [
                    'status' => (string) $attempt['status'],
                    'order_id' => $order->get_id(),
                    'attempt_id' => (int) $attempt['id'],
                    'merchant_oid' => (string) $attempt['merchant_oid'],
                    'duplicate_prevented' => true,
                    'quote' => $quote,
                ];
            }

            $fields = $this->recurring->fields(
                $order,
                $attempt,
                $utoken,
                $ctoken,
                $card,
                false
            );
            $this->repository->markAttempt(
                (int) $attempt['id'],
                'submitted',
                [
                    'test_mode' => $this->config->testMode() ? 1 : 0,
                    'submitted_at_utc' => gmdate('Y-m-d H:i:s'),
                ]
            );
            $result = $this->recurring->send($fields);

            if ($result instanceof WP_Error) {
                $this->repository->markAttempt(
                    (int) $attempt['id'],
                    'wait_callback',
                    [
                        'immediate_status' => 'transport_unknown',
                        'immediate_response' => wp_json_encode([
                            'error_code' => $result->get_error_code(),
                            'message' => $result->get_error_message(),
                            'data' => $this->safeData(
                                $result->get_error_data()
                            ),
                        ]),
                    ]
                );
                $order->update_status(
                    'on-hold',
                    'PayTR upgrade response was ambiguous; waiting for callback.'
                );
                do_action(
                    'codigle_paytr_direct_reconcile_attempt_later',
                    (int) $attempt['id'],
                    120
                );

                return [
                    'status' => 'wait_callback',
                    'order_id' => $order->get_id(),
                    'attempt_id' => (int) $attempt['id'],
                    'merchant_oid' => (string) $attempt['merchant_oid'],
                    'transport_unknown' => true,
                    'quote' => $quote,
                ];
            }

            $providerStatus = (string) $result['status'];
            $providerMessage = (string) $result['msg'];
            $tryAgain = ! empty($result['try_again']);
            $safeResponse = [
                'status' => $providerStatus,
                'msg' => $providerMessage,
                'try_again' => $tryAgain,
                'http_code' => (int) $result['http_code'],
                'response_keys' => $result['response_keys'] ?? [],
                'safe_response' => $result['safe_response'] ?? [],
                'body_sha256_prefix' => (
                    $result['body_sha256_prefix']
                    ?? ''
                ),
            ];

            if ($providerStatus === 'failed') {
                $terminalStatus = $tryAgain ? 'retryable' : 'failed';
                $this->repository->markAttempt(
                    (int) $attempt['id'],
                    $terminalStatus,
                    [
                        'immediate_status' => $providerStatus,
                        'immediate_try_again' => $tryAgain ? 1 : 0,
                        'immediate_response' => wp_json_encode($safeResponse),
                        'failed_reason_code' => $tryAgain
                            ? 'paytr_immediate_retryable'
                            : 'paytr_immediate_failed',
                        'failed_reason_msg' => $providerMessage,
                    ]
                );

                if ($tryAgain) {
                    $order->update_status(
                        'on-hold',
                        'PayTR upgrade payment is temporarily busy: '
                        . $providerMessage
                    );
                    do_action(
                        'codigle_paytr_direct_reconcile_attempt_later',
                        (int) $attempt['id'],
                        120
                    );
                } elseif (! $order->is_paid()) {
                    $order->update_status(
                        'failed',
                        'PayTR upgrade payment rejected: '
                        . $providerMessage
                    );
                }

                return [
                    'status' => 'failed',
                    'message' => $providerMessage,
                    'try_again' => $tryAgain,
                    'order_id' => $order->get_id(),
                    'attempt_id' => (int) $attempt['id'],
                    'merchant_oid' => (string) $attempt['merchant_oid'],
                    'waiting_for_callback' => false,
                    'final' => ! $tryAgain,
                    'retryable' => $tryAgain,
                    'quote' => $quote,
                ];
            }

            $this->repository->markAttempt(
                (int) $attempt['id'],
                'wait_callback',
                [
                    'immediate_status' => $providerStatus,
                    'immediate_try_again' => $tryAgain ? 1 : 0,
                    'immediate_response' => wp_json_encode($safeResponse),
                ]
            );
            $order->update_status(
                'on-hold',
                'PayTR upgrade request accepted; waiting for callback.'
            );
            do_action(
                'codigle_paytr_direct_reconcile_attempt_later',
                (int) $attempt['id'],
                $providerStatus === 'success' ? 60 : 300
            );

            return [
                'status' => $providerStatus,
                'message' => $providerMessage,
                'try_again' => $tryAgain,
                'order_id' => $order->get_id(),
                'attempt_id' => (int) $attempt['id'],
                'merchant_oid' => (string) $attempt['merchant_oid'],
                'waiting_for_callback' => true,
                'final' => false,
                'quote' => $quote,
            ];
        } catch (Throwable $error) {
            return new WP_Error(
                'codigle_upgrade_exception',
                substr(
                    sanitize_text_field($error->getMessage()),
                    0,
                    500
                ),
                ['status' => 500]
            );
        } finally {
            $this->repository->releaseRenewalLock($subscriptionId);
        }
    }

    /**
     * @param array<string, mixed> $subscription
     * @param array<string, mixed> $quote
     */
    private function upgradeOrder(
        array $subscription,
        array $quote,
        int $managementEventId
    ): WC_Order {
        $existing = wc_get_orders([
            'limit' => 1,
            'return' => 'objects',
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_codigle_subscription_id',
                    'value' => (string) $subscription['id'],
                ],
                [
                    'key' => '_codigle_upgrade_quote_hash',
                    'value' => (string) $quote['quote_hash'],
                ],
                [
                    'key' => '_codigle_management_event_id',
                    'value' => (string) $managementEventId,
                ],
            ],
        ]);

        if (
            is_array($existing)
            && isset($existing[0])
            && $existing[0] instanceof WC_Order
        ) {
            return $existing[0];
        }

        $initial = wc_get_order(
            (int) $subscription['initial_order_id']
        );

        if (! $initial instanceof WC_Order) {
            throw new RuntimeException(
                'Initial subscription order could not be loaded.'
            );
        }

        $product = wc_get_product(
            (int) $quote['target_product_id']
        );

        if (! $product instanceof WC_Product) {
            throw new RuntimeException(
                'Upgrade target product could not be loaded.'
            );
        }

        $order = wc_create_order([
            'customer_id' => (int) $subscription['user_id'],
            'created_via' => 'codigle-paytr-upgrade',
            'status' => 'pending',
        ]);

        if (! $order instanceof WC_Order) {
            throw new RuntimeException(
                'Upgrade order could not be created.'
            );
        }

        $order->set_address($initial->get_address('billing'), 'billing');
        $shipping = $initial->get_address('shipping');

        if (array_filter($shipping) !== []) {
            $order->set_address($shipping, 'shipping');
        }

        $amount = wc_format_decimal(
            (string) $quote['amount_due'],
            wc_get_price_decimals()
        );
        $item = new WC_Order_Item_Product();
        $item->set_product($product);
        $item->set_name(
            $product->get_name() . ' — prorated upgrade'
        );
        $item->set_quantity(1);
        $item->set_subtotal($amount);
        $item->set_total($amount);
        $order->add_item($item);
        $order->set_currency((string) $subscription['currency']);
        $order->set_payment_method(Config::GATEWAY_ID);
        $order->set_payment_method_title(
            'Codigle PayTR Direct subscription upgrade'
        );
        $order->set_total($amount);
        $order->update_meta_data(
            '_codigle_subscription_id',
            (int) $subscription['id']
        );
        $order->update_meta_data(
            '_codigle_management_event_id',
            $managementEventId
        );
        $order->update_meta_data(
            '_codigle_upgrade_from_product_id',
            (int) $quote['from_product_id']
        );
        $order->update_meta_data(
            '_codigle_upgrade_target_product_id',
            (int) $quote['target_product_id']
        );
        $order->update_meta_data(
            '_codigle_upgrade_target_duration_months',
            (int) $quote['duration_months']
        );
        $order->update_meta_data(
            '_codigle_upgrade_target_full_amount',
            (string) $quote['target_full_amount']
        );
        $order->update_meta_data(
            '_codigle_upgrade_period_end_utc',
            (string) $quote['period_end_utc']
        );
        $order->update_meta_data(
            '_codigle_upgrade_quote_hash',
            (string) $quote['quote_hash']
        );
        $order->update_meta_data(
            '_codigle_upgrade_quote_snapshot',
            wp_json_encode(
                $quote,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )
        );
        $order->add_order_note(
            'Codigle prorated subscription upgrade order created.'
        );
        $order->save();

        return $order;
    }

    private function safeData(mixed $data): mixed
    {
        if (! is_array($data)) {
            return null;
        }

        $allowed = [
            'ambiguous',
            'curl_errno',
            'http_code',
            'body_sha256_prefix',
            'response_keys',
        ];
        $safe = [];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $data)) {
                $safe[$key] = $data[$key];
            }
        }

        return $safe;
    }
}
