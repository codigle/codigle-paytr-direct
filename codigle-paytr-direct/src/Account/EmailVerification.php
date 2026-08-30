<?php

declare(strict_types=1);

namespace Codigle\PaytrDirect\Account;

use Codigle\PaytrDirect\Support\Config;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class EmailVerification
{
    private const VERIFIED_EMAIL_META = '_codigle_verified_email';
    private const VERIFIED_AT_META = '_codigle_email_verified_at_utc';
    private const TOKEN_HASH_META = '_codigle_email_verify_token_hash';
    private const TOKEN_EXPIRES_META = '_codigle_email_verify_expires_utc';

    public function __construct(
        private readonly Config $config
    ) {
    }

    public function register(): void
    {
        add_action(
            'rest_api_init',
            function (): void {
                register_rest_route(
                    'codigle-paytr-direct/v1',
                    '/email/send-verification',
                    [
                        'methods' => 'POST',
                        'callback' => [$this, 'sendRoute'],
                        'permission_callback' => static fn (): bool => (
                            is_user_logged_in()
                        ),
                    ]
                );
            }
        );

        add_action(
            'template_redirect',
            [$this, 'handleVerificationLink'],
            1
        );
    }

    public function isVerified(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }

        // Admin-only rollout is a controlled integration environment. Public
        // rollout requires explicit verification for every customer account.
        if (
            $this->config->rollout() === 'admin'
            && user_can($userId, 'manage_woocommerce')
        ) {
            return true;
        }

        $user = get_userdata($userId);

        if (! $user) {
            return false;
        }

        $verifiedEmail = strtolower(
            trim(
                (string) get_user_meta(
                    $userId,
                    self::VERIFIED_EMAIL_META,
                    true
                )
            )
        );

        return $verifiedEmail !== ''
            && hash_equals(
                strtolower((string) $user->user_email),
                $verifiedEmail
            );
    }

    public function send(int $userId): bool|WP_Error
    {
        $user = get_userdata($userId);

        if (! $user) {
            return new WP_Error(
                'codigle_email_user_missing',
                'The account could not be loaded.'
            );
        }

        if ($this->isVerified($userId)) {
            return true;
        }

        $cooldownKey = 'cdg_email_verify_' . $userId;

        if (get_transient($cooldownKey)) {
            return new WP_Error(
                'codigle_email_verification_cooldown',
                'A verification email was sent recently. Please wait a minute.',
                ['status' => 429]
            );
        }

        try {
            $token = bin2hex(random_bytes(32));
        } catch (Throwable) {
            return new WP_Error(
                'codigle_email_token_failed',
                'A secure verification link could not be created.'
            );
        }

        $expires = gmdate(
            'Y-m-d H:i:s',
            time() + 30 * MINUTE_IN_SECONDS
        );
        $hash = hash_hmac('sha256', $token, wp_salt('nonce'));
        $returnUrl = wp_validate_redirect(
            esc_url_raw(
                (string) ($_SERVER['HTTP_REFERER'] ?? home_url('/'))
            ),
            home_url('/')
        );
        $verifyUrl = add_query_arg(
            [
                'codigle_verify_email' => '1',
                'user' => $userId,
                'token' => $token,
                'return' => $returnUrl,
            ],
            home_url('/')
        );
        $subject = 'Verify your Codigle email address';
        $name = trim((string) $user->display_name);
        $greeting = $name !== '' ? 'Hello ' . $name . ',' : 'Hello,';
        $message = $greeting . "\n\n"
            . "Verify your email address to continue your Codigle purchase:\n"
            . $verifyUrl
            . "\n\nThis link expires in 30 minutes.\n\nCodigle";

        update_user_meta($userId, self::TOKEN_HASH_META, $hash);
        update_user_meta($userId, self::TOKEN_EXPIRES_META, $expires);

        if (! wp_mail((string) $user->user_email, $subject, $message)) {
            delete_user_meta($userId, self::TOKEN_HASH_META);
            delete_user_meta($userId, self::TOKEN_EXPIRES_META);

            return new WP_Error(
                'codigle_email_send_failed',
                'The verification email could not be sent.'
            );
        }

        set_transient($cooldownKey, '1', MINUTE_IN_SECONDS);

        return true;
    }

    public function sendRoute(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        $result = $this->send(get_current_user_id());

        if ($result instanceof WP_Error) {
            return $result;
        }

        return new WP_REST_Response(
            [
                'sent' => true,
                'message' => 'Verification email sent. Check your inbox.',
            ]
        );
    }

    public function handleVerificationLink(): void
    {
        if ((string) ($_GET['codigle_verify_email'] ?? '') !== '1') {
            return;
        }

        $userId = absint($_GET['user'] ?? 0);
        $token = sanitize_text_field(
            (string) ($_GET['token'] ?? '')
        );
        $return = wp_validate_redirect(
            esc_url_raw((string) ($_GET['return'] ?? home_url('/'))),
            home_url('/')
        );
        $user = get_userdata($userId);
        $storedHash = (string) get_user_meta(
            $userId,
            self::TOKEN_HASH_META,
            true
        );
        $expires = (string) get_user_meta(
            $userId,
            self::TOKEN_EXPIRES_META,
            true
        );
        $valid = $user
            && $token !== ''
            && $storedHash !== ''
            && $expires !== ''
            && strtotime($expires . ' UTC') >= time()
            && hash_equals(
                $storedHash,
                hash_hmac('sha256', $token, wp_salt('nonce'))
            );

        if ($valid && $user) {
            update_user_meta(
                $userId,
                self::VERIFIED_EMAIL_META,
                strtolower((string) $user->user_email)
            );
            update_user_meta(
                $userId,
                self::VERIFIED_AT_META,
                gmdate('Y-m-d H:i:s')
            );
            delete_user_meta($userId, self::TOKEN_HASH_META);
            delete_user_meta($userId, self::TOKEN_EXPIRES_META);
            $return = add_query_arg(
                'codigle_email_verified',
                '1',
                $return
            );
        } else {
            $return = add_query_arg(
                'codigle_email_verified',
                '0',
                $return
            );
        }

        wp_safe_redirect($return);
        exit;
    }
}
