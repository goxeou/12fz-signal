package api

import (
	"crypto/subtle"
	"database/sql"
	"fmt"
	"log/slog"
	"net/http"
	"strings"
	"sync"
	"time"

	"github.com/12fz/fz-signal-core/internal/config"
	"github.com/redis/go-redis/v9"
)

type authLevel int

const (
	apiKeyLevel       authLevel = iota
	masterKeyLevel
	agentOrMasterLevel
)

type Server struct {
	cfg  *config.Config
	rdb  *redis.Client
	db   *sql.DB
	mux  *http.ServeMux
	srv  *http.Server
	mu   sync.RWMutex

	// Timeout agent cache
	timeoutCache []map[string]interface{}
	cacheUpdated time.Time
}

func New(cfg *config.Config, rdb *redis.Client, db *sql.DB) *Server {
	s := &Server{
		cfg: cfg,
		rdb: rdb,
		db:  db,
		mux: http.NewServeMux(),
	}
	s.registerRoutes()
	return s
}

func (s *Server) registerRoutes() {
	// Public
	s.mux.HandleFunc("GET /api/v1/health", s.handleHealth)

	// Merchant API (X-API-Key)
	s.mux.HandleFunc("GET /api/v1/messages", s.withAuth(s.handleGetMessages, apiKeyLevel))
	s.mux.HandleFunc("POST /api/v1/messages/ack", s.withAuth(s.handleAckMessages, apiKeyLevel))
	s.mux.HandleFunc("POST /api/v1/messages/clear", s.withAuth(s.handleClearMessages, apiKeyLevel))
	s.mux.HandleFunc("GET /api/v1/agents", s.withAuth(s.handleListAgents, apiKeyLevel))
	s.mux.HandleFunc("GET /api/v1/agents/{id}/status", s.withAuth(s.handleAgentStatus, apiKeyLevel))

	// System API (X-Master-Key)
	s.mux.HandleFunc("POST /api/v1/messages", s.withAuth(s.handleWriteMessage, masterKeyLevel))
	s.mux.HandleFunc("POST /api/v1/agents/register", s.withAuth(s.handleRegisterAgent, masterKeyLevel))
	s.mux.HandleFunc("POST /api/v1/agents/{id}/ping", s.withAuth(s.handleAgentPing, agentOrMasterLevel))
	s.mux.HandleFunc("GET /api/v1/agents/timeout", s.withAuth(s.handleTimeoutAgents, masterKeyLevel))
}

func (s *Server) withAuth(next http.HandlerFunc, level authLevel) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		key := r.Header.Get("X-API-Key")
		if key == "" {
			// Try alternate headers
			key = r.Header.Get("x-api-key")
		}

		switch level {
		case apiKeyLevel:
			if key == "" {
				writeJSON(w, http.StatusUnauthorized, map[string]string{"error": "missing X-API-Key"})
				return
			}
			merchantID, err := s.lookupAPIKey(r.Context(), key)
			if err != nil || merchantID == 0 {
				writeJSON(w, http.StatusUnauthorized, map[string]string{"error": "invalid API key"})
				return
			}
			r.Header.Set("X-Merchant-ID", fmt.Sprintf("%d", merchantID))

		case masterKeyLevel:
			if !s.verifyMasterKey(key) {
				writeJSON(w, http.StatusUnauthorized, map[string]string{"error": "invalid master key"})
				return
			}

		case agentOrMasterLevel:
			if s.verifyMasterKey(key) {
				break
			}
			if key == "" {
				writeJSON(w, http.StatusUnauthorized, map[string]string{"error": "missing auth key"})
				return
			}
			merchantID, err := s.lookupAPIKey(r.Context(), key)
			if err != nil || merchantID == 0 {
				writeJSON(w, http.StatusUnauthorized, map[string]string{"error": "invalid auth key"})
				return
			}
			r.Header.Set("X-Merchant-ID", fmt.Sprintf("%d", merchantID))
		}

		next(w, r)
	}
}

func (s *Server) verifyMasterKey(key string) bool {
	if s.cfg.Auth.MasterKey == "" {
		return false
	}
	return subtle.ConstantTimeCompare([]byte(key), []byte(s.cfg.Auth.MasterKey)) == 1
}

func (s *Server) lookupAPIKey(r *http.Request, key string) (int64, error) {
	// Try Redis cache first
	cacheKey := "fz:apikey:" + key
	val, err := s.rdb.Get(r.Context(), cacheKey).Result()
	if err == nil {
		var mid int64
		fmt.Sscanf(val, "%d", &mid)
		if mid > 0 {
			return mid, nil
		}
	}

	// Fallback to MySQL
	var merchantID int64
	prefix := s.cfg.Database.TablePrefix
	query := fmt.Sprintf("SELECT id FROM %sfz_merchants WHERE api_key = ? AND status = 'active' LIMIT 1", prefix)
	err = s.db.QueryRowContext(r.Context(), query, key).Scan(&merchantID)
	if err != nil {
		return 0, err
	}

	// Cache in Redis
	ttl := time.Duration(s.cfg.Auth.APIKeyCacheTTL) * time.Second
	if err := s.rdb.Set(r.Context(), cacheKey, fmt.Sprintf("%d", merchantID), ttl).Err(); err != nil {
		slog.Warn("redis cache set failed", "error", err)
	}

	return merchantID, nil
}

func (s *Server) Start() error {
	addr := fmt.Sprintf("%s:%d", s.cfg.Server.Host, s.cfg.Server.Port)
	s.srv = &http.Server{
		Addr:         addr,
		Handler:      s.logMiddleware(s.mux),
		ReadTimeout:  s.cfg.Server.ReadTimeout,
		WriteTimeout: s.cfg.Server.WriteTimeout,
	}
	slog.Info("starting server", "addr", addr)
	return s.srv.ListenAndServe()
}

func (s *Server) Shutdown() error {
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	return s.srv.Shutdown(ctx)
}

func (s *Server) logMiddleware(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		start := time.Now()
		lw := &loggedResponse{ResponseWriter: w, status: 200}
		next.ServeHTTP(lw, r)
		duration := time.Since(start)
		slog.Info("request",
			"method", r.Method,
			"path", r.URL.Path,
			"status", lw.status,
			"duration", duration.String(),
		)
	})
}

type loggedResponse struct {
	http.ResponseWriter
	status int
}

func (l *loggedResponse) WriteHeader(code int) {
	l.status = code
	l.ResponseWriter.WriteHeader(code)
}
