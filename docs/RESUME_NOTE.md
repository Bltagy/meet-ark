# Resume Note: Linux Server Production Deployment

**Date**: 2026-10-17  
**Current Milestone**: Milestone 6 Completed Locally (Minimal Authenticated API for Mobile & Desktop Clients)  
**Previous Milestone**: Milestone 5 Completed Locally (Arabic & English Bilingual Support & Responsive Usability Pass)  
**Local Status**: Work locally only (no push or deployment to production server)  
**Deployed Production Revision**: `6ea79c2` (`git@github.com:Bltagy/meet-ark.git`)  
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

---

## 8. Milestone: Meeting Scheduling & Clearer Dashboard

- **Database Changes**:
  - Migration `2026_10_17_000001_add_scheduling_to_meetings_table.php` adding nullable `starts_at` (timestamp) and `duration_minutes` (unsigned int).
  - Existing meetings preserved as instant meetings (`starts_at = null`, `duration_minutes = null`).
- **Backend & Validation**:
  - `MeetingController::store` supports both instant and scheduled creation.
  - Future time validation on scheduling (`starts_at` must be future at creation).
  - Timezone-aware parsing (`Carbon::parse($date, $timezone)->utc()`) ensuring times are stored strictly in UTC.
  - Default planned duration: 60 minutes (informational; does not cut off meetings).
  - `MeetingController::update` allows owners to update title, scheduled time, and duration while preserving `room_name` and the invitation URL.
  - Non-owner edit attempts are rejected with HTTP 403.
- **Invitation Flow Enhancement**:
  - Updated `RegisteredUserController` to use `redirect()->intended(...)`, ensuring unauthenticated invitees opening an invitation link are redirected directly to the meeting room after registration or login.
- **Dashboard & UI**:
  - "Start Now" and "Schedule for Later" creation modes.
  - Browser IANA timezone detection (`Intl.DateTimeFormat().resolvedOptions().timeZone`) displayed explicitly.
  - Separate sections: "Upcoming Scheduled Meetings" (ordered by start time ascending) and "Instant & Past Meetings" (without labeling past meetings as "completed").
  - Owner edit modal and confirmation dialogs.
  - Resilient invitation link copying with fallback prompt.
- **Verification & Test Coverage**:
  - 53 automated tests passing (265 assertions) in `php artisan test`, including 13 dedicated scheduling, non-shifting edits, past meeting edits, timezone conversions, and invitation flow tests (`MeetingSchedulingTest`).
  - Live Prosody protocol & JWT verification passed all 7 checks.
  - Vite production bundle compiled cleanly in 0.9s (`app-DK5p6UfS.js`, `app-DkNMZaHZ.css`).
- **Production Release Execution (`devark`)**:
  - Pre-deployment DB backup created: `/var/www/html/meet-ark/backups/db_backup_pre_scheduling_20261017_071652.sql` (chmod 600).
  - Previous app image recorded: `sha256:fc52618d68466358d49c3e47b68aecbe1020af1caff38f4b64afa5710ea8874c`.
  - Built production image natively on server (`sha256:d887926d813321c91f2bd8e7434c122bba721f3bf5fdc58c5fe7ef7032b7011b`).
  - Ran additive migration `2026_10_17_000001_add_scheduling_to_meetings_table` explicitly via production app.
  - Recreated only `meet-ark-app-1` with zero interruption to Jitsi, PostgreSQL, NPM, or unrelated containers.
  - Live production smoke checks verified user registration, future meeting scheduling with IANA timezone conversion, dashboard display under upcoming meetings, title and duration editing with exact room name preservation, Jitsi URL integration, and JWT token issuance. Disposable records cleanly purged.

---

## 9. Milestone 5: Arabic & English Bilingual Support and Responsive Usability Pass (Local Only)

**Status**: Completed Locally (No push or deploy per instructions)

