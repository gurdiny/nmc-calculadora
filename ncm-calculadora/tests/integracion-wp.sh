#!/usr/bin/env bash
#
# Pruebas de integración contra un WordPress real.
#
# Levanta mariadb + wordpress en Docker con el plugin montado, corre las
# comprobaciones que no se pueden hacer sin WP (activación, panel, shortcode,
# AJAX, permisos) y desmonta todo al terminar.
#
#   bash tests/integracion-wp.sh
#
set -uo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PUERTO="${NCM_PUERTO:-8899}"
BASE="http://localhost:${PUERTO}"
COOKIES="$(mktemp)"
FALLOS=0
TOTAL=0

if ! command -v docker >/dev/null 2>&1; then
	echo "Docker es necesario para estas pruebas." >&2
	exit 1
fi

limpiar() {
	docker rm -f ncm-wp ncm-db >/dev/null 2>&1
	docker network rm ncm-net >/dev/null 2>&1
	rm -f "${COOKIES}"
}
trap limpiar EXIT

comprobar() { # titulo esperado obtenido
	TOTAL=$((TOTAL + 1))

	if [ "$2" = "$3" ]; then
		echo "  OK    $1"
	else
		FALLOS=$((FALLOS + 1))
		echo "  FALLA $1"
		echo "         esperado: $2"
		echo "         obtenido: $3"
	fi
}

wpcli() { docker exec -u www-data ncm-wp wp "$@" 2>/dev/null; }

echo "Levantando WordPress…"
docker network create ncm-net >/dev/null 2>&1
docker rm -f ncm-db ncm-wp >/dev/null 2>&1
docker run -d --name ncm-db --network ncm-net \
	-e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp \
	-e MARIADB_USER=wp -e MARIADB_PASSWORD=wp mariadb:11 >/dev/null
docker run -d --name ncm-wp --network ncm-net \
	-e WORDPRESS_DB_HOST=ncm-db -e WORDPRESS_DB_USER=wp \
	-e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
	-v "${RAIZ}:/var/www/html/wp-content/plugins/ncm-calculadora" \
	-p "${PUERTO}:80" wordpress:6-apache >/dev/null

for _ in $(seq 1 40); do
	[ "$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/" || true)" != "000" ] && break
	sleep 3
done

docker exec ncm-wp bash -c \
	'curl -sO https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x wp-cli.phar && mv wp-cli.phar /usr/local/bin/wp' >/dev/null 2>&1

for _ in $(seq 1 30); do
	wpcli db check >/dev/null && break
	sleep 3
done

wpcli core install --url="${BASE}" --title=NCM --admin_user=admin \
	--admin_password=admin --admin_email=admin@example.test --skip-email >/dev/null

echo
echo "== Activación =="
comprobar "la opción no existe antes de activar" "no" \
	"$(wpcli option get ncm_calc_config >/dev/null && echo sí || echo no)"

wpcli plugin activate ncm-calculadora >/dev/null

comprobar "el plugin queda activo" "active" "$(wpcli plugin get ncm-calculadora --field=status)"
comprobar "siembra 15 diseños" "15" "$(wpcli eval 'echo count( NCM_Data::get_disenos() );')"
comprobar "margen sembrado" "0.35" "$(wpcli eval 'echo NCM_Data::get_parametro( "margen_comercial" );')"

echo
echo "== Casos de aceptación del Excel =="
CASO_A='$r = NCM_Calculator::desde_config()->calcular( "Pulsera", "Bangle (Rígida)", "Natural", "Diamante", "Redonda", "Oro blanco" ); echo $r["precio_final_formateado"];'
CASO_B='$r = NCM_Calculator::desde_config()->calcular( "Anillo", "Solitario", "Natural", "Diamante", "Redonda", "Oro blanco" ); echo $r["precio_final_formateado"];'

comprobar "caso A" 'DESDE $14.480.000 COP' "$(wpcli eval "${CASO_A}")"
comprobar "caso B" 'DESDE $19.290.000 COP' "$(wpcli eval "${CASO_B}")"

echo
echo "== Shortcode =="
PAGINA=$(wpcli post create --post_type=page --post_title=Cotizador \
	--post_content='[ncm_calculadora]' --post_status=publish --porcelain)
