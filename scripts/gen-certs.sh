#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

# Source .env if present
if [[ -f "${PROJECT_DIR}/.env" ]]; then
    # shellcheck disable=SC1091
    source "${PROJECT_DIR}/.env"
fi

CONFIG_DIR="${CONFIG:-./data/jitsi-meet-cfg}"
# Resolve relative to project dir if relative
if [[ "${CONFIG_DIR}" != /* ]]; then
    CONFIG_DIR="${PROJECT_DIR}/${CONFIG_DIR#./}"
fi

KEYS_DIR="${CONFIG_DIR}/web/keys"
mkdir -p "${KEYS_DIR}"

CERT_KEY="${KEYS_DIR}/cert.key"
CERT_CRT="${KEYS_DIR}/cert.crt"

echo "Generating local TLS certificate with Subject Alternative Names (SAN)..."

# Detect host LAN IP if available (for cross-device LAN testing)
HOST_IP=$(ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1 2>/dev/null || echo "127.0.0.1")

SAN="DNS:localhost,IP:127.0.0.1,IP:::1"
if [[ -n "${HOST_IP}" && "${HOST_IP}" != "127.0.0.1" ]]; then
    SAN="${SAN},IP:${HOST_IP}"
    echo "Detected LAN IP: ${HOST_IP} (included in certificate SAN)"
fi

openssl req -x509 -nodes -newkey rsa:2048 -days 365 \
    -keyout "${CERT_KEY}" \
    -out "${CERT_CRT}" \
    -subj "/CN=localhost/O=Meet-Ark Local Dev/C=US" \
    -addext "subjectAltName = ${SAN}" \
    -addext "basicConstraints = CA:FALSE" \
    -addext "keyUsage = digitalSignature, keyEncipherment" \
    -addext "extendedKeyUsage = serverAuth"

echo "Certificate and key generated successfully in:"
echo "  ${CERT_CRT}"
echo "  ${CERT_KEY}"
echo ""
echo "To trust this certificate on macOS (enabling green lock and clean WebRTC testing in Chrome/Safari):"
echo "  sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain \"${CERT_CRT}\""
