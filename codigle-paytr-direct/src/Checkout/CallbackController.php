<?php

declare(strict_types=1);

namespace Codigle\PaytrDirect\Checkout;

use Codigle\PaytrDirect\Database\Repository;
use Codigle\PaytrDirect\Paytr\CapiClient;
use Codigle\PaytrDirect\Paytr\TokenService;
use Codigle\PaytrDirect\Subscription\SubscriptionService;
use Codigle\PaytrDirect\Support\ClientIp;
use Codigle\PaytrDirect\Support\Config;
use Throwable;
use WC_Order;

final class CallbackController
{
    private readonly TokenService $tokenService;

    public function __construct(
        private readonly Config $config,
        private readonly Repository $repository,
        private readonly SubscriptionService $subscriptions,
        private readonly CapiClient $capi
    ) {
        $this->tokenService = new TokenService(
            $this->config,
            new ClientIp($this->config)
        );
    }

    public function register(): void
    {
        add_action(
            'woocommerce_api_wc_gateway_paytrcheckout',
            [$this, 'maybeHandle'],
            1
        );
        add_action(
            'woocommerce_api_codigle_paytr_direct_callback',
            [$this, 'handle'],
            1
        );
    }

    public function maybeHandle(): void
    {
        $merchantOid = sanitize_text_field(
            (string) ($_POST['merchant_oid'] ?? '')
        );

        if (! str_starts_with($merchantOid, 'CDG')) {
            return;
        }

        $this->handle();
    }

    public function handle(): void
    {
        nocache_headers();
        header('Content-Type: text/plain; charset=UTF-8');

        $merchantOid = sanitize_text_field(
            (string) ($_POST['merchant_oid'] ?? '')
        );
        $status = sanitize_key(
            (string) ($_POST['status'] ?? '')
        );
        $totalAmount = preg_replace(
            '/\D+/',
            '',
            (string) ($_POST['total_amount'] ?? '')
        ) ?? '';
        $receivedHash = (string) ($_POST['hash'] ?? '');

        if (
            ! str_starts_with($merchantOid, 'CDG')
            || ! in_array($status, ['success', 'failed'], true)
            || $totalAmount === ''
            || $receivedHash === ''
        ) {
            status_header(400);
            echo 'PAYTR notification failed: missing fields';
            exit;
        }

        $expectedHash = $this->tokenService->callbackHash(
            $merchantOid,
            $status,
            $totalAmount
        );

        if (! hash_equals($expectedHash, $receivedHash)) {
            status_header(400);
            echo 'PAYTR notification failed: bad hash';
            exit;
        }

        $attempt = $this->repository->attemptByOid($merchantOid);

        if ($attempt === []) {
            status_header(500);
            echo 'PAYTR notification failed: order not found';
            exit;
        }

        $attemptStatus = (string) $attempt['status'];
        $recoverableStatusFailure = (
            $attemptStatus === 'failed'
            && $status === 'success'
            && (string) ($attempt['failed_reason_code'] ?? '')
                === 'provider_success_not_found'
        );

        if ($recoverableStatusFailure) {
            $reopened = $this->repository->transitionAttempt(
                (int) $attempt['id'],
                ['failed'],
                'wait_callback',
                [
                    'failed_reason_code' => '',
                    'failed_reason_msg' => '',
                ]
            );

            if ($reopened) {
                $attempt = $this->repository->attemptById(
                    (int) $attempt['id']
                );
                $attemptStatus = (string) $attempt['status'];
            }
        }

        if (
            in_array(
                $attemptStatus,
                ['success', 'failed', 'amount_mismatch'],
                true
            )
        ) {
            if (empty($attempt['callback_received_at_utc'])) {
                $this->repository->markAttempt(
                    (int) $attempt['id'],
                    $attemptStatus,
                    [
                        'callback_payload' => wp_json_encode(
                            $this->safePayload($_POST)
                        ),
                        'callback_received_at_utc' => gmdate('Y-m-d H:i:s'),
                    ]
                );
            }

            echo 'OK';
            exit;
        }

        if (! $this->repository->claimAttempt((int) $attempt['id'])) {
            echo 'OK';
            exit;
        }

        $order = wc_get_order((int) $attempt['order_id']);

        if (! $order instanceof WC_Order) {
            $this->repository->markAttempt(
                (int) $attempt['id'],
                'failed',
                [
                    'failed_reason_code' => 'order_missing',
                    'failed_reason_msg' => 'Order could not be loaded.',
                    'callback_received_at_utc' => gmdate('Y-m-d H:i:s'),
                ]
            );
            echo 'OK';
            exit;
        }

        try {
            $this->processCallback(
                $order,
                $attempt,
                $status,
                $totalAmount
            );

            echo 'OK';
            exit;
        } catch (Throwable $error) {
            error_log(
                'CODIGLE_PAYTR_DIRECT_CALLBACK_ERROR=' . substr(
                    sanitize_text_field($error->getMessage()),
                    0,
                    300
                )
            );
            status_header(500);
            echo 'PAYTR notification failed: temporary error';
            exit;
        }
    }

