<?php

declare(strict_types=1);

namespace Codigle\PaytrDirect\Subscription;

use Codigle\PaytrDirect\Database\Repository;
use Codigle\PaytrDirect\Paytr\StatusClient;
use Codigle\PaytrDirect\Support\Config;
use WC_Order;

final class RenewalScheduler
{
    private const GROUP = 'codigle-paytr-direct';
    private const SWEEP_HOOK =
        'codigle_paytr_direct_renewal_sweep';
    private const PROCESS_HOOK =
        'codigle_paytr_direct_process_renewal';
    private const RECONCILE_HOOK =
        'codigle_paytr_direct_reconcile_attempt';

    public function __construct(
        private readonly Config $config,
        private readonly Repository $repository,
        private readonly RenewalService $renewals,
        private readonly StatusClient $status,
        private readonly SubscriptionService $subscriptions
    ) {
    }

    public function register(): void
    {
        add_action(
            self::SWEEP_HOOK,
            [$this, 'sweep']
        );
        add_action(
            self::PROCESS_HOOK,
            [$this, 'process'],
            10,
            1
        );
        add_action(
            self::RECONCILE_HOOK,
            [$this, 'reconcile'],
            10,
            1
        );
        add_action(
            'codigle_paytr_direct_reconcile_attempt_later',
            [$this, 'scheduleReconcile'],
            10,
            2
        );
        add_action(
            'codigle_paytr_direct_schedule_retry',
            [$this, 'scheduleRetry'],
            10,
            1
        );
        add_action(
            'codigle_paytr_direct_schedule_subscription',
            [$this, 'scheduleSubscription'],
            10,
            2
        );

        $this->ensureSweep();
    }

    public function ensureSweep(): void
    {
        if (function_exists('as_next_scheduled_action')) {
            $next = as_next_scheduled_action(
                self::SWEEP_HOOK,
                [],
                self::GROUP
            );

            if ($next === false) {
                as_schedule_recurring_action(
                    time() + 300,
                    HOUR_IN_SECONDS,
                    self::SWEEP_HOOK,
                    [],
                    self::GROUP
                );
            }

            return;
        }

        if (! wp_next_scheduled(self::SWEEP_HOOK)) {
            wp_schedule_event(
                time() + 300,
                'hourly',
                self::SWEEP_HOOK
            );
        }
    }

    public function sweep(): void
    {
        foreach ($this->repository->subscriptionsToExpire(100) as $row) {
            $this->repository->expireSubscription((int) $row['id']);
        }

        if ($this->config->renewalMode() !== 'live') {
            return;
        }

        foreach ($this->repository->dueSubscriptions(100) as $row) {
            $this->scheduleSubscription(
                (int) $row['id'],
                time() + 5
            );
        }
    }

    public function process(int $subscriptionId): void
    {
        if ($this->config->renewalMode() !== 'live') {
            return;
        }

        $result = $this->renewals->process($subscriptionId);

        if (is_wp_error($result)) {
            error_log(
                'CODIGLE_RENEWAL_WORKER_ERROR='
                . sanitize_key($result->get_error_code())
            );
        }
    }

    public function scheduleSubscription(
        int $subscriptionId,
        int $timestamp
    ): void {
        if ($subscriptionId < 1) {
            return;
        }

        $args = [$subscriptionId];

        if (function_exists('as_next_scheduled_action')) {
            $next = as_next_scheduled_action(
                self::PROCESS_HOOK,
                $args,
                self::GROUP
            );

            if ($next === false) {
                as_schedule_single_action(
                    max(time() + 1, $timestamp),
                    self::PROCESS_HOOK,
                    $args,
                    self::GROUP
                );
            }

            return;
        }

        if (! wp_next_scheduled(self::PROCESS_HOOK, $args)) {
            wp_schedule_single_event(
                max(time() + 1, $timestamp),
                self::PROCESS_HOOK,
                $args
            );
        }
    }

    public function scheduleReconcile(
        int $attemptId,
        int $delay = 300
    ): void {
        if ($attemptId < 1) {
            return;
        }

        $args = [$attemptId];

        if (function_exists('as_next_scheduled_action')) {
            $next = as_next_scheduled_action(
                self::RECONCILE_HOOK,
                $args,
                self::GROUP
            );

            if ($next === false) {
                as_schedule_single_action(
                    time() + max(60, $delay),
                    self::RECONCILE_HOOK,
                    $args,
                    self::GROUP
                );
            }

            return;
        }

        if (! wp_next_scheduled(self::RECONCILE_HOOK, $args)) {
            wp_schedule_single_event(
                time() + max(60, $delay),
                self::RECONCILE_HOOK,
                $args
            );
        }
    }

