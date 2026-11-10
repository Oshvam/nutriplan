# NutriPlan PHP

Reescritura en **PHP + Twig + Tailwind** para hosting compartido (DirectAdmin) con
**MySQL/MariaDB**. Despliegue por FTP, sin Node ni build en el servidor.

## Requisitos

- PHP 8.1+ con `pdo_mysql`, `curl`, `json`, `mbstring` (+ `openssl` para push)
- MySQL/MariaDB (se crea en el panel de DirectAdmin)
- Composer (solo en local, para `vendor/`)

## Local (Laragon)

```bash
cp .env.example .env   # rellena DB_* y GOOGLE_CLIENT_ID
composer install
php -S localhost:8000 -t public
```

`var/cache/twig` debe poder escribirla PHP.

## Estructura

| Ruta | Qué es |
| --- | --- |
| `public/index.php` | Front controller + mini-router (API JSON + páginas) |
| `src/Db.php` | PDO MySQL: users, sessions, kv, settings |
| `src/Auth.php` | Login Google (tokeninfo) + cookie de sesión |
| `src/Nutrition.php` | Cálculos clínicos (Mifflin/St Jeor, Schofield, PAL, SEEDO) |
| `templates/` | Vistas Twig |
| `database/schema.sql` | Esquema MySQL (también se auto-aplica si falta) |

## Datos por cuenta

Igual que el original: tabla `kv` con `nutriplan_profile`,
`nutriplan_weight_history`, `nutriplan_weekly_plan`, `nutriplan_custom_recipes`
(JSON). Sesiones de 30 días en tabla `sessions`.
