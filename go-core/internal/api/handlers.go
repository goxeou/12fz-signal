package api

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"log/slog"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/12fz/fz-signal-core/internal/models"
	"github.com/redis/go-redis/v9"
)

// ── Public ──

func (s *Server) handleHealth(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	prefix := s.cfg.Database.TablePrefix

	var merchantCount, agentCount, unread int
	s.db.QueryRowContext(ctx, fmt.Sprintf("SELECT COUNT(*) FROM %sfz_merchants", prefix)).Scan(&merchantCount)
	s.db.QueryRowContext(ctx, fmt.Sprintf("SELECT COUNT(*) FROM %sfz_agents", prefix)).Scan(&agentCount)
	s.db.QueryRowContext(ctx, fmt.Sprintf("SELECT COUNT(*) FROM %sfz_messages WHERE is_read = 0", prefix)).Scan(&unread)

	writeJSON(w, http.StatusOK, models.HealthResponse{
		Status:    "ok",
		Version:   "2.1.0",
		Merchants: merchantCount,
		Agents:    agentCount,
		Unread:    unread,
	})
}

// ── Merchant API: Messages ──

func (s *Server) handleGetMessages(w http.ResponseWriter, r *http.Request) {
	merchantID := getMerchantID(r)
	prefix := s.cfg.Database.TablePrefix

	rows, err := s.db.QueryContext(r.Context(), fmt.Sprintf(`
		SELECT m.id, m.message_id, a.agent_name, m.platform, m.sender, m.text,
		       m.msg_type, m.category, m.is_read, m.acked, m.created_at
		FROM %sfz_messages m
		JOIN %sfz_agents a ON m.agent_id = a.id
		WHERE m.merchant_id = ? AND m.acked = 0
		ORDER BY m.created_at DESC LIMIT 50
	`, prefix, prefix), merchantID)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": err.Error()})
		return
	}
	defer rows.Close()

	type msgRow struct {
		ID        int64  `json:"id"`
		MessageID string `json:"message_id"`
		AgentName string `json:"agent_name"`
		Platform  string `json:"platform"`
		Sender    string `json:"sender"`
		Text      string `json:"text"`
		MsgType   string `json:"msg_type"`
		Category  string `json:"category"`
		IsRead    int    `json:"is_read"`
		Acked     int    `json:"acked"`
		CreatedAt string `json:"created_at"`
	}

	var messages []msgRow
	for rows.Next() {
		var m msgRow
		if err := rows.Scan(&m.ID, &m.MessageID, &m.AgentName, &m.Platform, &m.Sender, &m.Text,
			&m.MsgType, &m.Category, &m.IsRead, &m.Acked, &m.CreatedAt); err != nil {
			slog.Error("scan message", "error", err)
			continue
		}
		messages = append(messages, m)
	}
	if messages == nil {
		messages = []msgRow{}
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"messages": messages,
		"total":    len(messages),
	})
}

func (s *Server) handleAckMessages(w http.ResponseWriter, r *http.Request) {
	merchantID := getMerchantID(r)
	var req models.AckRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "invalid body"})
		return
	}

	prefix := s.cfg.Database.TablePrefix
	now := time.Now().Format("2006-01-02 15:04:05")

	if len(req.IDs) > 0 {
		placeholders := make([]string, len(req.IDs))
		args := make([]interface{}, 0, len(req.IDs)+2)
		args = append(args, now, merchantID)
		for i, id := range req.IDs {
			placeholders[i] = "?"
			args = append(args, id)
		}
		query := fmt.Sprintf("UPDATE %sfz_messages SET acked = 1, polled_at = ? WHERE merchant_id = ? AND id IN (%s)",
			prefix, strings.Join(placeholders, ","))
		_, err := s.db.ExecContext(r.Context(), query, args...)
		if err != nil {
			writeJSON(w, http.StatusInternalServerError, map[string]string{"error": err.Error()})
			return
		}
	} else {
		_, err := s.db.ExecContext(r.Context(),
			fmt.Sprintf("UPDATE %sfz_messages SET acked = 1, polled_at = ? WHERE merchant_id = ? AND acked = 0", prefix),
			now, merchantID)
		if err != nil {
			writeJSON(w, http.StatusInternalServerError, map[string]string{"error": err.Error()})
			return
		}
	}

	writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
}

