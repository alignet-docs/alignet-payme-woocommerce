<?php
/**
 * Plugin Name: Payme Gateway
 * Plugin URI: https://payme.com
 * Description: Pasarela de pagos Payme para WooCommerce. Acepta pagos con tarjeta, Yape, QR, transferencias bancarias y más.
 * Version: 1.0.0
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Author: Payme
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
define('PAYME_GATEWAY_VERSION', '1.3.0');
define('PAYME_GATEWAY_PLUGIN_FILE', __FILE__);
define('PAYME_GATEWAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PAYME_GATEWAY_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Initialize the plugin
 */
add_action('plugins_loaded', 'payme_gateway_init', 11);
add_action('before_woocommerce_init', 'payme_gateway_declare_compatibility');

/**
 * Load textdomain at the correct time for WordPress 6.7+.
 * WordPress core now auto-loads textdomains from the plugin header,
 * so we only need to ensure it's loaded at 'init' or later.
 */
add_action('init', 'payme_gateway_load_textdomain');

function payme_gateway_load_textdomain() {
    load_plugin_textdomain('payme-gateway', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

/**
 * Declare compatibility with WooCommerce features
 */
function payme_gateway_declare_compatibility() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
}

function payme_gateway_init() {
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
    
    // Include admin class if in admin
    if (is_admin()) {
        require_once PAYME_GATEWAY_PLUGIN_DIR . 'includes/class-wc-payme-admin.php';
        new WC_Payme_Admin();
    }

    // Add the gateway to WooCommerce
    add_filter('woocommerce_payment_gateways', 'payme_add_gateway_class');
    
    // Register AJAX handlers early - these need to be available during admin-ajax.php requests
    // WooCommerce doesn't instantiate payment gateways during AJAX, so we register them here
    if (wp_doing_ajax()) {
        $gateway = new WC_Payme_Gateway();
    }
    
    // Initialize webhook handler
    new WC_Payme_Webhook();
    
    // Initialize blocks support
    new WC_Payme_Blocks_Support();
    
    // Register blocks payment method (must be registered here to ensure correct timing)
    add_action('woocommerce_blocks_payment_method_type_registration', 'payme_register_blocks_payment_method');
}

/**
 * Register Payme payment method for WooCommerce Blocks
 */
function payme_register_blocks_payment_method($payment_method_registry) {
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
function payme_add_gateway_class($gateways) {
    if (class_exists('WC_Payme_Gateway')) {
        $gateways[] = 'WC_Payme_Gateway';
    }
    
    return $gateways;
}

/**
 * Admin notice for missing WooCommerce
 */
function payme_gateway_missing_wc_notice() {
    echo '<div class="error"><p><strong>' . sprintf(esc_html__('Payme Gateway requires WooCommerce to be installed and active. You can download %s here.', 'payme-gateway'), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>') . '</strong></p></div>';
}

/**
 * Plugin activation hook
 */
register_activation_hook(__FILE__, 'payme_gateway_activate');

function payme_gateway_activate() {
    // Create database tables
    payme_create_tables();
    
    // Initialize counter
    payme_init_counter();
}

/**
 * Create plugin tables
 */
function payme_create_tables() {
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
function payme_init_counter() {
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

function payme_gateway_deactivate() {
    // Clean up if needed
}

/**
 * Enqueue order-received page assets.
 * Registered as a standalone wp_enqueue_scripts hook so it works even if
 * WooCommerce doesn't instantiate the gateway on the thank-you page.
 */
add_action('wp_enqueue_scripts', 'payme_enqueue_order_received_assets');

function payme_enqueue_order_received_assets() {
    // Avoid double-enqueue
    if (wp_style_is('payme-order-received', 'enqueued')) {
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
    if (!$is_order_received && strpos($_SERVER['REQUEST_URI'], 'order-received') !== false) {
        $is_order_received = true;
    }

    if (!$is_order_received) {
        return;
    }

    wp_enqueue_style(
        'payme-order-received',
        PAYME_GATEWAY_PLUGIN_URL . 'assets/css/payme-order-received.css',
        array(),
        PAYME_GATEWAY_VERSION
    );

    wp_enqueue_script(
        'payme-order-received',
        PAYME_GATEWAY_PLUGIN_URL . 'assets/js/payme-order-received.js',
        array('jquery'),
        PAYME_GATEWAY_VERSION,
        true
    );

    // Pass order status to JS so the banner adapts for pending/on-hold orders
    $order_status = '';
    $order_id = isset($_GET['order-received']) ? absint($_GET['order-received']) : 0;
    if (!$order_id) {
        // Try from URL path (pretty permalinks)
        if (preg_match('/order-received\/(\d+)/', $_SERVER['REQUEST_URI'], $matches)) {
            $order_id = absint($matches[1]);
        }
    }
    if ($order_id && function_exists('wc_get_order')) {
        $order = wc_get_order($order_id);
        if ($order) {
            $order_status = $order->get_status();
        }
    }
    wp_localize_script('payme-order-received', 'payme_order', array(
        'status' => $order_status,
    ));

    wp_add_inline_style('payme-order-received', 'body.woocommerce-order-received { background: #f8fafc !important; }');
}

