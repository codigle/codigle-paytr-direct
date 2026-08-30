<?php
/**
 * Plugin Name: Codigle PayTR Direct
 * Description: PayTR Direct API checkout, card storage and Codigle subscription activation.
 * Version: 0.5.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Author: Codigle
 * Text Domain: codigle-paytr-direct
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('CODIGLE_PAYTR_DIRECT_VERSION', '0.5.5');
define('CODIGLE_PAYTR_DIRECT_FILE', __FILE__);
define('CODIGLE_PAYTR_DIRECT_PATH', plugin_dir_path(__FILE__));
define('CODIGLE_PAYTR_DIRECT_URL', plugin_dir_url(__FILE__));

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'Codigle\\PaytrDirect\\';

        if (! str_starts_with($class, $prefix)) {
            return;
        }

        $relative = str_replace(
            '\\',
            DIRECTORY_SEPARATOR,
            substr($class, strlen($prefix))
        );
        $file = CODIGLE_PAYTR_DIRECT_PATH . 'src/' . $relative . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
);

register_activation_hook(
    __FILE__,
    ['Codigle\\PaytrDirect\\Plugin', 'activate']
);
register_deactivation_hook(
    __FILE__,
    ['Codigle\\PaytrDirect\\Plugin', 'deactivate']
);

add_action(
    'before_woocommerce_init',
    static function (): void {
        if (class_exists(
            \Automattic\WooCommerce\Utilities\FeaturesUtil::class
        )) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                __FILE__,
                true
            );
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'cart_checkout_blocks',
                __FILE__,
                true
            );
        }
    }
);

add_action(
    'plugins_loaded',
    static function (): void {
        \Codigle\PaytrDirect\Plugin::boot();
    },
    20
);
