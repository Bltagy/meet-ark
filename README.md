# Meet-Ark - Meeting Platform (Phase 3)

Self-hosted video meeting platform integrating a Laravel 13 + Inertia (Vue 3) application layer, PostgreSQL 16 database, official Jitsi Meet (`stable-9955`) secured with server-side JWT authentication, and Excalidraw collaborative whiteboard backend.

---

## Architecture Overview

Meet-Ark is built in modular layers:
- **Application Layer (`app`)**: Laravel 13 (PHP 8.4) with Vue 3 and Inertia.js, providing user authentication, meeting creation, scoped dashboard, room-scoped JWT token issuance, and embedded Jitsi IFrame integration.
- **Database (`postgres`)**: PostgreSQL 16 storing users, meetings, and database-backed sessions.
- **Jitsi Web (`web`)**: Nginx reverse proxy serving Jitsi UI, TLS termination, `external_api.js`, and Socket.io for whiteboard.
- **Prosody XMPP Server (`prosody`)**: Core signaling, MUC room management, and participant presence. Configured with `auth_type=jwt` and `mod_token_moderation` to enforce host privileges and reject anonymous access.
- **Jicofo (`jicofo`)**: Focus supervisor managing conference bridge allocation (`ENABLE_AUTO_OWNER=false`).
- **Jitsi Videobridge (`jvb`)**: WebRTC SFU media server routing audio/video streams over UDP (`10000/udp`).
- **Whiteboard Backend (`whiteboard`)**: Excalidraw collaboration server synchronized via Socket.io.
- **Jibri Recording (Optional Profile)**: Isolated to `--profile recording` (unverified on Linux; disabled locally).

---

## Quick Start (Local Development)

### 1. Prerequisites
- Docker Engine 24+ and Docker Compose v2.20+ (macOS Apple Silicon or Linux).
- Available local ports: `8088` (App), `8000` (HTTP), `8443` (HTTPS), `5432` (PostgreSQL), `10000/udp` (JVB media).

### 2. Initial Setup
Run the setup scripts to configure credentials, JWT secrets, and SSL certificates:

```bash
# 1. Generate secure service credentials in .env
./scripts/gen-passwords.sh

# 2. Generate local SAN TLS certificate
./scripts/gen-certs.sh

# 3. Ensure JWT_APP_SECRET is set in root .env and application/.env
# (Generated via: openssl rand -hex 32)
```

*(Optional for macOS Keychain trust)*:
```bash
sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain ./data/jitsi-meet-cfg/web/keys/cert.crt
```

### 3. Start the Platform
Start all services in detached mode:

```bash
docker compose up -d
```

### 4. Verify Health Status
Check that all 7 containers are healthy:

```bash
docker compose ps
```

Expected status:
```
NAME                    SERVICE      STATUS
meet-ark-app-1          app          Up (healthy)
meet-ark-postgres-1     postgres     Up (healthy)
meet-ark-web-1          web          Up (healthy)
meet-ark-prosody-1      prosody      Up (healthy)
meet-ark-jicofo-1       jicofo       Up (healthy)
meet-ark-jvb-1          jvb          Up (healthy)
meet-ark-whiteboard-1   whiteboard   Up (healthy)
```

### 5. Access the Application
Open your browser and navigate to:
```
http://localhost:8088
```

1. **Register or Log In**: Create an account (e.g. `alice@example.com`).
2. **Dashboard**: Enter a title (e.g. "Sprint Planning") and click **Create Meeting**.
3. **Meeting Page**: The room opens at `http://localhost:8088/meetings/<id>` with an embedded Jitsi meeting. The meeting creator is issued a moderator JWT.
4. **Copy Invitation Link**: Click **Copy Invitation Link** to copy the join link.
5. **Join as Colleague**: Open an incognito/secondary browser, log in as another user (e.g. `bob@example.com`), and open the invitation link. The attendee is issued a participant JWT without moderator rights, even if they join first.
6. **Dashboard Scoping**: Bob's dashboard shows only his own meetings; Alice's meetings remain scoped to Alice.

---

## Running Verification & Tests

### Automated Laravel Test Suite
Run the full test suite inside the container (isolated in-memory SQLite):

```bash
docker compose exec app php artisan test
```
*40 tests covering authentication, route authorization, meeting creation, dashboard scoping, attendee link access, owner deletion, and JWT issuance.*

### Prosody XMPP Protocol Checks
Verify server-side JWT rejection, room scoping, and host permissions directly against Prosody:

```bash
docker compose exec app php scripts/verify_jitsi_jwt.php
```

Checks verified:
- No token → Rejected (`token required`).
- Invalid signature → Rejected (`Invalid signature`).
- Expired token → Rejected (`Not acceptable by exp`).
- Room mismatch (Token for Room A used in Room B) → Rejected (`Room and token mismatched`).
- Valid token for correct room → Accepted.
- Host permissions (Owner joins second, Invitee joins first) → Invitee denied admin query, Owner receives moderator/owner privileges.
- Client-side claim tampering → Rejected (`Invalid signature`).

---

## Operating & Management Commands

### Stop Services
```bash
docker compose down
```