INTERNA=$(wpcli post create --post_type=page --post_title=Interna \
	--post_content='[ncm_calculadora_interna]' --post_status=publish --porcelain)
OTRA=$(wpcli post create --post_type=page --post_title=Otra \
	--post_content='Página sin calculadora.' --post_status=publish --porcelain)

curl -s -c "${COOKIES}" -b "${COOKIES}" -o /dev/null "${BASE}/wp-login.php"
curl -s -c "${COOKIES}" -b "${COOKIES}" -o /dev/null -L \
	-d "log=admin&pwd=admin&wp-submit=Entrar&redirect_to=${BASE}/wp-admin/&testcookie=1" \
	"${BASE}/wp-login.php"

PAG=$(curl -s -b "${COOKIES}" "${BASE}/?page_id=${PAGINA}")
ANON=$(curl -s "${BASE}/?page_id=${PAGINA}")
SIN=$(curl -s -b "${COOKIES}" "${BASE}/?page_id=${OTRA}")

INT_CON=$(curl -s -b "${COOKIES}" "${BASE}/?page_id=${INTERNA}")
INT_ANON=$(curl -s "${BASE}/?page_id=${INTERNA}")

comprobar "pública: 6 pasos con sesión" "6" "$(grep -o 'data-paso="' <<<"${PAG}" | wc -l | tr -d ' ')"
comprobar "pública: 6 pasos SIN sesión" "6" "$(grep -o 'data-paso="' <<<"${ANON}" | wc -l | tr -d ' ')"
comprobar "pública: 35 tarjetas de opción" "35" "$(grep -o 'ncm-opcion__radio' <<<"${ANON}" | wc -l | tr -d ' ')"
comprobar "pública: ya no hay selects" "0" "$(grep -o '<select' <<<"${ANON}" | wc -l | tr -d ' ')"
comprobar "interna: 6 pasos con sesión" "6" "$(grep -o 'data-paso="' <<<"${INT_CON}" | wc -l | tr -d ' ')"
comprobar "interna: sin sesión no pinta el formulario" "0" "$(grep -o 'ncm-opcion__radio' <<<"${INT_ANON}" | wc -l | tr -d ' ')"
comprobar "interna: sin sesión muestra el aviso" "sí" \
	"$(grep -q 'uso interno' <<<"${INT_ANON}" && echo sí || echo no)"
comprobar "assets solo donde está el shortcode" "0" \
	"$(grep -c 'ncm-calculadora.css' <<<"${SIN}" | tr -d ' ')"
comprobar "catálogos incrustados" "sí" \
	"$(grep -q 'ncmCalcData' <<<"${PAG}" && echo sí || echo no)"

echo
echo "== SEO: contenido en el HTML inicial =="
comprobar "texto descriptivo sin interactuar" "sí" \
	"$(grep -q 'ncm-calc__texto' <<<"${ANON}" && echo sí || echo no)"
comprobar "precio desde visible sin interactuar" "sí" \
	"$(grep -q 'ncm-calc__desde-monto' <<<"${ANON}" && echo sí || echo no)"
comprobar "el precio desde es el del catálogo" "sí" \
	"$(grep -q '1.320.000' <<<"${ANON}" && echo sí || echo no)"
comprobar "la intro no filtra costos" "no" \
	"$(grep -q 'Desglose detallado\|Costo de producción' <<<"${ANON}" && echo sí || echo no)"

echo
echo "== AJAX =="
NONCE=$(grep -o '"nonce":"[a-z0-9]*"' <<<"${PAG}" | head -1 | cut -d'"' -f4)
AJAX="${BASE}/wp-admin/admin-ajax.php"
CAMPOS=(-d "action=ncm_calcular" -d "nonce=${NONCE}" -d "tipo=Pulsera"
	--data-urlencode "diseno=Bangle (Rígida)" -d "origen=Natural" -d "gema=Diamante"
	-d "talla=Redonda" --data-urlencode "metal=Oro blanco")

RESP=$(curl -s -b "${COOKIES}" -X POST "${AJAX}" "${CAMPOS[@]}")