    /**
     * Reconcile a non-terminal recurring attempt against PayTR's authenticated
     * status service. The Action Scheduler may ignore the returned report;
     * WP-CLI uses it for controlled repair output.
     *
     * @return array<string, mixed>
     */
    public function reconcile(
        int $attemptId,
        bool $safeExistingOnly = false
    ): array {
        $attempt = $this->repository->attemptById($attemptId);
        $currentStatus = (string) $attempt['status'];

        if (in_array(
            $currentStatus,
            ['success', 'failed', 'amount_mismatch'],
            true
        )) {
            return [
                'attempt_id' => $attemptId,
                'status' => $currentStatus,
                'already_terminal' => true,
            ];
        }

        $order = wc_get_order((int) $attempt['order_id']);
        $callbackRepair = $this->confirmSignedCallbackEvidence(
            $attempt,
            $order,
            $safeExistingOnly
        );

        if ($callbackRepair !== null) {
            return $callbackRepair;
        }

        $result = $this->status->query(
            (string) $attempt['merchant_oid']
        );
        $count = $this->repository->incrementReconcileCount($attemptId);

        if (! is_wp_error($result)) {
            return $this->confirmProviderSuccess(
                $attempt,
                $result,
                $order,
                $safeExistingOnly
            );
        }

        $errorCode = sanitize_key($result->get_error_code());
        $errorData = $result->get_error_data();
        $errorNumber = is_array($errorData)
            ? (string) ($errorData['err_no'] ?? '')
            : '';
        $submittedAt = strtotime(
            (string) ($attempt['submitted_at_utc'] ?? '') . ' UTC'
        );
        $age = $submittedAt !== false
            ? max(0, time() - $submittedAt)
            : 0;

        if (
            $errorCode === 'codigle_paytr_status_not_found'
            && $errorNumber === '004'
        ) {
            if ($age < 600 && $count < 3) {
                $this->scheduleReconcile($attemptId, 300);

                return [
                    'attempt_id' => $attemptId,
                    'status' => 'payment_pending',
                    'provider' => 'not_found_yet',
                    'age_seconds' => $age,
                    'reconcile_count' => $count,
                ];
            }

            return $this->confirmProviderNotFound(
                $attempt,
                $order,
                $result
            );
        }

        if ($count < 3) {
            $this->scheduleReconcile($attemptId, 300);

            return [
                'attempt_id' => $attemptId,
                'status' => 'payment_pending',
                'provider_error' => $errorCode,
                'reconcile_count' => $count,
            ];
        }

        $this->repository->markAttempt(
            $attemptId,
            'manual_review',
            [
                'failed_reason_code' => $errorCode,
                'failed_reason_msg' => substr(
                    sanitize_text_field($result->get_error_message()),
                    0,
                    500
                ),
            ]
        );

        if ($order instanceof WC_Order) {
            $order->update_status(
                'on-hold',
                'PayTR recurring result could not be reconciled; manual review required.'
            );
            $this->markManagementEventForOrder(
                $order,
                $attemptId,
                $errorCode,
                $result->get_error_message()
            );
        }

        return [
            'attempt_id' => $attemptId,
            'status' => 'manual_review',
            'provider_error' => $errorCode,
            'reconcile_count' => $count,
        ];
    }

