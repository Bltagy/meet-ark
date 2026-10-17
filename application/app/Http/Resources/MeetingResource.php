<?php

namespace App\Http\Resources;

use App\Models\Meeting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Meeting
 */
class MeetingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'room_name' => $this->room_name,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            'is_scheduled' => !is_null($this->starts_at),
            'is_owner' => ((int) $this->owner_id === (int) $request->user()?->id),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'invitation_url' => route('meetings.show', $this->resource),
        ];
    }
}
