<?php

declare(strict_types=1);

namespace Codigle\PaytrDirect;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Codigle\PaytrDirect\Account\AccountPage;
use Codigle\PaytrDirect\Account\EmailVerification;
use Codigle\PaytrDirect\Checkout\Blocks;
use Codigle\PaytrDirect\Checkout\CallbackController;
use Codigle\PaytrDirect\Checkout\Gateway;
use Codigle\PaytrDirect\Checkout\LegalSnapshot;
use Codigle\PaytrDirect\Checkout\ModalCheckout;
use Codigle\PaytrDirect\Checkout\PaymentPage;
use Codigle\PaytrDirect\Cli\Command;
use Codigle\PaytrDirect\Database\Repository;
use Codigle\PaytrDirect\Database\Schema;
use Codigle\PaytrDirect\Paytr\CapiClient;
use Codigle\PaytrDirect\Paytr\RecurringClient;
use Codigle\PaytrDirect\Paytr\StatusClient;
use Codigle\PaytrDirect\Paytr\TokenService;
use Codigle\PaytrDirect\Rest\SubscriptionController;
use Codigle\PaytrDirect\Subscription\ManagementService;
use Codigle\PaytrDirect\Subscription\RenewalScheduler;
use Codigle\PaytrDirect\Subscription\RenewalService;
use Codigle\PaytrDirect\Subscription\SubscriptionService;
use Codigle\PaytrDirect\Subscription\UpgradeQuoteService;
use Codigle\PaytrDirect\Subscription\UpgradeService;
use Codigle\PaytrDirect\Support\ClientIp;
use Codigle\PaytrDirect\Support\Config;
use Codigle\PaytrDirect\Support\Crypto;

final class Plugin
{
    private static bool $booted = false;

    public static function activate(): void
    {
        if (PHP_VERSION_ID < 80100) {
            wp_die('Codigle PayTR Direct requires PHP 8.1 or newer.');
        }

        if (! class_exists('WooCommerce')) {
            wp_die('WooCommerce must be active.');
        }

        $config = new Config();

        if ($config->credentialIssues() !== []) {
            wp_die(
                esc_html(implode(' ', $config->credentialIssues())),
                'Codigle PayTR Direct activation failed',
                ['back_link' => true]
            );
        }

        Schema::install();
        PaymentPage::ensurePage();
        add_rewrite_endpoint('subscriptions', EP_ROOT | EP_PAGES);

        add_option(
            'codigle_paytr_direct_rollout',
            'admin',
            '',
            false
        );
        add_option(
            'codigle_paytr_direct_renewal_mode',
            'manual',
            '',
            false
        );
        add_option(
            'codigle_paytr_direct_recurring_authorized',
            'no',
            '',
            false
        );

        flush_rewrite_rules(false);
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules(false);
    }