    /**
     * Repair a historical callback/request race using only callback evidence
     * that was stored after a valid PayTR hash was verified. This path never
     * advances a subscription period; it is allowed only when the period was
     * already applied by the original callback (or for renewal_test orders).
     *
     * @param array<string, mixed> $attempt
     * @return array<string, mixed>|null
     */
    private function confirmSignedCallbackEvidence(
        array $attempt,
        mixed $order,
        bool $safeExistingOnly
    ): ?array {
        $callbackTime = trim(
            (string) ($attempt['callback_received_at_utc'] ?? '')
        );
        $payload = json_decode(
            (string) ($attempt['callback_payload'] ?? ''),
            true
        );

        if (
            $callbackTime === ''
            || ! is_array($payload)
            || (string) ($payload['status'] ?? '') !== 'success'
        ) {
            return null;
        }

        $attemptId = (int) ($attempt['id'] ?? 0);

        if (! $order instanceof WC_Order || $attemptId < 1) {
            return null;
        }

        $amount = preg_replace(
            '/\D+/',
            '',
            (string) (
                $payload['payment_amount']
                ?? $payload['total_amount']
                ?? ''
            )
        ) ?? '';

        if (
            $amount === ''
            || (int) $amount !== (int) ($attempt['expected_amount_minor'] ?? -1)
        ) {
            return null;
        }

        $attemptType = (string) ($attempt['attempt_type'] ?? '');
        $subscription = $this->repository->subscriptionById(
            (int) ($attempt['subscription_id'] ?? 0)
        );
        $alreadyApplied = $attemptType === 'renewal'
            ? $this->renewalAlreadyApplied($subscription, $order)
            : $attemptType === 'renewal_test';

        if (! $alreadyApplied) {
            return $safeExistingOnly
                ? [
                    'attempt_id' => $attemptId,
                    'status' => 'signed_callback_requires_live_reconcile',
                    'safe_existing_only' => true,
                ]
                : null;
        }

        if (! $this->repository->claimAttempt($attemptId)) {
            $latest = $this->repository->attemptById($attemptId);

            return [
                'attempt_id' => $attemptId,
                'status' => (string) ($latest['status'] ?? 'processing'),
                'callback_or_worker_won_race' => true,
            ];
        }

        $this->repository->markAttempt(
            $attemptId,
            'success',
            [
                'immediate_status' => 'signed_callback_success_repair',
                'immediate_try_again' => 0,
                'failed_reason_code' => '',
                'failed_reason_msg' => '',
            ]
        );

        if ($order->get_transaction_id() === '') {
            $order->set_transaction_id(
                (string) ($attempt['merchant_oid'] ?? '')
            );
        }

        if (! $order->get_date_paid()) {
            $paidAt = function_exists('wc_string_to_datetime')
                ? wc_string_to_datetime($callbackTime . ' UTC')
                : null;

            if ($paidAt instanceof \WC_DateTime) {
                $order->set_date_paid($paidAt);
            }
        }

        $order->save();

        if (! $order->has_status('completed')) {
            $order->update_status(
                'completed',
                'Verified PayTR signed callback evidence repaired a historical renewal race. The subscription period was not advanced again.'
            );
        }
        $order->add_order_note(
            'Historical PayTR callback/request race repaired from previously verified signed callback evidence. No additional charge or period extension was applied.'
        );
        $this->markManagementEventSuccess(
            $order,
            $attemptId,
            $subscription,
            'signed_callback_repair'
        );

        return [
            'attempt_id' => $attemptId,
            'status' => 'success',
            'order_id' => $order->get_id(),
            'subscription_id' => (int) ($attempt['subscription_id'] ?? 0),
            'period_already_applied' => true,
            'confirmation_source' => 'signed_callback_repair',
        ];
    }

