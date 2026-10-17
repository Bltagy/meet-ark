# Resume Note: Linux Server Production Deployment

**Date**: 2026-10-17  
**Current Milestone**: Linux Server Production Deployment Complete (Internal Stack Verified, Awaiting NPM Host Entries)  
**Previous Milestone**: Phase 3 Complete & Production Configuration Prepared
**Deployed Revision**: `f8beb88` (`git@github.com:Bltagy/meet-ark.git`)
**Server**: `devark` (`192.248.191.76`), Ubuntu 24.04 LTS (x86_64)  
**Deployment Path**: `/var/www/html/meet-ark`

---

## 1. Operational Stack Summary

- **Running Services (All 7 Containers Healthy)**:
  - `meet-ark-app-1`: Laravel 13 application running on PHP 8.4 CLI (`http://localhost:8088`).
  - `meet-ark-postgres-1`: PostgreSQL 16 database on port `5432`.
  - `meet-ark-web-1`: Jitsi Web (Nginx) on `8000` (HTTP) and `8443` (HTTPS).
  - `meet-ark-prosody-1`: Prosody XMPP signaling server (`stable-9955`) with `auth_type=jwt` and `mod_token_moderation`.
  - `meet-ark-jicofo-1`: Jicofo conference focus supervisor (`stable-9955`) with `ENABLE_AUTO_OWNER=false`.
  - `meet-ark-jvb-1`: Jitsi Videobridge SFU on `10000/udp`.
  - `meet-ark-whiteboard-1`: Excalidraw collaboration backend on internal Docker network.
  - `jibri`: Disabled locally via Compose profile `recording` (unverified on Linux host).

- **URLs & Endpoints**:
  - **Application UI**: `http://localhost:8088` (login, register, dashboard, meeting embed).
  - **Jitsi Direct HTTPS**: `https://localhost:8443/<room-name>` (direct access without valid signed JWT rejected by Prosody).
  - **HTTP to HTTPS Redirect**: `http://localhost:8000/` redirects to `https://localhost:8443/` (port 8443 preserved).

---

## 2. Implemented in Phase 3

1. **Jitsi Server-Side Token Authentication**:
   - Enabled `ENABLE_AUTH=1`, `ENABLE_GUESTS=0`, `AUTH_TYPE=jwt` in Jitsi Compose environment.
   - Pinned JWT claims: `JWT_APP_ID=meet_ark_backend`, `JWT_ACCEPTED_ISSUERS=meet_ark_backend`, `JWT_ACCEPTED_AUDIENCES=meet_ark_platform`.
   - Signing secret (`JWT_APP_SECRET`) stored strictly on the backend (`application/.env` and root `.env`), never exposed to frontend bundles, Git, or API responses.

