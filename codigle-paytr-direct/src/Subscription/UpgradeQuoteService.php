<?php

declare(strict_types=1);

namespace Codigle\PaytrDirect\Subscription;

use Codigle\PaytrDirect\Database\Repository;
use WC_Product;
use WP_Error;

final class UpgradeQuoteService
{
    public function __construct(
        private readonly Repository $repository
    ) {
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function options(
        int $subscriptionId,
        int $userId
    ): array|WP_Error {
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

        $context = $this->context($subscription);

        if ($context instanceof WP_Error) {
            return $context;
        }

        $options = [];

        foreach ($context['policy']['plans'] as $plan) {
            if (! is_array($plan)) {
                continue;
            }

            $targetProductId = (int) (
                $plan['products'][$context['duration_months']]
                ?? 0
            );
            $target = $this->target(
                $subscription,
                $context,
                $plan,
                $targetProductId
            );

            if ($target instanceof WP_Error) {
                $options[] = [
                    'plan_id' => (string) ($plan['id'] ?? ''),
                    'plan_name' => (string) ($plan['name'] ?? ''),
                    'tier_rank' => (int) ($plan['tier_rank'] ?? 0),
                    'classification' => 'unavailable',
                    'available' => false,
                    'reason' => $target->get_error_message(),
                ];

                continue;
            }

            $options[] = $target;
        }

        return [
            'subscription_id' => $subscriptionId,
            'selection_url' => (string) (
                $context['policy']['selection_url']
                ?? ''
            ),
            'current_plan' => [
                'plan_id' => $context['current_plan_id'],
                'plan_name' => $context['current_plan_name'],
                'tier_rank' => $context['current_rank'],
                'product_id' => (int) $subscription['product_id'],
                'duration_months' => $context['duration_months'],
                'full_amount' => $this->decimal(
                    (float) $subscription['amount']
                ),
                'currency' => (string) $subscription['currency'],
            ],
            'period' => [
                'start_utc' => (string) (
                    $subscription['current_period_start_utc']
                    ?? ''
                ),
                'end_utc' => (string) (
                    $subscription['current_period_end_utc']
                    ?? ''
                ),
                'remaining_ratio' => $context['remaining_ratio'],
            ],
            'options' => $options,
        ];
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function quote(
        int $subscriptionId,
        int $userId,
        int $targetProductId
    ): array|WP_Error {
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

        $context = $this->context($subscription);

        if ($context instanceof WP_Error) {
            return $context;
        }

        $targetPlanId = (string) get_post_meta(
            $targetProductId,
            '_cpb_plan_id',
            true
        );
        $targetPlan = null;

        foreach ($context['policy']['plans'] as $plan) {
            if (
                is_array($plan)
                && hash_equals(
                    (string) ($plan['id'] ?? ''),
                    $targetPlanId
                )
            ) {
                $targetPlan = $plan;
                break;
            }
        }

        if (! is_array($targetPlan)) {
            return new WP_Error(
                'codigle_upgrade_target_invalid',
                'The requested plan does not belong to this product.',
                ['status' => 422]
            );
        }

        $target = $this->target(
            $subscription,
            $context,
            $targetPlan,
            $targetProductId
        );

        if ($target instanceof WP_Error) {
            return $target;
        }

        if ($target['classification'] !== 'upgrade') {
            return new WP_Error(
                'codigle_upgrade_not_immediate',
                'This plan change must be scheduled for the next billing period.',
                [
                    'status' => 409,
                    'classification' => $target['classification'],
                    'option' => $target,
                ]
            );
        }

        return $target;
    }

    /**
     * @param array<string, mixed> $subscription
     * @return array<string, mixed>|WP_Error
     */
    private function context(array $subscription): array|WP_Error
    {
        $planPageId = (int) ($subscription['plan_page_id'] ?? 0);

        if (
            $planPageId < 1
            || ! function_exists('cpb_plan_builder_upgrade_policy')
        ) {
            return new WP_Error(
                'codigle_upgrade_policy_unavailable',
                'Upgrade policy is not available for this subscription.',
                ['status' => 503]
            );
        }

        $policy = cpb_plan_builder_upgrade_policy($planPageId);
        $currentProductId = (int) $subscription['product_id'];
        $currentPlanId = (string) get_post_meta(
            $currentProductId,
            '_cpb_plan_id',
            true
        );
        $currentPlan = null;

        foreach ((array) ($policy['plans'] ?? []) as $plan) {
            if (
                is_array($plan)
                && hash_equals(
                    (string) ($plan['id'] ?? ''),
                    $currentPlanId
                )
            ) {
                $currentPlan = $plan;
                break;
            }
        }

        if (! is_array($currentPlan)) {
            return new WP_Error(
                'codigle_current_plan_missing',
                'The current plan could not be resolved.',
                ['status' => 409]
            );
        }

        $start = strtotime(
            (string) $subscription['current_period_start_utc'] . ' UTC'
        );
        $end = strtotime(
            (string) $subscription['current_period_end_utc'] . ' UTC'
        );

        if ($start === false || $end === false || $end <= $start) {
            return new WP_Error(
                'codigle_subscription_period_invalid',
                'The subscription billing period is invalid.',
                ['status' => 409]
            );
        }

        // Keep a quote stable for a short confirmation window. The portal
        // sends the quote hash back and the payment service revalidates it
        // under the subscription lock before charging.
        $quotedAt = intdiv(time(), 300) * 300;
        $remainingRatio = min(
            1.0,
            max(0.0, ($end - $quotedAt) / max(1, $end - $start))
        );

        return [
            'policy' => $policy,
            'current_plan' => $currentPlan,
            'current_plan_id' => $currentPlanId,
            'current_plan_name' => (string) (
                $currentPlan['name']
                ?? ''
            ),
            'current_rank' => (int) (
                $currentPlan['tier_rank']
                ?? 0
            ),
            'duration_months' => max(
                1,
                (int) $subscription['duration_months']
            ),
            'remaining_ratio' => $remainingRatio,
            'quoted_at' => $quotedAt,
            'expires_at' => $quotedAt + 300,
        ];
    }

    /**
     * @param array<string, mixed> $subscription
     * @param array<string, mixed> $context
     * @param array<string, mixed> $plan
     * @return array<string, mixed>|WP_Error
     */
    private function target(
        array $subscription,
        array $context,
        array $plan,
        int $targetProductId
    ): array|WP_Error {
        if (empty($plan['active'])) {
            return new WP_Error(
                'codigle_target_plan_inactive',
                'This plan is not currently available.'
            );
        }

        if ($targetProductId < 1) {
            return new WP_Error(
                'codigle_target_duration_unavailable',
                'This billing period is not available for the plan.'
            );
        }

        $product = wc_get_product($targetProductId);

        if (
            ! $product instanceof WC_Product
            || ! $product->is_purchasable()
            || get_post_status($targetProductId) !== 'publish'
        ) {
            return new WP_Error(
                'codigle_target_product_unavailable',
                'The target plan product is not purchasable.'
            );
        }

        $targetPlanPageId = (int) get_post_meta(
            $targetProductId,
            '_cpb_plan_page_id',
            true
        );
        $targetDuration = max(
            1,
            (int) get_post_meta(
                $targetProductId,
                '_cpb_duration_months',
                true
            )
        );

        if (
            $targetPlanPageId !== (int) $subscription['plan_page_id']
            || $targetDuration !== $context['duration_months']
        ) {
            return new WP_Error(
                'codigle_target_product_mismatch',
                'The target plan must use the current billing period.'
            );
        }

        $currentRank = (int) $context['current_rank'];
        $targetRank = (int) ($plan['tier_rank'] ?? 0);
        $currentAmount = (float) $subscription['amount'];
        $targetAmount = $this->productGrossAmount($product);
        $remainingRatio = (float) $context['remaining_ratio'];
        $currentCredit = $currentAmount * $remainingRatio;
        $targetRemaining = $targetAmount * $remainingRatio;
        $rawDifference = max(0.0, $targetRemaining - $currentCredit);
        $discountPercent = min(
            100.0,
            max(
                0.0,
                (float) (
                    $context['current_plan']['upgrade_discount_percent']
                    ?? 0
                )
            )
        );
        $discountAmount = $rawDifference * $discountPercent / 100;
        $amountDue = max(0.0, $rawDifference - $discountAmount);
        $classification = 'current';

        if (
            $targetProductId !== (int) $subscription['product_id']
        ) {
            if (
                $targetRank > $currentRank
                && $targetAmount > $currentAmount
                && ! empty(
                    $context['current_plan']['upgrade_enabled']
                )
            ) {
                $classification = 'upgrade';
            } elseif ($targetRank < $currentRank) {
                $classification = 'downgrade';
            } else {
                $classification = 'scheduled_change';
            }
        }

        $quote = [
            'subscription_id' => (int) $subscription['id'],
            'from_product_id' => (int) $subscription['product_id'],
            'target_product_id' => $targetProductId,
            'plan_id' => (string) ($plan['id'] ?? ''),
            'plan_name' => (string) ($plan['name'] ?? ''),
            'tier_rank' => $targetRank,
            'classification' => $classification,
            'available' => true,
            'duration_months' => $targetDuration,
            'currency' => (string) $subscription['currency'],
            'period_end_utc' => (string) (
                $subscription['current_period_end_utc']
                ?? ''
            ),
            'quoted_at_utc' => gmdate(
                'Y-m-d H:i:s',
                (int) $context['quoted_at']
            ),
            'expires_at_utc' => gmdate(
                'Y-m-d H:i:s',
                (int) $context['expires_at']
            ),
            'remaining_ratio' => $remainingRatio,
            'current_full_amount' => $this->decimal($currentAmount),
            'target_full_amount' => $this->decimal($targetAmount),
            'unused_current_credit' => $this->decimal($currentCredit),
            'target_remaining_value' => $this->decimal($targetRemaining),
            'raw_difference' => $this->decimal($rawDifference),
            'discount_percent' => $this->decimal($discountPercent),
            'discount_amount' => $this->decimal($discountAmount),
            'amount_due' => $this->decimal($amountDue),
        ];
        $quote['quote_hash'] = hash_hmac(
            'sha256',
            (string) wp_json_encode(
                $quote,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
            ),
            wp_salt('nonce')
        );

        return $quote;
    }

    private function productGrossAmount(WC_Product $product): float
    {
        $amount = function_exists('wc_get_price_including_tax')
            ? (float) wc_get_price_including_tax(
                $product,
                ['qty' => 1]
            )
            : (float) $product->get_price();

        return max(0.0, $amount);
    }

    private function decimal(float $amount): string
    {
        return wc_format_decimal($amount, 6);
    }
}
