<?php

declare(strict_types=1);

namespace Codigle\PaytrDirect\Cli;

use Codigle\PaytrDirect\Database\Repository;
use Codigle\PaytrDirect\Database\Schema;
use Codigle\PaytrDirect\Paytr\CapiClient;
use Codigle\PaytrDirect\Paytr\StatusClient;
use Codigle\PaytrDirect\Subscription\RenewalScheduler;
use Codigle\PaytrDirect\Subscription\RenewalService;
use Codigle\PaytrDirect\Support\ClientIp;
use Codigle\PaytrDirect\Support\Config;
use Codigle\PaytrDirect\Paytr\TokenService;
use WC_Order;
use WP_CLI;

final class Command
{
    public function __construct(
        private readonly Config $config,
        private readonly Repository $repository,
        private readonly CapiClient $capi,
        private readonly RenewalService $renewals,
        private readonly RenewalScheduler $scheduler,
        private readonly StatusClient $status
    ) {
    }

    public function diagnose(): void
    {
        global $wpdb;

        $tables = Schema::tables();
        $checks = [
            'version' => CODIGLE_PAYTR_DIRECT_VERSION,
            'schema_version' => get_option(
                'codigle_paytr_direct_schema_version',
                ''
            ),
            'rollout' => $this->config->rollout(),
            'renewal_mode' => $this->config->renewalMode(),
            'test_mode' => $this->config->testMode(),
            'credentials' => $this->config->credentialIssues() === []
                ? 'configured'
                : 'missing',
            'callback_url' => $this->config->callbackUrl(),
            'tables' => [],
        ];

        foreach ($tables as $key => $table) {
            $exists = $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $table)
            ) === $table;
            $checks['tables'][$key] = [
                'exists' => $exists,
                'rows' => $exists
                    ? (int) $wpdb->get_var(
                        "SELECT COUNT(*) FROM {$table}"
                    )
                    : 0,
            ];
        }

        WP_CLI::line(
            (string) wp_json_encode(
                $checks,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            )
        );

        if (
            $this->config->credentialIssues() !== []
            || (string) $checks['schema_version'] !== Schema::VERSION
        ) {
            WP_CLI::error('Codigle PayTR Direct diagnostics failed.');
        }

        WP_CLI::success(
            'Codigle PayTR Direct diagnostics passed.'
        );
    }

    /**
     * ## OPTIONS
     * --customer_id=<id>
     */
    public function refresh_cards(
        array $args,
        array $assocArgs
    ): void {
        $userId = absint($assocArgs['customer_id'] ?? 0);

        if ($userId < 1) {
            WP_CLI::error('--customer_id is required.');
        }

        $result = $this->capi->refreshForUser($userId);

        if (is_wp_error($result)) {
            WP_CLI::error(
                $result->get_error_code()
                . ': '
                . $result->get_error_message()
            );
        }

        $cards = array_map(
            static fn (array $card): array => [
                'id' => (int) ($card['id'] ?? 0),
                'last_4' => (string) ($card['last_4'] ?? ''),
                'card_brand' => (string) (
                    $card['card_brand']
                    ?? ''
                ),
                'card_schema' => (string) (
                    $card['card_schema']
                    ?? ''
                ),
                'require_cvv' => (int) (
                    $card['require_cvv']
                    ?? 0
                ),
                'is_default' => (int) (
                    $card['is_default']
                    ?? 0
                ),
                'status' => (string) ($card['status'] ?? ''),
            ],
            $this->repository->cards($userId)
        );

        WP_CLI::line(
            (string) wp_json_encode(
                [
                    'customer_id' => $userId,
                    'refreshed' => count($result),
                    'cards' => $cards,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            )
        );
        WP_CLI::success(
            sprintf('%d card(s) refreshed.', count($result))
        );
    }

    /**
     * ## OPTIONS
     * --subscription_id=<id>
     */
    public function renewal_dry_run(
        array $args,
        array $assocArgs
    ): void {
        $id = absint($assocArgs['subscription_id'] ?? 0);

        if ($id < 1) {
            WP_CLI::error('--subscription_id is required.');
        }

        $result = $this->renewals->dryRun($id);

        if (is_wp_error($result)) {
            WP_CLI::error(
                $result->get_error_code()
                . ': '
                . $result->get_error_message()
            );
        }

        WP_CLI::line(
            (string) wp_json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            )
        );

        if (empty($result['ready'])) {
            WP_CLI::error('Recurring payment is not ready.');
        }

        WP_CLI::success('Recurring dry-run passed.');
    }

    /**
     * Send a test-mode recurring charge without advancing the subscription.
     *
     * ## OPTIONS
     * --subscription_id=<id>
     * --confirm=TEST
     * [--simulate_failure]
     */
    public function renewal_test(
        array $args,
        array $assocArgs
    ): void {
        $id = absint($assocArgs['subscription_id'] ?? 0);
        $confirm = (string) ($assocArgs['confirm'] ?? '');

        if ($id < 1) {
            WP_CLI::error('--subscription_id is required.');
        }

        if ($confirm !== 'TEST') {
            WP_CLI::error('--confirm=TEST is required.');
        }

        if (! $this->config->testMode()) {
            WP_CLI::error(
                'PayTR test mode must be enabled for renewal_test.'
            );
        }

        $result = $this->renewals->process(
            $id,
            true,
            true,
            isset($assocArgs['simulate_failure'])
        );

        if (is_wp_error($result)) {
            WP_CLI::error(
                $result->get_error_code()
                . ': '
                . $result->get_error_message()
            );
        }

        WP_CLI::line(
            (string) wp_json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            )
        );

        if (
            (string) ($result['status'] ?? '') === 'failed'
            && ! empty($result['final'])
        ) {
            WP_CLI::error(
                'PayTR rejected the recurring test. '
                . 'No callback is expected.'
            );
        }

        if (! empty($result['retryable'])) {
            WP_CLI::warning(
                'PayTR reported a temporary conflict. '
                . 'Do not retry immediately.'
            );

            return;
        }

        if (! empty($result['waiting_for_callback'])) {
            WP_CLI::success(
                'Recurring test accepted. Wait for signed callback.'
            );

            return;
        }

        WP_CLI::success('Recurring test completed.');
    }

    /**
     * Repair an attempt incorrectly left waiting after an immediate,
     * non-retryable PayTR failure.
     *
     * ## OPTIONS
     * --attempt_id=<id>
     * --confirm=FAILED
     */
    public function repair_attempt(
        array $args,
        array $assocArgs
    ): void {
        $id = absint($assocArgs['attempt_id'] ?? 0);
        $confirm = (string) ($assocArgs['confirm'] ?? '');

        if ($id < 1) {
            WP_CLI::error('--attempt_id is required.');
        }

        if ($confirm !== 'FAILED') {
            WP_CLI::error('--confirm=FAILED is required.');
        }

        $attempt = $this->repository->attemptById($id);

        if (
            (string) ($attempt['immediate_status'] ?? '') !== 'failed'
            || (int) ($attempt['immediate_try_again'] ?? 1) !== 0
            || ! empty($attempt['callback_received_at_utc'])
            || ! in_array(
                (string) ($attempt['status'] ?? ''),
                ['submitted', 'wait_callback', 'processing'],
                true
            )
        ) {
            WP_CLI::error(
                'Attempt is not an eligible immediate-failure repair.'
            );
        }

        $order = wc_get_order((int) $attempt['order_id']);

        if (! $order instanceof WC_Order) {
            WP_CLI::error('Attempt order could not be loaded.');
        }

        if ($order->is_paid()) {
            WP_CLI::error('Paid orders cannot be repaired as failed.');
        }

        $decoded = json_decode(
            (string) ($attempt['immediate_response'] ?? ''),
            true
        );
        $message = '';

        if (is_array($decoded)) {
            $message = trim(
                sanitize_text_field(
                    (string) ($decoded['msg'] ?? '')
                )
            );
        }

        if ($message === '') {
            $message = (
                'PayTR rejected the recurring payment '
                . 'without returning a reason.'
            );
        }

        $this->repository->markAttempt(
            $id,
            'failed',
            [
                'failed_reason_code' => 'paytr_immediate_failed',
                'failed_reason_msg' => $message,
            ]
        );
        $order->update_status(
            'failed',
            'PayTR recurring payment rejected: ' . $message
        );

        WP_CLI::line(
            (string) wp_json_encode(
                [
                    'attempt_id' => $id,
                    'order_id' => $order->get_id(),
                    'attempt_status' => 'failed',
                    'order_status' => $order->get_status(),
                    'subscription_changed' => false,
                    'reason' => $message,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            )
        );
        WP_CLI::success('Immediate failure state repaired.');
    }

    /**
     * Repair callback-confirmed renewal attempts that were downgraded to
     * wait_callback/on-hold by the original request after the callback won a
     * race. Subscription periods are never advanced by this command.
     *
     * ## OPTIONS
     * --confirm=REPAIR
     */
    public function repair_callback_races(
        array $args,
        array $assocArgs
    ): void {
        global $wpdb;

        if ((string) ($assocArgs['confirm'] ?? '') !== 'REPAIR') {
            WP_CLI::error('--confirm=REPAIR is required.');
        }

        $attemptsTable = Schema::tables()['attempts'];
        $subscriptionsTable = Schema::tables()['subscriptions'];
        $rows = $wpdb->get_results(
            "SELECT a.*
             FROM {$attemptsTable} a
             INNER JOIN {$subscriptionsTable} s
                ON s.id = a.subscription_id
             WHERE a.attempt_type = 'renewal'
               AND a.status IN ('submitted', 'wait_callback', 'processing')
               AND a.callback_received_at_utc IS NOT NULL
               AND a.callback_received_at_utc <> ''
               AND a.immediate_status = 'success'
               AND s.last_renewal_order_id = a.order_id
             ORDER BY a.id ASC",
            ARRAY_A
        );

        $report = [];

        foreach (is_array($rows) ? $rows : [] as $attempt) {
            $attemptId = (int) ($attempt['id'] ?? 0);
            $order = wc_get_order((int) ($attempt['order_id'] ?? 0));

            if (! $order instanceof WC_Order || $attemptId < 1) {
                $report[] = [
                    'attempt_id' => $attemptId,
                    'status' => 'skipped',
                    'reason' => 'order_missing',
                ];
                continue;
            }

            $provider = $this->status->query(
                (string) ($attempt['merchant_oid'] ?? '')
            );

            if (
                is_wp_error($provider)
                || (string) ($provider['status'] ?? '') !== 'success'
            ) {
                $report[] = [
                    'attempt_id' => $attemptId,
                    'order_id' => $order->get_id(),
                    'status' => 'skipped',
                    'reason' => is_wp_error($provider)
                        ? $provider->get_error_code()
                        : 'provider_not_success',
                ];
                continue;
            }

            $this->repository->markAttempt(
                $attemptId,
                'success',
                [
                    'failed_reason_code' => '',
                    'failed_reason_msg' => '',
                ]
            );

            if ($order->has_status('on-hold')) {
                $order->update_status(
                    $order->needs_processing() ? 'processing' : 'completed',
                    'Callback race repaired: PayTR and the stored callback confirm payment success. The subscription period was not changed again.'
                );
            }

            $event = $this->repository->subscriptionEventByOrder(
                $order->get_id()
            );
            $eventId = (int) ($event['id'] ?? 0);

            if ($eventId > 0) {
                $subscription = $this->repository->subscriptionById(
                    (int) ($attempt['subscription_id'] ?? 0)
                );
                $this->repository->markSubscriptionEvent(
                    $eventId,
                    'success',
                    [
                        'repair' => 'callback_race_0.5.1',
                        'subscription_id' => (int) (
                            $subscription['id']
                            ?? 0
                        ),
                        'current_period_end_utc' => (string) (
                            $subscription['current_period_end_utc']
                            ?? ''
                        ),
                        'payment' => [
                            'order_id' => $order->get_id(),
                            'attempt_id' => $attemptId,
                            'status' => 'success',
                        ],
                    ],
                    $order->get_id(),
                    $attemptId
                );
            }

            $report[] = [
                'attempt_id' => $attemptId,
                'order_id' => $order->get_id(),
                'attempt_status' => 'success',
                'order_status' => $order->get_status(),
                'subscription_advanced' => false,
                'provider_status' => 'success',
            ];
        }

        WP_CLI::line(
            (string) wp_json_encode(
                [
                    'eligible' => count(is_array($rows) ? $rows : []),
                    'results' => $report,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            )
        );
        WP_CLI::success('Callback-race repair completed.');
    }

    /**
     * ## OPTIONS
     * <mode>
     * : off, manual or live
     */
    public function renewal_mode(array $args): void
    {
        $mode = sanitize_key((string) ($args[0] ?? ''));

        if (! in_array($mode, ['off', 'manual', 'live'], true)) {
            WP_CLI::error('Mode must be off, manual or live.');
        }

        $settings = $this->config->gatewaySettings();
        $settings['renewal_mode'] = $mode;
        update_option(
            'woocommerce_' . Config::GATEWAY_ID . '_settings',
            $settings,
            false
        );
        update_option(
            'codigle_paytr_direct_renewal_mode',
            $mode,
            false
        );

        if ($mode === 'live') {
            $this->scheduler->sweep();
        }

        WP_CLI::success('Renewal mode set to ' . $mode . '.');
    }

    /**
     * ## OPTIONS
     * [--subscription_id=<id>]
     */
    public function renewal_status(
        array $args,
        array $assocArgs
    ): void {
        $id = absint($assocArgs['subscription_id'] ?? 0);
        $report = $this->scheduler->report($id);

        WP_CLI::line(
            (string) wp_json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            )
        );
    }

    /**
     * Reconcile one non-terminal attempt using PayTR's authenticated status
     * inquiry. This does not submit a new charge.
     *
     * ## OPTIONS
     * --attempt_id=<id>
     * --confirm=RECONCILE
     * [--safe_existing]
     */
    public function reconcile_attempt(
        array $args,
        array $assocArgs
    ): void {
        $attemptId = absint($assocArgs['attempt_id'] ?? 0);

        if ($attemptId < 1) {
            WP_CLI::error('--attempt_id is required.');
        }
        if ((string) ($assocArgs['confirm'] ?? '') !== 'RECONCILE') {
            WP_CLI::error('--confirm=RECONCILE is required.');
        }

        $result = $this->scheduler->reconcile(
            $attemptId,
            isset($assocArgs['safe_existing'])
        );
        WP_CLI::line((string) wp_json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ));
        WP_CLI::success('Attempt reconciliation finished.');
    }

    /**
     * Reconcile old non-terminal renewal attempts without submitting charges.
     *
     * ## OPTIONS
     * --confirm=RECONCILE
     * [--min_age=<seconds>]
     * [--safe_existing]
     */
    public function reconcile_pending(
        array $args,
        array $assocArgs
    ): void {
        if ((string) ($assocArgs['confirm'] ?? '') !== 'RECONCILE') {
            WP_CLI::error('--confirm=RECONCILE is required.');
        }

        global $wpdb;
        $minimumAge = max(60, absint($assocArgs['min_age'] ?? 600));
        $table = Schema::tables()['attempts'];
        $cutoff = gmdate('Y-m-d H:i:s', time() - $minimumAge);
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM {$table}
                 WHERE attempt_type IN ('renewal','renewal_test')
                   AND status IN ('submitted','wait_callback','processing','manual_review')
                   AND submitted_at_utc IS NOT NULL
                   AND submitted_at_utc <= %s
                 ORDER BY id ASC
                 LIMIT 100",
                $cutoff
            ),
            ARRAY_A
        );
        $reports = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $reports[] = $this->scheduler->reconcile(
                (int) $row['id'],
                isset($assocArgs['safe_existing'])
            );
        }

        WP_CLI::line((string) wp_json_encode(
            [
                'minimum_age_seconds' => $minimumAge,
                'processed' => count($reports),
                'results' => $reports,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ));
        WP_CLI::success('Pending reconciliation scan finished.');
    }

    /**
     * Align WooCommerce order status with terminal PayTR attempt state. No
     * payment is submitted and no subscription period is changed.
     *
     * ## OPTIONS
     * --confirm=REPAIR
     */
    public function repair_order_statuses(
        array $args,
        array $assocArgs
    ): void {
        if ((string) ($assocArgs['confirm'] ?? '') !== 'REPAIR') {
            WP_CLI::error('--confirm=REPAIR is required.');
        }

        global $wpdb;
        $table = Schema::tables()['attempts'];
        $rows = $wpdb->get_results(
            "SELECT order_id,status
             FROM {$table}
             WHERE status IN ('success','failed')
               AND order_id > 0
             ORDER BY id ASC",
            ARRAY_A
        );
        $completed = [];
        $failed = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $orderId = (int) ($row['order_id'] ?? 0);
            $attemptStatus = (string) ($row['status'] ?? '');
            $order = wc_get_order($orderId);

            if (! $order instanceof WC_Order) {
                continue;
            }

            if (
                $attemptStatus === 'success'
                && $order->is_paid()
                && ! $order->has_status('completed')
            ) {
                $order->update_status(
                    'completed',
                    'Verified PayTR payment: Codigle service order completed.'
                );
                $completed[] = $orderId;
                continue;
            }

            if (
                $attemptStatus === 'failed'
                && ! $order->is_paid()
                && ! $order->has_status(['failed', 'cancelled'])
            ) {
                $order->update_status(
                    'failed',
                    'PayTR payment attempt finished without a successful payment.'
                );
                $failed[] = $orderId;
            }
        }

        WP_CLI::line((string) wp_json_encode(
            [
                'completed_order_ids' => array_values(array_unique($completed)),
                'failed_order_ids' => array_values(array_unique($failed)),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ));
        WP_CLI::success(sprintf(
            '%d paid order(s) completed; %d failed order(s) aligned.',
            count(array_unique($completed)),
            count(array_unique($failed))
        ));
    }

    /**
     * ## OPTIONS
     * --attempt_id=<id>
     */
    public function payment_status(
        array $args,
        array $assocArgs
    ): void {
        $id = absint($assocArgs['attempt_id'] ?? 0);

        if ($id < 1) {
            WP_CLI::error('--attempt_id is required.');
        }

        $attempt = $this->repository->attemptById($id);
        $result = $this->status->query(
            (string) $attempt['merchant_oid']
        );

        if (is_wp_error($result)) {
            WP_CLI::error(
                $result->get_error_code()
                . ': '
                . $result->get_error_message()
            );
        }

        WP_CLI::line(
            (string) wp_json_encode(
                [
                    'attempt_id' => $id,
                    'merchant_oid' => (string) $attempt['merchant_oid'],
                    'provider' => $result,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            )
        );
    }

    /**
     * [--order=<id>]
     */
    public function token_contract(
        array $args,
        array $assocArgs
    ): void {
        global $wpdb;

        $orderId = absint($assocArgs['order'] ?? 0);
        $attempts = Schema::tables()['attempts'];

        if ($orderId > 0) {
            $attempt = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$attempts}
                     WHERE order_id = %d
                     ORDER BY id DESC
                     LIMIT 1",
                    $orderId
                ),
                ARRAY_A
            );
        } else {
            $attempt = $wpdb->get_row(
                "SELECT * FROM {$attempts}
                 ORDER BY id DESC
                 LIMIT 1",
                ARRAY_A
            );
        }

        if (! is_array($attempt)) {
            WP_CLI::error('No Direct API attempt was found.');
        }

        $order = wc_get_order((int) $attempt['order_id']);

        if (! $order instanceof WC_Order) {
            WP_CLI::error('The attempt order could not be loaded.');
        }

        $clientIp = new ClientIp($this->config);
        $service = new TokenService(
            $this->config,
            $clientIp
        );
        $fields = $service->baseFields($order, $attempt);
        $required = [
            'merchant_id',
            'user_ip',
            'merchant_oid',
            'email',
            'payment_amount',
            'payment_type',
            'installment_count',
            'currency',
            'test_mode',
            'non_3d',
            'paytr_token',
        ];
        $missing = [];

        foreach ($required as $name) {
            if (
                ! array_key_exists($name, $fields)
                || (string) $fields[$name] === ''
            ) {
                $missing[] = $name;
            }
        }

        $report = [
            'plugin_version' => CODIGLE_PAYTR_DIRECT_VERSION,
            'order_id' => $order->get_id(),
            'attempt_id' => (int) $attempt['id'],
            'merchant_oid' => (string) $attempt['merchant_oid'],
            'checks' => [
                'required_fields_present' => $missing === [],
                'missing_fields' => $missing,
                'exact_paytr_formula_matches' =>
                    $service->verifyFields($fields),
                'token_decoded_bytes' => strlen(
                    (string) (
                        base64_decode(
                            (string) $fields['paytr_token'],
                            true
                        )
                        ?: ''
                    )
                ),
            ],
        ];

        WP_CLI::line(
            (string) wp_json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            )
        );

        if (
            $missing !== []
            || ! $service->verifyFields($fields)
        ) {
            WP_CLI::error(
                'Direct API token contract failed locally.'
            );
        }

        WP_CLI::success(
            'Direct API token contract passed locally.'
        );
    }

    /**
     * <mode>: off, admin or public
     */
    public function rollout(array $args): void
    {
        $mode = sanitize_key((string) ($args[0] ?? ''));

        if (! in_array($mode, ['off', 'admin', 'public'], true)) {
            WP_CLI::error('Mode must be off, admin or public.');
        }

        $settings = $this->config->gatewaySettings();
        $settings['rollout'] = $mode;
        update_option(
            'woocommerce_' . Config::GATEWAY_ID . '_settings',
            $settings,
            false
        );
        update_option(
            'codigle_paytr_direct_rollout',
            $mode,
            false
        );

        WP_CLI::success('Rollout set to ' . $mode . '.');
    }
}
