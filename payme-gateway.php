<?php
/**
 * Plugin Name: Pay-me Gateway
 * Plugin URI: https://payme.com
 * Description: Pasarela de pagos Pay-me para WooCommerce. Acepta pagos con tarjeta, Yape, QR, transferencias bancarias y más.
 * Version: 1.4.0
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Author: Pay-me
 * Author URI: https://payme.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: payme-gateway
 * Domain Path: /languages
 * WC requires at least: 3.0
 * WC tested up to: 8.5
 * Requires Plugins: woocommerce
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('PAYME_GATEWAY_VERSION', '1.4.0');
define('PAYME_GATEWAY_PLUGIN_FILE', __FILE__);
define('PAYME_GATEWAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PAYME_GATEWAY_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Return a cache-busting version for a local plugin asset.
 *
 * Including the file modification time makes WordPress generate a different
 * URL after every deployment that changes the asset, while still allowing the
 * browser to cache unchanged files.
 *
 * @param string $relative_path Path relative to the plugin directory.
 * @return string
 */
function payme_asset_version($relative_path)
{
    $asset_path = PAYME_GATEWAY_PLUGIN_DIR . ltrim($relative_path, '/');

    if (is_file($asset_path)) {
        return PAYME_GATEWAY_VERSION . '.' . filemtime($asset_path);
    }

    return PAYME_GATEWAY_VERSION;
}

/**
 * Initialize the plugin
 */
add_action('plugins_loaded', 'payme_gateway_init', 11);
add_action('before_woocommerce_init', 'payme_gateway_declare_compatibility');
add_action('upgrader_process_complete', 'payme_gateway_after_upgrade', 10, 2);

/**
 * Load textdomain at the correct time for WordPress 6.7+.
 * WordPress core now auto-loads textdomains from the plugin header,
 * so we only need to ensure it's loaded at 'init' or later.
 */
add_action('init', 'payme_gateway_load_textdomain');

