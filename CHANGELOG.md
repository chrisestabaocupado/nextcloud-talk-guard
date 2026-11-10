# Registro de cambios de GSI Talk Guard

Formato de cada entrada (ver `docs/ACTUALIZACION.md`, sección 8):

```
## X.Y.Z — AAAA-MM-DD
Compatibilidad: Nextcloud NN · Talk NN
Migraciones: sí | no
Pasos extra: ninguno | descripción
- Cambio
```

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
- Alertas en `alertas.jsonl` para Wazuh u otro SIEM, y por correo.
- Comandos `occ gsi-talk-guard:*`: estado, modo, probar, reglas, claves y evidencias.
