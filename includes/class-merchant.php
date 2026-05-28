<?php
/**
 * 商户管理
 */
class FZ_Signal_Merchant {

    public function __construct() {
        // 预留 hook
    }

    /**
     * 添加商户
     */
    public function create($data) {
        global $wpdb;
        $name = sanitize_text_field($data['merchant_name'] ?? '');
        $email = sanitize_email($data['contact_email'] ?? '');
        $phone = sanitize_text_field($data['contact_phone'] ?? '');
        $plan = in_array($data['plan'] ?? '', ['basic', 'pro', 'enterprise']) ? $data['plan'] : 'basic';

        if (!$name) {
            return new WP_Error('invalid_name', '商户名称不能为空');
        }

        $agent_limit = $this->get_plan_limit($plan);

        $wpdb->insert($wpdb->prefix . 'fz_merchants', [
            'merchant_name' => $name,
            'contact_email' => $email,
            'contact_phone' => $phone,
            'plan'          => $plan,
            'agent_limit'   => $agent_limit,
            'api_key'       => fz_signal_generate_key(),
            'status'        => 'active',
        ]);

        $id = $wpdb->insert_id;
        if (!$id) {
            return new WP_Error('db_error', '创建商户失败');
        }

        do_action('fz_signal_log', 'merchant_create', 'merchant', $id, 0, [
            'merchant_name' => $name, 'plan' => $plan
        ]);

        return $id;
    }

    /**
     * 获取所有商户
     */
    public function get_all($limit = 50, $offset = 0) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT m.*,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}fz_agents WHERE merchant_id = m.id) as agent_count,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}fz_messages WHERE merchant_id = m.id AND is_read = 0) as unread,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}fz_messages WHERE merchant_id = m.id AND acked = 0) as unacked
                FROM {$wpdb->prefix}fz_merchants m
                ORDER BY m.created_at DESC
                LIMIT %d OFFSET %d",
                $limit, $offset
            )
        );
    }

    public function get_count() {
        global $wpdb;
        return intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fz_merchants"));
    }

    /**
     * 根据 ID 获取商户
     */
    public function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fz_merchants WHERE id = %d", $id
        ));
    }

    /**
     * 根据 API Key 获取商户
     */
    public function get_by_key($key) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT id, merchant_name, plan, agent_limit, status FROM {$wpdb->prefix}fz_merchants WHERE api_key = %s AND status = 'active'",
            $key
        ));
    }

    /**
     * 更新商户
     */
    public function update($id, $data) {
        global $wpdb;
        $update = [];
        if (isset($data['merchant_name'])) $update['merchant_name'] = sanitize_text_field($data['merchant_name']);
        if (isset($data['contact_email'])) $update['contact_email'] = sanitize_email($data['contact_email']);
        if (isset($data['contact_phone'])) $update['contact_phone'] = sanitize_text_field($data['contact_phone']);
        if (isset($data['plan'])) {
            $update['plan'] = in_array($data['plan'], ['basic', 'pro', 'enterprise']) ? $data['plan'] : 'basic';
            $update['agent_limit'] = $this->get_plan_limit($update['plan']);
        }
        if (isset($data['status'])) $update['status'] = sanitize_text_field($data['status']);

        if (empty($update)) return true;

        $wpdb->update($wpdb->prefix . 'fz_merchants', $update, ['id' => $id]);

        do_action('fz_signal_log', 'merchant_update', 'merchant', $id, $id, [
            'changes' => array_keys($update)
        ]);

        return true;
    }

    /**
     * 删除商户
     */
    public function delete($id) {
        global $wpdb;
        do_action('fz_signal_log', 'merchant_delete', 'merchant', $id, $id, [
            'merchant_name' => $this->get($id)->merchant_name ?? ''
        ]);
        return $wpdb->delete($wpdb->prefix . 'fz_merchants', ['id' => $id]);
    }

    /**
     * 重置 API Key
     */
    public function regenerate_key($id) {
        global $wpdb;
        $new_key = fz_signal_generate_key();
        $wpdb->update($wpdb->prefix . 'fz_merchants', ['api_key' => $new_key], ['id' => $id]);

        do_action('fz_signal_log', 'merchant_regenerate_key', 'merchant', $id, $id);

        return $new_key;
    }

    /**
     * 根据套餐获取 Agent 上限
     */
    public function get_plan_limit($plan) {
        $limits = [
            'basic'      => 3,
            'pro'        => 10,
            'enterprise' => 9999,
        ];
        return $limits[$plan] ?? 3;
    }
}