    /**
     * @param array<string, mixed> $attempt
     */
    private function processCallback(
        WC_Order $order,
        array $attempt,
        string $status,
        string $totalAmount
    ): void {
        $payload = $this->safePayload($_POST);
        $callbackTime = gmdate('Y-m-d H:i:s');
        $type = (string) $attempt['attempt_type'];

        if ($status === 'success') {
            $paymentAmount = (int) (
                preg_replace(
                    '/\D+/',
                    '',
                    (string) (
                        $_POST['payment_amount']
                        ?? $totalAmount
                    )
                )
                ?? 0
            );

            if (
                $paymentAmount !== (int) $attempt['expected_amount_minor']
            ) {
                $this->repository->markAttempt(
                    (int) $attempt['id'],
                    'amount_mismatch',
                    [
                        'callback_payload' => wp_json_encode($payload),
                        'failed_reason_code' => 'amount_mismatch',
                        'failed_reason_msg' =>
                            'Callback amount did not match the order.',
                        'callback_received_at_utc' => $callbackTime,
                    ]
                );
                $order->update_status(
                    'on-hold',
                    'PayTR callback amount mismatch; manual review required.'
                );
                $this->markManagementEvent(
                    $order,
                    'manual_review',
                    [],
                    'amount_mismatch',
                    'Callback amount did not match the order.',
                    (int) $attempt['id']
                );

                return;
            }

            if (! $order->is_paid()) {
                $order->payment_complete(
                    (string) $attempt['merchant_oid']
                );
            }

            if ($type === 'initial') {
                $this->processInitialSuccess(
                    $order,
                    $attempt,
                    $payload,
                    $callbackTime
                );

                return;
            }

            if ($type === 'renewal') {
                $newCardId = $this->captureStoredCard($order);
                $subscription = $this->subscriptions->renew(
                    $order,
                    $attempt
                );

                if ($newCardId > 0) {
                    $subscription = $this->repository->setSubscriptionCard(
                        (int) $subscription['id'],
                        (int) $subscription['user_id'],
                        $newCardId
                    );
                }
                $order->add_order_note(
                    'PayTR recurring callback verified. Subscription period advanced.'
                );
                $this->repository->markAttempt(
                    (int) $attempt['id'],
                    'success',
                    [
                        'subscription_id' => (int) $subscription['id'],
                        'callback_payload' => wp_json_encode($payload),
                        'callback_received_at_utc' => $callbackTime,
                    ]
                );
                $this->markManagementEvent(
                    $order,
                    'success',
                    [
                        'subscription' => $this->safeSubscription(
                            $subscription
                        ),
                        'payment' => [
                            'order_id' => $order->get_id(),
                            'attempt_id' => (int) $attempt['id'],
                            'status' => 'success',
                        ],
                    ],
                    '',
                    '',
                    (int) $attempt['id']
                );
                $this->completePaidServiceOrder(
                    $order,
                    'PayTR callback verified. The paid Codigle service order is complete.'
                );

                return;
            }

            if ($type === 'upgrade') {
                $newCardId = $this->captureStoredCard($order);
                $subscription = $this->subscriptions->upgrade(
                    $order,
                    $attempt
                );

                if (is_array($subscription) && $newCardId > 0) {
                    $subscription = $this->repository->setSubscriptionCard(
                        (int) $subscription['id'],
                        (int) $subscription['user_id'],
                        $newCardId
                    );
                }

                if ($subscription instanceof \WP_Error) {
                    $this->repository->markAttempt(
                        (int) $attempt['id'],
                        'manual_review',
                        [
                            'callback_payload' => wp_json_encode($payload),
                            'failed_reason_code' => sanitize_key(
                                $subscription->get_error_code()
                            ),
                            'failed_reason_msg' => substr(
                                sanitize_text_field(
                                    $subscription->get_error_message()
                                ),
                                0,
                                500
                            ),
                            'callback_received_at_utc' => $callbackTime,
                        ]
                    );
                    $order->update_status(
                        'on-hold',
                        'Upgrade payment succeeded but the plan change requires manual review.'
                    );
                    $this->markManagementEvent(
                        $order,
                        'manual_review',
                        [],
                        (string) $subscription->get_error_code(),
                        $subscription->get_error_message(),
                        (int) $attempt['id']
                    );

                    return;
                }

                $order->add_order_note(
                    'PayTR upgrade callback verified. Subscription plan upgraded immediately.'
                );
                $this->repository->markAttempt(
                    (int) $attempt['id'],
                    'success',
                    [
                        'subscription_id' => (int) $subscription['id'],
                        'callback_payload' => wp_json_encode($payload),
                        'callback_received_at_utc' => $callbackTime,
                    ]
                );
                $this->markManagementEvent(
                    $order,
                    'success',
                    [
                        'subscription' => $this->safeSubscription(
                            $subscription
                        ),
                        'payment' => [
                            'order_id' => $order->get_id(),
                            'attempt_id' => (int) $attempt['id'],
                            'status' => 'success',
                        ],
                    ],
                    '',
                    '',
                    (int) $attempt['id']
                );
                $this->completePaidServiceOrder(
                    $order,
                    'PayTR callback verified. The paid Codigle service order is complete.'
                );

                return;
            }

            if ($type === 'renewal_test') {
                $order->add_order_note(
                    'PayTR recurring test callback verified. Subscription period was not changed.'
                );
                $this->repository->markAttempt(
                    (int) $attempt['id'],
                    'success',
                    [
                        'callback_payload' => wp_json_encode($payload),
                        'callback_received_at_utc' => $callbackTime,
                    ]
                );
                $this->completePaidServiceOrder(
                    $order,
                    'PayTR recurring test callback verified.'
                );

                return;
            }

            throw new \RuntimeException(
                'Unknown payment attempt type.'
            );
        }

        $reasonCode = sanitize_text_field(
            (string) ($_POST['failed_reason_code'] ?? '')
        );
        $reasonMessage = sanitize_text_field(
            (string) ($_POST['failed_reason_msg'] ?? 'Payment failed.')
        );

        $this->repository->markAttempt(
            (int) $attempt['id'],
            'failed',
            [
                'callback_payload' => wp_json_encode($payload),
                'failed_reason_code' => $reasonCode,
                'failed_reason_msg' => $reasonMessage,
                'callback_received_at_utc' => $callbackTime,
            ]
        );

        if (! $order->is_paid()) {
            $order->update_status(
                'failed',
                'PayTR Direct: ' . $reasonMessage
            );
        }

        if ($type === 'renewal') {
            $this->subscriptions->renewalFailed(
                $order,
                $attempt
            );
        }

        if (in_array($type, ['renewal', 'upgrade'], true)) {
            $this->markManagementEvent(
                $order,
                'failed',
                [
                    'payment' => [
                        'order_id' => $order->get_id(),
                        'attempt_id' => (int) $attempt['id'],
                        'status' => 'failed',
                    ],
                ],
                $reasonCode,
                $reasonMessage,
                (int) $attempt['id']
            );
        }
    }