    public static function boot(): void
    {
        if (self::$booted || ! class_exists('WooCommerce')) {
            return;
        }

        self::$booted = true;

        if (
            (string) get_option(
                'codigle_paytr_direct_schema_version',
                ''
            ) !== Schema::VERSION
        ) {
            Schema::install();
        }

        add_option(
            'codigle_paytr_direct_renewal_mode',
            'manual',
            '',
            false
        );
        add_option(
            'codigle_paytr_direct_recurring_authorized',
            'no',
            '',
            false
        );

        $config = new Config();
        $crypto = new Crypto();
        $repository = new Repository($crypto);
        $clientIp = new ClientIp($config);
        $tokenService = new TokenService($config, $clientIp);
        $capi = new CapiClient($config, $repository);
        $recurring = new RecurringClient($config);
        $status = new StatusClient($config);
        $subscriptions = new SubscriptionService($repository);
        $renewals = new RenewalService(
            $config,
            $repository,
            $capi,
            $recurring,
            $subscriptions
        );
        $scheduler = new RenewalScheduler(
            $config,
            $repository,
            $renewals,
            $status,
            $subscriptions
        );
        $paymentPage = new PaymentPage(
            $config,
            $repository,
            $tokenService,
            $capi
        );
        $emailVerification = new EmailVerification($config);
        $legalSnapshot = new LegalSnapshot();
        $upgradeQuotes = new UpgradeQuoteService($repository);
        $upgradeService = new UpgradeService(
            $config,
            $repository,
            $capi,
            $recurring,
            $upgradeQuotes
        );
        $management = new ManagementService(
            $config,
            $repository,
            $capi,
            $renewals,
            $upgradeQuotes,
            $upgradeService,
            $emailVerification,
            $clientIp,
            $legalSnapshot
        );
        $modalCheckout = new ModalCheckout(
            $config,
            $repository,
            $tokenService,
            $clientIp,
            $legalSnapshot,
            $emailVerification
        );

        add_filter(
            'codigle_portal_recurring_authorization_pending',
            static fn (bool $pending): bool => ! $config->recurringAuthorized()
        );

        add_filter(
            'woocommerce_payment_gateways',
            static function (array $gateways): array {
                $gateways[] = Gateway::class;

                return $gateways;
            }
        );

        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            static function (PaymentMethodRegistry $registry): void {
                $registry->register(new Blocks());
            }
        );

        add_filter(
            'woocommerce_available_payment_gateways',
            [Gateway::class, 'filterAvailableGateways'],
            50
        );

        $paymentPage->register();
        $emailVerification->register();
        $modalCheckout->register();

        (new CallbackController(
            $config,
            $repository,
            $subscriptions,
            $capi
        ))->register();
        (new SubscriptionController(
            $repository,
            $management,
            $capi
        ))->register();

        (new AccountPage($repository))->register();
        $scheduler->register();

        add_action(
            'codigle_paytr_direct_refresh_cards',
            static function (
                int $userId,
                int $attempt = 1
            ) use ($capi): void {
                $result = $capi->refreshForUser($userId);

                if (! is_wp_error($result)) {
                    return;
                }

                if ($attempt < 3) {
                    $nextAttempt = $attempt + 1;
                    $delay = $attempt === 1 ? 60 : 300;
                    $args = [$userId, $nextAttempt];

                    if (function_exists('as_schedule_single_action')) {
                        as_schedule_single_action(
                            time() + $delay,
                            'codigle_paytr_direct_refresh_cards',
                            $args,
                            'codigle-paytr-direct'
                        );

                        return;
                    }

                    wp_schedule_single_event(
                        time() + $delay,
                        'codigle_paytr_direct_refresh_cards',
                        $args
                    );

                    return;
                }

                error_log(
                    'CODIGLE_PAYTR_CAPI_REFRESH_FAILED='
                    . sanitize_key($result->get_error_code())
                );
            },
            10,
            2
        );

        add_action(
            'admin_notices',
            static function () use ($config): void {
                self::adminNotices($config);
            }
        );

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command(
                'codigle-paytr-direct',
                new Command(
                    $config,
                    $repository,
                    $capi,
                    $renewals,
                    $scheduler,
                    $status
                )
            );
        }
    }

    private static function adminNotices(Config $config): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        $issues = $config->credentialIssues();

        if ($issues !== []) {
            printf(
                '<div class="notice notice-error"><p><strong>Codigle PayTR Direct:</strong> %s</p></div>',
                esc_html(implode(' ', $issues))
            );

            return;
        }

        printf(
            '<div class="notice notice-warning is-dismissible"><p><strong>Codigle PayTR Direct:</strong> Callback <code>%s</code>. Checkout rollout: <strong>%s</strong>. Renewal mode: <strong>%s</strong>. Recurring authorization: <strong>%s</strong>.</p></div>',
            esc_url($config->callbackUrl()),
            esc_html($config->rollout()),
            esc_html($config->renewalMode()),
            $config->recurringAuthorized() ? 'confirmed' : 'pending'
        );
    }
}
