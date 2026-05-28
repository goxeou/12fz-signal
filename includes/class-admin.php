<?php
/**
 * 后台管理页面 — 商业版信号中台
 */
class FZ_Signal_Admin {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_menu() {
        add_menu_page(
            '信号中台', '信号中台', 'manage_options',
            'fz-signal', [$this, 'render_page'],
            'dashicons-randomize', 80
        );
        add_submenu_page(
            'fz-signal', '商户管理', '商户管理', 'manage_options',
            'fz-signal-merchants', [$this, 'render_page']
        );
        add_submenu_page(
            'fz-signal', '消息日志', '消息日志', 'manage_options',
            'fz-signal-messages', [$this, 'render_messages']
        );
        add_submenu_page(
            'fz-signal', '工作流', '工作流', 'manage_options',
            'fz-signal-workflows', [$this, 'render_workflows']
        );
        add_submenu_page(
            'fz-signal', '计费', '计费', 'manage_options',
            'fz-signal-billing', [$this, 'render_billing']
        );
        add_submenu_page(
            'fz-signal', '审计日志', '审计日志', 'manage_options',
            'fz-signal-logs', [$this, 'render_logs']
        );
        add_submenu_page(
            'fz-signal', '设置', '设置', 'manage_options',
            'fz-signal-settings', [$this, 'render_settings']
        );
    }

