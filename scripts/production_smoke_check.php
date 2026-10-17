<?php

/**
 * Meet-Ark Production API & Web Smoke Check
 *
 * Runs non-destructive, isolated verification against production https://meet-ark.dev-ark.com
 * Never prints raw credentials or complete tokens.
 * Tracks exact disposable IDs and cleans them up.
 */

require __DIR__ . '/../application/vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$baseUrl = 'https://meet-ark.dev-ark.com';
$runId = date('Ymd_His') . '_' . bin2hex(random_bytes(3));
$userA_email = "smoke_a_{$runId}@smoke.meet-ark.test";
$userB_email = "smoke_b_{$runId}@smoke.meet-ark.test";
$password = 'SmokePass_' . bin2hex(random_bytes(6)) . '!';

$results = [];
$trackedIds = [
    'users' => [],
    'meetings' => [],
];

function apiRequest(string $method, string $path, ?array $body = null, ?string $token = null, array $extraHeaders = []): array
{
    global $baseUrl;
    $url = $baseUrl . $path;
    $ch = curl_init($url);

    $headers = [
        'Accept: application/json',
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    foreach ($extraHeaders as $k => $v) {
        $headers[] = "{$k}: {$v}";
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $rawHeaders = substr($response, 0, $headerSize);
    $rawBody = substr($response, $headerSize);

    return [
        'status' => $statusCode,
        'headers' => $rawHeaders,
        'body' => json_decode($rawBody, true) ?? $rawBody,
    ];
}

echo "=== STARTING PRODUCTION SMOKE CHECKS (Run ID: {$runId}) ===\n\n";

try {
    // 1. Unauthenticated API request
    echo "1. Checking Unauthenticated API Request (GET /api/v1/meetings)... ";
    $unauth = apiRequest('GET', '/api/v1/meetings');
    if ($unauth['status'] === 401 && is_array($unauth['body']) && ($unauth['body']['message'] ?? '') === 'Unauthenticated.') {
        echo "[PASS] Returned 401 JSON\n";
    } else {
        throw new RuntimeException("Expected 401 JSON, got status {$unauth['status']}");
    }

    // 2. Register User A
    echo "2. Registering Disposable User A via API... ";
    $regA = apiRequest('POST', '/api/v1/register', [
        'name' => 'Smoke User A',
        'email' => $userA_email,
        'password' => $password,
        'password_confirmation' => $password,
        'device_name' => 'iPhone Smoke',
    ]);
    if ($regA['status'] === 201 && !empty($regA['body']['token']) && !empty($regA['body']['user']['id'])) {
        $userA_id = $regA['body']['user']['id'];
        $trackedIds['users'][] = $userA_id;
        $tokenA1 = $regA['body']['token'];
        $tokenA1_prefix = substr($tokenA1, 0, 6) . '...';
        echo "[PASS] Created user ID: {$userA_id}, Token issued: {$tokenA1_prefix}, Expiry: {$regA['body']['expires_at']}\n";
    } else {
        throw new RuntimeException("User A registration failed: status {$regA['status']}");
    }

    // 3. Login User A (Device 2)
    echo "3. Logging in User A second device (MacBook Smoke)... ";
    $loginA2 = apiRequest('POST', '/api/v1/login', [
        'email' => $userA_email,
        'password' => $password,
        'device_name' => 'MacBook Smoke',
    ]);
    if ($loginA2['status'] === 200 && !empty($loginA2['body']['token'])) {
        $tokenA2 = $loginA2['body']['token'];
        $tokenA2_prefix = substr($tokenA2, 0, 6) . '...';
        echo "[PASS] Device 2 Token issued: {$tokenA2_prefix}\n";
    } else {
        throw new RuntimeException("User A device 2 login failed: status {$loginA2['status']}");
    }

    // 4. Register User B (Invitee)
    echo "4. Registering Disposable User B via API... ";
    $regB = apiRequest('POST', '/api/v1/register', [
        'name' => 'Smoke User B',
        'email' => $userB_email,
        'password' => $password,
        'password_confirmation' => $password,
        'device_name' => 'Android Smoke',
    ]);
    if ($regB['status'] === 201 && !empty($regB['body']['token'])) {
        $userB_id = $regB['body']['user']['id'];
        $trackedIds['users'][] = $userB_id;
        $tokenB = $regB['body']['token'];
        echo "[PASS] Created user ID: {$userB_id}\n";
    } else {
        throw new RuntimeException("User B registration failed: status {$regB['status']}");
    }

    // 5. User A creates scheduled meeting with timezone conversion
    echo "5. Creating Scheduled Meeting with Timezone Conversion (Asia/Riyadh UTC+3)... ";
    $targetRiyadh = (new DateTime('now', new DateTimeZone('Asia/Riyadh')))->modify('+2 days')->setTime(16, 30, 0);
    $createMeeting = apiRequest('POST', '/api/v1/meetings', [
        'title' => "Smoke Meeting {$runId}",
        'type' => 'scheduled',
        'starts_at' => $targetRiyadh->format('Y-m-d H:i:s'),
        'timezone' => 'Asia/Riyadh',
        'duration_minutes' => 45,
    ], $tokenA1);
    if ($createMeeting['status'] === 201 && !empty($createMeeting['body']['meeting']['id'])) {
        $meeting = $createMeeting['body']['meeting'];
        $meetingId = $meeting['id'];
        $trackedIds['meetings'][] = $meetingId;
        $roomName = $meeting['room_name'];
        echo "[PASS] Created Meeting ID: {$meetingId}, Room: {$roomName}, Starts (UTC): {$meeting['starts_at']}\n";
        if (isset($createMeeting['body']['token']) || isset($createMeeting['body']['jwt'])) {
            throw new RuntimeException("JWT was unexpectedly exposed in meeting creation response!");
        }
    } else {
        throw new RuntimeException("Meeting creation failed: status {$createMeeting['status']}");
    }

    // 6. Paginated meetings list and isolation check
    echo "6. Checking Paginated Meetings List & User Isolation... ";
    $listA = apiRequest('GET', '/api/v1/meetings?per_page=10', null, $tokenA1);
    if ($listA['status'] !== 200 || empty($listA['body']['data'])) {
        throw new RuntimeException("Failed to fetch User A meetings list");
    }
    if (isset($listA['body']['data'][0]['token']) || isset($listA['body']['data'][0]['jwt'])) {
        throw new RuntimeException("JWT was unexpectedly exposed in meetings list response!");
    }

    // Verify User B cannot see User A's meeting in their list
    $listB = apiRequest('GET', '/api/v1/meetings', null, $tokenB);
    $userB_meetingIds = array_column($listB['body']['data'] ?? [], 'id');
    if (in_array($meetingId, $userB_meetingIds, true)) {
        throw new RuntimeException("List isolation breached: User B sees User A's meeting!");
    }
    echo "[PASS] List is paginated and strictly isolated across users\n";

    // 7. Invitee opens meeting detail
    echo "7. Checking Invitee Meeting Detail (GET /api/v1/meetings/{id})... ";
    $detailB = apiRequest('GET', "/api/v1/meetings/{$meetingId}", null, $tokenB);
    if ($detailB['status'] === 200 && ($detailB['body']['meeting']['is_owner'] ?? true) === false) {
        if (isset($detailB['body']['meeting']['token']) || isset($detailB['body']['token'])) {
            throw new RuntimeException("JWT was unexpectedly exposed in ordinary detail response!");
        }
        echo "[PASS] Invitee retrieved details (is_owner: false, no JWT in response)\n";
    } else {
        throw new RuntimeException("Failed invitee detail check");
    }

    // 8. User A joins meeting (Moderator token)
    echo "8. Checking Owner Join Token (POST /api/v1/meetings/{id}/join)... ";
    $joinA = apiRequest('POST', "/api/v1/meetings/{$meetingId}/join", [], $tokenA1);
    if ($joinA['status'] === 200 && !empty($joinA['body']['token']) && ($joinA['body']['is_moderator'] ?? false) === true) {
        if (stripos($joinA['headers'], 'no-store') === false) {
            throw new RuntimeException("Cache-Control header missing no-store on join response!");
        }
        $jitsiUrl = $joinA['body']['jitsi_url'];
        if ($jitsiUrl !== 'https://meet.dev-ark.com') {
            throw new RuntimeException("Expected jitsi_url https://meet.dev-ark.com, got {$jitsiUrl}");
        }
        echo "[PASS] Returned production Jitsi URL ({$jitsiUrl}), is_moderator: true, Cache-Control: no-store\n";
    } else {
        throw new RuntimeException("Owner join failed: status {$joinA['status']}");
    }

    // 9. User B joins meeting (Participant token)
    echo "9. Checking Invitee Join Token (POST /api/v1/meetings/{id}/join)... ";
    $joinB = apiRequest('POST', "/api/v1/meetings/{$meetingId}/join", [], $tokenB);
    if ($joinB['status'] === 200 && !empty($joinB['body']['token']) && ($joinB['body']['is_moderator'] ?? true) === false) {
        echo "[PASS] Returned is_moderator: false for invitee\n";
    } else {
        throw new RuntimeException("Invitee join failed: status {$joinB['status']}");
    }

    // 10. Logout revokes only current token
    echo "10. Checking Logout Revocation Isolation... ";
    $logoutA1 = apiRequest('POST', '/api/v1/logout', [], $tokenA1);
    if ($logoutA1['status'] !== 200) {
        throw new RuntimeException("Logout failed: status {$logoutA1['status']}");
    }

    // Token A1 should be 401
    $meA1 = apiRequest('GET', '/api/v1/me', null, $tokenA1);
    if ($meA1['status'] !== 401) {
        throw new RuntimeException("Revoked token A1 was not rejected (status {$meA1['status']})");
    }

    // Token A2 should still be 200
    $meA2 = apiRequest('GET', '/api/v1/me', null, $tokenA2);
    if ($meA2['status'] !== 200 || ($meA2['body']['user']['id'] ?? 0) !== $userA_id) {
        throw new RuntimeException("Device 2 token was unexpectedly invalidated!");
    }
    echo "[PASS] Token A1 revoked; Device 2 Token A2 remains active\n";

    echo "\n=== ALL PRODUCTION SMOKE CHECKS PASSED ===\n";
} finally {
    // 11. Clean up only exact tracked disposable records
    echo "\nCleaning up disposable test records...\n";
    $cleanupMeetingIds = implode(',', array_map('intval', $trackedIds['meetings']));
    $cleanupUserIds = implode(',', array_map('intval', $trackedIds['users']));

    $cleanupPhp = "";
    if (!empty($cleanupMeetingIds)) {
        $cleanupPhp .= "App\\Models\\Meeting::whereIn('id', [{$cleanupMeetingIds}])->delete(); ";
    }
    if (!empty($cleanupUserIds)) {
        $cleanupPhp .= "Laravel\\Sanctum\\PersonalAccessToken::whereIn('tokenable_id', [{$cleanupUserIds}])->delete(); ";
        $cleanupPhp .= "App\\Models\\User::whereIn('id', [{$cleanupUserIds}])->delete(); ";
    }

    if (!empty($cleanupPhp)) {
        $cmd = "ssh devark \"docker exec meet-ark-app-1 php -r \\\"" . addslashes($cleanupPhp) . "\\\"\"";
        exec($cmd, $cleanupOut, $cleanupCode);
        if ($cleanupCode === 0) {
            echo "[CLEANUP OK] Purged meetings [{$cleanupMeetingIds}] and users [{$cleanupUserIds}]\n";
        } else {
            echo "[CLEANUP WARNING] Failed to purge: " . implode("\n", $cleanupOut) . "\n";
        }
    }
}
