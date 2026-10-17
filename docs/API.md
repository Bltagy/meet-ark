# Meet-Ark Client API Specification (v1)

**Version**: `v1`  
**Base URL (Local)**: `http://localhost:8088/api/v1`  
**Base URL (Production)**: `https://app.<domain>/api/v1`  
**Data Format**: JSON (`Content-Type: application/json`, `Accept: application/json`)

---

## 1. Authentication & Security

Meet-Ark API uses **Laravel Sanctum Personal Access Tokens**.

- **Header**: `Authorization: Bearer <personal_access_token>`
- **Token Format**: `<token_id>|<random_entropy>` (e.g., `1|abcdef...`)
- **Token Lifetime**: Finite lifetime (default 30 days / 43,200 minutes configured via `SANCTUM_EXPIRATION_MINUTES`). The exact UTC expiry is returned as `expires_at` upon issuance.
- **Token Issuance**: Access tokens are returned **only once** upon successful registration or login.
- **Revocation**: `POST /logout` revokes **only** the current API token. Other devices/tokens for the same user remain active.
- **Rate Limiting**: Registration (`POST /register`) and Login (`POST /login`) are rate-limited to 10 attempts per minute per client IP. Exceeding limits returns HTTP `429 Too Many Requests`.
- **Unauthenticated Handling**: Any unauthenticated request to a protected API endpoint returns HTTP `401 Unauthorized` as JSON (never HTML redirects).

---

## 2. Localization & Timezone Semantics

- **Localization Header**: Pass `Accept-Language: ar` for Arabic or `Accept-Language: en` for English.
- **Documented Fallback**: Defaults to Arabic (`ar`) if the header is omitted or unsupported.
- **Localized Content**: Human-readable error messages and notifications are localized. Field names (e.g. `title`), IDs, meeting titles, room names, and error codes are **not** translated.
- **Web Session Isolation**: Web application locale cookies (`locale=ar|en`) operate independently and remain preserved.
- **Timestamps**: All timestamps in responses are returned in machine-readable **ISO 8601 UTC** (e.g. `2026-10-20T12:00:00+00:00`).
- **Scheduling Input**:
  - `starts_at`: ISO 8601 string or date string (e.g., `2026-10-22 15:00:00`). Must be in the future when creating scheduled meetings.
  - `timezone`: Valid IANA timezone identifier (e.g., `Asia/Riyadh`, `UTC`, `America/New_York`).
  - Backend converts inputs to UTC before saving.
  - `duration_minutes`: Integer between `1` and `1440` (default: 60). Informational planned duration.

---

## 3. Standard HTTP Status Codes & Error Formats

| Code | Meaning | Example Scenario |
|---|---|---|
| `200 OK` | Request succeeded | Details, list, login, update, join |
| `201 Created` | Resource created | Registration, meeting created |
| `401 Unauthorized` | Invalid or expired token | Missing `Authorization` header, revoked token |
| `403 Forbidden` | Access denied | Non-owner attempting update or deletion |
| `404 Not Found` | Resource does not exist | Meeting ID not found or previously deleted |
| `422 Unprocessable Content` | Validation failure | Past start time, invalid email, duplicate email |
| `429 Too Many Requests` | Rate limit exceeded | Exceeded 10 auth requests / minute |

### Error Response Schema
```json
{
  "message": "The scheduled start time must be in the future.",
  "errors": {
    "starts_at": [
      "The scheduled start time must be in the future."
    ]
  }
}
```

---

## 4. Endpoints Reference

### 4.1 Authentication

#### `POST /register`
Creates a new user account and returns an initial personal access token.

- **Request Body**:
  ```json
  {
    "name": "Jane Doe",
    "email": "jane@example.com",
    "password": "SecurePassword123!",
    "password_confirmation": "SecurePassword123!",
    "device_name": "iPhone 15 Pro"
  }
  ```
  *(Note: `device_name` is bounded to max 100 characters; defaults to `api-client` if omitted).*

- **Response (`201 Created`)**:
  ```json
  {
    "message": "User registered successfully.",
    "user": {
      "id": 1,
      "name": "Jane Doe",
      "email": "jane@example.com",
      "created_at": "2026-10-17T08:00:00+00:00"
    },
    "token": "<personal_access_token>",
    "token_type": "Bearer",
    "expires_at": "2026-11-16T08:00:00+00:00"
  }
  ```

