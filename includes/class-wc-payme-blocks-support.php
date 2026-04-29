<?php
/**
 * Payme Blocks Support Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Payme_Blocks_Support {
    
    public function __construct() {
        // Register payment method for blocks
        add_action('woocommerce_blocks_loaded', array($this, 'register_payment_method_type'));
        
        // Enqueue scripts for blocks
        add_action('wp_enqueue_scripts', array($this, 'enqueue_block_scripts'));
    }
    
    public function register_payment_method_type() {
        if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            return;
        }
        
        // Include the blocks payment method class
        $blocks_file = PAYME_GATEWAY_PLUGIN_DIR . 'includes/class-wc-payme-blocks-payment-method.php';
        if (file_exists($blocks_file)) {
            require_once $blocks_file;
        }
    }
    
    public function enqueue_block_scripts() {
        if (!is_checkout() && !is_cart()) {
            return;
        }
        
        // Add payment method data for blocks using the correct hook
        add_filter('woocommerce_blocks_checkout_block_data', array($this, 'add_payme_data_to_blocks'));
    }
    
    public function add_payme_data_to_blocks($data) {
        $gateway = new WC_Payme_Gateway();
        
        // Get icon URL properly
        $icon_url = '';
        if (file_exists(PAYME_GATEWAY_PLUGIN_DIR . 'assets/images/payme-logo.png')) {
            $icon_url = PAYME_GATEWAY_PLUGIN_URL . 'assets/images/payme-logo.png';
        } elseif (file_exists(PAYME_GATEWAY_PLUGIN_DIR . 'assets/images/payme-logo.svg')) {
            $icon_url = PAYME_GATEWAY_PLUGIN_URL . 'assets/images/payme-logo.svg';
        }
        
        $payme_data = array(
            'title' => $gateway->get_title(),
            'description' => $gateway->get_description(),
            'supports' => $gateway->supports,
            'icon' => $icon_url,
            'settings' => array(
                'environment' => $gateway->get_option('environment'),
                'display_mode' => $gateway->get_option('display_mode'),
                'payment_methods' => $gateway->get_option('payment_methods', array()),
                'payment_type' => $gateway->get_option('payment_type', 'junto'),
                'hide_animation' => $gateway->get_option('hide_animation', 'no'),
            )
        );
        
        $data['payme_data'] = $payme_data;
        
        return $data;
    }
}