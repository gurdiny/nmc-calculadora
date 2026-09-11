#!/usr/bin/env bash
#
# Genera el .zip instalable del plugin en dist/.
#
#   bash bin/empaquetar.sh
#
# El paquete solo lleva lo que WordPress necesita en tiempo de ejecución. Se
# excluye todo el andamiaje de desarrollo:
#
#   tests/          se ejecutan fuera de WordPress (definen su propio ABSPATH),
#                   así que dentro de wp-content/plugins/ quedarían accesibles
#                   por URL.
#   vendor/         PHPUnit y sus dependencias: nunca deben llegar a producción.
#   composer.*      manifiesto de dependencias de desarrollo.
#   phpunit.xml.dist, .wp-env.json  configuración del entorno de pruebas.
set -euo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN="ncm-calculadora"
DESTINO="${RAIZ}/dist"

cd "${RAIZ}"

if [ ! -f "${PLUGIN}/${PLUGIN}.php" ]; then
	echo "No encuentro ${PLUGIN}/${PLUGIN}.php" >&2
	exit 1
fi

VERSION="$(grep -m1 '^ \* Version:' "${PLUGIN}/${PLUGIN}.php" | sed 's/.*Version: *//' | tr -d '[:space:]')"
ZIP="${DESTINO}/${PLUGIN}-${VERSION}.zip"

echo "Empaquetando ${PLUGIN} ${VERSION}…"

# Antes de empaquetar, que las pruebas pasen.
bash "${PLUGIN}/tests/run.sh" >/dev/null || {
	echo "Las pruebas fallaron: no se empaqueta." >&2
	exit 1
}

# Y las de PHPUnit, si el entorno de wp-env está levantado.
if command -v wp-env >/dev/null 2>&1 && [ -d "${PLUGIN}/vendor" ]; then
	if ( cd "${PLUGIN}" && wp-env run tests-cli --env-cwd=wp-content/plugins/"${PLUGIN}" \
		vendor/bin/phpunit >/dev/null 2>&1 ); then
		echo "PHPUnit: OK"
	else
		echo "PHPUnit no pudo correr (¿wp-env apagado?): se empaqueta igual." >&2
	fi
fi

mkdir -p "${DESTINO}"
rm -f "${ZIP}"

if command -v zip >/dev/null 2>&1; then
	zip -r -q "${ZIP}" "${PLUGIN}" \
		-x "${PLUGIN}/tests/*" \
		-x "${PLUGIN}/vendor/*" \
		-x "${PLUGIN}/composer.json" \
		-x "${PLUGIN}/composer.lock" \
		-x "${PLUGIN}/phpunit.xml.dist" \
		-x "${PLUGIN}/.*" \
		-x "*/.DS_Store" -x "*/.git/*" -x "*.zip"
else
	# Sin zip(1): se usa Python, que siempre está a mano.
	python3 - "${PLUGIN}" "${ZIP}" <<'PYEOF'
import os, sys, zipfile

plugin, destino = sys.argv[1], sys.argv[2]

EXCLUIR_DIRS = {".git", "tests", "vendor", "node_modules", "__pycache__"}
EXCLUIR_ARCHIVOS = {"composer.json", "composer.lock", "phpunit.xml.dist", ".DS_Store"}

with zipfile.ZipFile(destino, "w", zipfile.ZIP_DEFLATED) as z:
    for raiz, dirs, archivos in os.walk(plugin):
        dirs[:] = [d for d in dirs if d not in EXCLUIR_DIRS]
        for nombre in archivos:
            if nombre in EXCLUIR_ARCHIVOS or nombre.startswith(".") or nombre.endswith(".zip"):
                continue
            ruta = os.path.join(raiz, nombre)
            z.write(ruta, ruta)
PYEOF
fi

echo
echo "Listo: ${ZIP}"
echo "Contenido:"
python3 - "${ZIP}" <<'PYEOF'
import sys, zipfile

with zipfile.ZipFile(sys.argv[1]) as z:
    nombres = sorted(z.namelist())
    total = sum(i.file_size for i in z.infolist())

for n in nombres:
    print("   " + n)

print(f"\n   {len(nombres)} archivos, {total / 1024:.0f} KB sin comprimir")
PYEOF