---

#### `POST /login`
Authenticates credentials and returns a new personal access token.

- **Request Body**:
  ```json
  {
    "email": "jane@example.com",
    "password": "SecurePassword123!",
    "device_name": "macOS Desktop"
  }
  ```

- **Response (`200 OK`)**:
  ```json
  {
    "message": "Authenticated successfully.",
    "user": {
      "id": 1,
      "name": "Jane Doe",
      "email": "jane@example.com",
      "created_at": "2026-10-17T08:00:00+00:00"
    },
    "token": "<personal_access_token>",
    "token_type": "Bearer",
    "expires_at": "2026-11-16T08:00:00+00:00"
  }
  ```

---

#### `POST /logout`
Revokes the bearer token currently in use. Other active device tokens remain valid.

- **Headers**: `Authorization: Bearer <personal_access_token>`
- **Response (`200 OK`)**:
  ```json
  {
    "message": "Successfully logged out."
  }
  ```

---

#### `GET /me`
Returns details of the currently authenticated user.

- **Headers**: `Authorization: Bearer <personal_access_token>`
- **Response (`200 OK`)**:
  ```json
  {
    "user": {
      "id": 1,
      "name": "Jane Doe",
      "email": "jane@example.com",
      "created_at": "2026-10-17T08:00:00+00:00"
    }
  }
  ```

---

### 4.2 Meetings Management

> **Important**: Ordinary meeting endpoints (`GET /meetings`, `POST /meetings`, `GET /meetings/{id}`, `PATCH /meetings/{id}`) **never** return Jitsi join tokens. Join tokens are strictly isolated to the dedicated `POST /meetings/{id}/join` endpoint.

#### `GET /meetings`
Returns a paginated list of meetings owned by the authenticated user, ordered by creation date descending.

- **Headers**: `Authorization: Bearer <personal_access_token>`
- **Query Parameters**:
  - `page`: Page number (default: `1`).
  - `per_page`: Number of records per page (default: `15`, min: `1`, max: `100`).
- **Response (`200 OK`)**:
  ```json
  {
    "data": [
      {
        "id": 10,
        "title": "Architecture Review",
        "room_name": "meet-f9a8b7c6d5e4",
        "starts_at": "2026-10-22T12:00:00+00:00",
        "duration_minutes": 60,
        "is_scheduled": true,
        "is_owner": true,
        "created_at": "2026-10-17T08:30:00+00:00",
        "updated_at": "2026-10-17T08:30:00+00:00",
        "invitation_url": "http://localhost:8088/meetings/10"
      }
    ],
    "links": {
      "first": "http://localhost:8088/api/v1/meetings?page=1",
      "last": "http://localhost:8088/api/v1/meetings?page=1",
      "prev": null,
      "next": null
    },
    "meta": {
      "current_page": 1,
      "from": 1,
      "last_page": 1,
      "per_page": 15,
      "to": 1,
      "total": 1
    }
  }
  ```

---

#### `POST /meetings`
Creates an instant or scheduled meeting. Backend cryptographically generates `room_name`.

- **Instant Meeting Payload**:
  ```json
  {
    "title": "Quick Standup"
  }
  ```

- **Scheduled Meeting Payload**:
  ```json
  {
    "title": "Quarterly Planning",
    "type": "scheduled",
    "starts_at": "2026-10-25 14:00:00",
    "timezone": "Asia/Riyadh",
    "duration_minutes": 90
  }
  ```

- **Response (`201 Created`)**:
  ```json
  {
    "message": "Meeting created successfully.",
    "meeting": {
      "id": 11,
      "title": "Quarterly Planning",
      "room_name": "meet-a1b2c3d4e5f6",
      "starts_at": "2026-10-25T11:00:00+00:00",
      "duration_minutes": 90,
      "is_scheduled": true,
      "is_owner": true,
      "created_at": "2026-10-17T08:45:00+00:00",
      "updated_at": "2026-10-17T08:45:00+00:00",
      "invitation_url": "http://localhost:8088/meetings/11"
    }
  }
  ```

---

#### `GET /meetings/{id}`
Returns details for an existing meeting. Accessible to any authenticated user with the ID (owner or invitee).

