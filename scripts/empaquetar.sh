#!/usr/bin/env bash
# Empaqueta la app para entregarla: gsi_talk_guard-X.Y.Z.tar.gz + .sha256 en dist/.
# La versión se toma de app/gsi_talk_guard/appinfo/info.xml.
set -euo pipefail
REPO=$(cd "$(dirname "$0")/.." && pwd)
APP=$REPO/app/gsi_talk_guard
VERSION=$(grep -o '<version>[^<]*' "$APP/appinfo/info.xml" | sed 's/<version>//')
DIST=$REPO/dist
PAQUETE=gsi_talk_guard-$VERSION.tar.gz

grep -q "^## $VERSION " "$REPO/CHANGELOG.md" || { echo "Falta la entrada ## $VERSION en CHANGELOG.md"; exit 1; }
for f in $(find "$APP" -name '*.php'); do php -l "$f" > /dev/null || { echo "Error de sintaxis: $f"; exit 1; }; done

mkdir -p "$DIST"
TMP=$(mktemp -d)
cp -r "$APP" "$TMP/gsi_talk_guard"
find "$TMP" \( -name '.DS_Store' -o -name '*.swp' -o -name 'node_modules' \) -prune -exec rm -rf {} +
tar -czf "$DIST/$PAQUETE" -C "$TMP" gsi_talk_guard
rm -rf "$TMP"
(cd "$DIST" && sha256sum "$PAQUETE" > "$PAQUETE.sha256")
echo "Listo: dist/$PAQUETE"
cat "$DIST/$PAQUETE.sha256"
