<?php
namespace NutriPlan;

use PDO;

// Acceso a MySQL + funciones que replican server/db.ts (usuarios, sesiones, kv)
// más la tabla settings (ajustes Mistral, claves VAPID).
class Db {
    private static ?PDO $pdo = null;
    private static ?array $cfg = null;

    public static function pdo(?array $c = null): PDO {
        if ($c !== null) self::$cfg = $c;
        if (!self::$pdo) {
            if (self::$cfg === null) throw new \RuntimeException('Db sin configurar');
            $c = self::$cfg;
            $dsn = "mysql:host={$c['DB_HOST']};dbname={$c['DB_NAME']};charset=utf8mb4";
            self::$pdo = new PDO($dsn, $c['DB_USER'], $c['DB_PASS'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::ensureSchema(self::$pdo);
        }
        return self::$pdo;
    }

    private static function ensureSchema(PDO $pdo): void {
        $q = $pdo->query("SHOW TABLES LIKE 'users'");
        if ($q && $q->fetch()) return;
        $sql = file_get_contents(__DIR__ . '/../database/schema.sql');
        foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') $pdo->exec($stmt);
        }
    }

    // --- users ---

    public static function upsertUser(PDO $pdo, string $sub, ?string $email, ?string $name, ?string $picture): void {
        $now = (int)(microtime(true) * 1000);
        $pdo->prepare(
            "INSERT INTO users (sub, email, name, picture, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE email=VALUES(email), name=VALUES(name), picture=VALUES(picture), updated_at=VALUES(updated_at)"
        )->execute([$sub, $email, $name, $picture, $now, $now]);
    }

    public static function getUser(PDO $pdo, string $sub): ?array {
        $st = $pdo->prepare('SELECT sub, email, name, picture FROM users WHERE sub = ?');
        $st->execute([$sub]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // --- sessions (TTL 30 días) ---

    public static function createSession(PDO $pdo, string $sub): string {
        $token = bin2hex(random_bytes(32));
        $now = (int)(microtime(true) * 1000);
        $pdo->prepare('DELETE FROM sessions WHERE sub = ? AND expires_at <= ?')->execute([$sub, $now]);
        $pdo->prepare('INSERT INTO sessions (token, sub, expires_at) VALUES (?, ?, ?)')
            ->execute([$token, $sub, $now + 30 * 24 * 60 * 60 * 1000]);
        return $token;
    }

    public static function getSessionSub(PDO $pdo, string $token): ?string {
        $st = $pdo->prepare('SELECT sub, expires_at FROM sessions WHERE token = ?');
        $st->execute([$token]);
        $row = $st->fetch();
        if (!$row) return null;
        if ((int)$row['expires_at'] <= (int)(microtime(true) * 1000)) {
            $pdo->prepare('DELETE FROM sessions WHERE token = ?')->execute([$token]);
            return null;
        }
        return $row['sub'];
    }

    public static function deleteSession(PDO $pdo, string $token): void {
        $pdo->prepare('DELETE FROM sessions WHERE token = ?')->execute([$token]);
    }

    // --- kv (snapshot por cuenta: perfil, pesos, plan, recetas IA) ---

    /** @return array<string,string> */
    public static function getAllKv(PDO $pdo, string $sub): array {
        $st = $pdo->prepare('SELECT `key`, `value` FROM kv WHERE `sub` = ?');
        $st->execute([$sub]);
        $out = [];
        foreach ($st as $r) $out[$r['key']] = $r['value'];
        return $out;
    }

    public static function putKv(PDO $pdo, string $sub, string $key, string $value): void {
        $now = (int)(microtime(true) * 1000);
        $pdo->prepare(
            "INSERT INTO kv (`sub`, `key`, `value`, updated_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_at=VALUES(updated_at)"
        )->execute([$sub, $key, $value, $now]);
    }

    // --- settings (clave/valor globales: mistral, vapid) ---

    public static function getSetting(PDO $pdo, string $key): ?string {
        $st = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = ?');
        $st->execute([$key]);
        $row = $st->fetch();
        return $row ? (string)$row['value'] : null;
    }

    public static function setSetting(PDO $pdo, string $key, string $value): void {
        $now = (int)(microtime(true) * 1000);
        $pdo->prepare(
            "INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_at=VALUES(updated_at)"
        )->execute([$key, $value, $now]);
    }

    public static function deleteSetting(PDO $pdo, string $key): void {
        $pdo->prepare('DELETE FROM settings WHERE `key` = ?')->execute([$key]);
    }
}
