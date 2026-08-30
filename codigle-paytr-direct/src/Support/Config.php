<?php

declare(strict_types=1);

namespace Codigle\PaytrDirect\Support;

final class Config
{
    public const GATEWAY_ID = 'codigle_paytr_direct';

    /**
     * @return array<string, mixed>
     */
    public function officialSettings(): array
    {
        $settings = get_option(
            'woocommerce_paytr_payment_gateway_settings',
            []
        );

        return is_array($settings) ? $settings : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function gatewaySettings(): array
    {
        $settings = get_option(
            'woocommerce_' . self::GATEWAY_ID . '_settings',
            []
        );

        return is_array($settings) ? $settings : [];
    }

    public function merchantId(): string
    {
        return trim(
            (string) (
                $this->officialSettings()['paytr_merchant_id']
                ?? ''
            )
        );
    }

    public function merchantKey(): string
    {
        return (string) (
            $this->officialSettings()['paytr_merchant_key']
            ?? ''
        );
    }

    public function merchantSalt(): string
    {
        return (string) (
            $this->officialSettings()['paytr_merchant_salt']
            ?? ''
        );
    }

    public function testMode(): bool
    {
        $override = (string) (
            $this->gatewaySettings()['test_mode']
            ?? 'inherit'
        );

        if ($override === 'yes') {
            return true;
        }

        if ($override === 'no') {
            return false;
        }

        return (
            $this->officialSettings()['test']
            ?? 'no'
        ) === 'yes';
    }

    public function rollout(): string
    {
        $gateway = (string) (
            $this->gatewaySettings()['rollout']
            ?? ''
        );

        if (in_array($gateway, ['off', 'admin', 'public'], true)) {
            return $gateway;
        }

        $option = (string) get_option(
            'codigle_paytr_direct_rollout',
            'admin'
        );

        return in_array($option, ['off', 'admin', 'public'], true)
            ? $option
            : 'admin';
    }

    public function recurringAuthorized(): bool
    {
        $authorized = (string) get_option(
            'codigle_paytr_direct_recurring_authorized',
            'no'
        ) === 'yes';

        return (bool) apply_filters(
            'codigle_paytr_direct_recurring_authorized',
            $authorized,
            $this
        );
    }

    public function renewalMode(): string
    {
        $gateway = (string) (
            $this->gatewaySettings()['renewal_mode']
            ?? ''
        );

        if (in_array($gateway, ['off', 'manual', 'live'], true)) {
            return $gateway;
        }

        $option = (string) get_option(
            'codigle_paytr_direct_renewal_mode',
            'manual'
        );

        return in_array($option, ['off', 'manual', 'live'], true)
            ? $option
            : 'manual';
    }

    public function serverIpv4(): string
    {
        $value = trim(
            (string) (
                $this->gatewaySettings()['server_ipv4']
                ?? '72.61.140.3'
            )
        );

        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            ? $value
            : '72.61.140.3';
    }

    public function callbackUrl(): string
    {
        // PayTR has one merchant-level callback URL. Reuse the official
        // WooCommerce route and route only CDG merchant order IDs here.
        return home_url('/index.php?wc-api=wc_gateway_paytrcheckout');
    }

    public function paymentPageId(): int
    {
        return (int) get_option(
            'codigle_paytr_direct_payment_page_id',
            0
        );
    }

    /**
     * @return list<string>
     */
    public function credentialIssues(): array
    {
        $issues = [];

        if ($this->merchantId() === '') {
            $issues[] = 'PayTR Merchant ID is missing.';
        }

        if ($this->merchantKey() === '') {
            $issues[] = 'PayTR Merchant Key is missing.';
        }

        if ($this->merchantSalt() === '') {
            $issues[] = 'PayTR Merchant Salt is missing.';
        }

        return $issues;
    }
}
