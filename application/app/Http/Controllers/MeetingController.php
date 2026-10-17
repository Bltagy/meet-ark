<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

use App\Services\JitsiTokenService;

class MeetingController extends Controller
{
    /**
     * Display a listing of meetings created by the authenticated user.
     */
    public function index(Request $request): Response
    {
        $meetings = Meeting::where('owner_id', $request->user()->id)
            ->latest()
            ->get(['id', 'owner_id', 'title', 'room_name', 'created_at']);

        return Inertia::render('Dashboard', [
            'meetings' => $meetings->map(fn ($m) => [
                'id' => $m->id,
                'title' => $m->title,
                'room_name' => $m->room_name,
                'created_at' => $m->created_at->toIso8601String(),
                'url' => route('meetings.show', $m),
            ]),
        ]);
    }

    /**
     * Store a newly created meeting.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
        ]);

        // Generate a cryptographically random, collision-resistant room name on the backend.
        // Arbitrary room names from the client are strictly rejected.
        do {
            $roomName = 'meet-' . Str::lower(Str::random(12));
        } while (Meeting::where('room_name', $roomName)->exists());

        $meeting = $request->user()->meetings()->create([
            'title' => $validated['title'],
            'room_name' => $roomName,
        ]);

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
}
