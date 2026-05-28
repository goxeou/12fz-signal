package models

import "time"

type Message struct {
	ID        int64     `json:"id,omitempty"`
	MessageID string    `json:"message_id"`
	BotName   string    `json:"bot_name"`
	Category  string    `json:"category"`        // normal | collaboration | project_plan
	Sender    string    `json:"sender"`
	Text      string    `json:"text"`
	Platform  string    `json:"platform"`
	MsgType   string    `json:"msg_type"`
	AgentID   int64     `json:"agent_id,omitempty"`
	CreatedAt time.Time `json:"created_at"`
}

type Agent struct {
	ID         int64      `json:"id"`
	MerchantID int64      `json:"merchant_id"`
	AgentName  string     `json:"agent_name"`
	BotName    string     `json:"bot_name"`
	Platform   string     `json:"platform"`
	Status     string     `json:"status"`
	LastSeen   *time.Time `json:"last_seen"`
	IsOnline   bool       `json:"is_online"`
}

type HealthResponse struct {
	Status    string `json:"status"`
	Version   string `json:"version"`
	Merchants int    `json:"merchants"`
	Agents    int    `json:"agents"`
	Unread    int    `json:"unread"`
}

type WriteMessageRequest struct {
	MessageID string `json:"message_id"`
	BotName   string `json:"bot_name"`
	Category  string `json:"category"`        // normal | collaboration | project_plan
	Sender    string `json:"sender"`
	Text      string `json:"text"`
	Platform  string `json:"platform"`
	MsgType   string `json:"msg_type"`
	CreatedAt string `json:"created_at"`
}

type AckRequest struct {
	IDs []int64 `json:"ids"`
}
