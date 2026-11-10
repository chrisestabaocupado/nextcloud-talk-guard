# Instalación del IMCP (preproducción)

Datos confirmados el 10 de noviembre de 2026. Ninguno es secreto; las contraseñas y la clave privada de evidencias **no** van en este repositorio.

| Dato | Valor |
| --- | --- |
| Servidor | `nextcloud-sgi`, acceso por `https://172.16.101.223` |
| Docker Compose | `/opt/nextcloud`, proyecto `nextcloud` |
| Contenedores | `nextcloud-app-1`, `nextcloud-cron-1`, `nextcloud-notify_push-1` (`nextcloud:35-apache`); `nextcloud-db-1` (`postgres:17-alpine`); `nextcloud-redis-1` (`redis:7-alpine`) |
| Base de datos | `nextcloud`, usuario `oc_admin` |
| Volumen | `nextcloud_nextcloud_data` → `/var/www/html` |
| App | `/var/www/html/custom_apps/gsi_talk_guard` |
| Alertas (servidor) | `/var/lib/docker/volumes/nextcloud_nextcloud_data/_data/data/gsi_talk_guard/alertas.jsonl` |
| `www-data` | identificador 33 |
| HTTPS | Caddy como servicio del sistema, certificado interno; raíz en `/var/lib/caddy/.local/share/caddy/pki/authorities/local/root.crt` |
| LDAP | Active Directory; certificado `imcpbdc-ca.crt` |
| Dominios institucionales | `imcpbdc.com.ve` (y `imcp.local`, heredado del ejemplo) |
| Correo de alertas | `infosec@imcpbdc.com.ve`, desde severidad media |
| Cron | Contenedor `nextcloud-cron-1`; `backgroundjobs_mode` = `cron` |
| Wazuh | No se usa por ahora |
| Versiones | Nextcloud 35.0.2, Talk 25.0.7, GSI Talk Guard 1.1.0 |

Atajos usados en toda la documentación:

```bash
NC=nextcloud-app-1
occ() { docker exec -u www-data "$NC" php occ "$@"; }
```