    /**
     * @param array<string, mixed> $attempt
     * @param array<string, mixed> $provider
     * @return array<string, mixed>
     */
    private function confirmProviderSuccess(
        array $attempt,
        array $provider,
        mixed $order,
        bool $safeExistingOnly
    ): array {
        $attemptId = (int) $attempt['id'];

        if (! $order instanceof WC_Order) {
            $this->repository->markAttempt(
                $attemptId,
                'manual_review',
                [
                    'failed_reason_code' => 'order_missing',
                    'failed_reason_msg' => 'Provider payment exists but the order could not be loaded.',
                ]
            );

            return [
                'attempt_id' => $attemptId,
                'status' => 'manual_review',
                'reason' => 'order_missing',
            ];
        }

        $expectedMinor = (int) $attempt['expected_amount_minor'];
        $providerMinor = $this->providerAmountMinor(
            (string) ($provider['payment_amount'] ?? '')
        );
        $expectedCurrency = strtoupper((string) $attempt['currency']);
        $providerCurrency = strtoupper(
            (string) ($provider['currency'] ?? '')
        );
        $currencyMatches = $expectedCurrency === $providerCurrency
            || ($expectedCurrency === 'TRY' && $providerCurrency === 'TL')
            || ($expectedCurrency === 'TL' && $providerCurrency === 'TRY');

        if ($providerMinor !== $expectedMinor || ! $currencyMatches) {
            if ($this->repository->claimAttempt($attemptId)) {
                $this->repository->markAttempt(
                    $attemptId,
                    'amount_mismatch',
                    [
                        'immediate_status' => 'status_inquiry_mismatch',
                        'immediate_response' => wp_json_encode($provider),
                        'failed_reason_code' => 'status_amount_mismatch',
                        'failed_reason_msg' => 'PayTR status inquiry amount or currency did not match the renewal order.',
                    ]
                );
                $order->update_status(
                    'on-hold',
                    'PayTR status inquiry amount mismatch; manual review required.'
                );
                $this->markManagementEventForOrder(
                    $order,
                    $attemptId,
                    'status_amount_mismatch',
                    'Provider amount or currency did not match the renewal order.'
                );
            }

            return [
                'attempt_id' => $attemptId,
                'status' => 'amount_mismatch',
                'expected_minor' => $expectedMinor,
                'provider_minor' => $providerMinor,
            ];
        }

        $subscription = $this->repository->subscriptionById(
            (int) ($attempt['subscription_id'] ?? 0)
        );
        $alreadyApplied = $this->renewalAlreadyApplied(
            $subscription,
            $order
        );

        if (
            $safeExistingOnly
            && (string) $attempt['attempt_type'] === 'renewal'
            && ! $alreadyApplied
        ) {
            return [
                'attempt_id' => $attemptId,
                'status' => 'provider_success_requires_live_reconcile',
                'safe_existing_only' => true,
            ];
        }

        if (! $this->repository->claimAttempt($attemptId)) {
            $latest = $this->repository->attemptById($attemptId);

            return [
                'attempt_id' => $attemptId,
                'status' => (string) ($latest['status'] ?? 'processing'),
                'callback_or_worker_won_race' => true,
            ];
        }

        if (! $order->is_paid()) {
            $order->payment_complete((string) $attempt['merchant_oid']);
        }

        $attemptType = (string) $attempt['attempt_type'];
        $updatedSubscription = $subscription;

        if ($attemptType === 'renewal' && ! $alreadyApplied) {
            $updatedSubscription = $this->subscriptions->renew(
                $order,
                $attempt
            );
        } elseif (! in_array($attemptType, ['renewal', 'renewal_test'], true)) {
            $this->repository->markAttempt(
                $attemptId,
                'manual_review',
                [
                    'immediate_status' => 'status_inquiry_success',
                    'immediate_response' => wp_json_encode($provider),
                    'failed_reason_code' => 'unsupported_status_reconcile_type',
                    'failed_reason_msg' => 'This successful provider transaction type requires manual reconciliation.',
                ]
            );
            $order->update_status(
                'on-hold',
                'PayTR payment exists but this transaction type requires manual reconciliation.'
            );

            return [
                'attempt_id' => $attemptId,
                'status' => 'manual_review',
                'reason' => 'unsupported_attempt_type',
            ];
        }

        $this->repository->markAttempt(
            $attemptId,
            'success',
            [
                'immediate_status' => 'status_inquiry_success',
                'immediate_try_again' => 0,
                'immediate_response' => wp_json_encode($provider),
                'failed_reason_code' => '',
                'failed_reason_msg' => '',
            ]
        );

        if (! $order->has_status('completed')) {
            $order->update_status(
                'completed',
                'PayTR authenticated status inquiry confirmed the paid Codigle service order.'
            );
        }
        $order->add_order_note(
            $alreadyApplied
                ? 'PayTR status inquiry confirmed this earlier renewal. The subscription period was already applied and was not advanced again.'
                : 'PayTR status inquiry confirmed the renewal and the subscription period was advanced once.'
        );
        $this->markManagementEventSuccess(
            $order,
            $attemptId,
            $updatedSubscription,
            'status_inquiry'
        );

        return [
            'attempt_id' => $attemptId,
            'status' => 'success',
            'order_id' => $order->get_id(),
            'subscription_id' => (int) ($attempt['subscription_id'] ?? 0),
            'period_already_applied' => $alreadyApplied,
            'confirmation_source' => 'status_inquiry',
        ];
    }

    /**
     * @param array<string, mixed> $attempt
     * @return array<string, mixed>
     */
    private function confirmProviderNotFound(
        array $attempt,
        mixed $order,
        \WP_Error $error
    ): array {
        $attemptId = (int) $attempt['id'];

        if (! $this->repository->claimAttempt($attemptId)) {
            $latest = $this->repository->attemptById($attemptId);

            return [
                'attempt_id' => $attemptId,
                'status' => (string) ($latest['status'] ?? 'processing'),
                'callback_or_worker_won_race' => true,
            ];
        }

        $this->repository->markAttempt(
            $attemptId,
            'failed',
            [
                'immediate_status' => 'status_inquiry_not_found',
                'immediate_try_again' => 0,
                'failed_reason_code' => 'provider_success_not_found',
                'failed_reason_msg' => substr(
                    sanitize_text_field($error->get_error_message()),
                    0,
                    500
                ),
            ]
        );

        if ($order instanceof WC_Order) {
            if (! $order->is_paid()) {
                $order->update_status(
                    'failed',
                    'PayTR status inquiry found no successful payment for this renewal order.'
                );
            }
            $this->subscriptions->renewalFailed($order, $attempt);
            $this->markManagementEventFailed(
                $order,
                $attemptId,
                'provider_success_not_found',
                $error->get_error_message()
            );
        }

        return [
            'attempt_id' => $attemptId,
            'status' => 'failed',
            'no_successful_provider_payment' => true,
        ];
    }

