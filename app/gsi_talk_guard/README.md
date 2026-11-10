# GSI Talk Guard

App de Nextcloud de la Gerencia de Seguridad de la Información (IMCP). Analiza mensajes, ediciones y archivos compartidos de Talk **antes de guardarlos**, genera alertas locales y permite definir reglas de monitoreo. Según la regla, puede ocultar coincidencias, guardar el original cifrado como evidencia y avisar al autor. La integración con Wazuh/SIEM se abordará en una etapa posterior.

Versión 1.3.0. Compatible con Nextcloud 35 y Talk 25; validar en preproducción antes de desplegar.

```
Usuario envía ──▶ Talk ──▶ [antes de guardar] GSI Talk Guard
                              ├─ evalúa reglas
                              ├─ oculta datos (si la regla dice "enmascarar")
                              └─ guarda el original cifrado
                         ──▶ base de datos y participantes reciben el texto ya enmascarado
                         ──▶ [después de guardar] alerta local en alertas.jsonl
                                                  └─▶ consulta desde el panel de Nextcloud
                                                  aviso al autor (notificación)
                                                  correo a la GSI (opcional)
```

| Cubre | No cubre |
| --- | --- |
| Mensajes en conversaciones uno a uno, de grupo y públicas | Contenido de llamadas |
| Ediciones de mensajes | Contenido interno de los archivos (solo el nombre y el texto adjunto) |
| Texto que acompaña a un archivo compartido | Enmascarar en salas federadas con otros servidores (solo alerta) |
| Nombre de archivos compartidos en Talk | Otros canales: correo, USB, fotos de pantalla |

## Contenido

| Ruta | Para qué sirve |
| --- | --- |
| `appinfo/info.xml` | Manifiesto de la app |
| `config/reglas.json` | Reglas predeterminadas (todas en modo `alertar`) |
| `lib/` | Código de la app |
| `lib/Controller`, `lib/Settings`, `templates`, `js`, `css`, `img` | Página de administración (1.3.0) |
| `pruebas/prueba_humo.sh` | Prueba automática contra un Nextcloud real |

## Interfaz web

En **Configuración de administración › GSI Talk Guard** hay tres pestañas:

| Pestaña | Qué permite |
| --- | --- |
| **Alertas** | Ver las alertas con filtros por período (24 horas, 7 o 30 días), severidad, regla, acción y usuario. Resumen por severidad, detalle de cada alerta y actualización automática cada minuto. |
| **Configuración** | Cambiar el modo, usuarios del piloto, correo, aviso al autor y dominios; crear, editar, activar, desactivar y eliminar reglas de monitoreo. |
| **Probar reglas** | Probar un texto ficticio como mensaje, edición o archivo para ver reglas coincidentes y el efecto del ocultamiento. |

Cada regla se define con nombre e identificador, frases o expresión regular, severidad, eventos de Talk a revisar, umbral y acción. Las frases admiten lógica “cualquiera” o “todas”. Las expresiones regulares permiten detectar formatos de datos propios de la organización. El ocultamiento aplica solo a reglas regex y requiere la clave de evidencias.

Esto cubre la mayoría de reglas de prevención en chat: datos con formato fijo (cuentas, documentos, teléfonos, códigos), listas de términos sensibles, varias coincidencias en un mismo mensaje, validación Luhn para tarjetas y exclusiones de dominios institucionales. El umbral cuenta coincidencias dentro de un solo evento. Esta primera etapa no evalúa el contenido binario de adjuntos, identidad del emisor/sala, ventanas de tiempo entre mensajes, ni intención semántica; esos casos requieren nuevos datos del evento o un analizador especializado.

**Cada cambio hecho desde la web queda registrado** como una alerta `cambio_configuracion`, con usuario, detalle e IP, y se envía por correo si su severidad lo alcanza. Desactivar el análisis o dejar de ocultar una regla se registra con severidad alta.

Por diseño, dos cosas siguen solo en `occ`: **generar las claves** (la clave privada no debe pasar por un navegador) y **leer evidencias** (exige acceso al servidor además de la clave). En el detalle de una alerta con evidencia, la página muestra el comando listo para copiar.

Las alertas se leen del archivo `alertas.jsonl` y de sus copias rotadas (`.1`, `.2.gz`, ...), hasta 31 días. Si el período supera 50.000 registros, se muestran los 50.000 más recientes.

### Delegar la página a la GSI

Para que analistas de la GSI usen la página **sin ser administradores de Nextcloud**:

1. Cree un grupo, por ejemplo `gsi`, y agregue a los analistas (en **Cuentas**, o con `occ group:add gsi` y `occ group:adduser gsi USUARIO`).
2. En **Configuración de administración › Privilegios de administración**, asigne «GSI Talk Guard» al grupo. O por consola:
   ```bash
   occ admin-delegation:add 'OCA\GsiTalkGuard\Settings\Admin' gsi
   ```

Los miembros del grupo verán solo esta sección de la administración; el resto les devuelve «acceso denegado».

## Actualizar desde la versión 1.0.0

