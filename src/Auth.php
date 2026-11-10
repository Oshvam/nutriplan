<?php
namespace NutriPlan;

use PDO;

// Login con Google + cookie de sesión. Replica server/auth.ts.
class Auth {
    public const COOKIE = 'nutriplan_session';
    private const MAX_AGE = 30 * 24 * 60 * 60; // 30 días

    /** Verifica el idToken contra tokeninfo. Devuelve ['sub','email','name','picture']. */
    public static function verifyGoogleIdToken(string $idToken, string $clientId): array {
        if ($clientId === '') throw new \RuntimeException('GOOGLE_CLIENT_ID no configurado en el servidor');
        $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !is_string($body)) throw new \RuntimeException('Token de Google inválido');
        $info = json_decode($body, true);
        if (!is_array($info)) throw new \RuntimeException('Token de Google inválido');
        if (($info['aud'] ?? '') !== $clientId) throw new \RuntimeException('Token emitido para otro cliente');
        if ((int)($info['exp'] ?? 0) * 1000 < (int)(microtime(true) * 1000)) throw new \RuntimeException('Token caducado');
        if (empty($info['sub'])) throw new \RuntimeException('Token sin sujeto');
        return [
            'sub' => (string)$info['sub'],
            'email' => $info['email'] ?? null,
            'name' => $info['name'] ?? null,
            'picture' => $info['picture'] ?? null,
        ];
    }

    public static function isSecure(array $config): bool {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
        $proto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0]));
        if ($proto === 'https') return true;
        return ($config['APP_ENV'] ?? '') === 'production';
    }

    public static function login(PDO $pdo, string $sub, ?string $email, ?string $name, ?string $picture, bool $secure): void {
        Db::upsertUser($pdo, $sub, $email, $name, $picture);
        $token = Db::createSession($pdo, $sub);
        setcookie(self::COOKIE, $token, [
            'expires' => time() + self::MAX_AGE,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $secure,
        ]);
    }

    public static function logout(PDO $pdo): void {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if (is_string($token) && $token !== '') Db::deleteSession($pdo, $token);
        setcookie(self::COOKIE, '', ['expires' => 1, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    }

    public static function currentSub(PDO $pdo): ?string {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($token) || $token === '') return null;
        return Db::getSessionSub($pdo, $token);
    }
}
