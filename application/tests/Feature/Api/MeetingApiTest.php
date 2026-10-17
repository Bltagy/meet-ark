<?php

namespace Tests\Feature\Api;

use App\Models\Meeting;
use App\Models\User;
use Carbon\Carbon;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function createAuthenticatedUser(): array
    {
        $user = User::factory()->create();
        $token = $user->createToken('TestDevice')->plainTextToken;

        return [$user, $token];
    }

    protected function withApiToken(string $token): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_unauthenticated_api_requests_return_json_401(): void
    {
        $user = User::factory()->create();
        $meeting = Meeting::create([
            'owner_id' => $user->id,
            'title' => 'Test Meeting',
            'room_name' => 'meet-test123456',
        ]);

        // 1. Meeting index
        $resIndex = $this->get('/api/v1/meetings');
        $resIndex->assertStatus(401)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJson(['message' => 'Unauthenticated.']);

        // 2. Meeting create
        $resStore = $this->post('/api/v1/meetings', ['title' => 'Test']);
        $resStore->assertStatus(401)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJson(['message' => 'Unauthenticated.']);

        // 3. Meeting detail
        $resShow = $this->get("/api/v1/meetings/{$meeting->id}");
        $resShow->assertStatus(401)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJson(['message' => 'Unauthenticated.']);

        // 4. Meeting update
        $resUpdate = $this->patch("/api/v1/meetings/{$meeting->id}", ['title' => 'New']);
        $resUpdate->assertStatus(401)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJson(['message' => 'Unauthenticated.']);

        // 5. Meeting delete
        $resDelete = $this->delete("/api/v1/meetings/{$meeting->id}");
        $resDelete->assertStatus(401)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJson(['message' => 'Unauthenticated.']);

        // 6. Meeting join
        $resJoin = $this->post("/api/v1/meetings/{$meeting->id}/join");
        $resJoin->assertStatus(401)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_authenticated_user_can_list_their_meetings_paginated_with_isolation(): void
    {
        [$userA, $tokenA] = $this->createAuthenticatedUser();
        [$userB, $tokenB] = $this->createAuthenticatedUser();

        // Create 3 meetings for User A
        for ($i = 1; $i <= 3; $i++) {
            Meeting::create([
                'owner_id' => $userA->id,
                'title' => "User A Meeting {$i}",
                'room_name' => "meet-a-{$i}",
            ]);
        }

        // Create 2 meetings for User B
        for ($i = 1; $i <= 2; $i++) {
            Meeting::create([
                'owner_id' => $userB->id,
                'title' => "User B Meeting {$i}",
                'room_name' => "meet-b-{$i}",
            ]);
        }

        $response = $this->withApiToken($tokenA)
            ->getJson('/api/v1/meetings?per_page=2');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'room_name',
                        'starts_at',
                        'duration_minutes',
                        'is_scheduled',
                        'is_owner',
                        'created_at',
                        'updated_at',
                        'invitation_url',
                    ],
                ],
                'links',
                'meta' => ['current_page', 'total', 'per_page'],
            ]);

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));

        // Assert strictly isolated: no User B meeting returned
        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('User A Meeting 3'));
        $this->assertFalse($titles->contains('User B Meeting 1'));

        // Assert NO JWTs in list response
        $this->assertArrayNotHasKey('token', $response->json('data.0'));
        $this->assertArrayNotHasKey('jwt', $response->json('data.0'));
    }

    public function test_authenticated_user_can_create_instant_meeting(): void
    {
        [$user, $token] = $this->createAuthenticatedUser();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/meetings', [
                'title' => 'Quick Huddle',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'meeting' => [
                    'id',
                    'title',
                    'room_name',
                    'starts_at',
                    'duration_minutes',
                    'is_scheduled',
                    'is_owner',
                    'created_at',
                    'updated_at',
                    'invitation_url',
                ],
            ]);

        $this->assertSame('Quick Huddle', $response->json('meeting.title'));
        $this->assertNull($response->json('meeting.starts_at'));
        $this->assertNull($response->json('meeting.duration_minutes'));
        $this->assertFalse($response->json('meeting.is_scheduled'));
        $this->assertTrue($response->json('meeting.is_owner'));

        // Cryptographically generated room name starts with meet-
        $roomName = $response->json('meeting.room_name');
        $this->assertStringStartsWith('meet-', $roomName);
        $this->assertSame(17, strlen($roomName)); // meet- + 12 chars

        // Ordinary response does not have JWT
        $this->assertArrayNotHasKey('token', $response->json());
        $this->assertArrayNotHasKey('jwt', $response->json());
    }

    public function test_authenticated_user_can_create_scheduled_meeting_with_timezone_utc_conversion(): void
    {
        [$user, $token] = $this->createAuthenticatedUser();

        // 2 days in future at 15:00 in Asia/Riyadh (UTC+3)
        $riyadhTarget = Carbon::now('Asia/Riyadh')->addDays(2)->setTime(15, 0, 0);
        $expectedUtc = $riyadhTarget->copy()->utc();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/meetings', [
                'title' => 'Sprint Planning',
                'type' => 'scheduled',
                'starts_at' => $riyadhTarget->format('Y-m-d H:i:s'),
                'timezone' => 'Asia/Riyadh',
                'duration_minutes' => 90,
            ]);

        $response->assertStatus(201);
        $meeting = Meeting::latest()->first();
        $this->assertNotNull($meeting);

        $this->assertSame(
            $expectedUtc->format('Y-m-d H:i:00'),
            $meeting->starts_at->format('Y-m-d H:i:s')
        );
        $this->assertSame(90, $meeting->duration_minutes);
        $this->assertTrue($response->json('meeting.is_scheduled'));
        $this->assertSame(
            $meeting->starts_at->toIso8601String(),
            $response->json('meeting.starts_at')
        );
    }

    public function test_scheduled_meeting_creation_rejects_past_time_and_invalid_data(): void
    {
        [$user, $token] = $this->createAuthenticatedUser();

        // 1. Past start time
        $pastResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/meetings', [
                'title' => 'Past Meeting',
                'type' => 'scheduled',
                'starts_at' => Carbon::now()->subMinutes(15)->toIso8601String(),
                'duration_minutes' => 60,
            ]);
        $pastResponse->assertStatus(422)
            ->assertJsonValidationErrors(['starts_at']);

        // 2. Missing starts_at when scheduled
        $missingStartsResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/meetings', [
                'title' => 'Scheduled without date',
                'type' => 'scheduled',
            ]);
        $missingStartsResponse->assertStatus(422)
            ->assertJsonValidationErrors(['starts_at']);

        // 3. Invalid duration
        $invalidDurationResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/meetings', [
                'title' => 'Invalid duration',
                'type' => 'scheduled',
                'starts_at' => Carbon::now()->addDays(1)->toIso8601String(),
                'duration_minutes' => 0,
            ]);
        $invalidDurationResponse->assertStatus(422)
            ->assertJsonValidationErrors(['duration_minutes']);

        // 4. Invalid timezone
        $invalidTzResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/meetings', [
                'title' => 'Invalid tz',
                'starts_at' => Carbon::now()->addDays(1)->format('Y-m-d H:i:s'),
                'timezone' => 'Invalid/Timezone_Name',
            ]);
        $invalidTzResponse->assertStatus(422)
            ->assertJsonValidationErrors(['timezone']);
    }

    public function test_any_authenticated_user_with_invitation_link_can_view_meeting_details(): void
    {
        [$owner, $ownerToken] = $this->createAuthenticatedUser();
        [$invitee, $inviteeToken] = $this->createAuthenticatedUser();

        $meeting = Meeting::create([
            'owner_id' => $owner->id,
            'title' => 'Open Architectural Discussion',
            'room_name' => Meeting::generateRoomName(),
            'starts_at' => Carbon::now()->addDay()->utc(),
            'duration_minutes' => 45,
        ]);

        // Owner views details
        $ownerResponse = $this->withApiToken($ownerToken)
            ->getJson("/api/v1/meetings/{$meeting->id}");
        $ownerResponse->assertStatus(200)
            ->assertJsonPath('meeting.title', 'Open Architectural Discussion')
            ->assertJsonPath('meeting.is_owner', true);
        $this->assertArrayNotHasKey('token', $ownerResponse->json());

        // Invitee views details
        $inviteeResponse = $this->withApiToken($inviteeToken)
            ->getJson("/api/v1/meetings/{$meeting->id}");
        $inviteeResponse->assertStatus(200)
            ->assertJsonPath('meeting.title', 'Open Architectural Discussion')
            ->assertJsonPath('meeting.is_owner', false);
        $this->assertArrayNotHasKey('token', $inviteeResponse->json());
    }

    public function test_meeting_owner_can_update_meeting_and_preserves_room_name(): void
    {
        [$owner, $ownerToken] = $this->createAuthenticatedUser();

        $meeting = Meeting::create([
            'owner_id' => $owner->id,
            'title' => 'Initial Title',
            'room_name' => 'meet-fixedroom123',
            'starts_at' => Carbon::now()->addDays(2)->utc(),
            'duration_minutes' => 60,
        ]);

        $newTime = Carbon::now()->addDays(3)->utc();

        $response = $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->patchJson("/api/v1/meetings/{$meeting->id}", [
                'title' => 'Updated Title',
                'starts_at' => $newTime->toIso8601String(),
                'duration_minutes' => 75,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('meeting.title', 'Updated Title')
            ->assertJsonPath('meeting.room_name', 'meet-fixedroom123')
            ->assertJsonPath('meeting.duration_minutes', 75);

        $fresh = $meeting->fresh();
        $this->assertSame('Updated Title', $fresh->title);
        $this->assertSame('meet-fixedroom123', $fresh->room_name);
        $this->assertSame($owner->id, $fresh->owner_id);
    }

    public function test_non_owner_cannot_update_meeting(): void
    {
        [$owner, $ownerToken] = $this->createAuthenticatedUser();
        [$attacker, $attackerToken] = $this->createAuthenticatedUser();

        $meeting = Meeting::create([
            'owner_id' => $owner->id,
            'title' => 'Owner Safe Meeting',
            'room_name' => 'meet-safe123456',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$attackerToken}")
            ->patchJson("/api/v1/meetings/{$meeting->id}", [
                'title' => 'Hijacked Title',
            ]);

        $response->assertStatus(403);
        $this->assertSame('Owner Safe Meeting', $meeting->fresh()->title);
    }

    public function test_meeting_owner_can_delete_meeting(): void
    {
        [$owner, $ownerToken] = $this->createAuthenticatedUser();

        $meeting = Meeting::create([
            'owner_id' => $owner->id,
            'title' => 'To Be Deleted',
            'room_name' => 'meet-delete12345',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->deleteJson("/api/v1/meetings/{$meeting->id}");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Meeting deleted successfully.']);

        $this->assertDatabaseMissing('meetings', ['id' => $meeting->id]);
    }

    public function test_non_owner_cannot_delete_meeting(): void
    {
        [$owner, $ownerToken] = $this->createAuthenticatedUser();
        [$attacker, $attackerToken] = $this->createAuthenticatedUser();

        $meeting = Meeting::create([
            'owner_id' => $owner->id,
            'title' => 'Owner Protected Meeting',
            'room_name' => 'meet-nodelete123',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$attackerToken}")
            ->deleteJson("/api/v1/meetings/{$meeting->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('meetings', ['id' => $meeting->id]);
    }

    public function test_deleted_meeting_cannot_issue_new_join_tokens(): void
    {
        [$owner, $ownerToken] = $this->createAuthenticatedUser();

        $meeting = Meeting::create([
            'owner_id' => $owner->id,
            'title' => 'Ephemeral Meeting',
            'room_name' => 'meet-ephemeral12',
        ]);

        $meetingId = $meeting->id;
        $meeting->delete();

        $response = $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->postJson("/api/v1/meetings/{$meetingId}/join");

        $response->assertStatus(404);
    }

    public function test_join_endpoint_issues_correct_room_bound_tokens_with_no_store(): void
    {
        [$owner, $ownerToken] = $this->createAuthenticatedUser();
        [$invitee, $inviteeToken] = $this->createAuthenticatedUser();

        $meeting = Meeting::create([
            'owner_id' => $owner->id,
            'title' => 'Security Architecture Review',
            'room_name' => 'meet-secarch12345',
        ]);

        $secret = config('services.jitsi.app_secret', env('JWT_APP_SECRET'));

        // 1. Owner joins
        $ownerJoin = $this->withApiToken($ownerToken)
            ->postJson("/api/v1/meetings/{$meeting->id}/join");

        $ownerJoin->assertStatus(200)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure([
                'jitsi_url',
                'room_name',
                'token',
                'expires_at',
                'is_moderator',
            ]);

        $this->assertSame('meet-secarch12345', $ownerJoin->json('room_name'));
        $this->assertTrue($ownerJoin->json('is_moderator'));

        $ownerJwt = $ownerJoin->json('token');
        $decodedOwner = JWT::decode($ownerJwt, new Key($secret, 'HS256'));
        $this->assertSame('meet-secarch12345', $decodedOwner->room);
        $this->assertSame('meet.jitsi', $decodedOwner->sub);
        $this->assertTrue($decodedOwner->moderator);
        $this->assertTrue($decodedOwner->context->user->moderator);
        $this->assertSame((string) $owner->id, $decodedOwner->context->user->id);

        // 2. Invitee joins
        $inviteeJoin = $this->withApiToken($inviteeToken)
            ->postJson("/api/v1/meetings/{$meeting->id}/join");

        $inviteeJoin->assertStatus(200)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('is_moderator', false);

        $inviteeJwt = $inviteeJoin->json('token');
        $decodedInvitee = JWT::decode($inviteeJwt, new Key($secret, 'HS256'));
        $this->assertSame('meet-secarch12345', $decodedInvitee->room);
        $this->assertFalse($decodedInvitee->moderator);
        $this->assertFalse($decodedInvitee->context->user->moderator);
        $this->assertSame((string) $invitee->id, $decodedInvitee->context->user->id);
    }

    public function test_meeting_api_accept_language_localizes_messages(): void
    {
        [$owner, $ownerToken] = $this->createAuthenticatedUser();
        [$attacker, $attackerToken] = $this->createAuthenticatedUser();

        $meeting = Meeting::create([
            'owner_id' => $owner->id,
            'title' => 'Secret Sync',
            'room_name' => 'meet-secretsync1',
        ]);

        // Arabic Accept-Language on forbidden action
        $arResponse = $this->withHeader('Authorization', "Bearer {$attackerToken}")
            ->withHeader('Accept-Language', 'ar')
            ->patchJson("/api/v1/meetings/{$meeting->id}", ['title' => 'Hacked']);

        $arResponse->assertStatus(403);
        $this->assertSame(
            'مالك الاجتماع فقط يمكنه تعديل هذا الاجتماع.',
            $arResponse->json('message')
        );

        // English Accept-Language on forbidden action
        $this->flushHeaders();
        $enResponse = $this->withHeader('Authorization', "Bearer {$attackerToken}")
            ->withHeader('Accept-Language', 'en')
            ->patchJson("/api/v1/meetings/{$meeting->id}", ['title' => 'Hacked']);

        $enResponse->assertStatus(403);
        $this->assertSame(
            'Only the meeting owner can edit this meeting.',
            $enResponse->json('message')
        );
    }
}