La configuración, las reglas, las claves y las evidencias están en la base de datos y se conservan.

```bash
NC=nextcloud-app-1
occ() { docker exec -u www-data "$NC" php occ "$@"; }
cd /tmp && rm -rf gsi_talk_guard && tar -xzf gsi_talk_guard-1.3.0.tar.gz
occ maintenance:mode --on
docker exec "$NC" rm -rf /var/www/html/custom_apps/gsi_talk_guard
docker cp gsi_talk_guard "$NC":/var/www/html/custom_apps/
docker exec "$NC" chown -R www-data:www-data /var/www/html/custom_apps/gsi_talk_guard
occ upgrade
occ maintenance:mode --off
occ app:list | grep gsi_talk_guard     # debe decir 1.3.0
```

El modo de mantenimiento evita que alguien envíe mensajes en los segundos en que la carpeta se reemplaza.

## 1. Instalación (Docker)

La imagen oficial de Nextcloud carga las apps propias desde `custom_apps`.

```bash
NC=<contenedor_nextcloud>
docker cp gsi_talk_guard $NC:/var/www/html/custom_apps/
docker exec $NC chown -R www-data:www-data /var/www/html/custom_apps/gsi_talk_guard
docker exec -u www-data $NC php occ app:enable gsi_talk_guard
docker exec -u www-data $NC php occ gsi-talk-guard:estado
```

Al activarse, la app crea dos tablas (`oc_gsitg_evidencias` y `oc_gsitg_accesos`). La app no viene firmada por la tienda de Nextcloud; para apps propias en `custom_apps` eso es normal.

En los ejemplos siguientes, `occ` significa `docker exec -u www-data <contenedor> php occ`.

## 2. Configuración inicial

### Claves de evidencias

```bash
occ gsi-talk-guard:claves:generar
```

Se guarda la clave **pública** en Nextcloud y se muestra la **privada una sola vez**. Guárdela fuera del servidor, por ejemplo en un gestor de contraseñas de la GSI con acceso dual. Sin clave pública, la app **nunca enmascara**, para no perder el original.

Cada par tiene un ID. Las alertas con evidencia incluyen ese ID y el comando de lectura lo muestra. Conserve las claves privadas anteriores con sus IDs; la app deriva la clave pública desde la clave privada suministrada para descifrar evidencias tras una rotación. Las evidencias creadas antes de la versión 1.2.0 siguen usando la clave privada que estaba activa cuando se generaron.

### Opciones

| Opción | Comando | Por omisión |
| --- | --- | --- |
| Limitar a usuarios del piloto | `occ config:app:set gsi_talk_guard usuarios_piloto --value="ana.perez,luis.rojas"` | Todos |
| Correo a la GSI | `occ config:app:set gsi_talk_guard correo_para --value="seguridad@imcp.gob.ve"` | Desactivado |
| Severidad mínima del correo | `occ config:app:set gsi_talk_guard severidad_minima_correo --value=alta` | `media` |
| Avisar al autor | `occ config:app:set gsi_talk_guard avisar_usuario --value=no` | Sí |
| Ruta del archivo de alertas | `occ config:app:set gsi_talk_guard archivo_alertas --value=/ruta/alertas.jsonl` | `<datadirectory>/gsi_talk_guard/alertas.jsonl` |

El correo usa la configuración SMTP de Nextcloud y se envía con los trabajos en segundo plano (cron). El correo nunca incluye el contenido del mensaje. No se necesita Wazuh: el almacenamiento y la consulta de alertas funcionan dentro de Nextcloud. El archivo JSONL permite diseñar un conector futuro, pero no se configura ni envía a un SIEM en esta etapa.

### Aviso a los usuarios

Antes de activar el enmascaramiento, agregue al aviso del piloto una línea como esta y valídela con Consultoría Jurídica:

> Los números de cuenta, tarjetas y contraseñas que se escriban en Talk se ocultan automáticamente. El mensaje original se guarda cifrado y solo puede consultarse dentro de un caso autorizado.

## 3. Plan de activación recomendado

**Semanas 1 y 2: solo alertar.** Instale la app y revise las alertas en el panel de Nextcloud. Ajuste reglas y anote falsos positivos antes de activar cualquier ocultamiento.

**Después: enmascarar regla por regla.** Empiece por `tarjeta` y `cuenta_bancaria`, que son las más precisas. Deje `credenciales` para después.

```bash
occ gsi-talk-guard:reglas:exportar > reglas.json
# Edite reglas.json: cambie "accion": "alertar" por "accion": "enmascarar" en las reglas elegidas
docker cp reglas.json <contenedor>:/tmp/reglas.json
occ gsi-talk-guard:reglas:importar /tmp/reglas.json
occ gsi-talk-guard:probar "la cuenta es 0102-0000-00-0000000019"
```

La importación valida todo antes de guardar. Rechaza expresiones regulares inválidas, ids repetidos y reglas de palabras que intenten enmascarar. Para volver a las reglas originales use `occ gsi-talk-guard:reglas:importar --predeterminadas`.

### Reglas predeterminadas

