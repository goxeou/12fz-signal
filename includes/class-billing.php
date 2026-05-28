<?php
/**
 * 计费管理
 */
class FZ_Signal_Billing {

    const PLANS = [
        'basic'      => ['price' => 199,  'agent_limit' => 3,   'messages' => 100000],
        'pro'        => ['price' => 499,  'agent_limit' => 10,  'messages' => 999999999],
        'enterprise' => ['price' => 0,    'agent_limit' => 9999,'messages' => 999999999],
    ];

    /**
     * 创建计费记录
     */
    public function create_invoice($merchant_id, $plan, $period_start, $period_end) {
        global $wpdb;
        $plan_info = self::PLANS[$plan] ?? self::PLANS['basic'];

        $wpdb->insert($wpdb->prefix . 'fz_billing', [
            'merchant_id'  => $merchant_id,
            'plan'         => $plan,
            'amount'       => $plan_info['price'],
            'currency'     => 'CNY',
            'status'       => 'pending',
            'period_start' => $period_start,
            'period_end'   => $period_end,
        ]);

        return $wpdb->insert_id;
    }

    /**
     * 获取商户的计费记录
     */
    public function get_by_merchant($merchant_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fz_billing WHERE merchant_id = %d ORDER BY created_at DESC",
            $merchant_id
        ));
    }

    /**
     * 获取所有计费记录
     */
    public function get_all($limit = 50, $offset = 0) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT b.*, m.merchant_name FROM {$wpdb->prefix}fz_billing b
                LEFT JOIN {$wpdb->prefix}fz_merchants m ON b.merchant_id = m.id
                ORDER BY b.created_at DESC LIMIT %d OFFSET %d",
                $limit, $offset
            )
        );
    }

    /**
     * 统计计费概况
     */
    public function get_summary() {
        global $wpdb;
        return [
            'total_revenue'   => floatval($wpdb->get_var("SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}fz_billing WHERE status = 'paid'")),
            'pending_invoice' => intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fz_billing WHERE status = 'pending'")),
            'paid_count'      => intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fz_billing WHERE status = 'paid'")),
        ];
    }
}
