<?php

declare(strict_types=1);

namespace Codigle\PaytrDirect\Checkout;

use Codigle\PaytrDirect\Database\Repository;
use Codigle\PaytrDirect\Paytr\CapiClient;
use Codigle\PaytrDirect\Paytr\TokenService;
use Codigle\PaytrDirect\Support\Config;
use RuntimeException;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class PaymentPage
{
    public function __construct(
        private readonly Config $config,
        private readonly Repository $repository,
        private readonly TokenService $tokenService,
        private readonly CapiClient $capi
    ) {
    }

    public static function ensurePage(): int
    {
        $stored = (int) get_option(
            'codigle_paytr_direct_payment_page_id',
            0
        );

        if (
            $stored > 0
            && get_post_type($stored) === 'page'
            && get_post_status($stored) !== 'trash'
        ) {
            return $stored;
        }

        $existing = get_page_by_path('codigle-secure-payment');

        if (
            $existing instanceof \WP_Post
            && has_shortcode(
                (string) $existing->post_content,
                'codigle_paytr_direct_payment'
            )
        ) {
            $pageId = (int) $existing->ID;

            if ($existing->post_status !== 'publish') {
                wp_update_post(
                    [
                        'ID' => $pageId,
                        'post_status' => 'publish',
                    ]
                );
            }
        } else {
            $pageId = (int) wp_insert_post(
                [
                    'post_type' => 'page',
                    'post_status' => 'publish',
                    'post_title' => 'Secure Payment',
                    'post_name' => 'codigle-secure-payment',
                    'post_content' => '[codigle_paytr_direct_payment]',
                ]
            );
        }

        update_option(
            'codigle_paytr_direct_payment_page_id',
            $pageId,
            false
        );

        return $pageId;
    }

    public function register(): void
    {
        add_shortcode(
            'codigle_paytr_direct_payment',
            [$this, 'shortcode']
        );
        add_action(
            'wp_enqueue_scripts',
            [$this, 'enqueue']
        );
        add_action(
            'rest_api_init',
            function (): void {
                register_rest_route(
                    'codigle-paytr-direct/v1',
                    '/order-status',
                    [
                        'methods' => 'GET',
                        'callback' => [$this, 'orderStatus'],
                        'permission_callback' => '__return_true',
                    ]
                );
            }
        );
    }

    public static function url(WC_Order $order): string
    {
        $pageId = self::ensurePage();
        $permalink = get_permalink($pageId);

        return add_query_arg(
            [
                'order_id' => $order->get_id(),
                'key' => $order->get_order_key(),
            ],
            is_string($permalink)
                ? $permalink
                : home_url('/codigle-secure-payment/')
        );
    }

    public function enqueue(): void
    {
        if (
            ! is_page($this->config->paymentPageId())
            && ! is_account_page()
        ) {
            return;
        }

        wp_enqueue_style(
            'codigle-paytr-direct-payment',
            CODIGLE_PAYTR_DIRECT_URL . 'assets/payment.css',
            [],
            CODIGLE_PAYTR_DIRECT_VERSION
        );
        wp_enqueue_script(
            'codigle-paytr-direct-payment',
            CODIGLE_PAYTR_DIRECT_URL . 'assets/payment.js',
            [],
            CODIGLE_PAYTR_DIRECT_VERSION,
            true
        );
        wp_localize_script(
            'codigle-paytr-direct-payment',
            'CodiglePaytrDirect',
            [
                'statusUrl' => rest_url(
                    'codigle-paytr-direct/v1/order-status'
                ),
            ]
        );
    }

    public function shortcode(): string
    {
        $order = $this->requestedOrder();

        if (! $order instanceof WC_Order) {
            return $this->message(
                'Payment link invalid',
                'The order could not be verified.'
            );
        }

        if ($order->is_paid()) {
            return $this->message(
                'Payment completed',
                'Your payment has been confirmed.',
                $this->returnUrlForOrder($order)
            );
        }

        if (
            $order->get_payment_method()
            !== Config::GATEWAY_ID
        ) {
            return $this->message(
                'Payment method mismatch',
                'This order is not assigned to PayTR Direct.'
            );
        }

        try {
            $attempt = $this->attemptForOrder($order);
        } catch (RuntimeException $error) {
            return $this->message(
                'Payment could not start',
                $error->getMessage()
            );
        }

        $this->repository->markAttempt(
            (int) $attempt['id'],
            'submitted',
            ['test_mode' => $this->config->testMode() ? 1 : 0]
        );

        $userId = $order->get_customer_id();
        $utoken = '';
        $cardError = '';

        if ($userId > 0) {
            try {
                $utoken = $this->repository->userToken($userId);
            } catch (RuntimeException $error) {
                $cardError = 'Saved card access could not be opened securely.';
            }
        }

        if ($utoken !== '') {
            $refreshed = $this->capi->refreshForUser($userId);

            if ($refreshed instanceof WP_Error) {
                $cardError = $refreshed->get_error_message();
            }
        }

        $cards = $userId > 0
            ? $this->repository->cards($userId)
            : [];
        $fields = $this->tokenService->baseFields(
            $order,
            $attempt
        );

        if (! $this->tokenService->verifyFields($fields)) {
            return $this->message(
                'Payment could not start',
                'The PayTR security token failed the local contract check.'
            );
        }

        $this->recordSignatureSnapshot(
            $order,
            $attempt,
            $fields
        );

        ob_start();
        ?>
        <main class="cdg-pay-shell"
              data-order-id="<?php echo esc_attr((string) $order->get_id()); ?>"
              data-order-key="<?php echo esc_attr($order->get_order_key()); ?>"
              data-return-result="<?php echo esc_attr(sanitize_key((string) ($_GET['paytr_result'] ?? ''))); ?>">
            <section class="cdg-pay-card">
                <header class="cdg-pay-header">
                    <div>
                        <span class="cdg-pay-eyebrow">CODIGLE SECURE CHECKOUT</span>
                        <h1>Complete your payment</h1>
                        <p>First payment is protected by 3D Secure.</p>
                    </div>
                    <div class="cdg-pay-lock">Secure</div>
                </header>

                <div class="cdg-pay-summary">
                    <div>
                        <span>Order</span>
                        <strong>#<?php echo esc_html((string) $order->get_order_number()); ?></strong>
                    </div>
                    <div>
                        <span>Subscription period</span>
                        <strong><?php echo esc_html($this->durationLabel($order)); ?></strong>
                    </div>
                    <div>
                        <span>Total</span>
                        <strong><?php echo wp_kses_post($order->get_formatted_order_total()); ?></strong>
                    </div>
                </div>

                <div class="cdg-pay-status" hidden></div>

                <?php
                $failMessage = sanitize_text_field(
                    (string) ($_POST['fail_message'] ?? '')
                );
                ?>
                <?php if ($failMessage !== '') : ?>
                    <div class="cdg-pay-note">
                        <?php echo esc_html($failMessage); ?>
                    </div>
                <?php endif; ?>

                <?php if ($cardError !== '') : ?>
                    <div class="cdg-pay-note">
                        Saved cards could not be refreshed: <?php echo esc_html($cardError); ?>
                    </div>
                <?php endif; ?>

                <?php if ($cards !== []) : ?>
                    <section class="cdg-pay-section">
                        <div class="cdg-pay-section-title">
                            <h2>Saved cards</h2>
                            <span>Fetched securely from PayTR</span>
                        </div>
                        <div class="cdg-saved-cards">
                            <?php foreach ($cards as $card) : ?>
                                <?php
                                try {
                                    $ctoken = $this->repository->cardToken(
                                        (int) $card['id'],
                                        $userId
                                    );
                                } catch (RuntimeException $error) {
                                    $ctoken = '';
                                }

                                if ($ctoken === '') {
                                    continue;
                                }
                                ?>
                                <form class="cdg-saved-card cdg-paytr-form"
                                      action="https://www.paytr.com/odeme"
                                      method="post"
                                      autocomplete="off">
                                    <?php $this->hiddenFields($fields); ?>
                                    <input type="hidden" name="utoken" value="<?php echo esc_attr($utoken); ?>">
                                    <input type="hidden" name="ctoken" value="<?php echo esc_attr($ctoken); ?>">
                                    <input type="hidden" name="require_cvv" value="<?php echo esc_attr((string) ((int) $card['require_cvv'])); ?>">

                                    <div class="cdg-card-main">
                                        <div class="cdg-card-brand">
                                            <?php echo esc_html(
                                                strtoupper(
                                                    (string) (
                                                        $card['card_schema']
                                                        ?: $card['card_brand']
                                                        ?: 'CARD'
                                                    )
                                                )
                                            ); ?>
                                        </div>
                                        <div>
                                            <strong>•••• <?php echo esc_html((string) $card['last_4']); ?></strong>
                                            <span>
                                                <?php echo esc_html((string) $card['bank_name']); ?>
                                                <?php if ((string) $card['expiry_month'] !== '') : ?>
                                                    · <?php echo esc_html((string) $card['expiry_month']); ?>/<?php echo esc_html((string) $card['expiry_year']); ?>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>

                                    <?php if ((int) $card['require_cvv'] === 1) : ?>
                                        <label class="cdg-cvv">
                                            CVV
                                            <input type="password"
                                                   name="cvv"
                                                   inputmode="numeric"
                                                   maxlength="4"
                                                   required
                                                   autocomplete="cc-csc">
                                        </label>
                                    <?php endif; ?>

                                    <label class="cdg-renew-consent">
                                        <input type="checkbox" required>
                                        <span>I approve the subscription renewal terms and authorize future recurring charges for the selected period.</span>
                                    </label>

                                    <button type="submit">Pay with this card</button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <section class="cdg-pay-section">
                    <div class="cdg-pay-section-title">
                        <h2><?php echo $cards === [] ? 'Card details' : 'Use a new card'; ?></h2>
                        <span>The card will be saved at PayTR for renewals.</span>
                    </div>

                    <form class="cdg-new-card cdg-paytr-form"
                          action="https://www.paytr.com/odeme"
                          method="post"
                          autocomplete="off">
                        <?php $this->hiddenFields($fields); ?>
                        <input type="hidden" name="store_card" value="1">
                        <?php if ($utoken !== '') : ?>
                            <input type="hidden" name="utoken" value="<?php echo esc_attr($utoken); ?>">
                        <?php endif; ?>

                        <label>
                            Name on card
                            <input type="text"
                                   name="cc_owner"
                                   maxlength="50"
                                   required
                                   autocomplete="cc-name">
                        </label>

                        <label class="cdg-card-number">
                            Card number
                            <input type="text"
                                   name="card_number"
                                   inputmode="numeric"
                                   maxlength="23"
                                   required
                                   autocomplete="cc-number"
                                   data-card-number>
                        </label>

                        <div class="cdg-pay-grid">
                            <label>
                                Expiry month
                                <select name="expiry_month" required autocomplete="cc-exp-month">
                                    <option value="">MM</option>
                                    <?php for ($month = 1; $month <= 12; $month++) : ?>
                                        <option value="<?php echo esc_attr((string) $month); ?>">
                                            <?php echo esc_html(str_pad((string) $month, 2, '0', STR_PAD_LEFT)); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </label>

                            <label>
                                Expiry year
                                <select name="expiry_year" required autocomplete="cc-exp-year">
                                    <option value="">YY</option>
                                    <?php
                                    $year = (int) gmdate('y');

                                    for ($offset = 0; $offset <= 15; $offset++) :
                                        $value = str_pad(
                                            (string) (($year + $offset) % 100),
                                            2,
                                            '0',
                                            STR_PAD_LEFT
                                        );
                                        ?>
                                        <option value="<?php echo esc_attr($value); ?>">
                                            <?php echo esc_html($value); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </label>

                            <label>
                                CVV
                                <input type="password"
                                       name="cvv"
                                       inputmode="numeric"
                                       maxlength="4"
                                       required
                                       autocomplete="cc-csc">
                            </label>
                        </div>

                        <label class="cdg-pay-terms cdg-renew-consent">
                            <input type="checkbox" required>
                            <span>Save this card securely at PayTR and renew the selected subscription period automatically. Future renewals can be cancelled from the customer account.</span>
                        </label>

                        <button type="submit">Complete secure payment</button>
                    </form>
                </section>
            </section>

            <aside class="cdg-pay-aside">
                <h2>Order summary</h2>
                <?php foreach ($order->get_items() as $item) : ?>
                    <div class="cdg-pay-line">
                        <span><?php echo esc_html($item->get_name()); ?></span>
                        <strong><?php echo wp_kses_post(
                            $order->get_formatted_line_subtotal($item)
                        ); ?></strong>
                    </div>
                <?php endforeach; ?>
                <div class="cdg-pay-total">
                    <span>Total</span>
                    <strong><?php echo wp_kses_post($order->get_formatted_order_total()); ?></strong>
                </div>
                <ul>
                    <li>3D Secure first payment</li>
                    <li>Card details go directly to PayTR</li>
                    <li>Automatic renewal for the selected period</li>
                </ul>
            </aside>
        </main>
        <?php

        return (string) ob_get_clean();
    }

    public function orderStatus(
        WP_REST_Request $request
    ): WP_REST_Response {
        $orderId = absint($request->get_param('order_id'));
        $key = sanitize_text_field(
            (string) $request->get_param('key')
        );
        $order = wc_get_order($orderId);

        if (
            ! $order instanceof WC_Order
            || ! hash_equals($order->get_order_key(), $key)
        ) {
            return new WP_REST_Response(
                ['error' => 'invalid_order'],
                404
            );
        }

        return new WP_REST_Response(
            [
                'status' => $order->get_status(),
                'paid' => $order->is_paid(),
                'redirect' => $order->is_paid()
                    ? $this->returnUrlForOrder($order)
                    : '',
            ]
        );
    }

    private function requestedOrder(): ?WC_Order
    {
        $orderId = absint($_GET['order_id'] ?? 0);
        $key = sanitize_text_field(
            (string) ($_GET['key'] ?? '')
        );
        $order = wc_get_order($orderId);

        if (
            ! $order instanceof WC_Order
            || $key === ''
            || ! hash_equals($order->get_order_key(), $key)
        ) {
            return null;
        }

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    private function attemptForOrder(WC_Order $order): array
    {
        $type = sanitize_key(
            (string) $order->get_meta(
                '_codigle_payment_attempt_type',
                true
            )
        );
        $allowed = ['initial', 'renewal', 'upgrade'];

        if (! in_array($type, $allowed, true)) {
            $type = 'initial';
        }

        return $this->repository->ensureAttempt(
            $order,
            $type,
            (int) $order->get_meta(
                '_codigle_subscription_id',
                true
            )
        );
    }

    private function returnUrlForOrder(WC_Order $order): string
    {
        $fallback = $order->get_checkout_order_received_url();
        $requested = esc_url_raw(
            (string) $order->get_meta('_codigle_return_url', true)
        );

        if ($requested === '') {
            return $fallback;
        }

        return wp_validate_redirect($requested, $fallback);
    }

    /**
     * @param array<string, string> $fields
     */
    private function hiddenFields(array $fields): void
    {
        foreach ($fields as $name => $value) {
            printf(
                '<input type="hidden" name="%s" value="%s">',
                esc_attr($name),
                esc_attr($value)
            );
        }
    }

    /**
     * Keep enough non-card data to diagnose a rejected token without storing
     * merchant secrets, the raw token, PAN or CVV.
     *
     * @param array<string, mixed> $attempt
     * @param array<string, string> $fields
     */
    private function recordSignatureSnapshot(
        WC_Order $order,
        array $attempt,
        array $fields
    ): void {
        $ip = (string) ($fields['user_ip'] ?? '');
        $snapshot = [
            'plugin_version' => CODIGLE_PAYTR_DIRECT_VERSION,
            'attempt_id' => (int) ($attempt['id'] ?? 0),
            'merchant_oid' => (string) (
                $fields['merchant_oid']
                ?? ''
            ),
            'user_ip' => $ip,
            'ip_version' => filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV6
            ) ? 6 : 4,
            'email_sha256' => hash(
                'sha256',
                strtolower(
                    (string) ($fields['email'] ?? '')
                )
            ),
            'payment_amount' => (string) (
                $fields['payment_amount']
                ?? ''
            ),
            'payment_type' => (string) (
                $fields['payment_type']
                ?? ''
            ),
            'installment_count' => (string) (
                $fields['installment_count']
                ?? ''
            ),
            'currency' => (string) (
                $fields['currency']
                ?? ''
            ),
            'test_mode' => (string) (
                $fields['test_mode']
                ?? ''
            ),
            'non_3d' => (string) (
                $fields['non_3d']
                ?? ''
            ),
            'token_sha256' => hash(
                'sha256',
                (string) ($fields['paytr_token'] ?? '')
            ),
            'token_decoded_bytes' => strlen(
                (string) (
                    base64_decode(
                        (string) (
                            $fields['paytr_token']
                            ?? ''
                        ),
                        true
                    )
                    ?: ''
                )
            ),
            'generated_at_utc' => gmdate('c'),
        ];

        $order->update_meta_data(
            '_codigle_paytr_direct_signature_snapshot',
            wp_json_encode(
                $snapshot,
                JSON_UNESCAPED_SLASHES
            )
        );
        $order->save();
    }

    private function durationLabel(WC_Order $order): string
    {
        $item = current($order->get_items());
        $productId = $item ? (int) $item->get_product_id() : 0;
        $months = max(
            1,
            (int) get_post_meta(
                $productId,
                '_cpb_duration_months',
                true
            )
        );

        return $months === 1
            ? '1 month'
            : $months . ' months';
    }

    private function message(
        string $title,
        string $body,
        string $url = ''
    ): string {
        $button = $url !== ''
            ? '<a class="button" href="' . esc_url($url) . '">Continue</a>'
            : '';

        return sprintf(
            '<div class="cdg-pay-message"><h1>%s</h1><p>%s</p>%s</div>',
            esc_html($title),
            esc_html($body),
            $button
        );
    }
}
