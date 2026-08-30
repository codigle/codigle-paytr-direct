<?php

declare(strict_types=1);

namespace Codigle\PaytrDirect\Rest;

use Codigle\PaytrDirect\Database\Repository;
use Codigle\PaytrDirect\Paytr\CapiClient;
use Codigle\PaytrDirect\Subscription\ManagementService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class SubscriptionController
{
    private const NAMESPACE = 'codigle-paytr-direct/v1';

    public function __construct(
        private readonly Repository $repository,
        private readonly ManagementService $management,
        private readonly CapiClient $capi
    ) {
    }

    public function register(): void
    {
        add_action(
            'rest_api_init',
            function (): void {
                register_rest_route(
                    self::NAMESPACE,
                    '/payment-methods',
                    [
                        'methods' => 'GET',
                        'callback' => [$this, 'paymentMethods'],
                        'permission_callback' => [$this, 'permission'],
                    ]
                );
                register_rest_route(
                    self::NAMESPACE,
                    '/subscriptions',
                    [
                        'methods' => 'GET',
                        'callback' => [$this, 'index'],
                        'permission_callback' => [$this, 'permission'],
                    ]
                );
                register_rest_route(
                    self::NAMESPACE,
                    '/subscriptions/(?P<id>\d+)',
                    [
                        'methods' => 'GET',
                        'callback' => [$this, 'show'],
                        'permission_callback' => [$this, 'permission'],
                    ]
                );
                register_rest_route(
                    self::NAMESPACE,
                    '/subscriptions/(?P<id>\d+)/upgrade-options',
                    [
                        'methods' => 'GET',
                        'callback' => [$this, 'upgradeOptions'],
                        'permission_callback' => [$this, 'permission'],
                    ]
                );

                foreach ($this->writeRoutes() as $path => $callback) {
                    register_rest_route(
                        self::NAMESPACE,
                        '/subscriptions/(?P<id>\d+)/' . $path,
                        [
                            'methods' => 'POST',
                            'callback' => [$this, $callback],
                            'permission_callback' => [$this, 'permission'],
                        ]
                    );
                }
            }
        );
    }

    public function permission(WP_REST_Request $request): bool|WP_Error
    {
        if (! is_user_logged_in()) {
            return new WP_Error(
                'codigle_subscription_login_required',
                'Sign in to manage subscriptions.',
                ['status' => 401]
            );
        }

        $nonce = trim((string) $request->get_header('X-WP-Nonce'));

        if ($nonce === '' || ! wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error(
                'codigle_subscription_nonce_invalid',
                'The security token is missing or expired. Refresh the page and try again.',
                ['status' => 403]
            );
        }

        return true;
    }

    public function paymentMethods(): WP_REST_Response|WP_Error
    {
        $userId = get_current_user_id();
        $refresh = $this->capi->refreshForUser($userId);

        if ($refresh instanceof WP_Error) {
            return $refresh;
        }

        $items = [];

        foreach ($this->repository->cards($userId) as $card) {
            if ((string) ($card['status'] ?? '') !== 'active') {
                continue;
            }

            $requireCvv = (int) ($card['require_cvv'] ?? 1) === 1;
            $items[] = [
                'id' => (int) ($card['id'] ?? 0),
                'brand' => strtoupper((string) (
                    $card['card_schema']
                    ?: $card['card_brand']
                    ?: 'CARD'
                )),
                'last_4' => (string) ($card['last_4'] ?? ''),
                'bank_name' => (string) ($card['bank_name'] ?? ''),
                'expiry_month' => (string) ($card['expiry_month'] ?? ''),
                'expiry_year' => (string) ($card['expiry_year'] ?? ''),
                'is_default' => (bool) ($card['is_default'] ?? false),
                'require_cvv' => $requireCvv,
                'recurring_eligible' => ! $requireCvv,
            ];
        }

        return $this->response(['payment_methods' => $items]);
    }

    public function index(): WP_REST_Response
    {
        $items = [];

        foreach (
            $this->repository->subscriptions(get_current_user_id())
            as $subscription
        ) {
            $detail = $this->management->detail(
                (int) $subscription['id'],
                get_current_user_id()
            );

            if (is_array($detail)) {
                $items[] = $detail;
            }
        }

        return $this->response(['subscriptions' => $items]);
    }

    public function show(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        $result = $this->management->detail(
            absint($request['id']),
            get_current_user_id()
        );

        return $this->wrap($result);
    }

    public function upgradeOptions(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        $result = $this->management->upgradeOptions(
            absint($request['id']),
            get_current_user_id()
        );

        return $this->wrap($result);
    }

    public function autoRenew(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        $confirmed = $this->confirmed($request);

        if ($confirmed instanceof WP_Error) {
            return $confirmed;
        }

        $enabled = filter_var(
            $request->get_param('enabled'),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );

        if (! is_bool($enabled)) {
            return new WP_Error(
                'codigle_auto_renew_value_invalid',
                'The automatic-renewal value is invalid.',
                ['status' => 422]
            );
        }

        return $this->wrap(
            $this->management->setAutoRenew(
                absint($request['id']),
                get_current_user_id(),
                $enabled,
                $this->idempotencyKey($request),
                $this->context($request)
            )
        );
    }

    public function cancel(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        $confirmed = $this->confirmed($request);

        if ($confirmed instanceof WP_Error) {
            return $confirmed;
        }

        return $this->wrap(
            $this->management->cancel(
                absint($request['id']),
                get_current_user_id(),
                $this->idempotencyKey($request),
                $this->context($request)
            )
        );
    }

    public function reactivate(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        $confirmed = $this->confirmed($request);

        if ($confirmed instanceof WP_Error) {
            return $confirmed;
        }

        return $this->wrap(
            $this->management->reactivate(
                absint($request['id']),
                get_current_user_id(),
                $this->idempotencyKey($request),
                $this->context($request)
            )
        );
    }

    public function renew(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        $confirmed = $this->confirmed($request);

        if ($confirmed instanceof WP_Error) {
            return $confirmed;
        }

        return $this->wrap(
            $this->management->renewNow(
                absint($request['id']),
                get_current_user_id(),
                absint($request->get_param('card_id')),
                $this->idempotencyKey($request),
                $this->context($request)
            )
        );
    }

    public function renewWithNewCard(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        $confirmed = $this->confirmed($request);

        if ($confirmed instanceof WP_Error) {
            return $confirmed;
        }

        return $this->wrap(
            $this->management->renewWithNewCard(
                absint($request['id']),
                get_current_user_id(),
                $this->idempotencyKey($request),
                $this->context($request)
            )
        );
    }

    public function changePeriod(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        return $this->scheduleChange($request, 'period');
    }

    public function changePlan(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        return $this->scheduleChange($request, 'plan');
    }

    public function clearChange(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        $confirmed = $this->confirmed($request);

        if ($confirmed instanceof WP_Error) {
            return $confirmed;
        }

        return $this->wrap(
            $this->management->clearScheduledChange(
                absint($request['id']),
                get_current_user_id(),
                $this->idempotencyKey($request),
                $this->context($request)
            )
        );
    }

    public function upgrade(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        $confirmed = $this->confirmed($request);

        if ($confirmed instanceof WP_Error) {
            return $confirmed;
        }

        return $this->wrap(
            $this->management->upgrade(
                absint($request['id']),
                get_current_user_id(),
                absint($request->get_param('target_product_id')),
                sanitize_text_field(
                    (string) $request->get_param('quote_hash')
                ),
                absint($request->get_param('card_id')),
                $this->idempotencyKey($request),
                $this->context($request)
            )
        );
    }

    /**
     * @return array<string, string>
     */
    private function writeRoutes(): array
    {
        return [
            'auto-renew' => 'autoRenew',
            'cancel' => 'cancel',
            'reactivate' => 'reactivate',
            'renew' => 'renew',
            'renew-with-new-card' => 'renewWithNewCard',
            'change-period' => 'changePeriod',
            'change-plan' => 'changePlan',
            'clear-scheduled-change' => 'clearChange',
            'upgrade' => 'upgrade',
        ];
    }

    private function scheduleChange(
        WP_REST_Request $request,
        string $mode
    ): WP_REST_Response|WP_Error {
        $confirmed = $this->confirmed($request);

        if ($confirmed instanceof WP_Error) {
            return $confirmed;
        }

        $targetProductId = absint(
            $request->get_param('target_product_id')
        );

        if ($targetProductId < 1) {
            return new WP_Error(
                'codigle_change_target_required',
                'Choose a target plan.',
                ['status' => 422]
            );
        }

        return $this->wrap(
            $this->management->scheduleChange(
                absint($request['id']),
                get_current_user_id(),
                $targetProductId,
                $mode,
                $this->idempotencyKey($request),
                $this->context($request)
            )
        );
    }

    private function confirmed(
        WP_REST_Request $request
    ): bool|WP_Error {
        if (! filter_var(
            $request->get_param('confirmed'),
            FILTER_VALIDATE_BOOLEAN
        )) {
            return new WP_Error(
                'codigle_subscription_confirmation_required',
                'Confirm the subscription action before continuing.',
                ['status' => 422]
            );
        }

        $ip = trim((string) (
            $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? ''
        ));
        $rateKey = 'cdg_sub_action_' . hash(
            'sha256',
            get_current_user_id() . '|' . $ip
        );
        $count = (int) get_transient($rateKey);

        if ($count >= 30) {
            return new WP_Error(
                'codigle_subscription_rate_limited',
                'Too many subscription actions. Please wait ten minutes.',
                ['status' => 429]
            );
        }

        set_transient(
            $rateKey,
            (string) ($count + 1),
            10 * MINUTE_IN_SECONDS
        );

        return true;
    }

    private function idempotencyKey(
        WP_REST_Request $request
    ): string {
        return sanitize_text_field(
            (string) (
                $request->get_header('Idempotency-Key')
                ?: $request->get_param('idempotency_key')
            )
        );
    }

    /**
     * @return array<string, string>
     */
    private function context(WP_REST_Request $request): array
    {
        $sourceUrl = esc_url_raw(
            (string) $request->get_param('source_url')
        );

        return $this->management->requestContext($sourceUrl);
    }

    /**
     * @param array<string, mixed>|WP_Error $result
     */
    private function wrap(
        array|WP_Error $result
    ): WP_REST_Response|WP_Error {
        if ($result instanceof WP_Error) {
            return $result;
        }

        $status = (string) ($result['status'] ?? 'success');
        $httpStatus = in_array(
            $status,
            ['payment_pending', 'payment_redirect'],
            true
        ) ? 202 : 200;

        return $this->response($result, $httpStatus);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function response(
        array $data,
        int $status = 200
    ): WP_REST_Response {
        $response = new WP_REST_Response($data, $status);
        $response->header('Cache-Control', 'no-store, private');

        return $response;
    }
}
