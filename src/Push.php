<?php

declare(strict_types=1);

namespace NutriPlan;

require_once __DIR__ . '/Db.php';

use PDO;

/**
 * Push propias (Web Push + VAPID, RFC8292/RFC8291). Port de server/push.ts.
 * Requiere ext-openssl (curva P-256, ECDH, AES-128-GCM).
 */
class Push
{
    private const MAX = 500;
    private const TTL = 2419200; // 28 días

    private static function db(): PDO
    {
        return Db::pdo();
    }

    public static function b64uEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string
    {
        $bin = base64_decode(strtr($s, '-_', '+/'), true);
        if ($bin === false) {
            throw new \RuntimeException('base64url inválido');
        }
        return $bin;
    }

    /** Genera par EC P-256. Devuelve [pub crudo 65B, recurso privado]. */
    private static function genEphemeral(): array
    {
        $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($res === false) {
            throw new \RuntimeException('No se pudo generar clave EC P-256');
        }
        $d = openssl_pkey_get_details($res);
        return ["\x04" . $d['ec']['x'] . $d['ec']['y'], $res];
    }

    /** Clave pública VAPID para el frontend. Autogenera y persiste si no hay env. */
    public static function getVapidPublicKey(): string
    {
        $envPub = getenv('VAPID_PUBLIC_KEY') ?: '';
        $envPriv = self::envPrivatePem();
        if ($envPub !== '' && $envPriv !== '') {
            return $envPub;
        }
        $pub = Db::getSetting(self::db(), 'vapid_public');
        $pem = Db::getSetting(self::db(), 'vapid_private_pem');
        if ($pub && $pem && strlen(self::b64uDecode($pub)) === 65 && openssl_pkey_get_private($pem) !== false) {
            return $pub;
        }
        [$pubRaw, $privRes] = self::genEphemeral();
        openssl_pkey_export($privRes, $pem);
        Db::setSetting(self::db(), 'vapid_public', self::b64uEncode($pubRaw));
        Db::setSetting(self::db(), 'vapid_private_pem', $pem);
        return self::b64uEncode($pubRaw);
    }

    /** PEM privada desde env (acepta saltos reales o \n literales) o null. */
    private static function envPrivatePem(): string
    {
        $v = getenv('VAPID_PRIVATE_KEY') ?: '';
        if ($v === '') {
            return '';
        }
        $pem = str_replace('\n', "\n", $v);
        return openssl_pkey_get_private($pem) !== false ? $pem : '';
    }

    private static function vapidPrivatePem(): string
    {
        $env = self::envPrivatePem();
        if ($env !== '') {
            return $env;
        }
        self::getVapidPublicKey(); // asegura existencia
        return (string) Db::getSetting(self::db(), 'vapid_private_pem');
    }

    /** PEM pública a partir del punto crudo 65B (SubjectPublicKeyInfo P-256). */
    private static function pubPem(string $rawPoint): string
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $rawPoint;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** Firma ES256 y devuelve R||S crudo (64B). */
    private static function es256Sign(string $msg, string $privPem): string
    {
        if (!openssl_sign($msg, $der, $privPem, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Firma ES256 falló');
        }
        $p = 0;
        if (ord($der[$p++]) !== 0x30) {
            throw new \RuntimeException('DER inválido');
        }
        $p += (ord($der[$p]) & 0x80) ? (ord($der[$p]) & 0x7F) + 1 : 1;
        if (ord($der[$p++]) !== 0x02) {
            throw new \RuntimeException('DER inválido');
        }
        $rl = ord($der[$p++]);
        $r = substr($der, $p, $rl);
        $p += $rl;
        $p++; // 0x02
        $sl = ord($der[$p++]);
        $s = substr($der, $p, $sl);
        return str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT) . str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    }

    private static function hkdf(string $salt, string $ikm, string $info, int $len): string
    {
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $out = '';
        $prev = '';
        for ($i = 1; strlen($out) < $len; $i++) {
            $prev = hash_hmac('sha256', $prev . $info . chr($i), $prk, true);
            $out .= $prev;
        }
        return substr($out, 0, $len);
    }

    private static function jwt(string $audience): string
    {
        $sub = getenv('VAPID_SUBJECT') ?: 'mailto:admin@example.com';
        $head = self::b64uEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $body = self::b64uEncode(json_encode(['aud' => $audience, 'exp' => time() + 43200, 'sub' => $sub]));
        $sig = self::es256Sign($head . '.' . $body, self::vapidPrivatePem());
        return $head . '.' . $body . '.' . self::b64uEncode($sig);
    }

