# API PHP Local Para XAMPP

API local para desarrollo de `appdomicilios`.

## Endpoints

- `GET /appdomicilios_api/api/health.php`
- `POST /appdomicilios_api/api/login.php`
- `POST /appdomicilios_api/api/register.php`

## Base de datos

- Motor: MariaDB/MySQL de XAMPP
- Base: `appdomicilios`
- Config por defecto local: `root` sin contrasena

## Notas

- Esta API esta pensada para pruebas locales.
- En el emulador Android, la app debe apuntar a `http://10.0.2.2/appdomicilios_api/api/`.
- En telefono fisico debes cambiar la URL base por la IP local del computador.

