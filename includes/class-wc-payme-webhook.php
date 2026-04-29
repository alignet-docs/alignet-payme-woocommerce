<?php
/**
 * Payme Webhook Handler Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Payme_Webhook {

    /**
     * Alignet public keys for signature verification (RSA SHA512)
     */
    const ALIGNET_PUBLIC_KEY_SANDBOX = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAkmpsqcJzjQ45u0K7JOQJtfjXGeMXNwsaU6JDGSdKwSDGGXt1m551p2mlG0oGkmn9FPbp4E0lOQzkL/qhHB1YpTP2MqecJ7pMTonEeXOv0P6uwR9yvV5lxK17nE3+xgfcpFfxT5GAI/wZsQJ3+Lsvqh3+IcRG2Hb2BUdM5pYZFOUBGGSZWc/ULPtsFx2DSjI9peJ9kYibpaokphP+Cypz/LgKV7Yiv/TUufPiUk5gFYIad5VhRU822sTMRQ7BgS2CY4t49jqFkIiRnmPwM8fFKjPD4wvzssrqbAQvkk56XOcE9ML0iJhcIY1/xgNSiHqij0Ql1UTU5nAIJR5/paOnhQIDAQAB';

    const ALIGNET_PUBLIC_KEY_PRODUCTION = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAwEAOZB5HxPxraV/SmOUCi+Y/A2tQ9djLWTJ7yx4kXzdDuy0yojZMwA+p5jUExN0ZvW78Td2d29KpmXu7hdTkn+NCx+CpufDh5aSsIl6XLwQh9D7cS+elq/TVaizQIAfNa6+z/xT3zFhMOwfWuj3tY2UKdyss7GFxnQ7BWahci2vlUUuR2wjKniN1XLQWX1gPw1kZhU4EcKcMbymSv7GA/ZJAEs9Ce6kziO8qZAj/tvIf4O3iA2GvfjPgV7tgfp3z2LHeLbxMCIrc+GMpKt9+Yg+hKfandUC3/8fXWtyff2W8iQA6uNDvJ3uG61bOMEKEJwlc1piZxiBsuQkmeGVskwIDAQAB';

    /**
     * Constructor
     */
    public function __construct() {
        add_action('woocommerce_api_payme_callback', array($this, 'handle_callback'));
        add_action('woocommerce_api_payme_s2s', array($this, 'handle_s2s_notification'));
        add_action('wp_ajax_payme_process_payment', array($this, 'process_payment_response'));
        add_action('wp_ajax_nopriv_payme_process_payment', array($this, 'process_payment_response'));
    }

    // ─── S2S NOTIFICATION HANDLER ───────────────────────────────

    /**
     * Handle Server-to-Server notification from Alignet/Payme
     * POST to /wc-api/payme_s2s/
     */
    public function handle_s2s_notification() {
        // Must be POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_die('Method not allowed', 'Payme S2S', array('response' => 405));
        }

        // Read raw body
        $raw_body = file_get_contents('php://input');

        if (empty($raw_body)) {
            WC_Payme_Logger::error('S2S: Empty body received');
            wp_die('Empty body', 'Payme S2S', array('response' => 400));
        }

        // Get signature from header
        $signature = '';
        if (isset($_SERVER['HTTP_SIGNATURE'])) {
            $signature = $_SERVER['HTTP_SIGNATURE'];
        }

        if (empty($signature)) {
            WC_Payme_Logger::error('S2S: Missing signature header');
            wp_die('Missing signature', 'Payme S2S', array('response' => 400));
        }

        // Get gateway settings for environment
        $gateway = new WC_Payme_Gateway();

        // Verify signature
        if (!$this->verify_s2s_signature($raw_body, $signature, $gateway->environment)) {
            WC_Payme_Logger::error('S2S: Invalid signature');
            wp_die('Invalid signature', 'Payme S2S', array('response' => 403));
        }

        // Parse body
        $data = json_decode($raw_body, true);

        if (!$data || !is_array($data)) {
            WC_Payme_Logger::error('S2S: Invalid JSON body');
            wp_die('Invalid JSON', 'Payme S2S', array('response' => 400));
        }

        if ($gateway->debug_mode === 'yes') {
            WC_Payme_Logger::log('S2S notification received: ' . $raw_body);
        }

        // Extract fields
        $merchant_operation_number = isset($data['merchant_operation_number']) ? sanitize_text_field($data['merchant_operation_number']) : '';
        $merchant_code = isset($data['merchant_code']) ? sanitize_text_field($data['merchant_code']) : '';
        $success = isset($data['success']) ? $data['success'] : false;
        $transaction_state = isset($data['transaction']['state']) ? $data['transaction']['state'] : '';
        $transaction_id = isset($data['transaction']['transaction_id']) ? sanitize_text_field($data['transaction']['transaction_id']) : '';
        $amount = isset($data['transaction']['amount']) ? $data['transaction']['amount'] : '';
        $status_code = isset($data['meta']['status']['code']) ? $data['meta']['status']['code'] : '';

        if (empty($merchant_operation_number)) {
            WC_Payme_Logger::error('S2S: Missing merchant_operation_number');
            // Still respond 200 to avoid retries for malformed data
            status_header(200);
            echo 'OK';
            exit;
        }

        // Verify merchant_code matches our config
        if (!empty($merchant_code) && $merchant_code !== $gateway->merchant_code) {
            WC_Payme_Logger::error('S2S: merchant_code mismatch. Expected: ' . $gateway->merchant_code . ', Got: ' . $merchant_code);
            status_header(200);
            echo 'OK';
            exit;
        }

        // Find order by operation number
        global $wpdb;
        $transactions_table = $wpdb->prefix . 'payme_transactions';
        $transaction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $transactions_table WHERE merchant_operation_number = %s LIMIT 1",
            $merchant_operation_number
        ));

        if (!$transaction) {
            WC_Payme_Logger::error('S2S: Transaction not found for operation ' . $merchant_operation_number);
            status_header(200);
            echo 'OK';
            exit;
        }

        $order_id = (int) $transaction->order_id;
        if (!$order_id) {
            WC_Payme_Logger::error('S2S: No order_id for operation ' . $merchant_operation_number);
            status_header(200);
            echo 'OK';
            exit;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            WC_Payme_Logger::error('S2S: Order not found: ' . $order_id);
            status_header(200);
            echo 'OK';
            exit;
        }

        // Only process if order is on-hold or pending (async payment waiting)
        $current_status = $order->get_status();
        if (!in_array($current_status, array('on-hold', 'pending'), true)) {
            if ($gateway->debug_mode === 'yes') {
                WC_Payme_Logger::log('S2S: Order ' . $order_id . ' already in status "' . $current_status . '", skipping.');
            }
            status_header(200);
            echo 'OK';
            exit;
        }

        // Determine if payment was successful
        $is_authorized = ($transaction_state === 'AUTORIZADO')
            || ($success === true || $success === 'true')
            || ($status_code === '00');

        if ($is_authorized) {
            // Update order to processing
            $order->payment_complete($transaction_id);
            $order->add_order_note(sprintf(
                __('Pago confirmado vía notificación S2S de Payme. ID transacción: %s. Operación: %s', 'payme-gateway'),
                $transaction_id ?: 'N/A',
                $merchant_operation_number
            ));

            // Update transaction record
            $wpdb->update(
                $transactions_table,
                array(
                    'status' => 'completed',
                    'response_data' => wp_json_encode($data),
                ),
                array('merchant_operation_number' => $merchant_operation_number),
                array('%s', '%s'),
                array('%s')
            );

            if ($gateway->debug_mode === 'yes') {
                WC_Payme_Logger::log('S2S: Order ' . $order_id . ' updated to processing (AUTORIZADO)');
            }
        } else {
            // Payment denied
            $order->update_status('failed', sprintf(
                __('Pago denegado vía notificación S2S de Payme. Estado: %s. Código: %s', 'payme-gateway'),
                $transaction_state ?: 'N/A',
                $status_code ?: 'N/A'
            ));

            $wpdb->update(
                $transactions_table,
                array(
                    'status' => 'failed',
                    'response_data' => wp_json_encode($data),
                ),
                array('merchant_operation_number' => $merchant_operation_number),
                array('%s', '%s'),
                array('%s')
            );

            if ($gateway->debug_mode === 'yes') {
                WC_Payme_Logger::log('S2S: Order ' . $order_id . ' marked as failed. State: ' . $transaction_state);
            }
        }

        // Always respond 200 within 10 seconds
        status_header(200);
        echo 'OK';
        exit;
    }

    /**
     * Verify RSA SHA512 signature from Alignet
     */
    private function verify_s2s_signature($raw_body, $signature_b64, $environment) {
        $public_key_b64 = ($environment === 'production')
            ? self::ALIGNET_PUBLIC_KEY_PRODUCTION
            : self::ALIGNET_PUBLIC_KEY_SANDBOX;

        $pem = "-----BEGIN PUBLIC KEY-----\n" . wordwrap($public_key_b64, 64, "\n", true) . "\n-----END PUBLIC KEY-----";

        $public_key = openssl_pkey_get_public($pem);
        if (!$public_key) {
            WC_Payme_Logger::error('S2S: Failed to parse public key');
            return false;
        }

        $signature_bin = base64_decode($signature_b64, true);
        if ($signature_bin === false) {
            WC_Payme_Logger::error('S2S: Failed to decode base64 signature');
            return false;
        }

        $result = openssl_verify($raw_body, $signature_bin, $public_key, OPENSSL_ALGO_SHA512);

        return ($result === 1);
    }

    // ─── LEGACY CALLBACK HANDLERS ───────────────────────────────

    /**
     * Handle payment callback from frontend (POST only)
     */
    public function handle_callback() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'payme_checkout_nonce')) {
            wp_die('Security check failed', 'Payme Gateway', array('response' => 403));
        }

        $response_data = json_decode(stripslashes($_POST['response_data'] ?? ''), true);
        $order_id = intval($_POST['order_id'] ?? 0);

        if (!$response_data || !$order_id) {
            wp_die('Invalid data', 'Payme Gateway', array('response' => 400));
        }

        $this->process_payment_response_data($order_id, $response_data);

        wp_die('OK', 'Payme Gateway', array('response' => 200));
    }

    /**
     * Process payment response via AJAX
     */
    public function process_payment_response() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'payme_checkout_nonce')) {
            wp_send_json_error('Security check failed');
        }

        $response_data = json_decode(stripslashes($_POST['response_data'] ?? ''), true);
        $order_id = intval($_POST['order_id'] ?? 0);

        if (!$response_data || !$order_id) {
            wp_send_json_error('Invalid data');
        }

        $result = $this->process_payment_response_data($order_id, $response_data);
        
        if ($result) {
            $order = wc_get_order($order_id);
            wp_send_json_success(array(
                'redirect_url' => $order->get_checkout_order_received_url()
            ));
        } else {
            wp_send_json_error('Payment processing failed');
        }
    }

    /**
     * Process payment response data
     */
    private function process_payment_response_data($order_id, $response_data) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            WC_Payme_Logger::log('Order not found: ' . $order_id);
            return false;
        }

        $gateway = new WC_Payme_Gateway();
        
        try {
            $this->update_transaction($order_id, $response_data);

            if ($gateway->debug_mode === 'yes') {
                WC_Payme_Logger::log('Payment response for order ' . $order_id . ': ' . wp_json_encode($response_data));
            }

            $status_code = $this->get_status_code($response_data);
            
            if ($status_code === '00') {
                $this->handle_successful_payment($order, $response_data);
                return true;
            } else {
                $this->handle_failed_payment($order, $response_data, $status_code);
                return false;
            }

        } catch (Exception $e) {
            WC_Payme_Logger::log('Error processing payment response: ' . $e->getMessage());
            $order->update_status('failed', __('Error procesando respuesta de pago: ', 'payme-gateway') . $e->getMessage());
            return false;
        }
    }

    private function get_status_code($response_data) {
        if (isset($response_data['authorization']['meta']['status']['code'])) {
            return $response_data['authorization']['meta']['status']['code'];
        }
        if (isset($response_data['meta']['status']['code'])) {
            return $response_data['meta']['status']['code'];
        }
        if (isset($response_data['status']['code'])) {
            return $response_data['status']['code'];
        }
        if (isset($response_data['code'])) {
            return $response_data['code'];
        }
        return '99';
    }

    private function handle_successful_payment($order, $response_data) {
        $transaction_id = $this->get_transaction_id($response_data);
        
        if ($transaction_id) {
            $order->set_transaction_id($transaction_id);
        }

        $note = __('Pago completado exitosamente con Payme.', 'payme-gateway');
        if ($transaction_id) {
            $note .= ' ' . sprintf(__('ID de transacción: %s', 'payme-gateway'), $transaction_id);
        }
        
        $order->add_order_note($note);
        $order->payment_complete($transaction_id);
        wc_reduce_stock_levels($order->get_id());
        WC()->cart->empty_cart();
        
        WC_Payme_Logger::log('Payment completed successfully for order ' . $order->get_id());
    }

    private function handle_failed_payment($order, $response_data, $status_code) {
        $error_message = $this->get_error_message($response_data, $status_code);
        
        $note = sprintf(
            __('Pago falló con Payme. Código: %s. Mensaje: %s', 'payme-gateway'),
            $status_code,
            $error_message
        );
        
        $order->add_order_note($note);
        $order->update_status('failed', $note);
        
        WC_Payme_Logger::log('Payment failed for order ' . $order->get_id() . '. Code: ' . $status_code);
    }

    private function get_transaction_id($response_data) {
        if (isset($response_data['authorization']['transaction_id'])) {
            return $response_data['authorization']['transaction_id'];
        }
        if (isset($response_data['transaction_id'])) {
            return $response_data['transaction_id'];
        }
        if (isset($response_data['id'])) {
            return $response_data['id'];
        }
        return null;
    }

    private function get_error_message($response_data, $status_code) {
        if (isset($response_data['authorization']['meta']['status']['message_ilgn'])) {
            $messages = $response_data['authorization']['meta']['status']['message_ilgn'];
            if (is_array($messages) && !empty($messages)) {
                return $messages[0]['value'] ?? 'Error desconocido';
            }
        }
        if (isset($response_data['meta']['status']['message_ilgn'])) {
            $messages = $response_data['meta']['status']['message_ilgn'];
            if (is_array($messages) && !empty($messages)) {
                return $messages[0]['value'] ?? 'Error desconocido';
            }
        }
        if (isset($response_data['message'])) {
            return $response_data['message'];
        }
        return $this->get_generic_error_message($status_code);
    }

    private function get_generic_error_message($status_code) {
        $error_messages = array(
            '01' => __('Contactar al banco', 'payme-gateway'),
            '05' => __('Pago no aceptado por el emisor', 'payme-gateway'),
            '12' => __('Transacción inválida', 'payme-gateway'),
            '13' => __('Monto inválido', 'payme-gateway'),
            '14' => __('Número de cuenta inválido', 'payme-gateway'),
            '51' => __('Fondos insuficientes', 'payme-gateway'),
            '54' => __('Tarjeta expirada', 'payme-gateway'),
            '55' => __('PIN incorrecto', 'payme-gateway'),
            '57' => __('Transacción no permitida', 'payme-gateway'),
            '91' => __('Emisor no disponible', 'payme-gateway'),
            '96' => __('Error de sistema', 'payme-gateway'),
        );
        return $error_messages[$status_code] ?? __('Error en el procesamiento del pago', 'payme-gateway');
    }

    private function update_transaction($order_id, $response_data) {
        global $wpdb;
        $transactions_table = $wpdb->prefix . 'payme_transactions';
        $status_code = $this->get_status_code($response_data);
        $status = ($status_code === '00') ? 'success' : 'failed';
        
        $wpdb->update(
            $transactions_table,
            array(
                'status' => $status,
                'response_data' => wp_json_encode($response_data)
            ),
            array('order_id' => $order_id),
            array('%s', '%s'),
            array('%d')
        );
    }
}
