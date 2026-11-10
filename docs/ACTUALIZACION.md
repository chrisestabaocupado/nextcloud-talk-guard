# Procedimiento de actualización de GSI Talk Guard

Versión del 10 de noviembre de 2026. Procedimiento manual para actualizar la app GSI Talk Guard, y para actualizar Nextcloud o Talk sin perder la protección, en la instalación Docker del IMCP. Cada paso termina con una verificación: no avance si no se cumple.

## 1. Alcance y reglas generales

Hay cuatro tipos de cambio, con riesgo y procedimiento distintos:

| Cambio | Ejemplo | Riesgo | Sección |
| --- | --- | --- | --- |
| Reglas o configuración | Activar «ocultar» en una regla, cambiar dominios | Bajo | Se hace desde la web; no requiere este procedimiento |
| Nueva versión de la app | 1.1.0 a 1.2.0 | Medio | 4 |
| Actualización menor de Nextcloud o Talk | 35.0.2 a 35.0.3; Talk 25.0.7 a 25.0.8 | Medio | 7 |
| Actualización mayor de Nextcloud | 35 a 36 | Alto: la app se desactiva sola | 7 |

**Reglas que aplican siempre:**

1. **Primero preproducción, después producción.** Nunca aplique en producción una versión que no pasó la prueba de humo en preproducción.
2. **Respaldo antes de tocar nada:** base de datos, `config.php` y la carpeta de la app.
3. **Modo de mantenimiento mientras se reemplaza la app,** para que nadie envíe mensajes con la app a medio copiar.
4. **Si algo falla, primero se protege el servicio:** desactivar la app devuelve Talk a la normalidad de inmediato (sección 6). Después se investiga.
5. **Cada actualización se anota** en el registro de la sección 9.

## 2. Datos de la instalación

Ver `docs/ENTORNO_IMCP.md`. Al abrir cada sesión SSH, defina:

```bash
NC=nextcloud-app-1
occ() { docker exec -u www-data "$NC" php occ "$@"; }
```

## 3. Antes de cualquier actualización

**3.1 Lista de comprobación**

- [ ] Leyó el `CHANGELOG.md` de la versión nueva: qué cambia, si tiene **migraciones de base de datos** y si exige algún paso extra.
- [ ] Confirmó la compatibilidad: en `appinfo/info.xml` de la versión nueva, `min-version` y `max-version` deben incluir su versión de Nextcloud.
- [ ] La versión nueva pasó la prueba de humo en preproducción (si está actualizando producción).
- [ ] Tiene a mano la clave privada de evidencias, para la verificación final.
- [ ] Eligió un momento de poco uso y avisó que Talk estará en mantenimiento unos minutos.

**3.2 Anote la versión actual.** La necesitará si hay que revertir:

```bash
occ app:list | grep gsi_talk_guard
occ config:app:get gsi_talk_guard installed_version
```

**3.3 Verifique la integridad del paquete nuevo.** Quien entrega la versión debe acompañarla con su huella SHA-256. Compare:

```bash
sha256sum /tmp/gsi_talk_guard-X.Y.Z.tar.gz
```

Si no coincide con la huella publicada, no instale el paquete.

**3.4 Respalde base de datos, configuración y la carpeta actual de la app:**

```bash
FECHA=$(date +%F-%H%M)
VERSION_ACTUAL=$(occ config:app:get gsi_talk_guard installed_version)
sudo mkdir -p /opt/respaldos && cd /opt/respaldos
sudo sh -c "docker exec nextcloud-db-1 pg_dump -U oc_admin -d nextcloud -Fc > nextcloud_$FECHA.dump"
sudo docker cp nextcloud-app-1:/var/www/html/config/config.php ./config.php.$FECHA
sudo docker cp nextcloud-app-1:/var/www/html/custom_apps/gsi_talk_guard ./gsi_talk_guard-$VERSION_ACTUAL-$FECHA
sudo chmod -R go-rwx /opt/respaldos
ls -lh /opt/respaldos
```

Si el `CHANGELOG.md` indica **«Migraciones: sí»**, active primero el modo de mantenimiento (`occ maintenance:mode --on`) y haga el respaldo después. Así, si hubiera que restaurar la base de datos, no se pierde nada de lo ocurrido entre el respaldo y la actualización.

**Verificación:** existen el `.dump` (varios MB), la copia de `config.php` y la carpeta `gsi_talk_guard-VERSION-FECHA`. No continúe sin los tres.