function payme_gateway_load_textdomain()
{
    load_plugin_textdomain('payme-gateway', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

/**
 * Declare compatibility with WooCommerce features
 */
function payme_gateway_declare_compatibility()
{
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
}

function payme_gateway_init()
{
    // Check if WooCommerce is active
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', 'payme_gateway_missing_wc_notice');
        return;
    }

    // Include required files
    require_once PAYME_GATEWAY_PLUGIN_DIR . 'includes/class-wc-payme-logger.php';
    require_once PAYME_GATEWAY_PLUGIN_DIR . 'includes/class-wc-payme-gateway.php';
    require_once PAYME_GATEWAY_PLUGIN_DIR . 'includes/class-wc-payme-webhook.php';
    require_once PAYME_GATEWAY_PLUGIN_DIR . 'includes/class-wc-payme-blocks-support.php';
    require_once PAYME_GATEWAY_PLUGIN_DIR . 'includes/class-wc-payme-result.php';

    // Include admin class if in admin
    if (is_admin()) {
        require_once PAYME_GATEWAY_PLUGIN_DIR . 'includes/class-wc-payme-admin.php';
        new WC_Payme_Admin();
    }

    // Add the gateway to WooCommerce
    add_filter('woocommerce_payment_gateways', 'payme_add_gateway_class');

    add_filter('wcml_supported_gateways', 'payme_wcml_supported_gateways', 1);
    function payme_wcml_supported_gateways($gateways)
    {
        if (!is_array($gateways)) {
            $gateways = array();
        }
        $gateways['payme'] = 1;
        $gateways['paymecheckout'] = 1;
        return $gateways;
    }

    add_filter('woocommerce_available_payment_gateways', 'payme_force_gateway_available', 999);
    function payme_force_gateway_available($available_gateways)
    {
        if (!function_exists('WC') || !WC()->payment_gateways()) {
            return $available_gateways;
        }
        $all_gateways = WC()->payment_gateways()->payment_gateways();
        foreach (array('payme', 'paymecheckout') as $gw_id) {
            if (isset($available_gateways[$gw_id]) || !isset($all_gateways[$gw_id])) {
                continue;
            }
            $stored = get_option('woocommerce_' . $gw_id . '_settings', array());
            if (!empty($stored['enabled']) && $stored['enabled'] === 'yes') {
                $available_gateways[$gw_id] = $all_gateways[$gw_id];
            }
        }
        return $available_gateways;
    }



    // Register AJAX handlers early - these need to be available during admin-ajax.php requests
    // WooCommerce doesn't instantiate payment gateways during AJAX, so we register them here
    if (wp_doing_ajax()) {
        $gateway = new WC_Payme_Gateway();
    }

    // Initialize webhook handler
    new WC_Payme_Webhook();

    // Render the unified Pay-me result component on the thank-you page.
    new WC_Payme_Result();

    // Initialize blocks support
    new WC_Payme_Blocks_Support();

    // Register blocks payment method (must be registered here to ensure correct timing)
    add_action('woocommerce_blocks_payment_method_type_registration', 'payme_register_blocks_payment_method');
    add_action('admin_init', 'payme_register_wcml_custom_gateway', 5);
    function payme_register_wcml_custom_gateway()
    {
        $option_key = 'wcml_custom_payment_gateways_for_currencies';
        $current = get_option($option_key, array());
        if (!is_array($current)) {
            $current = array();
        }
        $modified = false;
        foreach (array('payme', 'paymecheckout') as $gw_id) {
            if (!isset($current[$gw_id])) {
                $current[$gw_id] = array('currency' => '');
                $modified = true;
            }
        }
        if ($modified) {
            update_option($option_key, $current);
        }
    }

}

/**
 * Register Payme payment method for WooCommerce Blocks
 */
function payme_register_blocks_payment_method($payment_method_registry)
{
    $blocks_file = PAYME_GATEWAY_PLUGIN_DIR . 'includes/class-wc-payme-blocks-payment-method.php';
    if (file_exists($blocks_file) && !class_exists('WC_Payme_Blocks_Payment_Method')) {
        require_once $blocks_file;
    }

    if (class_exists('WC_Payme_Blocks_Payment_Method')) {
        $payment_method_registry->register(new WC_Payme_Blocks_Payment_Method());
    }
}

/**
 * Add Payme Gateway to WooCommerce
 */
function payme_add_gateway_class($gateways)
{
    if (class_exists('WC_Payme_Gateway')) {
        $gateways[] = 'WC_Payme_Gateway';
    }

    return $gateways;
}

/**
 * Admin notice for missing WooCommerce
 */
function payme_gateway_missing_wc_notice()
{
    echo '<div class="error"><p><strong>' . sprintf(esc_html__('Pay-me Gateway requires WooCommerce to be installed and active. You can download %s here.', 'payme-gateway'), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>') . '</strong></p></div>';
}

/**
 * Plugin activation hook
 */
register_activation_hook(__FILE__, 'payme_gateway_activate');

function payme_gateway_activate()
{
    // Create database tables
    payme_create_tables();

    // Initialize counter
    payme_init_counter();

    // Remove stale code/plugin metadata after a reinstall or rollback.
    payme_gateway_clear_runtime_cache();
}

/**
 * Clear temporary caches without deleting credentials, gateway settings,
 * transactions or any other persistent Pay-me data.
 */
function payme_gateway_clear_runtime_cache()
{
    if (function_exists('wp_clean_plugins_cache')) {
        wp_clean_plugins_cache(true);
    } else {
        delete_site_transient('update_plugins');
    }

    // Invalidate only Pay-me-related option cache entries. This never deletes
    // the underlying database values, so credentials and settings are kept.
    if (function_exists('wp_cache_delete')) {
        $option_keys = array(
            'woocommerce_payme_settings',
            'woocommerce_paymecheckout_settings',
            'wcml_custom_payment_gateways_for_currencies',
        );
        foreach ($option_keys as $option_key) {
            wp_cache_delete($option_key, 'options');
        }
    }
}

/**
 * Clear caches after WordPress replaces this plugin during an update.
 * Plugin upgrades may silently deactivate it, skipping the deactivation hook.
 */
function payme_gateway_after_upgrade($upgrader, $hook_extra)
{
    if (empty($hook_extra['type']) || $hook_extra['type'] !== 'plugin') {
        return;
    }

    $updated_plugins = array();
    if (!empty($hook_extra['plugins']) && is_array($hook_extra['plugins'])) {
        $updated_plugins = $hook_extra['plugins'];
    } elseif (!empty($hook_extra['plugin'])) {
        $updated_plugins = array($hook_extra['plugin']);
    }

    if (in_array(plugin_basename(PAYME_GATEWAY_PLUGIN_FILE), $updated_plugins, true)) {
        payme_gateway_clear_runtime_cache();
    }
}

/**
 * Create plugin tables
 */
function payme_create_tables()
{
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    // Transactions table
    $transactions_table = $wpdb->prefix . 'payme_transactions';
    $transactions_sql = "CREATE TABLE $transactions_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        order_id bigint(20) NOT NULL,
        merchant_operation_number varchar(20) NOT NULL,
        amount decimal(10,2) NOT NULL,
        currency varchar(3) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        request_data longtext,
        response_data longtext,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY merchant_operation_number (merchant_operation_number),
        KEY order_id (order_id)
    ) $charset_collate;";

    // Counters table
    $counters_table = $wpdb->prefix . 'payme_counters';
    $counters_sql = "CREATE TABLE $counters_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        counter_name varchar(50) NOT NULL,
        current_value bigint(20) NOT NULL DEFAULT 0,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY counter_name (counter_name)
    ) $charset_collate;";

    // Logs table
    $logs_table = $wpdb->prefix . 'payme_logs';
    $logs_sql = "CREATE TABLE $logs_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        level varchar(20) NOT NULL,
        message text NOT NULL,
        context longtext,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY level (level),
        KEY created_at (created_at)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($transactions_sql);
    dbDelta($counters_sql);
    dbDelta($logs_sql);
}

