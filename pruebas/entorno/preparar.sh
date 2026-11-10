#!/usr/bin/env bash
# =====================================================================
# Entorno de prueba local: Nextcloud 35.0.2 + Talk 25.0.7 sobre SQLite,
# servido con el servidor web integrado de PHP. Mismas versiones que la
# preproducción del IMCP. NO es para producción.
#
# Uso:
#   pruebas/entorno/preparar.sh            # instala y arranca
#   pruebas/entorno/preparar.sh arrancar   # solo arranca el servidor
#   pruebas/entorno/preparar.sh detener
#
# Variables opcionales:
#   DESTINO=/tmp/nc-prueba   PUERTO=8080   CACHE=~/.cache/gsi-talk-guard
#
# Requisitos: php 8.3 (cli, sqlite3, xml, mbstring, zip, gd, curl, intl,
# bcmath, gmp), curl, python3. En Ubuntu 24.04:
#   sudo apt install php8.3-cli php8.3-sqlite3 php8.3-xml php8.3-mbstring \
#     php8.3-zip php8.3-gd php8.3-curl php8.3-intl php8.3-bcmath php8.3-gmp
# =====================================================================
set -euo pipefail

NC_VERSION=35.0.2
TALK_VERSION=25.0.7
DESTINO=${DESTINO:-/tmp/nc-prueba}
PUERTO=${PUERTO:-8080}
CACHE=${CACHE:-$HOME/.cache/gsi-talk-guard}
REPO=$(cd "$(dirname "$0")/../.." && pwd)
NC_DIR=$DESTINO/nextcloud
DATOS=$DESTINO/datos
ADMIN_CLAVE='Admin.Prueba.2026'
USUARIO_CLAVE='Clave.Prueba.2026'

occ() { php "$NC_DIR/occ" "$@"; }

arrancar() {
	if curl -s -m 3 -o /dev/null "http://127.0.0.1:$PUERTO/status.php"; then
		echo "El servidor ya responde en http://127.0.0.1:$PUERTO"
		return
	fi
	# Varios procesos: el servidor integrado de un solo proceso se bloquea
	# cuando la interfaz web lanza consultas en paralelo.
	# Todas las salidas redirigidas: si no, quien lea la salida de este script
	# (por ejemplo "| tee") quedaría esperando al servidor.
	(cd "$NC_DIR" && PHP_CLI_SERVER_WORKERS=4 setsid nohup php -S "127.0.0.1:$PUERTO" -t "$NC_DIR" \
		> "$DESTINO/servidor.log" 2>&1 < /dev/null &) > /dev/null 2>&1
	for _ in $(seq 1 20); do
		curl -s -m 2 -o /dev/null "http://127.0.0.1:$PUERTO/status.php" && break
		sleep 0.5
	done
	echo "Servidor en http://127.0.0.1:$PUERTO (registro: $DESTINO/servidor.log)"
}

detener() {
	for p in $(pgrep -f "php -S 127.0.0.1:$PUERTO" || true); do
		[ "$p" != "$$" ] && kill "$p" 2>/dev/null || true
	done
	echo "Servidor detenido."
}

case "${1:-instalar}" in
	arrancar) arrancar; exit 0 ;;
	detener) detener; exit 0 ;;
	instalar) ;;
	*) echo "Uso: $0 [instalar|arrancar|detener]"; exit 1 ;;
esac

if [ -e "$NC_DIR" ]; then
	echo "Ya existe $NC_DIR. Bórrelo para reinstalar, o use: $0 arrancar"
	exit 1
fi

mkdir -p "$CACHE" "$DESTINO"
NC_TAR=$CACHE/nextcloud-$NC_VERSION.tar.bz2
TALK_TAR=$CACHE/spreed-v$TALK_VERSION.tar.gz
# Paquetes oficiales compilados (incluyen dependencias de Composer).
[ -f "$NC_TAR" ] || curl -fL -o "$NC_TAR" \
	"https://github.com/nextcloud-releases/server/releases/download/v$NC_VERSION/nextcloud-$NC_VERSION.tar.bz2"
[ -f "$TALK_TAR" ] || curl -fL -o "$TALK_TAR" \
	"https://github.com/nextcloud-releases/spreed/releases/download/v$TALK_VERSION/spreed-v$TALK_VERSION.tar.gz"

echo "Extrayendo Nextcloud $NC_VERSION y Talk $TALK_VERSION..."
tar -xjf "$NC_TAR" -C "$DESTINO"
tar -xzf "$TALK_TAR" -C "$NC_DIR/apps/"
# La app se enlaza desde el repositorio: los cambios se ven sin copiar.
ln -s "$REPO/app/gsi_talk_guard" "$NC_DIR/apps/gsi_talk_guard"

mkdir -p "$DATOS"
occ maintenance:install --database sqlite --admin-user admin --admin-pass "$ADMIN_CLAVE" --data-dir "$DATOS" > /dev/null
occ config:system:set trusted_domains 1 --value=127.0.0.1 > /dev/null
occ config:system:set overwrite.cli.url --value="http://127.0.0.1:$PUERTO" > /dev/null
occ app:enable spreed > /dev/null
occ app:enable gsi_talk_guard > /dev/null

for u in ana.perez luis.rojas carla.diaz analista.gsi; do
	OC_PASS="$USUARIO_CLAVE" occ user:add --password-from-env --display-name="$u" "$u" > /dev/null
done
occ group:add gsi > /dev/null
occ group:adduser gsi analista.gsi
occ admin-delegation:add 'OCA\GsiTalkGuard\Settings\Admin' gsi > /dev/null

# Claves de evidencias: la privada se guarda SOLO en el entorno de prueba.
occ gsi-talk-guard:claves:generar | grep -E '^  [A-Za-z0-9+/=]{40,}$' | tr -d ' ' > "$DESTINO/clave_privada.txt"
chmod 600 "$DESTINO/clave_privada.txt"

arrancar
cat <<FIN

Entorno listo.
  URL:              http://127.0.0.1:$PUERTO
  Administrador:    admin / $ADMIN_CLAVE
  Usuarios:         ana.perez, luis.rojas, carla.diaz / $USUARIO_CLAVE
  Analista GSI:     analista.gsi / $USUARIO_CLAVE (grupo gsi, con la página delegada)
  occ:              php $NC_DIR/occ
  Alertas:          $DATOS/gsi_talk_guard/alertas.jsonl
  Clave privada:    $DESTINO/clave_privada.txt

Pruebas:
  NC_URL=http://127.0.0.1:$PUERTO USUARIO_A=ana.perez CLAVE_A=$USUARIO_CLAVE \\
  USUARIO_B=luis.rojas CLAVE_B=$USUARIO_CLAVE bash app/gsi_talk_guard/pruebas/prueba_humo.sh
  NC_URL=http://127.0.0.1:$PUERTO NC_DIR=$NC_DIR node pruebas/ui/prueba_ui.js
FIN
