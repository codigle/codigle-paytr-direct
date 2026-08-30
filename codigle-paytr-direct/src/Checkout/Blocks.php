<?php

declare(strict_types=1);

namespace Codigle\PaytrDirect\Checkout;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Codigle\PaytrDirect\Support\Config;

final class Blocks extends AbstractPaymentMethodType
{
    protected $name = Config::GATEWAY_ID;

    public function initialize(): void
    {
        $this->settings = get_option(
            'woocommerce_' . Config::GATEWAY_ID . '_settings',
            []
        );
    }

    public function is_active(): bool
    {
        $config = new Config();
        $enabled = (string) ($this->settings['enabled'] ?? 'yes');

        if ($enabled !== 'yes' || $config->credentialIssues() !== []) {
            return false;
        }

        $rollout = $config->rollout();

        if ($rollout === 'off') {
            return false;
        }

        return $rollout !== 'admin'
            || current_user_can('manage_woocommerce');
    }

    /**
     * @return list<string>
     */
    public function get_payment_method_script_handles(): array
    {
        wp_register_script(
            'codigle-paytr-direct-blocks',
            CODIGLE_PAYTR_DIRECT_URL . 'assets/blocks.js',
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
            ],
            CODIGLE_PAYTR_DIRECT_VERSION,
            true
        );

        return ['codigle-paytr-direct-blocks'];
    }

    /**
     * @return array<string, mixed>
     */
    public function get_payment_method_data(): array
    {
        $gateway = new Gateway();

        return [
            'title' => $gateway->get_title(),
            'description' => $gateway->get_description(),
            'supports' => $gateway->supports,
        ];
    }
}
