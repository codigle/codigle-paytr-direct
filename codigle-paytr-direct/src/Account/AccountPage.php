<?php

declare(strict_types=1);

namespace Codigle\PaytrDirect\Account;

use Codigle\PaytrDirect\Database\Repository;

final class AccountPage
{
    public function __construct(
        private readonly Repository $repository
    ) {
    }

    public function register(): void
    {
        add_action(
            'init',
            static function (): void {
                add_rewrite_endpoint(
                    'subscriptions',
                    EP_ROOT | EP_PAGES
                );
            }
        );
        add_filter(
            'woocommerce_account_menu_items',
            static function (array $items): array {
                $logout = $items['customer-logout'] ?? null;
                unset($items['customer-logout']);
                $items['subscriptions'] = 'Subscriptions';

                if ($logout !== null) {
                    $items['customer-logout'] = $logout;
                }

                return $items;
            }
        );
        add_action(
            'woocommerce_account_subscriptions_endpoint',
            [$this, 'render']
        );
    }

    public function render(): void
    {
        $userId = get_current_user_id();
        $subscriptions = $this->repository->subscriptions($userId);
        $cards = $this->repository->cards($userId);

        echo '<div class="cdg-account-subscriptions">';
        echo '<h2>Subscriptions</h2>';

        if ($subscriptions === []) {
            echo '<p>No active Codigle subscriptions yet.</p>';
        } else {
            echo '<div class="cdg-account-list">';

            foreach ($subscriptions as $subscription) {
                $order = wc_get_order(
                    (int) $subscription['initial_order_id']
                );
                $title = $order
                    ? implode(
                        ', ',
                        array_map(
                            static fn ($item): string => $item->get_name(),
                            $order->get_items()
                        )
                    )
                    : 'Codigle subscription';

                printf(
                    '<article><div><strong>%s</strong><span>Status: %s</span></div><div><span>Next renewal</span><strong>%s</strong></div></article>',
                    esc_html($title),
                    esc_html((string) $subscription['status']),
                    esc_html(
                        (string) (
                            $subscription['next_payment_at_utc']
                            ?? '—'
                        )
                    )
                );
            }

            echo '</div>';
        }

        echo '<h2>Saved payment cards</h2>';

        if ($cards === []) {
            echo '<p>No saved card is linked to this Codigle account yet.</p>';
        } else {
            echo '<div class="cdg-account-list">';

            foreach ($cards as $card) {
                printf(
                    '<article><div><strong>%s •••• %s</strong><span>%s</span></div><div><span>Expires</span><strong>%s/%s</strong></div></article>',
                    esc_html(
                        strtoupper(
                            (string) (
                                $card['card_schema']
                                ?: 'CARD'
                            )
                        )
                    ),
                    esc_html((string) $card['last_4']),
                    esc_html((string) $card['bank_name']),
                    esc_html((string) $card['expiry_month']),
                    esc_html((string) $card['expiry_year'])
                );
            }

            echo '</div>';
        }

        echo '</div>';
    }
}
