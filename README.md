# App Domicilios API

API PHP para una plataforma de domicilios. Atiende autenticación, pedidos, repartidores, estados de entrega, mensajes, calificaciones, notificaciones push y recargas de saldo.

## Módulos principales

- Registro e inicio de sesión de usuarios.
- Creación, consulta y actualización de pedidos.
- Toma y revisión de pedidos por repartidores.
- Área de servicio y reglas de entrega.
- Mensajería asociada a cada pedido.
- Tokens de notificación Firebase.
- Recargas y pagos ePayco configurables.
- Endpoint de salud en `app/api/health.php`.

## Tecnologías

- PHP con PDO y endpoints JSON.
- MySQL/MariaDB.
- Firebase Cloud Messaging y ePayco opcional.
- Docker y Apache.

## Estructura

- `app/api/`: endpoints públicos y configuración.
- `app/api/config/`: conexión, pagos, notificaciones y reglas de negocio.
- `Dockerfile` y `docker-compose.yml`: ejecución del servicio.

Las cargas, logs, tokens, usuarios y datos de producción están excluidos.
