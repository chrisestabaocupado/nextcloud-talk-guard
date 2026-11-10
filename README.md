# gsi-talk-guard

Repositorio de **GSI Talk Guard**, la app de Nextcloud de la Gerencia de Seguridad de la Información del IMCP que detecta y oculta información sensible en Nextcloud Talk.

| Para | Lea |
| --- | --- |
| Instalar, configurar y usar la app | `app/gsi_talk_guard/README.md` |
| Actualizar la app, Nextcloud o Talk | `docs/ACTUALIZACION.md` |
| Procesar correos en segundos con worker permanente | `docs/CORREO_INMEDIATO.md` y `deploy/compose.gsi-worker.yaml` |
| Desarrollar (o trabajar con Claude Code) | `CLAUDE.md` y `docs/DECISIONES.md` |
| Historial de versiones | `CHANGELOG.md` |

Inicio rápido de desarrollo:

```bash
pruebas/entorno/preparar.sh          # Nextcloud 35.0.2 + Talk 25.0.7 de prueba en http://127.0.0.1:8080
# ...cambios en app/gsi_talk_guard/...
scripts/empaquetar.sh                # dist/gsi_talk_guard-X.Y.Z.tar.gz + .sha256
GSITG_ENTORNO_PRUEBA=si php pruebas/prueba_identidad_correo.php  # identidad, LDAP simulado, cola y cuerpo de correo
```
