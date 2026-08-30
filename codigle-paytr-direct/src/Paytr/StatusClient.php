<?php

declare(strict_types=1);

namespace Codigle\PaytrDirect\Paytr;

use Codigle\PaytrDirect\Support\Config;
use WP_Error;

final class StatusClient
{
    private const ENDPOINT = 'https://www.paytr.com/odeme/durum-sorgu';

    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function query(string $merchantOid): array|WP_Error
    {
        if (! function_exists('curl_init')) {
            return new WP_Error(
                'codigle_paytr_status_curl_missing',
                'The server cURL extension is required.'
            );
        }

        $token = base64_encode(
            hash_hmac(
                'sha256',
                $this->config->merchantId()
                    . $merchantOid
                    . $this->config->merchantSalt(),
                $this->config->merchantKey(),
                true
            )
        );
        $body = http_build_query(
            [
                'merchant_id' => $this->config->merchantId(),
                'merchant_oid' => $merchantOid,
                'paytr_token' => $token,
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );
        $delays = [0, 2, 5, 10];
        $lastHttpCode = 0;
        $lastCurlErrno = 0;
        $lastBody = '';

        foreach ($delays as $delay) {
            if ($delay > 0) {
                sleep($delay);
            }

            $handle = curl_init();

            if ($handle === false) {
                return new WP_Error(
                    'codigle_paytr_status_curl_init_failed',
                    'Status inquiry could not be initialized.'
                );
            }

            curl_setopt_array(
                $handle,
                [
                    CURLOPT_URL => self::ENDPOINT,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/x-www-form-urlencoded',
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
            $lastCurlErrno = curl_errno($handle);
            $lastHttpCode = (int) curl_getinfo(
                $handle,
                CURLINFO_HTTP_CODE
            );
            curl_close($handle);
            $lastBody = is_string($rawBody) ? $rawBody : '';

            if (
                is_string($rawBody)
                && $lastCurlErrno === 0
                && $lastHttpCode === 200
            ) {
                $decoded = json_decode($rawBody, true);

                if (! is_array($decoded)) {
                    if ($delay !== end($delays)) {
                        continue;
                    }

                    return new WP_Error(
                        'codigle_paytr_status_invalid_response',
                        'PayTR status inquiry returned invalid JSON.'
                    );
                }

                if (($decoded['status'] ?? '') !== 'success') {
                    return new WP_Error(
                        'codigle_paytr_status_not_found',
                        substr(
                            sanitize_text_field(
                                (string) (
                                    $decoded['err_msg']
                                    ?? 'Payment status was not available.'
                                )
                            ),
                            0,
                            500
                        ),
                        [
                            'err_no' => sanitize_text_field(
                                (string) ($decoded['err_no'] ?? '')
                            ),
                        ]
                    );
                }

                return [
                    'status' => 'success',
                    'payment_amount' => sanitize_text_field(
                        (string) ($decoded['payment_amount'] ?? '')
                    ),
                    'payment_total' => sanitize_text_field(
                        (string) ($decoded['payment_total'] ?? '')
                    ),
                    'currency' => sanitize_text_field(
                        (string) ($decoded['currency'] ?? '')
                    ),
                    'payment_date' => sanitize_text_field(
                        (string) ($decoded['payment_date'] ?? '')
                    ),
                ];
            }

            if (! $this->isTransient($lastHttpCode, $lastCurlErrno)) {
                break;
            }
        }

        return new WP_Error(
            'codigle_paytr_status_transport_error',
            sprintf(
                'PayTR status inquiry failed after IPv4 retries (HTTP %d).',
                $lastHttpCode
            ),
            [
                'http_code' => $lastHttpCode,
                'curl_errno' => $lastCurlErrno,
                'body_sha256_prefix' => $lastBody !== ''
                    ? substr(hash('sha256', $lastBody), 0, 16)
                    : '',
            ]
        );
    }

    private function isTransient(int $httpCode, int $curlErrno): bool
    {
        if ($curlErrno !== 0) {
            return true;
        }

        return in_array(
            $httpCode,
            [0, 408, 425, 429, 500, 502, 503, 504, 520, 521, 522, 523, 524],
            true
        );
    }

}
