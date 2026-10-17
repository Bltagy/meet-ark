#!/usr/bin/env bash
set -euo pipefail

# Determine script and project directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ENV_FILE="${PROJECT_DIR}/.env"
ENV_EXAMPLE="${PROJECT_DIR}/.env.example"

if [[ ! -f "${ENV_FILE}" ]]; then
    echo "Creating .env from .env.example..."
    cp "${ENV_EXAMPLE}" "${ENV_FILE}"
fi

generate_password() {
    openssl rand -hex 16
}

echo "Generating secure credentials..."
JICOFO_AUTH_PASSWORD=$(generate_password)
JVB_AUTH_PASSWORD=$(generate_password)
JIGASI_XMPP_PASSWORD=$(generate_password)
JIGASI_TRANSCRIBER_PASSWORD=$(generate_password)
JIBRI_RECORDER_PASSWORD=$(generate_password)
JIBRI_XMPP_PASSWORD=$(generate_password)

# Update passwords in .env using sed
sed -i.bak \
    -e "s#^JICOFO_AUTH_PASSWORD=.*#JICOFO_AUTH_PASSWORD=${JICOFO_AUTH_PASSWORD}#g" \
    -e "s#^JVB_AUTH_PASSWORD=.*#JVB_AUTH_PASSWORD=${JVB_AUTH_PASSWORD}#g" \
    -e "s#^JIGASI_XMPP_PASSWORD=.*#JIGASI_XMPP_PASSWORD=${JIGASI_XMPP_PASSWORD}#g" \
    -e "s#^JIGASI_TRANSCRIBER_PASSWORD=.*#JIGASI_TRANSCRIBER_PASSWORD=${JIGASI_TRANSCRIBER_PASSWORD}#g" \
    -e "s#^JIBRI_RECORDER_PASSWORD=.*#JIBRI_RECORDER_PASSWORD=${JIBRI_RECORDER_PASSWORD}#g" \
    -e "s#^JIBRI_XMPP_PASSWORD=.*#JIBRI_XMPP_PASSWORD=${JIBRI_XMPP_PASSWORD}#g" \
    "${ENV_FILE}"

rm -f "${ENV_FILE}.bak"

echo "Passwords successfully generated and configured in .env."