### A. Language Support & Locale Persistence
1. **Locale Management & Flash Prevention**:
   - Implemented [app/Http/Middleware/SetLocale.php](file:///Users/ahmedbltagy/Desktop/Work/meet-ark/application/app/Http/Middleware/SetLocale.php): reads unencrypted `locale` cookie; defaults to `'ar'` (Arabic) on first visit; sets `App::setLocale()`.
   - Excluded `locale` cookie from encryption via `encryptCookies(except: ['locale'])` in [bootstrap/app.php](file:///Users/ahmedbltagy/Desktop/Work/meet-ark/application/bootstrap/app.php), enabling synchronous client-side cookie reading/writing.
   - Root template [resources/views/app.blade.php](file:///Users/ahmedbltagy/Desktop/Work/meet-ark/application/resources/views/app.blade.php) server-renders `<html lang="{{ $locale }}" dir="{{ $dir }}">` before frontend hydration, completely eliminating layout or language direction flashing.
   - Added Cairo font alongside Figtree for Arabic typography via Google Fonts and configured in `tailwind.config.js`.
   - Controller [app/Http/Controllers/LocaleController.php](file:///Users/ahmedbltagy/Desktop/Work/meet-ark/application/app/Http/Controllers/LocaleController.php) exposes `POST /locale` and `GET /locale/{locale}` saving a 1-year unencrypted `locale` cookie (`sameSite: 'lax', httpOnly: false`).
   - Shared reactive `locale` and `dir` Inertia props via [app/Http/Middleware/HandleInertiaRequests.php](file:///Users/ahmedbltagy/Desktop/Work/meet-ark/application/app/Http/Middleware/HandleInertiaRequests.php).

2. **Frontend Translation Structure & Switcher**:
   - Translation dictionaries: [resources/js/locales/en.json](file:///Users/ahmedbltagy/Desktop/Work/meet-ark/application/resources/js/locales/en.json) and [resources/js/locales/ar.json](file:///Users/ahmedbltagy/Desktop/Work/meet-ark/application/resources/js/locales/ar.json).
   - Global translation helper [resources/js/i18n.js](file:///Users/ahmedbltagy/Desktop/Work/meet-ark/application/resources/js/i18n.js) providing `$t()`, reactive locale state, client cookie update, and `formatMeetingTime()`.
   - Component [resources/js/Components/LanguageSwitcher.vue](file:///Users/ahmedbltagy/Desktop/Work/meet-ark/application/resources/js/Components/LanguageSwitcher.vue): visible toggle button embedded in desktop headers, mobile menus, guest navigation, and welcome screen.
   - Comprehensive translation across all application-owned pages:
     - Navigation & branding.
     - Authentication flows (`Login.vue`, `Register.vue`, `ForgotPassword.vue`, `ResetPassword.vue`, `ConfirmPassword.vue`, `VerifyEmail.vue`).
     - Dashboard (`Dashboard.vue`) with schedule/start modes, list sections, empty states, and delete/cancel confirmations.
     - Meeting room page (`Meetings/Show.vue`) with controls, host badges, copy feedback, and exit buttons.
     - User Profile (`Edit.vue`, `UpdateProfileInformationForm.vue`, `UpdatePasswordForm.vue`, `DeleteUserForm.vue`).
   - Backend validation & messages: [lang/ar/validation.php](file:///Users/ahmedbltagy/Desktop/Work/meet-ark/application/lang/ar/validation.php) and [lang/ar.json](file:///Users/ahmedbltagy/Desktop/Work/meet-ark/application/lang/ar.json), plus localized `MeetingController` error messages (`__('...')`).

3. **Preserved Boundaries & RTL Legibility**:
   - Meeting titles entered by users are strictly preserved as-is.
   - URLs, email inputs, technical tokens, room names, and timezone identifiers are wrapped with `dir="ltr"` and `text-start` for pristine readability in RTL layouts.

### B. Dates & Scheduling
- Displayed dates formatted with `Intl.DateTimeFormat(locale, ...)` using the viewer's detected or stored IANA timezone and active language.
- Timezone pill remains explicitly visible next to all timestamps.
- Scheduling input preserves UTC conversion (`Carbon::parse($date, $timezone)->utc()`). Localized display strings are never parsed back into scheduling values.
- Locale toggling does not alter the underlying date/time or scheduled instant.

### C. Jitsi Integration & Locale Passing
- Inspected installed Jitsi Web image (`stable-9955`); confirmed native presence of `/usr/share/jitsi-meet/lang/main-ar.json` and `countries-ar.json`.
- In [resources/js/Pages/Meetings/Show.vue](file:///Users/ahmedbltagy/Desktop/Work/meet-ark/application/resources/js/Pages/Meetings/Show.vue), initialized `JitsiMeetExternalAPI` with:
  - `lang: props.locale`
  - `configOverwrite: { defaultLanguage: props.locale }`
- Verified Jitsi is neither forked nor rebuilt.
- Active meetings are never torn down or re-instantiated upon language switch; the selected language seamlessly applies on next join.

### D. Responsive Usability Pass
- **Phone / Mobile Viewports (320px - 430px)**:
  - Eliminated horizontal overflow with responsive padding (`px-3 sm:px-6 lg:px-8`).
  - Action buttons and timezone indicators styled with `flex-wrap` and adaptive sizing (`text-xs sm:text-sm`).
  - Creation mode toggles and duration options flex cleanly on narrow screens.
  - Meeting edit modal constrained to `max-h-[85vh] overflow-y-auto` so all inputs and buttons remain accessible regardless of viewport height.
  - Jitsi meeting iframe container sized dynamically with `min-h-[460px] sm:min-h-[550px] h-[calc(100vh-210px)] sm:h-[calc(100vh-220px)]`, maximizing usable video area on mobile screens.
- **RTL & LTR Symmetry**:
  - Replaced directional margins with Tailwind `space-x-reverse`, `rtl:space-x-reverse`, and logical flex utilities.
  - Copy-link button feedback works symmetrically in both directions.

### E. Verification Results
1. **Automated Backend & Locale Suite**:
   - Added [tests/Feature/LocaleTest.php](file:///Users/ahmedbltagy/Desktop/Work/meet-ark/application/tests/Feature/LocaleTest.php) with 8 dedicated tests (115 assertions):
     - First visit defaults to Arabic (`ar`) with `dir="rtl"`.
     - Explicit choice persisted in unencrypted `locale` cookie.
     - Language switch updates cookie and reflects in response.
     - Direction helper maps `ar` -> `rtl` and `en` -> `ltr`.
     - Localized validation messages returned in Arabic and English.
     - Scheduled meeting preserves identical UTC instant across locale changes.
   - Full PHPUnit test suite: **61 passed (380 assertions)** via `docker compose exec app php artisan test`.
2. **Live Jitsi Protocol & Authentication**:
   - Executed `scripts/verify_jitsi_jwt.php`: **7/7 passed**.
3. **Frontend Production Build**:
   - `docker compose exec app npm run build` completed in 1.72s with zero warnings or errors.
4. **Browser Testing**:
   - Automated driver download unavailable on ARM64 (`playwright-1.57.0-mac-arm64.zip` 404). Per instructions: **NOT TESTED** via browser automation.
   - Manual verification checklist provided below.

---

## 10. Multi-Platform Client Roadmap

Recorded in [docs/ARCHITECTURE.md](file:///Users/ahmedbltagy/Desktop/Work/meet-ark/docs/ARCHITECTURE.md) (Section 6) and summarized here for upcoming milestones:

### Sequential Phases
1. **Complete the usable web application** (Achieved: responsive bilingual web app with scheduling, token auth, host moderation, and dashboard).
2. **Add a small authenticated API for mobile/desktop** when dedicated client development commences:
   - Reuse existing `MeetingController` / `JitsiTokenService` business logic rather than duplicating code.
   - Add token authentication (e.g. Laravel Sanctum).
   - Keep public application and Jitsi URLs configurable via `.env` without hardcoded domains.
3. **Validate Jitsi integration on Android and iOS**:
   - Leverage official Jitsi Meet SDKs (`jitsi-meet-sdk` for React Native / native Android/iOS).
   - Validate camera/mic background permissions, CallKit / ConnectionService, and token passing.
4. **Validate Windows and macOS desktop apps**:
   - **Critical Architecture Caveats**:
     - Do not assume the mobile Jitsi SDK supports Flutter desktop.
     - Do not assume desktop screen sharing, system-audio capture, and window picking behave identically across platforms.
   - Perform a focused desktop Proof of Concept (PoC) evaluating **Flutter Desktop** vs. an **Electron-based wrapper** before finalizing the framework choice.

### Translation Reusability
- JSON translations in `resources/js/locales/` follow standard key-value conventions (`section.key`) that can be translated or imported into mobile/desktop frameworks (e.g. Arb files for Flutter, JSON for Electron/React Native) without forcing an awkward monolithic translation layer.

---

## 11. Milestone 6: Minimal Authenticated API for Mobile & Desktop Clients (Local Only)

**Status**: Completed Locally (Local isolated testing only, no deployment or push)

### A. Implemented Architecture & Endpoints (`/api/v1`)
1. **Authentication (Laravel Sanctum)**:
   - `POST /api/v1/register`: validates input, rate limited (10/min), bounded `device_name` (max 100 chars), issues finite personal access token (30 days default) with explicit UTC expiry.
   - `POST /api/v1/login`: validates credentials, rate limited (10/min), bounded `device_name`, issues personal access token with explicit UTC expiry.
   - `POST /api/v1/logout`: revokes only the current API token; tokens on other devices remain valid.
   - `GET /api/v1/me`: returns authenticated user profile.
   - Unauthenticated API requests return JSON 401 (`{"message": "Unauthenticated."}`), never HTML redirects.
2. **Meetings Management & Shared Logic**:
   - `GET /api/v1/meetings`: paginated meetings for current user (`data`, `links`, `meta`), user-isolated, ordinary responses never contain Jitsi JWTs.
   - `POST /api/v1/meetings`: instant or scheduled meeting creation; cryptographically generates `room_name` via backend; converts timezones to UTC.
   - `GET /api/v1/meetings/{id}`: invitation details accessible to any authenticated user under existing invitation policy; does not include JWT.
   - `PATCH /api/v1/meetings/{id}`: owner only (403 for non-owners); updates title, schedule, duration while strictly preserving `room_name`.
   - `DELETE /api/v1/meetings/{id}`: owner only (403 for non-owners); deletes meeting record.
   - `POST /api/v1/meetings/{id}/join`: generates short-lived Jitsi JWT (5-minute TTL) via existing `JitsiTokenService`; returns `jitsi_url`, `room_name`, `token`, `expires_at`, `is_moderator`; sets `Cache-Control: no-store, private`; deleted meetings cannot issue join tokens (404).
3. **Shared Logic Extraction**:
   - Extracted `Meeting::generateRoomName()` and `ParsesMeetingDates` trait for clean reuse between web and API controllers without code duplication or calling Inertia controllers.
4. **Locale Support (`Accept-Language`)**:
   - Updated `SetLocale` middleware to inspect `Accept-Language` on API routes (`ar` or `en`) with documented fallback to Arabic (`ar`).
   - Web application cookie-based locale persistence preserved 100%.

### B. Documentation & Verification
- **Documentation**: Created [docs/API.md](file:///Users/ahmedbltagy/Desktop/Work/meet-ark/docs/API.md) with complete endpoint specifications, curl examples, authentication details, status codes, pagination, and scheduling timezone semantics.
- **Automated Tests**:
  - `tests/Feature/Api/AuthApiTest.php` (9 tests)
  - `tests/Feature/Api/MeetingApiTest.php` (13 tests)
  - Full PHPUnit suite: **83 passed (587 assertions)** in isolated SQLite test database.
- **Live Protocol Checks**: `scripts/verify_jitsi_jwt.php` passed all 7 live Prosody checks.


