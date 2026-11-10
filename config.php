<?php
// Carga .env (sin dependencias) y devuelve el array de configuración.

function loadEnv(string $dir): void {
    $file = $dir . '/.env';
    if (!is_file($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $k = trim(substr($line, 0, $pos));
        $v = trim(substr($line, $pos + 1));
        if (strlen($v) >= 2 && $v[0] === '"' && $v[-1] === '"') $v = stripcslashes(substr($v, 1, -1));
        elseif (strlen($v) >= 2 && $v[0] === "'" && $v[-1] === "'") $v = substr($v, 1, -1);
        $_ENV[$k] = $v;
    }
}

function envv(string $key, string $default = ''): string {
    $v = $_ENV[$key] ?? getenv($key);
    return $v === false || $v === null ? $default : (string)$v;
}

loadEnv(__DIR__);

return [
    'APP_URL' => rtrim(envv('APP_URL', 'http://localhost:8000'), '/'),
    'APP_ENV' => envv('APP_ENV', 'dev'),
    'GOOGLE_CLIENT_ID' => envv('GOOGLE_CLIENT_ID', ''),
    'MISTRAL_API_KEY' => envv('MISTRAL_API_KEY', ''),
    'DB_HOST' => envv('DB_HOST', 'localhost'),
    'DB_NAME' => envv('DB_NAME', 'nutriplan'),
    'DB_USER' => envv('DB_USER', 'nutriplan'),
    'DB_PASS' => envv('DB_PASS', ''),
    'VAPID_PUBLIC_KEY' => envv('VAPID_PUBLIC_KEY', ''),
    'VAPID_PRIVATE_KEY' => envv('VAPID_PRIVATE_KEY', ''),
    'VAPID_SUBJECT' => envv('VAPID_SUBJECT', ''),
    'APP_VERSION' => trim(@file_get_contents(__DIR__ . '/version.txt') ?: '1.0.0'),
    'GITHUB_REPO' => envv('GITHUB_REPO', ''),
];
