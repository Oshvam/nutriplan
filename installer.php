<?php
// NutriPlan · Autoinstalador web estilo WordPress.
// Uso: sube este fichero + el ZIP de la app (nutriplan-vX.Y.Z.zip,
// generado en local con deploy-ftp.ps1 -Zip) a tu hosting y abre http://tu-dominio/installer.php
// Guia: 1) requisitos + ZIP, 2) extraer, 3) base de datos + .env, 4) listo.
// Al terminar, borrate a ti mismo con el boton. No requiere SSH ni Composer.
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
@ini_set('display_errors', '1');
@set_time_limit(300);

session_start();
$step = (int)($_GET['step'] ?? 1);
if ($_SERVER['REQUEST_METHOD'] === 'POST') $step = (int)($_POST['next_step'] ?? $step);
$err = '';
$msg = '';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function scheme(): string {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return 'https';
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return 'https';
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') return 'https';
    return 'http';
}
function baseUrl(): string {
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return scheme() . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($dir === '' ? '' : $dir);
}
function findAppRoot(string $dir): ?string {
    if (is_file($dir . '/config.php') && is_file($dir . '/public/index.php')) return $dir;
    foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
        if (is_file($sub . '/config.php') && is_file($sub . '/public/index.php')) return $sub;
    }
    return null;
}
function rrmdir(string $d): void {
    if (!is_dir($d)) return;
    foreach (scandir($d) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $d . '/' . $f;
        is_dir($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($d);
}
function rcopy(string $src, string $dst): void {
    if (is_dir($src)) {
        @mkdir($dst, 0775, true);
        foreach (scandir($src) as $f) {
            if ($f === '.' || $f === '..') continue;
            rcopy($src . '/' . $f, $dst . '/' . $f);
        }
    } else {
        @mkdir(dirname($dst), 0775, true);
        copy($src, $dst);
    }
}
function envq(string $v): string {
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
}
function importSchema(PDO $pdo, string $file): int {
    $sql = file_get_contents($file);
    $stmts = preg_split('/;[\r\n]+/', $sql);
    $n = 0;
    foreach ($stmts as $st) {
        $st = trim($st);
        if ($st === '' || str_starts_with($st, '--')) {
            $lines = array_filter(array_map('trim', explode("\n", $st)), fn($l) => $l !== '' && !str_starts_with($l, '--'));
            $st = implode("\n", $lines);
            if ($st === '') continue;
        }
        $pdo->exec($st);
        $n++;
    }
    return $n;
}

// ---- Paso 2: obtener y extraer el ZIP ----
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $target = trim($_POST['target'] ?? 'nutriplan');
    if ($target === '' || $target === '.' || $target === '/') $targetDir = getcwd();
    else {
        if (!preg_match('#^[A-Za-z0-9_-]+$#', $target)) $err = 'Nombre de carpeta no valido (usa letras, numeros, - o _).';
        else $targetDir = getcwd() . '/' . $target;
    }
    if ($err === '') {
        $src = $_POST['zipsrc'] ?? 'upload';
        $zipPath = '';
        $localZips = array_map('basename', glob(getcwd() . '/*.zip') ?: []);
        if ($src === 'upload' && !empty($_FILES['zipfile']['tmp_name']) && is_uploaded_file($_FILES['zipfile']['tmp_name'])) {
            $zipPath = getcwd() . '/' . basename($_FILES['zipfile']['name']);
            if (!@move_uploaded_file($_FILES['zipfile']['tmp_name'], $zipPath)) $err = 'No se pudo guardar el ZIP subido (revisa upload_max_filesize).';
        } elseif ($src === 'existing' && !empty($_POST['zipname'])) {
            $zipPath = getcwd() . '/' . basename($_POST['zipname']);
            if (!is_file($zipPath)) $err = 'El ZIP indicado no existe.';
        } elseif ($src === 'upload' && $localZips) {
            $zipPath = getcwd() . '/' . $localZips[0];
        } elseif ($src === 'github') {
            $repo = trim($_POST['repo'] ?? 'Oshvam/nutriplan');
            if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) $err = 'Repo no valido (formato usuario/nombre).';
            else {
                $zipPath = getcwd() . '/nutriplan-github.zip';
                $url = 'https://codeload.github.com/' . $repo . '/zip/refs/heads/main';
                $ch = curl_init($url);
                $fp = fopen($zipPath, 'wb');
                curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120, CURLOPT_USERAGENT => 'NutriPlan-Installer']);
                $ok = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch); fclose($fp);
                if (!$ok || $code >= 400) { @unlink($zipPath); $err = 'No se pudo descargar el ZIP de GitHub (HTTP ' . $code . ').'; }
            }
        } else $err = 'Indica un origen para el ZIP.';
    }
    if ($err === '' && !class_exists('ZipArchive')) $err = 'Falta ZipArchive en este PHP (pide a tu hosting activar php-zip).';
    if ($err === '') {
        $tmp = getcwd() . '/.np-install-tmp';
        rrmdir($tmp); @mkdir($tmp, 0775, true);
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) $err = 'No se pudo abrir el ZIP (fichero corrupto?).';
        else { $zip->extractTo($tmp); $zip->close(); }
        $appRoot = $err === '' ? findAppRoot($tmp) : null;
        if ($err === '' && $appRoot === null) { $err = 'El ZIP no contiene la app (falta config.php + public/index.php).'; rrmdir($tmp); }
    }
    if ($err === '') {
        @mkdir($targetDir, 0775, true);
        rcopy($appRoot, $targetDir);
        rrmdir($tmp);
        if (!empty($_POST['delzip']) && realpath($zipPath) !== __FILE__) @unlink($zipPath);
        $_SESSION['np_appdir'] = $targetDir;
        $msg = 'App extraida en ' . h($targetDir);
        $step = 3;
    } else $step = 1;
}

