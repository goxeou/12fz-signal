<?php
/**
 * 工作流管理
 */
class FZ_Signal_Workflow {

    /**
     * 创建工作流
     */
    public function create($data) {
        global $wpdb;
        $merchant_id  = intval($data['merchant_id'] ?? 0);
        $name         = sanitize_text_field($data['name'] ?? '');
        $trigger_type = sanitize_text_field($data['trigger_type'] ?? '');
        $steps        = $data['steps'] ?? [];

        if (!$merchant_id || !$name || !$trigger_type || empty($steps)) {
            return new WP_Error('invalid', '缺少必填字段');
        }

        $allowed_triggers = ['message_match', 'cron', 'webhook', 'agent_offline'];
        if (!in_array($trigger_type, $allowed_triggers)) {
            return new WP_Error('invalid_trigger', '无效的触发类型');
        }

        $wpdb->insert($wpdb->prefix . 'fz_workflows', [
            'merchant_id'    => $merchant_id,
            'name'           => $name,
            'description'    => sanitize_textarea_field($data['description'] ?? ''),
            'trigger_type'   => $trigger_type,
            'trigger_config' => !empty($data['trigger_config']) ? wp_json_encode($data['trigger_config']) : '',
            'steps'          => wp_json_encode($steps),
            'status'         => $data['status'] ?? 'active',
        ]);

        $id = $wpdb->insert_id;

        do_action('fz_signal_log', 'workflow_create', 'workflow', $id, $merchant_id, [
            'name' => $name, 'trigger_type' => $trigger_type
        ]);

        return $id;
    }

    /**
     * 获取商户的工作流列表
     */
    public function get_by_merchant($merchant_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fz_workflows WHERE merchant_id = %d ORDER BY created_at DESC",
            $merchant_id
        ));
    }

    public function get_all($limit = 50, $offset = 0) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT w.*, m.merchant_name FROM {$wpdb->prefix}fz_workflows w
                LEFT JOIN {$wpdb->prefix}fz_merchants m ON w.merchant_id = m.id
                ORDER BY w.created_at DESC LIMIT %d OFFSET %d",
                $limit, $offset
            )
        );
    }

    public function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fz_workflows WHERE id = %d", $id
        ));
    }

    /**
     * 更新工作流
     */
    public function update($id, $data) {
        global $wpdb;
        $update = [];
        if (isset($data['name']))           $update['name'] = sanitize_text_field($data['name']);
        if (isset($data['description']))    $update['description'] = sanitize_textarea_field($data['description']);
        if (isset($data['trigger_type']))   $update['trigger_type'] = sanitize_text_field($data['trigger_type']);
        if (isset($data['trigger_config'])) $update['trigger_config'] = wp_json_encode($data['trigger_config']);
        if (isset($data['steps']))          $update['steps'] = wp_json_encode($data['steps']);
        if (isset($data['status']))         $update['status'] = sanitize_text_field($data['status']);

        if (empty($update)) return true;

        $wpdb->update($wpdb->prefix . 'fz_workflows', $update, ['id' => $id]);

        $wf = $this->get($id);
        do_action('fz_signal_log', 'workflow_update', 'workflow', $id, $wf->merchant_id ?? 0, [
            'changes' => array_keys($update)
        ]);

        return true;
    }

    /**
     * 删除工作流
     */
    public function delete($id) {
        global $wpdb;
        $wf = $this->get($id);
        if (!$wf) return false;
        do_action('fz_signal_log', 'workflow_delete', 'workflow', $id, $wf->merchant_id, [
            'name' => $wf->name
        ]);
        return $wpdb->delete($wpdb->prefix . 'fz_workflows', ['id' => $id]);
    }

    /**
     * 触发工作流（预留，Go 核心实现）
     */
    public function trigger($workflow_id, $context = []) {
        $wf = $this->get($workflow_id);
        if (!$wf || $wf->status !== 'active') {
            return new WP_Error('invalid', '工作流不可用');
        }

        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'fz_workflow_logs', [
            'workflow_id'  => $workflow_id,
            'merchant_id'  => $wf->merchant_id,
            'trigger_msg'  => wp_json_encode($context),
            'status'       => 'pending',
        ]);

        return $wpdb->insert_id;
    }
}
