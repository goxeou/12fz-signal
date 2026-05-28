<?php
/**
 * Agent 管理
 */
class FZ_Signal_Agent {

    /**
     * 注册 Agent
     */
    public function register($data) {
        global $wpdb;
        $merchant_id = intval($data['merchant_id'] ?? 0);
        $agent_name  = sanitize_text_field($data['agent_name'] ?? '');
        $bot_name    = sanitize_text_field($data['bot_name'] ?? '');
        $platform    = sanitize_text_field($data['platform'] ?? 'feishu');

        if (!$merchant_id || !$agent_name) {
            return new WP_Error('invalid', '缺少 merchant_id 或 agent_name');
        }

        // 检查商户套餐上限
        $merchant = new FZ_Signal_Merchant();
        $m = $merchant->get($merchant_id);
        if (!$m) {
            return new WP_Error('not_found', '商户不存在');
        }

        $count = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fz_agents WHERE merchant_id = %d",
            $merchant_id
        )));
        if ($count >= $m->agent_limit) {
            return new WP_Error('limit_exceeded', 'Agent 数量已达套餐上限 (' . $m->agent_limit . ')');
        }

        // 检查 bot_name 重复
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fz_agents WHERE bot_name = %s AND platform = %s",
            $bot_name, $platform
        ));
        if ($existing) {
            return new WP_Error('duplicate', "bot_name '{$bot_name}' 已存在");
        }

        $wpdb->insert($wpdb->prefix . 'fz_agents', [
            'merchant_id' => $merchant_id,
            'agent_name'  => $agent_name,
            'bot_name'    => $bot_name,
            'platform'    => $platform,
            'status'      => 'active',
            'tags'        => !empty($data['tags']) ? wp_json_encode($data['tags']) : '',
            'config'      => !empty($data['config']) ? wp_json_encode($data['config']) : '',
        ]);

        $id = $wpdb->insert_id;

        do_action('fz_signal_log', 'agent_register', 'agent', $id, $merchant_id, [
            'agent_name' => $agent_name, 'bot_name' => $bot_name, 'platform' => $platform
        ]);

        return $id;
    }

    /**
     * 获取商户的 Agent 列表
     */
    public function get_by_merchant($merchant_id, $limit = 50, $offset = 0) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT *,
                (SELECT COUNT(*) FROM {$wpdb->prefix}fz_messages WHERE agent_id = fz_agents.id AND is_read = 0) as unread
            FROM {$wpdb->prefix}fz_agents
            WHERE merchant_id = %d
            ORDER BY created_at DESC
            LIMIT %d OFFSET %d",
            $merchant_id, $limit, $offset
        ));
    }

    /**
     * 获取所有 Agent
     */
    public function get_all($limit = 50, $offset = 0) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.*, m.merchant_name FROM {$wpdb->prefix}fz_agents a
                LEFT JOIN {$wpdb->prefix}fz_merchants m ON a.merchant_id = m.id
                ORDER BY a.created_at DESC LIMIT %d OFFSET %d",
                $limit, $offset
            )
        );
    }

    public function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fz_agents WHERE id = %d", $id
        ));
    }

    /**
     * 根据 bot_name 查找 Agent
     */
    public function get_by_bot($bot_name, $platform = 'feishu') {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, m.api_key, m.merchant_name, m.plan
            FROM {$wpdb->prefix}fz_agents a
            JOIN {$wpdb->prefix}fz_merchants m ON a.merchant_id = m.id
            WHERE a.bot_name = %s AND a.platform = %s AND a.status = 'active' AND m.status = 'active'",
            $bot_name, $platform
        ));
    }

    /**
     * 更新心跳时间
     */
    public function update_heartbeat($id) {
        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . 'fz_agents',
            ['last_heartbeat' => current_time('mysql')],
            ['id' => $id]
        );
    }

    /**
     * 删除 Agent
     */
    public function delete($id) {
        global $wpdb;
        $agent = $this->get($id);
        if (!$agent) return false;
        do_action('fz_signal_log', 'agent_delete', 'agent', $id, $agent->merchant_id, [
            'agent_name' => $agent->agent_name
        ]);
        return $wpdb->delete($wpdb->prefix . 'fz_agents', ['id' => $id]);
    }

    /**
     * 获取离线 Agent
     */
    public function get_offline($timeout_minutes = 2) {
        global $wpdb;
        $deadline = date('Y-m-d H:i:s', time() - $timeout_minutes * 60);
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.*, m.merchant_name FROM {$wpdb->prefix}fz_agents a
                LEFT JOIN {$wpdb->prefix}fz_merchants m ON a.merchant_id = m.id
                WHERE a.last_heartbeat IS NULL OR a.last_heartbeat < %s",
                $deadline
            )
        );
    }
}