func (s *Server) handleClearMessages(w http.ResponseWriter, r *http.Request) {
	merchantID := getMerchantID(r)
	prefix := s.cfg.Database.TablePrefix
	now := time.Now().Format("2006-01-02 15:04:05")

	_, err := s.db.ExecContext(r.Context(),
		fmt.Sprintf("UPDATE %sfz_messages SET is_read = 1, polled_at = ? WHERE merchant_id = ? AND is_read = 0", prefix),
		now, merchantID)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": err.Error()})
		return
	}

	writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
}

// ── Merchant API: Agents ──

func (s *Server) handleListAgents(w http.ResponseWriter, r *http.Request) {
	merchantID := getMerchantID(r)
	prefix := s.cfg.Database.TablePrefix

	rows, err := s.db.QueryContext(r.Context(), fmt.Sprintf(`
		SELECT id, agent_name, bot_name, platform, status, last_seen
		FROM %sfz_agents WHERE merchant_id = ? ORDER BY created_at DESC
	`, prefix), merchantID)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": err.Error()})
		return
	}
	defer rows.Close()

	var agents []map[string]interface{}
	threshold := time.Now().Add(-time.Duration(s.cfg.Relay.TimeoutSeconds) * time.Second)

	for rows.Next() {
		var id int64
		var name, bot, platform, status string
		var lastSeen sql.NullTime
		if err := rows.Scan(&id, &name, &bot, &platform, &status, &lastSeen); err != nil {
			continue
		}
		online := lastSeen.Valid && lastSeen.Time.After(threshold)
		agent := map[string]interface{}{
			"id":         id,
			"agent_name": name,
			"bot_name":   bot,
			"platform":   platform,
			"status":     status,
			"is_online":  online,
		}
		if lastSeen.Valid {
			agent["last_seen"] = lastSeen.Time.Format("2006-01-02 15:04:05")
		}
		agents = append(agents, agent)
	}
	if agents == nil {
		agents = []map[string]interface{}{}
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"agents": agents,
		"total":  len(agents),
	})
}

func (s *Server) handleAgentStatus(w http.ResponseWriter, r *http.Request) {
	merchantID := getMerchantID(r)
	agentID, _ := strconv.ParseInt(r.PathValue("id"), 10, 64)
	prefix := s.cfg.Database.TablePrefix

	var id int64
	var name, bot, status string
	var lastSeen sql.NullTime
	err := s.db.QueryRowContext(r.Context(), fmt.Sprintf(`
		SELECT id, agent_name, bot_name, status, last_seen
		FROM %sfz_agents WHERE id = ? AND merchant_id = ?
	`, prefix), agentID, merchantID).Scan(&id, &name, &bot, &status, &lastSeen)
	if err != nil {
		writeJSON(w, http.StatusNotFound, map[string]string{"error": "agent not found"})
		return
	}

	threshold := time.Now().Add(-time.Duration(s.cfg.Relay.TimeoutSeconds) * time.Second)
	online := lastSeen.Valid && lastSeen.Time.After(threshold)
	agentStatus := "timeout"
	if online {
		agentStatus = "online"
	}

	resp := map[string]interface{}{
		"agent_id":   id,
		"agent_name": name,
		"bot_name":   bot,
		"status":     agentStatus,
	}
	if lastSeen.Valid {
		resp["last_seen"] = lastSeen.Time.Format("2006-01-02 15:04:05")
	}

	writeJSON(w, http.StatusOK, resp)
}

// ── System API: Write Message ──

