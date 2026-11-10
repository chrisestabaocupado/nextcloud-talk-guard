#!/usr/bin/env bash
# =====================================================================
# Prueba de humo de GSI Talk Guard contra un Nextcloud real.
# Usa la API de Talk con dos usuarios DE PRUEBA, crea una sala temporal,
# envía mensajes con datos ficticios, comprueba lo que ve el destinatario
# y borra la sala al terminar.
#
# Uso:
#   NC_URL=https://nube.imcp.local \
#   USUARIO_A=prueba.gsi1 CLAVE_A='...' \
#   USUARIO_B=prueba.gsi2 CLAVE_B='...' \
#   ./prueba_humo.sh
#
# Requiere curl y python3. Ejecútelo después de instalar la app y
# después de cada actualización de Nextcloud o Talk.
# =====================================================================
set -u
: "${NC_URL:?Defina NC_URL}" "${USUARIO_A:?}" "${CLAVE_A:?}" "${USUARIO_B:?}" "${CLAVE_B:?}"

ocs() { # usuario clave método ruta [json]
	curl -s -u "$1:$2" -X "$3" "$NC_URL/ocs/v2.php$4" -H "OCS-APIRequest: true" \
		-H "Accept: application/json" -H "Content-Type: application/json" ${5:+-d "$5"}
}
json() { python3 -c "import json,sys; d=json.load(sys.stdin); print($1)"; }
enviar() { # texto -> id
	ocs "$USUARIO_A" "$CLAVE_A" POST "/apps/spreed/api/v1/chat/$SALA" \
		"{\"message\": $(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$1")}" \
		| json "d['ocs']['data']['id']"
}
leer_como_b() { # id -> texto que ve B
	ocs "$USUARIO_B" "$CLAVE_B" GET "/apps/spreed/api/v1/chat/$SALA?lookIntoFuture=0&limit=20" \
		| python3 -c "import json,sys; print(next((m['message'] for m in json.load(sys.stdin)['ocs']['data'] if m['id']==$1), '(no encontrado)'))"
}

ok=0; fallas=0
verificar() { # descripción condición
	if eval "$2"; then echo "  OK     $1"; ok=$((ok+1)); else echo "  FALLA  $1"; fallas=$((fallas+1)); fi
}

echo "Creando sala temporal..."
SALA=$(ocs "$USUARIO_A" "$CLAVE_A" POST /apps/spreed/api/v4/room \
	"{\"roomType\":2,\"roomName\":\"GSI prueba de humo $(date +%Y%m%d-%H%M%S)\"}" | json "d['ocs']['data']['token']")
[ -n "$SALA" ] || { echo "No se pudo crear la sala. Revise URL y credenciales."; exit 2; }
ocs "$USUARIO_A" "$CLAVE_A" POST "/apps/spreed/api/v4/room/$SALA/participants" \
	"{\"newParticipant\":\"$USUARIO_B\",\"source\":\"users\"}" >/dev/null
trap 'ocs "$USUARIO_A" "$CLAVE_A" DELETE "/apps/spreed/api/v4/room/$SALA" >/dev/null; echo "Sala temporal borrada."' EXIT

echo
echo "1. Mensaje normal (no debe cambiar)"
ID=$(enviar "buenos días, ¿revisamos el informe?")
VISTO=$(leer_como_b "$ID")
verificar "llega intacto" '[ "$VISTO" = "buenos días, ¿revisamos el informe?" ]'

echo "2. Cuenta bancaria ficticia"
ID=$(enviar "la cuenta es 0102-0000-00-0000000019")
VISTO=$(leer_como_b "$ID"); echo "         B ve: $VISTO"
if [[ "$VISTO" == *"0102****0019"* ]]; then
	echo "  OK     enmascarada (regla cuenta_bancaria en modo enmascarar)"; ok=$((ok+1))
elif [[ "$VISTO" == *"0102-0000-00-0000000019"* ]]; then
	echo "  INFO   llega sin enmascarar: la regla está en modo alertar o el modo es solo_alertar"
else
	echo "  FALLA  resultado inesperado"; fallas=$((fallas+1))
fi

echo "3. Edición que agrega una tarjeta de prueba"
ID=$(enviar "te paso los datos en un momento")
ocs "$USUARIO_A" "$CLAVE_A" PUT "/apps/spreed/api/v1/chat/$SALA/$ID" \
	'{"message":"tarjeta 4111 1111 1111 1111"}' >/dev/null
VISTO=$(leer_como_b "$ID"); echo "         B ve: $VISTO"
verificar "la edición se procesó" '[[ "$VISTO" == *"4111"* ]]'

echo "4. Palabras clave (solo alerta, el texto no cambia)"
ID=$(enviar "pásamelo por whatsapp")
VISTO=$(leer_como_b "$ID")
verificar "llega intacto" '[ "$VISTO" = "pásamelo por whatsapp" ]'

echo
echo "Resultado: $ok correctas, $fallas fallas."
echo "Revise además en el servidor que el archivo de alertas tenga líneas nuevas"
echo "de las reglas cuenta_bancaria, tarjeta y exfiltracion:"
echo "  tail -n 5 <datadirectory>/gsi_talk_guard/alertas.jsonl"
[ "$fallas" -eq 0 ]
