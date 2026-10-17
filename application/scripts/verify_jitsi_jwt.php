<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\MeetingController;
use App\Models\Meeting;
use App\Models\User;
use App\Services\JitsiTokenService;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

$boshUrl = getenv('BOSH_URL') ?: (getenv('APP_ENV') === 'production' ? 'http://web:80/http-bind' : 'https://web:443/http-bind');
$secret = config('services.jitsi.app_secret', env('JWT_APP_SECRET'));
$appId = config('services.jitsi.app_id', env('JWT_APP_ID', 'meet_ark_backend'));
$aud = config('services.jitsi.audience', env('JWT_ACCEPTED_AUDIENCES', 'meet_ark_platform'));
$xmppDomain = config('services.jitsi.xmpp_domain', 'meet.jitsi');

if (empty($secret)) {
    echo "ERROR: JWT_APP_SECRET is not configured in services.jitsi.app_secret.\n";
    exit(1);
}

function makeBoshRequest(string $url, string $body, array $headers = []): string {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $reqHeaders = ['Content-Type: text/xml'];
    foreach ($headers as $h) {
        $reqHeaders[] = $h;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $reqHeaders);
    $res = curl_exec($ch);
    curl_close($ch);
    return is_string($res) ? $res : '';
}

function makeTestToken(string $signingKey, string $room, bool $isModerator, string $name, $userId, ?int $exp = null, string $iss = 'meet_ark_backend', string $aud = 'meet_ark_platform'): string {
    $now = time();
    $payload = [
        'iss' => $iss,
        'aud' => $aud,
        'sub' => 'meet.jitsi',
        'room' => $room,
        'iat' => $now,
        'nbf' => $now - 10,
        'exp' => $exp ?? ($now + 300),
        'moderator' => $isModerator,
        'context' => [
            'user' => [
                'id' => (string) $userId,
                'name' => $name,
                'email' => strtolower(str_replace(' ', '', $name)) . '@example.com',
                'moderator' => $isModerator,
            ],
        ],
    ];
    return JWT::encode($payload, $signingKey, 'HS256');
}

function establishXmppSession(string $boshUrl, string $token): array {
    $rid = rand(100000, 900000);
    $init = "<body rid='{$rid}' xmlns='http://jabber.org/protocol/httpbind' to='meet.jitsi' xml:lang='en' wait='5' hold='1' window='5' content='text/xml; charset=utf-8' ver='1.6' xmpp:version='1.0' xmlns:xmpp='urn:xmpp:xbosh'/>";
    $resp = makeBoshRequest($boshUrl, $init, ["Authorization: Bearer {$token}"]);
    preg_match("/sid='([^']+)'/", $resp, $m);
    $sid = $m[1] ?? '';

    // Auth
    $rid++;
    $auth = "<body rid='{$rid}' xmlns='http://jabber.org/protocol/httpbind' sid='{$sid}'><auth xmlns='urn:ietf:params:xml:ns:xmpp-sasl' mechanism='ANONYMOUS'/></body>";
    $authResp = makeBoshRequest($boshUrl, $auth, ["Authorization: Bearer {$token}"]);
    if (strpos($authResp, '<success') === false) {
        throw new RuntimeException("Auth failed: $authResp");
    }

    // Restart stream
    $rid++;
    $restart = "<body rid='{$rid}' xmlns='http://jabber.org/protocol/httpbind' sid='{$sid}' to='meet.jitsi' xml:lang='en' xmpp:restart='true' xmlns:xmpp='urn:xmpp:xbosh'/>";
    makeBoshRequest($boshUrl, $restart, ["Authorization: Bearer {$token}"]);

    // Bind resource
    $rid++;
    $bind = "<body rid='{$rid}' xmlns='http://jabber.org/protocol/httpbind' sid='{$sid}'><iq type='set' id='bind_1' xmlns='jabber:client'><bind xmlns='urn:ietf:params:xml:ns:xmpp-bind'/></iq></body>";
    $bindResp = makeBoshRequest($boshUrl, $bind, ["Authorization: Bearer {$token}"]);
    preg_match("/<jid>([^<]+)<\/jid>/", $bindResp, $mjid);
    $fullJid = $mjid[1] ?? '';

    // Session
    $rid++;
    $sess = "<body rid='{$rid}' xmlns='http://jabber.org/protocol/httpbind' sid='{$sid}'><iq type='set' id='sess_1' xmlns='jabber:client'><session xmlns='urn:ietf:params:xml:ns:xmpp-session'/></iq></body>";
    makeBoshRequest($boshUrl, $sess, ["Authorization: Bearer {$token}"]);

    return [
        'sid' => $sid,
        'rid' => $rid,
        'jid' => $fullJid,
        'bareJid' => explode('/', $fullJid)[0],
    ];
}

