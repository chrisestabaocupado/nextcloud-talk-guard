# Correos de alertas en segundos

El servicio `gsi-worker` revisa la cola de GSI Talk Guard cada segundo. La llegada
al buzón depende además del SMTP y de los filtros del servidor de correo. Bajo
carga, el envío también puede esperar a los trabajos anteriores de esta cola.

**Conserve `cron`.** Sigue atendiendo las tareas generales de Nextcloud y puede
procesar las alertas si el worker se detiene. Nextcloud reserva cada trabajo para
que cron y worker puedan compartir la cola.

## Instalación en el IMCP

El archivo está preparado para los Compose compartidos de `/opt/nextcloud`:
Nextcloud 35 Apache, volumen `nextcloud_data`, Redis y certificados LDAP locales.
No cambia los servicios existentes ni requiere contraseñas nuevas.

1. Copie `deploy/compose.gsi-worker.yaml` a `/opt/nextcloud/compose.gsi-worker.yaml`.
2. Termine con Ctrl+C el worker temporal si todavía está abierto.
3. Desde `/opt/nextcloud`, ejecute:

```bash
docker compose -f compose.yaml -f compose.override.yaml -f compose.gsi-worker.yaml config --quiet
docker compose -f compose.yaml -f compose.override.yaml -f compose.gsi-worker.yaml up -d --no-deps gsi-worker
docker compose -f compose.yaml -f compose.override.yaml -f compose.gsi-worker.yaml ps gsi-worker
docker compose -f compose.yaml -f compose.override.yaml -f compose.gsi-worker.yaml logs --tail=30 gsi-worker
```

El proceso se renueva cada hora para recargar código y liberar cachés. Docker lo
reinicia automáticamente al terminar y tras reiniciar el servidor, salvo que lo
detenga expresamente. No depende de que una sesión SSH permanezca abierta.

## Verificación

Envíe desde Talk un mensaje ficticio que active una regla de severidad media o
superior, usando una cuenta del piloto. No use la pestaña «Probar reglas»: esa
pestaña no genera alertas. El correo debe iniciar su envío en segundos, sin pulsar
el botón de prueba SMTP. Revise la bandeja de entrada y la salida de `logs`.

```bash
docker exec -u www-data nextcloud-app-1 php occ background-job:list --class='OCA\GsiTalkGuard\BackgroundJob\EnviarCorreoAlerta'
docker exec -u www-data nextcloud-app-1 php occ config:app:get core lastcron
```

La cola debe vaciarse y `lastcron` debe seguir avanzando aproximadamente cada cinco
minutos. Una cola vacía indica que se intentó el envío; confirme la recepción y
revise `nextcloud.log` si falta algún correo. La app registra los destinatarios
que el servicio SMTP devuelve como fallidos; no implementa reintentos automáticos.

## Actualizaciones y operación

Use los tres archivos al gestionar el conjunto con Compose. Antes de reemplazar
la app, actualizar Nextcloud/Talk o habilitar mantenimiento, detenga el worker:

```bash
docker compose -f compose.yaml -f compose.override.yaml -f compose.gsi-worker.yaml stop gsi-worker
```

Después de actualizar y salir del mantenimiento, vuelva a iniciarlo para que use
el código nuevo:

```bash
docker compose -f compose.yaml -f compose.override.yaml -f compose.gsi-worker.yaml up -d --no-deps gsi-worker
```

Para retirar el worker, detenga y elimine solo su servicio con `stop gsi-worker`
y `rm -f gsi-worker` usando los mismos tres archivos. El cron general seguirá
procesando correos con su intervalo habitual. No ejecute `down` para esta operación.

Referencia: [trabajador continuo de Nextcloud 35](https://docs.nextcloud.com/server/stable/admin_manual/occ_system.html#background-job-worker).
