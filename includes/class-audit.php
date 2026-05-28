<?php
/**
 * 审计日志
 */
class FZ_Signal_Audit {

    public function __construct() {
        add_action('fz_signal_log', [$this, 'log'], 10, 5);
    }

    public function log($action, $resource_type = '', $resource_id = 0, $merchant_id = 0, $detail = []) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'fz_audit_log', [
            'merchant_id'   => intval($merchant_id),
            'admin_id'      => get_current_user_id() ?: 0,
            'action'        => sanitize_text_field($action),
            'resource_type' => sanitize_text_field($resource_type),
            'resource_id'   => intval($resource_id),
            'detail'        => wp_json_encode($detail),
            'ip_address'    => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    }

    public function get_logs($merchant_id = 0, $limit = 50, $offset = 0) {
        global $wpdb;
        $where = $merchant_id ? $wpdb->prepare('WHERE merchant_id = %d', $merchant_id) : '';
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fz_audit_log $where ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $limit, $offset
            )
        );
    }
}