    public function enqueue_assets($hook) {
        if (strpos($hook, 'fz-signal') === false) return;
        wp_enqueue_style('fz-signal-admin', FZ_SIGNAL_URL . 'assets/admin.css', [], FZ_SIGNAL_VERSION);
        wp_enqueue_script('fz-signal-admin', FZ_SIGNAL_URL . 'assets/js/admin.js', ['jquery'], FZ_SIGNAL_VERSION, true);
        wp_localize_script('fz-signal-admin', 'FZ_SIGNAL', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'rest_url' => rest_url('fz-signal/v1/'),
            'nonce'    => wp_create_nonce('wp_rest'),
        ]);
    }

    /**
     * 商户管理页面
     */
    public function render_page() {
        global $wpdb;
        $merchant = new FZ_Signal_Merchant();

        // 处理表单
        if (isset($_POST['add_merchant']) && check_admin_referer('fz_signal')) {
            $result = $merchant->create($_POST);
            if (is_wp_error($result)) {
                echo '<div class="notice notice-error"><p>' . $result->get_error_message() . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>商户添加成功 ✅ API Key 已自动生成</p></div>';
            }
        }
        if (isset($_POST['delete_merchant']) && check_admin_referer('fz_signal')) {
            $merchant->delete(intval($_POST['merchant_id']));
            echo '<div class="notice notice-success"><p>已删除</p></div>';
        }
        if (isset($_POST['regenerate_key']) && check_admin_referer('fz_signal')) {
            $new_key = $merchant->regenerate_key(intval($_POST['merchant_id']));
            echo '<div class="notice notice-success"><p>API Key 已重置: <code>' . esc_html($new_key) . '</code></p></div>';
        }

        $merchants = $merchant->get_all();
        ?>
        <div class="wrap fz-signal-wrap">
            <h1>🤖 信号中台 — 商户管理</h1>

            <h2>添加商户</h2>
            <form method="post" class="fz-signal-form">
                <?php wp_nonce_field('fz_signal'); ?>
                <table class="form-table">
                    <tr><th>商户名称</th><td><input type="text" name="merchant_name" required placeholder="如：张三科技有限公司"></td></tr>
                    <tr><th>联系邮箱</th><td><input type="email" name="contact_email" placeholder="admin@example.com"></td></tr>
                    <tr><th>联系电话</th><td><input type="text" name="contact_phone" placeholder="13800138000"></td></tr>
                    <tr><th>套餐</th>
                        <td>
                            <select name="plan">
                                <option value="basic">基础版 ¥199/月 (3 Agent)</option>
                                <option value="pro">专业版 ¥499/月 (10 Agent)</option>
                                <option value="enterprise">企业版 定制</option>
                            </select>
                        </td>
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
                        <th>套餐</th>
                        <th>Agent / 上限</th>
                        <th>未读 / 未确认</th>
                        <th>API Key</th>
                        <th>状态</th>
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($merchants): ?>
                    <?php foreach ($merchants as $m): ?>
                    <tr>
                        <td><?= $m->id ?></td>
                        <td><strong><?= esc_html($m->merchant_name) ?></strong></td>
                        <td><span class="fz-plan fz-plan-<?= esc_attr($m->plan) ?>"><?= esc_html($m->plan) ?></span></td>
                        <td><?= intval($m->agent_count) ?> / <?= intval($m->agent_limit) ?></td>
                        <td>
                            <?php if ($m->unread > 0): ?><span class="fz-badge"><?= $m->unread ?> 未读</span><?php endif; ?>
                            <?php if ($m->unacked > 0): ?><span class="fz-badge fz-badge-warn"><?= $m->unacked ?> 未确认</span><?php endif; ?>
                            <?php if ($m->unread == 0 && $m->unacked == 0): ?><span style="color:#999">0</span><?php endif; ?>
                        </td>
                        <td><code class="fz-key"><?= esc_html(substr($m->api_key, 0, 16)) ?>…</code>
                            <form method="post" style="display:inline">
                                <?php wp_nonce_field('fz_signal'); ?>
                                <input type="hidden" name="merchant_id" value="<?= $m->id ?>">
                                <button type="submit" name="regenerate_key" class="button button-small">重置 Key</button>
                            </form>
                        </td>
                        <td><span class="fz-status-<?= esc_attr($m->status) ?>"><?= esc_html($m->status) ?></span></td>
                        <td><?= $m->created_at ?></td>
                        <td>
                            <form method="post" style="display:inline"
                                onsubmit="return confirm('确定删除商户「<?= esc_js($m->merchant_name) ?>」？所有 Agent 和消息将被清除。')">
                                <?php wp_nonce_field('fz_signal'); ?>
                                <input type="hidden" name="merchant_id" value="<?= $m->id ?>">
                                <button type="submit" name="delete_merchant" class="button button-small button-link-delete">删除</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <tr><td colspan="9" style="text-align:center;color:#999;">暂无商户</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * 消息日志页面
     */
    public function render_messages() {
        global $wpdb;
        $page    = max(1, intval($_GET['paged'] ?? 1));
        $perpage = 30;
        $offset  = ($page - 1) * $perpage;
        $mid     = intval($_GET['merchant_id'] ?? 0);

        $where = $mid ? $wpdb->prepare('WHERE m.merchant_id = %d', $mid) : '';
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
        $total = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fz_messages $where"));
        ?>
        <div class="wrap fz-signal-wrap">
            <h1>📨 消息日志</h1>
            <form method="get" class="fz-filter">
                <input type="hidden" name="page" value="fz-signal-messages">
                <label>商户 ID：<input type="number" name="merchant_id" value="<?= $mid ?>" placeholder="全部"></label>
                <button type="submit" class="button">筛选</button>
                <?php if ($mid): ?><a href="?page=fz-signal-messages" class="button">清除筛选</a><?php endif; ?>
            </form>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th>ID</th><th>商户</th><th>Agent</th><th>平台</th><th>发送者</th><th>消息</th><th>类型</th><th>已读</th><th>已确认</th><th>时间</th>
                </tr></thead>
                <tbody>
                <?php foreach ($messages as $msg): ?>
                    <tr>
                        <td><?= $msg->id ?></td>
                        <td><?= esc_html($msg->merchant_name) ?></td>
                        <td><?= esc_html($msg->agent_name) ?></td>
                        <td><?= esc_html($msg->platform) ?></td>
                        <td><?= esc_html($msg->sender) ?></td>
                        <td><div class="fz-msg-preview"><?= esc_html(mb_substr($msg->text, 0, 80)) ?></div></td>
                        <td><?= esc_html($msg->msg_type) ?></td>
                        <td><?= $msg->is_read ? '✅' : '⬜' ?></td>
                        <td><?= $msg->acked ? '✅' : '⏳' ?></td>
                        <td><?= $msg->created_at ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($messages)): ?><tr><td colspan="10" style="text-align:center;color:#999;">暂无消息</td></tr><?php endif; ?>
                </tbody>
            </table>
            <?php if ($total > $perpage): ?>
            <div class="tablenav"><div class="tablenav-pages">
                <?php for ($i = 1; $i <= ceil($total / $perpage); $i++): ?>
                    <a class="button <?= $i == $page ? 'button-primary' : '' ?>" href="?page=fz-signal-messages&paged=<?= $i ?>&merchant_id=<?= $mid ?>"><?= $i ?></a>
                <?php endfor; ?>
            </div></div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * 工作流管理
     */
    public function render_workflows() {
        $wf = new FZ_Signal_Workflow();

        if (isset($_POST['add_workflow']) && check_admin_referer('fz_signal')) {
            $data = [
                'merchant_id'   => intval($_POST['merchant_id']),
                'name'          => $_POST['name'],
                'description'   => $_POST['description'],
                'trigger_type'  => $_POST['trigger_type'],
                'trigger_config' => ['keyword' => sanitize_text_field($_POST['trigger_keyword'] ?? '')],
                'steps'         => [['type' => 'notify', 'channel' => 'feishu', 'message' => sanitize_textarea_field($_POST['step_message'] ?? '')]],
            ];
            $result = $wf->create($data);
            if (is_wp_error($result)) {
                echo '<div class="notice notice-error"><p>' . $result->get_error_message() . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>工作流创建成功</p></div>';
            }
        }
        if (isset($_POST['delete_workflow']) && check_admin_referer('fz_signal')) {
            $wf->delete(intval($_POST['workflow_id']));
            echo '<div class="notice notice-success"><p>已删除</p></div>';
        }

        $workflows = $wf->get_all();
        ?>
        <div class="wrap fz-signal-wrap">
            <h1>⚙️ 工作流管理</h1>

            <h2>创建工作流</h2>
            <form method="post" class="fz-signal-form">
                <?php wp_nonce_field('fz_signal'); ?>
                <table class="form-table">
                    <tr><th>商户</th><td>
                        <select name="merchant_id"><?php
                            $merchants = (new FZ_Signal_Merchant())->get_all();
                            foreach ($merchants as $m) {
                                echo '<option value="' . $m->id . '">' . esc_html($m->merchant_name) . '</option>';
                            }
                        ?></select>
                    </td></tr>
                    <tr><th>名称</th><td><input type="text" name="name" required placeholder="如：离线通知"></td></tr>
                    <tr><th>描述</th><td><textarea name="description" rows="2" style="width:100%"></textarea></td></tr>
                    <tr><th>触发类型</th>
                        <td>
                            <select name="trigger_type">
                                <option value="message_match">关键词匹配</option>
                                <option value="cron">定时触发</option>
                                <option value="agent_offline">Agent 离线</option>
                                <option value="webhook">Webhook</option>
                            </select>
                        </td>
                    </tr>
                    <tr><th>触发关键词</th><td><input type="text" name="trigger_keyword" placeholder="如：离线、告警"></td></tr>
                    <tr><th>通知消息</th><td><textarea name="step_message" rows="3" style="width:100%" placeholder="如：{{agent_name}} 已离线，请检查"></textarea></td></tr>
                </table>
                <button type="submit" name="add_workflow" class="button button-primary">创建工作流</button>
            </form>

            <hr>

            <h2>工作流列表</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>ID</th><th>商户</th><th>名称</th><th>触发类型</th><th>状态</th><th>步骤数</th><th>创建时间</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($workflows as $w): ?>
                    <tr>
                        <td><?= $w->id ?></td>
                        <td><?= esc_html($w->merchant_name) ?></td>
                        <td><strong><?= esc_html($w->name) ?></strong></td>
                        <td><code><?= esc_html($w->trigger_type) ?></code></td>
                        <td><span class="fz-status-<?= esc_attr($w->status) ?>"><?= esc_html($w->status) ?></span></td>
                        <td><?= count(json_decode($w->steps ?: '[]', true) ?: []) ?></td>
                        <td><?= $w->created_at ?></td>
                        <td>
                            <form method="post" style="display:inline"
                                onsubmit="return confirm('确定删除？')">
                                <?php wp_nonce_field('fz_signal'); ?>
                                <input type="hidden" name="workflow_id" value="<?= $w->id ?>">
                                <button type="submit" name="delete_workflow" class="button button-small button-link-delete">删除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($workflows)): ?><tr><td colspan="8" style="text-align:center;color:#999;">暂无工作流</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * 计费页面
     */
    public function render_billing() {
        $billing = new FZ_Signal_Billing();
        $summary = $billing->get_summary();
        ?>
        <div class="wrap fz-signal-wrap">
            <h1>💰 计费概况</h1>
            <div class="fz-cards">
                <div class="fz-card">
                    <div class="fz-card-value">¥<?= number_format($summary['total_revenue'], 2) ?></div>
                    <div class="fz-card-label">总收入</div>
                </div>
                <div class="fz-card">
                    <div class="fz-card-value"><?= $summary['paid_count'] ?></div>
                    <div class="fz-card-label">已支付笔数</div>
                </div>
                <div class="fz-card">
                    <div class="fz-card-value"><?= $summary['pending_invoice'] ?></div>
                    <div class="fz-card-label">待处理账单</div>
                </div>
            </div>

            <h2>套餐定价</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>套餐</th><th>价格</th><th>Agent 数</th><th>月消息量</th></tr></thead>
                <tbody>
                <?php foreach (FZ_Signal_Billing::PLANS as $name => $cfg): ?>
                    <tr>
                        <td><span class="fz-plan fz-plan-<?= $name ?>"><?= ucfirst($name) ?></span></td>
                        <td><?= $cfg['price'] > 0 ? '¥' . $cfg['price'] . '/月' : '定制' ?></td>
                        <td><?= $cfg['agent_limit'] >= 9999 ? '不限' : $cfg['agent_limit'] ?></td>
                        <td><?= $cfg['messages'] >= 999999999 ? '无限' : number_format($cfg['messages']) . '/月' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * 审计日志
     */
    public function render_logs() {
        global $wpdb;
        $page    = max(1, intval($_GET['paged'] ?? 1));
        $perpage = 50;
        $offset  = ($page - 1) * $perpage;
        $logs = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}fz_audit_log ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $perpage, $offset
            )
        );
        $total = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fz_audit_log"));
        ?>
        <div class="wrap fz-signal-wrap">
            <h1>📋 审计日志</h1>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>时间</th><th>操作</th><th>资源类型</th><th>资源 ID</th><th>商户 ID</th><th>IP</th><th>详情</th></tr></thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= $log->created_at ?></td>
                        <td><code><?= esc_html($log->action) ?></code></td>
                        <td><?= esc_html($log->resource_type) ?></td>
                        <td><?= $log->resource_id ?></td>
                        <td><?= $log->merchant_id ?: '-' ?></td>
                        <td><?= esc_html($log->ip_address) ?></td>
                        <td><small><?= esc_html(mb_substr($log->detail, 0, 100)) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($logs)): ?><tr><td colspan="7" style="text-align:center;">暂无日志</td></tr><?php endif; ?>
                </tbody>
            </table>
            <?php if ($total > $perpage): ?>
            <div class="tablenav"><div class="tablenav-pages">
                <?php for ($i = 1; $i <= ceil($total / $perpage); $i++): ?>
                    <a class="button <?= $i == $page ? 'button-primary' : '' ?>" href="?page=fz-signal-logs&paged=<?= $i ?>"><?= $i ?></a>
                <?php endfor; ?>
            </div></div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * 设置
     */
    public function render_settings() {
        if (isset($_POST['save_settings']) && check_admin_referer('fz_signal')) {
            $master_key = sanitize_text_field($_POST['master_key'] ?? '');
            if ($master_key) {
                update_option('fz_signal_master_key', $master_key);
                echo '<div class="notice notice-success"><p>Master Key 已更新</p></div>';
            }
        }

        $current_master = defined('FZ_SIGNAL_MASTER_KEY') ? '（常量已设置）' :
            (get_option('fz_signal_master_key') ? '（已设置）' : '（未设置）');
        ?>
        <div class="wrap fz-signal-wrap">
            <h1>⚙️ 信号中台设置</h1>
            <form method="post" class="fz-signal-form">
                <?php wp_nonce_field('fz_signal'); ?>
                <table class="form-table">
                    <tr>
                        <th>Master Key</th>
                        <td>
                            <input type="text" name="master_key" style="width:100%;font-family:monospace"
                                value="<?= esc_attr(get_option('fz_signal_master_key', '')) ?>"
                                placeholder="输入 Master Key (poller/agent注册用)">
                            <p class="description"><?= $current_master ?> 。也可在 wp-config.php 中定义 <code>FZ_SIGNAL_MASTER_KEY</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th>插件版本</th>
                        <td><code><?= FZ_SIGNAL_VERSION ?></code></td>
                    </tr>
                    <tr>
                        <th>API 域名</th>
                        <td><code>https://ai.12fz.com</code></td>
                    </tr>
                </table>
                <button type="submit" name="save_settings" class="button button-primary">保存设置</button>
            </form>
        </div>
        <?php
    }
}