    private function captureStoredCard(WC_Order $order): int
    {
        $userId = $order->get_customer_id();
        $utoken = sanitize_text_field(
            (string) ($_POST['utoken'] ?? '')
        );
        $ctoken = sanitize_text_field(
            (string) ($_POST['ctoken'] ?? '')
        );

        if ($userId < 1 || $utoken === '') {
            return 0;
        }

        $this->repository->saveCustomerToken($userId, $utoken);
        $refreshed = $this->capi->refreshForUser($userId);

        if ($refreshed instanceof \WP_Error) {
            do_action(
                'codigle_paytr_direct_refresh_cards',
                $userId,
                1
            );

            return 0;
        }

        $cardId = $ctoken !== ''
            ? $this->repository->cardIdByToken($userId, $ctoken)
            : 0;

        return $cardId > 0
            ? $cardId
            : $this->repository->defaultCardId($userId);
    }

    /**
     * @param array<string, mixed> $attempt
     * @param array<string, string> $payload
     */
    private function processInitialSuccess(
        WC_Order $order,
        array $attempt,
        array $payload,
        string $callbackTime
    ): void {
        $utoken = sanitize_text_field(
            (string) ($_POST['utoken'] ?? '')
        );
        $ctokenReceived = ! empty($_POST['ctoken']);
        $userId = $order->get_customer_id();

        if ($userId > 0 && $utoken !== '') {
            $this->repository->saveCustomerToken($userId, $utoken);
        }

        $order->add_order_note(
            'PayTR Direct callback verified. Initial 3D payment succeeded.'
        );
        $subscription = $this->subscriptions->activate($order);

        $this->repository->markAttempt(
            (int) $attempt['id'],
            'success',
            [
                'subscription_id' => (int) (
                    $subscription['id']
                    ?? 0
                ),
                'callback_payload' => wp_json_encode($payload),
                'callback_received_at_utc' => $callbackTime,
            ]
        );

        if ($userId > 0 && $utoken !== '') {
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(
                    'codigle_paytr_direct_refresh_cards',
                    [$userId],
                    'codigle-paytr-direct'
                );
            } else {
                wp_schedule_single_event(
                    time() + 5,
                    'codigle_paytr_direct_refresh_cards',
                    [$userId]
                );
            }
        }

