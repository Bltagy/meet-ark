<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Handle API user registration and issue initial personal access token.
     */
    public function register(Request $request): JsonResponse
    {
        $passwordRules = ['required', 'string', Rules\Password::defaults()];
        if ($request->has('password_confirmation')) {
            $passwordRules[] = 'confirmed';
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => $passwordRules,
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        event(new Registered($user));

        $deviceName = !empty($validated['device_name']) ? trim($validated['device_name']) : 'api-client';
        $expirationMinutes = (int) config('sanctum.expiration', 43200);
        $expiresAt = now()->addMinutes($expirationMinutes);

        $token = $user->createToken($deviceName, ['*'], $expiresAt);

        return response()->json([
            'message' => __('User registered successfully.'),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => $user->created_at->toIso8601String(),
            ],
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
        ], 201);
    }

    /**
     * Authenticate API credentials and issue personal access token.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [trans('auth.failed')],
            ]);
        }

        $deviceName = !empty($validated['device_name']) ? trim($validated['device_name']) : 'api-client';
        $expirationMinutes = (int) config('sanctum.expiration', 43200);
        $expiresAt = now()->addMinutes($expirationMinutes);

        $token = $user->createToken($deviceName, ['*'], $expiresAt);

        return response()->json([
            'message' => __('Authenticated successfully.'),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => $user->created_at->toIso8601String(),
            ],
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    /**
     * Revoke the current access token for the authenticated user.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => __('Successfully logged out.'),
        ]);
    }

    /**
     * Get the authenticated user details.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
                'email' => $request->user()->email,
                'created_at' => $request->user()->created_at->toIso8601String(),
            ],
        ]);
    }
}