echo "=== STARTING PROSODY XMPP PROTOCOL CHECKS ===\n\n";

// ---------------------------------------------------------
// CHECK 1: No Token
// ---------------------------------------------------------
echo "1. Checking No Token Rejection...\n";
$body1 = "<body rid='1001' xmlns='http://jabber.org/protocol/httpbind' to='meet.jitsi' xml:lang='en' wait='60' hold='1' window='5' content='text/xml; charset=utf-8' ver='1.6' xmpp:version='1.0' xmlns:xmpp='urn:xmpp:xbosh'/>";
$resp1 = makeBoshRequest($boshUrl, $body1);
preg_match("/sid='([^']+)'/", $resp1, $m1);
$sid1 = $m1[1] ?? '';
$auth1 = "<body rid='1002' xmlns='http://jabber.org/protocol/httpbind' sid='{$sid1}'><auth xmlns='urn:ietf:params:xml:ns:xmpp-sasl' mechanism='ANONYMOUS'/></body>";
$authResp1 = makeBoshRequest($boshUrl, $auth1);
if (strpos($authResp1, 'token required') !== false) {
    echo "   [PASS] Prosody rejected auth without token: 'token required'\n";
} else {
    echo "   [FAIL] Expected 'token required', got: $authResp1\n";
}

// ---------------------------------------------------------
// CHECK 2: Invalid Signature
// ---------------------------------------------------------
echo "\n2. Checking Invalid Signature Rejection...\n";
$badToken = makeTestToken('0000000000000000000000000000000000000000000000000000000000000000', 'room-a', true, 'Bad User', 99);
$body2 = "<body rid='2001' xmlns='http://jabber.org/protocol/httpbind' to='meet.jitsi' xml:lang='en' wait='60' hold='1' window='5' content='text/xml; charset=utf-8' ver='1.6' xmpp:version='1.0' xmlns:xmpp='urn:xmpp:xbosh'/>";
$resp2 = makeBoshRequest($boshUrl, $body2, ["Authorization: Bearer {$badToken}"]);
preg_match("/sid='([^']+)'/", $resp2, $m2);
$sid2 = $m2[1] ?? '';
$auth2 = "<body rid='2002' xmlns='http://jabber.org/protocol/httpbind' sid='{$sid2}'><auth xmlns='urn:ietf:params:xml:ns:xmpp-sasl' mechanism='ANONYMOUS'/></body>";
$authResp2 = makeBoshRequest($boshUrl, $auth2, ["Authorization: Bearer {$badToken}"]);
if (strpos($authResp2, 'Invalid signature') !== false) {
    echo "   [PASS] Prosody rejected auth with invalid signature: 'Invalid signature'\n";
} else {
    echo "   [FAIL] Expected 'Invalid signature', got: $authResp2\n";
}

