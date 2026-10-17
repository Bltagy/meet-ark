<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MeetingSchedulingTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_instant_meetings_remain_usable_with_null_schedule(): void
    {
        $user = User::factory()->create();

        // Create legacy instant meeting without starts_at or duration
        $meeting = Meeting::create([
            'owner_id' => $user->id,
            'title' => 'Legacy Instant Meeting',
            'room_name' => 'meet-legacy12345',
            'starts_at' => null,
            'duration_minutes' => null,
        ]);

        $response = $this->actingAs($user)->get("/meetings/{$meeting->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Meetings/Show')
            ->where('meeting.title', 'Legacy Instant Meeting')
            ->where('meeting.is_scheduled', false)
            ->where('meeting.starts_at', null)
            ->where('meeting.duration_minutes', null)
            ->has('token')
        );
    }

    public function test_authenticated_user_can_schedule_future_meeting(): void
    {
        $user = User::factory()->create();
        $futureTime = Carbon::now()->addDays(2)->setHour(14)->setMinute(0)->setSecond(0);

        $response = $this->actingAs($user)->post('/meetings', [
            'type' => 'scheduled',
            'title' => 'Sprint Planning Q4',
            'starts_at' => $futureTime->toDateTimeString(),
            'timezone' => 'UTC',
            'duration_minutes' => 90,
        ]);

        $response->assertRedirect('/dashboard');

        $meeting = Meeting::where('title', 'Sprint Planning Q4')->first();
        $this->assertNotNull($meeting);
        $this->assertEquals($user->id, $meeting->owner_id);
        $this->assertEquals(90, $meeting->duration_minutes);
        $this->assertEquals($futureTime->toDateTimeString(), $meeting->starts_at->toDateTimeString());
        $this->assertStringStartsWith('meet-', $meeting->room_name);
    }

    public function test_scheduled_meeting_creation_rejects_past_time(): void
    {
        $user = User::factory()->create();
        $pastTime = Carbon::now()->subHours(2);

        $response = $this->actingAs($user)->post('/meetings', [
            'type' => 'scheduled',
            'title' => 'Past Meeting',
            'starts_at' => $pastTime->toDateTimeString(),
            'timezone' => 'UTC',
            'duration_minutes' => 60,
        ]);

        $response->assertSessionHasErrors('starts_at');
        $this->assertDatabaseMissing('meetings', ['title' => 'Past Meeting']);
    }

    public function test_scheduled_meeting_creation_rejects_invalid_duration(): void
    {
        $user = User::factory()->create();
        $futureTime = Carbon::now()->addDay();

        // Test non-positive duration (0)
        $responseZero = $this->actingAs($user)->post('/meetings', [
            'type' => 'scheduled',
            'title' => 'Zero Duration Meeting',
            'starts_at' => $futureTime->toDateTimeString(),
            'duration_minutes' => 0,
        ]);
        $responseZero->assertSessionHasErrors('duration_minutes');

        // Test negative duration (-15)
        $responseNeg = $this->actingAs($user)->post('/meetings', [
            'type' => 'scheduled',
            'title' => 'Negative Duration Meeting',
            'starts_at' => $futureTime->toDateTimeString(),
            'duration_minutes' => -15,
        ]);
        $responseNeg->assertSessionHasErrors('duration_minutes');
    }

    public function test_utc_conversion_using_non_utc_timezones(): void
    {
        $user = User::factory()->create();

        // 1. New York in December (EST = UTC-5)
        // 10:00 AM EST is 15:00 UTC
        $nyLocal = '2026-12-15 10:00:00';
        $this->actingAs($user)->post('/meetings', [
            'type' => 'scheduled',
            'title' => 'NY Morning Sync',
            'starts_at' => $nyLocal,
            'timezone' => 'America/New_York',
            'duration_minutes' => 45,
        ])->assertRedirect('/dashboard');

        $nyMeeting = Meeting::where('title', 'NY Morning Sync')->first();
        $this->assertNotNull($nyMeeting);
        $this->assertEquals('2026-12-15 15:00:00', $nyMeeting->starts_at->toDateTimeString());

        // 2. Tokyo (JST = UTC+9)
        // 10:00 AM JST is 01:00 UTC
        $tokyoLocal = '2026-12-15 10:00:00';
        $this->actingAs($user)->post('/meetings', [
            'type' => 'scheduled',
            'title' => 'Tokyo Morning Sync',
            'starts_at' => $tokyoLocal,
            'timezone' => 'Asia/Tokyo',
            'duration_minutes' => 60,
        ])->assertRedirect('/dashboard');

        $tokyoMeeting = Meeting::where('title', 'Tokyo Morning Sync')->first();
        $this->assertNotNull($tokyoMeeting);
        $this->assertEquals('2026-12-15 01:00:00', $tokyoMeeting->starts_at->toDateTimeString());
    }

    public function test_owner_can_edit_meeting_and_preserves_room_name(): void
    {
        $owner = User::factory()->create();
        $meeting = Meeting::create([
            'owner_id' => $owner->id,
            'title' => 'Original Title',
            'room_name' => 'meet-fixedroom123',
            'starts_at' => Carbon::now()->addDays(1),
            'duration_minutes' => 30,
        ]);

        $originalRoomName = $meeting->room_name;
        $newFutureTime = Carbon::now()->addDays(5)->setHour(16)->setMinute(0)->setSecond(0);

        $response = $this->actingAs($owner)->patch("/meetings/{$meeting->id}", [
            'title' => 'Updated Title',
            'starts_at' => $newFutureTime->toDateTimeString(),
            'timezone' => 'UTC',
            'duration_minutes' => 75,
        ]);

        $response->assertRedirect('/dashboard');

        $meeting->refresh();
        $this->assertEquals('Updated Title', $meeting->title);
        $this->assertEquals($originalRoomName, $meeting->room_name); // Room name preserved!
        $this->assertEquals(75, $meeting->duration_minutes);
        $this->assertEquals($newFutureTime->toDateTimeString(), $meeting->starts_at->toDateTimeString());
    }

    public function test_non_owner_cannot_edit_meeting(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $meeting = Meeting::create([
            'owner_id' => $owner->id,
            'title' => 'Original Title',
            'room_name' => 'meet-owneronly12',
            'starts_at' => Carbon::now()->addDay(),
            'duration_minutes' => 60,
        ]);

        $response = $this->actingAs($stranger)->patch("/meetings/{$meeting->id}", [
            'title' => 'Hacked Title',
        ]);

        $response->assertStatus(403);
        $meeting->refresh();
        $this->assertEquals('Original Title', $meeting->title);
    }

    public function test_dashboard_separates_upcoming_and_recent_meetings_and_enforces_isolation(): void
    {
        $userA = User::factory()->create(['name' => 'User A']);
        $userB = User::factory()->create(['name' => 'User B']);

        // User A's upcoming meeting 1 (further in future)
        Meeting::create([
            'owner_id' => $userA->id,
            'title' => 'User A Later Meeting',
            'room_name' => 'meet-later123',
            'starts_at' => Carbon::now()->addDays(3),
            'duration_minutes' => 60,
        ]);

        // User A's upcoming meeting 2 (sooner in future)
        Meeting::create([
            'owner_id' => $userA->id,
            'title' => 'User A Sooner Meeting',
            'room_name' => 'meet-sooner123',
            'starts_at' => Carbon::now()->addDays(1),
            'duration_minutes' => 45,
        ]);

        // User A's past scheduled meeting
        Meeting::create([
            'owner_id' => $userA->id,
            'title' => 'User A Past Meeting',
            'room_name' => 'meet-past12345',
            'starts_at' => Carbon::now()->subDays(2),
            'duration_minutes' => 30,
        ]);

        // User A's instant meeting
        Meeting::create([
            'owner_id' => $userA->id,
            'title' => 'User A Instant Meeting',
            'room_name' => 'meet-instant12',
            'starts_at' => null,
            'duration_minutes' => null,
        ]);

        // User B's meeting (should NOT show up for User A)
        Meeting::create([
            'owner_id' => $userB->id,
            'title' => 'User B Private Meeting',
            'room_name' => 'meet-userbsecr',
            'starts_at' => Carbon::now()->addDays(1),
            'duration_minutes' => 60,
        ]);

        $response = $this->actingAs($userA)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('upcomingMeetings', 2)
            // Verify upcoming meetings are ordered by start time ascending
            ->where('upcomingMeetings.0.title', 'User A Sooner Meeting')
            ->where('upcomingMeetings.1.title', 'User A Later Meeting')
            // Verify instant and past meetings are in separate recent list
            ->has('recentMeetings', 2)
            // Verify User B's meetings are nowhere in User A's dashboard
            ->where('upcomingMeetings', fn ($list) => collect($list)->pluck('title')->doesntContain('User B Private Meeting'))
            ->where('recentMeetings', fn ($list) => collect($list)->pluck('title')->doesntContain('User B Private Meeting'))
        );
    }

    public function test_invitation_destination_survives_unauthenticated_login_flow(): void
    {
        $owner = User::factory()->create();
        $meeting = Meeting::create([
            'owner_id' => $owner->id,
            'title' => 'Design Review',
            'room_name' => 'meet-design123',
        ]);

        $attendee = User::factory()->create([
            'email' => 'attendee_login@example.com',
            'password' => bcrypt('password123'),
        ]);

        // 1. Unauthenticated visitor opens invitation link
        $meetingUrl = "/meetings/{$meeting->id}";
        $this->get($meetingUrl)->assertRedirect('/login');

        // 2. Attendee logs in
        $loginResponse = $this->post('/login', [
            'email' => 'attendee_login@example.com',
            'password' => 'password123',
        ]);

        // 3. Authenticated session redirects straight to the intended meeting!
        $loginResponse->assertRedirect($meetingUrl);
    }

    public function test_invitation_destination_survives_unauthenticated_registration_flow(): void
    {
        $owner = User::factory()->create();
        $meeting = Meeting::create([
            'owner_id' => $owner->id,
            'title' => 'Quarterly All Hands',
            'room_name' => 'meet-allhands12',
        ]);

        // 1. Unauthenticated visitor opens invitation link
        $meetingUrl = "/meetings/{$meeting->id}";
        $this->get($meetingUrl)->assertRedirect('/login');

        // 2. Attendee chooses to register
        $regResponse = $this->post('/register', [
            'name' => 'New Attendee',
            'email' => 'new_attendee@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);

        // 3. Registration redirects straight to the intended meeting!
        $regResponse->assertRedirect($meetingUrl);
    }

    public function test_editing_meeting_without_changing_displayed_time_does_not_shift_stored_start_time(): void
    {
        $owner = User::factory()->create();
        // Exact time with specific seconds in UTC
        $exactTime = Carbon::create(2026, 11, 20, 15, 30, 45, 'UTC');

        $meeting = Meeting::create([
            'owner_id' => $owner->id,
            'title' => 'Accurate Seconds Meeting',
            'room_name' => 'meet-seconds123',
            'starts_at' => $exactTime,
            'duration_minutes' => 60,
        ]);

        // Form sends minute-precision string as rendered by datetime-local input
        // 15:30 UTC is 10:30 EST (America/New_York)
        $response = $this->actingAs($owner)->patch("/meetings/{$meeting->id}", [
            'title' => 'Renamed Meeting',
            'starts_at' => '2026-11-20T10:30',
            'timezone' => 'America/New_York',
            'duration_minutes' => 60,
        ]);

        $response->assertRedirect('/dashboard');

        $meeting->refresh();
        $this->assertEquals('Renamed Meeting', $meeting->title);
        // Assert stored start time is exactly preserved down to the second without shifting
        $this->assertEquals($exactTime->toIso8601String(), $meeting->starts_at->toIso8601String());
    }

    public function test_editing_title_of_past_meeting_is_allowed_without_forcing_future_date(): void
    {
        $owner = User::factory()->create();
        $pastTime = Carbon::now()->subDays(3)->setHour(10)->setMinute(0)->setSecond(0);

        $meeting = Meeting::create([
            'owner_id' => $owner->id,
            'title' => 'Original Past Meeting',
            'room_name' => 'meet-pastedit123',
            'starts_at' => $pastTime,
            'duration_minutes' => 45,
        ]);

        // Edit only the title, submitting the existing displayed datetime
        $response = $this->actingAs($owner)->patch("/meetings/{$meeting->id}", [
            'title' => 'Renamed Past Meeting',
            'starts_at' => $pastTime->format('Y-m-d\TH:i'),
            'timezone' => 'UTC',
            'duration_minutes' => 45,
        ]);

        $response->assertRedirect('/dashboard');

        $meeting->refresh();
        $this->assertEquals('Renamed Past Meeting', $meeting->title);
        $this->assertEquals($pastTime->toDateTimeString(), $meeting->starts_at->toDateTimeString());
    }

    public function test_invalid_date_or_timezone_returns_validation_error_rather_than_500(): void
    {
        $user = User::factory()->create();

        // 1. Invalid timezone
        $tzResponse = $this->actingAs($user)->post('/meetings', [
            'type' => 'scheduled',
            'title' => 'Invalid Timezone Meeting',
            'starts_at' => '2026-12-01T10:00',
            'timezone' => 'Fake/NonExistent_Zone',
            'duration_minutes' => 60,
        ]);
        $tzResponse->assertStatus(302);
        $tzResponse->assertSessionHasErrors('timezone');

        // 2. Invalid date format
        $dateResponse = $this->actingAs($user)->post('/meetings', [
            'type' => 'scheduled',
            'title' => 'Invalid Date Meeting',
            'starts_at' => 'not-a-valid-date-string-12345',
            'timezone' => 'UTC',
            'duration_minutes' => 60,
        ]);
        $dateResponse->assertStatus(302);
        $dateResponse->assertSessionHasErrors('starts_at');
    }
}

