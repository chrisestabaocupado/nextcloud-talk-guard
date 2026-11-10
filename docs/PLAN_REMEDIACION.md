# Plan de remediación de GSI Talk Guard

**Base:** revisión del repositorio en su estado actual, versión 1.1.0.  
**Objetivo:** hacer que el enmascaramiento conserve evidencia recuperable, que la rotación de claves no inutilice evidencias previas y que las pruebas detecten fallos reales antes de actualizar la app.

## Prioridades

| Prioridad | Alcance | Criterio para avanzar |
| --- | --- | --- |
| P0 | No ocultar un dato si no se preservó su original de forma durable | Prueba ante errores de persistencia demuestra que el original se entrega sin modificar y se registra el fallo |
| P1 | Mantener acceso a evidencias al rotar claves; fortalecer la prueba de humo | Las claves anteriores siguen leyendo sus evidencias y la prueba falla cuando se simula una app sin protección |
| P2 | Corregir el límite de alertas y aislar/restaurar las pruebas de interfaz | Se muestran los registros más recientes y una prueba interrumpida no deja cambios de configuración |
| P3 | Añadir pruebas automatizadas y alinear documentación y empaquetado | Los controles de versión y las instrucciones de operación coinciden con el comportamiento validado |

## P0 — Preservar la evidencia antes de ocultar

### Problema

`Guardian::antesDeGuardar()` modifica el mensaje antes de que `reportar()` intente insertar el original cifrado. Si esa escritura, o una operación anterior necesaria para reportar, falla, el destinatario puede recibir el dato oculto y la evidencia puede no existir. La protección no debe depender de una escritura posterior al envío.

### Cambio propuesto

1. Separar la preparación y el cierre de una evidencia en `Evidencias`.
2. En el evento anterior al guardado, generar un identificador de evidencia, cifrar y persistir el original y sus metadatos disponibles (sala, autor, huella y fecha).
3. Aplicar la máscara solo después de confirmar que la evidencia quedó guardada. Si el cifrado o la inserción falla, conservar el texto original, registrar el error y continuar el envío, de acuerdo con la política de no bloquear Talk.
4. En el evento posterior, asociar el ID asignado por Talk y completar los metadatos que faltaban. Usar el identificador de evidencia ya creado al emitir las alertas.
5. Limpiar siempre el estado pendiente en memoria, también ante errores y cuando no se emite el evento posterior.
6. Añadir una migración nueva si hace falta cambiar el esquema. No modificar la migración publicada.

### Criterios de aceptación

- Con claves válidas y base de datos disponible, el mensaje se enmascara y su evidencia se puede descifrar e integra con SHA-256.
- Si falla la generación del cifrado o la inserción, el destinatario ve el mensaje original, Talk no recibe una excepción de la app y `nextcloud.log` registra el problema.
- Si falla el cierre posterior de metadatos, la evidencia preexistente sigue siendo descifrable y queda identificada para su investigación.
- Las rutas de edición y texto de adjuntos siguen el mismo requisito antes de sobrescribir el contenido visible.
- Ningún log, correo o registro de alerta contiene el texto original.

## P1 — Rotar claves sin perder evidencias anteriores

### Problema

Las evidencias no identifican qué clave pública las cifró. `Evidencias::leer()` construye el par para descifrar usando la clave pública actual. Después de `occ gsi-talk-guard:claves:generar --forzar`, una clave privada anterior deja de servir mediante el lector actual.

### Cambio propuesto

1. Asignar un `key_id` estable a cada par de claves.
2. Al cifrar, guardar el `key_id` junto con cada evidencia.
3. Mantener disponibles las claves públicas históricas en configuración de la app, indexadas por `key_id`; la clave privada sigue fuera del servidor.
4. En la lectura, obtener la clave pública asociada a la evidencia y verificar que la privada recibida corresponde a esa clave antes de descifrar.
5. Cambiar la salida de `occ ...:claves:generar --forzar` para explicar cómo respaldar e identificar la nueva clave privada y para advertir qué evidencias dependen de cada clave.
6. Documentar la recuperación de las evidencias actuales, que no tienen `key_id`: conservar la clave privada y pública vigentes al actualizar. No eliminar claves anteriores sin confirmar la retención de sus evidencias.

### Criterios de aceptación

- Una evidencia cifrada antes de una rotación se lee con la clave privada anterior.
- Una evidencia cifrada después de la rotación se lee con la clave nueva.
- La clave equivocada falla de forma clara y registra el intento fallido.
- Las evidencias de la versión anterior conservan una ruta de lectura documentada durante la migración.

## P1 — Hacer que la prueba de humo verifique protección

### Problema

La prueba actual acepta una cuenta sin enmascarar como `INFO`, comprueba una edición buscando `4111` (que aparece en el dato original y en el enmascarado) y deja la verificación de alertas como instrucción manual. Puede finalizar con cero fallas aunque la app no proteja mensajes.

### Cambio propuesto

1. Agregar un modo de prueba determinista que establezca configuración conocida y la restaure incluso si ocurre un error. Alternativamente, exigir un perfil explícito y fallar al detectar un modo incompatible.
2. Para pruebas de enmascaramiento, comprobar la máscara completa y verificar que no aparece la secuencia original. No tratar `INFO` como prueba aprobada.
3. Para reglas solo de alerta, confirmar que el mensaje permanece intacto y que aparece la alerta esperada en el JSONL.
4. Para ediciones, comprobar que la secuencia sensible completa dejó de ser visible y validar el registro de evento `edicion`.
5. Revisar y comprobar el código HTTP y la estructura OCS de cada petición; detener la prueba ante errores de autenticación, creación de sala o envío.
6. Mantener usuarios y datos exclusivamente de prueba, y borrar la sala temporal en todos los caminos de salida.

