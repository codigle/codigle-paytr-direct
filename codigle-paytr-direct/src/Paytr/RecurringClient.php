<?php

declare(strict_types=1);

namespace Codigle\PaytrDirect\Paytr;

use Codigle\PaytrDirect\Support\Config;
use WC_Order;
use WP_Error;

final class RecurringClient
{
    private const ENDPOINT = 'https://www.paytr.com/odeme';

    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * @param array<string, mixed> $attempt
     * @param array<string, mixed> $card
     * @return array<string, string>
     */
    public function fields(
        WC_Order $order,
        array $attempt,
        string $utoken,
        string $ctoken,
        array $card,
        bool $testFailure = false
    ): array {
        $merchantId = $this->config->merchantId();
        $userIp = $this->config->serverIpv4();
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
        $non3d = '1';
        $hashString = $merchantId
            . $userIp
            . $merchantOid
            . $email
            . $paymentAmount
            . $paymentType
            . $installmentCount
            . $currency
            . $testMode
            . $non3d;
        $token = base64_encode(
            hash_hmac(
                'sha256',
                $hashString . $this->config->merchantSalt(),
                $this->config->merchantKey(),
                true
            )
        );

        return [
            'merchant_id' => $merchantId,
            'user_ip' => $userIp,
            'merchant_oid' => $merchantOid,
            'email' => $email,
            'payment_type' => $paymentType,
            'payment_amount' => $paymentAmount,
            'installment_count' => $installmentCount,
            'card_type' => '',
            'currency' => $currency,
            'client_lang' => str_starts_with(
                strtolower(determine_locale()),
                'tr'
            ) ? 'tr' : 'en',
            'test_mode' => $testMode,
            'non_3d' => $non3d,
            'non3d_test_failed' => (
                $testFailure
                && $this->config->testMode()
            ) ? '1' : '0',
            'merchant_ok_url' => $this->accountUrl(),
            'merchant_fail_url' => $this->accountUrl(),
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
            'paytr_token' => $token,
            'utoken' => $utoken,
            'ctoken' => $ctoken,
            'recurring_payment' => '1',
        ];
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>|WP_Error
     */
    public function send(array $fields): array|WP_Error
    {
        if (! function_exists('curl_init')) {
            return new WP_Error(
                'codigle_paytr_recurring_curl_missing',
                'The server cURL extension is required.'
            );
        }

        $handle = curl_init();

        if ($handle === false) {
            return new WP_Error(
                'codigle_paytr_recurring_curl_init_failed',
                'Recurring payment connection could not be initialized.'
            );
        }

        // PayTR's Direct API expects a standard URL-encoded form body.
        // Passing an array to CURLOPT_POSTFIELDS makes cURL send multipart/form-data,
        // which is unnecessary here and has produced intermittent Cloudflare 522
        // responses from this server. Do not retry an ambiguous payment POST: the
        // same merchant_oid is reconciled through the authenticated Status API.
        $body = http_build_query(
            $fields,
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        curl_setopt_array(
            $handle,
            [
                CURLOPT_URL => self::ENDPOINT,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Expect:',
                ],
                CURLOPT_FRESH_CONNECT => true,
                CURLOPT_FORBID_REUSE => true,
                CURLOPT_TIMEOUT => 35,
                CURLOPT_CONNECTTIMEOUT => 12,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ]
        );

        $rawBody = curl_exec($handle);
        $curlErrno = curl_errno($handle);
        $httpCode = (int) curl_getinfo(
            $handle,
            CURLINFO_HTTP_CODE
        );
        curl_close($handle);

        if (! is_string($rawBody) || $curlErrno !== 0) {
            return new WP_Error(
                'codigle_paytr_recurring_transport_unknown',
                'The recurring request result is unknown; wait for callback.',
                [
                    'ambiguous' => true,
                    'curl_errno' => $curlErrno,
                    'http_code' => $httpCode,
                ]
            );
        }

        $decoded = json_decode($rawBody, true);

        if ($httpCode !== 200 || ! is_array($decoded)) {
            return new WP_Error(
                'codigle_paytr_recurring_transport_unknown',
                sprintf(
                    'The recurring request result is unknown (HTTP %d); wait for callback.',
                    $httpCode
                ),
                [
                    'ambiguous' => true,
                    'http_code' => $httpCode,
                    'body_sha256_prefix' => substr(
                        hash('sha256', $rawBody),
                        0,
                        16
                    ),
                ]
            );
        }

        $status = sanitize_key(
            (string) ($decoded['status'] ?? '')
        );

        if (! in_array(
            $status,
            ['failed', 'wait_callback', 'success'],
            true
        )) {
            return new WP_Error(
                'codigle_paytr_recurring_invalid_response',
                'PayTR returned an unknown recurring status.',
                [
                    'http_code' => $httpCode,
                    'response_keys' => array_slice(
                        array_map('strval', array_keys($decoded)),
                        0,
                        20
                    ),
                ]
            );
        }

        $message = $this->providerMessage($decoded);

        if ($status === 'failed' && $message === '') {
            $message = (
                'PayTR rejected the recurring payment '
                . 'without returning a reason.'
            );
        }

        return [
            'status' => $status,
            'msg' => $message,
            'try_again' => filter_var(
                $decoded['try_again'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            ),
            'http_code' => $httpCode,
            'response_keys' => array_slice(
                array_map('strval', array_keys($decoded)),
                0,
                30
            ),
            'safe_response' => $this->safeProviderResponse($decoded),
            'body_sha256_prefix' => substr(
                hash('sha256', $rawBody),
                0,
                16
            ),
        ];
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function providerMessage(array $decoded): string
    {
        $keys = [
            'msg',
            'err_msg',
            'message',
            'reason',
            'failed_reason_msg',
            'error_message',
        ];

        foreach ($keys as $key) {
            $value = trim(
                sanitize_text_field(
                    (string) ($decoded[$key] ?? '')
                )
            );

            if ($value !== '') {
                return substr($value, 0, 500);
            }
        }

        return '';
    }

    /**
     * Keep only non-sensitive scalar provider fields.
     *
     * @param array<string, mixed> $decoded
     * @return array<string, string|int|bool|float|null>
     */
    private function safeProviderResponse(array $decoded): array
    {
        $allowed = [
            'status',
            'msg',
            'err_msg',
            'message',
            'reason',
            'failed_reason_code',
            'failed_reason_msg',
            'try_again',
            'return_code',
            'return_message',
            'code',
            'error',
            'error_code',
            'error_message',
        ];
        $safe = [];

        foreach ($allowed as $key) {
            if (
                ! array_key_exists($key, $decoded)
                || ! is_scalar($decoded[$key])
            ) {
                continue;
            }

            if (is_string($decoded[$key])) {
                $safe[$key] = substr(
                    sanitize_text_field($decoded[$key]),
                    0,
                    500
                );

                continue;
            }

            $safe[$key] = $decoded[$key];
        }

        return $safe;
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
                    (float) $item->get_total(),
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

    private function accountUrl(): string
    {
        return wc_get_account_endpoint_url('subscriptions');
    }
}
