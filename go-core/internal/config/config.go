package config

import (
	"os"
	"strconv"
	"time"

	"gopkg.in/yaml.v3"
)

type Config struct {
	Server   ServerConfig   `yaml:"server"`
	Redis    RedisConfig    `yaml:"redis"`
	Database DatabaseConfig `yaml:"database"`
	Auth     AuthConfig     `yaml:"auth"`
	Relay    RelayConfig    `yaml:"relay"`
}

type ServerConfig struct {
	Host         string        `yaml:"host"`
	Port         int           `yaml:"port"`
	ReadTimeout  time.Duration `yaml:"read_timeout"`
	WriteTimeout time.Duration `yaml:"write_timeout"`
}

type RedisConfig struct {
	Address  string `yaml:"address"`
	Password string `yaml:"password"`
	DB       int    `yaml:"db"`
}

type DatabaseConfig struct {
	Host        string `yaml:"host"`
	Port        int    `yaml:"port"`
	User        string `yaml:"user"`
	Password    string `yaml:"password"`
	DBName      string `yaml:"dbname"`
	TablePrefix string `yaml:"table_prefix"`
}

type AuthConfig struct {
	MasterKey      string `yaml:"master_key"`
	APIKeyCacheTTL int    `yaml:"api_key_cache_ttl"`
}

type RelayConfig struct {
	TimeoutSeconds  int `yaml:"timeout_seconds"`
	CheckInterval   int `yaml:"check_interval"`
	StreamMaxLength int `yaml:"stream_max_length"`
}

func Load(path string) (*Config, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	cfg := &Config{}
	if err := yaml.Unmarshal(data, cfg); err != nil {
		return nil, err
	}
	applyEnvOverrides(cfg)
	return cfg, nil
}

func DefaultConfig() *Config {
	return &Config{
		Server: ServerConfig{
			Host:         "0.0.0.0",
			Port:         8080,
			ReadTimeout:  30 * time.Second,
			WriteTimeout: 30 * time.Second,
		},
		Redis: RedisConfig{
			Address: "127.0.0.1:6379",
			DB:      0,
		},
		Database: DatabaseConfig{
			Host:        "127.0.0.1",
			Port:        3306,
			User:        "wp_12fz_dev",
			Password:    "WpDev2026",
			DBName:      "wp_12fz_dev",
			TablePrefix: "wp_",
		},
		Auth: AuthConfig{
			MasterKey:      "change-me-in-production",
			APIKeyCacheTTL: 300,
		},
		Relay: RelayConfig{
			TimeoutSeconds:  120,
			CheckInterval:   30,
			StreamMaxLength: 10000,
		},
	}
}

func applyEnvOverrides(cfg *Config) {
	if h := os.Getenv("SERVER_HOST"); h != "" {
		cfg.Server.Host = h
	}
	if p := os.Getenv("SERVER_PORT"); p != "" {
		if port, err := strconv.Atoi(p); err == nil {
			cfg.Server.Port = port
		}
	}
	if addr := os.Getenv("REDIS_ADDRESS"); addr != "" {
		cfg.Redis.Address = addr
	}
	if pw := os.Getenv("REDIS_PASSWORD"); pw != "" {
		cfg.Redis.Password = pw
	}
	if db := os.Getenv("REDIS_DB"); db != "" {
		if d, err := strconv.Atoi(db); err == nil {
			cfg.Redis.DB = d
		}
	}
	if u := os.Getenv("DB_USER"); u != "" {
		cfg.Database.User = u
	}
	if pw := os.Getenv("DB_PASSWORD"); pw != "" {
		cfg.Database.Password = pw
	}
	if h := os.Getenv("DB_HOST"); h != "" {
		cfg.Database.Host = h
	}
	if p := os.Getenv("DB_PORT"); p != "" {
		if port, err := strconv.Atoi(p); err == nil {
			cfg.Database.Port = port
		}
	}
	if n := os.Getenv("DB_NAME"); n != "" {
		cfg.Database.DBName = n
	}
	if mk := os.Getenv("MASTER_KEY"); mk != "" {
		cfg.Auth.MasterKey = mk
	}
}
