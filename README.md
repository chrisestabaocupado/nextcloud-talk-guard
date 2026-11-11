# GSI Talk Guard

App interna de Nextcloud para detectar información sensible en mensajes de Nextcloud Talk. Evalúa mensajes, ediciones y texto asociado a archivos compartidos; genera alertas locales y, cuando una regla lo permite y existe una clave pública, puede ocultar la coincidencia y conservar el original cifrado como evidencia.

> **Estado:** la versión del código fuente está en [`app/gsi_talk_guard/appinfo/info.xml`](app/gsi_talk_guard/appinfo/info.xml) (actualmente **1.5.2**). La app declara compatibilidad con **Nextcloud 35**; el entorno de validación documentado usa **Talk 25**. Consulte el [registro de cambios](CHANGELOG.md) y valide en preproducción antes de actualizar.

## Alcance

- Inspecciona texto de mensajes, ediciones, nombres de archivos compartidos y texto que acompaña al archivo.
- Aplica reglas configurables por frase o expresión regular; las reglas predeterminadas solo alertan.
- Permite enmascarar coincidencias de reglas regex y cifra el original con libsodium antes de modificar el mensaje.
- Guarda alertas localmente en JSONL, las muestra en una página de administración y puede notificarlas por correo usando SMTP de Nextcloud.
- No inspecciona el contenido binario de archivos, llamadas ni otros canales. En salas federadas solo alerta. La integración con Wazuh/SIEM no está activa.

El análisis está diseñado para no impedir el envío de mensajes si falla la app. Sin clave pública configurada, la app no enmascara el mensaje. Consulte [la guía operativa](app/gsi_talk_guard/README.md) para conocer límites, custodia de claves y respuesta ante fallos.

## Empezar

### Instalar en Nextcloud

1. Obtenga el paquete `gsi_talk_guard-X.Y.Z.tar.gz` y su archivo `.sha256` desde la entrega correspondiente.
2. Siga el [procedimiento de actualización e instalación](docs/ACTUALIZACION.md), incluidas las copias de respaldo, el mantenimiento y las verificaciones posteriores.
3. Complete la [configuración inicial](app/gsi_talk_guard/README.md#2-configuración-inicial): genere y resguarde las claves, revise las opciones y mantenga inicialmente las reglas en modo de alerta.
4. Pruebe la instalación con usuarios y datos ficticios antes de habilitar reglas de enmascaramiento.

La app declara compatibilidad con Nextcloud 35. No amplíe ese rango hasta validar la integración con eventos de Talk en la versión objetivo.

### Preparar un entorno de desarrollo

Los scripts de desarrollo están escritos para Linux, macOS o WSL con Bash, PHP, Docker y Node.js/npm según la prueba que vaya a ejecutar. El entorno local prepara Nextcloud 35.0.2 y Talk 25.0.7 en `http://127.0.0.1:8080`:

```bash
pruebas/entorno/preparar.sh
```

Después de modificar el código bajo `app/gsi_talk_guard/`, empaquete la app:

```bash
scripts/empaquetar.sh
```

El script genera `dist/gsi_talk_guard-X.Y.Z.tar.gz` y su huella `.sha256`. Para la prueba de integración de identidad y correo, use el entorno preparado:

```bash
GSITG_ENTORNO_PRUEBA=si php pruebas/prueba_identidad_correo.php
```

Consulte [`CLAUDE.md`](CLAUDE.md) para el flujo completo de desarrollo. Antes de cerrar un cambio de código, ejecute las comprobaciones y pruebas indicadas allí.

## Documentación

| Documento | Contenido |
| --- | --- |
| [`app/gsi_talk_guard/README.md`](app/gsi_talk_guard/README.md) | Instalación, configuración, reglas, uso del panel, evidencias y límites |
| [`docs/ACTUALIZACION.md`](docs/ACTUALIZACION.md) | Instalación/actualización, respaldos, verificación y reversión |
| [`docs/CORREO_INMEDIATO.md`](docs/CORREO_INMEDIATO.md) | Worker opcional para procesar correos con más frecuencia; cómo conservar cron |
| [`docs/ENTORNO_IMCP.md`](docs/ENTORNO_IMCP.md) | Datos operativos del entorno de preproducción documentado |
| [`docs/DECISIONES.md`](docs/DECISIONES.md) | Arquitectura, decisiones y hechos técnicos verificados |
| [`docs/PLAN_REMEDIACION.md`](docs/PLAN_REMEDIACION.md) | Plan de remediación original e histórico, creado durante la versión 1.1.0 |
| [`deploy/INSTALAR_1.5.0.md`](deploy/INSTALAR_1.5.0.md) | Guía específica de la entrega 1.5.0; use la guía general para versiones posteriores |
| [`CHANGELOG.md`](CHANGELOG.md) | Cambios por versión, migraciones y pasos adicionales |

## Diseño seguro

- La clave privada de evidencias se muestra una sola vez al generarla y no debe guardarse en el repositorio ni en Nextcloud.
- La evidencia se cifra con la clave pública. Conserve cada clave privada junto con su ID y limite su acceso según el procedimiento institucional.
- El contenido original no se incluye en alertas, correos ni logs. Para leer una evidencia se necesita acceso al servidor y la clave privada.
- El almacenamiento JSONL y la administración de alertas son locales a Nextcloud. Wazuh/SIEM es trabajo futuro.
- Active el enmascaramiento de forma gradual y con los avisos institucionales aprobados.

## Licencia y mantenimiento

El manifiesto declara licencia **AGPL-3.0**. La compatibilidad de la app se limita a la indicada en `appinfo/info.xml`; revise ese manifiesto y el changelog para cada entrega.