    /**
     * @param array<string, mixed> $subscription
     */
    private function renewalAlreadyApplied(
        array $subscription,
        WC_Order $order
    ): bool {
        if ($subscription === []) {
            return false;
        }

        if ((int) ($subscription['last_renewal_order_id'] ?? 0) === $order->get_id()) {
            return true;
        }

        $periodKey = (string) $order->get_meta(
            '_codigle_renewal_period_key',
            true
        );
        $currentStart = (string) (
            $subscription['current_period_start_utc']
            ?? ''
        );
        $periodTime = $periodKey !== ''
            ? strtotime($periodKey . ' UTC')
            : false;
        $startTime = $currentStart !== ''
            ? strtotime($currentStart . ' UTC')
            : false;

        return $periodTime !== false
            && $startTime !== false
            && $startTime >= $periodTime;
    }

    private function providerAmountMinor(string $value): int
    {
        $normalized = str_replace(',', '.', trim($value));

        if ($normalized === '' || ! is_numeric($normalized)) {
            return -1;
        }

        return (int) round(((float) $normalized) * 100);
    }

    /**
     * @param array<string, mixed> $subscription
     */
    private function markManagementEventSuccess(
        WC_Order $order,
        int $attemptId,
        array $subscription,
        string $source
    ): void {
        $event = $this->repository->subscriptionEventByOrder(
            $order->get_id()
        );
        $eventId = (int) ($event['id'] ?? 0);

        if ($eventId < 1) {
            return;
        }

        $this->repository->markSubscriptionEvent(
            $eventId,
            'success',
            [
                'subscription' => $subscription,
                'payment' => [
                    'order_id' => $order->get_id(),
                    'attempt_id' => $attemptId,
                    'status' => 'success',
                    'confirmation_source' => $source,
                ],
            ],
            $order->get_id(),
            $attemptId
        );
    }

    private function markManagementEventFailed(
        WC_Order $order,
        int $attemptId,
        string $errorCode,
        string $errorMessage
    ): void {
        $event = $this->repository->subscriptionEventByOrder(
            $order->get_id()
        );
        $eventId = (int) ($event['id'] ?? 0);

        if ($eventId < 1) {
            return;
        }

        $this->repository->markSubscriptionEvent(
            $eventId,
            'failed',
            [
                'payment' => [
                    'order_id' => $order->get_id(),
                    'attempt_id' => $attemptId,
                    'status' => 'failed',
                ],
            ],
            $order->get_id(),
            $attemptId,
            $errorCode,
            $errorMessage
        );
    }

    private function markManagementEventForOrder(
        WC_Order $order,
        int $attemptId,
        string $errorCode,
        string $errorMessage
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
            'manual_review',
            [
                'payment' => [
                    'order_id' => $order->get_id(),
                    'attempt_id' => $attemptId,
                    'status' => 'manual_review',
                ],
            ],
            $order->get_id(),
            $attemptId,
            $errorCode,
            $errorMessage
        );
    }

    public function scheduleRetry(int $subscriptionId): void
    {
        $subscription = $this->repository->subscriptionById(
            $subscriptionId
        );

        if ($subscription === []) {
            return;
        }

        $retry = (int) $subscription['retry_count'];
        $delays = [
            1 => DAY_IN_SECONDS,
            2 => 3 * DAY_IN_SECONDS,
            3 => 7 * DAY_IN_SECONDS,
        ];

        if (! isset($delays[$retry])) {
            $grace = (string) (
                $subscription['grace_until_utc']
                ?? ''
            );

            if (
                $grace !== ''
                && strtotime($grace . ' UTC') <= time()
            ) {
                $this->repository->expireSubscription(
                    $subscriptionId
                );
            }

            return;
        }

        $this->scheduleSubscription(
            $subscriptionId,
            time() + $delays[$retry]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function report(int $subscriptionId = 0): array
    {
        $subscriptions = $subscriptionId > 0
            ? [$this->repository->subscriptionById($subscriptionId)]
            : $this->repository->dueSubscriptions(100);

        return [
            'renewal_mode' => $this->config->renewalMode(),
            'test_mode' => $this->config->testMode(),
            'subscriptions' => array_values(
                array_filter($subscriptions)
            ),
        ];
    }
}
