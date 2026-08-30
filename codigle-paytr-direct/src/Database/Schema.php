<?php

declare(strict_types=1);

namespace Codigle\PaytrDirect\Database;

final class Schema
{
    public const VERSION = '1.3.0';

    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $customers = $wpdb->prefix . 'codigle_paytr_customers';
        $cards = $wpdb->prefix . 'codigle_paytr_cards';
        $subscriptions = $wpdb->prefix . 'codigle_subscriptions';
        $attempts = $wpdb->prefix . 'codigle_payment_attempts';
        $consents = $wpdb->prefix . 'codigle_checkout_consents';
        $events = $wpdb->prefix . 'codigle_subscription_events';

        dbDelta(
            "CREATE TABLE {$customers} (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                utoken_encrypted longtext NOT NULL,
                utoken_hash char(64) NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'active',
                created_at_utc datetime NOT NULL,
                updated_at_utc datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY user_id (user_id),
                UNIQUE KEY utoken_hash (utoken_hash),
                KEY status (status)
            ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE {$cards} (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                ctoken_encrypted longtext NOT NULL,
                ctoken_hash char(64) NOT NULL,
                last_4 varchar(4) NOT NULL DEFAULT '',
                expiry_month varchar(2) NOT NULL DEFAULT '',
                expiry_year varchar(4) NOT NULL DEFAULT '',
                bank_name varchar(120) NOT NULL DEFAULT '',
                card_brand varchar(80) NOT NULL DEFAULT '',
                card_type varchar(20) NOT NULL DEFAULT '',
                card_schema varchar(30) NOT NULL DEFAULT '',
                business_card tinyint(1) NOT NULL DEFAULT 0,
                require_cvv tinyint(1) NOT NULL DEFAULT 0,
                is_default tinyint(1) NOT NULL DEFAULT 0,
                status varchar(20) NOT NULL DEFAULT 'active',
                refreshed_at_utc datetime NOT NULL,
                created_at_utc datetime NOT NULL,
                updated_at_utc datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY user_card (user_id, ctoken_hash),
                KEY user_status (user_id, status),
                KEY user_default (user_id, is_default)
            ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE {$subscriptions} (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                initial_order_id bigint unsigned NOT NULL,
                product_id bigint unsigned NOT NULL,
                plan_page_id bigint unsigned NOT NULL DEFAULT 0,
                duration_months smallint unsigned NOT NULL DEFAULT 1,
                payment_card_id bigint unsigned NULL,
                amount decimal(18,6) NOT NULL DEFAULT 0,
                currency varchar(8) NOT NULL DEFAULT 'TRY',
                status varchar(30) NOT NULL DEFAULT 'active',
                auto_renew tinyint(1) NOT NULL DEFAULT 1,
                cancel_at_period_end tinyint(1) NOT NULL DEFAULT 0,
                current_period_start_utc datetime NOT NULL,
                current_period_end_utc datetime NOT NULL,
                next_payment_at_utc datetime NULL,
                last_payment_at_utc datetime NULL,
                last_renewal_order_id bigint unsigned NULL,
                retry_count smallint unsigned NOT NULL DEFAULT 0,
                grace_until_utc datetime NULL,
                renewal_lock_until_utc datetime NULL,
                cancelled_at_utc datetime NULL,
                pending_product_id bigint unsigned NULL,
                pending_duration_months smallint unsigned NULL,
                pending_change_at_period_end tinyint(1) NOT NULL DEFAULT 0,
                pending_change_created_at_utc datetime NULL,
                created_at_utc datetime NOT NULL,
                updated_at_utc datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY initial_order_id (initial_order_id),
                KEY user_status (user_id, status),
                KEY next_payment (status, auto_renew, next_payment_at_utc),
                KEY renewal_lock (renewal_lock_until_utc),
                KEY pending_change (pending_change_at_period_end, current_period_end_utc)
            ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE {$attempts} (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                order_id bigint unsigned NOT NULL,
                subscription_id bigint unsigned NULL,
                merchant_oid varchar(64) NOT NULL,
                attempt_type varchar(20) NOT NULL DEFAULT 'initial',
                status varchar(30) NOT NULL DEFAULT 'created',
                expected_amount_minor bigint unsigned NOT NULL,
                currency varchar(8) NOT NULL,
                test_mode tinyint(1) NOT NULL DEFAULT 0,
                retry_number smallint unsigned NOT NULL DEFAULT 0,
                reconcile_count smallint unsigned NOT NULL DEFAULT 0,
                immediate_status varchar(30) NOT NULL DEFAULT '',
                immediate_try_again tinyint(1) NULL,
                immediate_response longtext NULL,
                submitted_at_utc datetime NULL,
                callback_payload longtext NULL,
                failed_reason_code varchar(50) NOT NULL DEFAULT '',
                failed_reason_msg text NULL,
                callback_received_at_utc datetime NULL,
                created_at_utc datetime NOT NULL,
                updated_at_utc datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY merchant_oid (merchant_oid),
                KEY order_status (order_id, status),
                KEY subscription_status (subscription_id, status),
                KEY callback_wait (status, submitted_at_utc)
            ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE {$consents} (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                order_id bigint unsigned NOT NULL,
                attempt_id bigint unsigned NOT NULL,
                consent_key varchar(40) NOT NULL,
                accepted tinyint(1) NOT NULL DEFAULT 0,
                consent_text text NOT NULL,
                document_manifest longtext NOT NULL,
                ip_encrypted longtext NOT NULL,
                ip_hash char(64) NOT NULL,
                ip_source varchar(40) NOT NULL DEFAULT '',
                user_agent text NOT NULL,
                user_agent_hash char(64) NOT NULL,
                session_hash char(64) NOT NULL,
                source_url text NOT NULL,
                accepted_at_utc datetime NOT NULL,
                created_at_utc datetime NOT NULL,
                updated_at_utc datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY attempt_consent (attempt_id, consent_key),
                KEY order_id (order_id),
                KEY user_time (user_id, accepted_at_utc)
            ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE {$events} (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                subscription_id bigint unsigned NOT NULL,
                user_id bigint unsigned NOT NULL,
                action varchar(40) NOT NULL,
                status varchar(30) NOT NULL DEFAULT 'created',
                idempotency_hash char(64) NOT NULL,
                request_hash char(64) NOT NULL,
                before_state longtext NOT NULL,
                after_state longtext NOT NULL,
                consent_text text NOT NULL,
                document_manifest longtext NOT NULL,
                ip_encrypted longtext NOT NULL,
                ip_hash char(64) NOT NULL,
                ip_source varchar(40) NOT NULL DEFAULT '',
                user_agent text NOT NULL,
                user_agent_hash char(64) NOT NULL,
                session_hash char(64) NOT NULL,
                source_url text NOT NULL,
                order_id bigint unsigned NULL,
                attempt_id bigint unsigned NULL,
                error_code varchar(80) NOT NULL DEFAULT '',
                error_message text NULL,
                created_at_utc datetime NOT NULL,
                updated_at_utc datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY user_idempotency (user_id, idempotency_hash),
                KEY subscription_time (subscription_id, created_at_utc),
                KEY order_id (order_id),
                KEY attempt_id (attempt_id),
                KEY status (status)
            ) {$charset};"
        );

        update_option(
            'codigle_paytr_direct_schema_version',
            self::VERSION,
            false
        );
    }

    /**
     * @return array<string, string>
     */
    public static function tables(): array
    {
        global $wpdb;

        return [
            'customers' => $wpdb->prefix . 'codigle_paytr_customers',
            'cards' => $wpdb->prefix . 'codigle_paytr_cards',
            'subscriptions' => $wpdb->prefix . 'codigle_subscriptions',
            'attempts' => $wpdb->prefix . 'codigle_payment_attempts',
            'consents' => $wpdb->prefix . 'codigle_checkout_consents',
            'events' => $wpdb->prefix . 'codigle_subscription_events',
        ];
    }
}