| Regla | Severidad | Máscara si se activa | Texto resultante |
| --- | --- | --- | --- |
| `credenciales` | alta | total | `[credencial oculta por GSI]` |
| `cuenta_bancaria` | alta | dígitos | `0102****6789` |
| `tarjeta` (validada con Luhn) | alta | dígitos | `4111****1111` |
| `cedulas_multiples` (3 o más) | alta | parcial | Solo alerta |
| `exfiltracion`, `destino_externo`, `datos_clientes`, `sistemas`, `operaciones` | media | No aplica | Solo alerta |
| `clasificacion` | baja | No aplica | Solo alerta |

Ajuste `dominios_institucionales` en el JSON con los dominios reales del IMCP.

## 4. Interruptor general

```bash
occ gsi-talk-guard:modo solo_alertar   # deja de enmascarar al instante; sigue alertando
occ gsi-talk-guard:modo desactivado    # no analiza nada
occ gsi-talk-guard:modo normal         # aplica la acción de cada regla
```

## 5. Integración SIEM futura

La versión actual no requiere Wazuh ni configura un envío remoto. El operador revisa las alertas desde la pestaña **Alertas** de Nextcloud. Las alertas también se persisten en JSONL para retención local y para facilitar un conector posterior. La integración futura deberá definir transporte, autenticación, reintentos, control de duplicados y reglas de mapeo sin afectar la entrega de mensajes de Talk.

## 6. Leer una evidencia

Solo dentro de un caso autorizado:

```bash
occ gsi-talk-guard:evidencia:leer 8 --caso GSI-INV-001-2026 --solicitante analista1
# pide la clave privada sin mostrarla en pantalla
```

El identificador es el valor del campo `evidencia` de la alerta. El comando muestra el mensaje original y verifica su huella SHA-256. Cada intento, exitoso o fallido, queda en la tabla `oc_gsitg_accesos` y genera una alerta local.

## 7. Prueba de humo

Cree dos usuarios de prueba y ejecute desde cualquier equipo con acceso a Nextcloud:

```bash
EXPECT_MASKING=yes NC_URL=https://nube.imcp.local USUARIO_A=prueba.gsi1 CLAVE_A='...' \
USUARIO_B=prueba.gsi2 CLAVE_B='...' ./pruebas/prueba_humo.sh
```

Crea una sala temporal, envía datos ficticios, comprueba lo que ve el destinatario y borra la sala. `EXPECT_MASKING=yes` requiere que las reglas estén en modo de enmascaramiento y falla si el original sigue visible. `EXPECT_MASKING=no` comprueba que el mensaje llega intacto, pero no valida la alerta JSONL. Ejecútela al instalar la app y **antes y después de cada actualización mayor de Nextcloud o Talk**.

## 8. Migrar desde el monitor de Python

1. Instale la app y verifique con la prueba de humo.
2. Si este servidor todavía ejecuta el monitor legado con Wazuh, migre ese componente cuando el conector nuevo esté planificado; esta app no requiere ni activa Wazuh.
3. Detenga el monitor: `docker compose down` en `/opt/talk-monitor`.
4. Elimine el rol de base de datos: `DROP SCHEMA gsi_monitor CASCADE; DROP ROLE gsi_monitor;`

No deje ambos funcionando a la vez, porque duplicarían las alertas.

## 9. Actualizaciones de Nextcloud o Talk

La app usa eventos internos de Talk, que no son API pública y podrían cambiar entre versiones mayores. Antes de actualizar producción:

1. Actualice primero preproducción.
2. Ejecute `prueba_humo.sh`.
3. Revise `nextcloud.log` buscando `GSI Talk Guard`.
4. Si la versión mayor cambió, ajuste `max-version` en `appinfo/info.xml` después de validar.

## Comportamiento ante fallos

Todo error dentro de la app se registra y **nunca impide enviar un mensaje**.

| Situación | Qué pasa |
| --- | --- |
| Reglas personalizadas corruptas | Usa las predeterminadas (solo alertan) y registra el error en `nextcloud.log` |
| Archivo de alertas no escribible | El mensaje se envía y se enmascara igual; el error queda en `nextcloud.log` |
| Falta la clave pública | No enmascara; la alerta indica `sin clave publica: no se enmascaró` |
| App desactivada | Talk funciona con normalidad, sin análisis |

## Limitaciones

- En una **edición**, Talk guarda el texto nuevo antes de avisar a la app; la app lo reescribe enmascarado en la misma petición. En las pruebas, ni el editor ni el destinatario vieron el dato.
- Las **salas federadas** con otros servidores solo generan alertas, sin enmascarar.
- Quien tenga acceso de superusuario a la base de datos puede leer los mensajes que **no** se enmascararon. Mantenga el control de acceso a PostgreSQL descrito en la propuesta.
- Las evidencias solo se pueden leer con la clave privada. Si se pierde, se pierden también.

## Desinstalar

```bash
occ app:disable gsi_talk_guard
occ app:remove gsi_talk_guard
```

Las tablas de evidencias se conservan hasta que las borre manualmente, según la política de retención.
