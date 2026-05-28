package queue

import (
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"sync"
	"time"

	"github.com/redis/go-redis/v9"
)

const (
	StreamKey     = "fz:messages"
	ConsumerGroup = "fz:core:writers"
)

type Producer struct {
	rdb *redis.Client
}

func NewProducer(rdb *redis.Client) *Producer {
	return &Producer{rdb: rdb}
}

func (p *Producer) Push(ctx context.Context, msg interface{}) (string, error) {
	data, err := json.Marshal(msg)
	if err != nil {
		return "", fmt.Errorf("marshal: %w", err)
	}
	return p.rdb.XAdd(ctx, &redis.XAddArgs{
		Stream: StreamKey,
		Values: map[string]interface{}{
			"payload": string(data),
			"time":    time.Now().Unix(),
		},
	}).Result()
}

type Consumer struct {
	rdb *redis.Client
}

func NewConsumer(rdb *redis.Client) *Consumer {
	return &Consumer{rdb: rdb}
}

func EnsureGroup(ctx context.Context, rdb *redis.Client) error {
	err := rdb.XGroupCreateMkStream(ctx, StreamKey, ConsumerGroup, "0").Err()
	if err != nil && err.Error() != "BUSYGROUP Consumer Group name already exists" {
		return fmt.Errorf("create group: %w", err)
	}
	return nil
}

func (c *Consumer) StartWorkers(ctx context.Context, wg *sync.WaitGroup, n int, handler func(context.Context, map[string]interface{}) error) {
	for i := 0; i < n; i++ {
		wg.Add(1)
		go func(id int) {
			defer wg.Done()
			c.workerLoop(ctx, id, handler)
		}(i)
	}
}

func (c *Consumer) workerLoop(ctx context.Context, workerID int, handler func(context.Context, map[string]interface{}) error) {
	defer func() {
		if r := recover(); r != nil {
			slog.Error("worker panic recovered", "worker_id", workerID, "panic", r)
		}
	}()

	for {
		select {
		case <-ctx.Done():
			slog.Info("worker stopping", "worker_id", workerID)
			return
		default:
		}

		result, err := c.rdb.XReadGroup(ctx, &redis.XReadGroupArgs{
			Group:    ConsumerGroup,
			Consumer: fmt.Sprintf("consumer-%d", workerID),
			Streams:  []string{StreamKey, ">"},
			Count:    10,
			Block:    3 * time.Second,
		}).Result()
		if err != nil {
			if err == redis.Nil {
				continue
			}
			slog.Error("xreadgroup error", "worker", workerID, "error", err)
			time.Sleep(time.Second)
			continue
		}

		for _, stream := range result {
			for _, msg := range stream.Messages {
				payload, ok := msg.Values["payload"].(string)
				if !ok {
					slog.Warn("invalid payload type", "msg_id", msg.ID)
					c.ack(ctx, stream.Stream, msg.ID)
					continue
				}
				var data map[string]interface{}
				if err := json.Unmarshal([]byte(payload), &data); err != nil {
					slog.Error("json unmarshal error", "msg_id", msg.ID, "error", err)
					c.ack(ctx, stream.Stream, msg.ID)
					continue
				}
				if err := handler(ctx, data); err != nil {
					slog.Error("handler failed", "msg_id", msg.ID, "error", err)
				}
				c.ack(ctx, stream.Stream, msg.ID)
			}
		}
	}
}

func (c *Consumer) ack(ctx context.Context, stream, msgID string) {
	if err := c.rdb.XAck(ctx, stream, ConsumerGroup, msgID).Err(); err != nil {
		slog.Error("xack error", "msg_id", msgID, "error", err)
	}
}

func TrimStream(ctx context.Context, rdb *redis.Client, maxLen int64) error {
	return rdb.XTrimApprox(ctx, StreamKey, maxLen).Err()
}