// ---- Paso 4: conectar BD, importar esquema, escribir .env ----
if ($step === 4 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = trim($_POST['appdir'] ?? '');
    if ($posted === '' || $posted === '.') $appdir = getcwd();
    elseif (preg_match('#^[A-Za-z0-9_-]+$#', $posted)) $appdir = getcwd() . '/' . $posted;
    else $appdir = $_SESSION['np_appdir'] ?? getcwd();
    if (!is_file($appdir . '/config.php')) { $err = 'No se encuentra la app (repite el paso 2).'; $step = 1; }
    else {
        $db = array_map('trim', [
            'host' => $_POST['db_host'] ?? 'localhost', 'name' => $_POST['db_name'] ?? '',
            'user' => $_POST['db_user'] ?? '', 'pass' => $_POST['db_pass'] ?? '',
        ]);
        try {
            $pdo = new PDO("mysql:host={$db['host']};charset=utf8mb4", $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            try { $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $db['name']) . '` CHARACTER SET utf8mb4'); } catch (Throwable $e) {}
            $pdo->exec('USE `' . str_replace('`', '', $db['name']) . '`');
            $n = importSchema($pdo, $appdir . '/database/schema.sql');
        } catch (Throwable $e) { $err = 'BD: ' . $e->getMessage(); $step = 3; }
    }
    if ($err === '') {
        $appUrl = rtrim(trim($_POST['app_url'] ?? ''), '/');
        $env = '# Generado por installer.php el ' . date('Y-m-d H:i') . "\n"
            . 'APP_URL=' . envq($appUrl) . "\n"
            . 'APP_ENV=' . envq('prod') . "\n\n"
            . 'GOOGLE_CLIENT_ID=' . envq(trim($_POST['google_id'] ?? '')) . "\n\n"
            . 'MISTRAL_API_KEY=' . envq(trim($_POST['mistral'] ?? '')) . "\n\n"
            . 'DB_HOST=' . envq($db['host']) . "\n"
            . 'DB_NAME=' . envq($db['name']) . "\n"
            . 'DB_USER=' . envq($db['user']) . "\n"
            . 'DB_PASS=' . envq($db['pass']) . "\n\n"
            . '# GITHUB_REPO=' . envq(trim($_POST['repo2'] ?? '')) . "\n";
        if (@file_put_contents($appdir . '/.env', $env) === false) { $err = 'No se pudo escribir .env (permisos).'; $step = 3; }
        else {
            @mkdir($appdir . '/var', 0775, true);
            $_SESSION['np_done'] = ['url' => $appUrl, 'stmts' => $n, 'appdir' => $appdir];
            $step = 5;
        }
    }
}

