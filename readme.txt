=== 12FZ 商业版信号中台 ===
Contributors: 12fz
Tags: feishu, bot, signal, api, merchant
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.0.0
License: GPLv2 or later

飞书机器人信号通信系统 — 商户授权管理，Web API 替代 SSH。

== Description ==

将飞书群消息信号系统从文件/SSH 方式升级为 WordPress 插件 + REST API。

商户在后台授权后，通过 API Key 访问自己的信号消息，无需 SSH。

== Installation ==

1. 上传 `12fz-signal` 目录到 `/wp-content/plugins/`
2. 在 WordPress 后台激活插件
3. 进入「信号系统」菜单添加商户
4. 设置 Master Key：`wp option set fz_signal_master_key your_master_key_here`

== API 文档 ==

商户用 API Key（放在 `X-API-Key` 请求头）调用：

### 健康检查
`GET /wp-json/fz-signal/v1/health`

### 读取消息
`GET /wp-json/fz-signal/v1/messages`
Header: `X-API-Key: fz_xxx...`

### 清空消息
`POST /wp-json/fz-signal/v1/messages/clear`
Header: `X-API-Key: fz_xxx...`

### 写入消息（poller 用）
`POST /wp-json/fz-signal/v1/messages`
Header: `X-API-Key: master_key`
Body: `{"bot_name":"xxx","sender":"服务器技术","text":"消息内容","message_id":"xxx","created_at":"2026-01-01 12:00:00"}`

== Changelog ==

= 1.0.0 =
* 商户管理（添加/删除/重置 Key）
* REST API 读写信号消息
* API Key 认证
* 消息去重
