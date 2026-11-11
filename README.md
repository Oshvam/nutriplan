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
| `GITHUB_REPO` | `Oshvam/nutriplan` para cotejar versión y auto-actualizar |

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

### Autoinstalador web estilo WordPress (`installer.php` + ZIP)

Para un hosting sin SSH ni FTP cómodo: genera el paquete y súbelo junto al
instalador, que te guía en el navegador.

```powershell
.\deploy-ftp.ps1 -Zip   # crea nutriplan-vX.Y.Z.zip (sin installer.php dentro)
```

1. Sube `nutriplan-vX.Y.Z.zip` e `installer.php` a tu hosting (misma carpeta).
2. Abre `https://tu-dominio/installer.php` en el navegador.
3. Paso 1: comprueba requisitos (PHP 8.1+, `zip`, `pdo_mysql`, `openssl`,
   `curl`, `mbstring`). Paso 2: elige el ZIP (subido, existente o descargado
   de GitHub) y la carpeta destino.
4. Paso 3: mete los datos MySQL (crea la base si el usuario tiene permiso),
   importa el esquema y escribe el `.env`.
5. Paso 4: apunta el DocumentRoot a `public/`, añade tu dominio a los
   orígenes de Google y **borra el instalador** con el botón (o `?bye=1`).

> **Si tu panel no deja cambiar el DocumentRoot** (p. ej. DirectAdmin sirve
> `public_html/` y ves un **403** al entrar): sube el fichero
> `public_html.htaccess` del repo a la raíz de tu hosting renombrado como
> `.htaccess`. Reenvía todo a `public/` sin mostrarlo en las URLs.
> Si aun así ves un **500**, revisa en este orden: `vendor/` subido
> (o `composer install` en el servidor), `.env` creado con datos válidos,
> base de datos importada (`database/schema.sql`) y el log de errores PHP
> del panel.

### Auto-actualización desde GitHub

En `/perfil` → Ajustes hay un bloque de versión: coteja tu `version.txt` con
el del repo y actualiza con un clic (descarga el código de la rama, hace
backup en `var/backups`, preserva `.env` y `var/`, aplica el esquema y
limpia la caché). Requiere sesión iniciada. El ZIP de instalación se
genera siempre en local (`.\deploy-ftp.ps1 -Zip`); GitHub solo se usa
para comparar versiones y traer código nuevo.

## Estructura

| Ruta | Qué es |
| --- | --- |
| `public/index.php` | Front controller + mini-router (API JSON + páginas) |
| `public/app.js` | Interacciones (sin recarga: toggle/swap/regenerar) |
| `public/assets/app.css` | Tailwind **compilado** (no se compila en el navegador) |
| `public/assets/lucide-1.72.0.min.js` | Iconos lucide pinneados y servidos en local |
| `src/Db.php` | PDO MySQL: users, sessions, kv, settings |
| `src/Auth.php` | Login Google (tokeninfo) + cookie de sesión |
| `src/Nutrition.php` | Cálculos clínicos (Mifflin/St Jeor, Schofield, PAL, SEEDO) |
| `src/Mistral.php` | Cliente Mistral con fallback algorítmico |
| `src/Push.php` | Web Push VAPID (EC P-256 + aes-128-gcm, solo openssl) |
| `src/Updater.php` | Auto-update desde el código de GitHub |
| `templates/` | Vistas Twig (plan, hoy, recetas, perfil, config, compra, receta, IA) |
| `database/schema.sql` | Esquema MySQL (idempotente) |
| `data/recipes.json` | 1126 recetas completas (ingredientes y pasos) |
| `data/recipes-index.json` | Catálogo ligero para plan/recetario (lo genera el script de abajo) |

## CSS y assets

El CSS ya viene compilado en `public/assets/app.css`; el servidor **no** necesita
Node. Solo hace falta Node si cambias clases de Tailwind en las plantillas:

```bash
npm install          # una vez (tailwindcss + @tailwindcss/cli)
npm run build:css    # regenera public/assets/app.css (minificado)
```

Si cambias `data/recipes.json` (recetas nuevas), regenera el índice ligero:

```bash
php tools/build-recipes-index.php
```

Los estáticos van con `Cache-Control: immutable` (1 año) y un `?v=<version>`
tomado de `version.txt`: al subir versión, los navegadores piden el CSS/JS nuevo.

## Service worker (offline)

`public/sw.js` (caché `nutriplan-v4`) usa una estrategia por tipo de recurso:

| Recurso | Estrategia |
| --- | --- |
| `/assets/*`, `/icons/*` | Caché primero (nombres versionados) |
| HTML y navegación | Stale-while-revalidate + pantalla «Sin conexión» |
| `/api/*` | Siempre red (nunca se cachean datos de usuario) |

Al cerrar sesión se borran las cachés para no dejar HTML autenticado en el equipo.

## Tests

Comprobaciones del núcleo sin base de datos (cálculo nutricional, reparto
calórico, lista de la compra, peso, búsqueda/filtros del catálogo y plan semanal):

```bash
php tests/run.php    # devuelve 0 si todo pasa
```

## Datos por cuenta

Igual que el original: tabla `kv` con `nutriplan_profile`,
`nutriplan_weight_history`, `nutriplan_weekly_plan`, `nutriplan_custom_recipes`
(JSON). Sesiones de 30 días en tabla `sessions`.

## Licencia

MIT — ver `LICENSE`. Úsalo, modifícalo y compártelo libremente.