comprobar "POST autenticado responde éxito" "true" \
	"$(python3 -c 'import json,sys;print(str(json.loads(sys.stdin.read())["success"]).lower())' <<<"${RESP}")"
comprobar "con el precio del Excel" 'DESDE $14.480.000 COP' \
	"$(python3 -c 'import json,sys;print(json.loads(sys.stdin.read())["data"]["precio_final_formateado"])' <<<"${RESP}")"
NONCE_ANON=$(grep -o '"nonce":"[a-z0-9]*"' <<<"${ANON}" | head -1 | cut -d'"' -f4)
CAMPOS_ANON=(-d "action=ncm_calcular" -d "nonce=${NONCE_ANON}" -d "tipo=Anillo"
	-d "diseno=Solitario" -d "origen=Natural" -d "gema=Diamante"
	-d "talla=Redonda" --data-urlencode "metal=Oro blanco")

RESP_ANON=$(curl -s -X POST "${AJAX}" "${CAMPOS_ANON[@]}")

comprobar "sin sesión sí calcula" "true" \
	"$(python3 -c 'import json,sys;print(str(json.loads(sys.stdin.read())["success"]).lower())' <<<"${RESP_ANON}")"
comprobar "sin sesión recibe el precio" 'DESDE $19.290.000 COP' \
	"$(python3 -c 'import json,sys;print(json.loads(sys.stdin.read())["data"]["precio_final_formateado"])' <<<"${RESP_ANON}")"
comprobar "sin sesión NO recibe gema/metal/margen" "" \
	"$(python3 -c '
import json,sys
d = json.loads(sys.stdin.read())["data"]
prohibidas = ("gema","metal","margen_comercial","valor_margen","costo_produccion","precio_calculado","desglose","codigo","mano_obra","extras")
print(",".join(k for k in prohibidas if k in d))' <<<"${RESP_ANON}")"
comprobar "sin sesión: ninguna cifra sensible en toda la carga" "" \
	"$(python3 -c '
import json,sys
crudo = sys.stdin.read()
print(",".join(c for c in ("12.000.000","2.288.000","14.288.000","5.000.800","19.288.800","650.000","Desglose","Margen") if c in crudo))' <<<"${RESP_ANON}")"

# Con la misma selección, pero con el nonce de la sesión: los nonces de
# WordPress van atados al usuario, así que reusar el del anónimo daría 403.
CAMPOS_INT=(-d "action=ncm_calcular" -d "nonce=${NONCE}" -d "tipo=Anillo"
	-d "diseno=Solitario" -d "origen=Natural" -d "gema=Diamante"
	-d "talla=Redonda" --data-urlencode "metal=Oro blanco")

RESP_INT=$(curl -s -b "${COOKIES}" -X POST "${AJAX}" "${CAMPOS_INT[@]}")

comprobar "el nonce anónimo no sirve con sesión" "403" \
	"$(curl -s -o /dev/null -w '%{http_code}' -b "${COOKIES}" -X POST "${AJAX}" "${CAMPOS_ANON[@]}")"

comprobar "con sesión SÍ recibe el desglose completo" "" \
	"$(python3 -c '
import json,sys
d = json.loads(sys.stdin.read())["data"]
requeridas = ("gema","metal","margen_comercial","valor_margen","costo_produccion","precio_calculado","desglose","codigo")
print(",".join(k for k in requeridas if k not in d))' <<<"${RESP_INT}")"
comprobar "con sesión el desglose trae sus filas" "25" \
	"$(python3 -c 'import json,sys;print(len(json.loads(sys.stdin.read())["data"]["desglose"]))' <<<"${RESP_INT}")"
comprobar "nonce inválido -> 403" "403" \
	"$(curl -s -o /dev/null -w '%{http_code}' -b "${COOKIES}" -X POST "${AJAX}" \
		-d "action=ncm_calcular" -d "nonce=basura" -d "tipo=Anillo" -d "diseno=Solitario" \
		-d "origen=Natural" -d "gema=Diamante" -d "talla=Redonda" --data-urlencode "metal=Oro blanco")"

