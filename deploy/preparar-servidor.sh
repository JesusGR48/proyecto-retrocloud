#!/usr/bin/env bash
# =====================================================================
#  RetroCloud · Preparacion del servidor
# =====================================================================
#  Hace las dos cosas que un `git clone` NO puede hacer por si solo:
#
#    1. Crear las carpetas de tiempo de ejecucion. El .gitignore del
#       proyecto ignora logs/ y storage/roms/ (y luego intenta rescatar
#       los .gitkeep con "!"), pero git NO permite rescatar archivos
#       dentro de una carpeta ya ignorada: por eso un clon recien hecho
#       llega SIN esas carpetas y la aplicacion falla al arrancar.
#
#    2. Generar src/config.php a partir de src/config.example.php con
#       las credenciales del hosting, en modo produccion y con HTTPS
#       forzado.
#
#  USO (desde la raiz del proyecto):
#
#    DB_HOST=mysql-tucuenta.alwaysdata.net \
#    DB_NOMBRE=tucuenta_retrocloud \
#    DB_USUARIO=tucuenta \
#    DB_PASSWORD='tu-contrasena' \
#    bash deploy/preparar-servidor.sh
#
#  Es idempotente: se puede volver a ejecutar sin romper nada. No
#  sobrescribe un src/config.php existente salvo que pases FORZAR=1.
# =====================================================================
set -euo pipefail

cd "$(dirname "$0")/.."
RAIZ="$(pwd)"
echo "Proyecto: $RAIZ"
echo

# ---------------------------------------------------------------------
# 1. Carpetas de tiempo de ejecucion
# ---------------------------------------------------------------------
echo "[1/3] Carpetas de tiempo de ejecucion"
for d in logs storage/roms storage/bios storage/saves storage/capturas \
         storage/sesiones storage/tmp public/assets/portadas; do
    if [ -d "$d" ]; then
        echo "      ya existe  $d"
    else
        mkdir -p "$d"
        echo "      creada     $d"
    fi
    [ -f "$d/.gitkeep" ] || touch "$d/.gitkeep"
done

# Permisos: el proceso de PHP debe poder escribir. 775 basta cuando PHP
# corre con tu propio usuario (el caso de AlwaysData); en un servidor
# donde PHP corre como www-data habria que ajustar el grupo.
chmod -R 775 logs storage 2>/dev/null || true
chmod 775 public/assets/portadas 2>/dev/null || true
echo "      permisos de escritura aplicados (775)"
echo

# ---------------------------------------------------------------------
# 2. Las carpetas privadas no deben servirse por web
# ---------------------------------------------------------------------
echo "[2/3] Proteccion de carpetas privadas"
if [ ! -f logs/.htaccess ]; then
    cat > logs/.htaccess <<'HT'
# Los registros de error jamas se sirven por web: revelan rutas
# absolutas, consultas SQL y trazas internas.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
HT
    echo "      creado     logs/.htaccess"
else
    echo "      ya existe  logs/.htaccess"
fi
echo "      storage/.htaccess ya viene en el repositorio"
echo

# ---------------------------------------------------------------------
# 3. src/config.php
# ---------------------------------------------------------------------
echo "[3/3] Configuracion"
if [ -f src/config.php ] && [ "${FORZAR:-0}" != "1" ]; then
    echo "      src/config.php YA EXISTE: no se toca."
    echo "      (para regenerarlo: FORZAR=1 bash deploy/preparar-servidor.sh)"
else
    : "${DB_HOST:?falta la variable DB_HOST}"
    : "${DB_NOMBRE:?falta la variable DB_NOMBRE}"
    : "${DB_USUARIO:?falta la variable DB_USUARIO}"
    : "${DB_PASSWORD:?falta la variable DB_PASSWORD}"

    # Se usa python3 a proposito en lugar de sed: la contrasena puede
    # contener /, &, comillas o barras invertidas, que romperian una
    # sustitucion de sed. Por el entorno no hay nada que escapar.
    DB_HOST="$DB_HOST" DB_NOMBRE="$DB_NOMBRE" DB_USUARIO="$DB_USUARIO" \
    DB_PASSWORD="$DB_PASSWORD" DB_PUERTO="${DB_PUERTO:-3306}" \
    python3 - <<'PY'
import os, re, pathlib

plantilla = pathlib.Path('src/config.example.php')
destino   = pathlib.Path('src/config.php')
s = plantilla.read_text(encoding='utf-8')

def php_str(v):
    return "'" + v.replace('\\', '\\\\').replace("'", "\\'") + "'"

cambios = [
    (r"define\('APP_ENTORNO',\s*'[^']*'\);",
     "define('APP_ENTORNO', 'produccion');"),
    (r"define\('DB_HOST',\s*'[^']*'\);",
     f"define('DB_HOST',   {php_str(os.environ['DB_HOST'])});"),
    (r"define\('DB_PUERTO',\s*\d+\);",
     f"define('DB_PUERTO', {int(os.environ['DB_PUERTO'])});"),
    (r"define\('DB_NOMBRE',\s*'[^']*'\);",
     f"define('DB_NOMBRE', {php_str(os.environ['DB_NOMBRE'])});"),
    (r"define\('DB_USUARIO',\s*'[^']*'\);",
     f"define('DB_USUARIO', {php_str(os.environ['DB_USUARIO'])});"),
    (r"define\('DB_PASSWORD',\s*'[^']*'\);[^\n]*",
     f"define('DB_PASSWORD', {php_str(os.environ['DB_PASSWORD'])});"),
    (r"define\('FORZAR_HTTPS',\s*(?:true|false)\);",
     "define('FORZAR_HTTPS', true);"),
]

for patron, reemplazo in cambios:
    s, n = re.subn(patron, reemplazo, s, count=1)
    if n != 1:
        raise SystemExit(f"ERROR: no se encontro el patron {patron!r} "
                         "en src/config.example.php")

# CONFIAR_PROXY no existe en la plantilla: se anade al final.
if 'CONFIAR_PROXY' not in s:
    s = s.rstrip() + """

// ---------------------------------------------------------------------
// Proxy inverso (lo anade deploy/preparar-servidor.sh)
//
// El hosting termina el TLS en un proxy delante de PHP, asi que la
// peticion llega por HTTP interno y $_SERVER['HTTPS'] viene vacio. Con
// esto, bootstrap.php lee X-Forwarded-Proto y sabe que el visitante si
// esta en HTTPS. Sin ello, FORZAR_HTTPS entra en un bucle infinito de
// redirecciones 301.
//
// En local debe quedar en false: la cabecera se puede falsificar si no
// hay un proxy delante que la filtre.
// ---------------------------------------------------------------------
define('CONFIAR_PROXY', true);
"""

destino.write_text(s, encoding='utf-8')
print("      generado   src/config.php (produccion, HTTPS forzado)")
PY
    chmod 640 src/config.php
    echo "      permisos   640 (solo tu usuario lo lee: lleva la contrasena)"
fi

echo
echo "====================================================================="
echo " Listo. Siguiente paso: importar la base de datos."
echo
echo "   mysql -h \$DB_HOST -u \$DB_USUARIO -p \$DB_NOMBRE \\"
echo "         < database/schema-hosting.sql"
echo "   mysql -h \$DB_HOST -u \$DB_USUARIO -p \$DB_NOMBRE \\"
echo "         < database/seed-hosting.sql"
echo
echo " Y comprobar en el navegador:  https://TU-DOMINIO/diagnostico.php"
echo "====================================================================="
