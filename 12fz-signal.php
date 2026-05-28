<?php
/**
 * Plugin Name: 12FZ 商业版信号中台
 * Plugin URI:  https://ai.12fz.com
 * Description: 飞书机器人信号通信系统 — 商户授权管理，多端消息路由，中继通信，任务调度
 * Version:     2.1.0
 * Author:      12FZ 服务器技术
 * Text Domain: fz-signal
 */

defined('ABSPATH') || exit;

define('FZ_SIGNAL_VERSION', '2.1.0');
define('FZ_SIGNAL_DIR', plugin_dir_path(__FILE__));
define('FZ_SIGNAL_URL', plugin_dir_url(__FILE__));

//--------------------------------------------------------------------------
// 安装 / 升级
//--------------------------------------------------------------------------
register_activation_hook(__FILE__, 'fz_signal_install');
add_action('plugins_loaded', 'fz_signal_check_upgrade');

function fz_signal_install() {
    fz_signal_create_tables();
    add_option('fz_signal_version', FZ_SIGNAL_VERSION);
}

function fz_signal_check_upgrade() {
    $ver = get_option('fz_signal_version', '0');
    if (version_compare($ver, FZ_SIGNAL_VERSION, '<')) {
        fz_signal_create_tables();
        update_option('fz_signal_version', FZ_SIGNAL_VERSION);
    }
}

function fz_signal_create_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // 1. 商户表
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fz_merchants (
        id            BIGINT AUTO_INCREMENT PRIMARY KEY,
        merchant_name VARCHAR(100) NOT NULL,
        contact_email VARCHAR(200) NOT NULL DEFAULT '',
        contact_phone VARCHAR(20)  NOT NULL DEFAULT '',
        plan          VARCHAR(20)  DEFAULT 'basic',
        agent_limit   INT          DEFAULT 3,
        status        VARCHAR(20)  DEFAULT 'active',
        api_key       VARCHAR(64)  NOT NULL UNIQUE,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        expired_at    DATETIME,
        KEY merchant_name (merchant_name),
        KEY plan (plan),
        KEY status (status)
    ) $charset;");

    // 2. Agent 表
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fz_agents (
        id              BIGINT AUTO_INCREMENT PRIMARY KEY,
        merchant_id     BIGINT NOT NULL,
        agent_name      VARCHAR(100) NOT NULL,
        bot_name        VARCHAR(100) NOT NULL,
        platform        VARCHAR(50) DEFAULT 'feishu',
        tags            TEXT,
        status          VARCHAR(20) DEFAULT 'active',
        last_seen       DATETIME,                         -- 最近一次通过中继通信时间
        config          TEXT,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (merchant_id) REFERENCES {$wpdb->prefix}fz_merchants(id) ON DELETE CASCADE,
        UNIQUE KEY bot_platform (bot_name(50), platform(20)),
        KEY merchant_id (merchant_id),
        KEY status (status)
    ) $charset;");

    // 3. 消息表
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fz_messages (
        id            BIGINT AUTO_INCREMENT PRIMARY KEY,
        agent_id      BIGINT NOT NULL,
        merchant_id   BIGINT NOT NULL,
        message_id    VARCHAR(100) NOT NULL,
        platform      VARCHAR(50) DEFAULT 'feishu',
        sender        VARCHAR(100) NOT NULL,
        text          TEXT NOT NULL,
        msg_type      VARCHAR(20) DEFAULT 'text',
        is_read       TINYINT(1) DEFAULT 0,
        tags          TEXT,
        created_at    DATETIME NOT NULL,
        received_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        polled_at     DATETIME,
        acked         TINYINT(1) DEFAULT 0,
        FOREIGN KEY (agent_id) REFERENCES {$wpdb->prefix}fz_agents(id) ON DELETE CASCADE,
        FOREIGN KEY (merchant_id) REFERENCES {$wpdb->prefix}fz_merchants(id) ON DELETE CASCADE,
        UNIQUE KEY message_id (message_id(100)),
        KEY merchant_id (merchant_id),
        KEY agent_id (agent_id),
        KEY is_read (is_read),
        KEY polled_at (polled_at),
        KEY acked (acked)
    ) $charset;");

    // 4. 工作流表
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fz_workflows (
        id              BIGINT AUTO_INCREMENT PRIMARY KEY,
        merchant_id     BIGINT NOT NULL,
        name            VARCHAR(200) NOT NULL,
        description     TEXT,
        trigger_type    VARCHAR(50) NOT NULL,
        trigger_config  TEXT,
        steps           TEXT NOT NULL,
        status          VARCHAR(20) DEFAULT 'active',
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (merchant_id) REFERENCES {$wpdb->prefix}fz_merchants(id) ON DELETE CASCADE,
        KEY merchant_id (merchant_id),
        KEY status (status)
    ) $charset;");

    // 5. 工作流执行日志表
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fz_workflow_logs (
        id            BIGINT AUTO_INCREMENT PRIMARY KEY,
        workflow_id   BIGINT NOT NULL,
        merchant_id   BIGINT NOT NULL,
        trigger_msg   TEXT,
        status        VARCHAR(20) DEFAULT 'pending',
        result        TEXT,
        started_at    DATETIME,
        finished_at   DATETIME,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (workflow_id) REFERENCES {$wpdb->prefix}fz_workflows(id) ON DELETE CASCADE,
        KEY merchant_id (merchant_id),
        KEY status (status)
    ) $charset;");

    // 6. 操作日志表
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fz_audit_log (
        id            BIGINT AUTO_INCREMENT PRIMARY KEY,
        merchant_id   BIGINT,
        admin_id      BIGINT,
        action        VARCHAR(100) NOT NULL,
        resource_type VARCHAR(50),
        resource_id   BIGINT,
        detail        TEXT,
        ip_address    VARCHAR(45),
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY merchant_id (merchant_id),
        KEY action (action),
        KEY created_at (created_at)
    ) $charset;");

    // 7. 计费记录表
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fz_billing (
        id            BIGINT AUTO_INCREMENT PRIMARY KEY,
        merchant_id   BIGINT NOT NULL,
        plan          VARCHAR(20) NOT NULL,
        amount        DECIMAL(10,2) NOT NULL,
        currency      VARCHAR(3) DEFAULT 'CNY',
        status        VARCHAR(20) DEFAULT 'pending',
        period_start  DATE NOT NULL,
        period_end    DATE NOT NULL,
        paid_at       DATETIME,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (merchant_id) REFERENCES {$wpdb->prefix}fz_merchants(id) ON DELETE CASCADE,
        KEY merchant_id (merchant_id),
        KEY status (status)
    ) $charset;");
}

