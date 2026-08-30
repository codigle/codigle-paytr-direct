<?php

declare(strict_types=1);

namespace Codigle\PaytrDirect\Subscription;

use Codigle\PaytrDirect\Account\EmailVerification;
use Codigle\PaytrDirect\Checkout\LegalSnapshot;
use Codigle\PaytrDirect\Database\Repository;
use Codigle\PaytrDirect\Paytr\CapiClient;
use Codigle\PaytrDirect\Support\ClientIp;
use Codigle\PaytrDirect\Support\Config;
use RuntimeException;
use Throwable;
use WC_Product;
use WP_Error;

final class ManagementService
{
    public function __construct(
        private readonly Config $config,
        private readonly Repository $repository,
        private readonly CapiClient $capi,
        private readonly RenewalService $renewals,
        private readonly UpgradeQuoteService $quotes,
        private readonly UpgradeService $upgrades,
        private readonly EmailVerification $emailVerification,
        private readonly ClientIp $clientIp,
        private readonly LegalSnapshot $legal
    ) {
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function detail(int $subscriptionId, int $userId): array|WP_Error
    {
        $subscription = $this->repository->subscriptionForUser(
            $subscriptionId,
            $userId
        );

        if ($subscription === []) {
            return $this->error(
                'codigle_subscription_missing',
                'Subscription was not found.',
                404
            );
        }

        return $this->safeSubscription($subscription);
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function upgradeOptions(
        int $subscriptionId,
        int $userId
    ): array|WP_Error {
        return $this->quotes->options($subscriptionId, $userId);
    }

    /**
     * @param array<string, string> $context
     * @return array<string, mixed>|WP_Error
     */
    public function setAutoRenew(
        int $subscriptionId,
        int $userId,
        bool $enabled,
        string $idempotencyKey,
        array $context
    ): array|WP_Error {
        $guard = $this->guard($subscriptionId, $userId);

        if ($guard instanceof WP_Error) {
            return $guard;
        }

        if ($enabled) {
            if ($this->config->renewalMode() !== 'live') {
                return $this->error(
                    'codigle_automatic_renewal_not_live',
                    'Automatic renewal is not enabled globally.',
                    409
                );
            }

            $ready = $this->ensureCardReady($guard, $userId);

            if ($ready instanceof WP_Error) {
                return $ready;
            }
        }

        $action = $enabled
            ? 'auto_renew_enable'
            : 'auto_renew_disable';
        $consent = $enabled
            ? 'I authorize Codigle to automatically renew this subscription and charge its saved payment method at the end of each billing period until I disable renewal or cancel.'
            : 'I understand that automatic renewal will be disabled and access will end at the current period end unless I renew manually.';
        $begin = $this->beginEvent(
            $guard,
            $userId,
            $action,
            $idempotencyKey,
            ['enabled' => $enabled],
            $consent,
            $context
        );

        if ($begin instanceof WP_Error || ! empty($begin['replayed'])) {
            return $begin;
        }

        $eventId = (int) $begin['event_id'];

        try {
            $subscription = $this->repository->updateRenewalPreference(
                $subscriptionId,
                $userId,
                $enabled
            );
            $response = [
                'event_id' => $eventId,
                'status' => 'success',
                'subscription' => $this->safeSubscription($subscription),
            ];
            $this->repository->markSubscriptionEvent(
                $eventId,
                'success',
                $response
            );

            if ($enabled) {
                do_action(
                    'codigle_paytr_direct_schedule_subscription',
                    $subscriptionId,
                    strtotime(
                        (string) $subscription['next_payment_at_utc']
                        . ' UTC'
                    )
                );
            }

            return $response;
        } catch (Throwable $error) {
            return $this->failEvent($eventId, $error);
        }
    }

    /**
     * @param array<string, string> $context
     * @return array<string, mixed>|WP_Error
     */
    public function cancel(
        int $subscriptionId,
        int $userId,
        string $idempotencyKey,
        array $context
    ): array|WP_Error {
        $guard = $this->guard($subscriptionId, $userId);

        if ($guard instanceof WP_Error) {
            return $guard;
        }

        $begin = $this->beginEvent(
            $guard,
            $userId,
            'cancel_at_period_end',
            $idempotencyKey,
            ['cancel_at_period_end' => true],
            'I request cancellation at the end of the current paid period. I understand that access continues until that date and the started period is not automatically refunded.',
            $context
        );

        if ($begin instanceof WP_Error || ! empty($begin['replayed'])) {
            return $begin;
        }

        $eventId = (int) $begin['event_id'];

        try {
            $subscription = $this->repository->cancelAtPeriodEnd(
                $subscriptionId,
                $userId
            );
            $response = [
                'event_id' => $eventId,
                'status' => 'success',
                'subscription' => $this->safeSubscription($subscription),
            ];
            $this->repository->markSubscriptionEvent(
                $eventId,
                'success',
                $response
            );

            return $response;
        } catch (Throwable $error) {
            return $this->failEvent($eventId, $error);
        }
    }

    /**
     * @param array<string, string> $context
     * @return array<string, mixed>|WP_Error
     */
    public function reactivate(
        int $subscriptionId,
        int $userId,
        string $idempotencyKey,
        array $context
    ): array|WP_Error {
        $guard = $this->guard($subscriptionId, $userId);

        if ($guard instanceof WP_Error) {
            return $guard;
        }

        if ($this->config->renewalMode() !== 'live') {
            return $this->error(
                'codigle_automatic_renewal_not_live',
                'Automatic renewal is not enabled globally.',
                409
            );
        }

        $ready = $this->ensureCardReady($guard, $userId);

        if ($ready instanceof WP_Error) {
            return $ready;
        }

        $begin = $this->beginEvent(
            $guard,
            $userId,
            'reactivate',
            $idempotencyKey,
            ['reactivate' => true],
            'I reactivate this subscription and authorize future automatic renewal charges to its saved payment method until I cancel.',
            $context
        );

        if ($begin instanceof WP_Error || ! empty($begin['replayed'])) {
            return $begin;
        }

        $eventId = (int) $begin['event_id'];

        try {
            $subscription = $this->repository->reactivateSubscription(
                $subscriptionId,
                $userId
            );
            $response = [
                'event_id' => $eventId,
                'status' => 'success',
                'subscription' => $this->safeSubscription($subscription),
            ];
            $this->repository->markSubscriptionEvent(
                $eventId,
                'success',
                $response
            );
            do_action(
                'codigle_paytr_direct_schedule_subscription',
                $subscriptionId,
                strtotime(
                    (string) $subscription['next_payment_at_utc']
                    . ' UTC'
                )
            );

            return $response;
        } catch (Throwable $error) {
            return $this->failEvent($eventId, $error);
        }
    }

    /**
     * @param array<string, string> $context
     * @return array<string, mixed>|WP_Error
     */
    public function renewNow(
        int $subscriptionId,
        int $userId,
        int $requestedCardId,
        string $idempotencyKey,
        array $context
    ): array|WP_Error {
        $guard = $this->guard($subscriptionId, $userId);

        if ($guard instanceof WP_Error) {
            return $guard;
        }

        if ((int) $guard['cancel_at_period_end'] === 1) {
            return $this->error(
                'codigle_subscription_cancelled_at_period_end',
                'Reactivate the subscription before renewing it.',
                409
            );
        }

        $selectedCardId = $this->ensureCardReady(
            $guard,
            $userId,
            $requestedCardId
        );

        if ($selectedCardId instanceof WP_Error) {
            return $selectedCardId;
        }

        $previousCardId = (int) ($guard['payment_card_id'] ?? 0);

        if ($selectedCardId !== $previousCardId) {
            try {
                $guard = $this->repository->setSubscriptionCard(
                    $subscriptionId,
                    $userId,
                    $selectedCardId
                );
            } catch (Throwable $error) {
                return $this->error(
                    'codigle_subscription_card_update_failed',
                    substr(sanitize_text_field($error->getMessage()), 0, 500),
                    409
                );
            }
        }

        $begin = $this->beginEvent(
            $guard,
            $userId,
            'renew_now',
            $idempotencyKey,
            [
                'period_end_utc' => (string) (
                    $guard['current_period_end_utc']
                    ?? ''
                ),
                'amount' => (string) ($guard['amount'] ?? ''),
                'previous_card_id' => $previousCardId,
                'selected_card_id' => $selectedCardId,
            ],
            'I authorize Codigle to charge the selected saved payment method now for the displayed renewal amount. The renewed period starts after the current paid period ends.',
            $context
        );

        if ($begin instanceof WP_Error || ! empty($begin['replayed'])) {
            return $begin;
        }

        $eventId = (int) $begin['event_id'];
        $result = $this->renewals->process(
            $subscriptionId,
            true,
            false,
            false,
            $eventId
        );

        if ($result instanceof WP_Error) {
            $this->repository->markSubscriptionEvent(
                $eventId,
                'failed',
                [],
                0,
                0,
                (string) $result->get_error_code(),
                $result->get_error_message()
            );

            return $result;
        }

        $resultStatus = (string) ($result['status'] ?? '');
        $status = $resultStatus === 'success'
            ? 'success'
            : (($resultStatus === 'failed' && empty($result['retryable']))
                ? 'failed'
                : 'payment_pending');
        $response = [
            'event_id' => $eventId,
            'status' => $status,
            'payment' => $this->safePaymentResult($result),
            'subscription' => $this->safeSubscription(
                $this->repository->subscriptionForUser(
                    $subscriptionId,
                    $userId
                )
            ),
        ];
        if ($status === 'payment_pending') {
            $stored = $this->repository->markSubscriptionEventPending(
                $eventId,
                $response,
                (int) ($result['order_id'] ?? 0),
                (int) ($result['attempt_id'] ?? 0)
            );

            if (! $stored) {
                $event = $this->repository->subscriptionEventById($eventId);
                $response['status'] = (string) ($event['status'] ?? 'success');
                $response['subscription'] = $this->safeSubscription(
                    $this->repository->subscriptionForUser(
                        $subscriptionId,
                        $userId
                    )
                );
            }
        } else {
            $this->repository->markSubscriptionEvent(
                $eventId,
                $status,
                $response,
                (int) ($result['order_id'] ?? 0),
                (int) ($result['attempt_id'] ?? 0),
                $status === 'failed' ? 'paytr_payment_failed' : '',
                $status === 'failed'
                    ? (string) ($result['message'] ?? 'Payment failed.')
                    : ''
            );
        }

        return $response;
    }

    /**
     * Start a normal 3D PayTR renewal checkout where the customer may enter a
     * new card. The browser posts PAN/CVV directly to PayTR; this REST action
     * receives no card details.
     *
     * @param array<string, string> $context
     * @return array<string, mixed>|WP_Error
     */
    public function renewWithNewCard(
        int $subscriptionId,
        int $userId,
        string $idempotencyKey,
        array $context
    ): array|WP_Error {
        $guard = $this->guard($subscriptionId, $userId);

        if ($guard instanceof WP_Error) {
            return $guard;
        }

        if ((int) ($guard['cancel_at_period_end'] ?? 0) === 1) {
            return $this->error(
                'codigle_subscription_cancelled_at_period_end',
                'Reactivate the subscription before renewing it.',
                409
            );
        }

        $begin = $this->beginEvent(
            $guard,
            $userId,
            'renew_with_new_card',
            $idempotencyKey,
            [
                'period_end_utc' => (string) (
                    $guard['current_period_end_utc']
                    ?? ''
                ),
                'amount' => (string) ($guard['amount'] ?? ''),
                'payment_method' => 'new_card_3ds',
            ],
            'I authorize the displayed renewal payment through PayTR 3D Secure and request that the new card be stored at PayTR for future subscription renewals.',
            $context
        );

        if ($begin instanceof WP_Error || ! empty($begin['replayed'])) {
            return $begin;
        }

        $eventId = (int) $begin['event_id'];
        $result = $this->renewals->prepareInteractivePayment(
            $subscriptionId,
            $eventId
        );

        if ($result instanceof WP_Error) {
            $this->repository->markSubscriptionEvent(
                $eventId,
                'failed',
                [],
                0,
                0,
                (string) $result->get_error_code(),
                $result->get_error_message()
            );

            return $result;
        }

        $response = [
            'event_id' => $eventId,
            'status' => 'payment_redirect',
            'payment_url' => esc_url_raw(
                (string) ($result['payment_url'] ?? '')
            ),
            'order_id' => (int) ($result['order_id'] ?? 0),
            'attempt_id' => (int) ($result['attempt_id'] ?? 0),
        ];
        $this->repository->markSubscriptionEventPending(
            $eventId,
            $response,
            (int) ($result['order_id'] ?? 0),
            (int) ($result['attempt_id'] ?? 0)
        );

        return $response;
    }

    /**
     * @param array<string, string> $context
     * @return array<string, mixed>|WP_Error
     */
    public function scheduleChange(
        int $subscriptionId,
        int $userId,
        int $targetProductId,
        string $mode,
        string $idempotencyKey,
        array $context
    ): array|WP_Error {
        $guard = $this->guard($subscriptionId, $userId);

        if ($guard instanceof WP_Error) {
            return $guard;
        }

        $validation = $this->validateScheduledChange(
            $guard,
            $targetProductId,
            $mode
        );

        if ($validation instanceof WP_Error) {
            return $validation;
        }

        $begin = $this->beginEvent(
            $guard,
            $userId,
            $mode === 'period'
                ? 'change_billing_period'
                : 'schedule_plan_change',
            $idempotencyKey,
            [
                'target_product_id' => $targetProductId,
                'target_duration_months' => (
                    $validation['duration_months']
                ),
                'mode' => $mode,
            ],
            'I request this plan or billing-period change for the next renewal. I understand that the current plan remains active until the current period ends and the next renewal uses the target plan price then in effect.',
            $context
        );

        if ($begin instanceof WP_Error || ! empty($begin['replayed'])) {
            return $begin;
        }

        $eventId = (int) $begin['event_id'];

        try {
            $subscription = $this->repository->scheduleSubscriptionChange(
                $subscriptionId,
                $userId,
                $targetProductId,
                (int) $validation['duration_months']
            );
            $response = [
                'event_id' => $eventId,
                'status' => 'success',
                'scheduled_change' => $validation,
                'subscription' => $this->safeSubscription($subscription),
            ];
            $this->repository->markSubscriptionEvent(
                $eventId,
                'success',
                $response
            );

            return $response;
        } catch (Throwable $error) {
            return $this->failEvent($eventId, $error);
        }
    }

    /**
     * @param array<string, string> $context
     * @return array<string, mixed>|WP_Error
     */
    public function clearScheduledChange(
        int $subscriptionId,
        int $userId,
        string $idempotencyKey,
        array $context
    ): array|WP_Error {
        $guard = $this->guard($subscriptionId, $userId);

        if ($guard instanceof WP_Error) {
            return $guard;
        }

        $begin = $this->beginEvent(
            $guard,
            $userId,
            'clear_scheduled_change',
            $idempotencyKey,
            ['clear' => true],
            'I request removal of the currently scheduled next-period plan or billing-period change.',
            $context
        );

        if ($begin instanceof WP_Error || ! empty($begin['replayed'])) {
            return $begin;
        }

        $eventId = (int) $begin['event_id'];

        try {
            $subscription = $this->repository
                ->clearScheduledSubscriptionChange(
                    $subscriptionId,
                    $userId
                );
            $response = [
                'event_id' => $eventId,
                'status' => 'success',
                'subscription' => $this->safeSubscription($subscription),
            ];
            $this->repository->markSubscriptionEvent(
                $eventId,
                'success',
                $response
            );

            return $response;
        } catch (Throwable $error) {
            return $this->failEvent($eventId, $error);
        }
    }

    /**
     * @param array<string, string> $context
     * @return array<string, mixed>|WP_Error
     */
    public function upgrade(
        int $subscriptionId,
        int $userId,
        int $targetProductId,
        string $expectedQuoteHash,
        int $requestedCardId,
        string $idempotencyKey,
        array $context
    ): array|WP_Error {
        $guard = $this->guard($subscriptionId, $userId);

        if ($guard instanceof WP_Error) {
            return $guard;
        }

        $selectedCardId = $this->ensureCardReady(
            $guard,
            $userId,
            $requestedCardId
        );

        if ($selectedCardId instanceof WP_Error) {
            return $selectedCardId;
        }

        $previousCardId = (int) ($guard['payment_card_id'] ?? 0);

        if ($selectedCardId !== $previousCardId) {
            try {
                $guard = $this->repository->setSubscriptionCard(
                    $subscriptionId,
                    $userId,
                    $selectedCardId
                );
            } catch (Throwable $error) {
                return $this->error(
                    'codigle_subscription_card_update_failed',
                    substr(sanitize_text_field($error->getMessage()), 0, 500),
                    409
                );
            }
        }

        $quote = $this->quotes->quote(
            $subscriptionId,
            $userId,
            $targetProductId
        );

        if ($quote instanceof WP_Error) {
            return $quote;
        }

        $expectedQuoteHash = trim($expectedQuoteHash);

        if (
            strlen($expectedQuoteHash) !== 64
            || ! ctype_xdigit($expectedQuoteHash)
            || ! hash_equals(
                (string) $quote['quote_hash'],
                strtolower($expectedQuoteHash)
            )
        ) {
            return $this->error(
                'codigle_upgrade_quote_stale',
                'The upgrade offer changed or expired. Review the refreshed offer before continuing.',
                409,
                ['quote' => $quote]
            );
        }

        $begin = $this->beginEvent(
            $guard,
            $userId,
            'upgrade',
            $idempotencyKey,
            [
                'target_product_id' => $targetProductId,
                'quote_hash' => (string) $quote['quote_hash'],
                'amount_due' => (string) $quote['amount_due'],
                'previous_card_id' => $previousCardId,
                'selected_card_id' => $selectedCardId,
            ],
            'I authorize Codigle to charge the selected saved payment method for the displayed prorated upgrade amount. The higher plan becomes active only after payment confirmation; the current period end remains unchanged and future renewals use the higher plan price.',
            $context
        );

        if ($begin instanceof WP_Error || ! empty($begin['replayed'])) {
            return $begin;
        }

        $eventId = (int) $begin['event_id'];
        $result = $this->upgrades->process(
            $subscriptionId,
            $userId,
            $targetProductId,
            $eventId,
            $quote
        );

        if ($result instanceof WP_Error) {
            $this->repository->markSubscriptionEvent(
                $eventId,
                'failed',
                ['quote' => $quote],
                0,
                0,
                (string) $result->get_error_code(),
                $result->get_error_message()
            );

            return $result;
        }

        $resultStatus = (string) ($result['status'] ?? '');
        $status = $resultStatus === 'success'
            ? 'success'
            : (($resultStatus === 'failed' && empty($result['retryable']))
                ? 'failed'
                : 'payment_pending');
        $response = [
            'event_id' => $eventId,
            'status' => $status,
            'quote' => $quote,
            'payment' => $this->safePaymentResult($result),
            'subscription' => $this->safeSubscription(
                $this->repository->subscriptionForUser(
                    $subscriptionId,
                    $userId
                )
            ),
        ];
        if ($status === 'payment_pending') {
            $stored = $this->repository->markSubscriptionEventPending(
                $eventId,
                $response,
                (int) ($result['order_id'] ?? 0),
                (int) ($result['attempt_id'] ?? 0)
            );

            if (! $stored) {
                $event = $this->repository->subscriptionEventById($eventId);
                $response['status'] = (string) ($event['status'] ?? 'success');
                $response['subscription'] = $this->safeSubscription(
                    $this->repository->subscriptionForUser(
                        $subscriptionId,
                        $userId
                    )
                );
            }
        } else {
            $this->repository->markSubscriptionEvent(
                $eventId,
                $status,
                $response,
                (int) ($result['order_id'] ?? 0),
                (int) ($result['attempt_id'] ?? 0),
                $status === 'failed' ? 'paytr_payment_failed' : '',
                $status === 'failed'
                    ? (string) ($result['message'] ?? 'Payment failed.')
                    : ''
            );
        }

        return $response;
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    private function guard(
        int $subscriptionId,
        int $userId
    ): array|WP_Error {
        if ($userId < 1) {
            return $this->error(
                'codigle_subscription_login_required',
                'Sign in to manage subscriptions.',
                401
            );
        }

        if (! $this->emailVerification->isVerified($userId)) {
            return $this->error(
                'codigle_subscription_email_unverified',
                'Verify the account email before changing a subscription.',
                403
            );
        }

        $subscription = $this->repository->subscriptionForUser(
            $subscriptionId,
            $userId
        );

        if ($subscription === []) {
            return $this->error(
                'codigle_subscription_missing',
                'Subscription was not found.',
                404
            );
        }

        return $subscription;
    }

    /**
     * @param array<string, mixed> $subscription
     * @return int|WP_Error Selected local saved-card ID.
     */
    private function ensureCardReady(
        array $subscription,
        int $userId,
        int $requestedCardId = 0
    ): int|WP_Error {
        if (! $this->config->recurringAuthorized()) {
            return $this->error(
                'codigle_paytr_recurring_not_authorized',
                'Saved-card recurring payments are not authorized for this merchant.',
                409
            );
        }

        $refresh = $this->capi->refreshForUser($userId);

        if ($refresh instanceof WP_Error) {
            return $refresh;
        }

        $subscription = $this->repository->subscriptionForUser(
            (int) $subscription['id'],
            $userId
        );
        $cardId = $requestedCardId > 0
            ? $requestedCardId
            : (int) ($subscription['payment_card_id'] ?? 0);
        $card = $this->repository->cardById($cardId, $userId);

        if ($card === [] || (string) ($card['status'] ?? '') !== 'active') {
            return $this->error(
                'codigle_subscription_card_invalid',
                'The selected saved card is unavailable.',
                422
            );
        }

        if ((int) ($card['require_cvv'] ?? 1) === 1) {
            return $this->error(
                'codigle_subscription_card_requires_cvv',
                'This card requires CVV and cannot be used for an unattended recurring charge. Use the secure PayTR payment flow instead.',
                409
            );
        }

        if (
            $this->repository->userToken($userId) === ''
            || $this->repository->cardToken($cardId, $userId) === ''
        ) {
            return $this->error(
                'codigle_subscription_card_not_ready',
                'The selected saved card token is unavailable.',
                409
            );
        }

        return $cardId;
    }

    /**
     * @param array<string, mixed> $subscription
     * @param array<string, mixed> $requestData
     * @param array<string, string> $context
     * @return array<string, mixed>|WP_Error
     */
    private function beginEvent(
        array $subscription,
        int $userId,
        string $action,
        string $idempotencyKey,
        array $requestData,
        string $consentText,
        array $context
    ): array|WP_Error {
        if (! $this->legal->requiredDocumentsAvailable()) {
            return $this->error(
                'codigle_subscription_legal_unavailable',
                'Required subscription legal documents are unavailable.',
                503
            );
        }

        $idempotencyKey = trim($idempotencyKey);

        if (
            strlen($idempotencyKey) < 16
            || strlen($idempotencyKey) > 160
            || ! preg_match('/^[A-Za-z0-9._:-]+$/', $idempotencyKey)
        ) {
            return $this->error(
                'codigle_idempotency_key_invalid',
                'A valid idempotency key is required.',
                422
            );
        }

        try {
            $event = $this->repository->createSubscriptionEvent(
                (int) $subscription['id'],
                $userId,
                $action,
                $idempotencyKey,
                (string) wp_json_encode(
                    [
                        'action' => $action,
                        'subscription_id' => (int) $subscription['id'],
                        'request' => $requestData,
                    ],
                    JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                ),
                $this->safeSubscription($subscription),
                $consentText,
                $this->legal->documents(),
                $context
            );
        } catch (Throwable $error) {
            return $this->error(
                'codigle_subscription_event_failed',
                $error->getMessage(),
                409
            );
        }

        $eventId = (int) ($event['id'] ?? 0);

        if ($eventId < 1) {
            return $this->error(
                'codigle_subscription_event_missing',
                'Subscription event could not be created.',
                500
            );
        }

        if ((string) ($event['status'] ?? '') !== 'created') {
            return $this->eventReplay($event);
        }

        if (! $this->repository->claimSubscriptionEvent($eventId)) {
            return $this->eventReplay(
                $this->repository->subscriptionEventById($eventId)
            );
        }

        return [
            'event_id' => $eventId,
            'replayed' => false,
        ];
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>|WP_Error
     */
    private function eventReplay(array $event): array|WP_Error
    {
        $status = (string) ($event['status'] ?? 'processing');
        $decoded = json_decode(
            (string) ($event['after_state'] ?? '{}'),
            true
        );
        $response = is_array($decoded) ? $decoded : [];
        $response['event_id'] = (int) ($event['id'] ?? 0);
        $response['status'] = $status;
        $response['replayed'] = true;

        if (in_array($status, ['failed', 'manual_review'], true)) {
            return $this->error(
                (string) ($event['error_code'] ?? '')
                    ?: 'codigle_subscription_action_failed',
                (string) ($event['error_message'] ?? '')
                    ?: 'The earlier request did not complete successfully.',
                409,
                ['event' => $response]
            );
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $subscription
     * @return array<string, mixed>|WP_Error
     */
    private function validateScheduledChange(
        array $subscription,
        int $targetProductId,
        string $mode
    ): array|WP_Error {
        $product = wc_get_product($targetProductId);

        if (
            ! $product instanceof WC_Product
            || ! $product->is_purchasable()
            || get_post_status($targetProductId) !== 'publish'
        ) {
            return $this->error(
                'codigle_change_target_unavailable',
                'The selected plan is not purchasable.',
                422
            );
        }

        if ($targetProductId === (int) $subscription['product_id']) {
            return $this->error(
                'codigle_change_target_current',
                'The selected plan is already active.',
                409
            );
        }

        $targetPlanPageId = (int) get_post_meta(
            $targetProductId,
            '_cpb_plan_page_id',
            true
        );
        $targetPlanId = (string) get_post_meta(
            $targetProductId,
            '_cpb_plan_id',
            true
        );
        $currentPlanId = (string) get_post_meta(
            (int) $subscription['product_id'],
            '_cpb_plan_id',
            true
        );
        $duration = max(
            1,
            (int) get_post_meta(
                $targetProductId,
                '_cpb_duration_months',
                true
            )
        );

        if (
            $targetPlanPageId !== (int) $subscription['plan_page_id']
            || $targetPlanId === ''
        ) {
            return $this->error(
                'codigle_change_target_mismatch',
                'The selected plan does not belong to this subscription product.',
                422
            );
        }

        if ($mode === 'period' && ! hash_equals(
            $currentPlanId,
            $targetPlanId
        )) {
            return $this->error(
                'codigle_period_change_plan_mismatch',
                'Billing-period changes must keep the current plan tier.',
                422
            );
        }

        if ($mode !== 'period' && hash_equals(
            $currentPlanId,
            $targetPlanId
        )) {
            return $this->error(
                'codigle_plan_change_same_tier',
                'Use the billing-period action for the same plan tier.',
                422
            );
        }

        if ($mode !== 'period') {
            $policy = function_exists('cpb_plan_builder_upgrade_policy')
                ? cpb_plan_builder_upgrade_policy(
                    (int) $subscription['plan_page_id']
                )
                : [];
            $ranks = [];

            foreach ((array) ($policy['plans'] ?? []) as $plan) {
                if (is_array($plan)) {
                    $ranks[(string) ($plan['id'] ?? '')] = (int) (
                        $plan['tier_rank']
                        ?? 0
                    );
                }
            }

            $targetAmount = (float) wc_get_price_including_tax(
                $product,
                ['qty' => 1]
            );
            $currentRank = (int) ($ranks[$currentPlanId] ?? 0);
            $targetRank = (int) ($ranks[$targetPlanId] ?? 0);

            if (
                $targetRank > $currentRank
                && $targetAmount > (float) $subscription['amount']
            ) {
                return $this->error(
                    'codigle_change_requires_upgrade',
                    'A higher and more expensive tier must use the immediate upgrade action.',
                    409
                );
            }
        }

        return [
            'target_product_id' => $targetProductId,
            'target_product_name' => $product->get_name(),
            'target_plan_id' => $targetPlanId,
            'duration_months' => $duration,
            'applies_at_utc' => (string) (
                $subscription['current_period_end_utc']
                ?? ''
            ),
            'target_full_amount_preview' => wc_format_decimal(
                (float) wc_get_price_including_tax(
                    $product,
                    ['qty' => 1]
                ),
                6
            ),
            'currency' => (string) $subscription['currency'],
        ];
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
            'retry_count',
            'grace_until_utc',
            'cancelled_at_utc',
            'pending_product_id',
            'pending_duration_months',
            'pending_change_at_period_end',
            'pending_change_created_at_utc',
            'updated_at_utc',
        ];
        $safe = [];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $subscription)) {
                $safe[$key] = $subscription[$key];
            }
        }

        if ((int) ($safe['payment_card_id'] ?? 0) > 0) {
            $card = $this->repository->cardById(
                (int) $safe['payment_card_id'],
                (int) ($safe['user_id'] ?? 0)
            );
            $safe['card'] = $card === [] ? null : [
                'id' => (int) $card['id'],
                'last_4' => (string) $card['last_4'],
                'schema' => (string) $card['card_schema'],
                'brand' => (string) $card['card_brand'],
                'bank' => (string) $card['bank_name'],
                'expiry_month' => (string) $card['expiry_month'],
                'expiry_year' => (string) $card['expiry_year'],
                'is_default' => (int) $card['is_default'],
                'require_cvv' => (int) $card['require_cvv'],
            ];
        }

        unset($safe['payment_card_id']);

        return $safe;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function safePaymentResult(array $result): array
    {
        $keys = [
            'status',
            'message',
            'try_again',
            'order_id',
            'attempt_id',
            'waiting_for_callback',
            'final',
            'retryable',
            'duplicate_prevented',
            'transport_unknown',
        ];
        $safe = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $result)) {
                $safe[$key] = $result[$key];
            }
        }

        return $safe;
    }

