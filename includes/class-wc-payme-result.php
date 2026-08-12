<?php
/**
 * Unified Pay-me payment result component.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Payme_Result
{
    public function __construct()
    {
        add_action('woocommerce_thankyou_payme', array($this, 'render'), 5, 1);
        add_action('woocommerce_thankyou_paymecheckout', array($this, 'render'), 5, 1);
        add_filter('woocommerce_thankyou_order_received_text', array($this, 'remove_default_message'), 10, 2);
        add_filter('body_class', array($this, 'add_body_class'));
    }

    /**
     * Whether an order-received request belongs to Pay-me.
     */
    public static function is_payme_result_request($order_id)
    {
        if (!$order_id || !function_exists('wc_get_order')) {
            return false;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }

        return in_array($order->get_payment_method(), array('payme', 'paymecheckout'), true);
    }

    public function add_body_class($classes)
    {
        $order_id = $this->get_request_order_id();
        if (self::is_payme_result_request($order_id)) {
            $classes[] = 'payme-result-page';
        }
        return $classes;
    }

    public function remove_default_message($message, $order)
    {
        if ($order && in_array($order->get_payment_method(), array('payme', 'paymecheckout'), true)) {
            return '';
        }
        return $message;
    }

    public function render($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order || !in_array($order->get_payment_method(), array('payme', 'paymecheckout'), true)) {
            return;
        }

        $data = $this->get_result_data($order);
        $template = PAYME_GATEWAY_PLUGIN_DIR . 'templates/payment-result.php';

        if (is_file($template)) {
            include $template;
        }
    }

    private function get_request_order_id()
    {
        $order_id = isset($_GET['order-received']) ? absint($_GET['order-received']) : 0;
        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if (!$order_id && preg_match('/order-received\/(\d+)/', $request_uri, $matches)) {
            $order_id = absint($matches[1]);
        }
        return $order_id;
    }

    private function get_result_data($order)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'payme_transactions';
        $transaction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table
             WHERE order_id = %d
               AND merchant_operation_number REGEXP '^[0-9]{7,12}$'
             ORDER BY id DESC LIMIT 1",
            $order->get_id()
        ));

        $response = array();
        if ($transaction && !empty($transaction->response_data)) {
            $decoded = json_decode($transaction->response_data, true);
            if (is_array($decoded)) {
                $response = $decoded;
            }
        }

        $raw_state = strtoupper((string) $this->first_value($response, array(
            array('transaction', 'state'),
            array('state'),
        ), ''));
        $state = $this->normalize_state($raw_state, $order, $transaction);
        $state_content = $this->get_state_content($state);

        $transaction_id = (string) $this->first_value($response, array(
            array('transaction', 'transaction_id'),
            array('transaction_id'),
            array('authorization', 'transaction_id'),
        ), $order->get_transaction_id());

        $authorization_code = (string) $this->first_value($response, array(
            array('transaction', 'authorization_code'),
            array('authorization', 'authorization_code'),
            array('authorization_code'),
        ), '');

        $payment_method = $this->resolve_payment_method($response, $order);
        $currency = (string) $this->first_value($response, array(
            array('transaction', 'currency'),
            array('currency'),
        ), $transaction ? $transaction->currency : $order->get_currency());
        $currency = $this->normalize_currency($currency, $order->get_currency());

        $response_amount = $this->first_value($response, array(
            array('transaction', 'amount'),
            array('amount'),
        ), null);
        $amount = $this->normalize_amount($response_amount, $transaction, $order);

        $default_return_url = wc_get_page_permalink('shop');
        if (!$default_return_url) {
            $default_return_url = home_url('/');
        }

        $primary_color = apply_filters('payme_result_primary_color', '', $order, $response);
        if ($primary_color !== '') {
            $primary_color = sanitize_hex_color($primary_color);
            if (!$primary_color) {
                $primary_color = '';
            }
        }

        $custom_classes = apply_filters('payme_result_custom_css_class', '', $order, $response);
        $custom_classes = array_filter(array_map('sanitize_html_class', preg_split('/\s+/', (string) $custom_classes)));

        $data = array(
            'state' => $state,
            'state_label' => $state_content['label'],
            'title' => $state_content['title'],
            'description' => $state_content['description'],
            'icon' => $state_content['icon'],
            'merchant_operation_number' => $transaction ? (string) $transaction->merchant_operation_number : (string) $order->get_meta('_payme_merchant_operation_number'),
            'transaction_id' => $transaction_id,
            'authorization_code' => $authorization_code,
            'amount_html' => wc_price($amount, array('currency' => $currency)),
            'payment_method' => $payment_method,
            'expiration_date' => (string) $this->first_value($response, array(
                array('transaction', 'expiration_date'),
                array('expiration_date'),
                array('payment_method', 'method_details', 'expiration_date'),
            ), ''),
            'continue_url' => (string) $this->first_value($response, array(
                array('transaction', 'continue_url'),
                array('continue_url'),
                array('payment_method', 'method_details', 'continue_url'),
            ), ''),
            'qr_image' => $this->sanitize_qr_image((string) $this->first_value($response, array(
                array('transaction', 'qr_image'),
                array('qr_image'),
                array('payment_method', 'method_details', 'qr_image'),
            ), '')),
            'qr_id' => (string) $this->first_value($response, array(
                array('transaction', 'qr_id'),
                array('qr_id'),
                array('payment_method', 'method_details', 'qr_id'),
            ), ''),
            'cip' => (string) $this->first_value($response, array(
                array('transaction', 'CIP'),
                array('transaction', 'cip'),
                array('CIP'),
                array('cip'),
                array('payment_method', 'method_details', 'CIP'),
                array('payment_method', 'method_details', 'cip'),
            ), ''),
            'cip_url' => (string) $this->first_value($response, array(
                array('transaction', 'cip_url'),
                array('cip_url'),
                array('payment_method', 'method_details', 'cip_url'),
            ), ''),
            // No payment-provider branding is shown by default. A merchant may
            // opt in to its own logo through the existing presentation filter.
            'logo' => apply_filters('payme_result_logo', '', $order, $response),
            'return_button_text' => apply_filters('payme_result_return_button_text', __('Volver a la tienda', 'payme-gateway'), $order, $response),
            'return_url' => apply_filters('payme_result_return_url', $default_return_url, $order, $response),
            'primary_color' => $primary_color,
            'custom_classes' => $custom_classes,
        );

        /**
         * Filter the presentation-safe data passed to the result template.
         * Technical authentication fields are intentionally never included.
         */
        return apply_filters('payme_result_data', $data, $order, $response);
    }

    private function first_value($data, $paths, $default = '')
    {
        foreach ($paths as $path) {
            $value = $data;
            foreach ($path as $key) {
                if (!is_array($value) || !array_key_exists($key, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$key];
            }
            if ($value !== null && $value !== '' && !is_array($value)) {
                return $value;
            }
        }
        return $default;
    }

    private function normalize_state($raw_state, $order, $transaction)
    {
        if (in_array($raw_state, array('EXTORNADO', 'REVERSED', 'REFUNDED'), true) || $order->has_status('refunded')) {
            return 'reversed';
        }
        if (in_array($raw_state, array('AUTORIZADO', 'AUTHORIZED', 'APPROVED'), true)) {
            return 'authorized';
        }
        if (in_array($raw_state, array('PENDIENTE', 'PENDING', 'REVIEW', 'EN_PROCESO', 'REGISTRADO'), true)) {
            return 'pending';
        }
        if (in_array($raw_state, array('DENEGADO', 'INVALIDO', 'DENIED', 'REJECTED', 'FAILED'), true)) {
            return 'denied';
        }

        // Legacy records may predate transaction.state storage. Fall back to
        // persisted financial/order state, never to HTTP success.
        if ($order->has_status(array('on-hold', 'pending'))) {
            return 'pending';
        }
        if ($order->has_status(array('failed', 'cancelled'))) {
            return 'denied';
        }
        if (($transaction && in_array($transaction->status, array('completed', 'success'), true)) || $order->has_status(array('processing', 'completed'))) {
            return 'authorized';
        }
        return 'denied';
    }

    private function get_state_content($state)
    {
        $content = array(
            'authorized' => array(
                'label' => __('Autorizado', 'payme-gateway'),
                'title' => __('¡Pago autorizado!', 'payme-gateway'),
                'description' => __('Tu pago fue procesado correctamente.', 'payme-gateway'),
                'icon' => 'check',
            ),
            'pending' => array(
                'label' => __('Pendiente', 'payme-gateway'),
                'title' => __('Pago pendiente', 'payme-gateway'),
                'description' => __('Tu operación fue registrada. Sigue las instrucciones para completar el pago.', 'payme-gateway'),
                'icon' => 'clock',
            ),
            'denied' => array(
                'label' => __('No autorizado', 'payme-gateway'),
                'title' => __('Pago no autorizado', 'payme-gateway'),
                'description' => __('No se pudo autorizar el pago. Puedes intentarlo nuevamente.', 'payme-gateway'),
                'icon' => 'close',
            ),
            'reversed' => array(
                'label' => __('Extornado', 'payme-gateway'),
                'title' => __('Pago extornado', 'payme-gateway'),
                'description' => __('La operación fue revertida.', 'payme-gateway'),
                'icon' => 'reverse',
            ),
        );
        return $content[$state];
    }

    private function normalize_currency($currency, $fallback)
    {
        $map = array('604' => 'PEN', '840' => 'USD', '188' => 'CRC', '590' => 'PAB');
        $currency = strtoupper(trim((string) $currency));
        if (isset($map[$currency])) {
            return $map[$currency];
        }
        return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : $fallback;
    }

    private function sanitize_qr_image($source)
    {
        $source = trim((string) $source);
        if (preg_match('#^data:image/(png|jpeg|jpg|webp|gif);base64,[A-Za-z0-9+/=\r\n]+$#', $source)) {
            return $source;
        }
        return esc_url_raw($source, array('http', 'https'));
    }

    private function normalize_amount($response_amount, $transaction, $order)
    {
        $stored_amount = $transaction ? (float) $transaction->amount : (float) $order->get_total();
        if (!is_numeric($response_amount)) {
            return $stored_amount;
        }

        $response_amount = (float) $response_amount;
        if (abs($response_amount - ($stored_amount * 100)) < 0.01) {
            return $response_amount / 100;
        }
        if (abs($response_amount - $stored_amount) < 0.01) {
            return $response_amount;
        }
        return $response_amount / 100;
    }

    private function resolve_payment_method($response, $order)
    {
        $method = $this->first_value($response, array(
            array('transaction', 'payment_method'),
            array('payment_method', 'name'),
            array('payment_method', 'code'),
            array('payment_method'),
        ), $order->get_meta('_payme_payment_method'));
        $method = strtoupper(trim((string) $method));

        $labels = array(
            'CARD' => __('Tarjeta de crédito/débito', 'payme-gateway'),
            'YAPE' => __('Yape', 'payme-gateway'),
            'BANK_TRANSFER' => __('Transferencia bancaria', 'payme-gateway'),
            'QR' => __('Código QR', 'payme-gateway'),
            'CUOTEALO' => __('Cuotéalo', 'payme-gateway'),
            'PAGOEFECTIVO' => __('PagoEfectivo', 'payme-gateway'),
            'SAFETYPAY' => __('Transferencia bancaria', 'payme-gateway'),
            'CASH' => __('PagoEfectivo', 'payme-gateway'),
        );

        if (isset($labels[$method])) {
            return $labels[$method];
        }
        $stored_label = $order->get_meta('_payme_payment_method_label');
        if ($stored_label) {
            return $stored_label;
        }

        // Legacy payment titles may contain the provider name. Keep the
        // customer-facing result neutral while retaining the internal gateway.
        return trim(preg_replace('/^Pay-?me\s*(?:—|-|:)\s*/i', '', $order->get_payment_method_title()));
    }
}
