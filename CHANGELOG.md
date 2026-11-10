# Registro de cambios de GSI Talk Guard

Formato de cada entrada (ver `docs/ACTUALIZACION.md`, sección 8):

```
## X.Y.Z — AAAA-MM-DD
Compatibilidad: Nextcloud NN · Talk NN
Migraciones: sí | no
Pasos extra: ninguno | descripción
- Cambio
```

## 1.3.1 — 2026-11-10
Compatibilidad: Nextcloud 35 · Talk 25
Migraciones: sí (corrige la migración pendiente de `clave_id` para conservar las evidencias anteriores con `NULL`)
Pasos extra: repetir `occ upgrade` después de instalar esta versión correctiva.
- La migración agrega `clave_id` como columna nullable, compatible con las evidencias históricas sin identificador.
- Si un intento anterior dejó la columna creada, la migración la modifica para permitir `NULL`.

## 1.3.0 — 2026-11-10
Compatibilidad: Nextcloud 35 · Talk 25
Migraciones: no
Pasos extra: ninguno. La integración con Wazuh/SIEM se planifica como etapa futura.
- Se pueden crear, editar, activar, desactivar y eliminar reglas desde la página de administración.
- Las reglas admiten frases (coincidencia cualquiera/todas) o expresiones regulares, severidad, umbral y acción.
- Se puede limitar cada regla a mensajes, ediciones o archivos compartidos.
- La pestaña de prueba permite seleccionar evento y validar alcance sin generar alertas.
- Las alertas se mantienen en Nextcloud y se consultan desde el panel; no se requiere Wazuh.

## 1.2.0 — 2026-11-10
Compatibilidad: Nextcloud 35 · Talk 25
Migraciones: sí (agrega `clave_id` a evidencias)
Pasos extra: conservar cada clave privada con su ID; las evidencias anteriores a 1.2.0 requieren la clave privada que estaba activa al crearse.
- El original cifrado se persiste antes de enmascarar; si falla la persistencia, el mensaje permanece intacto.
- Cada evidencia nueva identifica la clave usada y puede leerse tras rotar claves.
- El panel selecciona los 50.000 registros más recientes del período.
- La prueba de humo valida explícitamente el resultado del enmascaramiento y la prueba UI restaura estado y alertas.
- La guía de operación aclara que Wazuh no está en uso actualmente en el entorno del IMCP.

## 1.1.0 — 2026-11-10
Compatibilidad: Nextcloud 35 · Talk 25
Migraciones: no
Pasos extra: ninguno. Opcional: delegar la página a un grupo de la GSI (`occ admin-delegation:add 'OCA\GsiTalkGuard\Settings\Admin' GRUPO`).
- Página **Configuración de administración › GSI Talk Guard** con pestañas Alertas, Configuración y Probar reglas.
- Alertas: filtros por período (hasta 31 días, incluidos archivos rotados `.N` y `.N.gz`), severidad, regla, acción y usuario; resumen, detalle y actualización automática.
- Configuración desde la web: modo con botón de emergencia, usuarios del piloto, correo, aviso al autor, dominios y acción de cada regla.
- Cada cambio hecho desde la web genera una alerta `cambio_configuracion` con usuario, detalle e IP.
- La página es delegable a un grupo sin hacerlo administrador de Nextcloud.
- Generar claves y leer evidencias siguen solo en `occ`, por diseño.

## 1.0.0 — 2026-11-10
Compatibilidad: Nextcloud 35 · Talk 25
Migraciones: sí (crea `oc_gsitg_evidencias` y `oc_gsitg_accesos`)
Pasos extra: generar claves con `occ gsi-talk-guard:claves:generar`.
- Análisis de mensajes, ediciones y textos de archivos compartidos en Talk antes de guardarlos.
- 10 reglas configurables (regex y palabras clave), todas en modo `alertar` por omisión.
- Ocultamiento opcional por regla, con evidencia cifrada (sealed box de libsodium) y aviso al autor.
- Alertas locales en `alertas.jsonl`, consultables desde Nextcloud y notificables por correo.
- Comandos `occ gsi-talk-guard:*`: estado, modo, probar, reglas, claves y evidencias.
