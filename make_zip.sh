#!/usr/bin/env bash
set -euo pipefail

# Constants
SRC_DIR="src"
OUTPUTS_DIR="outputs"
PLUGIN_SLUG="wp-mp-subscriptions"

if [[ ! -d "$SRC_DIR" ]]; then
  echo "Error: no se encontró el directorio '$SRC_DIR'" >&2
  exit 1
fi

# Extract version from plugin header in src/wp-mp-subscriptions.php
HEADER_FILE="$SRC_DIR/wp-mp-subscriptions.php"
if [[ ! -f "$HEADER_FILE" ]]; then
  echo "Error: no se encontró '$HEADER_FILE'" >&2
  exit 1
fi

# Grep the first Version: line, trim spaces
VERSION=$(awk -F":" '/^\s*\*?\s*Version\s*/ {print $2; exit}' "$HEADER_FILE" | sed 's/^\s*//; s/\s*$//')

if [[ -z "${VERSION:-}" ]]; then
  echo "Error: no se pudo leer la versión desde el header de $HEADER_FILE" >&2
  exit 1
fi

TS=$(date +%Y%m%d-%H%M)
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}-${TS}.zip"

mkdir -p "$OUTPUTS_DIR"

# Empaquetar con carpeta raíz "wp-mp-subscriptions/" para permitir replace-on-upload en WP
TMP_DIR=".wpmps_pkg_tmp"
rm -rf "$TMP_DIR"
mkdir -p "$TMP_DIR/$PLUGIN_SLUG"

# Copiar contenido preservando estructura
# shellcheck disable=SC2174
cp -R "$SRC_DIR"/. "$TMP_DIR/$PLUGIN_SLUG"/

# Use 'zip' if available; otherwise fail clearly
if ! command -v zip >/dev/null 2>&1; then
  echo "Error: 'zip' no está instalado en el entorno" >&2
  rm -rf "$TMP_DIR"
  exit 1
fi

TMP_ZIP_PATH="$OUTPUTS_DIR/$ZIP_NAME"
(
  cd "$TMP_DIR"
  zip -rq "../$TMP_ZIP_PATH" "$PLUGIN_SLUG"
)

rm -rf "$TMP_DIR"

# Print absolute path
ABS_PATH=$(cd "$OUTPUTS_DIR" && pwd)/"$ZIP_NAME"
echo "$ABS_PATH"
