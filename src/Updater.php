<?php
namespace NutriPlan;

use PDO;
use ZipArchive;

// Actualización automática desde el código de GitHub.
// Flujo: el ZIP de instalación se genera en local (deploy-ftp.ps1 -Zip);
// GitHub solo sirve para cotejar version.txt y descargar el código nuevo.
// Descarga el zip de la rama main, lo vuelca sobre public/src/
// templates/database (+composer.json/lock y version.txt), preservando
// .env y var/, con copia de seguridad previa en var/backups/.
class Updater {
    const DIRS = ['public', 'src', 'templates', 'database'];
    const FILES = ['composer.json', 'composer.lock', 'version.txt'];

    public static function current(): string {
        $f = dirname(__DIR__) . '/version.txt';
        return is_file($f) ? trim((string)file_get_contents($f)) : '1.0.0';
    }

    public static function latest(string $repo): array {
        if ($repo === '') return ['error' => 'Sin repo configurado (GITHUB_REPO en .env)'];
        // Cotejo de versión: version.txt de la rama main (sin releases).
        $ch = curl_init('https://raw.githubusercontent.com/' . $repo . '/main/version.txt');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['User-Agent: NutriPlan-Updater'],
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false || $code !== 200) return ['error' => "GitHub ($code): no se pudo leer version.txt"];
        $tag = trim((string)$raw);
        if (!preg_match('/^\\d+\\.\\d+\\.\\d+$/', $tag)) return ['error' => 'version.txt remoto inválido'];
        // Código nuevo: zip de la rama (misma forma que el generado en local).
        return ['tag' => $tag, 'zip' => 'https://codeload.github.com/' . $repo . '/zip/refs/heads/main', 'notes' => ''];
    }

    public static function run(PDO $pdo, string $repo, string $current): array {
        $lat = self::latest($repo);
        if (isset($lat['error'])) return ['success' => false, 'error' => $lat['error']];
        if (version_compare($lat['tag'], $current, '<=')) {
            return ['success' => true, 'updated' => false, 'current' => $current, 'message' => 'Ya estás en la última versión'];
        }
        if (empty($lat['zip'])) return ['success' => false, 'error' => 'Release sin zip descargable'];
        if (!class_exists('ZipArchive')) return ['success' => false, 'error' => 'Falta la extensión zip de PHP'];
        $root = dirname(__DIR__);
        $tmp = $root . '/var/cache/upd_' . date('YmdHis');
        if (!mkdir($tmp, 0777, true)) return ['success' => false, 'error' => 'No se puede escribir en var/cache'];
        $zipFile = $tmp . '/update.zip';
        try {
            // Descarga
            $ch = curl_init($lat['zip']);
            $fh = fopen($zipFile, 'w');
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 180,
                CURLOPT_HTTPHEADER => ['User-Agent: NutriPlan-Updater'],
            ]);
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            fclose($fh);
            if ($code !== 200 || filesize($zipFile) < 1000) throw new \RuntimeException('Descarga fallida (HTTP ' . $code . ')');
            // Extracción (el zip de GitHub trae una sola carpeta raíz)
            $zip = new ZipArchive();
            if ($zip->open($zipFile) !== true) throw new \RuntimeException('Zip corrupto');
            $zip->extractTo($tmp . '/src');
            $zip->close();
            $subs = array_values(array_filter(glob($tmp . '/src/*'), 'is_dir'));
            if (!$subs) throw new \RuntimeException('Zip vacío');
            $src = $subs[0];
            // Copia de seguridad
            $bak = $root . '/var/backups/' . $current . '_' . date('YmdHis');
            mkdir($bak, 0777, true);
            foreach (array_merge(self::DIRS, self::FILES) as $rel) {
                if (file_exists($root . '/' . $rel)) self::copyInto($root . '/' . $rel, $bak . '/' . $rel);
            }
            // Volcado (directorios se sustituyen enteros; .env y var/ ni se tocan)
            foreach (self::DIRS as $rel) {
                if (is_dir($src . '/' . $rel)) {
                    self::rrmdir($root . '/' . $rel);
                    self::copyInto($src . '/' . $rel, $root . '/' . $rel);
                }
            }
            foreach (self::FILES as $rel) {
                if (is_file($src . '/' . $rel)) copy($src . '/' . $rel, $root . '/' . $rel);
            }
            file_put_contents($root . '/version.txt', $lat['tag'] . "\n");
            // Migraciones (schema idempotente) + limpieza de cachés
            self::applySchema($pdo, $root . '/database/schema.sql');
            self::rrmdir($root . '/var/cache/twig');
            self::rrmdir($tmp);
            // Solo las 3 últimas copias
            $baks = glob($root . '/var/backups/*') ?: [];
            sort($baks);
            while (count($baks) > 3) self::rrmdir(array_shift($baks));
            if (function_exists('opcache_reset')) @opcache_reset();
            return ['success' => true, 'updated' => true, 'from' => $current, 'to' => $lat['tag'],
                'message' => "Actualizado de $current a {$lat['tag']}. Copia de seguridad en var/backups/."];
        } catch (\Throwable $e) {
            self::rrmdir($tmp);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private static function applySchema(PDO $pdo, string $file): void {
        if (!is_file($file)) return;
        foreach (preg_split('/;\s*\n/', (string)file_get_contents($file)) as $stmt) {
            $stmt = trim((string)$stmt);
            if ($stmt !== '') $pdo->exec($stmt);
        }
    }

    private static function copyInto(string $from, string $to): void {
        if (is_file($from)) {
            $dir = dirname($to);
            if (!is_dir($dir)) mkdir($dir, 0777, true);
            copy($from, $to);
            return;
        }
        if (!is_dir($to)) mkdir($to, 0777, true);
        foreach (scandir($from) as $e) {
            if ($e === '.' || $e === '..') continue;
            self::copyInto($from . '/' . $e, $to . '/' . $e);
        }
    }

    private static function rrmdir(string $dir): void {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $e) {
            if ($e === '.' || $e === '..') continue;
            $p = $dir . '/' . $e;
            is_dir($p) ? self::rrmdir($p) : unlink($p);
        }
        rmdir($dir);
    }
}
