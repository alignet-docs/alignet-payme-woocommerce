<?php
/**
 * Payme Logger Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Payme_Logger {

    /**
     * Log messages to database
     */
    public static function log($message, $level = 'info', $context = array()) {
        global $wpdb;

        $logs_table = $wpdb->prefix . 'payme_logs';

        // Verify table exists before inserting (prevents errors if plugin wasn't activated properly)
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $logs_table)) !== $logs_table) {
            // Fall back to WooCommerce logger only
            if (function_exists('wc_get_logger')) {
                $logger = wc_get_logger();
                $logger->log($level, $message, array('source' => 'payme-gateway'));
            }
            return;
        }

        // Insert into custom logs table
        $wpdb->insert(
            $logs_table,
            array(
                'level' => $level,
                'message' => $message,
                'context' => !empty($context) ? wp_json_encode($context) : null
            )
        );

        // Also log to WooCommerce logger if available
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->log($level, $message, array('source' => 'payme-gateway'));
        }
    }

    /**
     * Log debug messages
     */
    public static function debug($message, $context = array()) {
        self::log($message, 'debug', $context);
    }

    /**
     * Log info messages
     */
    public static function info($message, $context = array()) {
        self::log($message, 'info', $context);
    }

    /**
     * Log warning messages
     */
    public static function warning($message, $context = array()) {
        self::log($message, 'warning', $context);
    }

    /**
     * Log error messages
     */
    public static function error($message, $context = array()) {
        self::log($message, 'error', $context);
    }

    /**
     * Get log entries for admin display
     */
    public static function get_logs($limit = 50, $level = null) {
        global $wpdb;
        
        $logs_table = $wpdb->prefix . 'payme_logs';
        
        $where_clause = '1=1';
        $where_values = array();
        
        if ($level) {
            $where_clause .= ' AND level = %s';
            $where_values[] = $level;
        }
        
        $query = "SELECT * FROM $logs_table WHERE $where_clause ORDER BY created_at DESC LIMIT %d";
        $where_values[] = $limit;
        
        return $wpdb->get_results($wpdb->prepare($query, $where_values));
    }

    /**
     * Clear old logs
     */
    public static function clear_logs($days_old = 30) {
        global $wpdb;
        
        $logs_table = $wpdb->prefix . 'payme_logs';
        
        if ($days_old > 0) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $logs_table WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days_old
            ));
        } else {
            // Clear all logs
            $wpdb->query("TRUNCATE TABLE $logs_table");
        }
    }

    /**
     * Log transaction data
     */
    public static function log_transaction($order_id, $operation_number, $action, $data = array()) {
        $message = sprintf(
            'Transaction %s - Order: %d, Operation: %s',
            $action,
            $order_id,
            $operation_number
        );
        
        $context = array(
            'order_id' => $order_id,
            'operation_number' => $operation_number,
            'action' => $action,
            'data' => $data
        );
        
        self::info($message, $context);
    }

    /**
     * Log API request
     */
    public static function log_api_request($url, $method, $request_data, $response_data = null) {
        $message = sprintf('API Request - %s %s', $method, $url);
        
        $context = array(
            'url' => $url,
            'method' => $method,
            'request' => $request_data,
            'response' => $response_data
        );
        
        self::debug($message, $context);
    }

    /**
     * Log payment status change
     */
    public static function log_payment_status($order_id, $old_status, $new_status, $reason = '') {
        $message = sprintf(
            'Payment Status Change - Order: %d, From: %s, To: %s',
            $order_id,
            $old_status,
            $new_status
        );
        
        $context = array(
            'order_id' => $order_id,
            'old_status' => $old_status,
            'new_status' => $new_status,
            'reason' => $reason
        );
        
        self::info($message, $context);
    }

    /**
     * Get log statistics
     */
    public static function get_log_stats() {
        global $wpdb;
        
        $logs_table = $wpdb->prefix . 'payme_logs';
        
        return $wpdb->get_row("
            SELECT 
                COUNT(*) as total_logs,
                SUM(CASE WHEN level = 'error' THEN 1 ELSE 0 END) as error_count,
                SUM(CASE WHEN level = 'warning' THEN 1 ELSE 0 END) as warning_count,
                SUM(CASE WHEN level = 'info' THEN 1 ELSE 0 END) as info_count,
                SUM(CASE WHEN level = 'debug' THEN 1 ELSE 0 END) as debug_count,
                MAX(created_at) as last_log_date
            FROM $logs_table
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
    }
}