// ---- Borrado del installer ----
if (isset($_GET['bye'])) {
    $self = __FILE__;
    echo '<p>Listo. Puedes cerrar esta pestana.</p>';
    @unlink($self);
    exit;
}

$appdirGuess = $_SESSION['np_appdir'] ?? null;
$alreadyEnv = $appdirGuess && is_file($appdirGuess . '/.env');
$zips = array_map('basename', glob(getcwd() . '/*.zip') ?: []);
$checks = [
    'PHP >= 8.1 (tienes ' . PHP_VERSION . ')' => version_compare(PHP_VERSION, '8.1', '>='),
    'ext-zip (ZipArchive)' => class_exists('ZipArchive'),
    'ext-pdo_mysql' => extension_loaded('pdo_mysql'),
    'ext-openssl' => extension_loaded('openssl'),
    'ext-curl' => extension_loaded('curl'),
    'ext-mbstring' => extension_loaded('mbstring'),
    'Carpeta escribible' => is_writable(getcwd()),
];
$allOk = !in_array(false, $checks, true);
$guessUrl = baseUrl() . ((($_SESSION['np_appdir'] ?? getcwd()) === getcwd()) ? '' : '/' . basename($_SESSION['np_appdir'] ?? ''));
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>NutriPlan · Instalador</title>
<style>
body{font-family:system-ui,Segoe UI,Roboto,sans-serif;background:#f1f5f9;color:#0f172a;margin:0;padding:24px}
.card{max-width:640px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px}
.brand{display:flex;align-items:center;gap:10px;margin-bottom:4px}
.logo{width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,#059669,#2dd4bf);display:flex;align-items:center;justify-content:center;color:#fff;flex-shrink:0}
.logo svg{width:22px;height:22px}
.name{font-size:22px;font-weight:800;letter-spacing:-0.02em}.name small{font-weight:400;color:#64748b;font-size:15px}
h1{font-size:22px;margin:0 0 4px}.sub{color:#64748b;margin:0 0 16px}
.ok{color:#15803d}.bad{color:#dc2626}
ul.check{list-style:none;padding:0}ul.check li{margin:4px 0}
label{display:block;margin:10px 0 2px;font-weight:600}
input[type=text],input[type=password],select{width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box}
button{background:#059669;color:#fff;border:0;border-radius:8px;padding:10px 18px;font-size:15px;cursor:pointer}
button.sec{background:#e2e8f0;color:#0f172a}
.err{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:10px;border-radius:8px;margin-bottom:12px}
.msg{background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;padding:10px;border-radius:8px;margin-bottom:12px}
.steps{color:#64748b;margin-bottom:16px}
a{color:#059669}
</style>
</head>
<body>
<div class="card">
<div class="brand"><span class="logo"><i data-lucide="apple"></i></span><span class="name">NutriPlan <small>· Instalador</small></span></div>
<p class="sub">Paso <?= [1 => 1, 2 => 2, 3 => 3][$step] ?? 4 ?> de 4</p>
<?php if ($err !== '') echo '<div class="err">' . h($err) . '</div>'; ?>
<?php if ($msg !== '') echo '<div class="msg">' . $msg . '</div>'; ?>

<?php if ($step <= 1): ?>
<p>Sube el ZIP de la app junto a este fichero (o descargalo de GitHub) y lo extraigo por ti.</p>
<ul class="check">
<?php foreach ($checks as $k => $v): ?>
<li class="<?= $v ? 'ok' : 'bad' ?>"><?= $v ? '✓' : '✗' ?> <?= h($k) ?></li>
<?php endforeach; ?>
</ul>
<?php if (!$allOk): ?><div class="err">Falta algun requisito: pide a tu hosting que lo active.</div><?php endif; ?>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="next_step" value="2">
<label><input type="radio" name="zipsrc" value="upload" <?= $zips ? '' : 'checked' ?>> Subir ZIP desde mi equipo</label>
<input type="file" name="zipfile" accept=".zip">
<label style="margin-top:12px"><input type="radio" name="zipsrc" value="existing" <?= $zips ? 'checked' : 'disabled' ?>> Usar ZIP ya subido</label>
<?php if ($zips): ?><select name="zipname"><?php foreach ($zips as $z): ?><option><?= h($z) ?></option><?php endforeach; ?></select>
<?php else: ?><p class="sub">No hay ningun .zip junto al installer.</p><?php endif; ?>
<label style="margin-top:12px"><input type="radio" name="zipsrc" value="github"> Descargar de GitHub (rama main)</label>
<input type="text" name="repo" value="Oshvam/nutriplan" placeholder="usuario/repo">
<label>Carpeta destino (vacio = aqui mismo, p. ej. tu public_html)</label>
<input type="text" name="target" value="" placeholder="p. ej. nutriplan">
<label><input type="checkbox" name="delzip" value="1" checked> Borrar el ZIP despues de extraer</label>
<p><button <?= $allOk ? '' : 'disabled' ?>>Extraer y continuar →</button></p>
</form>

<?php elseif ($step === 3): $appdir = $_SESSION['np_appdir'] ?? getcwd(); ?>
<?php if (is_file($appdir . '/.env')) echo '<div class="err">Ya existe un .env en la app: si continuas lo SOBREESCRIBIRE.</div>'; ?>
<form method="post">
<input type="hidden" name="next_step" value="4">
<input type="hidden" name="appdir" value="<?= h($appdir === getcwd() ? '.' : basename($appdir)) ?>">
<label>MySQL host</label><input type="text" name="db_host" value="localhost" required>
<label>Nombre de la base de datos (ya creada en tu panel)</label><input type="text" name="db_name" value="nutriplan" required>
<label>Usuario MySQL</label><input type="text" name="db_user" required>
<label>Contrasena MySQL</label><input type="password" name="db_pass">
<label>URL publica de la app</label><input type="text" name="app_url" value="<?= h($guessUrl) ?>" required>
<label>GOOGLE_CLIENT_ID (login obligatorio)</label><input type="text" name="google_id" placeholder="xxxx.apps.googleusercontent.com">
<label>MISTRAL_API_KEY (opcional, recomendaciones IA)</label><input type="text" name="mistral">
<label>GITHUB_REPO para auto-actualizar (opcional)</label><input type="text" name="repo2" value="Oshvam/nutriplan">
<p><button>Crear .env e importar la base →</button></p>
</form>

<?php else:
$d = $_SESSION['np_done'] ?? ['url' => '', 'stmts' => 0, 'appdir' => ''];
$appUrl = $d['url'];
$pubNote = (($_SESSION['np_appdir'] ?? getcwd()) === getcwd())
  ? 'Tu dominio debe apuntar a la carpeta <b>public/</b> (en DirectAdmin: cambia el DocumentRoot).'
  : 'Abre <b>' . h($appUrl) . '/public</b> o apunta el dominio a la carpeta <b>public/</b> dentro de ' . h(basename($d['appdir'] ?? '')) . '.';
?>
<div class="msg">Instalacion completa: esquema importado (<?= (int)($d['stmts'] ?? 0) ?> sentencias) y .env creado.</div>
<p><?= $pubNote ?></p>
<p>Anade <b><?= h($appUrl) ?></b> a los origenes autorizados de tu cliente Google (tarda unos minutos en propagarse).</p>
<p>
<a href="<?= h($appUrl) ?>"><button type="button">Abrir la app →</button></a>
<a href="?bye=1" onclick="return confirm('Se borrara installer.php. ¿Seguir?')"><button type="button" class="sec">Borrar installer.php</button></a>
</p>
<?php endif; ?>
</div>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
