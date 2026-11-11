# NutriPlan móvil (Android nativo)

Cliente 100 % nativo en **Flutter** del backend PHP de NutriPlan
(`public/index.php`). Sin SDK instalado en el PC: el APK se compila
en la nube con GitHub Actions.

## Obtener el APK

1. Sube el repo a GitHub y haz push a `main`.
2. Pestaña **Actions → APK Android → nutriplan-apk** → descarga el ZIP.
3. Pásalo al móvil e instálalo (permite «orígenes desconocidos»).

## Conectar con tu servidor

Al abrir la app escribe la **URL del servidor** PHP:

- Emulador Android: `http://10.0.2.2:8000`
- Móvil físico en tu Wi-Fi: `http://TU_IP:8000` (p. ej. `http://192.168.1.50:8000`)

El servidor debe estar accesible (Laragon con «compartir en red» o
`php -S 0.0.0.0:8000 -t public public/index.php`).

## Requisito único en Google Cloud Console

El login usa tu cliente web (`306351646081-…apps.googleusercontent.com`).
Crea **un** cliente OAuth tipo **Android** (una sola vez):

- Package: `com.oshvam.nutriplan`
- Huella SHA-1: `3C:0F:27:D8:4F:22:72:10:68:E7:12:BD:B9:6D:92:D4:A9:D6:FC:17`

Sin este paso, Google rechaza el inicio de sesión en el móvil.

## Firma estable (recomendado antes de instalar)

El keystore `android/app/upload-keystore.jks` **no** se sube al repo.
Para que las actualizaciones instalen sin desinstalar, añade estos
secretos en GitHub (**Settings → Secrets → Actions**):

| Secreto | Valor |
|---|---|
| `ANDROID_KEYSTORE_B64` | contenido en base64 del `.jks` (ver abajo) |
| `ANDROID_KEYSTORE_PASSWORD` | `nutriplan2026` |
| `ANDROID_KEY_PASSWORD` | `nutriplan2026` |
| `ANDROID_KEY_ALIAS` | `nutriplan` |

```powershell
[Convert]::ToBase64String([IO.File]::ReadAllBytes("app-mobil\android\app\upload-keystore.jks")) | Set-Clipboard
```

Sin secretos el APK se firma en modo debug: instala igual, pero si
luego añades el keystore tendrás que desinstalar y reinstalar.

## Comprobar sin Android SDK

```powershell
cd app-mobil
flutter analyze   # sin avisos
flutter test      # 8 tests
flutter build web # compila la lógica (la UI es la misma)
```

## Estructura

- `lib/main.dart` — arranque, sesión guardada, tema verde Material 3.
- `lib/api.dart` — cliente HTTP (cookie `nutriplan_session`, 20 métodos).
- `lib/models.dart` — modelos + cálculos portados del PHP.
- `lib/screens/` — login, shell (5 pestañas), plan, hoy, recetas,
  detalle, compra, perfil, alta/edición de datos.
- `android/app/upload-keystore.jks` — firma local (gitignored).
