<?php

declare(strict_types=1);

namespace Codigle\PaytrDirect\Checkout;

use Codigle\PaytrDirect\Account\EmailVerification;
use Codigle\PaytrDirect\Database\Repository;
use Codigle\PaytrDirect\Paytr\TokenService;
use Codigle\PaytrDirect\Support\ClientIp;
use Codigle\PaytrDirect\Support\Config;
use RuntimeException;
use WC_Customer;
use WC_Order;
use WC_Product;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class ModalCheckout
{
    public function __construct(
        private readonly Config $config,
        private readonly Repository $repository,
        private readonly TokenService $tokenService,
        private readonly ClientIp $clientIp,
        private readonly LegalSnapshot $legal,
        private readonly EmailVerification $emailVerification
    ) {
    }

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_footer', [$this, 'renderContainer'], 5);

        add_action(
            'rest_api_init',
            function (): void {
                $permission = [$this, 'permission'];

                register_rest_route(
                    'codigle-paytr-direct/v1',
                    '/modal-checkout/prepare',
                    [
                        'methods' => 'GET',
                        'callback' => [$this, 'prepare'],
                        'permission_callback' => $permission,
                    ]
                );
                register_rest_route(
                    'codigle-paytr-direct/v1',
                    '/modal-checkout/quote',
                    [
                        'methods' => 'POST',
                        'callback' => [$this, 'quote'],
                        'permission_callback' => $permission,
                    ]
                );
                register_rest_route(
                    'codigle-paytr-direct/v1',
                    '/modal-checkout/authorize',
                    [
                        'methods' => 'POST',
                        'callback' => [$this, 'authorize'],
                        'permission_callback' => $permission,
                    ]
                );
            }
        );
    }

    public function permission(): bool|WP_Error
    {
        if (! is_user_logged_in()) {
            return new WP_Error(
                'codigle_checkout_login_required',
                'Sign in to continue checkout.',
                ['status' => 401]
            );
        }

        if (! $this->enabled()) {
            return new WP_Error(
                'codigle_checkout_not_available',
                'The new checkout is not available for this account yet.',
                ['status' => 403]
            );
        }

        return true;
    }

    public function enqueue(): void
    {
        if (! $this->enabled() || is_admin()) {
            return;
        }

        wp_enqueue_style(
            'codigle-paytr-direct-modal',
            CODIGLE_PAYTR_DIRECT_URL . 'assets/modal-checkout.css',
            [],
            CODIGLE_PAYTR_DIRECT_VERSION
        );
        wp_enqueue_script(
            'codigle-paytr-direct-modal',
            CODIGLE_PAYTR_DIRECT_URL . 'assets/modal-checkout.js',
            [],
            CODIGLE_PAYTR_DIRECT_VERSION,
            true
        );
        wp_localize_script(
            'codigle-paytr-direct-modal',
            'CodigleCheckoutModal',
            [
                'prepareUrl' => rest_url(
                    'codigle-paytr-direct/v1/modal-checkout/prepare'
                ),
                'quoteUrl' => rest_url(
                    'codigle-paytr-direct/v1/modal-checkout/quote'
                ),
                'authorizeUrl' => rest_url(
                    'codigle-paytr-direct/v1/modal-checkout/authorize'
                ),
                'emailUrl' => rest_url(
                    'codigle-paytr-direct/v1/email/send-verification'
                ),
                'nonce' => wp_create_nonce('wp_rest'),
                'loginUrl' => wp_login_url($this->currentUrl()),
                'loggedIn' => is_user_logged_in(),
                'emailVerified' => is_user_logged_in()
                    ? $this->emailVerification->isVerified(
                        get_current_user_id()
                    )
                    : false,
                'currency' => get_woocommerce_currency(),
            ]
        );
    }

    public function renderContainer(): void
    {
        if (! $this->enabled() || is_admin()) {
            return;
        }
        ?>
        <div class="cdg-checkout-modal" data-cdg-checkout-modal hidden>
            <div class="cdg-checkout-backdrop" data-cdg-checkout-close></div>
            <section class="cdg-checkout-dialog"
                     role="dialog"
                     aria-modal="true"
                     aria-labelledby="cdg-checkout-title"
                     tabindex="-1">
                <button type="button"
                        class="cdg-checkout-close"
                        aria-label="Close checkout"
                        data-cdg-checkout-close>×</button>
                <div class="cdg-checkout-content" data-cdg-checkout-content>
                    <div class="cdg-checkout-loading">
                        <span class="cdg-checkout-spinner"></span>
                        <p>Preparing secure checkout…</p>
                    </div>
                </div>
            </section>
        </div>
        <?php
    }

    public function prepare(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        $product = $this->managedProduct(
            absint($request->get_param('product_id'))
        );

        if ($product instanceof WP_Error) {
            return $product;
        }

        $userId = get_current_user_id();

        return new WP_REST_Response(
            [
                'product' => $this->productPayload($product),
                'durations' => $this->durationProducts($product),
                'profile' => $this->profile($userId),
                'countries' => WC()->countries->get_countries(),
                'email_verified' => $this->emailVerification->isVerified(
                    $userId
                ),
                'legal' => [
                    'documents' => $this->legal->documents(),
                    'texts' => $this->legal->consentTexts(),
                    'available' => $this->legal
                        ->requiredDocumentsAvailable(),
                ],
                'account_required' => true,
            ]
        );
    }

    public function quote(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        $userId = get_current_user_id();
        $product = $this->managedProduct(
            absint($request->get_param('product_id'))
        );

        if ($product instanceof WP_Error) {
            return $product;
        }

        if (! $this->emailVerification->isVerified($userId)) {
            return new WP_Error(
                'codigle_checkout_email_unverified',
                'Verify your account email before continuing.',
                ['status' => 403]
            );
        }

        if (! $this->legal->requiredDocumentsAvailable()) {
            return new WP_Error(
                'codigle_checkout_legal_unavailable',
                'Required legal documents are not available.',
                ['status' => 503]
            );
        }

        $billing = $this->billingFromRequest($request);

        if ($billing instanceof WP_Error) {
            return $billing;
        }

        $order = $this->orderFromRequest($request, $userId);

        if (! $order instanceof WC_Order) {
            $created = wc_create_order(['customer_id' => $userId]);

            if ($created instanceof WP_Error) {
                return new WP_Error(
                    'codigle_checkout_order_create_failed',
                    $created->get_error_message(),
                    ['status' => 500]
                );
            }

            $order = $created;
        }

        if (! $order instanceof WC_Order) {
            return new WP_Error(
                'codigle_checkout_order_create_failed',
                'The checkout order could not be created.',
                ['status' => 500]
            );
        }

        try {
            $this->populateOrder($order, $product, $billing, $userId);
            $this->saveProfile($userId, $billing);
        } catch (RuntimeException $error) {
            return new WP_Error(
                'codigle_checkout_order_prepare_failed',
                $error->getMessage(),
                ['status' => 500]
            );
        }

        return new WP_REST_Response(
            [
                'order' => $this->orderPayload($order),
                'product' => $this->productPayload($product),
                'cards' => $this->cardPayloads($userId),
                'legal' => [
                    'documents' => $this->legal->documents(),
                    'texts' => $this->legal->consentTexts(),
                ],
            ]
        );
    }

    public function authorize(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        $userId = get_current_user_id();

        if (! $this->emailVerification->isVerified($userId)) {
            return new WP_Error(
                'codigle_checkout_email_unverified',
                'Verify your account email before continuing.',
                ['status' => 403]
            );
        }

        if (! $this->legal->requiredDocumentsAvailable()) {
            return new WP_Error(
                'codigle_checkout_legal_unavailable',
                'Required legal documents are not available.',
                ['status' => 503]
            );
        }

        $limited = $this->rateLimit($userId);

        if ($limited instanceof WP_Error) {
            return $limited;
        }

        $order = $this->authorizedOrder(
            absint($request->get_param('order_id')),
            sanitize_text_field(
                (string) $request->get_param('order_key')
            ),
            $userId
        );

        if ($order instanceof WP_Error) {
            return $order;
        }

        $terms = filter_var(
            $request->get_param('terms'),
            FILTER_VALIDATE_BOOLEAN
        );
        $renewal = filter_var(
            $request->get_param('renewal'),
            FILTER_VALIDATE_BOOLEAN
        );
        $marketing = filter_var(
            $request->get_param('marketing'),
            FILTER_VALIDATE_BOOLEAN
        );

        if (! $terms || ! $renewal) {
            return new WP_Error(
                'codigle_checkout_consent_required',
                'Terms and automatic renewal authorization are required.',
                ['status' => 422]
            );
        }

        $paymentMethod = sanitize_key(
            (string) $request->get_param('payment_method')
        );
        $cardId = absint($request->get_param('card_id'));
        $utoken = '';
        $method = ['type' => 'new', 'store_card' => '1'];

        try {
            $utoken = $this->repository->userToken($userId);
        } catch (RuntimeException $error) {
            return new WP_Error(
                'codigle_checkout_saved_card_unavailable',
                'Saved card access could not be opened securely.',
                ['status' => 500]
            );
        }

        if ($paymentMethod === 'saved') {
            if ($cardId < 1) {
                return new WP_Error(
                    'codigle_checkout_card_required',
                    'Select a saved card.',
                    ['status' => 422]
                );
            }

            $card = $this->cardById($userId, $cardId);

            if ($card === []) {
                return new WP_Error(
                    'codigle_checkout_card_invalid',
                    'The selected saved card is not available.',
                    ['status' => 404]
                );
            }

            try {
                $ctoken = $this->repository->cardToken($cardId, $userId);
            } catch (RuntimeException $error) {
                return new WP_Error(
                    'codigle_checkout_card_token_unavailable',
                    'The selected saved card could not be opened securely.',
                    ['status' => 500]
                );
            }

            if ($utoken === '' || $ctoken === '') {
                return new WP_Error(
                    'codigle_checkout_card_token_missing',
                    'The selected saved card is incomplete.',
                    ['status' => 409]
                );
            }

            $method = [
                'type' => 'saved',
                'utoken' => $utoken,
                'ctoken' => $ctoken,
                'require_cvv' => (int) $card['require_cvv'],
                'last_4' => (string) $card['last_4'],
            ];
        } elseif ($paymentMethod === 'new') {
            if ($utoken !== '') {
                $method['utoken'] = $utoken;
            }
        } else {
            return new WP_Error(
                'codigle_checkout_payment_method_invalid',
                'Choose a payment method.',
                ['status' => 422]
            );
        }

        try {
            $attempt = $this->repository->ensureAttempt($order);
            $this->repository->markAttempt(
                (int) $attempt['id'],
                'submitted',
                [
                    'test_mode' => $this->config->testMode() ? 1 : 0,
                    'submitted_at_utc' => gmdate('Y-m-d H:i:s'),
                ]
            );
            $fields = $this->tokenService->baseFields($order, $attempt);

            if (! $this->tokenService->verifyFields($fields)) {
                throw new RuntimeException(
                    'The PayTR security token failed the local contract check.'
                );
            }

            $context = $this->consentContext($request);
            $this->repository->saveCheckoutConsents(
                $order,
                (int) $attempt['id'],
                [
                    'terms' => $terms,
                    'renewal' => $renewal,
                    'marketing' => $marketing,
                ],
                $this->legal->consentTexts(),
                $this->legal->documents(),
                $context
            );
            $this->recordOrderEvidence(
                $order,
                $attempt,
                $fields,
                $context,
                $marketing
            );
        } catch (RuntimeException $error) {
            return new WP_Error(
                'codigle_checkout_authorize_failed',
                $error->getMessage(),
                ['status' => 500]
            );
        }

        return new WP_REST_Response(
            [
                'endpoint' => 'https://www.paytr.com/odeme',
                'fields' => $fields,
                'method' => $method,
                'order' => $this->orderPayload($order),
                'attempt_id' => (int) $attempt['id'],
                'merchant_oid' => (string) $attempt['merchant_oid'],
            ]
        );
    }

    private function enabled(): bool
    {
        $rollout = $this->config->rollout();

        if ($rollout === 'off') {
            return false;
        }

        if ($rollout === 'admin') {
            return current_user_can('manage_woocommerce');
        }

        return true;
    }

    private function currentUrl(): string
    {
        $uri = wp_unslash((string) ($_SERVER['REQUEST_URI'] ?? '/'));
        $path = wp_parse_url($uri, PHP_URL_PATH);
        $query = wp_parse_url($uri, PHP_URL_QUERY);
        $path = is_string($path) && str_starts_with($path, '/')
            ? $path
            : '/';
        $url = home_url($path);

        if (is_string($query) && $query !== '') {
            $url .= '?' . $query;
        }

        return esc_url_raw($url);
    }

    private function managedProduct(int $productId): WC_Product|WP_Error
    {
        $product = wc_get_product($productId);

        if (! $product instanceof WC_Product) {
            return new WP_Error(
                'codigle_checkout_product_missing',
                'The selected plan product was not found.',
                ['status' => 404]
            );
        }

        if (
            (int) get_post_meta(
                $productId,
                '_cpb_plan_page_id',
                true
            ) < 1
            || (string) get_post_meta(
                $productId,
                '_cpb_product_type',
                true
            ) !== 'subscription'
        ) {
            return new WP_Error(
                'codigle_checkout_product_unmanaged',
                'This product is not a managed Codigle subscription.',
                ['status' => 422]
            );
        }

        if (! $product->is_purchasable()) {
            return new WP_Error(
                'codigle_checkout_product_unavailable',
                'The selected plan is not currently purchasable.',
                ['status' => 409]
            );
        }

        return $product;
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(WC_Product $product): array
    {
        $productId = $product->get_id();
        $months = max(
            1,
            (int) get_post_meta(
                $productId,
                '_cpb_duration_months',
                true
            )
        );
        $price = (float) $product->get_price();
        $regular = (float) ($product->get_regular_price() ?: $price);
        $savings = max(0.0, $regular - $price);

        return [
            'id' => $productId,
            'name' => $product->get_name(),
            'duration_months' => $months,
            'duration_label' => $months === 1
                ? '1 month'
                : $months . ' months',
            'price' => wc_format_decimal($price, 2),
            'price_html' => wc_price($price),
            'regular_price' => wc_format_decimal($regular, 2),
            'regular_price_html' => wc_price($regular),
            'savings' => wc_format_decimal($savings, 2),
            'savings_html' => wc_price($savings),
            'currency' => get_woocommerce_currency(),
            'plan_page_id' => (int) get_post_meta(
                $productId,
                '_cpb_plan_page_id',
                true
            ),
            'plan_id' => (string) get_post_meta(
                $productId,
                '_cpb_plan_id',
                true
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function durationProducts(WC_Product $selected): array
    {
        $selectedId = $selected->get_id();
        $planPageId = (int) get_post_meta(
            $selectedId,
            '_cpb_plan_page_id',
            true
        );
        $planId = (string) get_post_meta(
            $selectedId,
            '_cpb_plan_id',
            true
        );
        $metaQuery = [
            'relation' => 'AND',
            [
                'key' => '_cpb_plan_page_id',
                'value' => (string) $planPageId,
            ],
            [
                'key' => '_cpb_product_type',
                'value' => 'subscription',
            ],
        ];

        if ($planId !== '') {
            $metaQuery[] = [
                'key' => '_cpb_plan_id',
                'value' => $planId,
            ];
        }

        $ids = get_posts(
            [
                'post_type' => 'product',
                'post_status' => 'publish',
                'posts_per_page' => 100,
                'fields' => 'ids',
                'meta_query' => $metaQuery,
            ]
        );
        $products = [];

        foreach ($ids as $id) {
            $product = wc_get_product((int) $id);

            if (
                ! $product instanceof WC_Product
                || ! $product->is_purchasable()
            ) {
                continue;
            }

            $products[] = $this->productPayload($product);
        }

        usort(
            $products,
            static fn (array $a, array $b): int => (
                (int) $a['duration_months']
                <=> (int) $b['duration_months']
            )
        );

        return $products !== []
            ? $products
            : [$this->productPayload($selected)];
    }

    /**
     * @return array<string, string|bool>
     */
    private function profile(int $userId): array
    {
        $user = get_userdata($userId);

        return [
            'first_name' => (string) get_user_meta(
                $userId,
                'billing_first_name',
                true
            ) ?: (string) get_user_meta($userId, 'first_name', true),
            'last_name' => (string) get_user_meta(
                $userId,
                'billing_last_name',
                true
            ) ?: (string) get_user_meta($userId, 'last_name', true),
            'email' => $user ? (string) $user->user_email : '',
            'phone' => (string) get_user_meta(
                $userId,
                'billing_phone',
                true
            ),
            'country' => (string) get_user_meta(
                $userId,
                'billing_country',
                true
            ),
            'state' => (string) get_user_meta(
                $userId,
                'billing_state',
                true
            ),
            'city' => (string) get_user_meta(
                $userId,
                'billing_city',
                true
            ),
            'postcode' => (string) get_user_meta(
                $userId,
                'billing_postcode',
                true
            ),
            'address_1' => (string) get_user_meta(
                $userId,
                'billing_address_1',
                true
            ),
            'address_2' => (string) get_user_meta(
                $userId,
                'billing_address_2',
                true
            ),
            'company' => (string) get_user_meta(
                $userId,
                'billing_company',
                true
            ),
            'vat_id' => (string) get_user_meta(
                $userId,
                'billing_vat_id',
                true
            ),
            'tax_office' => (string) get_user_meta(
                $userId,
                'billing_tax_office',
                true
            ),
            'company_invoice' => (string) get_user_meta(
                $userId,
                '_codigle_company_invoice',
                true
            ) === 'yes',
        ];
    }

    /**
     * @return array<string, string>|WP_Error
     */
    private function billingFromRequest(
        WP_REST_Request $request
    ): array|WP_Error {
        $user = wp_get_current_user();
        $email = sanitize_email(
            (string) $request->get_param('email')
        );
        $phone = preg_replace(
            '/[^0-9+]/',
            '',
            (string) $request->get_param('phone')
        ) ?? '';
        $country = strtoupper(
            sanitize_text_field(
                (string) $request->get_param('country')
            )
        );
        $countries = WC()->countries->get_countries();
        $billing = [
            'first_name' => substr(
                sanitize_text_field(
                    (string) $request->get_param('first_name')
                ),
                0,
                60
            ),
            'last_name' => substr(
                sanitize_text_field(
                    (string) $request->get_param('last_name')
                ),
                0,
                60
            ),
            'email' => substr($email, 0, 100),
            'phone' => substr($phone, 0, 20),
            'country' => $country,
            'state' => substr(
                sanitize_text_field(
                    (string) $request->get_param('state')
                ),
                0,
                100
            ),
            'city' => substr(
                sanitize_text_field(
                    (string) $request->get_param('city')
                ),
                0,
                100
            ),
            'postcode' => substr(
                sanitize_text_field(
                    (string) $request->get_param('postcode')
                ),
                0,
                30
            ),
            'address_1' => substr(
                sanitize_text_field(
                    (string) $request->get_param('address_1')
                ),
                0,
                200
            ),
            'address_2' => substr(
                sanitize_text_field(
                    (string) $request->get_param('address_2')
                ),
                0,
                200
            ),
            'company' => substr(
                sanitize_text_field(
                    (string) $request->get_param('company')
                ),
                0,
                160
            ),
            'vat_id' => substr(
                sanitize_text_field(
                    (string) $request->get_param('vat_id')
                ),
                0,
                80
            ),
            'tax_office' => substr(
                sanitize_text_field(
                    (string) $request->get_param('tax_office')
                ),
                0,
                120
            ),
            'company_invoice' => filter_var(
                $request->get_param('company_invoice'),
                FILTER_VALIDATE_BOOLEAN
            ) ? 'yes' : 'no',
        ];

        if (
            $billing['first_name'] === ''
            || $billing['last_name'] === ''
            || $billing['address_1'] === ''
            || $billing['city'] === ''
            || $billing['postcode'] === ''
        ) {
            return new WP_Error(
                'codigle_checkout_billing_required',
                'Name and complete billing address are required.',
                ['status' => 422]
            );
        }

        if (
            $email === ''
            || ! hash_equals(
                strtolower((string) $user->user_email),
                strtolower($email)
            )
        ) {
            return new WP_Error(
                'codigle_checkout_email_mismatch',
                'Use the verified email address of this account.',
                ['status' => 422]
            );
        }

        $phoneDigits = preg_replace('/\D/', '', $phone) ?? '';

        if (strlen($phoneDigits) < 7 || strlen($phoneDigits) > 15) {
            return new WP_Error(
                'codigle_checkout_phone_invalid',
                'Enter a valid phone number including country code.',
                ['status' => 422]
            );
        }

        if ($country === '' || ! isset($countries[$country])) {
            return new WP_Error(
                'codigle_checkout_country_invalid',
                'Choose a valid billing country.',
                ['status' => 422]
            );
        }

        if (
            $billing['company_invoice'] === 'yes'
            && $billing['company'] === ''
        ) {
            return new WP_Error(
                'codigle_checkout_company_required',
                'Company name is required for a company invoice.',
                ['status' => 422]
            );
        }

        return $billing;
    }

    private function orderFromRequest(
        WP_REST_Request $request,
        int $userId
    ): ?WC_Order {
        $orderId = absint($request->get_param('order_id'));
        $orderKey = sanitize_text_field(
            (string) $request->get_param('order_key')
        );

        if ($orderId < 1 || $orderKey === '') {
            return null;
        }

        $order = wc_get_order($orderId);

        if (
            ! $order instanceof WC_Order
            || $order->get_customer_id() !== $userId
            || ! hash_equals($order->get_order_key(), $orderKey)
            || $order->is_paid()
            || ! in_array(
                $order->get_status(),
                ['pending', 'failed', 'cancelled'],
                true
            )
            || $order->get_meta('_codigle_checkout_flow') !== 'modal_v1'
        ) {
            return null;
        }

        return $order;
    }

    /**
     * @param array<string, string> $billing
     */
    private function populateOrder(
        WC_Order $order,
        WC_Product $product,
        array $billing,
        int $userId
    ): void {
        $order->remove_order_items('line_item');
        $order->add_product($product, 1);
        $order->set_customer_id($userId);
        $order->set_created_via('codigle_modal_checkout');
        $client = $this->clientIp->details();
        $order->set_customer_ip_address(
            (string) (
                $client['original_value']
                ?: $client['value']
            )
        );
        $order->set_customer_user_agent(
            substr(
                sanitize_text_field(
                    (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
                ),
                0,
                500
            )
        );

        foreach (
            [
                'first_name',
                'last_name',
                'email',
                'phone',
                'country',
                'state',
                'city',
                'postcode',
                'address_1',
                'address_2',
                'company',
            ] as $field
        ) {
            $setter = 'set_billing_' . $field;
            $order->{$setter}((string) ($billing[$field] ?? ''));
        }

        $order->set_payment_method(Config::GATEWAY_ID);
        $order->set_payment_method_title('Credit or debit card');
        $order->update_meta_data('_codigle_checkout_flow', 'modal_v1');
        $order->update_meta_data(
            '_codigle_paytr_direct_auto_renew',
            'yes'
        );
        $order->update_meta_data(
            '_codigle_checkout_source_url',
            $this->sourceUrl()
        );
        $order->update_meta_data(
            '_codigle_billing_vat_id',
            (string) ($billing['vat_id'] ?? '')
        );
        $order->update_meta_data(
            '_codigle_billing_tax_office',
            (string) ($billing['tax_office'] ?? '')
        );
        $order->update_meta_data(
            '_codigle_company_invoice',
            (string) ($billing['company_invoice'] ?? 'no')
        );
        $order->calculate_taxes(
            [
                'country' => (string) $billing['country'],
                'state' => (string) $billing['state'],
                'postcode' => (string) $billing['postcode'],
                'city' => (string) $billing['city'],
            ]
        );
        $order->calculate_totals(false);
        $order->set_status('pending');
        $order->save();
    }

    /**
     * @param array<string, string> $billing
     */
    private function saveProfile(int $userId, array $billing): void
    {
        $customer = new WC_Customer($userId);

        foreach (
            [
                'first_name',
                'last_name',
                'email',
                'phone',
                'country',
                'state',
                'city',
                'postcode',
                'address_1',
                'address_2',
                'company',
            ] as $field
        ) {
            $setter = 'set_billing_' . $field;
            $customer->{$setter}((string) ($billing[$field] ?? ''));
        }

        $customer->save();
        update_user_meta(
            $userId,
            'billing_vat_id',
            (string) ($billing['vat_id'] ?? '')
        );
        update_user_meta(
            $userId,
            'billing_tax_office',
            (string) ($billing['tax_office'] ?? '')
        );
        update_user_meta(
            $userId,
            '_codigle_company_invoice',
            (string) ($billing['company_invoice'] ?? 'no')
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function orderPayload(WC_Order $order): array
    {
        $items = [];

        foreach ($order->get_items() as $item) {
            $items[] = [
                'name' => $item->get_name(),
                'quantity' => (int) $item->get_quantity(),
                'subtotal_html' => wc_price(
                    (float) $item->get_subtotal(),
                    ['currency' => $order->get_currency()]
                ),
                'total_html' => wc_price(
                    (float) $item->get_total(),
                    ['currency' => $order->get_currency()]
                ),
            ];
        }

        $tax = (float) $order->get_total_tax();
        $duration = 1;
        $firstItem = current($order->get_items());

        if ($firstItem) {
            $duration = max(
                1,
                (int) get_post_meta(
                    $firstItem->get_product_id(),
                    '_cpb_duration_months',
                    true
                )
            );
        }

        return [
            'id' => $order->get_id(),
            'key' => $order->get_order_key(),
            'number' => $order->get_order_number(),
            'currency' => $order->get_currency(),
            'items' => $items,
            'subtotal_html' => wc_price(
                (float) $order->get_subtotal(),
                ['currency' => $order->get_currency()]
            ),
            'tax_html' => wc_price(
                $tax,
                ['currency' => $order->get_currency()]
            ),
            'total' => wc_format_decimal($order->get_total(), 2),
            'total_html' => $order->get_formatted_order_total(),
            'renewal_date' => (new \DateTimeImmutable(
                'now',
                new \DateTimeZone('UTC')
            ))->modify('+' . $duration . ' months')->format('Y-m-d'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cardPayloads(int $userId): array
    {
        return array_map(
            static fn (array $card): array => [
                'id' => (int) $card['id'],
                'last_4' => (string) $card['last_4'],
                'expiry_month' => (string) $card['expiry_month'],
                'expiry_year' => (string) $card['expiry_year'],
                'bank_name' => (string) $card['bank_name'],
                'brand' => (string) $card['card_brand'],
                'schema' => (string) $card['card_schema'],
                'require_cvv' => (int) $card['require_cvv'],
                'is_default' => (int) $card['is_default'],
            ],
            $this->repository->cards($userId)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function cardById(int $userId, int $cardId): array
    {
        return $this->repository->cardById($cardId, $userId);
    }

    private function authorizedOrder(
        int $orderId,
        string $orderKey,
        int $userId
    ): WC_Order|WP_Error {
        $order = wc_get_order($orderId);

        if (
            ! $order instanceof WC_Order
            || $orderKey === ''
            || ! hash_equals($order->get_order_key(), $orderKey)
            || $order->get_customer_id() !== $userId
            || $order->is_paid()
            || $order->get_meta('_codigle_checkout_flow') !== 'modal_v1'
            || ! in_array($order->get_status(), ['pending', 'failed'], true)
        ) {
            return new WP_Error(
                'codigle_checkout_order_invalid',
                'The checkout session is no longer valid.',
                ['status' => 409]
            );
        }

        return $order;
    }

    private function rateLimit(int $userId): bool|WP_Error
    {
        $details = $this->clientIp->details();
        $key = 'cdg_checkout_rate_'
            . hash(
                'sha256',
                $userId . '|' . (string) $details['original_value']
            );
        $count = (int) get_transient($key);

        if ($count >= 8) {
            return new WP_Error(
                'codigle_checkout_rate_limited',
                'Too many checkout attempts. Please wait ten minutes.',
                ['status' => 429]
            );
        }

        set_transient($key, (string) ($count + 1), 10 * MINUTE_IN_SECONDS);

        return true;
    }

    /**
     * @return array<string, string>
     */
    private function consentContext(WP_REST_Request $request): array
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
            'ip_fallback_reason' => (string) (
                $details['fallback_reason']
                ?? ''
            ),
            'user_agent' => substr(
                sanitize_text_field(
                    (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
                ),
                0,
                500
            ),
            'source_url' => esc_url_raw(
                (string) $request->get_param('source_url')
            ) ?: $this->sourceUrl(),
            'session_hash' => hash(
                'sha256',
                wp_get_session_token()
            ),
        ];
    }

    /**
     * @param array<string, mixed> $attempt
     * @param array<string, string> $fields
     * @param array<string, string> $context
     */
    private function recordOrderEvidence(
        WC_Order $order,
        array $attempt,
        array $fields,
        array $context,
        bool $marketing
    ): void {
        $userId = $order->get_customer_id();
        $user = get_userdata($userId);
        $documents = $this->legal->documents();
        $risk = $this->riskSnapshot($userId);
        $snapshot = [
            'flow' => 'modal_v1',
            'plugin_version' => CODIGLE_PAYTR_DIRECT_VERSION,
            'attempt_id' => (int) ($attempt['id'] ?? 0),
            'merchant_oid' => (string) ($fields['merchant_oid'] ?? ''),
            'payment_amount' => (string) ($fields['payment_amount'] ?? ''),
            'currency' => (string) ($fields['currency'] ?? ''),
            'test_mode' => (string) ($fields['test_mode'] ?? ''),
            'non_3d' => (string) ($fields['non_3d'] ?? ''),
            'token_sha256' => hash(
                'sha256',
                (string) ($fields['paytr_token'] ?? '')
            ),
            'email_sha256' => hash(
                'sha256',
                strtolower((string) ($fields['email'] ?? ''))
            ),
            'phone_sha256' => hash(
                'sha256',
                preg_replace(
                    '/\D/',
                    '',
                    $order->get_billing_phone()
                ) ?? ''
            ),
            'billing_address_sha256' => hash(
                'sha256',
                strtolower(
                    implode(
                        '|',
                        [
                            $order->get_billing_address_1(),
                            $order->get_billing_address_2(),
                            $order->get_billing_city(),
                            $order->get_billing_state(),
                            $order->get_billing_postcode(),
                            $order->get_billing_country(),
                        ]
                    )
                )
            ),
            'ip_hash' => hash(
                'sha256',
                (string) ($context['ip'] ?? '')
            ),
            'ip_source' => (string) ($context['ip_source'] ?? ''),
            'ip_fallback_reason' => (string) (
                $context['ip_fallback_reason']
                ?? ''
            ),
            'user_agent_hash' => hash(
                'sha256',
                (string) ($context['user_agent'] ?? '')
            ),
            'session_hash' => (string) ($context['session_hash'] ?? ''),
            'source_url' => (string) ($context['source_url'] ?? ''),
            'marketing_consent' => $marketing,
            'legal_manifest_sha256' => hash(
                'sha256',
                (string) wp_json_encode(
                    $documents,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                )
            ),
            'account_registered_utc' => $this->registeredAtUtc($userId),
            'billing_country' => $order->get_billing_country(),
            'email_verified_at_utc' => (string) get_user_meta(
                $userId,
                '_codigle_email_verified_at_utc',
                true
            ),
            'risk' => $risk,
            'generated_at_utc' => gmdate('c'),
        ];

        $order->update_meta_data(
            '_codigle_paytr_direct_signature_snapshot',
            wp_json_encode($snapshot, JSON_UNESCAPED_SLASHES)
        );
        $order->update_meta_data(
            '_codigle_terms_accepted_at_utc',
            gmdate('Y-m-d H:i:s')
        );
        $order->update_meta_data(
            '_codigle_renewal_authorized_at_utc',
            gmdate('Y-m-d H:i:s')
        );
        $order->update_meta_data(
            '_codigle_marketing_consent',
            $marketing ? 'yes' : 'no'
        );
        $order->save();
    }

    private function registeredAtUtc(int $userId): string
    {
        $user = get_userdata($userId);

        if (! $user) {
            return '';
        }

        $timestamp = strtotime(
            (string) $user->user_registered . ' UTC'
        );

        return is_int($timestamp) ? gmdate('c', $timestamp) : '';
    }

    /**
     * @return array<string, int>
     */
    private function riskSnapshot(int $userId): array
    {
        $user = get_userdata($userId);
        $registered = $user
            ? strtotime((string) $user->user_registered . ' UTC')
            : false;
        $accountAgeDays = is_int($registered)
            ? max(0, (int) floor((time() - $registered) / DAY_IN_SECONDS))
            : 0;
        $paid = wc_get_orders(
            [
                'customer_id' => $userId,
                'status' => ['processing', 'completed'],
                'limit' => 1,
                'paginate' => true,
                'return' => 'ids',
            ]
        );
        $failed = wc_get_orders(
            [
                'customer_id' => $userId,
                'status' => ['failed', 'cancelled'],
                'limit' => 1,
                'paginate' => true,
                'return' => 'ids',
            ]
        );

        return [
            'account_age_days' => $accountAgeDays,
            'prior_paid_orders' => (int) ($paid->total ?? 0),
            'prior_failed_orders' => (int) ($failed->total ?? 0),
        ];
    }

    private function sourceUrl(): string
    {
        return wp_validate_redirect(
            esc_url_raw(
                (string) ($_SERVER['HTTP_REFERER'] ?? $this->currentUrl())
            ),
            $this->currentUrl()
        );
    }
}
