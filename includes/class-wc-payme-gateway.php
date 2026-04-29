<?php
/**
 * Payme Gateway Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Payme_Gateway extends WC_Payment_Gateway {

    /**
     * Gateway properties
     */
    public $client_id;
    public $client_secret;
    public $merchant_code;
    public $environment;
    public $currency;
    public $payment_methods;
    public $display_mode;
    public $country;
    public $debug_mode;
    public $payment_type;
    public $redirect_url;

    /**
     * Constructor
     */
    public function __construct() {
        $this->id = 'payme';
        $this->icon = '';
        // Set icon URL properly
        if (file_exists(PAYME_GATEWAY_PLUGIN_DIR . 'assets/images/payme-logo.png')) {
            $this->icon = PAYME_GATEWAY_PLUGIN_URL . 'assets/images/payme-logo.png';
        } elseif (file_exists(PAYME_GATEWAY_PLUGIN_DIR . 'assets/images/payme-logo.svg')) {
            $this->icon = PAYME_GATEWAY_PLUGIN_URL . 'assets/images/payme-logo.svg';
        }
        $this->has_fields = true;
        $this->method_title = 'Payme Gateway';
        $this->method_description = 'Acepta pagos con tarjeta, Yape, QR, transferencias bancarias y más métodos de pago.';
        $this->supports = array(
            'products',
            'refunds'
        );

        // Initialize form fields and settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title = $this->get_option('title', 'Pagar con Payme');
        $this->description = $this->get_option('description', 'Paga con tarjeta, Yape, QR y más métodos');
        $this->enabled = $this->get_option('enabled', 'yes');
        $this->client_id = $this->get_option('client_id');
        $this->client_secret = $this->get_option('client_secret');
        $this->merchant_code = $this->get_option('merchant_code');
        $this->environment = $this->get_option('environment', 'sandbox');
        $this->currency = $this->get_option('currency', '604');
        $this->payment_methods = $this->get_option('payment_methods', array('CARD'));
        $this->display_mode = $this->get_option('display_mode', 'embedded');
        $this->country = $this->get_option('country', 'PE');
        $this->debug_mode = $this->get_option('debug_mode', 'no');
        $this->payment_type = $this->get_option('payment_type', 'junto');
        $this->callback_url = '';
        $this->redirect_url = $this->get_option('redirect_url', '');

        // Apply saved order to payment methods
        $saved_order = $this->get_option('payment_methods_order', '');
        if (!empty($saved_order)) {
            $ordered = explode(',', $saved_order);
            // Keep only methods that are in the selected payment_methods
            $ordered_methods = array_intersect($ordered, $this->payment_methods);
            // Add any selected methods not in the order (safety fallback)
            $remaining = array_diff($this->payment_methods, $ordered_methods);
            $this->payment_methods = array_values(array_merge($ordered_methods, $remaining));
        }

        // Auto-populate URLs on first load if empty
        add_action('admin_init', array($this, 'maybe_populate_urls'));

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'order_received_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        
        // AJAX endpoints
        add_action('wp_ajax_payme_get_payment_data', array($this, 'ajax_get_payment_data'));
        add_action('wp_ajax_nopriv_payme_get_payment_data', array($this, 'ajax_get_payment_data'));
        add_action('wp_ajax_payme_process_payment_result', array($this, 'ajax_process_payment_result'));
        add_action('wp_ajax_nopriv_payme_process_payment_result', array($this, 'ajax_process_payment_result'));
        
        // Blocks support
        add_action('woocommerce_blocks_loaded', array($this, 'register_blocks_support'));
        
        // Handle payment success callback (template_redirect is the correct hook for frontend redirects)
        add_action('template_redirect', array($this, 'handle_payment_success_callback'), 1);

        // Auto-refund via Payme API when order status changes to "refunded"
        add_action('woocommerce_order_status_refunded', array($this, 'handle_status_refunded'), 10, 1);

        // Replace WooCommerce refund UI with a single "Extornar con Payme" button
        add_action('woocommerce_order_item_add_action_buttons', array($this, 'add_payme_refund_button'), 10, 1);
        add_action('wp_ajax_payme_full_refund', array($this, 'ajax_full_refund'));
    }

    /**
     * Auto-populate callback and redirect URLs if empty
     */
    public function maybe_populate_urls() {
        $updated = false;

        if (empty($this->redirect_url)) {
            $this->redirect_url = wc_get_checkout_url();
            $this->update_option('redirect_url', $this->redirect_url);
            $updated = true;
        }

        $s2s_url = $this->get_option('s2s_url', '');
        if (empty($s2s_url)) {
            $s2s_url = WC()->api_request_url('payme_s2s');
            $this->update_option('s2s_url', $s2s_url);
            $updated = true;
        }
    }

    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Habilitar/Deshabilitar', 'payme-gateway'),
                'type' => 'checkbox',
                'label' => __('Habilitar Payme Gateway', 'payme-gateway'),
                'default' => 'yes',
                'class' => 'payme-toggle-switch'
            ),
            'title' => array(
                'title' => __('Título', 'payme-gateway'),
                'type' => 'text',
                'description' => __('Título que verá el usuario durante el checkout.', 'payme-gateway'),
                'default' => __('Pagar con Payme', 'payme-gateway'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Descripción', 'payme-gateway'),
                'type' => 'textarea',
                'description' => __('Descripción que verá el usuario durante el checkout.', 'payme-gateway'),
                'default' => __('Paga con tarjeta, Yape, QR y más métodos', 'payme-gateway'),
                'desc_tip' => true,
            ),
            'country' => array(
                'title' => __('País', 'payme-gateway'),
                'type' => 'select',
                'description' => __('País de operación.', 'payme-gateway'),
                'default' => 'PE',
                'options' => array(
                    'PE' => __('Perú', 'payme-gateway'),
                    'CO' => __('Colombia', 'payme-gateway'),
                    'CL' => __('Chile', 'payme-gateway'),
                    'AR' => __('Argentina', 'payme-gateway'),
                    'BR' => __('Brasil', 'payme-gateway'),
                    'EC' => __('Ecuador', 'payme-gateway'),
                    'UY' => __('Uruguay', 'payme-gateway'),
                    'PY' => __('Paraguay', 'payme-gateway'),
                    'BO' => __('Bolivia', 'payme-gateway'),
                    'VE' => __('Venezuela', 'payme-gateway')
                ),
                'desc_tip' => true,
            ),
            'environment' => array(
                'title' => __('Ambiente', 'payme-gateway'),
                'type' => 'radio',
                'description' => __('Selecciona el ambiente de trabajo.', 'payme-gateway'),
                'default' => 'sandbox',
                'options' => array(
                    'sandbox' => __('Sandbox (Pruebas)', 'payme-gateway'),
                    'production' => __('Producción ⚠️', 'payme-gateway')
                ),
                'desc_tip' => true,
                'class' => 'payme-environment-radio'
            ),
            'display_mode' => array(
                'title' => __('Modo de Visualización', 'payme-gateway'),
                'type' => 'select',
                'description' => __('Cómo mostrar el formulario de pago.', 'payme-gateway'),
                'default' => 'embedded',
                'options' => array(
                    'embedded' => __('Embebido en el checkout', 'payme-gateway'),
                    'popup' => __('Modal (sobre la misma página)', 'payme-gateway')
                ),
                'desc_tip' => true,
            ),
            'client_id' => array(
                'title' => __('Client ID', 'payme-gateway'),
                'type' => 'text',
                'description' => __('Client ID proporcionado por Payme.', 'payme-gateway'),
                'default' => '',
                'desc_tip' => true,
            ),
            'client_secret' => array(
                'title' => __('Client Secret', 'payme-gateway'),
                'type' => 'password',
                'description' => __('Client Secret proporcionado por Payme.', 'payme-gateway'),
                'default' => '',
                'desc_tip' => true,
                'class' => 'payme-password-field'
            ),
            'merchant_code' => array(
                'title' => __('Merchant Code', 'payme-gateway'),
                'type' => 'text',
                'description' => __('Código de comercio proporcionado por Payme.', 'payme-gateway'),
                'default' => '',
                'desc_tip' => true,
            ),
            'currency' => array(
                'title' => __('Moneda', 'payme-gateway'),
                'type' => 'select',
                'description' => __('Moneda para las transacciones.', 'payme-gateway'),
                'default' => '604',
                'options' => array(
                    '604' => __('PEN - Sol Peruano', 'payme-gateway'),
                    '840' => __('USD - Dólar Americano', 'payme-gateway'),
                    '978' => __('EUR - Euro', 'payme-gateway'),
                    '170' => __('COP - Peso Colombiano', 'payme-gateway'),
                    '152' => __('CLP - Peso Chileno', 'payme-gateway'),
                    '032' => __('ARS - Peso Argentino', 'payme-gateway'),
                    '986' => __('BRL - Real Brasileño', 'payme-gateway')
                ),
                'desc_tip' => true,
            ),
            'payment_type' => array(
                'title' => __('Tipo de Visualización', 'payme-gateway'),
                'type' => 'radio',
                'description' => __('Cómo mostrar los métodos de pago en el checkout.', 'payme-gateway'),
                'default' => 'junto',
                'options' => array(
                    'junto' => __('Junto (Un solo gateway con todos los métodos)', 'payme-gateway'),
                    'separado' => __('Separado (Un gateway por cada método)', 'payme-gateway')
                ),
                'desc_tip' => true,
                'class' => 'payme-payment-type-radio'
            ),
            'payment_methods' => array(
                'title' => __('Métodos de Pago (arrastra para ordenar)', 'payme-gateway'),
                'type' => 'multiselect',
                'description' => __('Selecciona y ordena los métodos de pago. El orden aquí será el orden en el checkout.', 'payme-gateway'),
                'default' => array('CARD'),
                'options' => array(
                    'CARD' => __('Tarjeta de Crédito/Débito', 'payme-gateway'),
                    'YAPE' => __('Yape', 'payme-gateway'),
                    'QR' => __('Código QR', 'payme-gateway'),
                    'BANK_TRANSFER' => __('Transferencia Bancaria', 'payme-gateway'),
                    'CUOTEALO' => __('Cuotéalo BCP', 'payme-gateway'),
                    'PAGOEFECTIVO' => __('PagoEfectivo', 'payme-gateway')
                ),
                'desc_tip' => true,
                'class' => 'payme-payment-methods'
            ),
            'payment_methods_order' => array(
                'title' => '',
                'type' => 'text',
                'description' => '',
                'default' => '',
                'class' => 'payme-payment-methods-order',
                'css' => 'display:none;',
            ),
            'advanced_section' => array(
                'title' => __('Opciones Avanzadas', 'payme-gateway'),
                'type' => 'title',
                'description' => __('Configuraciones adicionales para desarrolladores y debugging.', 'payme-gateway'),
            ),
            'hide_animation' => array(
                'title' => __('Ocultar animación', 'payme-gateway'),
                'type' => 'select',
                'description' => __('Oculta la pantalla de resultado/animación del SDK Flex después del pago.', 'payme-gateway'),
                'default' => 'no',
                'options' => array(
                    'yes' => __('Sí', 'payme-gateway'),
                    'no' => __('No', 'payme-gateway'),
                ),
                'desc_tip' => true,
            ),
            'redirect_url' => array(
                'title' => __('Redirect URL', 'payme-gateway'),
                'type' => 'text',
                'description' => __('URL de redirección post-pago. Puedes modificarla si usas un proxy o dominio diferente.', 'payme-gateway'),
                'default' => '',
                'desc_tip' => true,
            ),
            's2s_url' => array(
                'title' => __('URL S2S (Notificación Server-to-Server)', 'payme-gateway'),
                'type' => 'text',
                'description' => __('URL para recibir notificaciones de pago asíncronas (QR, PagoEfectivo). Copia esta URL y pásala a Payme.', 'payme-gateway'),
                'default' => '',
                'custom_attributes' => array(
                    'readonly' => 'readonly',
                ),
                'css' => 'background:#f1f5f9;color:#64748b;',
                'desc_tip' => true,
            ),
            'debug_mode' => array(
                'title' => __('Modo Debug', 'payme-gateway'),
                'type' => 'checkbox',
                'label' => __('Habilitar logs de debug', 'payme-gateway'),
                'default' => 'no',
                'description' => __('Guarda logs detallados para debugging. Solo habilitar durante desarrollo o troubleshooting.', 'payme-gateway'),
                'desc_tip' => true
            )
        );
    }

    /**
     * Generate radio button field HTML
     */
    public function generate_radio_html($key, $data) {
        $field_key = $this->get_field_key($key);
        $defaults = array(
            'title' => '',
            'disabled' => false,
            'class' => '',
            'css' => '',
            'placeholder' => '',
            'type' => 'radio',
            'desc_tip' => false,
            'description' => '',
            'custom_attributes' => array(),
            'options' => array()
        );

        $data = wp_parse_args($data, $defaults);

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?> <?php echo $this->get_tooltip_html($data); ?></label>
            </th>
            <td class="forminp">
                <fieldset>
                    <legend class="screen-reader-text"><span><?php echo wp_kses_post($data['title']); ?></span></legend>
                    <div class="<?php echo esc_attr($data['class']); ?>" style="<?php echo esc_attr($data['css']); ?>">
                        <?php foreach ($data['options'] as $option_key => $option_value) : ?>
                            <label>
                                <input
                                    <?php disabled($data['disabled'], true); ?>
                                    class="<?php echo esc_attr($data['class']); ?>"
                                    type="radio"
                                    name="<?php echo esc_attr($field_key); ?>"
                                    id="<?php echo esc_attr($field_key . '_' . $option_key); ?>"
                                    value="<?php echo esc_attr($option_key); ?>"
                                    <?php checked($this->get_option($key), $option_key); ?>
                                    <?php echo $this->get_custom_attribute_html($data); ?>
                                /> <?php echo wp_kses_post($option_value); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?php echo $this->get_description_html($data); ?>
                </fieldset>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Check if the gateway is available for use
     */
    public function is_available() {
        // Check if gateway is enabled
        if ($this->enabled !== 'yes') {
            return false;
        }

        // Check basic configuration
        if (empty($this->client_id) || empty($this->client_secret) || empty($this->merchant_code)) {
            return false;
        }

        // Check if WooCommerce is available
        if (!class_exists('WC_Payment_Gateway')) {
            return false;
        }

        return true;
    }

    /**
     * Payment form on checkout page
     */
    
        public function payment_fields() {
            if ($this->description) {
                echo '<div class="payme-description">' . wp_kses_post(wpautop(wptexturize($this->description))) . '</div>';
            }

            // Billing incomplete banner — visible by default, hidden via JS when fields are complete
            // or when running in Blocks checkout (which has its own validation)
            echo '<div id="payme-billing-banner" class="payme-billing-warning" style="padding:16px 20px;background:linear-gradient(135deg,#fffbeb 0%,#fef3c7 100%);border:1px solid #f59e0b;border-left:4px solid #f59e0b;border-radius:8px;margin:0 0 12px;">';
            echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">';
            echo '<span style="font-size:20px;">📋</span>';
            echo '<strong style="font-size:14px;color:#1e293b;">Completa tus datos de facturación</strong>';
            echo '</div>';
            echo '<p style="margin:0;color:#92400e;font-size:13px;line-height:1.5;">Para seleccionar un método de pago, primero completa los campos obligatorios del formulario.</p>';
            echo '</div>';

            if ($this->display_mode === 'popup' && $this->payment_type !== 'separado') {
                // Junto + Modal: show a "Pagar" button
                echo '<div id="payme-payment-form" class="payme-embedded-form payme-junto-mode" style="display:none;"></div>';
                echo '<button type="button" id="payme-classic-popup-btn" class="payme-popup-pay-btn" style="display:block;width:100%;padding:14px 24px;margin:12px 0;background:linear-gradient(135deg,#3b82f6 0%,#2563eb 100%);color:#fff;border:none;border-radius:8px;font-size:16px;font-weight:600;cursor:pointer;text-align:center;box-shadow:0 2px 8px rgba(59,130,246,0.3);">💳 Pagar con Payme</button>';
            } else {
                if ($this->payment_type === 'separado') {
                    $this->render_separate_payment_methods();
                } else {
                    // Modo junto embebido
                    echo '<div id="payme-payment-form" class="payme-embedded-form payme-junto-mode"></div>';
                }
            }

            // Inline JS to hide billing banner when fields are complete or in Blocks checkout
            ?>
            <script type="text/javascript">
            (function(){
                // In Blocks checkout, hide the banner entirely (Blocks has its own validation in payme-blocks.js)
                if(document.querySelector('.wc-block-checkout')){
                    var b = document.getElementById('payme-billing-banner');
                    if(b) b.style.display = 'none';
                    return;
                }
                function checkBilling(){
                    var fields = [
                        'billing_first_name','billing_last_name','billing_email',
                        'billing_address_1','billing_city','billing_phone'
                    ];
                    var missing = false;
                    for(var i=0;i<fields.length;i++){
                        var el = document.getElementById(fields[i]);
                        if(!el){
                            var els = document.querySelectorAll('input[name="'+fields[i]+'"]');
                            el = els.length ? els[0] : null;
                        }
                        if(!el || !el.value.trim()){
                            missing = true;
                            break;
                        }
                    }
                    var b = document.getElementById('payme-billing-banner');
                    if(b) b.style.display = missing ? '' : 'none';
                }
                // Run checks at multiple intervals to handle WooCommerce AJAX re-renders
                checkBilling();
                setTimeout(checkBilling, 300);
                setTimeout(checkBilling, 800);
                setTimeout(checkBilling, 1500);
                // Listen for input changes in billing fields
                document.addEventListener('input', function(e){
                    if(e.target && e.target.closest){
                        var form = e.target.closest('.woocommerce-billing-fields') || e.target.closest('form.checkout');
                        if(form) checkBilling();
                    }
                });
                document.addEventListener('change', function(e){
                    if(e.target && e.target.closest){
                        var form = e.target.closest('.woocommerce-billing-fields') || e.target.closest('form.checkout');
                        if(form) checkBilling();
                    }
                });
                // Re-check after WooCommerce AJAX updates
                if(typeof jQuery !== 'undefined'){
                    jQuery(document.body).on('updated_checkout', function(){ setTimeout(checkBilling, 100); });
                }
            })();
            </script>
            <?php
        }

    
    /**
     * Render separate payment methods
     */
    private function render_separate_payment_methods() {
        $icons_url = PAYME_GATEWAY_PLUGIN_URL . 'assets/images/methods/';

        $method_config = array(
            'CARD' => array(
                'name' => __('Tarjeta de Crédito/Débito', 'payme-gateway'),
                'icon_url' => $icons_url . 'tarjeta.png',
                'description' => __('Visa, Mastercard, American Express', 'payme-gateway'),
                'color' => '#1e40af'
            ),
            'YAPE' => array(
                'name' => __('Yape', 'payme-gateway'),
                'icon_url' => $icons_url . 'yape.png',
                'description' => __('Pago rápido con tu celular', 'payme-gateway'),
                'color' => '#7c3aed'
            ),
            'QR' => array(
                'name' => __('Código QR', 'payme-gateway'),
                'icon_url' => $icons_url . 'qr.png',
                'description' => __('Escanea y paga al instante', 'payme-gateway'),
                'color' => '#059669'
            ),
            'BANK_TRANSFER' => array(
                'name' => __('Transferencia Bancaria', 'payme-gateway'),
                'icon_url' => $icons_url . 'transbank.png',
                'description' => __('Transferencia desde tu banco', 'payme-gateway'),
                'color' => '#dc2626'
            ),
            'CUOTEALO' => array(
                'name' => __('Cuotéalo BCP', 'payme-gateway'),
                'icon_url' => $icons_url . 'cuotealo.png',
                'description' => __('Paga en cuotas sin tarjeta', 'payme-gateway'),
                'color' => '#ea580c'
            ),
            'PAGOEFECTIVO' => array(
                'name' => __('PagoEfectivo', 'payme-gateway'),
                'icon_url' => $icons_url . 'pagoefectivo.png',
                'description' => __('Paga en agentes y bodegas', 'payme-gateway'),
                'color' => '#f59e0b'
            )
        );
        
        echo '<div class="payme-methods-container">';
        echo '<div class="payme-methods-header">';
        echo '<h4>' . __('Elige tu método de pago preferido', 'payme-gateway') . '</h4>';
        echo '<p>' . __('Selecciona la opción que más te convenga', 'payme-gateway') . '</p>';
        echo '</div>';
        
        echo '<div class="payme-methods-list">';
        
        foreach ($this->payment_methods as $index => $method) {
            $config = isset($method_config[$method]) ? $method_config[$method] : array(
                'name' => $method,
                'icon_url' => '',
                'description' => __('Método de pago', 'payme-gateway'),
                'color' => '#6b7280'
            );
            
            $method_id = 'payme_method_' . strtolower($method);
            $is_first = $index === 0;
            
            echo '<div class="payme-method-item" data-method="' . esc_attr($method) . '">';
            
            // Method card
            echo '<div class="payme-method-card" data-method="' . esc_attr($method) . '">';
            echo '<input type="radio" name="payme_selected_method" id="' . $method_id . '" value="' . $method . '" />';
            echo '<label for="' . $method_id . '" class="payme-method-label">';
            echo '<div class="payme-method-icon" style="background-color: ' . $config['color'] . '20;">';
            if (!empty($config['icon_url'])) {
                echo '<img src="' . esc_url($config['icon_url']) . '" alt="' . esc_attr($config['name']) . '" width="28" height="28" />';
            }
            echo '</div>';
            echo '<div class="payme-method-content">';
            echo '<h5 class="payme-method-title">' . $config['name'] . '</h5>';
            echo '<p class="payme-method-description">' . $config['description'] . '</p>';
            echo '</div>';
            echo '<div class="payme-method-check">';
            echo '<svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor">';
            echo '<path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>';
            echo '</svg>';
            echo '</div>';
            echo '</label>';
            echo '</div>';
            
            // Payment form container (initially hidden)
            echo '<div id="payme-payment-form-' . $method . '" class="payme-embedded-form" style="display:none;"></div>';
            
            echo '</div>'; // .payme-method-item
        }
        
        echo '</div>'; // .payme-methods-list
        echo '</div>'; // .payme-methods-container
    }

    /**
     * Load admin scripts
     */
    public function admin_scripts($hook) {
        // Only load on WooCommerce settings pages
        if (strpos($hook, 'woocommerce') === false) {
            return;
        }
        
        // Check if we're on the payment gateways page
        if (isset($_GET['page']) && $_GET['page'] === 'wc-settings' && 
            isset($_GET['tab']) && $_GET['tab'] === 'checkout' &&
            isset($_GET['section']) && $_GET['section'] === 'payme') {
            
            wp_enqueue_style('payme-admin-styles', PAYME_GATEWAY_PLUGIN_URL . 'assets/css/payme-admin.css', array(), PAYME_GATEWAY_VERSION);
        }
    }

    /**
     * Load payment scripts
     */
    public function payment_scripts() {
        if (!is_admin() && !is_checkout() && !is_cart()) {
            return;
        }
        
        if ($this->enabled !== 'yes') {
            return;
        }

        // Enqueue Payme Flex scripts based on environment
        if ($this->environment === 'production') {
            wp_enqueue_script('payme-flex', 'https://flex.alignet.io/flex-payment-forms.min.js', array(), null, true);
            wp_enqueue_style('payme-flex-css', 'https://flex.alignet.io/main-flex-payment-forms.css', array(), null);
        } else {
            wp_enqueue_script('payme-flex', 'https://alignet-flex-demo.s3.amazonaws.com/flex-payment-forms.min.js', array(), null, true);
            wp_enqueue_style('payme-flex-css', 'https://alignet-flex-demo.s3.amazonaws.com/main-flex-payment-forms.css', array(), null);
        }

        // Enqueue custom styles
        wp_enqueue_style('payme-styles', PAYME_GATEWAY_PLUGIN_URL . 'assets/css/payme-styles.css', array(), PAYME_GATEWAY_VERSION);
        wp_enqueue_style('payme-methods', PAYME_GATEWAY_PLUGIN_URL . 'assets/css/payme-methods.css', array('payme-styles'), PAYME_GATEWAY_VERSION);

        // Enqueue custom checkout script
        $checkout_js_path = PAYME_GATEWAY_PLUGIN_DIR . 'assets/js/payme-checkout.js';
        $checkout_js_ver = PAYME_GATEWAY_VERSION . '.' . (file_exists($checkout_js_path) ? filemtime($checkout_js_path) : '0');
        wp_enqueue_script('payme-checkout', PAYME_GATEWAY_PLUGIN_URL . 'assets/js/payme-checkout.js', array('jquery', 'payme-flex'), $checkout_js_ver, true);

        // Localize script with settings
        wp_localize_script('payme-checkout', 'payme_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('payme_checkout_nonce'),
            'environment' => $this->environment,
            'display_mode' => $this->display_mode,
            'payment_type' => $this->payment_type,
            'hide_animation' => $this->get_option('hide_animation', 'no'),
            'currency' => get_woocommerce_currency(),
            'callback_url' => WC()->api_request_url('payme_callback'),
            'checkout_url' => wc_get_checkout_url(),
            'success_url' => wc_get_endpoint_url('order-received', '', wc_get_checkout_url()),
            'messages' => array(
                'loading' => __('Cargando pasarela de pago...', 'payme-gateway'),
                'error' => __('Error al procesar el pago. Inténtalo nuevamente.', 'payme-gateway'),
                'popup_blocked' => __('El popup fue bloqueado. Por favor, permite popups para este sitio.', 'payme-gateway'),
                'payment_error' => __('El pago no pudo ser procesado. Por favor, inténtalo nuevamente.', 'payme-gateway'),
                'method_selected' => __('Método seleccionado:', 'payme-gateway')
            )
        ));
    }

    /**
     * Process the payment
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wc_add_notice(__('Orden no encontrada.', 'payme-gateway'), 'error');
            return array('result' => 'failure');
        }

        // Validate gateway configuration
        $validation_error = $this->validate_gateway_config();
        if ($validation_error) {
            wc_add_notice($validation_error, 'error');
            return array('result' => 'failure');
        }

        // Validate order data
        $order_validation = $this->validate_order_data($order);
        if ($order_validation !== true) {
            wc_add_notice($order_validation, 'error');
            return array('result' => 'failure');
        }

        try {
            // Generate merchant operation number
            $merchant_operation_number = $this->generate_operation_number();
            
            // Validate amount
            if (!$this->validate_amount($order->get_total())) {
                throw new Exception(__('Monto inválido para procesar el pago.', 'payme-gateway'));
            }

            // Get access token and nonce
            $access_token = $this->get_access_token_private();
            if (!$access_token) {
                throw new Exception(__('Error al obtener token de acceso. Verifica tus credenciales.', 'payme-gateway'));
            }

            $nonce = $this->get_nonce_private($access_token);
            if (!$nonce) {
                throw new Exception(__('Error al obtener nonce. Inténtalo nuevamente.', 'payme-gateway'));
            }

            // Prepare payment data
            $payment_data = $this->prepare_payment_data($order, $merchant_operation_number);
            
            // Save transaction to database
            $transaction_saved = $this->save_transaction($order_id, $merchant_operation_number, $payment_data, 'pending');
            
            if (!$transaction_saved) {
                throw new Exception(__('Error al guardar la transacción.', 'payme-gateway'));
            }

            // Set order status to pending payment
            $order->update_status('pending', sprintf(
                __('Esperando pago con Payme. Número de operación: %s', 'payme-gateway'),
                $merchant_operation_number
            ));

            // Log transaction if debug mode is enabled
            if ($this->debug_mode === 'yes') {
                WC_Payme_Logger::log_transaction(
                    $order_id, 
                    $merchant_operation_number, 
                    'initiated',
                    array('amount' => $order->get_total(), 'currency' => $this->currency)
                );
            }

            // Store order data in session for JavaScript access
            WC()->session->set('payme_order_data', array(
                'order_id' => $order_id,
                'operation_number' => $merchant_operation_number
            ));

            // Return success with payment data for JavaScript
            return array(
                'result' => 'success',
                'redirect' => '',
                'order_id' => $order_id,
                'payment_data' => array(
                    'nonce' => $nonce,
                    'payload' => $payment_data,
                    'display_settings' => array(
                        'methods' => $this->payment_methods
                    ),
                    'order_id' => $order_id,
                    'operation_number' => $merchant_operation_number
                )
            );

        } catch (Exception $e) {
            // Log error
            if ($this->debug_mode === 'yes') {
                WC_Payme_Logger::error('Payment error for order ' . $order_id . ': ' . $e->getMessage());
            }
            
            // Add user-friendly error message
            wc_add_notice($e->getMessage(), 'error');
            
            // Update order status to failed if it was created
            if (isset($merchant_operation_number)) {
                $order->update_status('failed', __('Error al procesar pago con Payme: ', 'payme-gateway') . $e->getMessage());
            }
            
            return array('result' => 'failure');
        }
    }

    /**
     * Generate unique merchant operation number
     */
    private function generate_operation_number() {
        global $wpdb;

        $counters_table = $wpdb->prefix . 'payme_counters';

        // Atomic increment — prevents race conditions with concurrent requests
        $wpdb->query($wpdb->prepare(
            "UPDATE $counters_table SET current_value = current_value + 1 WHERE counter_name = %s",
            'operation_number'
        ));

        $new_value = $wpdb->get_var($wpdb->prepare(
            "SELECT current_value FROM $counters_table WHERE counter_name = %s",
            'operation_number'
        ));

        // Return formatted operation number (9 digits with leading zeros)
        return str_pad(intval($new_value), 9, '0', STR_PAD_LEFT);
    }

    /**
     * Get access token from Payme API (public method for admin use)
     */
    public function get_access_token() {
        return $this->get_access_token_private();
    }
    
    /**
     * Get access token from Payme API (private implementation)
     */
    private function get_access_token_private() {
        $url = $this->environment === 'production' 
            ? 'https://auth.alignet.io/token'
            : 'https://auth.wip.alignet.io/token';

        $audience = $this->environment === 'production'
            ? 'https://api.alignet.io'
            : 'https://api.dev.alignet.io';

        $body = array(
            'action' => 'authorize',
            'grant_type' => 'client_credentials',
            'audience' => $audience,
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'scope' => 'create:token post:charges offline_access'
        );

        $response = wp_remote_post($url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode($body),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            if ($this->debug_mode === 'yes') {
                WC_Payme_Logger::log('Access token error: ' . $response->get_error_message());
            }
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['access_token'])) {
            return $data['access_token'];
        }

        if ($this->debug_mode === 'yes') {
            WC_Payme_Logger::log('Access token response: ' . $body);
        }

        return false;
    }

    /**
     * Get nonce from Payme API (public method for admin use)
     */
    public function get_nonce($access_token) {
        return $this->get_nonce_private($access_token);
    }
    
    /**
     * Get nonce from Payme API (private implementation)
     */
    private function get_nonce_private($access_token) {
        $url = $this->environment === 'production'
            ? 'https://auth.alignet.io/nonce'
            : 'https://auth.wip.alignet.io/nonce';

        $audience = $this->environment === 'production'
            ? 'https://api.alignet.io'
            : 'https://api.dev.alignet.io';

        $body = array(
            'action' => 'create.nonce',
            'audience' => $audience,
            'client_id' => $this->client_id,
            'scope' => 'post:charges'
        );

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $access_token
            ),
            'body' => json_encode($body),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            if ($this->debug_mode === 'yes') {
                WC_Payme_Logger::log('Nonce error: ' . $response->get_error_message());
            }
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['nonce'])) {
            return $data['nonce'];
        }

        if ($this->debug_mode === 'yes') {
            WC_Payme_Logger::log('Nonce response: ' . $body);
        }

        return false;
    }

    /**
     * Get phone country code based on configured country
     */
    private function get_phone_country_code() {
        $country_codes = array(
            'PE' => '+51',
            'CO' => '+57',
            'CL' => '+56',
            'AR' => '+54',
            'BR' => '+55',
            'EC' => '+593',
            'UY' => '+598',
            'PY' => '+595',
            'BO' => '+591',
            'VE' => '+58',
        );

        return isset($country_codes[$this->country]) ? $country_codes[$this->country] : '+51';
    }

    /**
     * Get country full name based on configured country code
     */
    private function get_country_name() {
        $country_names = array(
            'PE' => 'Peru',
            'CO' => 'Colombia',
            'CL' => 'Chile',
            'AR' => 'Argentina',
            'BR' => 'Brasil',
            'EC' => 'Ecuador',
            'UY' => 'Uruguay',
            'PY' => 'Paraguay',
            'BO' => 'Bolivia',
            'VE' => 'Venezuela',
        );

        return isset($country_names[$this->country]) ? $country_names[$this->country] : 'Peru';
    }

    /**
     * Get the resolved redirect URL (manual override or auto-generated)
     */
    private function get_redirect_url() {
        if (!empty($this->redirect_url)) {
            return $this->redirect_url;
        }
        return wc_get_checkout_url();
    }

    /**
     * Prepare payment data for Payme
     */
    private function prepare_payment_data($order, $merchant_operation_number) {
        // Convert amount to cents
        $amount = number_format($order->get_total() * 100, 0, '', '');

        return array(
            'action' => 'authorize',
            'channel' => 'ecommerce',
            'merchant_code' => $this->merchant_code,
            'merchant_operation_number' => $merchant_operation_number,
            'payment_method' => array(
                'method_details' => array(
                    'callback_url' => '',
                    'redirect_url' => $this->get_redirect_url(),
                )
            ),
            'payment_details' => array(
                'amount' => $amount,
                'currency' => $this->currency,
                'additional_fields' => array(
                    'cms' => 'WordPress',
                    'tipo' => $this->display_mode,
                ),
                'billing' => array(
                    'first_name' => $order->get_billing_first_name(),
                    'last_name' => $order->get_billing_last_name(),
                    'email' => $order->get_billing_email(),
                    'phone' => array(
                        'country_code' => $this->get_phone_country_code(),
                        'subscriber' => preg_replace('/[^0-9]/', '', $order->get_billing_phone())
                    ),
                    'location' => array(
                        'line_1' => $order->get_billing_address_1(),
                        'line_2' => $order->get_billing_address_2(),
                        'city' => $order->get_billing_city(),
                        'state' => $order->get_billing_state(),
                        'country' => $this->country,
                        'zip_code' => $order->get_billing_postcode()
                    )
                ),
                'shipping' => array(
                    'first_name' => $order->get_shipping_first_name() ?: $order->get_billing_first_name(),
                    'last_name' => $order->get_shipping_last_name() ?: $order->get_billing_last_name(),
                    'email' => $order->get_billing_email(),
                    'phone' => array(
                        'country_code' => $this->get_phone_country_code(),
                        'subscriber' => preg_replace('/[^0-9]/', '', $order->get_billing_phone())
                    ),
                    'location' => array(
                        'line_1' => $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
                        'line_2' => $order->get_shipping_address_2() ?: $order->get_billing_address_2(),
                        'city' => $order->get_shipping_city() ?: $order->get_billing_city(),
                        'state' => $order->get_shipping_state() ?: $order->get_billing_state(),
                        'country' => $this->country,
                        'zip_code' => $order->get_shipping_postcode() ?: $order->get_billing_postcode()
                    )
                ),
                'customer' => array(
                    'first_name' => $order->get_billing_first_name(),
                    'last_name' => $order->get_billing_last_name(),
                    'email' => $order->get_billing_email(),
                    'phone' => array(
                        'country_code' => $this->get_phone_country_code(),
                        'subscriber' => preg_replace('/[^0-9]/', '', $order->get_billing_phone())
                    ),
                    'location' => array(
                        'line_1' => $order->get_billing_address_1(),
                        'line_2' => $order->get_billing_address_2(),
                        'city' => $order->get_billing_city(),
                        'state' => $order->get_billing_state(),
                        'country' => $this->country,
                        'zip_code' => $order->get_billing_postcode()
                    )
                )
            )
        );
    }

    /**
     * Handle payment success callback on template_redirect
     */
    public function handle_payment_success_callback() {
        // Only run on frontend and if we have the payment success parameters
        if (!is_admin() && 
            isset($_GET['payme_payment']) && 
            $_GET['payme_payment'] === 'success' && 
            isset($_GET['payment_data'])) {
            
            try {
                $payment_data = json_decode(urldecode($_GET['payment_data']), true);
                
                if (!$payment_data || !is_array($payment_data)) {
                    throw new Exception('Invalid payment data');
                }
                
                // Sanitize all string values recursively to prevent stored XSS
                $payment_data = map_deep($payment_data, 'sanitize_text_field');
                
                // Extract operation number — required for verification
                $operation_number = '';
                if (isset($payment_data['merchant_operation_number'])) {
                    $operation_number = sanitize_text_field($payment_data['merchant_operation_number']);
                }
                
                if (empty($operation_number)) {
                    throw new Exception('Missing operation number');
                }
                
                // Verify that this operation number exists in our transactions table
                // This prevents forged payment data — only operations initiated by our plugin are valid
                global $wpdb;
                $transactions_table = $wpdb->prefix . 'payme_transactions';
                $transaction = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $transactions_table WHERE merchant_operation_number = %s LIMIT 1",
                    $operation_number
                ));
                
                if (!$transaction) {
                    throw new Exception('Unknown operation number');
                }
                
                // If transaction already completed, redirect to existing order
                if ($transaction->status === 'completed') {
                    $existing_order = wc_get_order($transaction->order_id);
                    if ($existing_order) {
                        wp_redirect($existing_order->get_checkout_order_received_url());
                        exit;
                    }
                }
                
                // Validate payment success from response data
                // Check for explicit denial first
                $is_denied = false;
                if (isset($payment_data['transaction']['state'])) {
                    $state = strtoupper($payment_data['transaction']['state']);
                    if (in_array($state, array('DENEGADO', 'INVALIDO', 'DENIED', 'REJECTED', 'FAILED'), true)) {
                        $is_denied = true;
                    }
                }

                $is_success = false;
                if (!$is_denied) {
                    $is_success = (
                        (isset($payment_data['success']) && ($payment_data['success'] === 'true' || $payment_data['success'] === true || $payment_data['success'] === '1')) ||
                        (isset($payment_data['transaction']['state']) && strtoupper($payment_data['transaction']['state']) === 'AUTORIZADO') ||
                        (isset($payment_data['status']) && in_array($payment_data['status'], array('AUTHORIZED', 'APPROVED'), true))
                    );
                }
                
                if (!$is_success) {
                    throw new Exception('Payment was not successful');
                }
                
                // Create order
                $order = $this->create_simple_order_from_payment_result($payment_data);
                
                if (!$order) {
                    throw new Exception('Could not create order');
                }
                
                // Get transaction ID
                $transaction_id = '';
                if (isset($payment_data['transaction']['transaction_id'])) {
                    $transaction_id = sanitize_text_field($payment_data['transaction']['transaction_id']);
                } elseif (isset($payment_data['transaction_id'])) {
                    $transaction_id = sanitize_text_field($payment_data['transaction_id']);
                }
                
                // Mark payment as complete
                $order->payment_complete($transaction_id);
                
                // Add order note
                $order->add_order_note(sprintf(
                    __('Pago completado con Payme. ID de transacción: %s. Número de operación: %s', 'payme-gateway'),
                    $transaction_id ?: 'N/A',
                    $operation_number
                ));
                
                // Update transaction record
                $wpdb->update(
                    $transactions_table,
                    array(
                        'order_id' => $order->get_id(),
                        'status' => 'completed',
                        'response_data' => wp_json_encode($payment_data)
                    ),
                    array('merchant_operation_number' => $operation_number),
                    array('%d', '%s', '%s'),
                    array('%s')
                );
                
                // Clear cart
                if (WC()->cart) {
                    WC()->cart->empty_cart();
                }
                
                // Redirect to order received page
                wp_redirect($order->get_checkout_order_received_url());
                exit;
                
            } catch (Exception $e) {
                if ($this->debug_mode === 'yes') {
                    WC_Payme_Logger::error('Payment callback error: ' . $e->getMessage());
                }
                
                // Redirect to checkout with error
                wc_add_notice(__('Error al procesar el pago: ', 'payme-gateway') . $e->getMessage(), 'error');
                wp_redirect(wc_get_checkout_url());
                exit;
            }
        }
    }

    /**
     * Validate gateway configuration
     */
    private function validate_gateway_config() {
        if (empty($this->client_id)) {
            return __('Client ID es requerido. Configúralo en la configuración del gateway.', 'payme-gateway');
        }
        
        if (empty($this->client_secret)) {
            return __('Client Secret es requerido. Configúralo en la configuración del gateway.', 'payme-gateway');
        }
        
        if (empty($this->merchant_code)) {
            return __('Merchant Code es requerido. Configúralo en la configuración del gateway.', 'payme-gateway');
        }
        
        if (empty($this->payment_methods)) {
            return __('Debes seleccionar al menos un método de pago.', 'payme-gateway');
        }
        
        return false;
    }

    /**
     * Validate order data
     */
    private function validate_order_data($order) {
        if (!$order->get_billing_email()) {
            return __('Email de facturación es requerido.', 'payme-gateway');
        }
        
        if (!filter_var($order->get_billing_email(), FILTER_VALIDATE_EMAIL)) {
            return __('Email de facturación no es válido.', 'payme-gateway');
        }
        
        if (!$order->get_billing_first_name()) {
            return __('Nombre de facturación es requerido.', 'payme-gateway');
        }
        
        if (!$order->get_billing_last_name()) {
            return __('Apellido de facturación es requerido.', 'payme-gateway');
        }
        
        if ($order->get_total() <= 0) {
            return __('El monto de la orden debe ser mayor a cero.', 'payme-gateway');
        }
        
        return true;
    }

    /**
     * Validate amount
     */
    private function validate_amount($amount) {
        $amount = floatval($amount);
        
        // Minimum amount validation (1 cent)
        if ($amount < 0.01) {
            return false;
        }
        
        // Maximum amount validation (adjust as needed)
        if ($amount > 999999.99) {
            return false;
        }
        
        return true;
    }

    /**
     * Process refund
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);

        if (!$order) {
            return new WP_Error('invalid_order', __('Orden no encontrada.', 'payme-gateway'));
        }

        // Skip if refund was already processed (prevents duplicate API calls)
        if ($order->get_meta('_payme_refund_processed') === 'yes') {
            return true;
        }

        // Get transaction data
        global $wpdb;
        $transactions_table = $wpdb->prefix . 'payme_transactions';

        $transaction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $transactions_table WHERE order_id = %d AND status IN ('completed','pending') ORDER BY created_at DESC LIMIT 1",
            $order_id
        ));

        if (!$transaction) {
            // Fallback: any non-refunded transaction for this order
            $transaction = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $transactions_table WHERE order_id = %d AND status != 'refunded' ORDER BY created_at DESC LIMIT 1",
                $order_id
            ));
        }

        if (!$transaction) {
            return new WP_Error('no_transaction', __('No se encontró una transacción para esta orden.', 'payme-gateway'));
        }

        try {
            // Get access token
            $access_token = $this->get_access_token_private();
            if (!$access_token) {
                throw new Exception(__('Error al obtener token de acceso para el extorno.', 'payme-gateway'));
            }

            // Payme refund API: DELETE /charges/{merchant_code}/{merchant_operation_number}
            $base_url = $this->environment === 'production'
                ? 'https://api.alignet.io'
                : 'https://api.preprod.alignet.io';

            $url = $base_url . '/charges/' . $this->merchant_code . '/' . $transaction->merchant_operation_number;

            // Log the refund request for debugging
            $order->add_order_note(sprintf('Payme extorno: DELETE %s', $url));

            $response = wp_remote_request($url, array(
                'method' => 'DELETE',
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $access_token,
                    'ALG-API-VERSION' => '1709847567'
                ),
                'timeout' => 30
            ));

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $http_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            // Log the API response
            if ($this->debug_mode === 'yes') {
                WC_Payme_Logger::log_transaction(
                    $order_id,
                    $transaction->merchant_operation_number,
                    'refund_attempt',
                    array('http_code' => $http_code, 'response' => $data)
                );
            }

            // Accept 200 or 204 as success
            if ($http_code >= 200 && $http_code < 300) {
                // Save refund record
                $wpdb->insert(
                    $transactions_table,
                    array(
                        'order_id' => $order_id,
                        'merchant_operation_number' => $transaction->merchant_operation_number . '_refund',
                        'amount' => -abs($transaction->amount),
                        'currency' => $this->currency,
                        'status' => 'refunded',
                        'request_data' => wp_json_encode(array('url' => $url, 'method' => 'DELETE')),
                        'response_data' => $body
                    )
                );

                $order->add_order_note(sprintf(
                    __('Extorno completo procesado con Payme. Operación: %s. Monto: %s', 'payme-gateway'),
                    $transaction->merchant_operation_number,
                    wc_price($transaction->amount)
                ));

                return true;
            } else {
                $error_message = isset($data['message']) ? $data['message'] : '';
                if (empty($error_message) && isset($data['error'])) {
                    $error_message = is_string($data['error']) ? $data['error'] : wp_json_encode($data['error']);
                }
                if (empty($error_message)) {
                    $error_message = sprintf(__('Error del API Payme (HTTP %d)', 'payme-gateway'), $http_code);
                }
                throw new Exception($error_message);
            }

        } catch (Exception $e) {
            if ($this->debug_mode === 'yes') {
                WC_Payme_Logger::error('Refund error for order ' . $order_id . ': ' . $e->getMessage());
            }

            $order->add_order_note(sprintf(
                __('Error al procesar extorno con Payme: %s', 'payme-gateway'),
                $e->getMessage()
            ));

            return new WP_Error('refund_failed', $e->getMessage());
        }
    }

    /**
     * Handle automatic refund when order status changes to "refunded"
     * Calls Payme DELETE API to process full refund
     */
    public function handle_status_refunded($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Only process orders paid via Payme
        if ($order->get_payment_method() !== $this->id) {
            return;
        }

        // Check if refund was already processed (avoid double-refund)
        $already_refunded = $order->get_meta('_payme_refund_processed');
        if ($already_refunded === 'yes') {
            return;
        }

        // Call process_refund with full amount
        $result = $this->process_refund($order_id, $order->get_total(), __('Cambio de estado a Reembolsado', 'payme-gateway'));

        if (is_wp_error($result)) {
            $order->add_order_note(sprintf(
                __('⚠️ No se pudo procesar el extorno automático con Payme: %s', 'payme-gateway'),
                $result->get_error_message()
            ));
        } else {
            // Mark as processed to avoid duplicate refunds
            $order->update_meta_data('_payme_refund_processed', 'yes');
            $order->save();
        }
    }

    /**
     * Add custom "Extornar con Payme" button and hide default refund form
     */
    public function add_payme_refund_button($order) {
        if ($order->get_payment_method() !== $this->id) {
            return;
        }

        // Prevent duplicate rendering
        static $rendered = false;
        if ($rendered) {
            return;
        }
        $rendered = true;

        // Only show for completed/processing orders that haven't been refunded
        $status = $order->get_status();
        if (!in_array($status, array('processing', 'completed', 'on-hold'), true)) {
            return;
        }

        $already_refunded = $order->get_meta('_payme_refund_processed');
        if ($already_refunded === 'yes') {
            return;
        }

        $order_total = $order->get_total();
        $nonce = wp_create_nonce('payme_full_refund');
        ?>
        <style>
            /* Hide default WooCommerce refund button for Payme orders */
            .wc-order-data-row .refund-items { display: none !important; }
        </style>
        <button type="button" class="button payme-refund-btn" id="payme-full-refund-btn"
            style="background: #d63638; color: #fff; border-color: #d63638; margin-left: 8px;"
            data-order-id="<?php echo esc_attr($order->get_id()); ?>"
            data-nonce="<?php echo esc_attr($nonce); ?>"
            data-total="<?php echo esc_attr($order_total); ?>">
            Extornar con Payme (<?php echo wp_kses_post(wc_price($order_total)); ?>)
        </button>
        <script type="text/javascript">
        jQuery(function($) {
            $('#payme-full-refund-btn').on('click', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var total = $btn.data('total');

                if (!confirm('¿Estás seguro de extornar el monto completo de ' + total + '? Esta acción no se puede deshacer.')) {
                    return;
                }

                $btn.prop('disabled', true).text('Procesando extorno...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'payme_full_refund',
                        order_id: $btn.data('order-id'),
                        nonce: $btn.data('nonce')
                    },
                    success: function(response) {
                        console.log('Payme refund debug:', response.data && response.data.debug ? response.data.debug : response.data);
                        if (response.success) {
                            alert('✓ Extorno procesado exitosamente.');
                            location.reload();
                        } else {
                            alert('Error: ' + (response.data.message || 'Error desconocido'));
                            $btn.prop('disabled', false).html($btn.data('original-text'));
                        }
                    },
                    error: function(xhr, status, error) {
                        console.log('Payme refund connection error:', status, error);
                        alert('Error de conexión. Inténtalo nuevamente.');
                        $btn.prop('disabled', false).html($btn.data('original-text'));
                    }
                });
            }).each(function() {
                $(this).data('original-text', $(this).html());
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX handler for full refund button
     */
    public function ajax_full_refund() {
        check_ajax_referer('payme_full_refund', 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(array('message' => __('No tienes permisos para realizar esta acción.', 'payme-gateway')));
            return;
        }

        $order_id = absint($_POST['order_id']);
        $order = wc_get_order($order_id);

        if (!$order) {
            wp_send_json_error(array('message' => __('Orden no encontrada.', 'payme-gateway')));
            return;
        }

        if ($order->get_payment_method() !== $this->id) {
            wp_send_json_error(array('message' => __('Esta orden no fue pagada con Payme.', 'payme-gateway')));
            return;
        }

        // Build debug info before calling refund
        global $wpdb;
        $transactions_table = $wpdb->prefix . 'payme_transactions';
        $transaction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $transactions_table WHERE order_id = %d AND status IN ('completed','pending') ORDER BY created_at DESC LIMIT 1",
            $order_id
        ));
        if (!$transaction) {
            $transaction = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $transactions_table WHERE order_id = %d AND status != 'refunded' ORDER BY created_at DESC LIMIT 1",
                $order_id
            ));
        }

        $base_url = $this->environment === 'production'
            ? 'https://api.alignet.io'
            : 'https://api.preprod.alignet.io';

        $debug = array(
            'method' => 'DELETE',
            'url' => $transaction ? $base_url . '/charges/' . $this->merchant_code . '/' . $transaction->merchant_operation_number : 'NO TRANSACTION FOUND',
            'merchant_code' => $this->merchant_code,
            'operation_number' => $transaction ? $transaction->merchant_operation_number : 'N/A',
            'transaction_status' => $transaction ? $transaction->status : 'N/A',
            'environment' => $this->environment,
        );

        // Process full refund
        $result = $this->process_refund($order_id, $order->get_total(), __('Extorno completo desde panel de administración', 'payme-gateway'));

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
                'debug' => $debug,
            ));
            return;
        }

        // Mark as processed BEFORE changing status to prevent duplicate refund calls
        $order->update_meta_data('_payme_refund_processed', 'yes');
        $order->save();

        // Update order status to refunded
        $order->update_status('refunded', __('Extorno completo procesado con Payme. El estado del pedido cambió de ' . ucfirst($order->get_status()) . ' a Reembolsado.', 'payme-gateway'));

        wp_send_json_success(array(
            'message' => __('Extorno procesado exitosamente.', 'payme-gateway'),
            'debug' => $debug,
        ));
    }

    /**
     * AJAX endpoint to get payment data
     */
    public function ajax_get_payment_data() {
        // Check nonce
        if (!check_ajax_referer('payme_checkout_nonce', 'nonce', false)) {
            wp_send_json_error(array(
                'message' => __('Error de seguridad. Recarga la página e inténtalo nuevamente.', 'payme-gateway')
            ));
            return;
        }

        try {
            // Get order data from session or create temporary order
            $order_data = $this->get_checkout_order_data();
            if (!$order_data) {
                throw new Exception(__('No se pudieron obtener los datos de la orden.', 'payme-gateway'));
            }

            // Validate required billing fields
            $billing = isset($order_data['billing']) ? $order_data['billing'] : array();
            $required_fields = array(
                'first_name' => __('Nombre', 'payme-gateway'),
                'last_name'  => __('Apellido', 'payme-gateway'),
                'email'      => __('Correo electrónico', 'payme-gateway'),
                'phone'      => __('Teléfono', 'payme-gateway'),
                'address_1'  => __('Dirección', 'payme-gateway'),
                'city'       => __('Ciudad', 'payme-gateway'),
            );
            $missing = array();
            foreach ($required_fields as $key => $label) {
                if (empty($billing[$key])) {
                    $missing[] = $label;
                }
            }
            if (!empty($missing)) {
                throw new Exception(
                    __('Completa los siguientes campos para continuar: ', 'payme-gateway') . implode(', ', $missing)
                );
            }

            // Always generate a fresh operation number for each Flex session
            // Flex API requires a unique operation number per session — reusing causes 500 errors
            $operation_number = $this->generate_operation_number();

            // Get access token
            $access_token = $this->get_access_token_private();
            if (!$access_token) {
                throw new Exception(__('Error al obtener token de acceso.', 'payme-gateway'));
            }

            // Get nonce
            $nonce = $this->get_nonce_private($access_token);
            if (!$nonce) {
                throw new Exception(__('Error al obtener nonce.', 'payme-gateway'));
            }

            // Capture selected method from POST data
            $selected_method = '';
            if (isset($_POST['order_data'])) {
                $raw = json_decode(stripslashes($_POST['order_data']), true);
                if (isset($raw['selected_method'])) {
                    $selected_method = sanitize_text_field($raw['selected_method']);
                }
            }

            // Prepare payment payload
            $payload_data = $this->prepare_payment_payload($order_data, $operation_number);

            // Store operation data in session (including selected method for async detection)
            $order_data['selected_method'] = $selected_method;
            WC()->session->set('payme_operation_data', array(
                'operation_number' => $operation_number,
                'order_data' => $order_data,
                'payload' => $payload_data['payload']
            ));

            $response_data = array(
                'nonce' => $nonce,
                'payload' => $payload_data['payload'],
                'operation_number' => $operation_number,
                'display_settings' => array(
                    'methods' => $payload_data['display_methods']
                ),
                'environment' => $this->environment
            );

            wp_send_json_success($response_data);

        } catch (Exception $e) {
            if ($this->debug_mode === 'yes') {
                WC_Payme_Logger::error('AJAX get payment data error: ' . $e->getMessage());
            }

            wp_send_json_error(array(
                'message' => $e->getMessage()
            ));
        }
    }
    
    /**
     * Get checkout order data
     */
    private function get_checkout_order_data() {
        // Try to get from POST data (WooCommerce Blocks)
        if (isset($_POST['order_data'])) {
            $order_data = json_decode(stripslashes($_POST['order_data']), true);

            if (!is_array($order_data)) {
                return null;
            }

            // Sanitize total
            $order_data['total'] = isset($order_data['total']) ? floatval($order_data['total']) : 0;

            // Validate total — try cart if POST value is invalid
            if ($order_data['total'] <= 0 && WC()->cart && !WC()->cart->is_empty()) {
                $order_data['total'] = floatval(WC()->cart->get_total('raw'));
            }

            // Sanitize currency
            $order_data['currency'] = isset($order_data['currency']) ? sanitize_text_field($order_data['currency']) : get_woocommerce_currency();

            // Sanitize billing data
            if (isset($order_data['billing']) && is_array($order_data['billing'])) {
                $billing = $order_data['billing'];
                $order_data['billing'] = array(
                    'first_name' => sanitize_text_field($billing['first_name'] ?? ''),
                    'last_name'  => sanitize_text_field($billing['last_name'] ?? ''),
                    'email'      => sanitize_email($billing['email'] ?? ''),
                    'phone'      => sanitize_text_field($billing['phone'] ?? ''),
                    'address_1'  => sanitize_text_field($billing['address_1'] ?? ''),
                    'address_2'  => sanitize_text_field($billing['address_2'] ?? ''),
                    'city'       => sanitize_text_field($billing['city'] ?? ''),
                    'state'      => sanitize_text_field($billing['state'] ?? ''),
                    'country'    => sanitize_text_field($billing['country'] ?? 'PE'),
                    'postcode'   => sanitize_text_field($billing['postcode'] ?? ''),
                );
            }

            // Fill billing from WC customer if not provided
            if (!isset($order_data['billing']) || empty($order_data['billing']['first_name'])) {
                $order_data['billing'] = $this->get_customer_billing_data();
            }

            return $order_data;
        }

        // Get from cart — main source of truth
        if (WC()->cart && !WC()->cart->is_empty()) {
            return array(
                'total' => WC()->cart->get_total('raw'),
                'currency' => get_woocommerce_currency(),
                'billing' => $this->get_customer_billing_data()
            );
        }

        // No cart and no POST data — cannot proceed
        return null;
    }

    /**
     * Get billing data from WooCommerce customer session
     */
    private function get_customer_billing_data() {
        if (!WC()->customer) {
            return array(
                'first_name' => '', 'last_name' => '', 'email' => '',
                'phone' => '', 'address_1' => '', 'address_2' => '',
                'city' => '', 'state' => '', 'country' => 'PE', 'postcode' => ''
            );
        }

        return array(
            'first_name' => WC()->customer->get_billing_first_name(),
            'last_name' => WC()->customer->get_billing_last_name(),
            'email' => WC()->customer->get_billing_email(),
            'phone' => WC()->customer->get_billing_phone(),
            'address_1' => WC()->customer->get_billing_address_1(),
            'address_2' => WC()->customer->get_billing_address_2(),
            'city' => WC()->customer->get_billing_city(),
            'state' => WC()->customer->get_billing_state(),
            'country' => WC()->customer->get_billing_country() ?: 'PE',
            'postcode' => WC()->customer->get_billing_postcode()
        );
    }
    
    /**
     * Prepare payment payload for Payme Flex
     */
    private function prepare_payment_payload($order_data, $operation_number) {
        $amount = number_format($order_data['total'] * 100, 0, '', '');
        
        // Determine display methods
        $display_methods = array();
        if (isset($order_data['selected_method']) && !empty($order_data['selected_method'])) {
            // Single method selected
            $display_methods = array($order_data['selected_method']);
            
            // Debug log
            if ($this->debug_mode === 'yes') {
                WC_Payme_Logger::log('Selected payment method: ' . $order_data['selected_method']);
            }
        } else {
            // Fallback to all configured methods (for "junto" mode)
            $display_methods = $this->payment_methods;
            
            // Debug log
            if ($this->debug_mode === 'yes') {
                WC_Payme_Logger::log('Using all configured methods: ' . implode(', ', $this->payment_methods));
            }
        }
        
        $payload = array(
            'action' => 'authorize',
            'channel' => 'ecommerce',
            'merchant_code' => $this->merchant_code,
            'merchant_operation_number' => $operation_number,
            'payment_method' => array(
                'method_details' => array(
                    'callback_url' => '',
                    'redirect_url' => $this->get_redirect_url(),
                )
            ),
            'payment_details' => array(
                'amount' => $amount,
                'currency' => $this->currency,
                'additional_fields' => array(
                    'cms' => 'WordPress',
                    'tipo' => $this->display_mode,
                ),
                'billing' => array(
                    'first_name' => $order_data['billing']['first_name'],
                    'last_name' => $order_data['billing']['last_name'],
                    'email' => $order_data['billing']['email'],
                    'phone' => array(
                        'country_code' => $this->get_phone_country_code(),
                        'subscriber' => preg_replace('/[^0-9]/', '', $order_data['billing']['phone'])
                    ),
                    'location' => array(
                        'line_1' => $order_data['billing']['address_1'],
                        'line_2' => $order_data['billing']['address_2'] ?: '',
                        'city' => $order_data['billing']['city'],
                        'state' => $order_data['billing']['state'],
                        'country' => $this->get_country_name(),
                        'zip_code' => $order_data['billing']['postcode'] ?? ''
                    )
                )
            )
        );
        
        // Debug log the complete payload
        if ($this->debug_mode === 'yes') {
            WC_Payme_Logger::log('Payment payload prepared: ' . wp_json_encode($payload));
            WC_Payme_Logger::log('Display methods: ' . wp_json_encode($display_methods));
        }
        
        return array(
            'payload' => $payload,
            'display_methods' => $display_methods
        );
    }
    
    /**
     * AJAX endpoint to process payment result
     */
    public function ajax_process_payment_result() {
        check_ajax_referer('payme_checkout_nonce', 'nonce');
        
        try {
            $payment_result = json_decode(stripslashes($_POST['payment_result']), true);
            
            if (!is_array($payment_result)) {
                throw new Exception(__('Datos de pago inválidos.', 'payme-gateway'));
            }
            
            // Sanitize all string values recursively
            $payment_result = map_deep($payment_result, 'sanitize_text_field');
            
            $operation_data = WC()->session->get('payme_operation_data');
            
            if (!$operation_data) {
                throw new Exception(__('No se encontraron datos de la operación.', 'payme-gateway'));
            }
            
            // Create WooCommerce order
            $order = $this->create_order_from_session_data($operation_data['order_data']);
            if (!$order) {
                throw new Exception(__('Error al crear la orden.', 'payme-gateway'));
            }
            
            // Validate payment result.
            // Priority: check transaction.state first (most reliable from Alignet),
            // then action_code, then fallback to generic fields.
            // IMPORTANT: If transaction.state is DENEGADO or INVALIDO, always reject
            // even if other fields suggest success.
            $action_code = null;
            if (isset($payment_result['action_code'])) {
                $action_code = $payment_result['action_code'];
            } elseif (isset($payment_result['response_code'])) {
                $action_code = $payment_result['response_code'];
            } elseif (isset($payment_result['transaction']['action_code'])) {
                $action_code = $payment_result['transaction']['action_code'];
            } elseif (isset($payment_result['transaction']['response_code'])) {
                $action_code = $payment_result['transaction']['response_code'];
            }

            // Check for explicit denial first
            $is_denied = false;
            if (isset($payment_result['transaction']['state'])) {
                $state = strtoupper($payment_result['transaction']['state']);
                if (in_array($state, array('DENEGADO', 'INVALIDO', 'DENIED', 'REJECTED', 'FAILED'), true)) {
                    $is_denied = true;
                }
            }
            if (!$is_denied && $action_code !== null) {
                $code_str = str_pad($action_code, 2, '0', STR_PAD_LEFT);
                if ($code_str !== '00') {
                    $is_denied = true;
                }
            }

            $is_success = false;
            if (!$is_denied) {
                $is_success = ($action_code === '00' || $action_code === 0 || $action_code === '0')
                    || (isset($payment_result['transaction']['state']) && strtoupper($payment_result['transaction']['state']) === 'AUTORIZADO')
                    || (isset($payment_result['status']) && in_array($payment_result['status'], array('AUTHORIZED', 'APPROVED'), true))
                    || (isset($payment_result['success']) && ($payment_result['success'] === 'true' || $payment_result['success'] === true || $payment_result['success'] === '1'));
            }
            
            // Extract transaction ID from various possible response structures
            $transaction_id = '';
            if (isset($payment_result['transaction_id'])) {
                $transaction_id = sanitize_text_field($payment_result['transaction_id']);
            } elseif (isset($payment_result['transaction']['transaction_id'])) {
                $transaction_id = sanitize_text_field($payment_result['transaction']['transaction_id']);
            } elseif (isset($payment_result['merchant_operation_number'])) {
                $transaction_id = sanitize_text_field($payment_result['merchant_operation_number']);
            }
            
            if ($is_success) {
                // Detect async payment methods (QR, PagoEfectivo, Bank Transfer)
                // These methods generate a code/QR for the user to pay later
                $async_methods = array('QR', 'PAGOEFECTIVO', 'BANK_TRANSFER');
                $selected_method = '';
                if (isset($operation_data['order_data']['selected_method'])) {
                    $selected_method = strtoupper($operation_data['order_data']['selected_method']);
                }

                $is_async = in_array($selected_method, $async_methods, true);

                // Save payment method as order meta
                $method_names = array(
                    'CARD' => 'Tarjeta de Crédito/Débito',
                    'YAPE' => 'Yape',
                    'QR' => 'Código QR',
                    'BANK_TRANSFER' => 'Transferencia Bancaria',
                    'CUOTEALO' => 'Cuotéalo BCP',
                    'PAGOEFECTIVO' => 'PagoEfectivo',
                );
                $method_label = isset($method_names[$selected_method]) ? $method_names[$selected_method] : $selected_method;
                if ($selected_method) {
                    $order->update_meta_data('_payme_payment_method', $selected_method);
                    $order->update_meta_data('_payme_payment_method_label', $method_label);
                    $order->set_payment_method_title('Payme — ' . $method_label);
                    $order->save();
                }

                if ($is_async) {
                    // Async method: mark as on-hold (pending payment)
                    $order->update_status('on-hold', sprintf(
                        __('Pago pendiente con Payme (%s). Número de operación: %s', 'payme-gateway'),
                        $selected_method,
                        $transaction_id ?: 'N/A'
                    ));
                    $order->add_order_note(sprintf(
                        __('Método asíncrono: %s. El cliente debe completar el pago. ID: %s', 'payme-gateway'),
                        $method_label,
                        $transaction_id ?: 'N/A'
                    ));
                } else {
                    // Sync method (CARD, YAPE, CUOTEALO): payment complete
                    $order->payment_complete($transaction_id);
                    $order->add_order_note(sprintf(
                        __('Pago completado con Payme (%s). ID de transacción: %s', 'payme-gateway'),
                        $method_label,
                        $transaction_id ?: 'N/A'
                    ));
                }
                
                // Save transaction
                $this->save_transaction(
                    $order->get_id(),
                    $operation_data['operation_number'],
                    $operation_data['payload'],
                    $is_async ? 'pending' : 'completed',
                    wp_json_encode($payment_result)
                );
                
                // Clear cart
                WC()->cart->empty_cart();
                
                wp_send_json_success(array(
                    'redirect' => $order->get_checkout_order_received_url()
                ));
                
            } else {
                // Payment failed — include response code in order note
                $code_str = $action_code !== null ? str_pad($action_code, 2, '0', STR_PAD_LEFT) : 'N/A';
                $code_messages = array(
                    '01' => 'Denegado por el emisor',
                    '03' => 'Datos de comercio Inválidos',
                    '04' => 'Denegado por el emisor',
                    '05' => 'Denegado por el emisor',
                    '08' => 'Denegado por el emisor',
                    '10' => 'Aprobación Parcial Denegada',
                    '12' => 'Transacción no válida',
                    '13' => 'Monto invalido',
                    '14' => 'Número de cuenta no válido',
                    '15' => 'Emisor no disponible',
                    '30' => 'Error durante el proceso emisor',
                    '41' => 'Denegado por el emisor',
                    '43' => 'Denegado por el emisor',
                    '51' => 'Fondos insuficientes',
                    '54' => 'Tarjeta expirada',
                    '55' => 'PIN incorrecto',
                    '57' => 'La transacción no es permitida',
                    '58' => 'La transacción no es permitida',
                    '61' => 'Excede el límite del monto',
                    '62' => 'Denegado por el emisor',
                    '63' => 'Violación de seguridad',
                    '65' => 'Error durante el proceso emisor',
                    '70' => 'Denegado por el emisor',
                    '71' => 'Error de clave PIN',
                    '75' => 'Excedió el numero de intentos',
                    '76' => 'Error durante el proceso emisor',
                    '77' => 'Error durante el proceso emisor',
                    '78' => 'Error durante el proceso emisor',
                    '79' => 'Denegado por la marca',
                    '81' => 'Denegado por el emisor',
                    '82' => 'Denegado por la marca',
                    '83' => 'Denegado por el emisor',
                    '84' => 'Denegado por el emisor',
                    '85' => 'Denegado por el emisor',
                    '86' => 'Error durante el proceso emisor',
                    '87' => 'Denegado por el emisor',
                    '88' => 'Error de ingreso de datos',
                    '89' => 'Denegado por el emisor',
                    '91' => 'Error durante el proceso emisor',
                    '92' => 'Error durante el proceso emisor',
                    '94' => 'Numero pedido duplicado',
                    '96' => 'Error durante el proceso emisor',
                );
                $code_desc = isset($code_messages[$code_str]) ? $code_messages[$code_str] : 'Error desconocido';

                // Save payment method info on failed order too
                $failed_method = '';
                if (isset($operation_data['order_data']['selected_method'])) {
                    $failed_method = strtoupper($operation_data['order_data']['selected_method']);
                }
                $failed_method_names = array(
                    'CARD' => 'Tarjeta de Crédito/Débito',
                    'YAPE' => 'Yape',
                    'QR' => 'Código QR',
                    'BANK_TRANSFER' => 'Transferencia Bancaria',
                    'CUOTEALO' => 'Cuotéalo BCP',
                    'PAGOEFECTIVO' => 'PagoEfectivo',
                );
                $failed_method_label = isset($failed_method_names[$failed_method]) ? $failed_method_names[$failed_method] : $failed_method;
                if ($failed_method) {
                    $order->update_meta_data('_payme_payment_method', $failed_method);
                    $order->update_meta_data('_payme_payment_method_label', $failed_method_label);
                    $order->set_payment_method_title('Payme — ' . $failed_method_label);
                    $order->save();
                }

                $order->update_status('failed', sprintf(
                    __('Pago rechazado por Payme (%s). Código: %s — %s', 'payme-gateway'),
                    $failed_method_label ?: 'N/A',
                    $code_str,
                    $code_desc
                ));
                
                // Save failed transaction
                $this->save_transaction(
                    $order->get_id(),
                    $operation_data['operation_number'],
                    $operation_data['payload'],
                    'failed',
                    wp_json_encode($payment_result)
                );
                
                wp_send_json_error(array(
                    'message' => sprintf(__('Pago denegado. Código: %s — %s', 'payme-gateway'), $code_str, $code_desc),
                    'redirect' => $order->get_checkout_order_received_url()
                ));
            }
            
        } catch (Exception $e) {
            if ($this->debug_mode === 'yes') {
                WC_Payme_Logger::error('AJAX process payment result error: ' . $e->getMessage());
            }
            
            wp_send_json_error(array(
                'message' => $e->getMessage()
            ));
        }
    }
    
    /**
     * Create order from session data
     */
    private function create_order_from_session_data($order_data) {
        try {
            // Create new order
            $order = wc_create_order();
            
            // Add products from cart to order
            if (WC()->cart && !WC()->cart->is_empty()) {
                foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                    $product = $cart_item['data'];
                    $quantity = $cart_item['quantity'];
                    
                    // Add product to order
                    $item_id = $order->add_product($product, $quantity, array(
                        'variation' => isset($cart_item['variation']) ? $cart_item['variation'] : array(),
                        'totals' => array(
                            'subtotal' => $cart_item['line_subtotal'],
                            'subtotal_tax' => $cart_item['line_subtotal_tax'],
                            'total' => $cart_item['line_total'],
                            'tax' => $cart_item['line_tax'],
                            'tax_data' => $cart_item['line_tax_data']
                        )
                    ));
                }
                
                // Add fees
                foreach (WC()->cart->get_fees() as $fee_key => $fee) {
                    $order->add_fee($fee);
                }
                
                // Add shipping
                foreach (WC()->cart->get_shipping_packages() as $package_key => $package) {
                    if (isset($package['rates']) && !empty($package['rates'])) {
                        foreach ($package['rates'] as $rate) {
                            $order->add_shipping($rate);
                            break; // Only add first rate
                        }
                    }
                }
                
                // Add taxes
                foreach (WC()->cart->get_taxes() as $tax_rate_id => $tax_amount) {
                    $order->add_tax($tax_rate_id, $tax_amount, WC()->cart->get_shipping_tax_amount($tax_rate_id));
                }
            }
            
            // Set billing address
            if (isset($order_data['billing'])) {
                $billing = $order_data['billing'];
                $order->set_billing_first_name($billing['first_name'] ?? '');
                $order->set_billing_last_name($billing['last_name'] ?? '');
                $order->set_billing_email($billing['email'] ?? '');
                $order->set_billing_phone($billing['phone'] ?? '');
                $order->set_billing_address_1($billing['address_1'] ?? '');
                $order->set_billing_address_2($billing['address_2'] ?? '');
                $order->set_billing_city($billing['city'] ?? '');
                $order->set_billing_state($billing['state'] ?? '');
                $order->set_billing_country($billing['country'] ?? 'PE');
                $order->set_billing_postcode($billing['postcode'] ?? '');
            }
            
            // Set shipping address (copy from billing if not provided)
            $order->set_shipping_first_name($order->get_billing_first_name());
            $order->set_shipping_last_name($order->get_billing_last_name());
            $order->set_shipping_address_1($order->get_billing_address_1());
            $order->set_shipping_address_2($order->get_billing_address_2());
            $order->set_shipping_city($order->get_billing_city());
            $order->set_shipping_state($order->get_billing_state());
            $order->set_shipping_country($order->get_billing_country());
            $order->set_shipping_postcode($order->get_billing_postcode());
            
            // Set payment method
            $order->set_payment_method($this->id);
            $order->set_payment_method_title($this->get_title());
            
            // Set currency
            $order->set_currency($order_data['currency'] ?? get_woocommerce_currency());
            
            // Calculate totals
            $order->calculate_totals();
            
            // Save order
            $order->save();
            
            return $order;
            
        } catch (Exception $e) {
            if ($this->debug_mode === 'yes') {
                WC_Payme_Logger::error('Error creating order: ' . $e->getMessage());
            }
            return false;
        }
    }
    
    /**
     * Save transaction with response data
     */
    private function save_transaction($order_id, $operation_number, $request_data, $status, $response_data = null) {
        global $wpdb;
        
        $transactions_table = $wpdb->prefix . 'payme_transactions';
        $order = wc_get_order($order_id);
        
        $data = array(
            'order_id' => $order_id,
            'merchant_operation_number' => $operation_number,
            'amount' => $order ? $order->get_total() : 0,
            'currency' => $order ? $order->get_currency() : get_woocommerce_currency(),
            'status' => $status,
            'request_data' => is_array($request_data) ? wp_json_encode($request_data) : $request_data
        );
        
        if ($response_data) {
            $data['response_data'] = $response_data;
        }
        
        $result = $wpdb->insert($transactions_table, $data);
        
        if ($result === false && $this->debug_mode === 'yes') {
            WC_Payme_Logger::error('Failed to save transaction: ' . $wpdb->last_error);
        }
        
        return $result !== false;
    }
    
    
    /**
     * Create simple order from payment result when session data is not available
     */
    public function create_simple_order_from_payment_result($payment_result) {
        try {
            $order = wc_create_order();
            
            // Add products from cart if available
            if (WC()->cart && !WC()->cart->is_empty()) {
                foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                    $product = $cart_item['data'];
                    $quantity = $cart_item['quantity'];
                    
                    $order->add_product($product, $quantity, array(
                        'subtotal' => $cart_item['line_subtotal'],
                        'total' => $cart_item['line_total']
                    ));
                }
                
                // Add fees
                foreach (WC()->cart->get_fees() as $fee) {
                    $order->add_fee($fee);
                }
                
                // Add shipping
                $shipping_methods = WC()->session->get('chosen_shipping_methods');
                if (!empty($shipping_methods)) {
                    $shipping_packages = WC()->cart->get_shipping_packages();
                    foreach ($shipping_packages as $package_key => $package) {
                        if (isset($package['rates'][$shipping_methods[$package_key]])) {
                            $order->add_shipping($package['rates'][$shipping_methods[$package_key]]);
                        }
                    }
                }
                
            } else {
                // Create order based on payment amount
                $amount = 0;
                if (isset($payment_result['transaction']['amount'])) {
                    $amount = floatval($payment_result['transaction']['amount']) / 100; // Convert from cents
                } elseif (isset($payment_result['amount'])) {
                    $amount = floatval($payment_result['amount']) / 100;
                }
                
                if ($amount > 0) {
                    // Add a simple fee to represent the payment
                    $fee = new WC_Order_Item_Fee();
                    $fee->set_name('Pago con Payme');
                    $fee->set_total($amount);
                    $order->add_item($fee);
                }
            }
            
            // Set customer info from session
            $billing = $this->get_customer_billing_data();
            $order->set_billing_first_name($billing['first_name']);
            $order->set_billing_last_name($billing['last_name']);
            $order->set_billing_email($billing['email']);
            $order->set_billing_phone($billing['phone']);
            $order->set_billing_address_1($billing['address_1']);
            $order->set_billing_city($billing['city']);
            $order->set_billing_country($billing['country']);
            
            // Set payment method
            $order->set_payment_method($this->id);
            $order->set_payment_method_title($this->get_title());
            
            // Set currency
            $order->set_currency(get_woocommerce_currency());
            
            // Calculate totals
            $order->calculate_totals();
            $order->save();
            
            return $order;
            
        } catch (Exception $e) {
            if ($this->debug_mode === 'yes') {
                WC_Payme_Logger::error('Error creating order from payment result: ' . $e->getMessage());
            }
            return false;
        }
    }
    
    /**
     * Register blocks support
     */
    public function register_blocks_support() {
        if (function_exists('woocommerce_store_api_register_payment_requirements')) {
            woocommerce_store_api_register_payment_requirements(array(
                'data_callback' => array($this, 'get_blocks_payment_data')
            ));
        }
    }
    
    /**
     * Get payment data for blocks
     */
    public function get_blocks_payment_data() {
        return array(
            'payme_data' => array(
                'title' => $this->get_title(),
                'description' => $this->get_description(),
                'supports' => $this->supports,
                'icon' => $this->get_icon()
            )
        );
    }

    /**
     * Admin options
     */
    public function admin_options() {
        // Enqueue admin styles
        wp_enqueue_style('payme-admin-styles', PAYME_GATEWAY_PLUGIN_URL . 'assets/css/payme-admin.css', array(), PAYME_GATEWAY_VERSION);
        
        ?>
        <h2><?php esc_html_e('Payme Gateway', 'payme-gateway'); ?></h2>
        <p><?php esc_html_e('Acepta pagos con tarjeta, Yape, QR, transferencias bancarias y más métodos de pago.', 'payme-gateway'); ?></p>
        
        <?php if ($this->enabled === 'yes'): ?>
        <div class="payme-status-check">
            <h3><?php esc_html_e('Estado de la Configuración', 'payme-gateway'); ?></h3>
            <?php $this->display_configuration_status(); ?>
        </div>
        <?php endif; ?>
        
        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Add password toggle functionality
            $('.payme-password-field input[type="password"]').each(function() {
                var $input = $(this);
                var $wrapper = $input.parent();
                
                // Add toggle button
                $wrapper.append('<button type="button" class="payme-password-toggle" title="Mostrar/Ocultar contraseña">👁</button>');
                
                // Toggle functionality
                $wrapper.find('.payme-password-toggle').click(function() {
                    var type = $input.attr('type') === 'password' ? 'text' : 'password';
                    $input.attr('type', type);
                    $(this).text(type === 'password' ? '👁' : '🙈');
                });
            });
            
            // Style radio buttons for environment
            $('input[name="woocommerce_payme_environment"]').each(function() {
                var $radio = $(this);
                var $label = $radio.next('label');
                
                // Wrap radio and label
                $radio.add($label).wrapAll('<div class="payme-radio-wrapper"></div>');
            });
            
            // Style radio buttons for payment type
            $('input[name="woocommerce_payme_payment_type"]').each(function() {
                var $radio = $(this);
                var $label = $radio.next('label');
                
                // Wrap radio and label
                $radio.add($label).wrapAll('<div class="payme-radio-wrapper"></div>');
            });
            
            // Add warning for production environment
            $('input[name="woocommerce_payme_environment"][value="production"]').change(function() {
                if ($(this).is(':checked')) {
                    if (!confirm('⚠️ Estás seleccionando el ambiente de PRODUCCIÓN. Asegúrate de tener las credenciales correctas. ¿Continuar?')) {
                        $('input[name="woocommerce_payme_environment"][value="sandbox"]').prop('checked', true);
                    }
                }
            });
            
            // Enhance payment methods — sortable checkboxes
            $('select[name="woocommerce_payme_payment_methods[]"]').each(function() {
                var $select = $(this);
                var $wrapper = $('<div class="payme-payment-methods-wrapper payme-sortable-methods"></div>');
                var $orderInput = $('input[name="woocommerce_payme_payment_methods_order"]');
                
                // Get saved order from hidden field
                var savedOrder = $orderInput.val() ? $orderInput.val().split(',') : [];
                
                $select.before($wrapper);
                
                // Build options array respecting saved order
                var allOptions = [];
                $select.find('option').each(function() {
                    allOptions.push({
                        value: $(this).val(),
                        text: $(this).text(),
                        selected: $(this).is(':selected')
                    });
                });
                
                // Sort by saved order (selected first in saved order, then unselected)
                if (savedOrder.length > 0) {
                    allOptions.sort(function(a, b) {
                        var aIdx = savedOrder.indexOf(a.value);
                        var bIdx = savedOrder.indexOf(b.value);
                        if (aIdx === -1) aIdx = 999;
                        if (bIdx === -1) bIdx = 999;
                        return aIdx - bIdx;
                    });
                }
                
                // Create sortable items
                allOptions.forEach(function(opt) {
                    var $item = $('<div class="payme-sortable-item" data-value="' + opt.value + '">' +
                        '<span class="payme-drag-handle">☰</span>' +
                        '<label><input type="checkbox" value="' + opt.value + '" ' + (opt.selected ? 'checked' : '') + '> <span>' + opt.text + '</span></label>' +
                        '</div>');
                    $wrapper.append($item);
                });
                
                // Hide original select
                $select.hide();
                
                // Make sortable (using native drag & drop)
                var dragSrcEl = null;
                
                $wrapper.on('mousedown', '.payme-drag-handle', function(e) {
                    var $item = $(this).closest('.payme-sortable-item');
                    $item.attr('draggable', 'true');
                });
                
                $wrapper.on('dragstart', '.payme-sortable-item', function(e) {
                    dragSrcEl = this;
                    $(this).addClass('payme-dragging');
                    e.originalEvent.dataTransfer.effectAllowed = 'move';
                    e.originalEvent.dataTransfer.setData('text/plain', '');
                });
                
                $wrapper.on('dragover', '.payme-sortable-item', function(e) {
                    e.preventDefault();
                    e.originalEvent.dataTransfer.dropEffect = 'move';
                    var $this = $(this);
                    var rect = this.getBoundingClientRect();
                    var midY = rect.top + rect.height / 2;
                    if (e.originalEvent.clientY < midY) {
                        $this.addClass('payme-drag-above').removeClass('payme-drag-below');
                    } else {
                        $this.addClass('payme-drag-below').removeClass('payme-drag-above');
                    }
                });
                
                $wrapper.on('dragleave', '.payme-sortable-item', function() {
                    $(this).removeClass('payme-drag-above payme-drag-below');
                });
                
                $wrapper.on('drop', '.payme-sortable-item', function(e) {
                    e.preventDefault();
                    if (dragSrcEl !== this) {
                        var $target = $(this);
                        var $src = $(dragSrcEl);
                        $target.removeClass('payme-drag-above payme-drag-below');
                        var rect = this.getBoundingClientRect();
                        var midY = rect.top + rect.height / 2;
                        if (e.originalEvent.clientY < midY) {
                            $src.insertBefore($target);
                        } else {
                            $src.insertAfter($target);
                        }
                        syncSelectFromSortable();
                    }
                });
                
                $wrapper.on('dragend', '.payme-sortable-item', function() {
                    $(this).removeClass('payme-dragging').removeAttr('draggable');
                    $wrapper.find('.payme-sortable-item').removeClass('payme-drag-above payme-drag-below');
                });
                
                // Sync checkboxes with select and order
                function syncSelectFromSortable() {
                    var selectedValues = [];
                    var allValues = [];
                    $wrapper.find('.payme-sortable-item').each(function() {
                        var val = $(this).data('value');
                        allValues.push(val);
                        if ($(this).find('input[type="checkbox"]').is(':checked')) {
                            selectedValues.push(val);
                        }
                    });
                    $select.val(selectedValues);
                    $orderInput.val(selectedValues.join(','));
                }
                
                $wrapper.on('change', 'input[type="checkbox"]', function() {
                    syncSelectFromSortable();
                });
                
                // Initial sync
                syncSelectFromSortable();
            });

            // Hide/show payment methods row based on payment_type
            function toggleMethodsRow() {
                var pt = $('input[name="woocommerce_payme_payment_type"]:checked').val();
                var $methodsRow = $('select[name="woocommerce_payme_payment_methods[]"]').closest('tr');
                var $sortableWrapper = $('.payme-payment-methods-wrapper').closest('tr');
                if (pt === 'junto') {
                    $methodsRow.hide();
                    $sortableWrapper.hide();
                } else {
                    $methodsRow.show();
                    $sortableWrapper.show();
                }
            }
            $('input[name="woocommerce_payme_payment_type"]').on('change', toggleMethodsRow);
            toggleMethodsRow();
        });
        </script>
        
        <style>
        .payme-radio-wrapper {
            display: inline-block;
            margin-right: 20px;
            margin-bottom: 10px;
        }
        
        /* Hide the order hidden field row */
        .payme-payment-methods-order {
            display: none !important;
        }
        tr:has(.payme-payment-methods-order) {
            display: none !important;
        }
        
        .payme-payment-type-radio {
            display: flex;
            gap: 20px;
            margin-top: 5px;
        }
        
        .payme-payment-type-radio label {
            display: flex;
            align-items: center;
            padding: 10px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #fff;
        }
        
        .payme-payment-type-radio label:hover {
            border-color: #007cba;
            background: #f8f9fa;
        }
        
        .payme-payment-type-radio input[type="radio"] {
            margin-right: 8px;
        }
        
        .payme-payment-type-radio input[type="radio"]:checked + label,
        .payme-payment-type-radio label:has(input[type="radio"]:checked) {
            border-color: #007cba;
            background: #e7f3ff;
            font-weight: 600;
        }
        
        .payme-payment-methods-wrapper {
            display: flex;
            flex-direction: column;
            gap: 4px;
            margin-top: 10px;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 8px;
            border: 1px solid #ddd;
        }
        
        .payme-sortable-item {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 6px;
            cursor: default;
            transition: all 0.2s ease;
        }
        
        .payme-sortable-item:hover {
            border-color: #007cba;
            background: #f0f8ff;
        }
        
        .payme-drag-handle {
            cursor: grab;
            margin-right: 10px;
            color: #999;
            font-size: 16px;
            user-select: none;
        }
        
        .payme-drag-handle:active {
            cursor: grabbing;
        }
        
        .payme-sortable-item.payme-dragging {
            opacity: 0.4;
        }
        
        .payme-sortable-item.payme-drag-above {
            border-top: 2px solid #007cba;
        }
        
        .payme-sortable-item.payme-drag-below {
            border-bottom: 2px solid #007cba;
        }
        
        .payme-sortable-item label {
            display: flex;
            align-items: center;
            cursor: pointer;
            margin: 0;
        }
        
        .payme-sortable-item input[type="checkbox"] {
            margin-right: 8px;
        }
        
        .payme-sortable-item input[type="checkbox"]:checked + span {
            font-weight: 600;
            color: #007cba;
        }
        
        .payme-sortable-item:has(input[type="checkbox"]:checked) {
            background: #e7f3ff;
            border-color: #007cba;
        }
        </style>
        <?php
    }
    
    /**
     * Display configuration status
     */
    private function display_configuration_status() {
        $status_items = array(
            'client_id' => array(
                'label' => __('Client ID', 'payme-gateway'),
                'status' => !empty($this->client_id)
            ),
            'client_secret' => array(
                'label' => __('Client Secret', 'payme-gateway'),
                'status' => !empty($this->client_secret)
            ),
            'merchant_code' => array(
                'label' => __('Merchant Code', 'payme-gateway'),
                'status' => !empty($this->merchant_code)
            ),
            'payment_methods' => array(
                'label' => __('Métodos de Pago', 'payme-gateway'),
                'status' => !empty($this->payment_methods)
            )
        );
        
        echo '<ul style="margin: 0;">';
        foreach ($status_items as $key => $item) {
            $icon = $item['status'] ? '✅' : '❌';
            $color = $item['status'] ? 'green' : 'red';
            echo '<li style="color: ' . $color . '; margin: 5px 0;">';
            echo $icon . ' ' . esc_html($item['label']);
            if (!$item['status']) {
                echo ' - <em>' . __('No configurado', 'payme-gateway') . '</em>';
            }
            echo '</li>';
        }
        echo '</ul>';
        
        // Test connection button
        if (!empty($this->client_id) && !empty($this->client_secret)) {
            echo '<p style="margin-top: 15px;">';
            echo '<button type="button" id="payme-test-connection" class="button button-secondary">';
            echo __('Probar Conexión', 'payme-gateway');
            echo '</button>';
            echo '<span id="payme-test-result" style="margin-left: 10px;"></span>';
            echo '</p>';
            
            // Add JavaScript for test connection
            ?>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#payme-test-connection').click(function() {
                    var button = $(this);
                    var result = $('#payme-test-result');
                    
                    button.prop('disabled', true).text('<?php echo esc_js(__('Probando...', 'payme-gateway')); ?>');
                    result.html('');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'payme_test_connection',
                            nonce: '<?php echo wp_create_nonce('payme_test_connection'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                result.html('<span style="color: green;">✅ ' + response.data.message + '</span>');
                            } else {
                                result.html('<span style="color: red;">❌ ' + response.data.message + '</span>');
                            }
                        },
                        error: function() {
                            result.html('<span style="color: red;">❌ <?php echo esc_js(__('Error de conexión', 'payme-gateway')); ?></span>');
                        },
                        complete: function() {
                            button.prop('disabled', false).text('<?php echo esc_js(__('Probar Conexión', 'payme-gateway')); ?>');
                        }
                    });
                });
            });
            </script>
            <?php
        }
    }
    
    /**
     * Load order received page scripts and styles
     * NOTE: Also registered independently in payme-gateway.php as a fallback
     */
    public function order_received_scripts() {
        payme_enqueue_order_received_assets();
    }
}