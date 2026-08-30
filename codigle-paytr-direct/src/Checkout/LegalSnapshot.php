<?php

declare(strict_types=1);

namespace Codigle\PaytrDirect\Checkout;

use WP_Post;

final class LegalSnapshot
{
    /**
     * @return array<string, array<string, int|string>>
     */
    public function documents(): array
    {
        $documents = [];

        foreach ($this->slugs() as $key => $slug) {
            $post = get_page_by_path($slug);

            if (
                ! $post instanceof WP_Post
                || get_post_status($post) !== 'publish'
                || trim((string) $post->post_content) === ''
            ) {
                $documents[$key] = [
                    'id' => 0,
                    'slug' => $slug,
                    'title' => $this->fallbackTitle($key),
                    'url' => home_url('/' . $slug . '/'),
                    'modified_gmt' => '',
                    'sha256' => '',
                    'available' => 0,
                ];

                continue;
            }

            $documents[$key] = [
                'id' => (int) $post->ID,
                'slug' => $slug,
                'title' => get_the_title($post),
                'url' => (string) get_permalink($post),
                'modified_gmt' => (string) $post->post_modified_gmt,
                'sha256' => hash(
                    'sha256',
                    (string) $post->post_content
                ),
                'available' => 1,
            ];
        }

        return $documents;
    }

    /**
     * @return array<string, string>
     */
    public function consentTexts(): array
    {
        return [
            'terms' => (
                'I accept the Terms and Conditions, Refund Policy and '
                . 'Subscription Policy that apply to this purchase.'
            ),
            'renewal' => (
                'I authorize Codigle to renew this subscription for the '
                . 'selected billing period and charge the saved payment '
                . 'method until I cancel automatic renewal.'
            ),
            'marketing' => (
                'I would like to receive optional product and promotional '
                . 'emails from Codigle.'
            ),
        ];
    }

    public function requiredDocumentsAvailable(): bool
    {
        $documents = $this->documents();

        foreach (['terms', 'refund', 'subscription', 'privacy'] as $key) {
            if ((int) ($documents[$key]['available'] ?? 0) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    private function slugs(): array
    {
        return [
            'terms' => 'terms-and-conditions',
            'refund' => 'refund-policy',
            'subscription' => 'subscription-policy',
            'privacy' => 'privacy-policy',
        ];
    }

    private function fallbackTitle(string $key): string
    {
        return match ($key) {
            'terms' => 'Terms and Conditions',
            'refund' => 'Refund Policy',
            'subscription' => 'Subscription Policy',
            'privacy' => 'Privacy Policy',
            default => 'Legal document',
        };
    }
}
