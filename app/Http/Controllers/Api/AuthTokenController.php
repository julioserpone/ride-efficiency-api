<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IssueTokenRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthTokenController extends Controller
{
    /**
     * Issue a new personal access token for the given credentials.
     */
    public function store(IssueTokenRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->string('email'))->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $deviceName = $request->string('device_name', 'api-client')->toString();

        $token = $user->createToken($deviceName);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => $user->only(['id', 'name', 'email']),
        ], 201);
    }

    /**
     * Revoke the token used to authenticate the current request.
     */
    public function destroy(Request $request): JsonResponse
    {
        /** @var PersonalAccessToken|null $token */
        $token = $request->user()->currentAccessToken();

        $token?->delete();

        return response()->json(['message' => 'Token revoked successfully.']);
    }

    /**
     * Return the authenticated user for the current token.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $user->only(['id', 'name', 'email', 'country_id']),
            'password_changed_at' => $user->password_changed_at?->toIso8601String(),
            'password_rotation_due' => $this->passwordRotationDue($user),
        ]);
    }

    /**
     * Change the authenticated user's password.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('auth.password'),
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'password_changed_at' => now(),
        ])->save();

        return response()->json(['message' => 'Password changed successfully.']);
    }

    /**
     * List active sessions (personal access tokens) for the authenticated user.
     */
    public function sessions(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'abilities', 'last_used_at', 'created_at', 'expires_at']);

        return response()->json(['sessions' => $tokens]);
    }

    /**
     * Revoke a single session by token id.
     */
    public function revokeSession(Request $request, int $tokenId): JsonResponse
    {
        $deleted = $request->user()->tokens()->where('id', $tokenId)->delete();

        if ($deleted === 0) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        return response()->json(['message' => 'Session revoked successfully.']);
    }

    /**
     * Revoke all sessions except the current one.
     */
    public function revokeAllSessions(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()->id;

        $request->user()->tokens()
            ->where('id', '!=', $currentId)
            ->delete();

        return response()->json(['message' => 'Sessions revoked successfully.']);
    }

    private function passwordRotationDue(User $user): bool
    {
        if ($user->password_changed_at === null) {
            return false;
        }

        $days = (int) config('auth.password_rotation_days', 180);

        return $user->password_changed_at->lt(now()->subDays($days));
    }
}