## 4. Actualizar la app GSI Talk Guard

En los comandos, reemplace `X.Y.Z` por la versión nueva. La configuración, las reglas, la clave pública y las evidencias están en la base de datos y se conservan.

Para la versión 1.2.0, `occ upgrade` agrega el ID de clave a las evidencias nuevas. Las filas anteriores quedan sin ID y siguen leyéndose con la clave privada que las creó. Conserve esa clave privada y pruébela antes de rotar o reemplazar el par.

**4.1 Copie el paquete al servidor.** Desde su computadora:

```bash
scp gsi_talk_guard-X.Y.Z.tar.gz cglood@172.16.101.223:/tmp/
```

**4.2 Descomprímalo y confirme la versión que trae:**

```bash
cd /tmp && rm -rf gsi_talk_guard && tar -xzf gsi_talk_guard-X.Y.Z.tar.gz
grep -o '<version>[^<]*' gsi_talk_guard/appinfo/info.xml
grep -o '<nextcloud [^>]*>' gsi_talk_guard/appinfo/info.xml
```

La versión debe ser `X.Y.Z` y mayor que la actual; Nextcloud solo ejecuta la actualización si el número sube. La segunda línea debe incluir su versión de Nextcloud.

**4.3 Reemplace la app en modo de mantenimiento:**

Si instaló el worker permanente de correo, deténgalo primero desde `/opt/nextcloud`
con los tres archivos Compose (ver `CORREO_INMEDIATO.md`). Debe recargar el código
nuevo al volver a arrancar; manténgalo detenido durante el mantenimiento:

```bash
docker compose -f compose.yaml -f compose.override.yaml -f compose.gsi-worker.yaml stop gsi-worker
```

```bash
occ maintenance:mode --on
docker exec "$NC" rm -rf /var/www/html/custom_apps/gsi_talk_guard
docker cp gsi_talk_guard "$NC":/var/www/html/custom_apps/
docker exec "$NC" chown -R www-data:www-data /var/www/html/custom_apps/gsi_talk_guard
occ upgrade
```

La salida debe terminar con `Updated <gsi_talk_guard> to X.Y.Z` y `Update successful`.

Si `occ upgrade` muestra un error, **no desactive el mantenimiento**: copie el error y vaya a la sección 6.

**4.4 Salga del mantenimiento y confirme:**

```bash
occ maintenance:mode --off
occ app:list | grep gsi_talk_guard
```

**Verificación:** aparece `gsi_talk_guard: X.Y.Z`. Continúe con la sección 5 antes de dar la actualización por terminada.

Si usa el worker permanente, inícielo de nuevo desde `/opt/nextcloud` después de
salir del mantenimiento:

```bash
docker compose -f compose.yaml -f compose.override.yaml -f compose.gsi-worker.yaml up -d --no-deps gsi-worker
```

## 5. Verificación posterior

Use esta sección después de **cualquier** actualización: de la app, de Nextcloud o de Talk.

**5.1 Estado y registro de errores:**

```bash
occ gsi-talk-guard:estado | head -8
docker exec "$NC" grep "GSI Talk Guard" /var/www/html/data/nextcloud.log | tail -n 3
```

El modo, la clave pública, el correo y las reglas deben seguir como antes. El segundo comando no debe mostrar errores nuevos.

**5.2 Interfaz web.** Abra **Configuración de administración › GSI Talk Guard**, recargando con `Ctrl+F5`, y revise que cargan las tres pestañas.

**5.3 Prueba de humo con usuarios temporales:**

```bash
CLAVE_PRUEBA=$(openssl rand -base64 18)
for u in prueba.gsi1 prueba.gsi2; do
  docker exec -e OC_PASS="$CLAVE_PRUEBA" -u www-data "$NC" php occ user:add --password-from-env "$u"
done
sudo cp /var/lib/caddy/.local/share/caddy/pki/authorities/local/root.crt /tmp/ca-nextcloud.crt
sudo chmod 644 /tmp/ca-nextcloud.crt
export CURL_CA_BUNDLE=/tmp/ca-nextcloud.crt
NC_URL=https://172.16.101.223 USUARIO_A=prueba.gsi1 CLAVE_A="$CLAVE_PRUEBA" \
USUARIO_B=prueba.gsi2 CLAVE_B="$CLAVE_PRUEBA" bash /tmp/gsi_talk_guard/pruebas/prueba_humo.sh
occ user:delete prueba.gsi1; occ user:delete prueba.gsi2; unset CLAVE_PRUEBA
```

