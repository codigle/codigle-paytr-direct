<?php

declare(strict_types=1);

namespace Codigle\PaytrDirect\Paytr;

use Codigle\PaytrDirect\Database\Repository;
use Codigle\PaytrDirect\Support\Config;
use RuntimeException;
use WP_Error;

final class CapiClient
{
    private const ENDPOINT = 'https://www.paytr.com/odeme/capi/list';

    public function __construct(
        private readonly Config $config,
        private readonly Repository $repository
    ) {
    }

    /**
     * @return list<array<string, mixed>>|WP_Error
     */
    public function refreshForUser(int $userId): array|WP_Error
    {
        try {
            $utoken = $this->repository->userToken($userId);
        } catch (RuntimeException $error) {
            return new WP_Error(
                'codigle_paytr_token_unavailable',
                'Saved card access could not be opened securely.'
            );
        }

        if ($utoken === '') {
            return [];
        }

        $token = base64_encode(
            hash_hmac(
                'sha256',
                $utoken . $this->config->merchantSalt(),
                $this->config->merchantKey(),
                true
            )
        );

        $decoded = $this->request(
            [
                'merchant_id' => $this->config->merchantId(),
                'utoken' => $utoken,
                'paytr_token' => $token,
            ]
        );

        if ($decoded instanceof WP_Error) {
            return $decoded;
        }

        if (($decoded['status'] ?? '') === 'error') {
            return new WP_Error(
                'codigle_paytr_capi_error',
                sanitize_text_field(
                    (string) (
                        $decoded['err_msg']
                        ?? 'PayTR card list failed.'
                    )
                )
            );
        }

        $cards = array_is_list($decoded)
            ? array_values(
                array_filter(
                    $decoded,
                    static fn ($item): bool => is_array($item)
                )
            )
            : [];

        $this->repository->syncCards($userId, $cards);
        $this->repository->attachDefaultCardToOpenSubscriptions($userId);

        return $cards;
    }

    /**
     * @param array<string, string> $body
     * @return array<mixed>|WP_Error
     */
    private function request(array $body): array|WP_Error
    {
        if (! function_exists('curl_init')) {
            return new WP_Error(
                'codigle_paytr_capi_curl_missing',
                'The server cURL extension is required for PayTR card access.'
            );
        }

        $encodedBody = http_build_query(
            $body,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
        $delays = [0, 2, 5, 10];
        $lastHttpCode = 0;
        $lastCurlErrno = 0;

        foreach ($delays as $delay) {
            if ($delay > 0) {
                sleep($delay);
            }

            $handle = curl_init();

            if ($handle === false) {
                return new WP_Error(
                    'codigle_paytr_capi_curl_init_failed',
                    'PayTR card connection could not be initialized.'
                );
            }

            curl_setopt_array(
                $handle,
                [
                    CURLOPT_URL => self::ENDPOINT,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $encodedBody,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/x-www-form-urlencoded',
                    ],
                    CURLOPT_FRESH_CONNECT => true,
                    CURLOPT_TIMEOUT => 25,
                    CURLOPT_CONNECTTIMEOUT => 10,
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

            if (
                is_string($rawBody)
                && $lastCurlErrno === 0
            ) {
                $decoded = json_decode($rawBody, true);

                if (
                    $lastHttpCode === 200
                    && is_array($decoded)
                ) {
                    return $decoded;
                }

                if (
                    is_array($decoded)
                    && ($decoded['status'] ?? '') === 'error'
                ) {
                    return new WP_Error(
                        'codigle_paytr_capi_error',
                        sanitize_text_field(
                            (string) (
                                $decoded['err_msg']
                                ?? 'PayTR card list failed.'
                            )
                        ),
                        ['http_code' => $lastHttpCode]
                    );
                }
            }

            if (! $this->isTransient(
                $lastHttpCode,
                $lastCurlErrno,
                $rawBody
            )) {
                break;
            }
        }

        if ($lastCurlErrno !== 0) {
            return new WP_Error(
                'codigle_paytr_capi_transport_error',
                'PayTR card service could not be reached.',
                ['curl_errno' => $lastCurlErrno]
            );
        }

        return new WP_Error(
            'codigle_paytr_capi_invalid_response',
            sprintf(
                'PayTR card list returned an invalid response (HTTP %d).',
                $lastHttpCode
            ),
            ['http_code' => $lastHttpCode]
        );
    }

    private function isTransient(
        int $httpCode,
        int $curlErrno,
        mixed $rawBody
    ): bool {
        if ($curlErrno !== 0 || ! is_string($rawBody)) {
            return true;
        }

        if (
            in_array($httpCode, [0, 408, 425, 429, 522], true)
            || $httpCode >= 500
        ) {
            return true;
        }

        return json_decode($rawBody, true) === null
            && json_last_error() !== JSON_ERROR_NONE;
    }
}
