<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_visit_defaults_to_arabic_with_rtl(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSee('lang="ar"', false);
        $response->assertSee('dir="rtl"', false);
        $response->assertInertia(fn ($page) => $page
            ->where('locale', 'ar')
            ->where('dir', 'rtl')
        );
    }

    public function test_user_can_switch_locale_via_post_and_receives_cookie(): void
    {
        $response = $this->from('/login')->post('/locale', [
            'locale' => 'en',
        ]);

        $response->assertRedirect('/login');
        $response->assertPlainCookie('locale', 'en');
    }

    public function test_user_can_switch_locale_via_get_shortcut(): void
    {
        $response = $this->from('/login')->get('/locale/en');

        $response->assertRedirect('/login');
        $response->assertPlainCookie('locale', 'en');
    }

    public function test_cookie_persists_english_locale_with_ltr(): void
    {
        $response = $this->withUnencryptedCookie('locale', 'en')->get('/login');

        $response->assertStatus(200);
        $response->assertSee('lang="en"', false);
        $response->assertSee('dir="ltr"', false);
        $response->assertInertia(fn ($page) => $page
            ->where('locale', 'en')
            ->where('dir', 'ltr')
        );
    }

    public function test_invalid_locale_cookie_safely_defaults_to_arabic(): void
    {
        $response = $this->withUnencryptedCookie('locale', 'es')->get('/login');

        $response->assertStatus(200);
        $response->assertSee('lang="ar"', false);
        $response->assertSee('dir="rtl"', false);
        $response->assertInertia(fn ($page) => $page
            ->where('locale', 'ar')
            ->where('dir', 'rtl')
        );
    }

    public function test_validation_errors_are_localized_in_arabic_by_default(): void
    {
        $user = User::factory()->create();

        // 1. Missing title validation
        $response = $this->actingAs($user)->post('/meetings', [
            'title' => '',
        ]);

        $response->assertSessionHasErrors(['title']);
        $this->assertSame(
            'حقل العنوان مطلوب.',
            session('errors')->first('title')
        );

        // 2. Custom past date validation
        $pastResponse = $this->actingAs($user)->post('/meetings', [
            'title' => 'اجتماع تجريبي',
            'type' => 'scheduled',
            'starts_at' => Carbon::now()->subMinutes(10)->format('Y-m-d\TH:i'),
            'duration_minutes' => 60,
        ]);

        $pastResponse->assertSessionHasErrors(['starts_at']);
        $this->assertSame(
            'يجب أن يكون وقت البدء المجدول في المستقبل.',
            session('errors')->first('starts_at')
        );
    }

    public function test_validation_errors_are_localized_in_english_when_cookie_is_set(): void
    {
        $user = User::factory()->create();

        // 1. Missing title validation
        $response = $this->actingAs($user)
            ->withUnencryptedCookie('locale', 'en')
            ->post('/meetings', [
                'title' => '',
            ]);

        $response->assertSessionHasErrors(['title']);
        $this->assertSame(
            'The title field is required.',
            session('errors')->first('title')
        );

        // 2. Custom past date validation
        $pastResponse = $this->actingAs($user)
            ->withUnencryptedCookie('locale', 'en')
            ->post('/meetings', [
                'title' => 'Test Meeting',
                'type' => 'scheduled',
                'starts_at' => Carbon::now()->subMinutes(10)->format('Y-m-d\TH:i'),
                'duration_minutes' => 60,
            ]);

        $pastResponse->assertSessionHasErrors(['starts_at']);
        $this->assertSame(
            'The scheduled start time must be in the future.',
            session('errors')->first('starts_at')
        );
    }

    public function test_switching_locale_does_not_change_meeting_instant_or_schedule(): void
    {
        $user = User::factory()->create();
        $targetTime = Carbon::now()->addDays(2)->setMinutes(0)->setSeconds(0);

        // Create meeting while in English
        $response = $this->actingAs($user)
            ->withUnencryptedCookie('locale', 'en')
            ->post('/meetings', [
                'title' => 'Cross-Language Sync',
                'type' => 'scheduled',
                'starts_at' => $targetTime->copy()->setTimezone('UTC')->format('Y-m-d\TH:i'),
                'timezone' => 'UTC',
                'duration_minutes' => 45,
            ]);

        $response->assertRedirect('/dashboard');
        $meeting = $user->meetings()->first();
        $this->assertNotNull($meeting);
        $expectedUtc = $targetTime->copy()->utc()->format('Y-m-d H:i:00');
        $this->assertSame($expectedUtc, $meeting->starts_at->format('Y-m-d H:i:s'));

        // View dashboard in Arabic
        $arDashboard = $this->actingAs($user)
            ->withUnencryptedCookie('locale', 'ar')
            ->get('/dashboard');

        $arDashboard->assertStatus(200);
        $arDashboard->assertInertia(fn ($page) => $page
            ->where('locale', 'ar')
            ->where('dir', 'rtl')
            ->has('upcomingMeetings', 1, fn ($item) => $item
                ->where('id', $meeting->id)
                ->where('title', 'Cross-Language Sync') // user-entered title unchanged
                ->where('duration_minutes', 45)
                ->where('starts_at', $meeting->starts_at->toIso8601String()) // exact same UTC instant
                ->etc()
            )
        );

        // View meeting show room in Arabic
        $arShow = $this->actingAs($user)
            ->withUnencryptedCookie('locale', 'ar')
            ->get("/meetings/{$meeting->id}");

        $arShow->assertStatus(200);
        $arShow->assertInertia(fn ($page) => $page
            ->where('locale', 'ar')
            ->where('dir', 'rtl')
            ->where('meeting.starts_at', $meeting->starts_at->toIso8601String())
            ->where('meeting.title', 'Cross-Language Sync')
        );
    }
}
