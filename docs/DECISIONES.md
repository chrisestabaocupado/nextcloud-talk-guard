# Decisiones de diseño y hechos verificados

## Flujo de un mensaje

1. `BeforeChatMessageSentEvent` → `Guardian::antesDeGuardar()`: evalúa reglas y, si corresponde, enmascara con `IComment::setMessage()`. El mensaje aún no tiene id, así que el original y los hallazgos se guardan en `Guardian::$pendientes`, indexado por `spl_object_id($comentario)`. Talk pasa el mismo objeto a los dos eventos.
2. `ChatMessageSentEvent` → `despuesDeGuardar()`: con el id ya asignado, guarda la evidencia, emite las alertas y avisa al autor.
3. Archivos compartidos y ediciones llegan como mensajes de sistema: `BeforeSystemMessageSentEvent` (enmascara el texto adjunto) y `SystemMessageSentEvent` (alerta de archivo; reprocesa ediciones).

## Servicios (`app/gsi_talk_guard/lib/`)

| Clase | Función |
| --- | --- |
| `Service/Guardian` | Orquesta todo lo anterior |
| `Service/MotorReglas` | Carga, valida (`interpretar()`), evalúa y enmascara. Regex sobre el texto original; palabras sobre texto en minúsculas y sin acentos |
| `Service/Alertas` | Escribe `alertas.jsonl` y encola el correo (`BackgroundJob/EnviarCorreoAlerta`) |
| `Service/Evidencias` | Cifra con `sodium_crypto_box_seal` usando solo la clave pública; descifra con la privada que entrega el operador; registra cada acceso |
| `Service/Settings` | Configuración en `appconfig` |
| `Service/LectorAlertas` | Lee `alertas.jsonl`, `.N` y `.N.gz` para la web |
| `Service/EditorReglas` | Cambios puntuales al JSON de reglas desde la web, validados con `MotorReglas::interpretar()` |
| `Controller/AdminController` | API de la web, con `#[AuthorizedAdminSetting(settings: Admin::class)]` para permitir delegación |
| `Settings/Admin`, `Settings/Section` | Página de administración (`IDelegatedSettings`) |

## Decisiones y por qué

- **Ningún oyente lanza excepciones.** Todo va en `try/catch (\Throwable)` y se registra. Un fallo de la app nunca debe impedir enviar un mensaje.
- **Las alertas nunca llevan el texto del mensaje.** Solo metadatos y fragmentos enmascarados. El original solo existe cifrado como evidencia.
- **Sin clave pública no se enmascara.** Si no, el original se perdería. La alerta lleva la nota `sin clave publica: no se enmascaró`.
- **Solo las reglas `regex` con `mascara` pueden enmascarar.** Ocultar palabras clave no protege nada. `MotorReglas::interpretar()` lo rechaza.
- **Generar claves y leer evidencias solo por `occ`.** La clave privada no debe pasar por un navegador, y leer evidencias exige acceso al servidor.
- **Cada cambio desde la web genera una alerta `cambio_configuracion`.** Desactivar o dejar de ocultar se registra con severidad alta.
- **La web es JavaScript puro, sin compilación.** Todo dato del servidor se inserta con `textContent`; nunca `innerHTML`, porque nombres de sala y fragmentos los controlan los usuarios.
- **Todas las reglas vienen en `alertar`.** Activar el ocultamiento es una decisión explícita de la GSI, con aprobación de Consultoría Jurídica.
- **Salas federadas:** solo alertan, no se enmascara.

## Hechos verificados en Nextcloud 35.0.2 y Talk 25.0.7

- Eventos: `OCA\Talk\Events\{BeforeChatMessageSentEvent, ChatMessageSentEvent, BeforeSystemMessageSentEvent, SystemMessageSentEvent}`; `getRoom()` y `getComment()`. No son API pública: revisar en cada versión mayor.
- Largo máximo del mensaje: 32000 (`ChatManager::MAX_CHAT_LENGTH`).
- En salas uno a uno (tipos 1 y 5), `Room::getName()` devuelve un JSON con los dos ids, por ejemplo `["ana.perez","luis.rojas"]`.
- Tipos de sala: 1 uno a uno, 2 grupo, 3 pública, 4 registro de cambios, 5 antigua uno a uno, 6 notas personales.
- Un archivo compartido es un mensaje de sistema con verbo `object_shared` y JSON `{"message":"file_shared","parameters":{"share":ID,"metaData":{"caption":...}}}`.
- `oc_share.file_target` de una compartición de Talk empieza con `{TALK_PLACEHOLDER}/`: hay que quitarlo.
- Una edición se guarda **antes** de avisar: Talk llama `editMessage()`, guarda y después emite el mensaje de sistema `message_edited` con `parameters.message` = id editado. Para enmascarar ediciones hay que volver a guardar el comentario.
- Columnas usadas: `oc_talk_attendees(room_id, actor_type, actor_id)`, `oc_share(id, share_type, share_with, file_target)`; `share_type` 10 es sala de Talk.
- Valores de `appconfig` guardados con `occ config:app:set` quedan como `mixed`. `IAppConfig::setValueString()` puede sobrescribirlos sin conflicto.
- Nextcloud solo ejecuta la actualización de la app si sube `<version>` en `info.xml`, y hay que correr `occ upgrade`.
- **Volver a una versión anterior:** si el código es más viejo que `installed_version`, la instancia queda en «actualización requerida» y `occ upgrade` no lo resuelve. Hay que ajustar `occ config:app:set gsi_talk_guard installed_version --value=ANTERIOR`.
- Con `max-version` 35, al actualizar Nextcloud a la 36 la app se desactiva sola.

## Errores ya corregidos (no reintroducir)

- Credenciales con palabras intermedias («la clave del sistema es: X») y valores sin dígitos al inicio («usuario y clave: admin / Clave.2026»). El patrón actual admite hasta 4 palabras intermedias y busca el valor en los 20 caracteres siguientes.
- `el()` en `js/admin.js` debe aplanar con `flat(Infinity)`; con `flat()` el detalle mostraba `[object HTMLElement]`.
- Carrera de filtros: `cargarAlertas()` descarta respuestas que no sean de la última consulta (`consultaActual`).

## Peculiaridades del entorno de prueba

- El servidor integrado de PHP con un solo proceso se bloquea con consultas en paralelo: usar `PHP_CLI_SERVER_WORKERS=4` (ya lo hace `preparar.sh`).
- El inicio de sesión por formulario necesita la cabecera `Origin`. La autenticación básica de `curl` no crea sesión de navegador, así que la API web responde 401.
- El `requesttoken` puede terminar en `=`: extraerlo con una expresión no codiciosa.
- `/bin/sh` puede ser `dash`: los scripts con sintaxis de bash deben ejecutarse con `bash`.
- El aviso de índices faltantes de `occ upgrade` en SQLite es del núcleo de Nextcloud, no de la app.

## Pendiente o posible

- Validar las reglas y el dashboard de Wazuh en un servidor Wazuh real (el IMCP decidió no usar Wazuh por ahora).
- Versión compatible con Nextcloud 36 cuando el IMCP planifique esa actualización.
- Editar patrones y palabras desde la web (hoy solo por `occ gsi-talk-guard:reglas:exportar` / `importar`).
- Inspeccionar el contenido de los archivos compartidos (por ejemplo, con Apache Tika).
- Pruebas unitarias de `MotorReglas` con PHPUnit.
