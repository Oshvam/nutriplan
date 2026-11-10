# NutriPlan PHP

Planificador nutricional personalizado: cálculo calórico clínico (SEEDO),
plan semanal de comidas, recetario con IA (**Mistral**), asistente «¿Qué comer
hoy?», seguimiento gráfico de peso y notificaciones push propias (Web Push +
VAPID, sin terceros).

Port en **PHP + Twig + Tailwind** del proyecto original Node/React, pensado
para **hosting compartido (DirectAdmin/cPanel)**: despliegue por **FTP**, sin
Node ni compilación en el servidor. Uso público bajo licencia MIT: cualquiera
puede usarlo, modificarlo y redistribuirlo (ver `LICENSE`).

Repo: https://github.com/Oshvam/nutriplan

## Requisitos

- PHP 8.1+ con `pdo_mysql`, `curl`, `json`, `mbstring` (+ `openssl` para push)
- MySQL/MariaDB (se crea desde el panel del hosting)
- Composer **solo en local**, para generar `vendor/`

## Instalación

### Opción A — Local (Laragon/XAMPP)

```bash
cp .env.example .env   # rellena DB_* y GOOGLE_CLIENT_ID
composer install
php -S localhost:8000 -t public public/index.php
```

Abre http://localhost:8000. `var/cache/twig` debe poder escribirla PHP.

### Opción B — Hosting compartido (DirectAdmin/cPanel)

1. Crea la base de datos MySQL en el panel (nombre, usuario, clave).
2. En «Configurar aplicación Node.js» no hay nada que hacer: crea un dominio o
   subdominio con **DocumentRoot a `public/`** y versión PHP **8.1+**.
3. Importa `database/schema.sql` en phpMyAdmin (si no lo haces, la app lo
   auto-aplica al primer arranque).
4. Sube los ficheros por FTP (ver Despliegue) y crea el `.env` en el servidor
   copiando `.env.example` con tus datos reales.

### Opción C — VPS

Como la opción B, pero además configura el virtualhost (Apache/Nginx → `public/`)
y un cron o supervisor si quieres. No hay demonios ni colas: basta PHP + MySQL.

## Configuración (`.env`)

| Clave | Qué es |
| --- | --- |
| `APP_URL` | URL pública (enlaces y callbacks) |
| `GOOGLE_CLIENT_ID` | Cliente OAuth «aplicación web»; añade tu dominio a sus orígenes JS autorizados |
| `MISTRAL_API_KEY` | **Global del servidor**: los usuarios no meten ninguna clave |
| `DB_HOST/DB_NAME/DB_USER/DB_PASS` | Conexión MySQL |
| `VAPID_*` | Push propias; vacías se autogeneran en la tabla `settings` |
| `GITHUB_REPO` | `Oshvam/nutriplan` para auto-actualizar desde releases |

## Despliegue

### FTP (`deploy-ftp.ps1`, Windows)

```powershell
$env:NUTRIPLAN_FTP_PASSWORD = "tu-clave"
.\deploy-ftp.ps1                # actualización
.\deploy-ftp.ps1 -FirstInstall  # primera vez (+ checklist del panel)
```

Sube `public/`, `src/`, `templates/`, `data/`, `database/`, `composer.json/lock`
y `config.php`. Con `-Vendor` también sube `vendor/`. **Nunca** sube `.env`,
`var/` ni herramientas locales. Edita la sección `CONFIG` (host, usuario, ruta).

### Auto-actualización desde GitHub

En `/perfil` → Ajustes hay un bloque de versión: comprueba la última release de
`GITHUB_REPO` y actualiza con un clic (descarga el zip, hace backup en
`var/backups`, preserva `.env` y `var/`, aplica el esquema y limpia la caché).
Requiere sesión iniciada.

## Estructura

| Ruta | Qué es |
| --- | --- |
| `public/index.php` | Front controller + mini-router (API JSON + páginas) |
| `public/app.js` | Interacciones (sin recarga: toggle/swap/regenerar) |
| `src/Db.php` | PDO MySQL: users, sessions, kv, settings |
| `src/Auth.php` | Login Google (tokeninfo) + cookie de sesión |
| `src/Nutrition.php` | Cálculos clínicos (Mifflin/St Jeor, Schofield, PAL, SEEDO) |
| `src/Mistral.php` | Cliente Mistral con fallback algorítmico |
| `src/Push.php` | Web Push VAPID (EC P-256 + aes-128-gcm, solo openssl) |
| `src/Updater.php` | Auto-update desde releases de GitHub |
| `templates/` | Vistas Twig (plan, hoy, recetas, perfil, config, compra, receta, IA) |
| `database/schema.sql` | Esquema MySQL (idempotente) |
| `data/recipes.json` | 1126 recetas del catálogo |

## Datos por cuenta

Igual que el original: tabla `kv` con `nutriplan_profile`,
`nutriplan_weight_history`, `nutriplan_weekly_plan`, `nutriplan_custom_recipes`
(JSON). Sesiones de 30 días en tabla `sessions`.

## Licencia

MIT — ver `LICENSE`. Úsalo, modifícalo y compártelo libremente.