func (s *Server) handleWriteMessage(w http.ResponseWriter, r *http.Request) {
	var req models.WriteMessageRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "invalid body"})
		return
	}

	if req.BotName == "" || req.Text == "" {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "missing bot_name or text"})
		return
	}

	prefix := s.cfg.Database.TablePrefix

	// Find agent by bot_name
	var agentID, merchantID int64
	err := s.db.QueryRowContext(r.Context(), fmt.Sprintf(`
		SELECT a.id, a.merchant_id FROM %sfz_agents a
		JOIN %sfz_merchants m ON a.merchant_id = m.id
		WHERE a.bot_name = ? AND a.platform = ? AND a.status = 'active' AND m.status = 'active'
		LIMIT 1
	`, prefix, prefix), req.BotName, req.Platform).Scan(&agentID, &merchantID)
	if err != nil {
		writeJSON(w, http.StatusOK, map[string]string{"status": "skipped", "message": "no matching agent"})
		return
	}

	// Dedup
	if req.MessageID != "" {
		var exists int
		s.db.QueryRowContext(r.Context(),
			fmt.Sprintf("SELECT COUNT(*) FROM %sfz_messages WHERE message_id = ?", prefix),
			req.MessageID).Scan(&exists)
		if exists > 0 {
			writeJSON(w, http.StatusOK, map[string]string{"status": "duplicate", "message": "already exists"})
			return
		}
	}

	createdAt := time.Now()
	if req.CreatedAt != "" {
		if t, err := time.Parse("2006-01-02 15:04:05", req.CreatedAt); err == nil {
			createdAt = t
		}
	}

	// Include category in DB insert
	if req.Category == "" {
		req.Category = "normal"
	}
	validCategories := map[string]bool{"normal": true, "collaboration": true, "project_plan": true}
	if !validCategories[req.Category] {
		req.Category = "normal"
	}

	_, err = s.db.ExecContext(r.Context(), fmt.Sprintf(`
		INSERT INTO %sfz_messages (agent_id, merchant_id, message_id, platform, sender, text, msg_type, category, created_at, received_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
	`, prefix), agentID, merchantID, req.MessageID, coalesce(req.Platform, "feishu"),
		coalesce(req.Sender, "unknown"), req.Text, coalesce(req.MsgType, "text"), req.Category, createdAt)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": err.Error()})
		return
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"status":      "ok",
		"agent_id":    agentID,
		"merchant_id": merchantID,
	})
}

// ── System API: Agents ──

func (s *Server) handleRegisterAgent(w http.ResponseWriter, r *http.Request) {
	var req struct {
		MerchantID int64  `json:"merchant_id"`
		AgentName  string `json:"agent_name"`
		BotName    string `json:"bot_name"`
		Platform   string `json:"platform"`
	}
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "invalid body"})
		return
	}

	if req.MerchantID == 0 || req.AgentName == "" {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "missing merchant_id or agent_name"})
		return
	}

	prefix := s.cfg.Database.TablePrefix

	// Check merchant exists and agent limit
	var agentLimit, currentCount int
	err := s.db.QueryRowContext(r.Context(),
		fmt.Sprintf("SELECT agent_limit FROM %sfz_merchants WHERE id = ? AND status = 'active'", prefix),
		req.MerchantID).Scan(&agentLimit)
	if err != nil {
		writeJSON(w, http.StatusNotFound, map[string]string{"error": "merchant not found"})
		return
	}

	s.db.QueryRowContext(r.Context(),
		fmt.Sprintf("SELECT COUNT(*) FROM %sfz_agents WHERE merchant_id = ?", prefix),
		req.MerchantID).Scan(&currentCount)
	if currentCount >= agentLimit {
		writeJSON(w, http.StatusForbidden, map[string]string{"error": "agent limit reached"})
		return
	}

	result, err := s.db.ExecContext(r.Context(), fmt.Sprintf(`
		INSERT INTO %sfz_agents (merchant_id, agent_name, bot_name, platform, status)
		VALUES (?, ?, ?, ?, 'active')
	`, prefix), req.MerchantID, req.AgentName, req.BotName, coalesce(req.Platform, "feishu"))
	if err != nil {
		if strings.Contains(err.Error(), "Duplicate") {
			writeJSON(w, http.StatusConflict, map[string]string{"error": "agent already exists"})
			return
		}
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": err.Error()})
		return
	}

	id, _ := result.LastInsertId()
	writeJSON(w, http.StatusOK, map[string]interface{}{"status": "ok", "agent_id": id})
}

