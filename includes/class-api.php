<?php
/**
 * REST API — 替代 SSH 读信号文件
 * 
 * 商户通过 API Key 认证，访问自己的信号消息。
 * 外部 poller 通过 Master Key 写入消息。
 */

class FZ_Signal_API {

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        // 公开：健康检查
        register_rest_route('fz-signal/v1', '/health', [
            'methods' => 'GET',
            'callback' => [$this, 'health'],
            'permission_callback' => '__return_true',
        ]);

        // 商户：读取自己的消息
        register_rest_route('fz-signal/v1', '/messages', [
            'methods' => 'GET',
            'callback' => [$this, 'get_messages'],
            'permission_callback' => [$this, 'auth_merchant'],
        ]);

        // 商户：清空已读消息
        register_rest_route('fz-signal/v1', '/messages/clear', [
            'methods' => 'POST',
            'callback' => [$this, 'clear_messages'],
            'permission_callback' => [$this, 'auth_merchant'],
        ]);

        // 外部：写入消息（供 poller 调用）
        register_rest_route('fz-signal/v1', '/messages', [
            'methods' => 'POST',
            'callback' => [$this, 'write_message'],
            'permission_callback' => [$this, 'auth_master'],
        ]);
    }

    /**
     * API Key 认证（商户用）
     */
    public function auth_merchant() {
        $key = $this->get_api_key();
        if (!$key) return false;

        global $wpdb;
        $merchant = $wpdb->get_row($wpdb->prepare(
            "SELECT id, merchant_name, bot_name FROM {$wpdb->prefix}fz_signal_merchants 
             WHERE api_key = %s AND status = 'active'",
            $key
        ));

        if (!$merchant) return false;
        
        // 存入当前请求上下文
        wp_cache_set('fz_signal_merchant', $merchant, 'fz_signal');
        return true;
    }

    /**
     * Master Key 认证（poller 用）
     */
    public function auth_master() {
        $key = $this->get_api_key();
        if (!$key) return false;
        // 优先检查常量，其次检查 wp_option
        if (defined('FZ_SIGNAL_MASTER_KEY') && $key === FZ_SIGNAL_MASTER_KEY) {
            return true;
        }
        return $key === get_option('fz_signal_master_key');
    }

    private function get_api_key() {
        // PHP-FPM下用 $_SERVER，兼容 Nginx
        foreach (['HTTP_X_API_KEY', 'X-API-Key', 'REDIRECT_HTTP_X_API_KEY'] as $k) {
            if (!empty($_SERVER[$k])) {
                return $_SERVER[$k];
            }
        }
        // 备用：getallheaders()
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

    /**
     * GET /fz-signal/v1/health
     */
    public function health() {
        global $wpdb;
        $merchant_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fz_signal_merchants");
        $unread = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fz_signal_messages WHERE is_read = 0");
        return [
            'status' => 'ok',
            'version' => FZ_SIGNAL_VERSION,
            'merchants' => intval($merchant_count),
            'unread' => intval($unread),
        ];
    }

    /**
     * GET /fz-signal/v1/messages
     * 读取当前商户的未读消息
     */
    public function get_messages() {
        $merchant = wp_cache_get('fz_signal_merchant', 'fz_signal');
        if (!$merchant) {
            return new WP_Error('auth_failed', '认证失败', ['status' => 401]);
        }

        global $wpdb;
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT id, message_id, sender, text, created_at, is_read 
             FROM {$wpdb->prefix}fz_signal_messages 
             WHERE merchant_id = %d 
             ORDER BY created_at DESC LIMIT 50",
            $merchant->id
        ));

        return [
            'merchant' => $merchant->merchant_name,
            'bot_name' => $merchant->bot_name,
            'messages' => $messages,
            'total' => count($messages),
        ];
    }

    /**
     * POST /fz-signal/v1/messages/clear
     * 清空当前商户的消息
     */
    public function clear_messages() {
        $merchant = wp_cache_get('fz_signal_merchant', 'fz_signal');
        if (!$merchant) {
            return new WP_Error('auth_failed', '认证失败', ['status' => 401]);
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'fz_signal_messages',
            ['is_read' => 1],
            ['merchant_id' => $merchant->id, 'is_read' => 0]
        );

        return ['status' => 'ok', 'message' => '已清空'];
    }

    /**
     * POST /fz-signal/v1/messages
     * poller 写入消息
     */
    public function write_message($request) {
        $bot_name = sanitize_text_field($request->get_param('bot_name'));
        $sender = sanitize_text_field($request->get_param('sender'));
        $text = sanitize_textarea_field($request->get_param('text'));
        $msg_id = sanitize_text_field($request->get_param('message_id'));
        $created_at = sanitize_text_field($request->get_param('created_at'));

        if (!$bot_name || !$text) {
            return new WP_Error('invalid', '缺少 bot_name 或 text', ['status' => 400]);
        }

        global $wpdb;

        // 查找匹配的商户
        $merchant = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fz_signal_merchants 
             WHERE bot_name = %s AND status = 'active'",
            $bot_name
        ));

        if (!$merchant) {
            return ['status' => 'skipped', 'message' => "无商户匹配 bot_name: $bot_name"];
        }

        // 去重
        if ($msg_id) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fz_signal_messages WHERE message_id = %s",
                $msg_id
            ));
            if ($exists) {
                return ['status' => 'duplicate', 'message' => '消息已存在'];
            }
        }

        $wpdb->insert($wpdb->prefix . 'fz_signal_messages', [
            'merchant_id' => $merchant->id,
            'message_id' => $msg_id ?: uniqid('msg_'),
            'sender' => $sender ?: 'unknown',
            'text' => $text,
            'created_at' => $created_at ?: current_time('mysql'),
        ]);

        return [
            'status' => 'ok',
            'merchant_id' => $merchant->id,
            'message' => '消息已写入',
        ];
    }
}
