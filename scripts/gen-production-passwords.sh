#!/usr/bin/env bash
set -euo pipefail

# Meet-Ark Production Credential Generator
# Automatically populates random cryptographically secure credentials in .env.production

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ENV_FILE="${PROJECT_DIR}/.env.production"
ENV_EXAMPLE="${PROJECT_DIR}/.env.production.example"

if [[ ! -f "${ENV_FILE}" ]]; then
    if [[ -f "${ENV_EXAMPLE}" ]]; then
        echo "Creating .env.production from template..."
        cp "${ENV_EXAMPLE}" "${ENV_FILE}"
    else
        echo "ERROR: Template ${ENV_EXAMPLE} not found." >&2
        exit 1
    fi
fi

generate_secret() {
    local length="${1:-16}"
    openssl rand -hex "${length}"
}

echo "Generating random credentials for .env.production..."

DB_PASSWORD=$(generate_secret 16)
JWT_SECRET=$(generate_secret 32)
JICOFO_AUTH_PASSWORD=$(generate_secret 16)
JVB_AUTH_PASSWORD=$(generate_secret 16)
JIGASI_XMPP_PASSWORD=$(generate_secret 16)
JIGASI_TRANSCRIBER_PASSWORD=$(generate_secret 16)
JIBRI_RECORDER_PASSWORD=$(generate_secret 16)
JIBRI_XMPP_PASSWORD=$(generate_secret 16)

# Use sed with backup to update placeholders
sed -i.bak \
    -e "s#^DB_PASSWORD=.*#DB_PASSWORD=${DB_PASSWORD}#g" \
    -e "s#^JWT_APP_SECRET=.*#JWT_APP_SECRET=${JWT_SECRET}#g" \
    -e "s#^JICOFO_AUTH_PASSWORD=.*#JICOFO_AUTH_PASSWORD=${JICOFO_AUTH_PASSWORD}#g" \
    -e "s#^JVB_AUTH_PASSWORD=.*#JVB_AUTH_PASSWORD=${JVB_AUTH_PASSWORD}#g" \
    -e "s#^JIGASI_XMPP_PASSWORD=.*#JIGASI_XMPP_PASSWORD=${JIGASI_XMPP_PASSWORD}#g" \
    -e "s#^JIGASI_TRANSCRIBER_PASSWORD=.*#JIGASI_TRANSCRIBER_PASSWORD=${JIGASI_TRANSCRIBER_PASSWORD}#g" \
    -e "s#^JIBRI_RECORDER_PASSWORD=.*#JIBRI_RECORDER_PASSWORD=${JIBRI_RECORDER_PASSWORD}#g" \
    -e "s#^JIBRI_XMPP_PASSWORD=.*#JIBRI_XMPP_PASSWORD=${JIBRI_XMPP_PASSWORD}#g" \
    "${ENV_FILE}"

rm -f "${ENV_FILE}.bak"

echo "Successfully populated random passwords and JWT secret in .env.production."
echo "NOTE: Remember to set APP_KEY, APP_URL, PUBLIC_URL, and JVB_ADVERTISE_IPS."