// ---------------------------------------------------------
// CHECK 3: Expired Token
// ---------------------------------------------------------
echo "\n3. Checking Expired Token Rejection...\n";
$expiredToken = makeTestToken($secret, 'room-a', true, 'Expired User', 98, time() - 60);
$body3 = "<body rid='3001' xmlns='http://jabber.org/protocol/httpbind' to='meet.jitsi' xml:lang='en' wait='60' hold='1' window='5' content='text/xml; charset=utf-8' ver='1.6' xmpp:version='1.0' xmlns:xmpp='urn:xmpp:xbosh'/>";
$resp3 = makeBoshRequest($boshUrl, $body3, ["Authorization: Bearer {$expiredToken}"]);
preg_match("/sid='([^']+)'/", $resp3, $m3);
$sid3 = $m3[1] ?? '';
$auth3 = "<body rid='3002' xmlns='http://jabber.org/protocol/httpbind' sid='{$sid3}'><auth xmlns='urn:ietf:params:xml:ns:xmpp-sasl' mechanism='ANONYMOUS'/></body>";
$authResp3 = makeBoshRequest($boshUrl, $auth3, ["Authorization: Bearer {$expiredToken}"]);
if (strpos($authResp3, 'Not acceptable by exp') !== false) {
    echo "   [PASS] Prosody rejected auth with expired token: 'Not acceptable by exp'\n";
} else {
    echo "   [FAIL] Expected 'Not acceptable by exp', got: $authResp3\n";
}

// ---------------------------------------------------------
// CHECK 4: Token for Room A used in Room B -> Rejected
// ---------------------------------------------------------
echo "\n4. Checking Room Mismatch Rejection (Token for room-a used in room-b)...\n";
$roomAToken = makeTestToken($secret, 'room-a', true, 'Alice Host', 1);
$sessionA = establishXmppSession($boshUrl, $roomAToken);
$sessionA['rid']++;
$joinRoomB = "<body rid='{$sessionA['rid']}' xmlns='http://jabber.org/protocol/httpbind' sid='{$sessionA['sid']}'>
    <presence to='room-b@muc.meet.jitsi/alice-nick' xmlns='jabber:client'>
        <x xmlns='http://jabber.org/protocol/muc'/>
    </presence>
</body>";
$joinRespB = makeBoshRequest($boshUrl, $joinRoomB, ["Authorization: Bearer {$roomAToken}"]);
if (strpos($joinRespB, 'Room and token mismatched') !== false) {
    echo "   [PASS] Prosody MUC rejected room mismatch: 'Room and token mismatched'\n";
} else {
    echo "   [FAIL] Expected 'Room and token mismatched', got: $joinRespB\n";
}

// ---------------------------------------------------------
// CHECK 5: Valid Token for Correct Room -> Accepted
// ---------------------------------------------------------
echo "\n5. Checking Valid Token for Correct Room (Token for room-a in room-a)...\n";
$sessionA['rid']++;
$joinRoomA = "<body rid='{$sessionA['rid']}' xmlns='http://jabber.org/protocol/httpbind' sid='{$sessionA['sid']}'>
    <presence to='room-a@muc.meet.jitsi/alice-nick' xmlns='jabber:client'>
        <x xmlns='http://jabber.org/protocol/muc'/>
    </presence>
</body>";
$joinRespA = makeBoshRequest($boshUrl, $joinRoomA, ["Authorization: Bearer {$roomAToken}"]);
if (strpos($joinRespA, 'Room and token mismatched') === false && strpos($joinRespA, 'error') === false) {
    echo "   [PASS] Prosody accepted join for correct room\n";
} else {
    echo "   [FAIL] Unexpected response: $joinRespA\n";
}

// ---------------------------------------------------------
// CHECK 6: Real Application Path & Host Permissions
// Tokens issued via MeetingController::show and JitsiTokenService
// ---------------------------------------------------------
echo "\n6. Checking Real Application Path & Host Permissions...\n";
echo "   Creating meeting owned by User A...\n";
$userA = User::firstOrCreate(
    ['email' => 'user_a_owner@example.com'],
    ['name' => 'User A (Owner)', 'password' => bcrypt('password123')]
);
$userB = User::firstOrCreate(
    ['email' => 'user_b_invitee@example.com'],
    ['name' => 'User B (Invitee)', 'password' => bcrypt('password123')]
);

$meeting = Meeting::create([
    'owner_id' => $userA->id,
    'title' => 'Real Path Meeting ' . date('Ymd-His'),
    'room_name' => 'meet-path-' . Str::lower(Str::random(10)),
]);

