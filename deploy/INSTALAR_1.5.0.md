# Aplicar GSI Talk Guard 1.5.0 y el worker permanente

Preparado para la instalación Docker del IMCP en `/opt/nextcloud`.
El cambio de la app no requiere migraciones de base de datos.

## 1. Copiar la entrega

Copie `gsi_talk_guard-1.5.0-entrega.tar.gz` a `/tmp/` del servidor. Por ejemplo,
desde PowerShell, situado en la carpeta del repositorio:

```powershell
scp .\dist\gsi_talk_guard-1.5.0-entrega.tar.gz cglood@172.16.101.223:/tmp/
```

En su sesión SSH:

```bash
mkdir -p /tmp/gsitg-entrega-1.5.0
tar -xzf /tmp/gsi_talk_guard-1.5.0-entrega.tar.gz -C /tmp/gsitg-entrega-1.5.0
cd /tmp/gsitg-entrega-1.5.0
sha256sum -c gsi_talk_guard-1.5.0.tar.gz.sha256
```

## 2. Actualizar la app

Siga `ACTUALIZACION.md`, secciones 3, 4 y 5, usando el paquete
`/tmp/gsitg-entrega-1.5.0/gsi_talk_guard-1.5.0.tar.gz`. Incluye respaldo,
mantenimiento, reemplazo de la app y `occ upgrade`. Detenga con Ctrl+C cualquier
worker temporal antes de reemplazar archivos. Si ya existe el worker permanente,
deténgalo como indica la guía antes de entrar en mantenimiento.

Después de la actualización, compruebe:

```bash
docker exec -u www-data nextcloud-app-1 php occ config:app:get gsi_talk_guard installed_version
```

Debe mostrar `1.5.0`. Recargue el panel con Ctrl+F5. Las alertas nuevas incluyen la
ficha capturada del autor y las identidades de los participantes. Los registros
antiguos se enriquecen al consultarlos con la información actual del directorio.

## 3. Activar el worker permanente

Copie el fragmento de Compose. Si ya existe ese archivo en `/opt/nextcloud`,
conserve una copia antes de reemplazarlo:

```bash
cp /tmp/gsitg-entrega-1.5.0/compose.gsi-worker.yaml /opt/nextcloud/compose.gsi-worker.yaml
cd /opt/nextcloud
docker compose -f compose.yaml -f compose.override.yaml -f compose.gsi-worker.yaml config --quiet
docker compose -f compose.yaml -f compose.override.yaml -f compose.gsi-worker.yaml up -d --no-deps gsi-worker
docker compose -f compose.yaml -f compose.override.yaml -f compose.gsi-worker.yaml ps gsi-worker
docker compose -f compose.yaml -f compose.override.yaml -f compose.gsi-worker.yaml logs --tail=30 gsi-worker
```

**No elimine el cron general.** El nuevo servicio atiende únicamente los correos
de GSI Talk Guard, consulta la cola cada segundo y se reinicia automáticamente.
El cron sigue atendiendo las demás tareas de Nextcloud.

## 4. Comprobar el resultado

Envíe desde Talk un mensaje de prueba que active una regla, usando una cuenta del
piloto. Compruebe la alerta en el panel y la recepción del correo sin usar el botón
de prueba SMTP. El correo debe mostrar el nombre real, cuenta, correo y demás
datos disponibles, junto con el nombre y enlace de la sala.

Si algún dato no está en Nextcloud/LDAP, la app conserva la información disponible
y el ID; no inventa nombres de cuenta a partir de la dirección de correo.
El enlace a una sala respeta los permisos normales de Talk.

Consulte `CORREO_INMEDIATO.md` para operación, reinicios y actualizaciones.

## Validación de la entrega

Probada localmente con Nextcloud 35.0.2 y Talk 25.0.7: 4 comprobaciones de Talk,
24 de interfaz y 19 de identidad/correo, todas sin fallas. El caso de Active
Directory usa un proveedor LDAP simulado con la API real de Nextcloud. Los
atributos del directorio institucional deben verificarse después de instalarla.
