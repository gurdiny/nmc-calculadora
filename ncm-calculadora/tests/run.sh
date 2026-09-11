#!/usr/bin/env bash
#
# Corre las pruebas del plugin. Usa el PHP del sistema si existe; si no,
# levanta un contenedor php:8.2-cli con el plugin montado.
#
#   bash tests/run.sh
#
set -euo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SUITES=(tests/test-calculator.php tests/test-admin.php tests/test-respuesta.php)

if command -v php >/dev/null 2>&1; then
	CORRER=(php)
elif command -v docker >/dev/null 2>&1; then
	CORRER=(docker run --rm -v "${RAIZ}:/app" -w /app php:8.2-cli php)
else
	echo "No hay PHP ni Docker disponibles para correr las pruebas." >&2
	exit 1
fi

cd "${RAIZ}"

for suite in "${SUITES[@]}"; do
	echo "### ${suite}"
	"${CORRER[@]}" "${suite}"
done

echo "Todas las suites pasaron."