2. **Room-Scoped Backend JWT Issuance (`JitsiTokenService`)**:
   - Installed `firebase/php-jwt` (^7.2) in Laravel backend.
   - Built [app/Services/JitsiTokenService.php](file:///Users/ahmedbltagy/Desktop/Work/meet-ark/application/app/Services/JitsiTokenService.php):
     - Issues short-lived (5-minute TTL, 10s leeway for clock skew) HS256-signed JWTs.
     - Scoped strictly to the specific meeting's `room_name` (`sub=meet.jitsi`, `room=<meeting->room_name>`).
     - Embeds user identity (`context.user.id`, `context.user.name`, `context.user.email`).
      - Enforces host status: sets `context.user.moderator = true` and root `moderator = true` only when `(int) $meeting->owner_id === (int) $user->id`. Invitees receive `moderator = false`. (Note: Database schema, models, controllers, and tests consistently use `owner_id`; earlier reference to `user_id` was a documentation typo).
    - Tokens issued only when an authenticated user accesses `GET /meetings/{meeting}` via [MeetingController::show](file:///Users/ahmedbltagy/Desktop/Work/meet-ark/application/app/Http/Controllers/MeetingController.php).
    - Deleting a meeting record prevents any future token issuance (subsequent requests 404). Note: as designed, deleting a meeting record does not terminate an already active XMPP conference or instantly revoke previously issued 5-minute tokens.

3. **Frontend IFrame API Integration**:
   - [Show.vue](file:///Users/ahmedbltagy/Desktop/Work/meet-ark/application/resources/js/Pages/Meetings/Show.vue) receives the backend-issued `token` prop.
   - Passes `jwt: props.token` to `JitsiMeetExternalAPI` options.
   - Copy-link button copies the full application URL (`window.location.origin + '/meetings/' + meeting.id`), with fallback clipboard support for non-HTTPS contexts.

4. **Host Permission & Join Order Enforcement (`mod_token_moderation.lua`)**:
   - Identified Prosody `stable-9955` / Jicofo limitation: default `mod_token_verification` only checks room match and does not set MUC affiliations or check `context.user.moderator`. Furthermore, Jicofo default `enable-auto-owner = true` grants moderator privileges to whoever joins first.
   - Configured `ENABLE_AUTO_OWNER=false` in Jicofo.
   - Configured `XMPP_MUC_CONFIGURATION=restrict_room_creation = "local"` so only authenticated sessions on `meet.jitsi` can create rooms.
   - Implemented custom Prosody module [docker/prosody/mod_token_moderation.lua](file:///Users/ahmedbltagy/Desktop/Work/meet-ark/docker/prosody/mod_token_moderation.lua):
     - Hooks `muc-room-pre-create` at priority -2: overrides Prosody's default auto-owner behavior by inspecting token claims of the creator. If creator has `moderator = false`, demotes first occupant affiliation to `member`.
     - Hooks `muc-occupant-pre-join` and `muc-occupant-joined`: assigns `owner` (`moderator`) affiliation only if `is_token_moderator(session)` is true; assigns `member` (`participant`) otherwise.
     - Wraps `room.set_affiliation` on `muc-room-created` to prevent any unauthorized external elevation to `owner`.

---

## 3. Verification & Test Evidence

### A. Automated Laravel Tests
- **Status**: 40 passed (172 assertions) via `docker compose exec app php artisan test`.
- **Database Isolation**: Tests run in an in-memory SQLite database (`:memory:`), completely isolated from the development PostgreSQL database (`meet_ark`).
- **Test Coverage**:
  - Route authentication, meeting creation, scoped dashboard isolation, invitee link access, owner-only deletion.
  - `JitsiTokenTest`: Owner receives moderator token; invitee receives non-moderator token; show route yields scoped token; unauthenticated access denied; deleting meeting record prevents token issuance; tampered token signature failure. All tests read secret dynamically from configuration without hardcoded values.

### B. Live Prosody XMPP Protocol Checks & Real Path Verification
Executed live against the running Prosody BOSH endpoint (`https://web:443/http-bind`) via `docker compose exec app php scripts/verify_jitsi_jwt.php` (reading secret from configuration, without hardcoded signing keys or exposing tokens in output):

| Check | Scenario | Result | Evidence |
|---|---|---|---|
| 1 | No token provided | **PASS** | Rejected by Prosody with `<failure><not-allowed/><text>token required</text></failure>` |
| 2 | Invalid signature | **PASS** | Rejected by Prosody with `<failure><not-allowed/><text>Invalid signature</text></failure>` |
| 3 | Expired token | **PASS** | Rejected by Prosody with `<failure><not-allowed/><text>Not acceptable by exp</text></failure>` |
| 4 | Room mismatch (Token for room-a in room-b) | **PASS** | Rejected by Prosody MUC with `<error><not-allowed/><text>Room and token mismatched</text></error>` |
| 5 | Valid token for correct room | **PASS** | Prosody accepted session and allowed MUC room join |
| 6 | Real Path: Invitee opens route & joins first (User B) | **PASS** | Token issued via real `MeetingController::show` + `JitsiTokenService`; User B connects first; denied `muc#admin` owner query with `<error type='auth'><forbidden/></error>` |
| 7 | Real Path: Owner opens route & joins second (User A) | **PASS** | Token issued via real `MeetingController::show` + `JitsiTokenService`; User A connects second; returned in XEP-0045 `muc#admin` query as `item affiliation='owner'` |
| 8 | Client-side claim tampering | **PASS** | Attacker modifying `moderator: true` without server secret rejected with `Invalid signature` |
| 9 | Old rotated secret rejection | **PASS** | Token signed with previous rotated secret rejected by Prosody with `<failure><not-allowed/><text>Invalid signature</text></failure>` |

### C. Browser Testing Flow & Tooling Report
- **Subagent Automated Browser Tooling**: Failed during driver acquisition because the upstream Playwright ARM64 binary download returned 404 (`playwright-1.57.0-mac-arm64.zip`). In accordance with task instructions, driver downloads were not repeatedly retried.
- **Manual Browser Verification Checklist**:
  1. **Login & Meeting Creation**:
     - Navigate to `http://localhost:8088`. Register User 1 (Alice: `alice@example.com` / `password123`).
     - Create a meeting titled "Sprint Review".
     - Copy the invitation link via the UI button; verify clipboard contains `http://localhost:8088/meetings/<id>`.
  2. **Invitee Joins First, Owner Joins Second**:
     - In an incognito window, register User 2 (Bob: `bob@example.com` / `password123`).
     - Paste invitation link `http://localhost:8088/meetings/<id>` in User 2's session first.
     - User 1 joins second in the primary window.
  3. **Actual In-Meeting Host Controls & Participant Restrictions**:
     - Do not rely on application "Host Mode" / "Participant Mode" badges (those reflect Inertia UI state only).
     - Inside the Jitsi IFrame, verify Alice has host action controls (Mute Everyone, Kick Participant, Grant Moderator).
     - Verify Bob has restricted participant UI (no Mute Everyone or kick controls).
  4. **Media & Collaboration Features**:
     - Device/browser media and whiteboard synchronization must be manually checked across both windows.
     - Note: Marked **NOT TESTED** below until physically observed.

---

## 4. Status of Features & Boundaries

- **Audio / Video Transmission**: **NOT TESTED** (device-dependent, requires physical camera/mic input).
- **Screen Sharing**: **NOT TESTED** (requires manual screen capture permission prompt).
- **Whiteboard Live Synchronization**: **NOT TESTED** (marked NOT TESTED until multi-session live drawings are visually observed synchronizing).
- **Jibri Recording**: **BLOCKED LOCALLY** on macOS (requires Linux kernel ALSA loopback driver `snd-aloop`). Kept disabled locally.
- **Meeting Deletion**: Deleting a meeting record prevents any future token issuance from Laravel; does not terminate an in-flight conference or revoke active 5-minute tokens.

---

## 5. Local Start & Operation Commands

- **Local Application URL**: `http://localhost:8088`
- **Start Stack**: `docker compose up -d`
- **Verify Health**: `docker compose ps` (all 7 services healthy)
- **Run Laravel Tests**: `docker compose exec app php artisan test`
- **Run Protocol Verification**: `docker compose exec app php scripts/verify_jitsi_jwt.php`

---

## 6. Production Deployment Preparation (Completed)

- **User Acceptance**: The user accepted the current local experience; enhancements postponed.
- **Production Artifacts Prepared**:
  - `docker/app/Dockerfile.prod`: Multi-stage build (Node 22 asset compilation, Composer `--no-dev`, PHP-FPM 8.4 + Nginx on Alpine).
  - `docker-compose.prod.yml`: Explicit production Compose definition without dev overrides, host source binds, or open PostgreSQL ports.
  - `.env.production.example`: Complete placeholder configuration for production variables.
  - `deploy/nginx/meet-ark.conf`: Host edge reverse proxy configuration for `app.<domain>` and `meet.<domain>` with WebSocket and BOSH proxying.
  - `scripts/gen-production-passwords.sh`: Generates secure secrets and passwords for `.env.production`.
- **Local Production Verification**:
  - `docker build -f docker/app/Dockerfile.prod -t meet-ark-app:prod .` passed (exit code 0).
  - Configuration validated with `docker compose -f docker-compose.yml -f docker-compose.prod.yml config -q`.
  - Smoke-tested production image on isolated port `8099`: Nginx + PHP-FPM serving `/up` (HTTP 200) and compiled Vite assets `/build/manifest.json`.
  - Database isolation: Development PostgreSQL remained untouched; SQLite used for tests and smoke run.
- **Status of Production Boundaries**:
  - External WebRTC media connectivity and public TLS are **NOT TESTED LOCALLY** (require public IP and DNS).
  - 50-user load capacity is **UNVERIFIED** (load testing postponed).
  - Jibri recording remains **DISABLED** for initial deployment.

---

## 7. Production Deployment Execution on Linux Server (`devark`)

- **Server Info**: Ubuntu 24.04.4 LTS, x86_64, 4 vCPUs, 7.7 GiB RAM, 47 GiB disk available.
- **Server Deployment Path**: `/var/www/html/meet-ark`
- **Git Commit Deployed**: `f8beb88` (`main`)
- **Docker Compose Command**:
  ```bash
  docker compose -p meet-ark -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production <cmd>
  ```
- **Port Conflict Mitigations on Shared Server**:
  - `APP_PORT=127.0.0.1:8098` (avoiding host 8088 occupied by Mailcow).
  - `HTTPS_PORT=127.0.0.1:8444` (avoiding host 8443 occupied by foodapp).
  - `JVB_COLIBRI_PORT=8082` (avoiding host 8080 occupied by doctube).
  - `JICOFO_REST_PORT=8889` (avoiding host 8888 occupied by Dozzle).
  - PostgreSQL port 5432 is private inside `meet-ark_default` (avoiding host 5432 occupied by existing `backend-postgres`).
  - JVB media port `10000/udp` bound to `0.0.0.0:10000` with UFW rule active.
- **Nginx Proxy Manager Integration**:
  - `meet-ark-app-1` and `meet-ark-web-1` joined external network `nginx-proxy-net` with aliases `meet-ark-app` and `meet-ark-web`.
  - All internal HTTP, BOSH, Whiteboard Socket.io, and XMPP WebSocket connections tested from inside NPM container and passing.
- **Automated Protocol & Host Privilege Checks on Production Server**:
  - Executed `scripts/verify_jitsi_jwt.php` inside `meet-ark-app-1`: all 7 security, JWT rejection, and room/host privilege tests passed.
  - Test records cleaned up.
