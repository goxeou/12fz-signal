<?php
/**
 * 后台管理页面
 */
class FZ_Signal_Admin {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_menu() {
        add_menu_page(
            '信号系统',
            '信号系统',
            'manage_options',
            'fz-signal',
            [$this, 'render_page'],
            'dashicons-randomize',
            80
        );
    }

    public function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_fz-signal') return;
        wp_enqueue_style('fz-signal-admin', FZ_SIGNAL_URL . 'assets/admin.css', [], FZ_SIGNAL_VERSION);
    }

    public function render_page() {
        global $wpdb;

        // 处理表单
        if (isset($_POST['add_merchant']) && check_admin_referer('fz_signal')) {
            $this->handle_add();
        }
        if (isset($_POST['delete_merchant']) && check_admin_referer('fz_signal')) {
            $this->handle_delete();
        }
        if (isset($_POST['regenerate_key']) && check_admin_referer('fz_signal')) {
            $this->handle_regenerate();
        }

        $merchants = $wpdb->get_results("SELECT m.*, 
            (SELECT COUNT(*) FROM {$wpdb->prefix}fz_signal_messages WHERE merchant_id = m.id AND is_read = 0) as unread
            FROM {$wpdb->prefix}fz_signal_merchants m ORDER BY m.created_at DESC");
        ?>
        <div class="wrap fz-signal-wrap">
            <h1>🤖 信号系统管理</h1>
            <p>管理商户授权，每个商户通过 API Key 访问自己的信号消息。</p>

            <hr>

            <h2>添加商户</h2>
            <form method="post" class="fz-signal-form">
                <?php wp_nonce_field('fz_signal'); ?>
                <table class="form-table">
                    <tr>
                        <th><label>商户名称</label></th>
                        <td><input type="text" name="merchant_name" required placeholder="如：张三的店"></td>
                    </tr>
                    <tr>
                        <th><label>Bot 名称</label></th>
                        <td><input type="text" name="bot_name" required placeholder="如：zhangsan_bot" 
                            title="飞书群中 @ 此名称时，消息会推送给该商户"></td>
                    </tr>
                </table>
                <button type="submit" name="add_merchant" class="button button-primary">添加商户</button>
            </form>

            <hr>

            <h2>商户列表</h2>
            <table class="wp-list-table widefat fixed striped fz-signal-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>商户名称</th>
                        <th>Bot 名称</th>
                        <th>API Key</th>
                        <th>未读消息</th>
                        <th>状态</th>
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($merchants): ?>
                    <?php foreach ($merchants as $m): ?>
                    <tr>
                        <td><?= esc_html($m->id) ?></td>
                        <td><strong><?= esc_html($m->merchant_name) ?></strong></td>
                        <td><code><?= esc_html($m->bot_name) ?></code></td>
                        <td>
                            <code class="fz-api-key"><?= esc_html(substr($m->api_key, 0, 16)) ?>…</code>
                            <form method="post" style="display:inline">
                                <?php wp_nonce_field('fz_signal'); ?>
                                <input type="hidden" name="merchant_id" value="<?= $m->id ?>">
                                <button type="submit" name="regenerate_key" class="button button-small">重置 Key</button>
                            </form>
                        </td>
                        <td>
                            <?php if ($m->unread > 0): ?>
                            <span class="fz-unread-badge"><?= $m->unread ?></span>
                            <?php else: ?>
                            <span style="color:#999">0</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="fz-status-<?= $m->status ?>"><?= $m->status ?></span></td>
                        <td><?= $m->created_at ?></td>
                        <td>
                            <form method="post" style="display:inline" 
                                onsubmit="return confirm('确定删除商户「<?= esc_js($m->merchant_name) ?>」？')">
                                <?php wp_nonce_field('fz_signal'); ?>
                                <input type="hidden" name="merchant_id" value="<?= $m->id ?>">
                                <button type="submit" name="delete_merchant" class="button button-small button-link-delete">删除</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <tr><td colspan="8" style="text-align:center;color:#999;">暂无商户</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function handle_add() {
        global $wpdb;
        $name = sanitize_text_field($_POST['merchant_name']);
        $bot = sanitize_text_field($_POST['bot_name']);
        if (!$name || !$bot) {
            echo '<div class="notice notice-error"><p>请填写完整信息</p></div>';
            return;
        }
        $wpdb->insert($wpdb->prefix . 'fz_signal_merchants', [
            'merchant_name' => $name,
            'bot_name' => $bot,
            'api_key' => fz_signal_generate_key(),
            'status' => 'active',
        ]);
        echo '<div class="notice notice-success"><p>商户添加成功 ✅ API Key 已自动生成</p></div>';
    }

    private function handle_delete() {
        global $wpdb;
        $id = intval($_POST['merchant_id']);
        $wpdb->delete($wpdb->prefix . 'fz_signal_merchants', ['id' => $id]);
        $wpdb->delete($wpdb->prefix . 'fz_signal_messages', ['merchant_id' => $id]);
        echo '<div class="notice notice-success"><p>已删除</p></div>';
    }

    private function handle_regenerate() {
        global $wpdb;
        $id = intval($_POST['merchant_id']);
        $wpdb->update($wpdb->prefix . 'fz_signal_merchants', 
            ['api_key' => fz_signal_generate_key()], 
            ['id' => $id]
        );
        echo '<div class="notice notice-success"><p>API Key 已重置 ✅</p></div>';
    }
}
