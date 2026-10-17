<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\User;
use App\Services\JitsiTokenService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JitsiTokenTest extends TestCase
{
    use RefreshDatabase;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = config('services.jitsi.app_secret') ?: 'test_jwt_secret_for_phpunit_isolated_testing_key_123';
        config([
            'services.jitsi.app_id' => 'meet_ark_backend',
            'services.jitsi.app_secret' => $this->secret,
            'services.jitsi.audience' => 'meet_ark_platform',
            'services.jitsi.issuer' => 'meet_ark_backend',
            'services.jitsi.xmpp_domain' => 'meet.jitsi',
        ]);
    }

    public function test_meeting_owner_receives_moderator_token(): void
    {
        $owner = User::factory()->create(['name' => 'Alice Host']);
        $meeting = Meeting::create([
            'owner_id' => $owner->id,
            'title' => 'Host Strategy Room',
            'room_name' => 'meet-host-room-1',
        ]);

        $service = app(JitsiTokenService::class);
        $token = $service->generateToken($owner, $meeting);

        $this->assertNotEmpty($token);

        $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));

        $this->assertEquals('meet_ark_backend', $decoded->iss);
        $this->assertEquals('meet_ark_platform', $decoded->aud);
        $this->assertEquals('meet.jitsi', $decoded->sub);
        $this->assertEquals('meet-host-room-1', $decoded->room);
        $this->assertTrue($decoded->moderator);
        $this->assertTrue($decoded->context->user->moderator);
        $this->assertEquals((string) $owner->id, $decoded->context->user->id);
        $this->assertEquals('Alice Host', $decoded->context->user->name);
        $this->assertGreaterThan(time(), $decoded->exp);
        $this->assertLessThanOrEqual(time() + 300, $decoded->exp);
    }

    public function test_invitee_receives_participant_token_without_moderator_privileges(): void
    {
        $owner = User::factory()->create();
        $invitee = User::factory()->create(['name' => 'Bob Participant']);
        $meeting = Meeting::create([
            'owner_id' => $owner->id,
            'title' => 'Public Discussion',
            'room_name' => 'meet-invitee-room-2',
        ]);

        $service = app(JitsiTokenService::class);
        $token = $service->generateToken($invitee, $meeting);

        $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));

        $this->assertEquals('meet-invitee-room-2', $decoded->room);
        $this->assertFalse($decoded->moderator);
        $this->assertFalse($decoded->context->user->moderator);
        $this->assertEquals((string) $invitee->id, $decoded->context->user->id);
    }

    public function test_meeting_show_route_provides_scoped_token_to_authenticated_owner(): void
    {
        $owner = User::factory()->create();
        $meeting = Meeting::create([
            'owner_id' => $owner->id,
            'title' => 'Planning Session',
            'room_name' => 'meet-planning-123',
        ]);

        $response = $this->actingAs($owner)->get(route('meetings.show', $meeting));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Meetings/Show')
            ->has('token')
            ->where('meeting.id', $meeting->id)
            ->where('meeting.is_owner', true)
        );
    }

    public function test_meeting_show_route_provides_scoped_token_to_authenticated_invitee(): void
    {
        $owner = User::factory()->create();
        $invitee = User::factory()->create();
        $meeting = Meeting::create([
            'owner_id' => $owner->id,
            'title' => 'Planning Session',
            'room_name' => 'meet-planning-456',
        ]);

        $response = $this->actingAs($invitee)->get(route('meetings.show', $meeting));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Meetings/Show')
            ->has('token')
            ->where('meeting.id', $meeting->id)
            ->where('meeting.is_owner', false)
        );
    }

    public function test_unauthenticated_user_cannot_access_meeting_or_obtain_token(): void
    {
        $owner = User::factory()->create();
        $meeting = Meeting::create([
            'owner_id' => $owner->id,
            'title' => 'Confidential Review',
            'room_name' => 'meet-confidential-789',
        ]);

        $response = $this->get(route('meetings.show', $meeting));

        $response->assertRedirect(route('login'));
    }

    public function test_deleting_meeting_record_prevents_future_token_issuance(): void
    {
        $owner = User::factory()->create();
        $meeting = Meeting::create([
            'owner_id' => $owner->id,
            'title' => 'Temporary Standup',
            'room_name' => 'meet-temp-999',
        ]);

        $meetingId = $meeting->id;

        // Owner deletes the meeting
        $response = $this->actingAs($owner)->delete(route('meetings.destroy', $meeting));
        $response->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('meetings', ['id' => $meetingId]);

        // Attempting to access the deleted meeting gives 404
        $accessResponse = $this->actingAs($owner)->get('/meetings/' . $meetingId);
        $accessResponse->assertNotFound();
    }

    public function test_token_signature_fails_when_tampered(): void
    {
        $owner = User::factory()->create();
        $invitee = User::factory()->create();
        $meeting = Meeting::create([
            'owner_id' => $owner->id,
            'title' => 'Security Test',
            'room_name' => 'meet-sec-test',
        ]);

        $service = app(JitsiTokenService::class);
        $token = $service->generateToken($invitee, $meeting);

        // Tamper with payload: alter moderator claim
        $parts = explode('.', $token);
        $payload = json_decode(base64_decode($parts[1]), true);
        $payload['moderator'] = true;
        $payload['context']['user']['moderator'] = true;
        $tamperedPayload = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $tamperedToken = $parts[0] . '.' . $tamperedPayload . '.' . $parts[2];

        $this->expectException(\Firebase\JWT\SignatureInvalidException::class);
        JWT::decode($tamperedToken, new Key($this->secret, 'HS256'));
    }
}
