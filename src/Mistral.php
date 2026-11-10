<?php

declare(strict_types=1);

namespace NutriPlan;

require_once __DIR__ . '/Db.php';

use PDO;

/**
 * Ajustes y cliente de Mistral. Port de server/mistral.ts.
 * La API key vive SOLO en el servidor: settings DB o MISTRAL_API_KEY (tiene prioridad).
 */
class Mistral
{
    public const MODELS = ['mistral-small-latest', 'mistral-medium-latest', 'mistral-large-latest', 'open-mistral-nemo'];
    private const URL = 'https://api.mistral.ai/v1/chat/completions';

    private static function db(): PDO
    {
        return Db::pdo();
    }

    public static function getSettings(): array
    {
        $key = Db::getSetting(self::db(), 'mistral_api_key') ?? '';
        $env = getenv('MISTRAL_API_KEY') ?: '';
        if ($env !== '') {
            $key = $env;
        }
        $model = Db::getSetting(self::db(), 'mistral_model') ?? '';
        if (!in_array($model, self::MODELS, true)) {
            $model = 'mistral-small-latest';
        }
        return ['apiKey' => $key, 'model' => $model, 'configured' => $key !== '', 'models' => self::MODELS];
    }

    /** @param array{apiKey?:string,model?:string} $patch */
    public static function saveSettings(array $patch): array
    {
        if (array_key_exists('apiKey', $patch)) {
            $v = trim((string) $patch['apiKey']);
            if ($v === '') {
                Db::deleteSetting(self::db(), 'mistral_api_key');
            } else {
                Db::setSetting(self::db(), 'mistral_api_key', $v);
            }
        }
        if (array_key_exists('model', $patch) && in_array($patch['model'], self::MODELS, true)) {
            Db::setSetting(self::db(), 'mistral_model', $patch['model']);
        }
        return self::getSettings();
    }

    public static function clearApiKey(): void
    {
        Db::deleteSetting(self::db(), 'mistral_api_key');
    }

    public static function maskKey(string $key): string
    {
        $len = strlen($key);
        if ($len <= 8) {
            return $len > 0 ? '****' : '';
        }
        return substr($key, 0, 4) . '...' . substr($key, -4);
    }

    /**
     * @param array<int,array{role:string,content:string}> $messages
     * @throws \RuntimeException
     */
    public static function chat(array $messages, bool $json = false, int $maxTokens = 3000, ?string $apiKey = null, ?string $model = null): string
    {
        $s = self::getSettings();
        $key = $apiKey ?? $s['apiKey'];
        if ($key === '') {
            throw new \RuntimeException('Mistral no configurado: falta la API key');
        }
        $body = [
            'model' => $model ?? $s['model'],
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => $maxTokens,
        ];
        if ($json) {
            $body['response_format'] = ['type' => 'json_object'];
        }
        try {
            return self::post($body, $key);
        } catch (\RuntimeException $e) {
            // Reintento sin response_format (algunos modelos lo rechazan con 400).
            if ($json && str_contains($e->getMessage(), ' 400 ')) {
                unset($body['response_format']);
                return self::post($body, $key);
            }
            throw $e;
        }
    }

    /** @throws \RuntimeException */
    public static function testConnection(?string $apiKey = null, ?string $model = null): string
    {
        return self::chat([['role' => 'user', 'content' => 'Responde exactamente: OK']], false, 10, $apiKey, $model);
    }

    /** @throws \RuntimeException */
    private static function post(array $body, string $key): string
    {
        $ch = curl_init(self::URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
            CURLOPT_TIMEOUT => 60,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new \RuntimeException('Error de red con Mistral: ' . $err);
        }
        $data = json_decode((string) $raw, true);
        if ($code < 200 || $code >= 300) {
            $msg = is_array($data) ? (($data['message'] ?? '') ?: json_encode($data)) : substr((string) $raw, 0, 200);
            throw new \RuntimeException("Mistral {$code} {$msg}");
        }
        $text = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
        if ($text === '') {
            throw new \RuntimeException('Mistral devolvió una respuesta vacía');
        }
        return $text;
    }
}