- **Headers**: `Authorization: Bearer <personal_access_token>`
- **Response (`200 OK`)**:
  ```json
  {
    "meeting": {
      "id": 11,
      "title": "Quarterly Planning",
      "room_name": "meet-a1b2c3d4e5f6",
      "starts_at": "2026-10-25T11:00:00+00:00",
      "duration_minutes": 90,
      "is_scheduled": true,
      "is_owner": false,
      "created_at": "2026-10-17T08:45:00+00:00",
      "updated_at": "2026-10-17T08:45:00+00:00",
      "invitation_url": "http://localhost:8088/meetings/11"
    }
  }
  ```

---

#### `PATCH /meetings/{id}`
Updates an existing meeting. Restricted to the meeting **owner only**. Non-owners receive HTTP `403 Forbidden`. `room_name` and `owner_id` are strictly immutable.

- **Request Body**:
  ```json
  {
    "title": "Updated Strategy Review",
    "starts_at": "2026-10-26 15:00:00",
    "timezone": "UTC",
    "duration_minutes": 45
  }
  ```

- **Response (`200 OK`)**:
  ```json
  {
    "message": "Meeting updated successfully.",
    "meeting": {
      "id": 11,
      "title": "Updated Strategy Review",
      "room_name": "meet-a1b2c3d4e5f6",
      "starts_at": "2026-10-26T15:00:00+00:00",
      "duration_minutes": 45,
      "is_scheduled": true,
      "is_owner": true,
      "created_at": "2026-10-17T08:45:00+00:00",
      "updated_at": "2026-10-17T08:50:00+00:00",
      "invitation_url": "http://localhost:8088/meetings/11"
    }
  }
  ```

---

#### `DELETE /meetings/{id}`
Deletes the meeting record. Restricted to the meeting **owner only**. Non-owners receive HTTP `403 Forbidden`. Once deleted, no further join tokens can be issued for this meeting (subsequent join requests return `404`).

- **Headers**: `Authorization: Bearer <personal_access_token>`
- **Response (`200 OK`)**:
  ```json
  {
    "message": "Meeting deleted successfully."
  }
  ```

---

### 4.3 Jitsi Join Token

#### `POST /meetings/{id}/join`
Issues a room-scoped, short-lived signed JWT for an authenticated user to connect to the Jitsi conference.

- **Headers**:
  - `Authorization: Bearer <personal_access_token>`
  - `Accept: application/json`
- **Response Headers**:
  - `Cache-Control: no-store, private` *(ensures responses and tokens are never cached by intermediate proxies or mobile caches)*
- **Privilege Logic**:
  - If the authenticated user is the meeting owner: `is_moderator: true`, JWT claim `moderator: true`, `context.user.moderator: true`.
  - If the authenticated user is an invitee: `is_moderator: false`, JWT claim `moderator: false`, `context.user.moderator: false`.
- **Token TTL**: 5 minutes (300 seconds).
- **Deleted Meetings**: Returns HTTP `404 Not Found`.

- **Response (`200 OK`)**:
  ```json
  {
    "jitsi_url": "https://localhost:8443",
    "room_name": "meet-a1b2c3d4e5f6",
    "token": "<jitsi_hs256_signed_jwt>",
    "expires_at": "2026-10-17T08:55:00+00:00",
    "is_moderator": true
  }
  ```

---

## 5. Client Integration Quick Guide (Mobile / Desktop)

1. **Authentication**: Call `POST /api/v1/login` or `POST /api/v1/register` with your `device_name`. Store the returned Bearer `token` securely in Keychain (iOS/macOS), EncryptedSharedPreferences / KeyStore (Android), or DPAPI / Secret Service (Desktop).
2. **List Meetings**: Call `GET /api/v1/meetings` with `Authorization: Bearer <token>`.
3. **Open Invitation Link**: When receiving a Meet-Ark link like `https://app.<domain>/meetings/11`, call `GET /api/v1/meetings/11` to inspect details.
4. **Join Conference**: Call `POST /api/v1/meetings/11/join`.
   - Pass `jitsi_url`, `room_name`, and `token` directly to the Jitsi Meet SDK (`JitsiMeetConferenceOptions` on Android/iOS, or IFrame/Electron API on Desktop).
   - If the token expires before the room is joined, call `POST /meetings/{id}/join` again to obtain a fresh 5-minute token.