/**
 * Initialize operation counter
 */
function payme_init_counter()
{
    global $wpdb;

    $counters_table = $wpdb->prefix . 'payme_counters';

    // Check if counter exists
    $counter_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $counters_table WHERE counter_name = %s",
        'operation_number'
    ));

    if (!$counter_exists) {
        $wpdb->insert(
            $counters_table,
            array(
                'counter_name' => 'operation_number',
                'current_value' => 1
            )
        );
    }
}

/**
 * Plugin deactivation hook
 */
register_deactivation_hook(__FILE__, 'payme_gateway_deactivate');

function payme_gateway_deactivate()
{
    // Keep all settings and credentials; remove temporary runtime caches only.
    payme_gateway_clear_runtime_cache();
}

/**
 * Enqueue order-received page assets.
 * Registered as a standalone wp_enqueue_scripts hook so it works even if
 * WooCommerce doesn't instantiate the gateway on the thank-you page.
 */
add_action('wp_enqueue_scripts', 'payme_enqueue_order_received_assets');

function payme_enqueue_order_received_assets()
{
    // Avoid double-enqueue
    if (wp_style_is('payme-result', 'enqueued')) {
        return;
    }

    // Multiple detection methods for the order-received / thank-you page
    $is_order_received = false;

    // 1. WooCommerce endpoint detection (works with pretty permalinks)
    if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received')) {
        $is_order_received = true;
    }

    // 2. Query parameter detection (plain permalinks: ?page_id=8&order-received=97&key=...)
    if (!$is_order_received && isset($_GET['order-received']) && isset($_GET['key'])) {
        $is_order_received = true;
    }

    // 3. URL path detection
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if (!$is_order_received && strpos($request_uri, 'order-received') !== false) {
        $is_order_received = true;
    }

    if (!$is_order_received) {
        return;
    }

    $order_id = isset($_GET['order-received']) ? absint($_GET['order-received']) : 0;
    if (!$order_id && preg_match('/order-received\/(\d+)/', $request_uri, $matches)) {
        $order_id = absint($matches[1]);
    }

    if (!class_exists('WC_Payme_Result') || !WC_Payme_Result::is_payme_result_request($order_id)) {
        return;
    }

    wp_enqueue_style(
        'payme-result',
        PAYME_GATEWAY_PLUGIN_URL . 'assets/css/payme-result.css',
        array(),
        payme_asset_version('assets/css/payme-result.css')
    );

    wp_enqueue_script(
        'payme-result',
        PAYME_GATEWAY_PLUGIN_URL . 'assets/js/payme-result.js',
        array(),
        payme_asset_version('assets/js/payme-result.js'),
        true
    );

    wp_localize_script('payme-result', 'payme_result_i18n', array(
        'copy' => __('Copiar ID de transacción', 'payme-gateway'),
        'copied' => __('Copiado', 'payme-gateway'),
        'copy_error' => __('No se pudo copiar', 'payme-gateway'),
    ));
}