### Criterios de aceptación

- Una respuesta simulada de una instancia sin app o sin protección produce un código de salida distinto de cero.
- La prueba de cuenta y la de tarjeta comprueban que el original ya no aparece cuando el modo de prueba es de enmascaramiento.
- La prueba de palabras clave confirma tanto el texto original como la alerta correspondiente.
- Errores HTTP u OCS producen un fallo comprensible; la sala temporal se intenta borrar.

## P2 — Devolver las 50.000 alertas más recientes

### Problema

`LectorAlertas::recientes()` lee desde el inicio de cada archivo y deja de leer al llegar al tope de memoria. Luego ordena esos registros. En archivos grandes, conserva los primeros registros encontrados y puede omitir los más recientes.

### Cambio propuesto

1. Mantener una colección acotada de los registros más recientes mientras se recorre el archivo, o leer los archivos en orden inverso cuando el formato de rotación lo permita.
2. Preservar el orden global entre el archivo actual y las copias rotadas/comprimidas.
3. Mantener un indicador `truncado` fiel y que la interfaz comunique que el resumen y el total corresponden al conjunto limitado, si ese sigue siendo el diseño.

### Criterios de aceptación

- Con más de 50.000 alertas en un archivo, la colección devuelta contiene las 50.000 más recientes.
- El mismo resultado se obtiene al distribuir las alertas entre archivo actual, rotaciones y rotaciones `.gz`.
- El orden de salida es descendente por fecha; la memoria permanece limitada.

## P2 — Garantizar la restauración de la prueba web

### Problema

`prueba_ui.js` cambia reglas, modo y usuarios antes de entrar al bloque `try/finally`. Un fallo al iniciar sesión, cargar Nextcloud o preparar los datos puede dejar el entorno de prueba modificado.

### Cambio propuesto

1. Mover todas las mutaciones, incluida la creación de alertas de prueba, dentro de un `try/finally` que empiece antes del primer cambio.
2. Registrar qué cambios se aplicaron para restaurar solo los valores que la prueba alteró.
3. Restaurar reglas, modo y usuarios en pasos independientes para que el fallo de una restauración no impida intentar las demás.
4. Borrar el archivo temporal de reglas solo si existe y retirar las alertas de prueba añadidas o marcarlas de forma que no contaminen resultados posteriores.

### Criterios de aceptación

- Si falla el inicio de sesión después de modificar configuración, se restauran reglas, modo y usuarios.
- Si una restauración falla, se intentan las demás y el proceso informa el error de limpieza.
- Una ejecución completa deja el estado de la instancia igual al inicial, salvo artefactos explícitamente documentados.

## P3 — Pruebas unitarias, documentación y empaquetado

1. Añadir pruebas unitarias para `MotorReglas`: validación de JSON, umbrales, normalización, exclusión de dominios institucionales, Luhn y cada tipo de máscara. No sustituir las pruebas de integración con Talk.
2. Mantener la prueba de integración contra Nextcloud 35.0.2 y Talk 25.0.7, pero distinguir claramente resultados reproducidos de afirmaciones documentales.
3. Alinear la guía de Wazuh con `docs/ENTORNO_IMCP.md`, que indica que Wazuh no se usa por ahora. Presentar la integración como opcional y mantener claro qué configuración externa falta en este repositorio.
4. Alinear los dominios de ejemplo con el entorno del IMCP: retirar `imcp.gob.ve` si no es un dominio institucional vigente y añadir `imcpbdc.com.ve` donde corresponda. Confirmar con el operador antes de cambiar el comportamiento predeterminado.
5. Verificar permisos ejecutables de `pruebas/entorno/preparar.sh`, `scripts/empaquetar.sh` y `app/gsi_talk_guard/pruebas/prueba_humo.sh`, ya que las instrucciones muestran su ejecución directa. Conservar el modo de archivo en Git y en el paquete.
6. Actualizar `CHANGELOG.md`, `docs/ACTUALIZACION.md` y la versión de la app para cada cambio publicado. Crear migraciones incrementales si se modifica el esquema; nunca editar una migración publicada.

## Orden de implementación y publicación

1. Implementar P0 y preparar las pruebas de fallo de persistencia.
2. Implementar P1 de rotación y documentar la custodia de claves actuales e históricas.
3. Fortalecer la prueba de humo y su limpieza de configuración.
4. Corregir la selección de alertas recientes y el aislamiento de la prueba web.
5. Completar pruebas unitarias y actualizar documentación y paquete.
6. Validar en el entorno local indicado por `CLAUDE.md`: lint PHP, prueba de humo, prueba UI y revisión de `nextcloud.log`.
7. Confirmar versión y migraciones en el changelog, generar el paquete y verificar su SHA-256.
8. Aplicar primero en preproducción. Revisar en Nextcloud el estado, las alertas, el descifrado de evidencias anteriores y nuevas, el correo y el modo de emergencia antes de programar la actualización de producción.

## Fuera del alcance de este plan

- Ampliar compatibilidad más allá de Nextcloud 35 y Talk 25 sin un entorno validado.
- Inspeccionar contenido interno de los archivos compartidos.
- Integrar o desplegar Wazuh en el servidor del IMCP, que actualmente no lo usa.
- Activar el enmascaramiento de reglas en producción sin decisión explícita de la GSI y la revisión jurídica indicada por la documentación existente.
