<?php

declare(strict_types=1);

namespace Codigle\PaytrDirect\Database;

use Codigle\PaytrDirect\Support\Crypto;
use RuntimeException;
use WC_Order;

final class Repository
{
    public function __construct(
        private readonly Crypto $crypto
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function ensureAttempt(
        WC_Order $order,
        string $type = 'initial',
        int $subscriptionId = 0
    ): array {
        global $wpdb;

        $table = Schema::tables()['attempts'];
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE order_id = %d
                   AND attempt_type = %s
                   AND status IN (
                        'created',
                        'submitted',
                        'wait_callback',
                        'processing',
                        'manual_review'
                   )
                 ORDER BY id DESC
                 LIMIT 1",
                $order->get_id(),
                $type
            ),
            ARRAY_A
        );

        if (is_array($existing)) {
            return $existing;
        }

        $retryNumber = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE order_id = %d
                   AND attempt_type = %s",
                $order->get_id(),
                $type
            )
        );
        $merchantOid = $this->merchantOid($order, $type);
        $now = gmdate('Y-m-d H:i:s');
        $amountMinor = (int) round(
            (float) $order->get_total() * 100
        );
        $currency = strtoupper($order->get_currency());

        $inserted = $wpdb->insert(
            $table,
            [
                'order_id' => $order->get_id(),
                'subscription_id' => $subscriptionId > 0
                    ? $subscriptionId
                    : null,
                'merchant_oid' => $merchantOid,
                'attempt_type' => $type,
                'status' => 'created',
                'expected_amount_minor' => $amountMinor,
                'currency' => $currency,
                'test_mode' => 0,
                'retry_number' => $retryNumber,
                'created_at_utc' => $now,
                'updated_at_utc' => $now,
            ]
        );

        if ($inserted !== 1) {
            throw new RuntimeException(
                'Payment attempt could not be created.'
            );
        }

        return $this->attemptById((int) $wpdb->insert_id);
    }

    /**
     * @return array<string, mixed>
     */
    public function attemptByOid(string $merchantOid): array
    {
        global $wpdb;

        $table = Schema::tables()['attempts'];
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE merchant_oid = %s
                 LIMIT 1",
                $merchantOid
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function attemptById(int $id): array
    {
        global $wpdb;

        $table = Schema::tables()['attempts'];
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d LIMIT 1",
                $id
            ),
            ARRAY_A
        );

        if (! is_array($row)) {
            throw new RuntimeException('Payment attempt was not found.');
        }

        return $row;
    }

    public function markAttempt(
        int $id,
        string $status,
        array $extra = []
    ): void {
        global $wpdb;

        $table = Schema::tables()['attempts'];
        $data = array_merge(
            [
                'status' => $status,
                'updated_at_utc' => gmdate('Y-m-d H:i:s'),
            ],
            $extra
        );

        $updated = $wpdb->update(
            $table,
            $data,
            ['id' => $id]
        );

        if ($updated === false) {
            throw new RuntimeException(
                'Payment attempt could not be updated.'
            );
        }
    }

    /**
     * Atomically move an attempt only while it is still in an expected
     * non-terminal state. This prevents a fast signed callback from being
     * overwritten by the slower HTTP request that originally submitted the
     * recurring charge.
     *
     * @param list<string> $fromStatuses
     * @param array<string, int|string|null> $extra
     */
    public function transitionAttempt(
        int $id,
        array $fromStatuses,
        string $status,
        array $extra = []
    ): bool {
        global $wpdb;

        $fromStatuses = array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn (mixed $value): string => sanitize_key(
                            (string) $value
                        ),
                        $fromStatuses
                    )
                )
            )
        );

        if ($id < 1 || $fromStatuses === []) {
            throw new RuntimeException(
                'Attempt transition requires an ID and source status.'
            );
        }

        $allowed = [
            'test_mode',
            'immediate_status',
            'immediate_try_again',
            'immediate_response',
            'submitted_at_utc',
            'callback_payload',
            'failed_reason_code',
            'failed_reason_msg',
            'callback_received_at_utc',
        ];
        $data = [
            'status' => sanitize_key($status),
            'updated_at_utc' => gmdate('Y-m-d H:i:s'),
        ];

        foreach ($extra as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                throw new RuntimeException(
                    'Unsupported atomic attempt field: ' . $key
                );
            }

            $data[$key] = $value;
        }

        $assignments = [];
        $values = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                $assignments[] = "`{$key}` = NULL";
                continue;
            }

            $assignments[] = "`{$key}` = %s";
            $values[] = (string) $value;
        }

        $statusPlaceholders = implode(
            ', ',
            array_fill(0, count($fromStatuses), '%s')
        );
        $values[] = $id;
        array_push($values, ...$fromStatuses);
        $table = Schema::tables()['attempts'];
        $sql = "UPDATE {$table} SET "
            . implode(', ', $assignments)
            . " WHERE id = %d AND status IN ({$statusPlaceholders})";
        $affected = $wpdb->query($wpdb->prepare($sql, ...$values));

        if ($affected === false) {
            throw new RuntimeException(
                'Payment attempt transition could not be completed.'
            );
        }

        return $affected === 1;
    }

    public function incrementReconcileCount(int $id): int
    {
        global $wpdb;

        $table = Schema::tables()['attempts'];
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET reconcile_count = reconcile_count + 1,
                     updated_at_utc = %s
                 WHERE id = %d",
                gmdate('Y-m-d H:i:s'),
                $id
            )
        );

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT reconcile_count FROM {$table} WHERE id = %d",
                $id
            )
        );
    }

    public function claimAttempt(int $id): bool
    {
        global $wpdb;

        $table = Schema::tables()['attempts'];
        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET status = 'processing',
                     updated_at_utc = %s
                 WHERE id = %d
                   AND (
                        status IN (
                            'created',
                            'submitted',
                            'wait_callback',
                            'manual_review'
                        )
                        OR (
                            status = 'processing'
                            AND updated_at_utc
                                < UTC_TIMESTAMP() - INTERVAL 5 MINUTE
                        )
                   )",
                gmdate('Y-m-d H:i:s'),
                $id
            )
        );

        return $affected === 1;
    }

    public function saveCustomerToken(
        int $userId,
        string $utoken
    ): void {
        global $wpdb;

        if ($userId < 1 || $utoken === '') {
            return;
        }

        $table = Schema::tables()['customers'];
        $now = gmdate('Y-m-d H:i:s');
        $hash = $this->crypto->hash($utoken);
        $encrypted = $this->crypto->encrypt($utoken);
        $tokenOwner = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT user_id FROM {$table}
                 WHERE utoken_hash = %s
                 LIMIT 1",
                $hash
            )
        );

        if ($tokenOwner > 0 && $tokenOwner !== $userId) {
            throw new RuntimeException(
                'PayTR customer token is already linked to another account.'
            );
        }

        $existingId = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE user_id = %d",
                $userId
            )
        );

        if ($existingId > 0) {
            $updated = $wpdb->update(
                $table,
                [
                    'utoken_encrypted' => $encrypted,
                    'utoken_hash' => $hash,
                    'status' => 'active',
                    'updated_at_utc' => $now,
                ],
                ['id' => $existingId]
            );

            if ($updated === false) {
                throw new RuntimeException(
                    'PayTR customer token could not be updated.'
                );
            }

            return;
        }

        $inserted = $wpdb->insert(
            $table,
            [
                'user_id' => $userId,
                'utoken_encrypted' => $encrypted,
                'utoken_hash' => $hash,
                'status' => 'active',
                'created_at_utc' => $now,
                'updated_at_utc' => $now,
            ]
        );

        if ($inserted !== 1) {
            throw new RuntimeException(
                'PayTR customer token could not be stored.'
            );
        }
    }

    public function userToken(int $userId): string
    {
        global $wpdb;

        if ($userId < 1) {
            return '';
        }

        $table = Schema::tables()['customers'];
        $encrypted = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT utoken_encrypted
                 FROM {$table}
                 WHERE user_id = %d
                   AND status = 'active'
                 LIMIT 1",
                $userId
            )
        );

        if (! is_string($encrypted) || $encrypted === '') {
            return '';
        }

        return $this->crypto->decrypt($encrypted);
    }

    /**
     * @param list<array<string, mixed>> $cards
     */
    public function syncCards(
        int $userId,
        array $cards
    ): void {
        global $wpdb;

        if ($userId < 1) {
            return;
        }

        $table = Schema::tables()['cards'];
        $now = gmdate('Y-m-d H:i:s');
        $seen = [];

        foreach ($cards as $index => $card) {
            $ctoken = trim((string) ($card['ctoken'] ?? ''));

            if ($ctoken === '') {
                continue;
            }

            $hash = $this->crypto->hash($ctoken);
            $seen[] = $hash;
            $data = [
                'user_id' => $userId,
                'ctoken_encrypted' => $this->crypto->encrypt($ctoken),
                'ctoken_hash' => $hash,
                'last_4' => substr(
                    preg_replace(
                        '/\D+/',
                        '',
                        (string) ($card['last_4'] ?? '')
                    ) ?? '',
                    -4
                ),
                'expiry_month' => substr(
                    (string) ($card['month'] ?? ''),
                    0,
                    2
                ),
                'expiry_year' => substr(
                    (string) ($card['year'] ?? ''),
                    0,
                    4
                ),
                'bank_name' => substr(
                    sanitize_text_field(
                        (string) ($card['c_bank'] ?? '')
                    ),
                    0,
                    120
                ),
                'card_brand' => substr(
                    sanitize_text_field(
                        (string) ($card['c_brand'] ?? '')
                    ),
                    0,
                    80
                ),
                'card_type' => substr(
                    sanitize_text_field(
                        (string) ($card['c_type'] ?? '')
                    ),
                    0,
                    20
                ),
                'card_schema' => substr(
                    sanitize_text_field(
                        (string) ($card['schema'] ?? '')
                    ),
                    0,
                    30
                ),
                'business_card' => (
                    (string) ($card['businessCard'] ?? 'n')
                ) === 'y' ? 1 : 0,
                'require_cvv' => (
                    (string) ($card['require_cvv'] ?? '0')
                ) === '1' ? 1 : 0,
                'is_default' => $index === 0 ? 1 : 0,
                'status' => 'active',
                'refreshed_at_utc' => $now,
                'updated_at_utc' => $now,
            ];

            $existingId = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table}
                     WHERE user_id = %d
                       AND ctoken_hash = %s
                     LIMIT 1",
                    $userId,
                    $hash
                )
            );

            if ($existingId > 0) {
                $updated = $wpdb->update(
                    $table,
                    $data,
                    ['id' => $existingId]
                );

                if ($updated === false) {
                    throw new RuntimeException(
                        'Saved card could not be updated.'
                    );
                }
            } else {
                $data['created_at_utc'] = $now;
                $inserted = $wpdb->insert($table, $data);

                if ($inserted !== 1) {
                    throw new RuntimeException(
                        'Saved card could not be stored.'
                    );
                }
            }
        }

        if ($seen === []) {
            $wpdb->update(
                $table,
                [
                    'status' => 'missing',
                    'is_default' => 0,
                    'updated_at_utc' => $now,
                ],
                ['user_id' => $userId]
            );

            return;
        }

        $placeholders = implode(
            ',',
            array_fill(0, count($seen), '%s')
        );
        $params = array_merge([$userId], $seen);
        $sql = $wpdb->prepare(
            "UPDATE {$table}
             SET status = 'missing',
                 is_default = 0,
                 updated_at_utc = UTC_TIMESTAMP()
             WHERE user_id = %d
               AND ctoken_hash NOT IN ({$placeholders})",
            ...$params
        );
        $wpdb->query($sql);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function cards(int $userId): array
    {
        global $wpdb;

        $table = Schema::tables()['cards'];
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                 FROM {$table}
                 WHERE user_id = %d
                   AND status = 'active'
                 ORDER BY is_default DESC, id ASC",
                $userId
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function cardById(int $cardId, int $userId): array
    {
        global $wpdb;

        $table = Schema::tables()['cards'];
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE id = %d
                   AND user_id = %d
                   AND status = 'active'
                 LIMIT 1",
                $cardId,
                $userId
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : [];
    }

    public function cardToken(int $cardId, int $userId): string
    {
        global $wpdb;

        $table = Schema::tables()['cards'];
        $encrypted = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ctoken_encrypted
                 FROM {$table}
                 WHERE id = %d
                   AND user_id = %d
                   AND status = 'active'
                 LIMIT 1",
                $cardId,
                $userId
            )
        );

        return is_string($encrypted) && $encrypted !== ''
            ? $this->crypto->decrypt($encrypted)
            : '';
    }

    public function defaultCardId(int $userId): int
    {
        global $wpdb;

        $table = Schema::tables()['cards'];

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table}
                 WHERE user_id = %d
                   AND status = 'active'
                 ORDER BY is_default DESC, id ASC
                 LIMIT 1",
                $userId
            )
        );
    }

    public function cardIdByToken(int $userId, string $ctoken): int
    {
        global $wpdb;

        $ctoken = trim($ctoken);

        if ($userId < 1 || $ctoken === '') {
            return 0;
        }

        $table = Schema::tables()['cards'];
        $hash = $this->crypto->hash($ctoken);

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table}
                 WHERE user_id = %d
                   AND ctoken_hash = %s
                   AND status = 'active'
                 LIMIT 1",
                $userId,
                $hash
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function setSubscriptionCard(
        int $subscriptionId,
        int $userId,
        int $cardId
    ): array {
        global $wpdb;

        if ($this->cardById($cardId, $userId) === []) {
            throw new RuntimeException(
                'The selected saved card does not belong to this customer.'
            );
        }

        $updated = $wpdb->update(
            Schema::tables()['subscriptions'],
            [
                'payment_card_id' => $cardId,
                'updated_at_utc' => gmdate('Y-m-d H:i:s'),
            ],
            [
                'id' => $subscriptionId,
                'user_id' => $userId,
            ]
        );

        if ($updated === false) {
            throw new RuntimeException(
                'The subscription payment method could not be updated.'
            );
        }

        return $this->subscriptionForUser($subscriptionId, $userId);
    }

    public function attachDefaultCardToOpenSubscriptions(int $userId): void
    {
        global $wpdb;

        $cardId = $this->defaultCardId($userId);

        if ($cardId < 1) {
            return;
        }

        $table = Schema::tables()['subscriptions'];
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET payment_card_id = %d,
                     updated_at_utc = %s
                 WHERE user_id = %d
                   AND payment_card_id IS NULL
                   AND status IN ('active','past_due')",
                $cardId,
                gmdate('Y-m-d H:i:s'),
                $userId
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function createSubscription(
        WC_Order $order,
        int $cardId = 0
    ): array {
        global $wpdb;

        $table = Schema::tables()['subscriptions'];
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE initial_order_id = %d
                 LIMIT 1",
                $order->get_id()
            ),
            ARRAY_A
        );

        if (is_array($existing)) {
            return $existing;
        }

        $item = current($order->get_items());

        if (! $item) {
            throw new RuntimeException(
                'Subscription order has no line item.'
            );
        }

        $productId = (int) $item->get_product_id();
        $duration = max(
            1,
            (int) get_post_meta(
                $productId,
                '_cpb_duration_months',
                true
            )
        );
        $planPageId = (int) get_post_meta(
            $productId,
            '_cpb_plan_page_id',
            true
        );
        $start = new \DateTimeImmutable(
            'now',
            new \DateTimeZone('UTC')
        );
        $end = $start->modify('+' . $duration . ' months');
        $now = gmdate('Y-m-d H:i:s');

        $inserted = $wpdb->insert(
            $table,
            [
                'user_id' => $order->get_customer_id(),
                'initial_order_id' => $order->get_id(),
                'product_id' => $productId,
                'plan_page_id' => $planPageId,
                'duration_months' => $duration,
                'payment_card_id' => $cardId > 0 ? $cardId : null,
                'amount' => $order->get_total(),
                'currency' => $order->get_currency(),
                'status' => 'active',
                'auto_renew' => 1,
                'cancel_at_period_end' => 0,
                'current_period_start_utc' => $start->format(
                    'Y-m-d H:i:s'
                ),
                'current_period_end_utc' => $end->format(
                    'Y-m-d H:i:s'
                ),
                'next_payment_at_utc' => $end->format(
                    'Y-m-d H:i:s'
                ),
                'last_payment_at_utc' => $start->format(
                    'Y-m-d H:i:s'
                ),
                'retry_count' => 0,
                'created_at_utc' => $now,
                'updated_at_utc' => $now,
            ]
        );

        if ($inserted !== 1 || (int) $wpdb->insert_id < 1) {
            throw new RuntimeException(
                'Subscription could not be created.'
            );
        }

        $order->update_meta_data(
            '_codigle_subscription_id',
            (int) $wpdb->insert_id
        );
        $order->update_meta_data(
            '_codigle_subscription_duration_months',
            $duration
        );
        $order->update_meta_data(
            '_codigle_subscription_period_end_utc',
            $end->format(DATE_ATOM)
        );
        $order->save();

        return $this->subscriptionById((int) $wpdb->insert_id);
    }

    /**
     * @return array<string, mixed>
     */
    public function subscriptionById(int $id): array
    {
        global $wpdb;

        $table = Schema::tables()['subscriptions'];
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d LIMIT 1",
                $id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function subscriptions(int $userId): array
    {
        global $wpdb;

        $table = Schema::tables()['subscriptions'];
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                 FROM {$table}
                 WHERE user_id = %d
                 ORDER BY id DESC",
                $userId
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function dueSubscriptions(int $limit = 50): array
    {
        global $wpdb;

        $table = Schema::tables()['subscriptions'];
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE status IN ('active','past_due')
                   AND auto_renew = 1
                   AND cancel_at_period_end = 0
                   AND next_payment_at_utc IS NOT NULL
                   AND next_payment_at_utc <= UTC_TIMESTAMP()
                 ORDER BY next_payment_at_utc ASC
                 LIMIT %d",
                max(1, min(500, $limit))
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    public function acquireRenewalLock(
        int $subscriptionId,
        int $minutes = 15
    ): bool {
        global $wpdb;

        $table = Schema::tables()['subscriptions'];
        $until = gmdate(
            'Y-m-d H:i:s',
            time() + max(1, $minutes) * MINUTE_IN_SECONDS
        );
        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET renewal_lock_until_utc = %s,
                     updated_at_utc = %s
                 WHERE id = %d
                   AND (
                        renewal_lock_until_utc IS NULL
                        OR renewal_lock_until_utc < UTC_TIMESTAMP()
                   )",
                $until,
                gmdate('Y-m-d H:i:s'),
                $subscriptionId
            )
        );

        return $affected === 1;
    }

    public function releaseRenewalLock(int $subscriptionId): void
    {
        global $wpdb;

        $table = Schema::tables()['subscriptions'];
        $wpdb->update(
            $table,
            [
                'renewal_lock_until_utc' => null,
                'updated_at_utc' => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $subscriptionId]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function advanceSubscription(
        int $subscriptionId,
        WC_Order $renewalOrder
    ): array {
        global $wpdb;

        $subscription = $this->subscriptionById($subscriptionId);

        if ($subscription === []) {
            throw new RuntimeException('Subscription was not found.');
        }

        $targetProductId = max(
            1,
            (int) $renewalOrder->get_meta(
                '_codigle_renewal_target_product_id',
                true
            ) ?: (int) $subscription['product_id']
        );
        $targetProduct = wc_get_product($targetProductId);

        if (! $targetProduct instanceof \WC_Product) {
            throw new RuntimeException(
                'Renewal target product could not be loaded.'
            );
        }

        $duration = max(
            1,
            (int) $renewalOrder->get_meta(
                '_codigle_renewal_target_duration_months',
                true
            ) ?: (int) $subscription['duration_months']
        );
        $amount = wc_format_decimal(
            (string) (
                $renewalOrder->get_meta(
                    '_codigle_renewal_target_amount',
                    true
                ) ?: $renewalOrder->get_total()
            ),
            6
        );
        $planPageId = max(
            0,
            (int) get_post_meta(
                $targetProductId,
                '_cpb_plan_page_id',
                true
            )
        );
        $start = new \DateTimeImmutable(
            (string) $subscription['current_period_end_utc'],
            new \DateTimeZone('UTC')
        );
        $end = $start->modify('+' . $duration . ' months');
        $now = gmdate('Y-m-d H:i:s');
        $table = Schema::tables()['subscriptions'];

        $updated = $wpdb->update(
            $table,
            [
                'product_id' => $targetProductId,
                'plan_page_id' => $planPageId,
                'duration_months' => $duration,
                'amount' => $amount,
                'status' => 'active',
                'current_period_start_utc' => $start->format(
                    'Y-m-d H:i:s'
                ),
                'current_period_end_utc' => $end->format(
                    'Y-m-d H:i:s'
                ),
                'next_payment_at_utc' => $end->format(
                    'Y-m-d H:i:s'
                ),
                'last_payment_at_utc' => $now,
                'last_renewal_order_id' => $renewalOrder->get_id(),
                'retry_count' => 0,
                'grace_until_utc' => null,
                'renewal_lock_until_utc' => null,
                'pending_product_id' => null,
                'pending_duration_months' => null,
                'pending_change_at_period_end' => 0,
                'pending_change_created_at_utc' => null,
                'updated_at_utc' => $now,
            ],
            ['id' => $subscriptionId]
        );

        if ($updated === false) {
            throw new RuntimeException(
                'Subscription period could not be advanced.'
            );
        }

        return $this->subscriptionById($subscriptionId);
    }

    /**
     * @return array<string, mixed>
     */
    public function markSubscriptionPastDue(int $subscriptionId): array
    {
        global $wpdb;

        $subscription = $this->subscriptionById($subscriptionId);

        if ($subscription === []) {
            return [];
        }

        $retryCount = (int) $subscription['retry_count'] + 1;
        $grace = (string) ($subscription['grace_until_utc'] ?? '');

        if ($grace === '') {
            $grace = gmdate(
                'Y-m-d H:i:s',
                time() + 7 * DAY_IN_SECONDS
            );
        }

        $table = Schema::tables()['subscriptions'];
        $wpdb->update(
            $table,
            [
                'status' => 'past_due',
                'retry_count' => $retryCount,
                'grace_until_utc' => $grace,
                'renewal_lock_until_utc' => null,
                'updated_at_utc' => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $subscriptionId]
        );

        return $this->subscriptionById($subscriptionId);
    }

    public function expireSubscription(int $subscriptionId): void
    {
        global $wpdb;

        $table = Schema::tables()['subscriptions'];
        $wpdb->update(
            $table,
            [
                'status' => 'expired',
                'auto_renew' => 0,
                'next_payment_at_utc' => null,
                'renewal_lock_until_utc' => null,
                'pending_product_id' => null,
                'pending_duration_months' => null,
                'pending_change_at_period_end' => 0,
                'pending_change_created_at_utc' => null,
                'updated_at_utc' => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $subscriptionId]
        );
    }

    /**
     * @param array<string, bool> $consents
     * @param array<string, string> $texts
     * @param array<string, array<string, int|string>> $documents
     * @param array<string, string> $context
     */
    public function saveCheckoutConsents(
        WC_Order $order,
        int $attemptId,
        array $consents,
        array $texts,
        array $documents,
        array $context
    ): void {
        global $wpdb;

        $table = Schema::tables()['consents'];
        $now = gmdate('Y-m-d H:i:s');
        $ip = (string) ($context['ip'] ?? '');
        $userAgent = (string) ($context['user_agent'] ?? '');
        $manifest = (string) wp_json_encode(
            $documents,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        foreach (['terms', 'renewal', 'marketing'] as $key) {
            $existing = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table}
                     WHERE attempt_id = %d
                       AND consent_key = %s
                     LIMIT 1",
                    $attemptId,
                    $key
                )
            );

            // Consent evidence is append-only for a payment attempt. A
            // repeated browser authorization must not rewrite the original
            // accepted time, legal snapshot, IP or session evidence.
            if ($existing > 0) {
                continue;
            }

            $accepted = ! empty($consents[$key]);
            $saved = $wpdb->insert(
                $table,
                [
                    'user_id' => $order->get_customer_id(),
                    'order_id' => $order->get_id(),
                    'attempt_id' => $attemptId,
                    'consent_key' => $key,
                    'accepted' => $accepted ? 1 : 0,
                    'consent_text' => (string) ($texts[$key] ?? ''),
                    'document_manifest' => $manifest,
                    'ip_encrypted' => $this->crypto->encrypt($ip),
                    'ip_hash' => $this->crypto->hash($ip),
                    'ip_source' => substr(
                        sanitize_key((string) ($context['ip_source'] ?? '')),
                        0,
                        40
                    ),
                    'user_agent' => substr($userAgent, 0, 1000),
                    'user_agent_hash' => $this->crypto->hash($userAgent),
                    'session_hash' => substr(
                        sanitize_text_field(
                            (string) ($context['session_hash'] ?? '')
                        ),
                        0,
                        64
                    ),
                    'source_url' => esc_url_raw(
                        (string) ($context['source_url'] ?? '')
                    ),
                    'accepted_at_utc' => $now,
                    'created_at_utc' => $now,
                    'updated_at_utc' => $now,
                ]
            );

            if ($saved === false) {
                $duplicate = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$table}
                         WHERE attempt_id = %d
                           AND consent_key = %s
                         LIMIT 1",
                        $attemptId,
                        $key
                    )
                );

                if ($duplicate < 1) {
                    throw new RuntimeException(
                        'Checkout consent evidence could not be stored.'
                    );
                }
            }
        }
    }


    /**
     * @return array<string, mixed>
     */
    public function subscriptionForUser(int $id, int $userId): array
    {
        global $wpdb;

        $table = Schema::tables()['subscriptions'];
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE id = %d AND user_id = %d
                 LIMIT 1",
                $id,
                $userId
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function updateRenewalPreference(
        int $subscriptionId,
        int $userId,
        bool $enabled
    ): array {
        global $wpdb;

        $subscription = $this->subscriptionForUser(
            $subscriptionId,
            $userId
        );

        if ($subscription === []) {
            throw new RuntimeException('Subscription was not found.');
        }

        if (! in_array(
            (string) $subscription['status'],
            ['active', 'past_due'],
            true
        )) {
            throw new RuntimeException(
                'This subscription cannot change automatic renewal.'
            );
        }

        $data = [
            'auto_renew' => $enabled ? 1 : 0,
            'updated_at_utc' => gmdate('Y-m-d H:i:s'),
        ];

        if ($enabled) {
            $data['cancel_at_period_end'] = 0;
            $data['cancelled_at_utc'] = null;
            $data['next_payment_at_utc'] = (
                (string) $subscription['current_period_end_utc']
            );
        }

        $updated = $wpdb->update(
            Schema::tables()['subscriptions'],
            $data,
            [
                'id' => $subscriptionId,
                'user_id' => $userId,
            ]
        );

        if ($updated === false) {
            throw new RuntimeException(
                'Automatic renewal preference could not be updated.'
            );
        }

        return $this->subscriptionForUser($subscriptionId, $userId);
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelAtPeriodEnd(
        int $subscriptionId,
        int $userId
    ): array {
        global $wpdb;

        $subscription = $this->subscriptionForUser(
            $subscriptionId,
            $userId
        );

        if ($subscription === []) {
            throw new RuntimeException('Subscription was not found.');
        }

        if (! in_array(
            (string) $subscription['status'],
            ['active', 'past_due'],
            true
        )) {
            throw new RuntimeException(
                'This subscription cannot be cancelled.'
            );
        }

        $updated = $wpdb->update(
            Schema::tables()['subscriptions'],
            [
                'auto_renew' => 0,
                'cancel_at_period_end' => 1,
                'cancelled_at_utc' => gmdate('Y-m-d H:i:s'),
                'pending_product_id' => null,
                'pending_duration_months' => null,
                'pending_change_at_period_end' => 0,
                'pending_change_created_at_utc' => null,
                'updated_at_utc' => gmdate('Y-m-d H:i:s'),
            ],
            [
                'id' => $subscriptionId,
                'user_id' => $userId,
            ]
        );

        if ($updated === false) {
            throw new RuntimeException(
                'Subscription cancellation could not be scheduled.'
            );
        }

        return $this->subscriptionForUser($subscriptionId, $userId);
    }

    /**
     * @return array<string, mixed>
     */
    public function reactivateSubscription(
        int $subscriptionId,
        int $userId
    ): array {
        global $wpdb;

        $subscription = $this->subscriptionForUser(
            $subscriptionId,
            $userId
        );

        if ($subscription === []) {
            throw new RuntimeException('Subscription was not found.');
        }

        $periodEnd = strtotime(
            (string) $subscription['current_period_end_utc'] . ' UTC'
        );

        if (
            ! in_array(
                (string) $subscription['status'],
                ['active', 'past_due'],
                true
            )
            || $periodEnd === false
            || $periodEnd <= time()
        ) {
            throw new RuntimeException(
                'The subscription can no longer be reactivated.'
            );
        }

        $updated = $wpdb->update(
            Schema::tables()['subscriptions'],
            [
                'auto_renew' => 1,
                'cancel_at_period_end' => 0,
                'cancelled_at_utc' => null,
                'next_payment_at_utc' => (
                    (string) $subscription['current_period_end_utc']
                ),
                'updated_at_utc' => gmdate('Y-m-d H:i:s'),
            ],
            [
                'id' => $subscriptionId,
                'user_id' => $userId,
            ]
        );

        if ($updated === false) {
            throw new RuntimeException(
                'Subscription could not be reactivated.'
            );
        }

        return $this->subscriptionForUser($subscriptionId, $userId);
    }

    /**
     * @return array<string, mixed>
     */
    public function scheduleSubscriptionChange(
        int $subscriptionId,
        int $userId,
        int $targetProductId,
        int $targetDurationMonths
    ): array {
        global $wpdb;

        $subscription = $this->subscriptionForUser(
            $subscriptionId,
            $userId
        );

        if ($subscription === []) {
            throw new RuntimeException('Subscription was not found.');
        }

        if ((int) $subscription['cancel_at_period_end'] === 1) {
            throw new RuntimeException(
                'Reactivate the subscription before scheduling a plan change.'
            );
        }

        $updated = $wpdb->update(
            Schema::tables()['subscriptions'],
            [
                'pending_product_id' => $targetProductId,
                'pending_duration_months' => max(
                    1,
                    $targetDurationMonths
                ),
                'pending_change_at_period_end' => 1,
                'pending_change_created_at_utc' => gmdate(
                    'Y-m-d H:i:s'
                ),
                'updated_at_utc' => gmdate('Y-m-d H:i:s'),
            ],
            [
                'id' => $subscriptionId,
                'user_id' => $userId,
            ]
        );

        if ($updated === false) {
            throw new RuntimeException(
                'The next-period plan change could not be scheduled.'
            );
        }

        return $this->subscriptionForUser($subscriptionId, $userId);
    }

    /**
     * @return array<string, mixed>
     */
    public function clearScheduledSubscriptionChange(
        int $subscriptionId,
        int $userId
    ): array {
        global $wpdb;

        $updated = $wpdb->update(
            Schema::tables()['subscriptions'],
            [
                'pending_product_id' => null,
                'pending_duration_months' => null,
                'pending_change_at_period_end' => 0,
                'pending_change_created_at_utc' => null,
                'updated_at_utc' => gmdate('Y-m-d H:i:s'),
            ],
            [
                'id' => $subscriptionId,
                'user_id' => $userId,
            ]
        );

        if ($updated === false) {
            throw new RuntimeException(
                'The scheduled subscription change could not be cleared.'
            );
        }

        return $this->subscriptionForUser($subscriptionId, $userId);
    }

    /**
     * @return array<string, mixed>
     */
    public function applyUpgrade(
        int $subscriptionId,
        int $userId,
        int $fromProductId,
        int $targetProductId,
        int $targetDurationMonths,
        string $targetFullAmount,
        string $quotedPeriodEnd
    ): array {
        global $wpdb;

        $targetPlanPageId = (int) get_post_meta(
            $targetProductId,
            '_cpb_plan_page_id',
            true
        );
        $now = gmdate('Y-m-d H:i:s');
        $table = Schema::tables()['subscriptions'];
        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET product_id = %d,
                     plan_page_id = %d,
                     duration_months = %d,
                     amount = %s,
                     pending_product_id = NULL,
                     pending_duration_months = NULL,
                     pending_change_at_period_end = 0,
                     pending_change_created_at_utc = NULL,
                     updated_at_utc = %s
                 WHERE id = %d
                   AND user_id = %d
                   AND product_id = %d
                   AND current_period_end_utc = %s
                   AND status IN ('active','past_due')",
                $targetProductId,
                max(0, $targetPlanPageId),
                max(1, $targetDurationMonths),
                wc_format_decimal($targetFullAmount, 6),
                $now,
                $subscriptionId,
                $userId,
                $fromProductId,
                $quotedPeriodEnd
            )
        );

        if ($affected !== 1) {
            throw new RuntimeException(
                'The subscription changed before the upgrade could be applied.'
            );
        }

        return $this->subscriptionForUser($subscriptionId, $userId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function subscriptionsToExpire(int $limit = 100): array
    {
        global $wpdb;

        $table = Schema::tables()['subscriptions'];
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE status IN ('active','past_due')
                   AND current_period_end_utc <= UTC_TIMESTAMP()
                   AND (
                        cancel_at_period_end = 1
                        OR auto_renew = 0
                   )
                 ORDER BY current_period_end_utc ASC
                 LIMIT %d",
                max(1, min(500, $limit))
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string, mixed> $beforeState
     * @param array<string, mixed> $documents
     * @param array<string, string> $context
     * @return array<string, mixed>
     */
    public function createSubscriptionEvent(
        int $subscriptionId,
        int $userId,
        string $action,
        string $idempotencyKey,
        string $requestHashSource,
        array $beforeState,
        string $consentText,
        array $documents,
        array $context
    ): array {
        global $wpdb;

        $table = Schema::tables()['events'];
        $idempotencyHash = $this->crypto->hash($idempotencyKey);
        $requestHash = $this->crypto->hash($requestHashSource);
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE user_id = %d
                   AND idempotency_hash = %s
                 LIMIT 1",
                $userId,
                $idempotencyHash
            ),
            ARRAY_A
        );

        if (is_array($existing)) {
            if (! hash_equals(
                (string) $existing['request_hash'],
                $requestHash
            )) {
                throw new RuntimeException(
                    'The idempotency key was already used for another request.'
                );
            }

            return $existing;
        }

        $now = gmdate('Y-m-d H:i:s');
        $ip = (string) ($context['ip'] ?? '');
        $userAgent = substr(
            (string) ($context['user_agent'] ?? ''),
            0,
            1000
        );
        $inserted = $wpdb->insert(
            $table,
            [
                'subscription_id' => $subscriptionId,
                'user_id' => $userId,
                'action' => substr(sanitize_key($action), 0, 40),
                'status' => 'created',
                'idempotency_hash' => $idempotencyHash,
                'request_hash' => $requestHash,
                'before_state' => (string) wp_json_encode(
                    $beforeState,
                    JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                ),
                'after_state' => '{}',
                'consent_text' => $consentText,
                'document_manifest' => (string) wp_json_encode(
                    $documents,
                    JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                ),
                'ip_encrypted' => $this->crypto->encrypt($ip),
                'ip_hash' => $this->crypto->hash($ip),
                'ip_source' => substr(
                    sanitize_key((string) ($context['ip_source'] ?? '')),
                    0,
                    40
                ),
                'user_agent' => $userAgent,
                'user_agent_hash' => $this->crypto->hash($userAgent),
                'session_hash' => substr(
                    sanitize_text_field(
                        (string) ($context['session_hash'] ?? '')
                    ),
                    0,
                    64
                ),
                'source_url' => esc_url_raw(
                    (string) ($context['source_url'] ?? '')
                ),
                'created_at_utc' => $now,
                'updated_at_utc' => $now,
            ]
        );

        if ($inserted !== 1) {
            $duplicate = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table}
                     WHERE user_id = %d
                       AND idempotency_hash = %s
                     LIMIT 1",
                    $userId,
                    $idempotencyHash
                ),
                ARRAY_A
            );

            if (is_array($duplicate)) {
                return $duplicate;
            }

            throw new RuntimeException(
                'Subscription event evidence could not be created.'
            );
        }

        return $this->subscriptionEventById((int) $wpdb->insert_id);
    }

    /**
     * @return array<string, mixed>
     */
    public function subscriptionEventById(int $eventId): array
    {
        global $wpdb;

        $table = Schema::tables()['events'];
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d LIMIT 1",
                $eventId
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function subscriptionEventByOrder(int $orderId): array
    {
        global $wpdb;

        $table = Schema::tables()['events'];
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE order_id = %d
                 ORDER BY id DESC
                 LIMIT 1",
                $orderId
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : [];
    }

    public function claimSubscriptionEvent(int $eventId): bool
    {
        global $wpdb;

        $table = Schema::tables()['events'];
        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET status = 'processing',
                     updated_at_utc = %s
                 WHERE id = %d
                   AND (
                        status = 'created'
                        OR (
                            status = 'processing'
                            AND updated_at_utc
                                < UTC_TIMESTAMP() - INTERVAL 5 MINUTE
                        )
                   )",
                gmdate('Y-m-d H:i:s'),
                $eventId
            )
        );

        return $affected === 1;
    }

    /**
     * @param array<string, mixed> $afterState
     */
    public function markSubscriptionEvent(
        int $eventId,
        string $status,
        array $afterState = [],
        int $orderId = 0,
        int $attemptId = 0,
        string $errorCode = '',
        string $errorMessage = ''
    ): void {
        global $wpdb;

        $data = [
            'status' => substr(sanitize_key($status), 0, 30),
            'after_state' => (string) wp_json_encode(
                $afterState,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
            'error_code' => substr(
                sanitize_key($errorCode),
                0,
                80
            ),
            'error_message' => substr(
                sanitize_text_field($errorMessage),
                0,
                1000
            ),
            'updated_at_utc' => gmdate('Y-m-d H:i:s'),
        ];

        if ($orderId > 0) {
            $data['order_id'] = $orderId;
        }

        if ($attemptId > 0) {
            $data['attempt_id'] = $attemptId;
        }

        $updated = $wpdb->update(
            Schema::tables()['events'],
            $data,
            ['id' => $eventId]
        );

        if ($updated === false) {
            throw new RuntimeException(
                'Subscription event evidence could not be updated.'
            );
        }
    }

    /**
     * Store a non-terminal payment-pending event only while the callback has
     * not already completed it. A terminal callback result is authoritative.
     *
     * @param array<string, mixed> $afterState
     */
    public function markSubscriptionEventPending(
        int $eventId,
        array $afterState = [],
        int $orderId = 0,
        int $attemptId = 0
    ): bool {
        global $wpdb;

        $table = Schema::tables()['events'];
        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET status = 'payment_pending',
                     after_state = %s,
                     order_id = CASE WHEN %d > 0 THEN %d ELSE order_id END,
                     attempt_id = CASE WHEN %d > 0 THEN %d ELSE attempt_id END,
                     error_code = '',
                     error_message = '',
                     updated_at_utc = %s
                 WHERE id = %d
                   AND status NOT IN ('success', 'failed', 'manual_review')",
                (string) wp_json_encode(
                    $afterState,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ),
                $orderId,
                $orderId,
                $attemptId,
                $attemptId,
                gmdate('Y-m-d H:i:s'),
                $eventId
            )
        );

        if ($affected === false) {
            throw new RuntimeException(
                'Subscription payment-pending event could not be updated.'
            );
        }

        return $affected === 1;
    }

    private function merchantOid(
        WC_Order $order,
        string $type
    ): string {
        $suffix = match ($type) {
            'renewal' => 'R',
            'renewal_test' => 'T',
            'upgrade' => 'U',
            default => 'I',
        };

        return 'CDG'
            . $order->get_id()
            . $suffix
            . gmdate('ymdHis')
            . wp_rand(1000, 9999);
    }
}