### Rebuild Application
```bash
docker compose up -d --build app
```

### Compile Frontend Assets (Vite)
```bash
docker compose exec app npm run build
```

### View Service Logs
```bash
docker compose logs -f app
docker compose logs -f prosody
docker compose logs -f web
```

---

## Security & Access Boundary

- **Server-Side Token Authentication**: Prosody requires a signed JWT (`ENABLE_AUTH=1`, `ENABLE_GUESTS=0`). Direct anonymous access to `https://localhost:8443/<room>` is rejected.
- **Backend Signing**: Tokens are signed exclusively by Laravel using `HS256`. The secret is never exposed to frontend bundles, clients, or Git.
- **Meeting Deletion**: Deleting a meeting record prevents any future token issuance.
- **Honest Status**: Audio/video streams, screen sharing, and whiteboard live drawing synchronization remain marked **NOT TESTED** until visually observed across participants on external hardware. Public TLS, external media connectivity, and 50-user capacity are unverified.

---

## Production Deployment & Operations

### 1. Production Architecture Summary
- **Laravel Application (`app`)**: Runs on PHP-FPM 8.4 + Nginx on Alpine Linux (`meet-ark-app:prod`). Frontend assets and production Composer packages are built into the image (no runtime compilation or volume overrides).
- **PostgreSQL (`postgres`)**: Private to the internal Docker network `meet.jitsi`. Port 5432 is not exposed to the host.
- **Edge Reverse Proxy**: Nginx Proxy Manager (NPM) on the `nginx-proxy-net` Docker network terminates public TLS, proxying `meet-ark.dev-ark.com` to `meet-ark-app:8088` and `meet.dev-ark.com` to `meet-ark-web:80` with WebSocket support.
- **JVB Media SFU (`jvb`)**: Advertises the server's public IPv4 on `10000/udp`.

### 2. First-Time Deployment

```bash
# 1. Prepare persistent storage directories on the host
mkdir -p data/postgres data/jitsi-meet-cfg data/app-storage

# 2. Copy and populate the production environment file
cp .env.production.example .env.production
./scripts/gen-production-passwords.sh

# 3. Edit .env.production to set domain names, public IP, and generate APP_KEY:
# - APP_URL=https://app.yourdomain.com
# - PUBLIC_URL=https://meet.yourdomain.com
# - JVB_ADVERTISE_IPS=<SERVER_PUBLIC_IPV4>
# - APP_KEY=<output from step 4>

# 4. Generate a persistent production APP_KEY:
docker run --rm meet-ark-app:prod php artisan key:generate --show

# 5. Build and launch production containers (explicit flags ensure dev overrides are excluded)
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production up -d --build

# 6. Run explicit database migrations
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production exec app php artisan migrate --force

# 7. Configure host reverse proxy (deploy/nginx/meet-ark.conf) and obtain TLS certificates:
# certbot --nginx -d app.yourdomain.com -d meet.yourdomain.com
```

### 3. Updating to a Specific Application Revision

```bash
# 1. Pull the target Git commit/tag
git fetch --tags
git checkout <tag-or-commit>

# 2. Rebuild the application image and restart services with zero orphaned config
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production up -d --build app

# 3. Run any pending database migrations explicitly
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production exec app php artisan migrate --force

# 4. Verify service health
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production ps
```

### 4. Backup & Disaster Recovery

#### Backup Database and Persistent Files
```bash
BACKUP_DIR="./backups/$(date +%Y%m%d_%H%M%S)"
mkdir -p "${BACKUP_DIR}"

# 1. PostgreSQL logical dump
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production exec -T postgres pg_dump -U meet_ark meet_ark > "${BACKUP_DIR}/database.sql"

# 2. Archive persistent files and Jitsi configs
tar -czf "${BACKUP_DIR}/data.tar.gz" data/
cp .env.production "${BACKUP_DIR}/env.production.backup"

echo "Backup created at ${BACKUP_DIR}"
```

#### Restore from Backup
```bash
# 1. Stop application containers
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production stop app

# 2. Restore database
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production exec -T postgres psql -U meet_ark -d meet_ark < "${BACKUP_DIR}/database.sql"

# 3. Restore persistent data files if needed
tar -xzf "${BACKUP_DIR}/data.tar.gz" -C .

# 4. Restart application
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production start app
```

#### Application Rollback
```bash
# 1. Roll back the Git revision and rebuild image
git checkout <previous-tag-or-commit>
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production up -d --build app

# 2. Note: If database migrations occurred between revisions, restore the database backup taken before the update.
```

### 5. Jibri Recording Prerequisites (For Future Activation)
Recording is kept disabled in this initial deployment. To enable Jibri on a Linux host in the future:
1. **ALSA Loopback Kernel Module**: Load module `snd-aloop` with 2 devices:
   ```bash
   sudo modprobe snd-aloop devices=2
   echo "snd-aloop" | sudo tee -a /etc/modules
   ```
2. **Compute Resources**: Ensure host has at least 4 dedicated CPU cores and 8GB RAM (video encoding is CPU-intensive).
3. **Start Profile**: Launch with `--profile recording`.
