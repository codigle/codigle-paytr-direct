<?php

declare(strict_types=1);

namespace Codigle\PaytrDirect\Subscription;

use Codigle\PaytrDirect\Checkout\PaymentPage;
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

final class RenewalService
{
    public function __construct(
        private readonly Config $config,
        private readonly Repository $repository,
        private readonly CapiClient $capi,
        private readonly RecurringClient $recurring,
        private readonly SubscriptionService $subscriptions
    ) {
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function dryRun(int $subscriptionId): array|WP_Error
    {
        $subscription = $this->repository->subscriptionById(
            $subscriptionId
        );

        if ($subscription === []) {
            return new WP_Error(
                'codigle_subscription_missing',
                'Subscription was not found.'
            );
        }

        $userId = (int) $subscription['user_id'];
        $refresh = $this->capi->refreshForUser($userId);

        if ($refresh instanceof WP_Error) {
            return $refresh;
        }

        $subscription = $this->repository->subscriptionById(
            $subscriptionId
        );
        $cardId = (int) ($subscription['payment_card_id'] ?? 0);
        $card = $this->repository->cardById($cardId, $userId);
        $utoken = $this->repository->userToken($userId);
        $ctoken = $this->repository->cardToken($cardId, $userId);

        return [
            'subscription_id' => $subscriptionId,
            'status' => (string) $subscription['status'],
            'auto_renew' => (int) $subscription['auto_renew'],
            'renewal_mode' => $this->config->renewalMode(),
            'test_mode' => $this->config->testMode(),
            'amount' => (string) $subscription['amount'],
            'currency' => (string) $subscription['currency'],
            'next_payment_at_utc' => (
                $subscription['next_payment_at_utc']
                ?? null
            ),
            'card' => $card === [] ? null : [
                'id' => (int) $card['id'],
                'last_4' => (string) $card['last_4'],
                'schema' => (string) $card['card_schema'],
                'brand' => (string) $card['card_brand'],
                'require_cvv' => (int) $card['require_cvv'],
            ],
            'utoken_available' => $utoken !== '',
            'ctoken_available' => $ctoken !== '',
            'ready' => (
                $utoken !== ''
                && $ctoken !== ''
                && $card !== []
                && (int) $card['require_cvv'] === 0
            ),
        ];
    }

    /**
     * Create a normal 3D PayTR renewal order. Card data is posted by the
     * customer's browser directly to PayTR; Codigle receives only the signed
     * callback and masked/tokenized card metadata.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function prepareInteractivePayment(
        int $subscriptionId,
        int $managementEventId = 0
    ): array|WP_Error {
        $subscription = $this->repository->subscriptionById(
            $subscriptionId
        );

        if ($subscription === []) {
            return new WP_Error(
                'codigle_subscription_missing',
                'Subscription was not found.',
                ['status' => 404]
            );
        }

        if (
            ! in_array(
                (string) $subscription['status'],
                ['active', 'past_due'],
                true
            )
            || (int) $subscription['cancel_at_period_end'] === 1
        ) {
            return new WP_Error(
                'codigle_subscription_not_renewable',
                'Reactivate the subscription before renewing it.',
                ['status' => 409]
            );
        }

        if (! $this->repository->acquireRenewalLock($subscriptionId)) {
            return new WP_Error(
                'codigle_renewal_locked',
                'A renewal process is already running.',
                ['status' => 409]
            );
        }

        try {
            $order = $this->renewalOrder(
                $subscription,
                false,
                $managementEventId,
                true
            );
            $order->update_meta_data(
                '_codigle_payment_attempt_type',
                'renewal'
            );
            $order->update_meta_data(
                '_codigle_interactive_payment',
                'yes'
            );
            $order->update_meta_data(
                '_codigle_return_url',
                home_url('/customer-portal/#subscriptions')
            );
            $order->set_payment_method_title(
                'Codigle PayTR Direct secure renewal'
            );
            $order->save();

            $attempt = $this->repository->ensureAttempt(
                $order,
                'renewal',
                $subscriptionId
            );

            return [
                'status' => 'payment_redirect',
                'order_id' => $order->get_id(),
                'attempt_id' => (int) $attempt['id'],
                'payment_url' => PaymentPage::url($order),
            ];
        } catch (Throwable $error) {
            return new WP_Error(
                'codigle_interactive_renewal_failed',
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
     * @return array<string, mixed>|WP_Error
     */
    public function process(
        int $subscriptionId,
        bool $force = false,
        bool $testOnly = false,
        bool $testFailure = false,
        int $managementEventId = 0
    ): array|WP_Error {
        $subscription = $this->repository->subscriptionById(
            $subscriptionId
        );

        if ($subscription === []) {
            return new WP_Error(
                'codigle_subscription_missing',
                'Subscription was not found.'
            );
        }

        if (
            ! $force
            && $this->config->renewalMode() !== 'live'
        ) {
            return new WP_Error(
                'codigle_renewal_not_live',
                'Automatic renewal mode is not live.'
            );
        }

        if (
            ! in_array(
                (string) $subscription['status'],
                ['active', 'past_due'],
                true
            )
            || (int) $subscription['cancel_at_period_end'] === 1
            || (
                ! $force
                && (int) $subscription['auto_renew'] !== 1
            )
        ) {
            return new WP_Error(
                'codigle_subscription_not_renewable',
                'Subscription is not eligible for renewal.'
            );
        }

        if (! $force && ! $this->isDue($subscription)) {
            return new WP_Error(
                'codigle_subscription_not_due',
                'Subscription is not due yet.'
            );
        }

        if (! $this->repository->acquireRenewalLock(
            $subscriptionId
        )) {
            return new WP_Error(
                'codigle_renewal_locked',
                'A renewal process is already running.'
            );
        }

        try {
            $userId = (int) $subscription['user_id'];
            $refresh = $this->capi->refreshForUser($userId);

            if ($refresh instanceof WP_Error) {
                return $refresh;
            }

            $subscription = $this->repository->subscriptionById(
                $subscriptionId
            );
            $cardId = (int) (
                $subscription['payment_card_id']
                ?? 0
            );
            $card = $this->repository->cardById(
                $cardId,
                $userId
            );

            if ($card === []) {
                return new WP_Error(
                    'codigle_renewal_card_missing',
                    'No active saved card is linked to this subscription.'
                );
            }

            if ((int) $card['require_cvv'] === 1) {
                return new WP_Error(
                    'codigle_renewal_cvv_required',
                    'This saved card requires CVV and cannot be charged automatically.'
                );
            }

            $utoken = $this->repository->userToken($userId);
            $ctoken = $this->repository->cardToken(
                $cardId,
                $userId
            );

            if ($utoken === '' || $ctoken === '') {
                return new WP_Error(
                    'codigle_renewal_tokens_missing',
                    'Saved card tokens are unavailable.'
                );
            }

            $order = $this->renewalOrder(
                $subscription,
                $testOnly,
                $managementEventId
            );
            if ($order->is_paid()) {
                return new WP_Error(
                    'codigle_renewal_paid_not_applied',
                    'A paid renewal order already exists for this period and requires review.'
                );
            }

            $attemptType = $testOnly
                ? 'renewal_test'
                : 'renewal';
            $attempt = $this->repository->ensureAttempt(
                $order,
                $attemptType,
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
                ];
            }

            $fields = $this->recurring->fields(
                $order,
                $attempt,
                $utoken,
                $ctoken,
                $card,
                $testFailure
            );
            $this->repository->markAttempt(
                (int) $attempt['id'],
                'submitted',
                [
                    'test_mode' => $this->config->testMode()
                        ? 1
                        : 0,
                    'submitted_at_utc' => gmdate('Y-m-d H:i:s'),
                ]
            );

            // Put the order into its waiting state before the network call.
            // The signed callback may arrive before send() returns; nothing
            // after send() is allowed to downgrade the callback's paid state.
            if (! $order->is_paid() && ! $order->has_status('on-hold')) {
                $order->update_status(
                    'on-hold',
                    'PayTR recurring request submitted; waiting for the verified provider result.'
                );
            }

            $result = $this->recurring->send($fields);

            if ($result instanceof WP_Error) {
                $safe = [
                    'error_code' => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                    'data' => $this->safeData(
                        $result->get_error_data()
                    ),
                ];
                $transitioned = $this->repository->transitionAttempt(
                    (int) $attempt['id'],
                    ['submitted'],
                    'wait_callback',
                    [
                        'immediate_status' => 'transport_unknown',
                        'immediate_response' => wp_json_encode($safe),
                    ]
                );

                if (! $transitioned) {
                    return $this->concurrentCallbackResult(
                        (int) $attempt['id'],
                        $order,
                        $testOnly
                    );
                }

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
                ];
            }

            $providerStatus = (string) $result['status'];
            $providerMessage = (string) $result['msg'];
            $tryAgain = ! empty($result['try_again']);
            $immediateResponse = [
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
                $terminalStatus = $tryAgain
                    ? 'retryable'
                    : 'failed';
                $reasonCode = $tryAgain
                    ? 'paytr_immediate_retryable'
                    : 'paytr_immediate_failed';

                $transitioned = $this->repository->transitionAttempt(
                    (int) $attempt['id'],
                    ['submitted'],
                    $terminalStatus,
                    [
                        'immediate_status' => $providerStatus,
                        'immediate_try_again' => $tryAgain ? 1 : 0,
                        'immediate_response' => wp_json_encode(
                            $immediateResponse
                        ),
                        'failed_reason_code' => $reasonCode,
                        'failed_reason_msg' => $providerMessage,
                    ]
                );

                if (! $transitioned) {
                    return $this->concurrentCallbackResult(
                        (int) $attempt['id'],
                        $order,
                        $testOnly
                    );
                }

                if ($tryAgain) {
                    $order->add_order_note(
                        'PayTR recurring payment is temporarily busy: '
                        . $providerMessage
                    );
                    do_action(
                        'codigle_paytr_direct_reconcile_attempt_later',
                        (int) $attempt['id'],
                        120
                    );
                } else {
                    if (! $order->is_paid()) {
                        $order->update_status(
                            'failed',
                            'PayTR recurring payment rejected: '
                            . $providerMessage
                        );
                    }

                    if ($attemptType === 'renewal') {
                        $this->subscriptions->renewalFailed(
                            $order,
                            $attempt
                        );
                    }
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
                    'test_only' => $testOnly,
                    'provider_debug' => [
                        'response_keys' => (
                            $result['response_keys']
                            ?? []
                        ),
                        'safe_response' => (
                            $result['safe_response']
                            ?? []
                        ),
                        'body_sha256_prefix' => (
                            $result['body_sha256_prefix']
                            ?? ''
                        ),
                    ],
                ];
            }

            if ($providerStatus === 'success') {
                $transitioned = $this->repository->transitionAttempt(
                    (int) $attempt['id'],
                    ['submitted'],
                    'processing',
                    [
                        'immediate_status' => 'success',
                        'immediate_try_again' => 0,
                        'immediate_response' => wp_json_encode(
                            $immediateResponse
                        ),
                    ]
                );

                if (! $transitioned) {
                    return $this->concurrentCallbackResult(
                        (int) $attempt['id'],
                        $order,
                        $testOnly
                    );
                }

                if (! $order->is_paid()) {
                    $order->payment_complete(
                        (string) $attempt['merchant_oid']
                    );
                }

                $updatedSubscription = $subscription;

                if ($attemptType === 'renewal') {
                    $updatedSubscription = $this->subscriptions->renew(
                        $order,
                        $attempt
                    );
                }

                $this->repository->markAttempt(
                    (int) $attempt['id'],
                    'success',
                    [
                        'subscription_id' => $subscriptionId,
                        'immediate_status' => 'success',
                        'immediate_try_again' => 0,
                        'immediate_response' => wp_json_encode(
                            $immediateResponse
                        ),
                    ]
                );

                if (! $order->has_status('completed')) {
                    $order->update_status(
                        'completed',
                        'PayTR recurring payment confirmed by the immediate provider response.'
                    );
                }

                if ($managementEventId > 0) {
                    $this->repository->markSubscriptionEvent(
                        $managementEventId,
                        'success',
                        [
                            'subscription' => $updatedSubscription,
                            'payment' => [
                                'order_id' => $order->get_id(),
                                'attempt_id' => (int) $attempt['id'],
                                'status' => 'success',
                                'confirmation_source' => 'immediate_response',
                            ],
                        ],
                        $order->get_id(),
                        (int) $attempt['id']
                    );
                }

                $order->add_order_note(
                    'PayTR recurring payment returned success immediately. The signed callback remains idempotent evidence.'
                );

                return [
                    'status' => 'success',
                    'message' => $providerMessage,
                    'try_again' => false,
                    'order_id' => $order->get_id(),
                    'attempt_id' => (int) $attempt['id'],
                    'merchant_oid' => (string) $attempt['merchant_oid'],
                    'waiting_for_callback' => false,
                    'final' => true,
                    'test_only' => $testOnly,
                    'subscription' => $updatedSubscription,
                    'confirmation_source' => 'immediate_response',
                ];
            }

            $transitioned = $this->repository->transitionAttempt(
                (int) $attempt['id'],
                ['submitted'],
                'wait_callback',
                [
                    'immediate_status' => $providerStatus,
                    'immediate_try_again' => $tryAgain ? 1 : 0,
                    'immediate_response' => wp_json_encode(
                        $immediateResponse
                    ),
                ]
            );

            if (! $transitioned) {
                return $this->concurrentCallbackResult(
                    (int) $attempt['id'],
                    $order,
                    $testOnly
                );
            }

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
                'test_only' => $testOnly,
                'provider_debug' => [
                    'response_keys' => (
                        $result['response_keys']
                        ?? []
                    ),
                    'safe_response' => (
                        $result['safe_response']
                        ?? []
                    ),
                    'body_sha256_prefix' => (
                        $result['body_sha256_prefix']
                        ?? ''
                    ),
                ],
            ];
        } catch (Throwable $error) {
            return new WP_Error(
                'codigle_renewal_exception',
                substr(
                    sanitize_text_field($error->getMessage()),
                    0,
                    500
                )
            );
        } finally {
            $this->repository->releaseRenewalLock(
                $subscriptionId
            );
        }
    }

    /**
     * Return the state written by a callback that won the race with the
     * original recurring HTTP response. No order or attempt state is changed.
     *
     * @return array<string, mixed>
     */
    private function concurrentCallbackResult(
        int $attemptId,
        WC_Order $order,
        bool $testOnly
    ): array {
        $latest = $this->repository->attemptById($attemptId);
        $status = (string) ($latest['status'] ?? 'processing');
        $terminal = in_array(
            $status,
            ['success', 'failed', 'amount_mismatch', 'manual_review'],
            true
        );

        return [
            'status' => $status,
            'message' => $status === 'success'
                ? 'PayTR callback confirmed the payment.'
                : 'PayTR callback processing has already started.',
            'try_again' => false,
            'order_id' => $order->get_id(),
            'attempt_id' => $attemptId,
            'merchant_oid' => (string) ($latest['merchant_oid'] ?? ''),
            'waiting_for_callback' => ! $terminal,
            'final' => $terminal,
            'retryable' => false,
            'test_only' => $testOnly,
            'callback_won_race' => true,
        ];
    }

    /**
     * @param array<string, mixed> $subscription
     */
    private function isDue(array $subscription): bool
    {
        $next = (string) (
            $subscription['next_payment_at_utc']
            ?? ''
        );

        return $next !== ''
            && strtotime($next . ' UTC') <= time();
    }

    /**
     * @param array<string, mixed> $subscription
     */
    private function renewalOrder(
        array $subscription,
        bool $testOnly,
        int $managementEventId = 0,
        bool $interactive = false
    ): WC_Order {
        $periodKey = (string) $subscription['current_period_end_utc'];

        // Explicit CLI test runs are isolated from one another. Production
        // renewals keep period-level deduplication.
        if (! $testOnly && ! $interactive) {
            $existing = wc_get_orders(
                [
                    'limit' => 1,
                    'return' => 'objects',
                    'orderby' => 'date',
                    'order' => 'DESC',
                    // Only reuse an actually pending renewal order. A failed or
                    // cancelled order must never receive a new merchant_oid/attempt;
                    // every later charge gets a fresh WooCommerce order for a clean
                    // audit trail and unambiguous callback ownership.
                    'status' => ['pending', 'on-hold'],
                    'meta_query' => [
                        'relation' => 'AND',
                        [
                            'key' => '_codigle_subscription_id',
                            'value' => (string) $subscription['id'],
                        ],
                        [
                            'key' => '_codigle_renewal_period_key',
                            'value' => $periodKey,
                        ],
                        [
                            'key' => '_codigle_renewal_test_only',
                            'value' => 'no',
                        ],
                    ],
                ]
            );

            if (
                is_array($existing)
                && isset($existing[0])
                && $existing[0] instanceof WC_Order
            ) {
                if ($managementEventId > 0) {
                    $existing[0]->update_meta_data(
                        '_codigle_management_event_id',
                        $managementEventId
                    );
                    $existing[0]->save();
                }

                return $existing[0];
            }
        }

        $initial = wc_get_order(
            (int) $subscription['initial_order_id']
        );

        if (! $initial instanceof WC_Order) {
            throw new RuntimeException(
                'Initial subscription order could not be loaded.'
            );
        }

        $targetProductId = (int) $subscription['product_id'];
        $targetDuration = max(
            1,
            (int) $subscription['duration_months']
        );
        $appliesPendingChange = (
            (int) ($subscription['pending_change_at_period_end'] ?? 0) === 1
            && (int) ($subscription['pending_product_id'] ?? 0) > 0
        );

        if ($appliesPendingChange) {
            $targetProductId = (int) $subscription['pending_product_id'];
            $targetDuration = max(
                1,
                (int) (
                    $subscription['pending_duration_months']
                    ?? get_post_meta(
                        $targetProductId,
                        '_cpb_duration_months',
                        true
                    )
                )
            );
        }

        $product = wc_get_product($targetProductId);

        if (! $product instanceof WC_Product) {
            throw new RuntimeException(
                'Subscription renewal product could not be loaded.'
            );
        }

        $targetAmount = $appliesPendingChange
            ? (float) wc_get_price_including_tax($product, ['qty' => 1])
            : (float) $subscription['amount'];

        if ($targetAmount < 0.01) {
            throw new RuntimeException(
                'Subscription renewal amount is invalid.'
            );
        }

        $order = wc_create_order(
            [
                'customer_id' => (int) $subscription['user_id'],
                'created_via' => 'codigle-paytr-renewal',
                'status' => 'pending',
            ]
        );

        if (! $order instanceof WC_Order) {
            throw new RuntimeException(
                'Renewal order could not be created.'
            );
        }

        $order->set_address(
            $initial->get_address('billing'),
            'billing'
        );
        $shipping = $initial->get_address('shipping');

        if (array_filter($shipping) !== []) {
            $order->set_address($shipping, 'shipping');
        }

        $amount = wc_format_decimal(
            (string) $targetAmount,
            wc_get_price_decimals()
        );
        $item = new WC_Order_Item_Product();
        $item->set_product($product);
        $item->set_quantity(1);
        $item->set_subtotal($amount);
        $item->set_total($amount);
        $order->add_item($item);
        $order->set_currency(
            (string) $subscription['currency']
        );
        $order->set_payment_method(Config::GATEWAY_ID);
        $order->set_payment_method_title(
            'Codigle PayTR Direct recurring payment'
        );
        $order->set_total($amount);
        $order->update_meta_data(
            '_codigle_subscription_id',
            (int) $subscription['id']
        );
        $order->update_meta_data(
            '_codigle_renewal_period_key',
            $periodKey
        );
        $order->update_meta_data(
            '_codigle_renewal_test_only',
            $testOnly ? 'yes' : 'no'
        );
        $order->update_meta_data(
            '_codigle_renewal_interactive',
            $interactive ? 'yes' : 'no'
        );
        $order->update_meta_data(
            '_codigle_renewal_duration_months',
            $targetDuration
        );
        $order->update_meta_data(
            '_codigle_renewal_target_product_id',
            $targetProductId
        );
        $order->update_meta_data(
            '_codigle_renewal_target_duration_months',
            $targetDuration
        );
        $order->update_meta_data(
            '_codigle_renewal_target_amount',
            wc_format_decimal((string) $targetAmount, 6)
        );
        $order->update_meta_data(
            '_codigle_renewal_applies_pending_change',
            $appliesPendingChange ? 'yes' : 'no'
        );

        if ($managementEventId > 0) {
            $order->update_meta_data(
                '_codigle_management_event_id',
                $managementEventId
            );
        }

        $order->add_order_note(
            $testOnly
                ? 'Codigle PayTR recurring test order created.'
                : 'Codigle subscription renewal order created.'
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