REV=$(curl -s -b "${COOKIES}" -X POST "${AJAX}" -d "action=ncm_calcular" -d "nonce=${NONCE}" \
	-d "tipo=Anillo" -d "diseno=Tennis" -d "origen=Natural" -d "gema=Diamante" \
	-d "talla=Redonda" --data-urlencode "metal=Oro blanco")

comprobar "Anillo + Tennis -> REVISAR_CONFIGURACION" "REVISAR_CONFIGURACION" \
	"$(python3 -c 'import json,sys;print(json.loads(sys.stdin.read())["data"]["estado"])' <<<"${REV}")"

echo
echo "== Impresión =="
comprobar "el resultado interno trae el botón de imprimir" "sí" \
	"$(grep -q 'ncm-calc__imprimir' <<<"${RESP}" && echo sí || echo no)"
comprobar "el resultado público NO lo trae" "no" \
	"$(grep -q 'ncm-calc__imprimir' <<<"${RESP_ANON}" && echo sí || echo no)"
comprobar "y el membrete con la marca NCM" "sí" \
	"$(grep -q 'ncm-res__membrete' <<<"${RESP}" && echo sí || echo no)"
comprobar "con la nota legal" "sí" \
	"$(python3 -c 'import json,sys;d=json.loads(sys.stdin.read())["data"];print("sí" if "Valor estimado para la configuración" in d["html"] else "no")' <<<"${RESP}")"

echo
echo "== Permisos del panel =="
wpcli user create editor editor@example.test --role=editor --user_pass=editor >/dev/null
COOKIES_ED=$(mktemp)
curl -s -c "${COOKIES_ED}" -b "${COOKIES_ED}" -o /dev/null "${BASE}/wp-login.php"
curl -s -c "${COOKIES_ED}" -b "${COOKIES_ED}" -o /dev/null -L \
	-d "log=editor&pwd=editor&wp-submit=Entrar&redirect_to=${BASE}/wp-admin/&testcookie=1" \
	"${BASE}/wp-login.php"

comprobar "un editor no entra al panel" "403" \
	"$(curl -s -o /dev/null -w '%{http_code}' -b "${COOKIES_ED}" \
		"${BASE}/wp-admin/admin.php?page=ncm-calculadora")"
comprobar "pero sí puede usar la calculadora" "true" \
	"$(curl -s -b "${COOKIES_ED}" "${BASE}/?page_id=${PAGINA}" | grep -o '"nonce":"[a-z0-9]*"' | head -1 \
		| cut -d'"' -f4 | xargs -I{} curl -s -b "${COOKIES_ED}" -X POST "${AJAX}" \
			-d "action=ncm_calcular" -d "nonce={}" -d "tipo=Anillo" -d "diseno=Solitario" \
			-d "origen=Natural" -d "gema=Diamante" -d "talla=Redonda" --data-urlencode "metal=Oro blanco" \
		| python3 -c 'import json,sys;print(str(json.loads(sys.stdin.read())["success"]).lower())')"
rm -f "${COOKIES_ED}"

echo
echo "== Reactivar no pisa ediciones =="
wpcli eval '$c = NCM_Data::get_config(); $c["parametros"]["margen_comercial"] = 0.42; NCM_Data::guardar_config( $c );' >/dev/null
wpcli plugin deactivate ncm-calculadora >/dev/null
wpcli plugin activate ncm-calculadora >/dev/null

comprobar "el margen editado sobrevive" "0.42" "$(wpcli eval 'echo NCM_Data::get_parametro( "margen_comercial" );')"

wpcli eval 'NCM_Data::restaurar_semilla();' >/dev/null

comprobar "restaurar deja el caso A exacto" 'DESDE $14.480.000 COP' "$(wpcli eval "${CASO_A}")"

echo
echo "== Sin avisos de PHP =="
comprobar "el log de Apache no tiene avisos del plugin" "0" \
	"$(docker logs ncm-wp 2>&1 | grep -iE 'PHP (Warning|Notice|Fatal|Deprecated)' | grep -ci ncm | tr -d ' ')"

echo
echo "----------------------------------------"

if [ "${FALLOS}" -gt 0 ]; then
	echo "RESULTADO: ${FALLOS} de ${TOTAL} pruebas fallaron."
	exit 1
fi

echo "RESULTADO: ${TOTAL} pruebas de integración, todas OK."
