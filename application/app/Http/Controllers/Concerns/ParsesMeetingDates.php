<?php

namespace App\Http\Controllers\Concerns;

use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

trait ParsesMeetingDates
{
    /**
     * Parse date string with timezone awareness into UTC Carbon instance.
     * Throws ValidationException on invalid format or unknown timezone rather than 500 error.
     */
    protected function parseStartsAt(string $dateString, ?string $timezone = null): Carbon
    {
        $trimmed = trim($dateString);

        if ($timezone && !in_array($timezone, timezone_identifiers_list(), true)) {
            throw ValidationException::withMessages([
                'timezone' => __('The selected timezone is invalid.'),
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
                'starts_at' => __('The provided scheduled start time is invalid.'),
            ]);
        }
    }
}