// Step 6a: User B opens invitation via the real route first
$reqB = Request::create(route('meetings.show', $meeting), 'GET');
$reqB->headers->set('X-Inertia', 'true');
$reqB->setUserResolver(fn () => $userB);
$resB = app(MeetingController::class)->show($reqB, $meeting, app(JitsiTokenService::class));
$dataB = $resB->toResponse($reqB)->getData(true);
$inviteeToken = $dataB['props']['token'];

echo "   User B (invitee) connects to Prosody first using route-issued token...\n";
$sessionBob = establishXmppSession($boshUrl, $inviteeToken);
$sessionBob['rid']++;
$bobJoin = "<body rid='{$sessionBob['rid']}' xmlns='http://jabber.org/protocol/httpbind' sid='{$sessionBob['sid']}'>
    <presence to='{$meeting->room_name}@muc.meet.jitsi/user-b-nick' xmlns='jabber:client'>
        <x xmlns='http://jabber.org/protocol/muc'/>
    </presence>
</body>";
makeBoshRequest($boshUrl, $bobJoin, ["Authorization: Bearer {$inviteeToken}"]);

// Step 6b: User B attempts MUC admin query (must fail with forbidden because User B is not moderator)
$sessionBob['rid']++;
$bobAdminIq = "<body rid='{$sessionBob['rid']}' xmlns='http://jabber.org/protocol/httpbind' sid='{$sessionBob['sid']}'>
    <iq to='{$meeting->room_name}@muc.meet.jitsi' type='get' id='q_bob' xmlns='jabber:client'>
        <query xmlns='http://jabber.org/protocol/muc#admin'>
            <item affiliation='owner'/>
        </query>
    </iq>
</body>";
$bobAdminResp = makeBoshRequest($boshUrl, $bobAdminIq, ["Authorization: Bearer {$inviteeToken}"]);
if (strpos($bobAdminResp, 'forbidden') !== false) {
    echo "   [PASS] User B (invitee joining first) was denied admin/owner query (forbidden)\n";
} else {
    echo "   [FAIL] Expected forbidden for invitee admin query, got: $bobAdminResp\n";
}

// Step 6c: User A (Owner) joins second via the real route
$reqA = Request::create(route('meetings.show', $meeting), 'GET');
$reqA->headers->set('X-Inertia', 'true');
$reqA->setUserResolver(fn () => $userA);
$resA = app(MeetingController::class)->show($reqA, $meeting, app(JitsiTokenService::class));
$dataA = $resA->toResponse($reqA)->getData(true);
$ownerToken = $dataA['props']['token'];

echo "   User A (owner) joins room second using route-issued token...\n";
$sessionAlice = establishXmppSession($boshUrl, $ownerToken);
$sessionAlice['rid']++;
$aliceJoin = "<body rid='{$sessionAlice['rid']}' xmlns='http://jabber.org/protocol/httpbind' sid='{$sessionAlice['sid']}'>
    <presence to='{$meeting->room_name}@muc.meet.jitsi/user-a-nick' xmlns='jabber:client'>
        <x xmlns='http://jabber.org/protocol/muc'/>
    </presence>
</body>";
makeBoshRequest($boshUrl, $aliceJoin, ["Authorization: Bearer {$ownerToken}"]);

// Step 6d: User A queries owners list via XEP-0045 muc#admin
$sessionAlice['rid']++;
$aliceAdminIq = "<body rid='{$sessionAlice['rid']}' xmlns='http://jabber.org/protocol/httpbind' sid='{$sessionAlice['sid']}'>
    <iq to='{$meeting->room_name}@muc.meet.jitsi' type='get' id='q_alice_owners' xmlns='jabber:client'>
        <query xmlns='http://jabber.org/protocol/muc#admin'>
            <item affiliation='owner'/>
        </query>
    </iq>
</body>";
$aliceOwnersResp = makeBoshRequest($boshUrl, $aliceAdminIq, ["Authorization: Bearer {$ownerToken}"]);

