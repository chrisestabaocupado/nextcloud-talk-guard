# GSI Talk Guard

App de Nextcloud de la Gerencia de Seguridad de la Información (GSI) del IMCP. Analiza cada mensaje de Nextcloud Talk antes de guardarlo, genera alertas y, si una regla lo indica, oculta datos críticos (cuentas, tarjetas, credenciales), guarda el original cifrado y avisa al autor. Tiene una página de administración web delegable a la GSI.

- Versión actual: ver `<version>` en `app/gsi_talk_guard/appinfo/info.xml` y `CHANGELOG.md`.
- Compatibilidad: **solo Nextcloud 35 con Talk 25**. Producción del IMCP: Nextcloud 35.0.2, Talk 25.0.7, en Docker (ver `docs/ENTORNO_IMCP.md`).
- Idioma: código con nombres en español; interfaz, mensajes y documentación en español neutro, tratando al lector de «usted».

## Estructura

| Ruta | Contenido |
| --- | --- |
| `app/gsi_talk_guard/` | La app tal como se instala en `custom_apps/` |
| `app/gsi_talk_guard/config/reglas.json` | Reglas predeterminadas (todas en `alertar`) |
| `app/gsi_talk_guard/pruebas/prueba_humo.sh` | Prueba de humo por API de Talk; se entrega dentro de la app |
| `pruebas/entorno/preparar.sh` | Nextcloud 35.0.2 + Talk 25.0.7 de prueba (SQLite, `php -S`) |
| `pruebas/ui/prueba_ui.js` | Prueba de la página web con jsdom contra el Nextcloud de prueba |
| `scripts/empaquetar.sh` | Genera `dist/gsi_talk_guard-X.Y.Z.tar.gz` y su `.sha256` |
| `docs/ACTUALIZACION.md` | Procedimiento de actualización que sigue el operador |
| `docs/DECISIONES.md` | Arquitectura, decisiones y hechos verificados de Talk/Nextcloud |
| `docs/ENTORNO_IMCP.md` | Datos de la instalación del IMCP |

## Comandos

```bash
# Entorno de prueba (una vez; la app se enlaza desde el repositorio)
pruebas/entorno/preparar.sh                 # instala en /tmp/nc-prueba y arranca en :8080
pruebas/entorno/preparar.sh arrancar        # tras reiniciar la máquina
OCC="php /tmp/nc-prueba/nextcloud/occ"

# Tras cambiar código PHP
for f in $(find app/gsi_talk_guard -name '*.php'); do php -l "$f"; done
$OCC gsi-talk-guard:probar "la cuenta es 0102-0000-00-0000000019"

# Tras subir <version>: aplicar la actualización en el entorno de prueba
$OCC upgrade

# Pruebas completas (las dos deben terminar con 0 fallas)
NC_URL=http://127.0.0.1:8080 USUARIO_A=ana.perez CLAVE_A=Clave.Prueba.2026 \
USUARIO_B=luis.rojas CLAVE_B=Clave.Prueba.2026 bash app/gsi_talk_guard/pruebas/prueba_humo.sh
(cd pruebas/ui && npm install && NC_URL=http://127.0.0.1:8080 NC_DIR=/tmp/nc-prueba/nextcloud node prueba_ui.js)

# Revisar errores de la app
grep "GSI Talk Guard" /tmp/nc-prueba/datos/nextcloud.log | tail

# Publicar
scripts/empaquetar.sh
```

La prueba de humo da `INFO` en la prueba 2 si la regla `cuenta_bancaria` está en `alertar`; para probar el ocultamiento, cámbiela a `enmascarar` desde la web o con `reglas:exportar` / `reglas:importar`.

## Reglas de trabajo

- **Probar siempre contra el Nextcloud real del entorno de prueba,** no solo con `php -l`. Antes de dar por terminado un cambio: prueba de humo y prueba de la interfaz con 0 fallas, y sin errores nuevos en `nextcloud.log`.
- **Ningún oyente de eventos puede lanzar excepciones:** `try/catch (\Throwable)` y registrar. Un fallo de la app nunca debe impedir enviar un mensaje.
- **Las alertas, correos y registros nunca incluyen el texto del mensaje.** Solo metadatos y fragmentos enmascarados.
- **En `js/admin.js`, nunca `innerHTML`** con datos del servidor: usar `el()` / `textContent`.
- **Todo cambio de configuración desde la web debe pasar por `AdminController::registrar()`.**
- **Nunca modificar una migración publicada;** crear una nueva en `lib/Migration/`.
- **Para publicar una versión:** subir `<version>`, agregar la entrada en `CHANGELOG.md` (con «Migraciones: sí/no» y «Pasos extra»), ejecutar todas las pruebas y luego `scripts/empaquetar.sh`. Si el cambio altera los pasos del operador, actualizar `docs/ACTUALIZACION.md`.
- **No ampliar `max-version`** en `info.xml` sin haber probado contra esa versión de Nextcloud y su Talk.
- **Nunca** guardar en el repositorio claves privadas, contraseñas reales ni mensajes reales del IMCP. Los ejemplos usan datos ficticios (cuenta `0102-0000-00-0000000019`, tarjeta `4111 1111 1111 1111`).

## Contexto de diseño

@docs/DECISIONES.md
