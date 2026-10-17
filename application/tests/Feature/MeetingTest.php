<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MeetingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_users_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->post('/meetings', ['title' => 'Test'])->assertRedirect('/login');

        $user = User::factory()->create();
        $meeting = Meeting::create([
            'owner_id' => $user->id,
            'title' => 'Secret Meeting',
            'room_name' => 'meet-secret123',
        ]);

        $this->get("/meetings/{$meeting->id}")->assertRedirect('/login');
        $this->delete("/meetings/{$meeting->id}")->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_dashboard(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertStatus(200);
    }

    public function test_authenticated_user_can_create_meeting_with_title_only(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/meetings', [
            'title' => 'Architecture Sync',
        ]);

        $meeting = Meeting::first();

        $this->assertNotNull($meeting);
        $this->assertEquals('Architecture Sync', $meeting->title);
        $this->assertEquals($user->id, $meeting->owner_id);
        $this->assertStringStartsWith('meet-', $meeting->room_name);
        $this->assertEquals(17, strlen($meeting->room_name)); // 'meet-' (5) + 12 chars

        $response->assertRedirect("/meetings/{$meeting->id}");
    }

    public function test_meeting_requires_valid_title(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/meetings', [
            'title' => '',
        ]);

        $response->assertSessionHasErrors('title');
        $this->assertDatabaseCount('meetings', 0);
    }

    public function test_user_cannot_list_another_users_meetings_on_their_dashboard(): void
    {
        $userA = User::factory()->create(['name' => 'User A']);
        $userB = User::factory()->create(['name' => 'User B']);

        $meetingA = Meeting::create([
            'owner_id' => $userA->id,
            'title' => 'User A Private Meeting',
            'room_name' => 'meet-usera123',
        ]);

        $meetingB = Meeting::create([
            'owner_id' => $userB->id,
            'title' => 'User B Meeting',
            'room_name' => 'meet-userb123',
        ]);

        // User B visits dashboard
        $response = $this->actingAs($userB)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('meetings', 1)
            ->where('meetings.0.title', 'User B Meeting')
            ->where('meetings.0.room_name', 'meet-userb123')
        );
    }

    public function test_any_authenticated_user_with_invitation_link_can_access_meeting(): void
    {
        $owner = User::factory()->create(['name' => 'Owner']);
        $guest = User::factory()->create(['name' => 'Invited Colleague']);

        $meeting = Meeting::create([
            'owner_id' => $owner->id,
            'title' => 'Product Discussion',
            'room_name' => 'meet-product789',
        ]);

        // Guest user visits the meeting room
        $response = $this->actingAs($guest)->get("/meetings/{$meeting->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Meetings/Show')
            ->where('meeting.title', 'Product Discussion')
            ->where('meeting.room_name', 'meet-product789')
            ->where('meeting.is_owner', false)
            ->has('token')
            ->has('jitsi.url')
        );
    }

    public function test_owner_can_delete_their_meeting(): void
    {
        $owner = User::factory()->create();
        $meeting = Meeting::create([
            'owner_id' => $owner->id,
            'title' => 'Ephemeral Meeting',
            'room_name' => 'meet-delete123',
        ]);

        $response = $this->actingAs($owner)->delete("/meetings/{$meeting->id}");

        $response->assertRedirect('/dashboard');
        $this->assertDatabaseMissing('meetings', ['id' => $meeting->id]);
    }

    public function test_non_owner_cannot_delete_meeting(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $meeting = Meeting::create([
            'owner_id' => $owner->id,
            'title' => 'Protected Meeting',
            'room_name' => 'meet-protected1',
        ]);

        $response = $this->actingAs($stranger)->delete("/meetings/{$meeting->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('meetings', ['id' => $meeting->id]);
    }
}