El resultado debe terminar con `0 fallas`. Si hay usuarios del piloto definidos, agregue temporalmente `prueba.gsi1`, o la prueba 2 mostrará `INFO` en lugar de `OK`.

**5.4 Correo.** Con el worker permanente activo, el envío de las alertas debe iniciar en segundos; con solo cron puede esperar aproximadamente cinco minutos. Compruebe la recepción en `infosec@imcpbdc.com.ve` y que el correo muestre nombre, cuenta y correo del autor, nombre legible de sala y participantes. La entrega final depende del servidor SMTP. Revise `docs/CORREO_INMEDIATO.md` para instalar o verificar el worker, conservando el cron general.

**Verificación:** estado sin cambios, sin errores nuevos en el registro, la web carga, la prueba de humo pasa y llega el correo. Solo entonces anote la actualización en la sección 9.

## 6. Primera respuesta y reversión

**6.1 Primera respuesta: proteger el servicio.** Si después de una actualización Talk falla, los mensajes no llegan o la web muestra errores:

| Síntoma | Acción inmediata |
| --- | --- |
| Talk falla o no se pueden enviar mensajes | `occ app:disable gsi_talk_guard`: Talk vuelve a funcionar sin la app |
| Talk funciona, pero se ocultan datos que no deberían ocultarse | `occ gsi-talk-guard:modo solo_alertar`: deja de ocultar y sigue alertando |
| La web muestra la pantalla «actualización requerida» | Revise la sección 6.2: casi siempre es una versión de la app inconsistente con la base de datos |

Después, con el servicio estable, decida si se corrige hacia adelante (una versión corregida) o se revierte.

**6.2 Revertir a la versión anterior, sin migraciones.** Aplica cuando el `CHANGELOG.md` de la versión nueva dice «Migraciones: no».

No basta con poner la carpeta anterior. Nextcloud recuerda la versión instalada y, si encuentra un código más viejo, marca la instancia como «actualización requerida» y bloquea la web. `occ upgrade` no lo resuelve. Hay que restaurar la carpeta **y** declarar la versión anterior (procedimiento probado en preproducción):

```bash
occ maintenance:mode --on
docker exec "$NC" rm -rf /var/www/html/custom_apps/gsi_talk_guard
sudo docker cp /opt/respaldos/gsi_talk_guard-VERSION_ANTERIOR-FECHA "$NC":/var/www/html/custom_apps/gsi_talk_guard
docker exec "$NC" chown -R www-data:www-data /var/www/html/custom_apps/gsi_talk_guard
occ config:app:set gsi_talk_guard installed_version --value=VERSION_ANTERIOR
occ status | grep needsDbUpgrade
occ maintenance:mode --off
occ app:enable gsi_talk_guard
occ app:list | grep gsi_talk_guard
```

`needsDbUpgrade` debe decir `false` antes de salir del mantenimiento. Después, repita la sección 5.

**6.3 Revertir una versión con migraciones.** Si la versión nueva cambió tablas de la base de datos, el código anterior puede no entenderlas. Hay que restaurar también el respaldo de la base de datos:

```bash
occ maintenance:mode --on
sudo sh -c "docker exec -i nextcloud-db-1 pg_restore -U oc_admin -d nextcloud --clean --if-exists < /opt/respaldos/nextcloud_FECHA.dump"
```

Y después restaure la carpeta como en el punto 6.2, sin el paso de `installed_version`: la base restaurada ya contiene la versión anterior.

**Advertencia:** restaurar la base de datos devuelve **todo Nextcloud** al momento del respaldo. Se pierden los mensajes, comparticiones y cambios hechos desde entonces. Por eso el mantenimiento debe empezar antes del respaldo del punto 3.4 y durar hasta la verificación. Ensaye esta restauración en preproducción antes de necesitarla en producción.

## 7. Actualizar Nextcloud o Talk

La app usa eventos internos de Talk, que no forman parte de la API pública y pueden cambiar entre versiones. Por eso **toda** actualización de Nextcloud o Talk se prueba primero en preproducción con la sección 5.

**7.1 Actualización menor de Nextcloud (35.0.x a 35.0.y).** La imagen `nextcloud:35-apache` del `docker-compose.yml` garantiza que `pull` trae correcciones de la 35, sin saltar a la 36.

