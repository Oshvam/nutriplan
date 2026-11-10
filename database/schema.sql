-- Esquema MySQL/MariaDB de NutriPlan PHP. Se aplica solo con `mysql`
-- o automáticamente al arrancar (Db::ensureSchema) si falta la tabla users.
CREATE TABLE IF NOT EXISTS users (
  sub VARCHAR(255) PRIMARY KEY,
  email VARCHAR(255) NULL,
  name VARCHAR(255) NULL,
  picture TEXT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sessions (
  token VARCHAR(128) PRIMARY KEY,
  sub VARCHAR(255) NOT NULL,
  expires_at BIGINT NOT NULL,
  INDEX idx_sessions_sub (sub),
  INDEX idx_sessions_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS kv (
  `sub` VARCHAR(255) NOT NULL,
  `key` VARCHAR(191) NOT NULL,
  `value` MEDIUMTEXT NOT NULL,
  updated_at BIGINT NOT NULL,
  PRIMARY KEY (`sub`, `key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS push_subscriptions (
  endpoint VARCHAR(767) PRIMARY KEY,
  `sub` VARCHAR(255) NULL,
  p256dh TEXT NOT NULL,
  auth TEXT NOT NULL,
  created_at BIGINT NOT NULL,
  INDEX idx_push_sub (`sub`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(191) PRIMARY KEY,
  `value` TEXT NOT NULL,
  updated_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
