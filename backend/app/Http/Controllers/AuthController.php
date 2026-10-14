<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Anmeldung fehlgeschlagen.'],
            ]);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return response()->json([
            'user' => $this->serializeUser($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return response()->json([
            'user' => $this->serializeUser($user),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUser(User $user): array
    {
        $name = trim((string) $user->name);
        $parts = preg_split('/\s+/', $name) ?: [];
        $first = $parts[0] ?? '';
        $last = trim(implode(' ', array_slice($parts, 1)));
        $role = ($user->global_role ?? 'admin') === 'member' ? 'member' : 'admin';

        return [
            'id' => (string) $user->id,
            'name' => $name !== '' ? $name : (string) $user->email,
            'email' => (string) $user->email,
            'firstName' => $first,
            'lastName' => $last,
            'alias' => $first !== '' ? $first : (string) $user->email,
            'notificationEmail' => (string) $user->email,
            'avatarDataUrl' => null,
            'twoFactorEnabled' => false,
            'globalRole' => $role,
            'active' => true,
            'memberships' => [],
            'groupIds' => [],
        ];
    }
}
