<?php

declare(strict_types=1);

namespace Codigle\PaytrDirect\Support;

final class ClientIp
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * PayTR Direct API documents user_ip as IPv4.
     *
     * Use the real customer IPv4 when one is available. When the customer
     * reaches Codigle only over IPv6, sign and submit the configured server
     * IPv4 instead. The original IPv6 remains available in request/server
     * logs and is not sent as the PayTR user_ip value.
     */
    public function value(): string
    {
        $actual = $this->actualClient();

        if (
            $actual !== null
            && filter_var(
                $actual['value'],
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4
            )
        ) {
            return $actual['value'];
        }

        return $this->config->serverIpv4();
    }

    /**
     * @return array{
     *   value:string,
     *   source:string,
     *   version:int,
     *   original_value:string,
     *   original_source:string,
     *   original_version:int,
     *   fallback_reason:string
     * }
     */
    public function details(): array
    {
        $actual = $this->actualClient();
        $signed = $this->value();

        if (
            $actual !== null
            && hash_equals($signed, $actual['value'])
        ) {
            return [
                'value' => $signed,
                'source' => $actual['source'],
                'version' => 4,
                'original_value' => $actual['value'],
                'original_source' => $actual['source'],
                'original_version' => 4,
                'fallback_reason' => '',
            ];
        }

        $originalValue = $actual['value'] ?? '';
        $originalVersion = 0;

        if ($originalValue !== '') {
            $originalVersion = filter_var(
                $originalValue,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV6
            ) ? 6 : 4;
        }

        return [
            'value' => $signed,
            'source' => 'server_ipv4_fallback',
            'version' => 4,
            'original_value' => $originalValue,
            'original_source' => $actual['source'] ?? '',
            'original_version' => $originalVersion,
            'fallback_reason' => (
                $originalVersion === 6
                    ? 'paytr_direct_ipv4_contract'
                    : 'client_ip_unavailable'
            ),
        ];
    }

    /**
     * @return array{source:string,value:string}|null
     */
    private function actualClient(): ?array
    {
        foreach ($this->candidates() as $candidate) {
            $value = trim((string) $candidate['value']);

            if (
                $value !== ''
                && strlen($value) <= 39
                && filter_var($value, FILTER_VALIDATE_IP)
            ) {
                return [
                    'source' => (string) $candidate['source'],
                    'value' => $value,
                ];
            }
        }

        return null;
    }

    /**
     * @return list<array{source:string,value:string}>
     */
    private function candidates(): array
    {
        return [
            [
                'source' => 'cf_connecting_ip',
                'value' => (string) (
                    $_SERVER['HTTP_CF_CONNECTING_IP']
                    ?? ''
                ),
            ],
            [
                'source' => 'x_forwarded_for',
                'value' => $this->firstForwarded(
                    (string) (
                        $_SERVER['HTTP_X_FORWARDED_FOR']
                        ?? ''
                    )
                ),
            ],
            [
                'source' => 'client_ip',
                'value' => (string) (
                    $_SERVER['HTTP_CLIENT_IP']
                    ?? ''
                ),
            ],
            [
                'source' => 'remote_addr',
                'value' => (string) (
                    $_SERVER['REMOTE_ADDR']
                    ?? ''
                ),
            ],
        ];
    }

    private function firstForwarded(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return trim(explode(',', $value)[0] ?? '');
    }
}
