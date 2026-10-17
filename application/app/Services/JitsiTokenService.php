<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\User;
use Firebase\JWT\JWT;
use RuntimeException;

class JitsiTokenService
{
    /**
     * Generate a room-scoped, short-lived JWT for an authenticated user joining a meeting.
     */
    public function generateToken(User $user, Meeting $meeting): string
    {
        $appId = config('services.jitsi.app_id', env('JWT_APP_ID', 'meet_ark_backend'));
        $appSecret = config('services.jitsi.app_secret', env('JWT_APP_SECRET'));
        $audience = config('services.jitsi.audience', env('JWT_ACCEPTED_AUDIENCES', 'meet_ark_platform'));
        $issuer = config('services.jitsi.issuer', env('JWT_ACCEPTED_ISSUERS', $appId));
        $xmppDomain = config('services.jitsi.xmpp_domain', 'meet.jitsi');

        if (empty($appSecret)) {
            throw new RuntimeException('JWT_APP_SECRET is not configured for Jitsi token generation.');
        }

        $now = time();
        $isOwner = ((int) $meeting->owner_id === (int) $user->id);

        $payload = [
            'iss' => $issuer,
            'aud' => $audience,
            'sub' => $xmppDomain,
            'room' => $meeting->room_name,
            'iat' => $now,
            'nbf' => $now - 10,
            'exp' => $now + 300, // 5 minutes TTL
            'moderator' => $isOwner,
            'context' => [
                'user' => [
                    'id' => (string) $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'moderator' => $isOwner,
                ],
            ],
        ];

        return JWT::encode($payload, $appSecret, 'HS256');
    }
}