//--------------------------------------------------------------------------
// 加载业务类
//--------------------------------------------------------------------------
require_once FZ_SIGNAL_DIR . 'includes/class-audit.php';
require_once FZ_SIGNAL_DIR . 'includes/class-merchant.php';
require_once FZ_SIGNAL_DIR . 'includes/class-agent.php';
require_once FZ_SIGNAL_DIR . 'includes/class-workflow.php';
require_once FZ_SIGNAL_DIR . 'includes/class-billing.php';
require_once FZ_SIGNAL_DIR . 'includes/class-api.php';
require_once FZ_SIGNAL_DIR . 'includes/class-admin.php';

// 初始化
add_action('plugins_loaded', 'fz_signal_init');
function fz_signal_init() {
    new FZ_Signal_Audit();
    new FZ_Signal_Merchant();
    new FZ_Signal_Agent();
    new FZ_Signal_Workflow();
    new FZ_Signal_Billing();
    new FZ_Signal_API();
    new FZ_Signal_Admin();
}

//--------------------------------------------------------------------------
// 工具函数
//--------------------------------------------------------------------------

/**
 * 生成 API Key
 */
function fz_signal_generate_key() {
    return 'fz_' . bin2hex(random_bytes(24));
}

/**
 * 获取当前商户（API 请求上下文中设置）
 */
function fz_signal_get_merchant() {
    return wp_cache_get('fz_signal_merchant', 'fz_signal');
}

/**
 * 写审计日志
 */
function fz_signal_audit($action, $resource_type = '', $resource_id = 0, $merchant_id = 0, $detail = []) {
    do_action('fz_signal_log', $action, $resource_type, $resource_id, $merchant_id, $detail);
}