    /**
     * Cifra según RFC8291 aes128gcm. Devuelve cuerpo binario listo para POST.
     * @param array{endpoint:string,p256dh:string,auth:string} $sub
     */
    private static function encrypt(array $sub, string $payloadJson): string
    {
        $peerPub = self::b64uDecode($sub['p256dh']); // 65B 04||x||y
        $authSecret = self::b64uDecode($sub['auth']); // 16B
        if (strlen($peerPub) !== 65 || strlen($authSecret) !== 16) {
            throw new \RuntimeException('Suscripción inválida');
        }
        $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($eph === false) {
            throw new \RuntimeException('No se pudo generar clave efímera');
        }
        $ed = openssl_pkey_get_details($eph);
        $ephPubRaw = "\x04" . $ed['ec']['x'] . $ed['ec']['y'];
        $peerRes = openssl_pkey_get_public(self::pubPem($peerPub));
        if ($peerRes === false) {
            throw new \RuntimeException('Clave peer inválida');
        }
        $secret = openssl_pkey_derive($peerRes, $eph, 32);
        if ($secret === false || strlen($secret) !== 32) {
            throw new \RuntimeException('ECDH falló');
        }
        $salt = random_bytes(16);
        $prk = self::hkdf($authSecret, $secret, "WebPush: info\x00" . $peerPub . $ephPubRaw, 32);
        $cek = self::hkdf($salt, $prk, "Content-Encoding: aes128gcm\x00", 16);
        $nonce = self::hkdf($salt, $prk, "Content-Encoding: nonce\x00", 12);
        $ct = openssl_encrypt($payloadJson . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ct === false) {
            throw new \RuntimeException('Cifrado AES-GCM falló');
        }
        return $salt . "\x00\x00\x10\x00" . chr(65) . $ephPubRaw . $ct . $tag;
    }

    /**
     * Envía a una suscripción. Devuelve ['ok'=>bool,'status'=>int,'gone'=>bool].
     * @param array{endpoint:string,p256dh:string,auth:string} $sub
     */
    public static function sendToSubscription(array $sub, array $payload): array
    {
        $body = self::encrypt($sub, json_encode($payload, JSON_UNESCAPED_UNICODE));
        $parts = parse_url($sub['endpoint']);
        $aud = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        $ch = curl_init($sub['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'Encryption: salt=' . self::b64uEncode(substr($body, 0, 16)),
                'Crypto-Key: dh=' . self::b64uEncode(substr($body, 83, 65)) . ';p256ecdsa=' . self::getVapidPublicKey(),
                'Authorization: WebPush ' . self::jwt($aud),
                'TTL: ' . self::TTL,
            ],
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $ok = $code === 200 || $code === 201 || $code === 202;
        return ['ok' => $ok, 'status' => $code, 'gone' => $code === 404 || $code === 410];
    }

    public static function saveSubscription(?string $sub, string $endpoint, string $p256dh, string $auth): void
    {
        self::db()->prepare(
            'INSERT INTO push_subscriptions (endpoint, `sub`, p256dh, auth, created_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE `sub` = VALUES(`sub`), p256dh = VALUES(p256dh), auth = VALUES(auth)'
        )->execute([$endpoint, $sub, $p256dh, $auth, time()]);
    }

    public static function removeSubscription(string $endpoint): void
    {
        self::db()->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?')->execute([$endpoint]);
    }

    /** @return array<int,array{endpoint:string,sub:?string,p256dh:string,auth:string}> */
    public static function listSubscriptions(?string $sub): array
    {
        if ($sub === null) {
            $st = self::db()->query('SELECT endpoint, `sub`, p256dh, auth FROM push_subscriptions LIMIT ' . self::MAX);
        } else {
            $st = self::db()->prepare('SELECT endpoint, `sub`, p256dh, auth FROM push_subscriptions WHERE `sub` = ? LIMIT ' . self::MAX);
            $st->execute([$sub]);
        }
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function countSubscriptions(): int
    {
        return (int) self::db()->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn();
    }

    /** @return array{sent:int,failed:int,removed:int} */
    public static function broadcast(array $payload): array
    {
        $r = ['sent' => 0, 'failed' => 0, 'removed' => 0];
        $all = self::db()->query('SELECT endpoint, `sub`, p256dh, auth FROM push_subscriptions LIMIT ' . self::MAX)->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($all as $s) {
            try {
                $res = self::sendToSubscription($s, $payload);
                if ($res['gone']) {
                    self::removeSubscription($s['endpoint']);
                    $r['removed']++;
                } elseif ($res['ok']) {
                    $r['sent']++;
                } else {
                    $r['failed']++;
                }
            } catch (\Throwable) {
                $r['failed']++;
            }
        }
        return $r;
    }
}
