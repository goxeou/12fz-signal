<?php
/**
 * REST API — 商业版信号中台
 *
 * 商户 API:     https://ai.12fz.com/api/v1/*
 * 系统 API:     https://ai.12fz.com/api/v1/* (Master Key)
 * 管理后台:     https://ai.12fz.com/wp-json/fz-signal/v1/admin/*
 */

class FZ_Signal_API {

    private $merchant;
    private $agent;

    public function __construct() {
        $this->merchant = new FZ_Signal_Merchant();
        $this->agent    = new FZ_Signal_Agent();
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        // ── 公开 ──
        register_rest_route('fz-signal/v1', '/health', [
            'methods'             => 'GET',
            'callback'            => [$this, 'health'],
            'permission_callback' => '__return_true',
        ]);

        // ── 商户 API：消息 ──
        register_rest_route('fz-signal/v1', '/messages', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_messages'],
                'permission_callback' => [$this, 'auth_merchant'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'write_message'],
                'permission_callback' => [$this, 'auth_master'],
            ],
        ]);
        register_rest_route('fz-signal/v1', '/messages/ack', [
            'methods'             => 'POST',
            'callback'            => [$this, 'ack_messages'],
            'permission_callback' => [$this, 'auth_merchant'],
        ]);
        register_rest_route('fz-signal/v1', '/messages/clear', [
            'methods'             => 'POST',
            'callback'            => [$this, 'clear_messages'],
            'permission_callback' => [$this, 'auth_merchant'],
        ]);

        // ── 商户 API：Agent ──
        register_rest_route('fz-signal/v1', '/agents', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_agents'],
            'permission_callback' => [$this, 'auth_merchant'],
        ]);
        register_rest_route('fz-signal/v1', '/agents/(?P<id>\d+)/heartbeat', [
            'methods'             => 'GET',
            'callback'            => [$this, 'agent_heartbeat_status'],
            'permission_callback' => [$this, 'auth_merchant'],
        ]);

        // ── 系统 API：Agent 注册 / 心跳上报 ──
        register_rest_route('fz-signal/v1', '/agents/register', [
            'methods'             => 'POST',
            'callback'            => [$this, 'register_agent'],
            'permission_callback' => [$this, 'auth_master'],
        ]);
        register_rest_route('fz-signal/v1', '/agents/(?P<id>\d+)/heartbeat', [
            'methods'             => 'POST',
            'callback'            => [$this, 'report_heartbeat'],
            'permission_callback' => [$this, 'auth_agent'],
        ]);
        register_rest_route('fz-signal/v1', '/agents/offline', [
            'methods'             => 'GET',
            'callback'            => [$this, 'offline_agents'],
            'permission_callback' => [$this, 'auth_master'],
        ]);

        // ── 管理后台（admin） ──
        // 商户
        register_rest_route('fz-signal/v1', '/admin/merchants', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'admin_merchants_list'],
                'permission_callback' => [$this, 'auth_admin'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'admin_merchants_create'],
                'permission_callback' => [$this, 'auth_admin'],
            ],
        ]);
        register_rest_route('fz-signal/v1', '/admin/merchants/(?P<id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [$this, 'admin_merchants_update'],
                'permission_callback' => [$this, 'auth_admin'],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'admin_merchants_delete'],
                'permission_callback' => [$this, 'auth_admin'],
            ],
        ]);
        register_rest_route('fz-signal/v1', '/admin/merchants/(?P<id>\d+)/regenerate-key', [
            'methods'             => 'POST',
            'callback'            => [$this, 'admin_merchants_regenerate_key'],
            'permission_callback' => [$this, 'auth_admin'],
        ]);

        // Agent
        register_rest_route('fz-signal/v1', '/admin/agents', [
            'methods'             => 'GET',
            'callback'            => [$this, 'admin_agents_list'],
            'permission_callback' => [$this, 'auth_admin'],
        ]);
        register_rest_route('fz-signal/v1', '/admin/agents/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'admin_agents_delete'],
            'permission_callback' => [$this, 'auth_admin'],
        ]);

        // 消息
        register_rest_route('fz-signal/v1', '/admin/messages', [
            'methods'             => 'GET',
            'callback'            => [$this, 'admin_messages_list'],
            'permission_callback' => [$this, 'auth_admin'],
        ]);

        // 工作流
        register_rest_route('fz-signal/v1', '/admin/workflows', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'admin_workflows_list'],
                'permission_callback' => [$this, 'auth_admin'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'admin_workflows_create'],
                'permission_callback' => [$this, 'auth_admin'],
            ],
        ]);
        register_rest_route('fz-signal/v1', '/admin/workflows/(?P<id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [$this, 'admin_workflows_update'],
                'permission_callback' => [$this, 'auth_admin'],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'admin_workflows_delete'],
                'permission_callback' => [$this, 'auth_admin'],
            ],
        ]);

        // 计费
        register_rest_route('fz-signal/v1', '/admin/billing', [
            'methods'             => 'GET',
            'callback'            => [$this, 'admin_billing_summary'],
            'permission_callback' => [$this, 'auth_admin'],
        ]);

        // 审计日志
        register_rest_route('fz-signal/v1', '/admin/logs', [
            'methods'             => 'GET',
            'callback'            => [$this, 'admin_logs_list'],
            'permission_callback' => [$this, 'auth_admin'],
        ]);

        // 全局设置
        register_rest_route('fz-signal/v1', '/admin/settings', [
            'methods'             => 'GET',
            'callback'            => [$this, 'admin_settings'],
            'permission_callback' => [$this, 'auth_admin'],
        ]);
    }

    // ═════════════════════════════════════════════
    //  认证
    // ═════════════════════════════════════════════

    /**
     * 商户 API Key 认证
     */
    public function auth_merchant() {
        $key = $this->get_api_key();
        if (!$key) return false;
        $merchant = $this->merchant->get_by_key($key);
        if (!$merchant) return false;
        wp_cache_set('fz_signal_merchant', $merchant, 'fz_signal');
        return true;
    }

    /**
     * Master Key 认证
     */
    public function auth_master() {
        $key = $this->get_api_key();
        if (!$key) return false;
        if (defined('FZ_SIGNAL_MASTER_KEY') && $key === FZ_SIGNAL_MASTER_KEY) {
            return true;
        }
        return $key === get_option('fz_signal_master_key');
    }

    /**
     * Agent 自身认证（用商户 Key 或 Agent Token）
     */
    public function auth_agent() {
        // 先用商户 Key 认证
        if ($this->auth_merchant()) return true;
        // 再用 Master Key
        return $this->auth_master();
    }

    /**
     * 管理员认证
     */
    public function auth_admin() {
        return current_user_can('manage_options');
    }

    private function get_api_key() {
        foreach (['HTTP_X_API_KEY', 'X-API-Key', 'REDIRECT_HTTP_X_API_KEY'] as $k) {
            if (!empty($_SERVER[$k])) {
                return $_SERVER[$k];
            }
        }
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach (['X-API-Key', 'x-api-key'] as $h) {
                if (!empty($headers[$h])) {
                    return $headers[$h];
                }
            }
        }
        return '';
    }

    // ═════════════════════════════════════════════
    //  公开端点
    // ═════════════════════════════════════════════

    public function health() {
        global $wpdb;
        $merchant_count = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fz_merchants"));
        $agent_count    = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fz_agents"));
        $unread         = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fz_messages WHERE is_read = 0"));
        return [
            'status'    => 'ok',
            'version'   => FZ_SIGNAL_VERSION,
            'merchants' => $merchant_count,
            'agents'    => $agent_count,
            'unread'    => $unread,
        ];
    }

    // ═════════════════════════════════════════════
    //  商户 API
    // ═════════════════════════════════════════════

    public function get_messages() {
        $merchant = fz_signal_get_merchant();
        if (!$merchant) return new WP_Error('auth_failed', '认证失败', ['status' => 401]);

        global $wpdb;
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT m.id, m.message_id, a.agent_name, m.platform, m.sender, m.text,
                    m.msg_type, m.is_read, m.acked, m.created_at
             FROM {$wpdb->prefix}fz_messages m
             JOIN {$wpdb->prefix}fz_agents a ON m.agent_id = a.id
             WHERE m.merchant_id = %d AND m.acked = 0
             ORDER BY m.created_at DESC LIMIT 50",
            $merchant->id
        ));

        return [
            'merchant'     => $merchant->merchant_name,
            'plan'         => $merchant->plan,
            'messages'     => $messages,
            'total'        => count($messages),
        ];
    }

    public function ack_messages($request) {
        $merchant = fz_signal_get_merchant();
        if (!$merchant) return new WP_Error('auth_failed', '认证失败', ['status' => 401]);

        $ids = $request->get_param('ids');
        global $wpdb;

        if (is_array($ids) && !empty($ids)) {
            $ids = array_map('intval', $ids);
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}fz_messages SET acked = 1, polled_at = %s
                 WHERE merchant_id = %d AND id IN ($placeholders)",
                array_merge([current_time('mysql'), $merchant->id], $ids)
            ));
        } else {
            // 全部确认
            $wpdb->update(
                $wpdb->prefix . 'fz_messages',
                ['acked' => 1, 'polled_at' => current_time('mysql')],
                ['merchant_id' => $merchant->id, 'acked' => 0]
            );
        }

        return ['status' => 'ok', 'message' => '已确认'];
    }

    public function clear_messages() {
        $merchant = fz_signal_get_merchant();
        if (!$merchant) return new WP_Error('auth_failed', '认证失败', ['status' => 401]);

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'fz_messages',
            ['is_read' => 1, 'polled_at' => current_time('mysql')],
            ['merchant_id' => $merchant->id, 'is_read' => 0]
        );

        return ['status' => 'ok', 'message' => '已清空'];
    }

    public function get_agents() {
        $merchant = fz_signal_get_merchant();
        if (!$merchant) return new WP_Error('auth_failed', '认证失败', ['status' => 401]);

        $agents = $this->agent->get_by_merchant($merchant->id);
        return [
            'merchant' => $merchant->merchant_name,
            'agents'   => $agents,
            'total'    => count($agents),
        ];
    }

    public function agent_heartbeat_status($request) {
        $merchant = fz_signal_get_merchant();
        if (!$merchant) return new WP_Error('auth_failed', '认证失败', ['status' => 401]);

        $agent = $this->agent->get(intval($request->get_param('id')));
        if (!$agent || $agent->merchant_id != $merchant->id) {
            return new WP_Error('not_found', 'Agent 不存在', ['status' => 404]);
        }

        $offline_threshold = time() - 120;
        $is_online = $agent->last_heartbeat && strtotime($agent->last_heartbeat) > $offline_threshold;

        return [
            'agent_id'      => $agent->id,
            'agent_name'    => $agent->agent_name,
            'status'        => $is_online ? 'online' : 'offline',
            'last_heartbeat' => $agent->last_heartbeat,
        ];
    }

    // ═════════════════════════════════════════════
    //  系统 API
    // ═════════════════════════════════════════════

    public function write_message($request) {
        $bot_name  = sanitize_text_field($request->get_param('bot_name'));
        $sender    = sanitize_text_field($request->get_param('sender'));
        $text      = sanitize_textarea_field($request->get_param('text'));
        $msg_id    = sanitize_text_field($request->get_param('message_id'));
        $platform  = sanitize_text_field($request->get_param('platform') ?: 'feishu');
        $created   = sanitize_text_field($request->get_param('created_at'));

        if (!$bot_name || !$text) {
            return new WP_Error('invalid', '缺少 bot_name 或 text', ['status' => 400]);
        }

        global $wpdb;

        // 查找匹配的 Agent
        $agent = $this->agent->get_by_bot($bot_name, $platform);
        if (!$agent) {
            return ['status' => 'skipped', 'message' => "无匹配 Agent: {$bot_name}@{$platform}"];
        }

        // 去重
        if ($msg_id) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fz_messages WHERE message_id = %s",
                $msg_id
            ));
            if ($exists) {
                return ['status' => 'duplicate', 'message' => '消息已存在'];
            }
        }

        $wpdb->insert($wpdb->prefix . 'fz_messages', [
            'agent_id'    => $agent->id,
            'merchant_id' => $agent->merchant_id,
            'message_id'  => $msg_id ?: uniqid('msg_'),
            'platform'    => $platform,
            'sender'      => $sender ?: 'unknown',
            'text'        => $text,
            'msg_type'    => sanitize_text_field($request->get_param('msg_type') ?: 'text'),
            'created_at'  => $created ?: current_time('mysql'),
        ]);

        return [
            'status'      => 'ok',
            'agent_id'    => $agent->id,
            'merchant_id' => $agent->merchant_id,
            'message'     => '消息已写入',
        ];
    }

    public function register_agent($request) {
        $data = [
            'merchant_id' => intval($request->get_param('merchant_id')),
            'agent_name'  => $request->get_param('agent_name'),
            'bot_name'    => $request->get_param('bot_name'),
            'platform'    => $request->get_param('platform') ?: 'feishu',
            'tags'        => $request->get_param('tags'),
            'config'      => $request->get_param('config'),
        ];
        $result = $this->agent->register($data);
        if (is_wp_error($result)) {
            return $result;
        }
        return ['status' => 'ok', 'agent_id' => $result];
    }

    public function report_heartbeat($request) {
        $id = intval($request->get_param('id'));
        $this->agent->update_heartbeat($id);
        return ['status' => 'ok', 'agent_id' => $id];
    }

    public function offline_agents() {
        $offline = $this->agent->get_offline();
        return ['offline_count' => count($offline), 'agents' => $offline];
    }

    // ═════════════════════════════════════════════
    //  管理后台 API
    // ═════════════════════════════════════════════

    public function admin_merchants_list($request) {
        $page    = max(1, intval($request->get_param('page') ?: 1));
        $perpage = min(100, max(1, intval($request->get_param('per_page') ?: 20)));
        $offset  = ($page - 1) * $perpage;

        return [
            'merchants'  => $this->merchant->get_all($perpage, $offset),
            'total'      => $this->merchant->get_count(),
            'page'       => $page,
            'per_page'   => $perpage,
        ];
    }

    public function admin_merchants_create($request) {
        $result = $this->merchant->create($request->get_params());
        if (is_wp_error($result)) return $result;
        return ['status' => 'ok', 'merchant_id' => $result];
    }

    public function admin_merchants_update($request) {
        $id = intval($request->get_param('id'));
        $this->merchant->update($id, $request->get_params());
        return ['status' => 'ok'];
    }

    public function admin_merchants_delete($request) {
        $id = intval($request->get_param('id'));
        $this->merchant->delete($id);
        return ['status' => 'ok', 'message' => '已删除'];
    }

    public function admin_merchants_regenerate_key($request) {
        $id = intval($request->get_param('id'));
        $new_key = $this->merchant->regenerate_key($id);
        return ['status' => 'ok', 'api_key' => $new_key];
    }

    public function admin_agents_list($request) {
        $page    = max(1, intval($request->get_param('page') ?: 1));
        $perpage = min(100, max(1, intval($request->get_param('per_page') ?: 20)));
        $offset  = ($page - 1) * $perpage;
        return ['agents' => $this->agent->get_all($perpage, $offset)];
    }

    public function admin_agents_delete($request) {
        $id = intval($request->get_param('id'));
        $this->agent->delete($id);
        return ['status' => 'ok'];
    }

    public function admin_messages_list($request) {
        global $wpdb;
        $page    = max(1, intval($request->get_param('page') ?: 1));
        $perpage = min(100, max(1, intval($request->get_param('per_page') ?: 20)));
        $offset  = ($page - 1) * $perpage;
        $merchant_id = intval($request->get_param('merchant_id') ?: 0);

        $where = $merchant_id ? $wpdb->prepare('WHERE m.merchant_id = %d', $merchant_id) : '';
        $messages = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT m.*, a.agent_name, mr.merchant_name
                 FROM {$wpdb->prefix}fz_messages m
                 JOIN {$wpdb->prefix}fz_agents a ON m.agent_id = a.id
                 LEFT JOIN {$wpdb->prefix}fz_merchants mr ON m.merchant_id = mr.id
                 $where ORDER BY m.created_at DESC LIMIT %d OFFSET %d",
                $perpage, $offset
            )
        );
        return ['messages' => $messages, 'page' => $page, 'per_page' => $perpage];
    }

    public function admin_workflows_list() {
        return ['workflows' => (new FZ_Signal_Workflow())->get_all()];
    }

    public function admin_workflows_create($request) {
        $wf = new FZ_Signal_Workflow();
        $result = $wf->create($request->get_params());
        if (is_wp_error($result)) return $result;
        return ['status' => 'ok', 'workflow_id' => $result];
    }

    public function admin_workflows_update($request) {
        $id = intval($request->get_param('id'));
        (new FZ_Signal_Workflow())->update($id, $request->get_params());
        return ['status' => 'ok'];
    }

    public function admin_workflows_delete($request) {
        $id = intval($request->get_param('id'));
        (new FZ_Signal_Workflow())->delete($id);
        return ['status' => 'ok'];
    }

    public function admin_billing_summary() {
        return (new FZ_Signal_Billing())->get_summary();
    }

    public function admin_logs_list($request) {
        $page    = max(1, intval($request->get_param('page') ?: 1));
        $perpage = min(100, max(1, intval($request->get_param('per_page') ?: 50)));
        $offset  = ($page - 1) * $perpage;
        $merchant_id = intval($request->get_param('merchant_id') ?: 0);
        $audit = new FZ_Signal_Audit();

        global $wpdb;
        $where = $merchant_id ? $wpdb->prepare('WHERE merchant_id = %d', $merchant_id) : '';
        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fz_audit_log $where ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $perpage, $offset
            )
        );

        return ['logs' => $logs, 'page' => $page, 'per_page' => $perpage];
    }

    public function admin_settings() {
        return [
            'version'      => FZ_SIGNAL_VERSION,
            'master_key_set' => defined('FZ_SIGNAL_MASTER_KEY') || !empty(get_option('fz_signal_master_key')),
            'domain'       => 'https://ai.12fz.com',
            'api_prefix'   => '/api/v1',
            'plans'        => FZ_Signal_Billing::PLANS,
        ];
    }
}
