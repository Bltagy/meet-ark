<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Services\JitsiTokenService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MeetingController extends Controller
{
    /**
     * Display a listing of meetings created by the authenticated user.
     */
    public function index(Request $request): Response
    {
        $userId = $request->user()->id;
        $now = now();

        $formatMeeting = fn (Meeting $m) => [
            'id' => $m->id,
            'title' => $m->title,
            'room_name' => $m->room_name,
            'starts_at' => $m->starts_at?->toIso8601String(),
            'duration_minutes' => $m->duration_minutes,
            'is_scheduled' => !is_null($m->starts_at),
            'created_at' => $m->created_at->toIso8601String(),
            'url' => route('meetings.show', $m),
        ];

        // Upcoming scheduled meetings, ordered by start time
        $upcomingMeetings = Meeting::where('owner_id', $userId)
            ->whereNotNull('starts_at')
            ->where('starts_at', '>=', $now)
            ->orderBy('starts_at', 'asc')
            ->get()
            ->map($formatMeeting);

        // Instant meetings and past scheduled meetings in a separate section
        $recentMeetings = Meeting::where('owner_id', $userId)
            ->where(function ($query) use ($now) {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<', $now);
            })
            ->orderBy('created_at', 'desc')
            ->get()
            ->map($formatMeeting);

        // All user meetings (for backward compatibility with existing tests and views)
        $allMeetings = Meeting::where('owner_id', $userId)
            ->latest()
            ->get()
            ->map($formatMeeting);

        return Inertia::render('Dashboard', [
            'meetings' => $allMeetings,
            'upcomingMeetings' => $upcomingMeetings,
            'recentMeetings' => $recentMeetings,
        ]);
    }

    /**
     * Store a newly created meeting.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'type' => ['nullable', 'string', 'in:instant,scheduled'],
            'starts_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'timezone:all'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        $isScheduled = ($request->input('type') === 'scheduled') || $request->filled('starts_at');
        $startsAt = null;
        $duration = null;

        if ($isScheduled) {
            if (!$request->filled('starts_at')) {
                throw ValidationException::withMessages([
                    'starts_at' => 'The scheduled start time is required for scheduled meetings.',
                ]);
            }

            $startsAt = $this->parseStartsAt(
                (string) $request->input('starts_at'),
                $request->input('timezone')
            );

            if ($startsAt->isPast()) {
                throw ValidationException::withMessages([
                    'starts_at' => 'The scheduled start time must be in the future.',
                ]);
            }

            $duration = (int) $request->input('duration_minutes', 60);
            if ($duration < 1) {
                throw ValidationException::withMessages([
                    'duration_minutes' => 'The duration must be at least 1 minute.',
                ]);
            }
        }

        // Generate a cryptographically random, collision-resistant room name on the backend.
        // Arbitrary room names from the client are strictly rejected.
        do {
            $roomName = 'meet-' . Str::lower(Str::random(12));
        } while (Meeting::where('room_name', $roomName)->exists());

        $meeting = $request->user()->meetings()->create([
            'title' => $validated['title'],
            'room_name' => $roomName,
            'starts_at' => $startsAt,
            'duration_minutes' => $duration,
        ]);

        if ($isScheduled) {
            return redirect()->route('dashboard');
        }

        return redirect()->route('meetings.show', $meeting);
    }

    /**
     * Display the specified meeting room for any authenticated user with the link.
     */
    public function show(Request $request, Meeting $meeting, JitsiTokenService $tokenService): Response
    {
        $jitsiUrl = config('services.jitsi.url', env('JITSI_PUBLIC_URL', 'https://localhost:8443'));
        $token = $tokenService->generateToken($request->user(), $meeting);

        return Inertia::render('Meetings/Show', [
            'meeting' => [
                'id' => $meeting->id,
                'title' => $meeting->title,
                'room_name' => $meeting->room_name,
                'starts_at' => $meeting->starts_at?->toIso8601String(),
                'duration_minutes' => $meeting->duration_minutes,
                'is_scheduled' => !is_null($meeting->starts_at),
                'is_owner' => $meeting->owner_id === $request->user()->id,
                'created_at' => $meeting->created_at->toIso8601String(),
                'invitation_url' => route('meetings.show', $meeting),
            ],
            'token' => $token,
            'jitsi' => [
                'url' => rtrim($jitsiUrl, '/'),
            ],
        ]);
    }

    /**
     * Update the specified meeting (owner only).
     */
    public function update(Request $request, Meeting $meeting): RedirectResponse
    {
        if ($meeting->owner_id !== $request->user()->id) {
            abort(403, 'Only the meeting owner can edit this meeting.');
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'starts_at' => ['nullable'],
            'timezone' => ['nullable', 'string'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        $meeting->title = $validated['title'];
        $tz = $request->input('timezone') ?: 'UTC';

        if ($request->has('starts_at')) {
            if ($request->filled('starts_at')) {
                $startsAt = $this->parseStartsAt(
                    (string) $request->input('starts_at'),
                    $request->input('timezone')
                );

                // Determine whether the displayed date/time was actually changed.
                // datetime-local inputs provide minute-level precision in the user's timezone.
                $isDateChanged = false;
                if ($meeting->starts_at === null) {
                    $isDateChanged = true;
                } else {
                    $formattedExisting = $meeting->starts_at->copy()->setTimezone($tz)->format('Y-m-d H:i');
                    $formattedNew = $startsAt->copy()->setTimezone($tz)->format('Y-m-d H:i');
                    if ($formattedExisting !== $formattedNew) {
                        $isDateChanged = true;
                    }
                }

                // If user changed the schedule to a new time, enforce future date
                if ($isDateChanged) {
                    if ($startsAt->isPast()) {
                        throw ValidationException::withMessages([
                            'starts_at' => 'The scheduled start time must be in the future.',
                        ]);
                    }
                    $meeting->starts_at = $startsAt;
                }
                // If the displayed time was not changed (e.g. editing title of an upcoming or past meeting),
                // preserve $meeting->starts_at exactly without shifting stored seconds.

                $meeting->duration_minutes = (int) $request->input('duration_minutes', $meeting->duration_minutes ?: 60);
            } else {
                // Clear scheduled time (convert to instant meeting)
                $meeting->starts_at = null;
                $meeting->duration_minutes = null;
            }
        } elseif ($request->filled('duration_minutes') && $meeting->starts_at) {
            $meeting->duration_minutes = (int) $request->input('duration_minutes');
        }

        // Room name and owner_id are strictly preserved
        $meeting->save();

        return redirect()->route('dashboard');
    }

    /**
     * Remove the specified meeting (owner only).
     */
    public function destroy(Request $request, Meeting $meeting): RedirectResponse
    {
        if ($meeting->owner_id !== $request->user()->id) {
            abort(403, 'Only the meeting owner can delete this meeting.');
        }

        $meeting->delete();

        return redirect()->route('dashboard');
    }

    /**
     * Parse date string with timezone awareness into UTC Carbon instance.
     * Throws ValidationException on invalid format or unknown timezone rather than 500 error.
     */
    protected function parseStartsAt(string $dateString, ?string $timezone = null): Carbon
    {
        $trimmed = trim($dateString);

        if ($timezone && !in_array($timezone, timezone_identifiers_list(), true)) {
            throw ValidationException::withMessages([
                'timezone' => 'The selected timezone is invalid.',
            ]);
        }

        try {
            // If string contains explicit timezone offset or Z, parse directly and convert to UTC
            if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $trimmed)) {
                return Carbon::parse($trimmed)->utc();
            }

            // Otherwise, interpret in the provided timezone (or UTC if none given) and convert to UTC
            $tz = $timezone ?: 'UTC';
            return Carbon::parse($trimmed, $tz)->utc();
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'starts_at' => 'The provided scheduled start time is invalid.',
            ]);
        }
    }
}
