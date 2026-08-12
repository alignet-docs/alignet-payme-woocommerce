<?php
/**
 * Payme Blocks Payment Method Class
 */

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class WC_Payme_Blocks_Payment_Method extends AbstractPaymentMethodType {
    
    protected $name = 'payme';
    
    public function initialize() {
        $this->settings = get_option('woocommerce_payme_settings', array());
    }
    
    public function is_active() {
        $gateway = new WC_Payme_Gateway();
        return $gateway->is_available();
    }
    
    public function get_payment_method_script_handles() {
        $script_path = PAYME_GATEWAY_PLUGIN_URL . 'assets/js/payme-blocks.js';

        wp_register_script(
            'payme-blocks-integration',
            $script_path,
            array(
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n'
            ),
            payme_asset_version('assets/js/payme-blocks.js'),
            true
        );
        
        return array('payme-blocks-integration');
    }
    
    public function get_payment_method_data() {
        $gateway = new WC_Payme_Gateway();
        
        // Get icon URL properly
        $icon_url = '';
        if (file_exists(PAYME_GATEWAY_PLUGIN_DIR . 'assets/images/payme-logo.png')) {
            $icon_url = PAYME_GATEWAY_PLUGIN_URL . 'assets/images/payme-logo.png';
        } elseif (file_exists(PAYME_GATEWAY_PLUGIN_DIR . 'assets/images/payme-logo.svg')) {
            $icon_url = PAYME_GATEWAY_PLUGIN_URL . 'assets/images/payme-logo.svg';
        }
        
        $data = array(
            'title' => $gateway->get_title(),
            'description' => $gateway->get_description(),
            'supports' => $gateway->supports,
            'icon' => $icon_url,
            'plugin_url' => PAYME_GATEWAY_PLUGIN_URL,
            'settings' => array(
                'environment' => $gateway->get_option('environment'),
                'display_mode' => $gateway->get_option('display_mode'),
                'payment_methods' => $gateway->payment_methods,
                'payment_type' => $gateway->get_option('payment_type', 'junto'),
                'hide_animation' => $gateway->get_option('hide_animation', 'no'),
            )
        );
        
        // Also add to wc settings for JavaScript access
        if (function_exists('wp_add_inline_script')) {
            $script = 'window.wc = window.wc || {}; window.wc.wcSettings = window.wc.wcSettings || {}; window.wc.wcSettings.getSetting = window.wc.wcSettings.getSetting || function(key, defaultValue) { return window.wc.wcSettings[key] || defaultValue; }; window.wc.wcSettings.payme_data = ' . wp_json_encode($data) . ';';
            wp_add_inline_script('wc-settings', $script, 'before');
        }
        
        return $data;
    }
}