if (strpos($aliceOwnersResp, "affiliation='owner'") !== false && strpos($aliceOwnersResp, $sessionAlice['bareJid']) !== false) {
    echo "   [PASS] User A (owner joining second) granted moderator/owner privileges in room ({$sessionAlice['bareJid']})\n";
} else {
    echo "   [FAIL] Owner was not returned in owners list. Response: $aliceOwnersResp\n";
}

// ---------------------------------------------------------
// CHECK 7: Client-side Claim Tampering
// ---------------------------------------------------------
echo "\n7. Checking Client-Side Claim Tampering Rejection...\n";
$tamperedToken = makeTestToken('attacker_secret_key_that_is_invalid_000000000000000000000000', $meeting->room_name, true, 'Bob Attacker', 2);
$tamperedRid = rand(100000, 900000);
$tamperInit = "<body rid='{$tamperedRid}' xmlns='http://jabber.org/protocol/httpbind' to='meet.jitsi' xml:lang='en' wait='5' hold='1' window='5' content='text/xml; charset=utf-8' ver='1.6' xmpp:version='1.0' xmlns:xmpp='urn:xmpp:xbosh'/>";
$tamperResp = makeBoshRequest($boshUrl, $tamperInit, ["Authorization: Bearer {$tamperedToken}"]);
preg_match("/sid='([^']+)'/", $tamperResp, $tamperM);
$tamperSid = $tamperM[1] ?? '';
$tamperedRid++;
$tamperAuth = "<body rid='{$tamperedRid}' xmlns='http://jabber.org/protocol/httpbind' sid='{$tamperSid}'><auth xmlns='urn:ietf:params:xml:ns:xmpp-sasl' mechanism='ANONYMOUS'/></body>";
$tamperAuthResp = makeBoshRequest($boshUrl, $tamperAuth, ["Authorization: Bearer {$tamperedToken}"]);
if (strpos($tamperAuthResp, 'Invalid signature') !== false) {
    echo "   [PASS] Client-side claim tampering is rejected: 'Invalid signature'\n";
} else {
    echo "   [FAIL] Tampered token was not rejected with 'Invalid signature'. Got: $tamperAuthResp\n";
}

// ---------------------------------------------------------
// CHECK 8: Rotated (Old) Secret Rejection Check
// ---------------------------------------------------------
$oldSecret = $argv[1] ?? getenv('OLD_JWT_SECRET') ?? null;
if (!empty($oldSecret)) {
    echo "\n8. Checking Old Rotated Secret Rejection...\n";
    $oldSecretToken = makeTestToken($oldSecret, $meeting->room_name, true, 'User Old Secret', 1);
    $oldRid = rand(100000, 900000);
    $oldInit = "<body rid='{$oldRid}' xmlns='http://jabber.org/protocol/httpbind' to='meet.jitsi' xml:lang='en' wait='5' hold='1' window='5' content='text/xml; charset=utf-8' ver='1.6' xmpp:version='1.0' xmlns:xmpp='urn:xmpp:xbosh'/>";
    $oldResp = makeBoshRequest($boshUrl, $oldInit, ["Authorization: Bearer {$oldSecretToken}"]);
    preg_match("/sid='([^']+)'/", $oldResp, $oldM);
    $oldSid = $oldM[1] ?? '';
    $oldRid++;
    $oldAuth = "<body rid='{$oldRid}' xmlns='http://jabber.org/protocol/httpbind' sid='{$oldSid}'><auth xmlns='urn:ietf:params:xml:ns:xmpp-sasl' mechanism='ANONYMOUS'/></body>";
    $oldAuthResp = makeBoshRequest($boshUrl, $oldAuth, ["Authorization: Bearer {$oldSecretToken}"]);
    if (strpos($oldAuthResp, 'Invalid signature') !== false) {
        echo "   [PASS] Prosody rejected auth with old rotated secret: 'Invalid signature'\n";
    } else {
        echo "   [FAIL] Old secret was not rejected with 'Invalid signature'. Got: $oldAuthResp\n";
    }
}

echo "\n=== ALL PROTOCOL CHECKS COMPLETED ===\n";
