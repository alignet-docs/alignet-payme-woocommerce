<?php
/**
 * Payme Admin Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Payme_Admin {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_payme_test_connection', array($this, 'test_connection'));
        add_action('wp_ajax_payme_get_transaction_details', array($this, 'get_transaction_details'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Payme Transacciones', 'payme-gateway'),
            __('Payme Transacciones', 'payme-gateway'),
            'manage_woocommerce',
            'payme-transactions',
            array($this, 'transactions_page')
        );
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook === 'woocommerce_page_payme-transactions') {
            wp_enqueue_style('payme-admin', PAYME_GATEWAY_PLUGIN_URL . 'assets/css/payme-admin.css', array(), PAYME_GATEWAY_VERSION);
        }
    }

    /**
     * Transactions page
     */
    public function transactions_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No tienes permisos para acceder a esta página.', 'payme-gateway'));
        }

        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'transactions';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Payme Gateway', 'payme-gateway'); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=payme-transactions&tab=transactions" class="nav-tab <?php echo $current_tab === 'transactions' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Transacciones', 'payme-gateway'); ?>
                </a>
                <a href="?page=payme-transactions&tab=logs" class="nav-tab <?php echo $current_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Logs', 'payme-gateway'); ?>
                </a>
                <a href="?page=payme-transactions&tab=stats" class="nav-tab <?php echo $current_tab === 'stats' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Estadísticas', 'payme-gateway'); ?>
                </a>
            </nav>

            <div class="tab-content">
                <?php
                switch ($current_tab) {
                    case 'logs':
                        $this->render_logs_tab();
                        break;
                    case 'stats':
                        $this->render_stats_tab();
                        break;
                    default:
                        $this->render_transactions_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render transactions tab
     */
    private function render_transactions_tab() {
        global $wpdb;
        
        // Handle bulk actions with nonce verification
        if (isset($_POST['action']) && $_POST['action'] === 'bulk_delete' && isset($_POST['transaction_ids'])) {
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'payme_bulk_action')) {
                wp_die(esc_html__('Error de seguridad. Inténtalo nuevamente.', 'payme-gateway'));
            }
            $this->handle_bulk_delete($_POST['transaction_ids']);
        }
        
        $transactions_table = $wpdb->prefix . 'payme_transactions';
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Get filters
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
        
        // Build query
        $where_conditions = array('1=1');
        $where_values = array();
        
        if ($status_filter) {
            $where_conditions[] = 'status = %s';
            $where_values[] = $status_filter;
        }
        
        if ($date_from) {
            $where_conditions[] = 'created_at >= %s';
            $where_values[] = $date_from . ' 00:00:00';
        }
        
        if ($date_to) {
            $where_conditions[] = 'created_at <= %s';
            $where_values[] = $date_to . ' 23:59:59';
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        // Get total count
        $total_query = "SELECT COUNT(*) FROM $transactions_table WHERE $where_clause";
        if ($where_values) {
            $total_count = $wpdb->get_var($wpdb->prepare($total_query, $where_values));
        } else {
            $total_count = $wpdb->get_var($total_query);
        }
        
        // Get transactions
        $query = "SELECT * FROM $transactions_table WHERE $where_clause ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $query_values = array_merge($where_values, array($per_page, $offset));
        $transactions = $wpdb->get_results($wpdb->prepare($query, $query_values));
        
        ?>
        <div class="payme-transactions-wrapper">
            <!-- Filters -->
            <div class="payme-filters">
                <form method="get" action="">
                    <input type="hidden" name="page" value="payme-transactions">
                    <input type="hidden" name="tab" value="transactions">
                    
                    <select name="status">
                        <option value=""><?php esc_html_e('Todos los estados', 'payme-gateway'); ?></option>
                        <option value="pending" <?php selected($status_filter, 'pending'); ?>><?php esc_html_e('Pendiente', 'payme-gateway'); ?></option>
                        <option value="completed" <?php selected($status_filter, 'completed'); ?>><?php esc_html_e('Completado', 'payme-gateway'); ?></option>
                        <option value="failed" <?php selected($status_filter, 'failed'); ?>><?php esc_html_e('Fallido', 'payme-gateway'); ?></option>
                        <option value="refunded" <?php selected($status_filter, 'refunded'); ?>><?php esc_html_e('Reembolsado', 'payme-gateway'); ?></option>
                    </select>
                    
                    <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" placeholder="<?php esc_attr_e('Desde', 'payme-gateway'); ?>">
                    <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>" placeholder="<?php esc_attr_e('Hasta', 'payme-gateway'); ?>">
                    
                    <input type="submit" class="button" value="<?php esc_attr_e('Filtrar', 'payme-gateway'); ?>">
                    <a href="?page=payme-transactions&tab=transactions" class="button"><?php esc_html_e('Limpiar', 'payme-gateway'); ?></a>
                </form>
            </div>

            <!-- Transactions table -->
            <form method="post" action="">
                <?php wp_nonce_field('payme_bulk_action'); ?>
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="action">
                            <option value=""><?php esc_html_e('Acciones en lote', 'payme-gateway'); ?></option>
                            <option value="bulk_delete"><?php esc_html_e('Eliminar', 'payme-gateway'); ?></option>
                        </select>
                        <input type="submit" class="button action" value="<?php esc_attr_e('Aplicar', 'payme-gateway'); ?>">
                    </div>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="manage-column column-cb check-column">
                                <input type="checkbox" id="cb-select-all">
                            </td>
                            <th><?php esc_html_e('ID Orden', 'payme-gateway'); ?></th>
                            <th><?php esc_html_e('Número Operación', 'payme-gateway'); ?></th>
                            <th><?php esc_html_e('Monto', 'payme-gateway'); ?></th>
                            <th><?php esc_html_e('Estado', 'payme-gateway'); ?></th>
                            <th><?php esc_html_e('Fecha', 'payme-gateway'); ?></th>
                            <th><?php esc_html_e('Acciones', 'payme-gateway'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($transactions): ?>
                            <?php foreach ($transactions as $transaction): ?>
                                <tr>
                                    <th class="check-column">
                                        <input type="checkbox" name="transaction_ids[]" value="<?php echo esc_attr($transaction->id); ?>">
                                    </th>
                                    <td>
                                        <a href="<?php echo esc_url(admin_url('post.php?post=' . $transaction->order_id . '&action=edit')); ?>">
                                            #<?php echo esc_html($transaction->order_id); ?>
                                        </a>
                                    </td>
                                    <td><?php echo esc_html($transaction->merchant_operation_number); ?></td>
                                    <td>
                                        <?php 
                                        $amount_class = $transaction->amount < 0 ? 'refund-amount' : '';
                                        echo '<span class="' . $amount_class . '">';
                                        echo wc_price(abs($transaction->amount), array('currency' => $transaction->currency));
                                        echo '</span>';
                                        ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo esc_attr($transaction->status); ?>">
                                            <?php echo esc_html($this->get_status_label($transaction->status)); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($transaction->created_at))); ?></td>
                                    <td>
                                        <button type="button" class="button button-small view-details" data-transaction-id="<?php echo esc_attr($transaction->id); ?>">
                                            <?php esc_html_e('Ver Detalles', 'payme-gateway'); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 20px;">
                                    <?php esc_html_e('No se encontraron transacciones.', 'payme-gateway'); ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>

            <!-- Pagination -->
            <?php if ($total_count > $per_page): ?>
                <div class="tablenav bottom">
                    <?php
                    $total_pages = ceil($total_count / $per_page);
                    $pagination_args = array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                        'total' => $total_pages,
                        'current' => $current_page
                    );
                    echo paginate_links($pagination_args);
                    ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Transaction details modal -->
        <div id="transaction-details-modal" style="display: none;">
            <div class="modal-content">
                <span class="close">&times;</span>
                <div id="transaction-details-content"></div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Select all checkbox
            $('#cb-select-all').change(function() {
                $('input[name="transaction_ids[]"]').prop('checked', this.checked);
            });
            
            // View details
            $('.view-details').click(function() {
                var transactionId = $(this).data('transaction-id');
                // Load transaction details via AJAX
                $.post(ajaxurl, {
                    action: 'payme_get_transaction_details',
                    transaction_id: transactionId,
                    nonce: '<?php echo wp_create_nonce('payme_transaction_details'); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#transaction-details-content').html(response.data);
                        $('#transaction-details-modal').show();
                    }
                });
            });
            
            // Close modal
            $('.close').click(function() {
                $('#transaction-details-modal').hide();
            });
        });
        </script>
        <?php
    }

    /**
     * Render logs tab
     */
    private function render_logs_tab() {
        global $wpdb;

        $logs_table = $wpdb->prefix . 'payme_logs';

        // Handle clear logs BEFORE rendering (with nonce verification)
        if (isset($_POST['action']) && $_POST['action'] === 'clear_logs') {
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'payme_clear_logs')) {
                wp_die(esc_html__('Error de seguridad. Inténtalo nuevamente.', 'payme-gateway'));
            }
            $wpdb->query("TRUNCATE TABLE $logs_table");
            echo '<div class="notice notice-success"><p>' . esc_html__('Logs eliminados exitosamente.', 'payme-gateway') . '</p></div>';
        }

        $per_page = 50;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;

        // Get logs
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $logs_table ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));

        $total_count = $wpdb->get_var("SELECT COUNT(*) FROM $logs_table");

        ?>
        <div class="payme-logs-wrapper">
            <div class="logs-actions">
                <form method="post" action="" style="display: inline;">
                    <?php wp_nonce_field('payme_clear_logs'); ?>
                    <input type="hidden" name="action" value="clear_logs">
                    <input type="submit" class="button button-secondary" value="<?php esc_attr_e('Limpiar Logs', 'payme-gateway'); ?>" 
                           onclick="return confirm('<?php esc_attr_e('¿Estás seguro de que quieres eliminar todos los logs?', 'payme-gateway'); ?>')">
                </form>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Fecha', 'payme-gateway'); ?></th>
                        <th><?php esc_html_e('Nivel', 'payme-gateway'); ?></th>
                        <th><?php esc_html_e('Mensaje', 'payme-gateway'); ?></th>
                        <th><?php esc_html_e('Contexto', 'payme-gateway'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($logs): ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log->created_at))); ?></td>
                                <td>
                                    <span class="log-level log-level-<?php echo esc_attr($log->level); ?>">
                                        <?php echo esc_html(strtoupper($log->level)); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($log->message); ?></td>
                                <td>
                                    <?php if ($log->context): ?>
                                        <button type="button" class="button button-small view-context" data-context="<?php echo esc_attr($log->context); ?>">
                                            <?php esc_html_e('Ver', 'payme-gateway'); ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 20px;">
                                <?php esc_html_e('No hay logs disponibles.', 'payme-gateway'); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render stats tab
     */
    private function render_stats_tab() {
        global $wpdb;
        
        $transactions_table = $wpdb->prefix . 'payme_transactions';
        
        // Get stats for last 30 days
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total_transactions,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_transactions,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_transactions,
                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_amount,
                AVG(CASE WHEN status = 'completed' THEN amount ELSE NULL END) as avg_amount
            FROM $transactions_table 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        
        // Get daily stats for chart
        $daily_stats = $wpdb->get_results("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as transactions,
                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as amount
            FROM $transactions_table 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ");
        
        ?>
        <div class="payme-stats-wrapper">
            <div class="stats-cards">
                <div class="stat-card">
                    <h3><?php esc_html_e('Transacciones Totales', 'payme-gateway'); ?></h3>
                    <div class="stat-number"><?php echo number_format($stats->total_transactions); ?></div>
                    <div class="stat-period"><?php esc_html_e('Últimos 30 días', 'payme-gateway'); ?></div>
                </div>
                
                <div class="stat-card success">
                    <h3><?php esc_html_e('Transacciones Exitosas', 'payme-gateway'); ?></h3>
                    <div class="stat-number"><?php echo number_format($stats->completed_transactions); ?></div>
                    <div class="stat-percentage">
                        <?php 
                        $success_rate = $stats->total_transactions > 0 ? ($stats->completed_transactions / $stats->total_transactions) * 100 : 0;
                        echo number_format($success_rate, 1) . '%';
                        ?>
                    </div>
                </div>
                
                <div class="stat-card error">
                    <h3><?php esc_html_e('Transacciones Fallidas', 'payme-gateway'); ?></h3>
                    <div class="stat-number"><?php echo number_format($stats->failed_transactions); ?></div>
                    <div class="stat-percentage">
                        <?php 
                        $failure_rate = $stats->total_transactions > 0 ? ($stats->failed_transactions / $stats->total_transactions) * 100 : 0;
                        echo number_format($failure_rate, 1) . '%';
                        ?>
                    </div>
                </div>
                
                <div class="stat-card">
                    <h3><?php esc_html_e('Monto Total', 'payme-gateway'); ?></h3>
                    <div class="stat-number"><?php echo wc_price($stats->total_amount); ?></div>
                    <div class="stat-period"><?php esc_html_e('Últimos 30 días', 'payme-gateway'); ?></div>
                </div>
                
                <div class="stat-card">
                    <h3><?php esc_html_e('Monto Promedio', 'payme-gateway'); ?></h3>
                    <div class="stat-number"><?php echo wc_price($stats->avg_amount ?: 0); ?></div>
                    <div class="stat-period"><?php esc_html_e('Por transacción', 'payme-gateway'); ?></div>
                </div>
            </div>
            
            <div class="stats-chart">
                <h3><?php esc_html_e('Transacciones Diarias', 'payme-gateway'); ?></h3>
                <table class="wp-list-table widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Fecha', 'payme-gateway'); ?></th>
                            <th><?php esc_html_e('Transacciones', 'payme-gateway'); ?></th>
                            <th><?php esc_html_e('Monto', 'payme-gateway'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($daily_stats): ?>
                            <?php foreach ($daily_stats as $day): ?>
                                <tr>
                                    <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($day->date))); ?></td>
                                    <td><?php echo number_format($day->transactions); ?></td>
                                    <td><?php echo wc_price($day->amount); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" style="text-align: center; padding: 20px;">
                                    <?php esc_html_e('No hay datos disponibles.', 'payme-gateway'); ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Get status label
     */
    private function get_status_label($status) {
        $labels = array(
            'pending' => __('Pendiente', 'payme-gateway'),
            'completed' => __('Completado', 'payme-gateway'),
            'failed' => __('Fallido', 'payme-gateway'),
            'refunded' => __('Reembolsado', 'payme-gateway')
        );
        
        return isset($labels[$status]) ? $labels[$status] : $status;
    }

    /**
     * Handle bulk delete
     */
    private function handle_bulk_delete($transaction_ids) {
        global $wpdb;
        
        if (!is_array($transaction_ids)) {
            return;
        }
        
        $transaction_ids = array_map('intval', $transaction_ids);
        $placeholders = implode(',', array_fill(0, count($transaction_ids), '%d'));
        
        $transactions_table = $wpdb->prefix . 'payme_transactions';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $transactions_table WHERE id IN ($placeholders)",
            $transaction_ids
        ));
        
        echo '<div class="notice notice-success"><p>' . 
             sprintf(__('%d transacciones eliminadas.', 'payme-gateway'), count($transaction_ids)) . 
             '</p></div>';
    }

    /**
     * Test connection AJAX handler
     */
    public function test_connection() {
        check_ajax_referer('payme_test_connection', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('No tienes permisos para realizar esta acción.', 'payme-gateway'));
        }
        
        $gateway = new WC_Payme_Gateway();
        $access_token = $gateway->get_access_token();
        
        if ($access_token) {
            wp_send_json_success(array(
                'message' => __('Conexión exitosa con Payme.', 'payme-gateway')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Error de conexión. Verifica tus credenciales.', 'payme-gateway')
            ));
        }
    }
    
    /**
     * Get transaction details AJAX handler
     */
    public function get_transaction_details() {
        check_ajax_referer('payme_transaction_details', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('No tienes permisos para realizar esta acción.', 'payme-gateway'));
        }
        
        $transaction_id = intval($_POST['transaction_id']);
        
        global $wpdb;
        $transactions_table = $wpdb->prefix . 'payme_transactions';
        
        $transaction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $transactions_table WHERE id = %d",
            $transaction_id
        ));
        
        if (!$transaction) {
            wp_send_json_error(array(
                'message' => __('Transacción no encontrada.', 'payme-gateway')
            ));
        }
        
        $order = wc_get_order($transaction->order_id);
        
        ob_start();
        ?>
        <div class="transaction-details">
            <h3><?php esc_html_e('Detalles de la Transacción', 'payme-gateway'); ?></h3>
            
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('ID Transacción:', 'payme-gateway'); ?></th>
                    <td><?php echo esc_html($transaction->id); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Orden:', 'payme-gateway'); ?></th>
                    <td>
                        <a href="<?php echo esc_url(admin_url('post.php?post=' . $transaction->order_id . '&action=edit')); ?>" target="_blank">
                            #<?php echo esc_html($transaction->order_id); ?>
                        </a>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Número de Operación:', 'payme-gateway'); ?></th>
                    <td><?php echo esc_html($transaction->merchant_operation_number); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Monto:', 'payme-gateway'); ?></th>
                    <td><?php echo wc_price($transaction->amount, array('currency' => $transaction->currency)); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Estado:', 'payme-gateway'); ?></th>
                    <td>
                        <span class="status-badge status-<?php echo esc_attr($transaction->status); ?>">
                            <?php echo esc_html($this->get_status_label($transaction->status)); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Fecha de Creación:', 'payme-gateway'); ?></th>
                    <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($transaction->created_at))); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Última Actualización:', 'payme-gateway'); ?></th>
                    <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($transaction->updated_at))); ?></td>
                </tr>
                <?php if ($order): ?>
                <tr>
                    <th><?php esc_html_e('Cliente:', 'payme-gateway'); ?></th>
                    <td><?php echo esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Email:', 'payme-gateway'); ?></th>
                    <td><?php echo esc_html($order->get_billing_email()); ?></td>
                </tr>
                <?php endif; ?>
            </table>
            
            <?php if ($transaction->request_data): ?>
            <h4><?php esc_html_e('Datos de Solicitud', 'payme-gateway'); ?></h4>
            <pre style="background: #f9f9f9; padding: 10px; border: 1px solid #ddd; max-height: 200px; overflow-y: auto;"><?php echo esc_html(wp_json_encode(json_decode($transaction->request_data), JSON_PRETTY_PRINT)); ?></pre>
            <?php endif; ?>
            
            <?php if ($transaction->response_data): ?>
            <h4><?php esc_html_e('Datos de Respuesta', 'payme-gateway'); ?></h4>
            <pre style="background: #f9f9f9; padding: 10px; border: 1px solid #ddd; max-height: 200px; overflow-y: auto;"><?php echo esc_html(wp_json_encode(json_decode($transaction->response_data), JSON_PRETTY_PRINT)); ?></pre>
            <?php endif; ?>
        </div>
        <?php
        $content = ob_get_clean();
        
        wp_send_json_success($content);
    }
}