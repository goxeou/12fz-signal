<?php
/**
 * Plugin Name: 12FZ Bot Signal
 * Plugin URI:  https://12fz.com
 * Description: 飞书机器人信号通信系统 — 商户授权管理，API 方式替代 SSH 读文件
 * Version:     1.0.0
 * Author:      12FZ 服务器技术
 * Text Domain: fz-signal
 */

defined('ABSPATH') || exit;

define('FZ_SIGNAL_VERSION', '1.0.0');
define('FZ_SIGNAL_DIR', plugin_dir_path(__FILE__));
define('FZ_SIGNAL_URL', plugin_dir_url(__FILE__));

// 安装时创建表
register_activation_hook(__FILE__, 'fz_signal_install');
function fz_signal_install() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    // 商户表
    $table = $wpdb->prefix . 'fz_signal_merchants';
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        merchant_name varchar(100) NOT NULL,
        api_key varchar(64) NOT NULL,
        bot_name varchar(100) NOT NULL,
        status varchar(20) DEFAULT 'active',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY api_key (api_key),
        KEY merchant_name (merchant_name)
    ) $charset;";
    $wpdb->query($sql);

    // 信号消息表
    $table = $wpdb->prefix . 'fz_signal_messages';
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        merchant_id bigint(20) NOT NULL,
        message_id varchar(100) NOT NULL,
        sender varchar(100) NOT NULL,
        text text NOT NULL,
        created_at datetime NOT NULL,
        is_read tinyint(1) DEFAULT 0,
        received_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY merchant_id (merchant_id),
        KEY is_read (is_read)
    ) $charset;";
    $wpdb->query($sql);

    add_option('fz_signal_version', FZ_SIGNAL_VERSION);
}

// 加载核心文件
require_once FZ_SIGNAL_DIR . 'includes/class-admin.php';
require_once FZ_SIGNAL_DIR . 'includes/class-api.php';

// 初始化
add_action('plugins_loaded', 'fz_signal_init');
function fz_signal_init() {
    new FZ_Signal_Admin();
    new FZ_Signal_API();
}

// 生成 API Key
function fz_signal_generate_key() {
    return 'fz_' . bin2hex(random_bytes(24));
}