```bash
# 1. Respaldos de la sección 3.4
cd /opt/nextcloud
docker compose pull
docker compose up -d
docker logs -f nextcloud-app-1      # espere el fin de la actualización; salga con Ctrl+C
occ status
occ app:list | grep -E "gsi_talk_guard|spreed"
```

`gsi_talk_guard` debe seguir en la lista de apps habilitadas. Después, la sección 5 completa.

**7.2 Actualización de Talk.** Dentro de Nextcloud 35, Talk se mantiene en la serie 25.x.

```bash
occ app:update --showonly
occ app:update spreed
occ app:list | grep spreed
```

Después, la sección 5 completa. Si la prueba de humo falla tras actualizar Talk, aplique la primera respuesta (6.1) y contacte al responsable del desarrollo de la app.

**7.3 Actualización mayor de Nextcloud (35 a 36).** GSI Talk Guard declara compatibilidad solo con Nextcloud 35. Al actualizar a la 36, **Nextcloud la desactiva automáticamente**: Talk sigue funcionando, pero sin análisis, alertas ni ocultamiento, y sin avisar en Talk.

Orden obligatorio:

1. En preproducción: cambie la imagen a `nextcloud:36-apache`, actualice y verifique que Nextcloud funciona.
2. Prepare una versión de la app que declare compatibilidad con la 36 (sección 8) y pruébela contra la versión de Talk que trae la 36.
3. Solo cuando la prueba de humo pase en preproducción, repita los pasos 1 y 2 en producción, en la misma ventana de mantenimiento.

Nextcloud solo admite subir una versión mayor a la vez (35 a 36, luego 36 a 37). Nunca cambie la etiqueta de la imagen en producción antes de tener lista la versión compatible de la app.

Para detectar si la app quedó desactivada tras cualquier actualización:

```bash
occ app:list --disabled | grep gsi_talk_guard && echo "ATENCIÓN: la app está desactivada"
```

## 8. Preparar una versión nueva de la app

Esta sección es para quien desarrolla la app (ver también `CLAUDE.md`).

**8.1 Número de versión** (formato `MAYOR.MENOR.CORRECCIÓN`):

| Cambio | Ejemplo | Sube |
| --- | --- | --- |
| Corrección sin cambio de comportamiento | Arreglo de un error en la web | Corrección: 1.1.0 a 1.1.1 |
| Función nueva compatible | Nueva regla, nueva pestaña | Menor: 1.1.1 a 1.2.0 |
| Cambio que exige pasos manuales o rompe compatibilidad | Nueva versión mayor de Nextcloud, cambio de formato de reglas | Mayor: 1.2.0 a 2.0.0 |

**8.2 Pasos para publicar una versión:**

1. Cambie `<version>` en `app/gsi_talk_guard/appinfo/info.xml`. Si no sube, Nextcloud no ejecuta la actualización.
2. Si cambia la base de datos, agregue una clase nueva en `lib/Migration/` con el formato `VersionXYYYDate{AAAAMMDDHHMMSS}.php`. **Nunca modifique una migración ya publicada.**
3. Si la versión es para una Nextcloud mayor nueva, ajuste `min-version` y `max-version` en `appinfo/info.xml` solo después de probarla contra esa versión y su Talk.
4. Agregue la entrada en `CHANGELOG.md` con el formato indicado al inicio de ese archivo.
5. Ejecute todas las pruebas del repositorio (ver `CLAUDE.md`): sintaxis PHP, prueba de humo y prueba de la interfaz web.
6. Empaquete con `scripts/empaquetar.sh`. Genera `dist/gsi_talk_guard-X.Y.Z.tar.gz` y su `.sha256`, y se niega a empaquetar si falta la entrada del `CHANGELOG.md` o hay errores de sintaxis.
7. Entregue el paquete, el archivo `.sha256` y la entrada del `CHANGELOG.md` a quien hace la actualización.

## 9. Registro de actualizaciones

Agregue una fila por cada instalación, actualización o reversión, en preproducción y en producción.

| Fecha | Entorno | Cambio | Versión | Responsable | Resultado | Observaciones |
| --- | --- | --- | --- | --- | --- | --- |
| 2026-11-10 | Preproducción | Instalación de la app | 1.0.0 | cglood | Exitosa | Prueba de humo 3/0, lectura de evidencia verificada, correo recibido |
| 2026-11-10 | Preproducción | Actualización de la app (interfaz web) | 1.0.0 a 1.1.0 | cglood | Exitosa | Sin migraciones; configuración conservada |
