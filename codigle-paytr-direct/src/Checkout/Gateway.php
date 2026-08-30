<?php

declare(strict_types=1);

namespace Codigle\PaytrDirect\Checkout;

use Codigle\PaytrDirect\Support\Config;
use WC_Order;
use WC_Payment_Gateway;
use WP_Error;

final class Gateway extends WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = Config::GATEWAY_ID;
        $this->method_title = 'Codigle PayTR Direct';
        $this->method_description =
            'PayTR Direct API with 3D Secure and saved cards.';
        $this->has_fields = false;
        $this->supports = ['products'];
        $this->icon = '';

        $this->init_form_fields();
        $this->init_settings();

        $this->title = (string) $this->get_option(
            'title',
            'Credit or debit card'
        );
        $this->description = (string) $this->get_option(
            'description',
            'Secure 3D payment through PayTR.'
        );
        $this->enabled = (string) $this->get_option(
            'enabled',
            'yes'
        );

        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            [$this, 'process_admin_options']
        );
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled' => [
                'title' => 'Enable',
                'type' => 'checkbox',
                'label' => 'Enable Codigle PayTR Direct',
                'default' => 'yes',
            ],
            'title' => [
                'title' => 'Title',
                'type' => 'text',
                'default' => 'Credit or debit card',
            ],
            'description' => [
                'title' => 'Description',
                'type' => 'textarea',
                'default' => 'Secure 3D payment through PayTR.',
            ],
            'rollout' => [
                'title' => 'Rollout',
                'type' => 'select',
                'default' => 'admin',
                'options' => [
                    'off' => 'Off',
                    'admin' => 'Administrators only',
                    'public' => 'Public',
                ],
            ],
            'test_mode' => [
                'title' => 'PayTR test mode',
                'type' => 'select',
                'default' => 'inherit',
                'options' => [
                    'inherit' => 'Use official PayTR plugin setting',
                    'yes' => 'Force test mode',
                    'no' => 'Force live mode',
                ],
            ],
            'renewal_mode' => [
                'title' => 'Automatic renewals',
                'type' => 'select',
                'default' => 'manual',
                'options' => [
                    'off' => 'Off',
                    'manual' => 'Manual test only',
                    'live' => 'Live scheduled renewals',
                ],
                'description' =>
                    'Keep this on Manual until the recurring test succeeds.',
            ],
            'server_ipv4' => [
                'title' => 'Server IPv4 fallback',
                'type' => 'text',
                'default' => '72.61.140.3',
                'description' => 'Used only when the buyer IP is not IPv4.',
            ],
        ];
    }

    public function is_available(): bool
    {
        if (! parent::is_available()) {
            return false;
        }

        $config = new Config();

        if ($config->credentialIssues() !== []) {
            return false;
        }

        $rollout = $config->rollout();

        if ($rollout === 'off') {
            return false;
        }

        if (
            $rollout === 'admin'
            && ! current_user_can('manage_woocommerce')
        ) {
            return false;
        }

        return self::managedCart();
    }

    /**
     * @return array{result:string,redirect:string}|array{result:string,messages:string}
     */
    public function process_payment($order_id): array
    {
        $order = wc_get_order((int) $order_id);

        if (! $order instanceof WC_Order) {
            wc_add_notice('The order could not be loaded.', 'error');

            return ['result' => 'failure', 'messages' => 'Order missing'];
        }

        $validation = $this->validateOrder($order);

        if ($validation instanceof WP_Error) {
            wc_add_notice($validation->get_error_message(), 'error');

            return [
                'result' => 'failure',
                'messages' => $validation->get_error_message(),
            ];
        }

        $customer = $this->ensureCustomer($order);

        if ($customer instanceof WP_Error) {
            wc_add_notice($customer->get_error_message(), 'error');

            return [
                'result' => 'failure',
                'messages' => $customer->get_error_message(),
            ];
        }

        $order->set_payment_method($this);
        $order->set_payment_method_title($this->title);
        $order->update_meta_data(
            '_codigle_paytr_direct_auto_renew',
            'yes'
        );
        $order->save();

        return [
            'result' => 'success',
            'redirect' => PaymentPage::url($order),
        ];
    }

    /**
     * @param array<string, WC_Payment_Gateway> $gateways
     * @return array<string, WC_Payment_Gateway>
     */
    public static function filterAvailableGateways(
        array $gateways
    ): array {
        if (! self::managedCart()) {
            return $gateways;
        }

        $direct = $gateways[Config::GATEWAY_ID] ?? null;

        if ($direct instanceof self && $direct->is_available()) {
            unset($gateways['paytr_payment_gateway']);
        }

        return $gateways;
    }

    public static function managedCart(): bool
    {
        if (! function_exists('WC') || WC()->cart === null) {
            return false;
        }

        $items = WC()->cart->get_cart();

        if ($items === []) {
            return false;
        }

        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);

            if (
                $productId < 1
                || (int) get_post_meta(
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
                return false;
            }
        }

        return true;
    }

    private function validateOrder(WC_Order $order): bool|WP_Error
    {
        $currency = strtoupper($order->get_currency());

        if (! in_array(
            $currency,
            ['TRY', 'TL', 'EUR', 'USD', 'GBP', 'RUB'],
            true
        )) {
            return new WP_Error(
                'codigle_paytr_currency_unsupported',
                'This currency is not supported by PayTR Direct.'
            );
        }

        if (sanitize_email($order->get_billing_email()) === '') {
            return new WP_Error(
                'codigle_paytr_email_required',
                'A valid email address is required.'
            );
        }

        if (trim($order->get_formatted_billing_full_name()) === '') {
            return new WP_Error(
                'codigle_paytr_name_required',
                'Billing name is required.'
            );
        }

        if (trim($order->get_billing_address_1()) === '') {
            return new WP_Error(
                'codigle_paytr_address_required',
                'Billing address is required.'
            );
        }

        if (trim($order->get_billing_phone()) === '') {
            return new WP_Error(
                'codigle_paytr_phone_required',
                'A billing phone number is required.'
            );
        }

        if ((float) $order->get_total() <= 0) {
            return new WP_Error(
                'codigle_paytr_amount_invalid',
                'The payment amount must be greater than zero.'
            );
        }

        return true;
    }

    private function ensureCustomer(WC_Order $order): int|WP_Error
    {
        $existingId = $order->get_customer_id();

        if ($existingId > 0) {
            return $existingId;
        }

        $email = sanitize_email($order->get_billing_email());

        if ($email === '') {
            return new WP_Error(
                'codigle_paytr_email_required',
                'A valid email address is required for subscriptions.'
            );
        }

        $user = get_user_by('email', $email);

        if ($user instanceof \WP_User) {
            $userId = (int) $user->ID;
        } else {
            $userId = wc_create_new_customer(
                $email,
                '',
                wp_generate_password(24, true, true),
                [
                    'first_name' => $order->get_billing_first_name(),
                    'last_name' => $order->get_billing_last_name(),
                ]
            );

            if (is_wp_error($userId)) {
                return $userId;
            }
        }

        $order->set_customer_id((int) $userId);
        $order->save();

        if (! is_user_logged_in()) {
            wp_set_current_user((int) $userId);
            wp_set_auth_cookie((int) $userId, true, is_ssl());
        }

        return (int) $userId;
    }
}
