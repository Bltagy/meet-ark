<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Meeting extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'owner_id',
        'title',
        'room_name',
        'starts_at',
        'duration_minutes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'owner_id' => 'integer',
        'starts_at' => 'datetime',
        'duration_minutes' => 'integer',
    ];

    /**
     * Get the user that owns the meeting.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Generate a cryptographically random, collision-resistant room name.
     */
    public static function generateRoomName(): string
    {
        do {
            $roomName = 'meet-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(12));
        } while (static::where('room_name', $roomName)->exists());

        return $roomName;
    }
}
