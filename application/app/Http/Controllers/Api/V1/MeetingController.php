<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ParsesMeetingDates;
use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingResource;
use App\Models\Meeting;
use App\Services\JitsiTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class MeetingController extends Controller
{
    use ParsesMeetingDates;

    /**
     * Display a paginated listing of meetings owned by the authenticated user.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = (int) $request->query('per_page', 15);
        $perPage = max(1, min($perPage, 100));

        $meetings = Meeting::where('owner_id', $request->user()->id)
            ->latest('id')
            ->paginate($perPage);

        return MeetingResource::collection($meetings);
    }

    /**
     * Store a newly created instant or scheduled meeting.
     */
    public function store(Request $request): JsonResponse
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
                    'starts_at' => __('The scheduled start time is required for scheduled meetings.'),
                ]);
            }

            $startsAt = $this->parseStartsAt(
                (string) $request->input('starts_at'),
                $request->input('timezone')
            );

            if ($startsAt->isPast()) {
                throw ValidationException::withMessages([
                    'starts_at' => __('The scheduled start time must be in the future.'),
                ]);
            }

            $duration = (int) $request->input('duration_minutes', 60);
            if ($duration < 1) {
                throw ValidationException::withMessages([
                    'duration_minutes' => __('The duration must be at least 1 minute.'),
                ]);
            }
        }

        $roomName = Meeting::generateRoomName();

        $meeting = $request->user()->meetings()->create([
            'title' => $validated['title'],
            'room_name' => $roomName,
            'starts_at' => $startsAt,
            'duration_minutes' => $duration,
        ]);

        return response()->json([
            'message' => __('Meeting created successfully.'),
            'meeting' => new MeetingResource($meeting),
        ], 201);
    }

    /**
     * Display the specified meeting details for any authenticated user with access.
     */
    public function show(Request $request, Meeting $meeting): JsonResponse
    {
        return response()->json([
            'meeting' => new MeetingResource($meeting),
        ]);
    }

    /**
     * Update the specified meeting (owner only).
     */
    public function update(Request $request, Meeting $meeting): JsonResponse
    {
        if ((int) $meeting->owner_id !== (int) $request->user()->id) {
            abort(403, __('Only the meeting owner can edit this meeting.'));
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

                if ($isDateChanged) {
                    if ($startsAt->isPast()) {
                        throw ValidationException::withMessages([
                            'starts_at' => __('The scheduled start time must be in the future.'),
                        ]);
                    }
                    $meeting->starts_at = $startsAt;
                }

                $meeting->duration_minutes = (int) $request->input('duration_minutes', $meeting->duration_minutes ?: 60);
            } else {
                $meeting->starts_at = null;
                $meeting->duration_minutes = null;
            }
        } elseif ($request->filled('duration_minutes') && $meeting->starts_at) {
            $meeting->duration_minutes = (int) $request->input('duration_minutes');
        }

        // Room name and owner_id are strictly preserved
        $meeting->save();

        return response()->json([
            'message' => __('Meeting updated successfully.'),
            'meeting' => new MeetingResource($meeting),
        ]);
    }

    /**
     * Remove the specified meeting (owner only).
     */
    public function destroy(Request $request, Meeting $meeting): JsonResponse
    {
        if ((int) $meeting->owner_id !== (int) $request->user()->id) {
            abort(403, __('Only the meeting owner can delete this meeting.'));
        }

        $meeting->delete();

        return response()->json([
            'message' => __('Meeting deleted successfully.'),
        ]);
    }

    /**
     * Issue a short-lived Jitsi join token for the authenticated user.
     */
    public function join(Request $request, Meeting $meeting, JitsiTokenService $tokenService): JsonResponse
    {
        $jitsiUrl = rtrim(config('services.jitsi.url', env('JITSI_PUBLIC_URL', 'https://localhost:8443')), '/');
        $token = $tokenService->generateToken($request->user(), $meeting);
        $isModerator = ((int) $meeting->owner_id === (int) $request->user()->id);
        $expiresAt = now()->addMinutes(5)->toIso8601String();

        return response()->json([
            'jitsi_url' => $jitsiUrl,
            'room_name' => $meeting->room_name,
            'token' => $token,
            'expires_at' => $expiresAt,
            'is_moderator' => $isModerator,
        ])->header('Cache-Control', 'no-store, private');
    }
}