func (s *Server) handleAgentPing(w http.ResponseWriter, r *http.Request) {
	agentID, _ := strconv.ParseInt(r.PathValue("id"), 10, 64)
	prefix := s.cfg.Database.TablePrefix
	now := time.Now().Format("2006-01-02 15:04:05")

	_, err := s.db.ExecContext(r.Context(),
		fmt.Sprintf("UPDATE %sfz_agents SET last_seen = ? WHERE id = ?", prefix),
		now, agentID)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": err.Error()})
		return
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"status":   "ok",
		"agent_id": agentID,
		"last_seen": now,
	})
}

func (s *Server) handleTimeoutAgents(w http.ResponseWriter, r *http.Request) {
	prefix := s.cfg.Database.TablePrefix
	deadline := time.Now().Add(-time.Duration(s.cfg.Relay.TimeoutSeconds) * time.Second).Format("2006-01-02 15:04:05")

	rows, err := s.db.QueryContext(r.Context(), fmt.Sprintf(`
		SELECT a.id, a.agent_name, a.bot_name, a.last_seen, m.merchant_name
		FROM %sfz_agents a
		LEFT JOIN %sfz_merchants m ON a.merchant_id = m.id
		WHERE a.last_seen IS NULL OR a.last_seen < ?
	`, prefix, prefix), deadline)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": err.Error()})
		return
	}
	defer rows.Close()

	var agents []map[string]interface{}
	for rows.Next() {
		var id int64
		var name, bot, merchantName string
		var lastSeen sql.NullTime
		if err := rows.Scan(&id, &name, &bot, &lastSeen, &merchantName); err != nil {
			continue
		}
		a := map[string]interface{}{
			"id":            id,
			"agent_name":    name,
			"bot_name":      bot,
			"merchant_name": merchantName,
		}
		if lastSeen.Valid {
			a["last_seen"] = lastSeen.Time.Format("2006-01-02 15:04:05")
		}
		agents = append(agents, a)
	}
	if agents == nil {
		agents = []map[string]interface{}{}
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"timeout_count": len(agents),
		"agents":        agents,
	})
}

// ── Background relay timeout checker ──

func (s *Server) StartTimeoutChecker(ctx context.Context) {
	interval := time.Duration(s.cfg.Relay.CheckInterval) * time.Second
	if interval < 10*time.Second {
		interval = 30 * time.Second
	}

	go func() {
		ticker := time.NewTicker(interval)
		defer ticker.Stop()

		for {
			select {
			case <-ctx.Done():
				return
			case <-ticker.C:
				s.refreshTimeoutCache(ctx)
			}
		}
	}()
}

func (s *Server) refreshTimeoutCache(ctx context.Context) {
	prefix := s.cfg.Database.TablePrefix
	deadline := time.Now().Add(-time.Duration(s.cfg.Relay.TimeoutSeconds) * time.Second).Format("2006-01-02 15:04:05")

	rows, err := s.db.QueryContext(ctx, fmt.Sprintf(`
		SELECT COUNT(*) FROM %sfz_agents WHERE last_seen IS NULL OR last_seen < ?
	`, prefix), deadline)
	if err != nil {
		return
	}
	defer rows.Close()

	s.mu.Lock()
	s.cacheUpdated = time.Now()
	s.mu.Unlock()
}

// ── Helpers ──

func getMerchantID(r *http.Request) int64 {
	id, _ := strconv.ParseInt(r.Header.Get("X-Merchant-ID"), 10, 64)
	return id
}

func writeJSON(w http.ResponseWriter, status int, data interface{}) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	json.NewEncoder(w).Encode(data)
}

func coalesce(s, def string) string {
	if s == "" {
		return def
	}
	return s
}