    private function failEvent(
        int $eventId,
        Throwable $error
    ): WP_Error {
        $code = $error instanceof RuntimeException
            ? 'codigle_subscription_action_rejected'
            : 'codigle_subscription_action_failed';
        $message = substr(
            sanitize_text_field($error->getMessage()),
            0,
            500
        );
        $this->repository->markSubscriptionEvent(
            $eventId,
            'failed',
            [],
            0,
            0,
            $code,
            $message
        );

        return $this->error($code, $message, 409);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function error(
        string $code,
        string $message,
        int $status,
        array $extra = []
    ): WP_Error {
        return new WP_Error(
            $code,
            $message,
            array_merge(['status' => $status], $extra)
        );
    }

    /**
     * @return array<string, string>
     */
    public function requestContext(string $sourceUrl = ''): array
    {
        $details = $this->clientIp->details();

        return [
            'ip' => (string) (
                $details['original_value']
                ?: $details['value']
            ),
            'ip_source' => (string) (
                $details['original_source']
                ?: $details['source']
            ),
            'user_agent' => substr(
                sanitize_text_field(
                    (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
                ),
                0,
                500
            ),
            'source_url' => esc_url_raw($sourceUrl)
                ?: wc_get_page_permalink('myaccount'),
            'session_hash' => hash(
                'sha256',
                wp_get_session_token()
            ),
        ];
    }
}
