package main

import (
	"context"
	"database/sql"
	"flag"
	"fmt"
	"log/slog"
	"os"
	"os/signal"
	"sync"
	"syscall"
	"time"

	_ "github.com/go-sql-driver/mysql"
	"github.com/redis/go-redis/v9"

	"github.com/12fz/fz-signal-core/internal/api"
	"github.com/12fz/fz-signal-core/internal/config"
	"github.com/12fz/fz-signal-core/internal/queue"
)

var version = "2.1.0"

func main() {
	configPath := flag.String("config", "config.yaml", "path to config file")
	flag.Parse()

	// Load config
	cfg, err := config.Load(*configPath)
	if err != nil {
		slog.Error("failed to load config", "error", err)
		os.Exit(1)
	}

	slog.SetDefault(slog.New(slog.NewTextHandler(os.Stdout, &slog.HandlerOptions{
		Level: slog.LevelInfo,
	})))

	slog.Info("starting fz-signal-core", "version", version)

	// Connect Redis
	rdb := redis.NewClient(&redis.Options{
		Addr:     cfg.Redis.Address,
		Password: cfg.Redis.Password,
		DB:       cfg.Redis.DB,
	})
	defer rdb.Close()

	ctx := context.Background()
	if err := rdb.Ping(ctx).Err(); err != nil {
		slog.Error("redis connection failed", "error", err)
		os.Exit(1)
	}
	slog.Info("redis connected", "addr", cfg.Redis.Address)

	// Ensure Redis Streams consumer group exists
	if err := queue.EnsureGroup(ctx, rdb); err != nil {
		slog.Warn("redis stream group", "error", err)
	}

	// Connect MySQL
	dsn := fmt.Sprintf("%s:%s@tcp(%s:%d)/%s?charset=utf8mb4&parseTime=true&loc=Local",
		cfg.Database.User, cfg.Database.Password, cfg.Database.Host, cfg.Database.Port, cfg.Database.DBName)
	db, err := sql.Open("mysql", dsn)
	if err != nil {
		slog.Error("mysql connection failed", "error", err)
		os.Exit(1)
	}
	defer db.Close()

	db.SetMaxOpenConns(25)
	db.SetMaxIdleConns(5)
	db.SetConnMaxLifetime(5 * time.Minute)

	if err := db.PingContext(ctx); err != nil {
		slog.Error("mysql ping failed", "error", err)
		os.Exit(1)
	}
	slog.Info("mysql connected", "db", cfg.Database.DBName)

	// Create server
	srv := api.New(cfg, rdb, db)

	// Start timeout checker
	srv.StartTimeoutChecker(ctx)

	// Start stream consumer workers
	consumer := queue.NewConsumer(rdb)
	var wg sync.WaitGroup
	consumer.StartWorkers(ctx, &wg, 3, func(ctx context.Context, data map[string]interface{}) error {
		slog.Info("stream message received",
			"payload", fmt.Sprintf("%v", data))
		return nil
	})

	// Handle graceful shutdown
	sigCh := make(chan os.Signal, 1)
	signal.Notify(sigCh, syscall.SIGINT, syscall.SIGTERM)

	go func() {
		<-sigCh
		slog.Info("shutting down...")
		srv.Shutdown()
	}()

	// Start HTTP server
	if err := srv.Start(); err != nil {
		slog.Error("server error", "error", err)
	}

	// Wait for workers
	wg.Wait()
	slog.Info("shutdown complete")
}
