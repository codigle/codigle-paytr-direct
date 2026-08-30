<?php

declare(strict_types=1);

namespace Codigle\PaytrDirect\Subscription;

use Codigle\PaytrDirect\Database\Repository;
use WC_Order;

final class SubscriptionService
{
    public function __construct(
        private readonly Repository $repository
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function activate(WC_Order $order): array
    {
        $subscription = $this->repository->createSubscription($order);

        do_action(
            'codigle_paytr_direct_schedule_subscription',
            (int) $subscription['id'],
            strtotime(
                (string) $subscription['next_payment_at_utc']
                . ' UTC'
            )
        );

        return $subscription;
    }

    /**
     * @param array<string, mixed> $attempt
     * @return array<string, mixed>
     */
    public function renew(
        WC_Order $order,
        array $attempt
    ): array {
        $subscriptionId = (int) (
            $attempt['subscription_id']
            ?? $order->get_meta(
                '_codigle_subscription_id',
                true
            )
        );
        $subscription = $this->repository->advanceSubscription(
            $subscriptionId,
            $order
        );

        do_action(
            'codigle_paytr_direct_schedule_subscription',
            $subscriptionId,
            strtotime(
                (string) $subscription['next_payment_at_utc']
                . ' UTC'
            )
        );

        return $subscription;
    }


    /**
     * @param array<string, mixed> $attempt
     * @return array<string, mixed>|\WP_Error
     */
    public function upgrade(
        WC_Order $order,
        array $attempt
    ): array|\WP_Error {
        $subscriptionId = (int) (
            $attempt['subscription_id']
            ?? $order->get_meta(
                '_codigle_subscription_id',
                true
            )
        );
        $subscription = $this->repository->subscriptionById(
            $subscriptionId
        );

        if ($subscription === []) {
            return new \WP_Error(
                'codigle_upgrade_subscription_missing',
                'The upgraded subscription could not be loaded.'
            );
        }

        try {
            return $this->repository->applyUpgrade(
                $subscriptionId,
                (int) $subscription['user_id'],
                (int) $order->get_meta(
                    '_codigle_upgrade_from_product_id',
                    true
                ),
                (int) $order->get_meta(
                    '_codigle_upgrade_target_product_id',
                    true
                ),
                max(
                    1,
                    (int) $order->get_meta(
                        '_codigle_upgrade_target_duration_months',
                        true
                    )
                ),
                (string) $order->get_meta(
                    '_codigle_upgrade_target_full_amount',
                    true
                ),
                (string) $order->get_meta(
                    '_codigle_upgrade_period_end_utc',
                    true
                )
            );
        } catch (\Throwable $error) {
            return new \WP_Error(
                'codigle_upgrade_apply_failed',
                substr(
                    sanitize_text_field($error->getMessage()),
                    0,
                    500
                )
            );
        }
    }

    /**
     * @param array<string, mixed> $attempt
     */
    public function renewalFailed(
        WC_Order $order,
        array $attempt
    ): void {
        if (
            (string) $order->get_meta(
                '_codigle_renewal_test_only',
                true
            ) === 'yes'
        ) {
            return;
        }

        $subscriptionId = (int) (
            $attempt['subscription_id']
            ?? 0
        );

        if ($subscriptionId < 1) {
            return;
        }

        $subscription = $this->repository->subscriptionById(
            $subscriptionId
        );
        $next = (string) (
            $subscription['next_payment_at_utc']
            ?? ''
        );

        if (
            $next !== ''
            && strtotime($next . ' UTC') <= time()
        ) {
            $this->repository->markSubscriptionPastDue(
                $subscriptionId
            );
            do_action(
                'codigle_paytr_direct_schedule_retry',
                $subscriptionId
            );
        }
    }
}
