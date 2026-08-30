<?php

declare(strict_types=1);

namespace Codigle\PaytrDirect\Paytr;

use Codigle\PaytrDirect\Support\ClientIp;
use Codigle\PaytrDirect\Support\Config;
use WC_Order;

final class TokenService
{
    public function __construct(
        private readonly Config $config,
        private readonly ClientIp $clientIp
    ) {
    }

    /**
     * @param array<string, mixed> $attempt
     * @return array<string, string>
     */
    public function baseFields(
        WC_Order $order,
        array $attempt
    ): array {
        $merchantId = $this->config->merchantId();
        $userIp = $this->clientIp->value();
        $merchantOid = (string) $attempt['merchant_oid'];
        $email = substr(
            sanitize_email($order->get_billing_email()),
            0,
            100
        );
        $paymentAmount = number_format(
            (float) $order->get_total(),
            2,
            '.',
            ''
        );
        $paymentType = 'card';
        $installmentCount = '0';
        $currency = strtoupper($order->get_currency());
        $currency = $currency === 'TRY' ? 'TL' : $currency;
        $testMode = $this->config->testMode() ? '1' : '0';
        $non3d = '0';

        $token = $this->createToken(
            [
                'merchant_id' => $merchantId,
                'user_ip' => $userIp,
                'merchant_oid' => $merchantOid,
                'email' => $email,
                'payment_amount' => $paymentAmount,
                'payment_type' => $paymentType,
                'installment_count' => $installmentCount,
                'currency' => $currency,
                'test_mode' => $testMode,
                'non_3d' => $non3d,
            ]
        );

        return [
            'merchant_id' => $merchantId,
            'user_ip' => $userIp,
            'merchant_oid' => $merchantOid,
            'email' => $email,
            'payment_type' => $paymentType,
            'payment_amount' => $paymentAmount,
            'currency' => $currency,
            'test_mode' => $testMode,
            'non_3d' => $non3d,
            'merchant_ok_url' => $this->returnUrl($order, 'success'),
            'merchant_fail_url' => $this->returnUrl($order, 'failed'),
            'user_name' => substr(
                trim(
                    $order->get_billing_first_name()
                    . ' '
                    . $order->get_billing_last_name()
                ),
                0,
                60
            ),
            'user_address' => substr(
                $this->address($order),
                0,
                400
            ),
            'user_phone' => substr(
                preg_replace(
                    '/[^0-9+]/',
                    '',
                    $order->get_billing_phone()
                ) ?? '',
                0,
                20
            ),
            'user_basket' => (string) wp_json_encode(
                $this->basket($order),
                JSON_UNESCAPED_UNICODE
            ),
            'debug_on' => (
                $this->config->testMode()
                || $this->config->rollout() === 'admin'
            ) ? '1' : '0',
            'client_lang' => str_starts_with(
                strtolower(determine_locale()),
                'tr'
            ) ? 'tr' : 'en',
            'paytr_token' => $token,
            'non3d_test_failed' => '0',
            'installment_count' => $installmentCount,
            'card_type' => '',
        ];
    }

    /**
     * @param array<string, string> $fields
     */
    public function verifyFields(array $fields): bool
    {
        $required = [
            'merchant_id',
            'user_ip',
            'merchant_oid',
            'email',
            'payment_amount',
            'payment_type',
            'installment_count',
            'currency',
            'test_mode',
            'non_3d',
            'paytr_token',
        ];

        foreach ($required as $field) {
            if (
                ! array_key_exists($field, $fields)
                || (string) $fields[$field] === ''
            ) {
                return false;
            }
        }

        $decoded = base64_decode(
            (string) $fields['paytr_token'],
            true
        );

        if (! is_string($decoded) || strlen($decoded) !== 32) {
            return false;
        }

        return hash_equals(
            $this->createToken($fields),
            (string) $fields['paytr_token']
        );
    }

    /**
     * Exact field order from PayTR Direct API documentation.
     *
     * @param array<string, string> $fields
     */
    private function createToken(array $fields): string
    {
        $hashString = (string) ($fields['merchant_id'] ?? '')
            . (string) ($fields['user_ip'] ?? '')
            . (string) ($fields['merchant_oid'] ?? '')
            . (string) ($fields['email'] ?? '')
            . (string) ($fields['payment_amount'] ?? '')
            . (string) ($fields['payment_type'] ?? '')
            . (string) ($fields['installment_count'] ?? '')
            . (string) ($fields['currency'] ?? '')
            . (string) ($fields['test_mode'] ?? '')
            . (string) ($fields['non_3d'] ?? '');

        return base64_encode(
            hash_hmac(
                'sha256',
                $hashString . $this->config->merchantSalt(),
                $this->config->merchantKey(),
                true
            )
        );
    }

    public function callbackHash(
        string $merchantOid,
        string $status,
        string $totalAmount
    ): string {
        return base64_encode(
            hash_hmac(
                'sha256',
                $merchantOid
                    . $this->config->merchantSalt()
                    . $status
                    . $totalAmount,
                $this->config->merchantKey(),
                true
            )
        );
    }

    /**
     * @return list<array{0:string,1:string,2:int}>
     */
    private function basket(WC_Order $order): array
    {
        $basket = [];

        foreach ($order->get_items() as $item) {
            $basket[] = [
                wp_strip_all_tags($item->get_name()),
                number_format(
                    (float) $order->get_item_subtotal(
                        $item,
                        true,
                        true
                    ),
                    2,
                    '.',
                    ''
                ),
                max(1, (int) $item->get_quantity()),
            ];
        }

        return $basket;
    }

    private function address(WC_Order $order): string
    {
        return trim(
            implode(
                ' ',
                array_filter(
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
        );
    }

    private function returnUrl(
        WC_Order $order,
        string $result
    ): string {
        $pageId = (int) get_option(
            'codigle_paytr_direct_payment_page_id',
            0
        );
        $url = $pageId > 0
            ? get_permalink($pageId)
            : home_url('/secure-payment/');

        return add_query_arg(
            [
                'order_id' => $order->get_id(),
                'key' => $order->get_order_key(),
                'paytr_result' => $result,
            ],
            (string) $url
        );
    }
}