        $order->update_meta_data(
            '_codigle_paytr_direct_utoken_received',
            $utoken !== '' ? 'yes' : 'no'
        );
        $order->update_meta_data(
            '_codigle_paytr_direct_ctoken_received',
            $ctokenReceived ? 'yes' : 'no'
        );
        $order->save();
        $this->completePaidServiceOrder(
            $order,
            'PayTR initial payment callback verified. The paid Codigle service order is complete.'
        );
    }

    private function completePaidServiceOrder(
        WC_Order $order,
        string $note
    ): void {
        if (! $order->has_status('completed')) {
            $order->update_status('completed', $note);
        }
    }

    /**
     * @param array<string, mixed> $afterState
     */
    private function markManagementEvent(
        WC_Order $order,
        string $status,
        array $afterState = [],
        string $errorCode = '',
        string $errorMessage = '',
        int $attemptId = 0
    ): void {
        $eventId = (int) $order->get_meta(
            '_codigle_management_event_id',
            true
        );

        if ($eventId < 1) {
            $event = $this->repository->subscriptionEventByOrder(
                $order->get_id()
            );
            $eventId = (int) ($event['id'] ?? 0);
        }

        if ($eventId < 1) {
            return;
        }

        $this->repository->markSubscriptionEvent(
            $eventId,
            $status,
            $afterState,
            $order->get_id(),
            $attemptId,
            $errorCode,
            $errorMessage
        );
    }

    /**
     * @param array<string, mixed> $subscription
     * @return array<string, mixed>
     */
    private function safeSubscription(array $subscription): array
    {
        $allowed = [
            'id',
            'user_id',
            'initial_order_id',
            'product_id',
            'plan_page_id',
            'duration_months',
            'payment_card_id',
            'amount',
            'currency',
            'status',
            'auto_renew',
            'cancel_at_period_end',
            'current_period_start_utc',
            'current_period_end_utc',
            'next_payment_at_utc',
            'last_payment_at_utc',
            'last_renewal_order_id',
            'pending_product_id',
            'pending_duration_months',
            'pending_change_at_period_end',
            'updated_at_utc',
        ];
        $safe = [];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $subscription)) {
                $safe[$key] = $subscription[$key];
            }
        }

        return $safe;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private function safePayload(array $input): array
    {
        $allowed = [
            'merchant_oid',
            'status',
            'total_amount',
            'failed_reason_code',
            'failed_reason_msg',
            'test_mode',
            'payment_type',
            'currency',
            'payment_amount',
            'installment_count',
        ];
        $safe = [];

        foreach ($allowed as $key) {
            if (isset($input[$key]) && is_scalar($input[$key])) {
                $safe[$key] = substr(
                    sanitize_text_field((string) $input[$key]),
                    0,
                    500
                );
            }
        }

        $safe['utoken_received'] = empty($input['utoken'])
            ? 'no'
            : 'yes';
        $safe['ctoken_received'] = empty($input['ctoken'])
            ? 'no'
            : 'yes';

        return $safe;
    }
}
