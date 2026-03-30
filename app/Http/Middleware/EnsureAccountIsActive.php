<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    private const ALLOWED_ROLES = ['super_admin', 'super_admin_viewer', 'admin'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        if (!in_array((string) $user->role, self::ALLOWED_ROLES, true)) {
            $user->currentAccessToken()?->delete();

            return response()->json([
                'success' => false,
                'message' => 'Access denied for this account role.',
                'errors' => [
                    'role' => ['Only super_admin, super_admin_viewer, and admin are allowed in this system.'],
                ],
            ], 403);
        }

        if (($user->account_status ?? null) === 'suspended') {
            Log::warning('Suspended user attempted API access.', [
                'user_id' => $user->id,
                'email' => $user->email,
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);

            $user->currentAccessToken()?->delete();

            return response()->json([
                'success' => false,
                'message' => 'Account suspended. Please contact your administrator.',
                'errors' => [
                    'account' => ['Your account has been suspended.'],
                ],
            ], 403);
        }

        if (
            ($user->password_expires_at ?? null) &&
            now()->greaterThan($user->password_expires_at) &&
            $user->role !== 'super_admin' &&
            !$request->is('api/change-password')
        ) {
            $user->update([
                'is_password_expired' => true,
                'account_status' => 'expired',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Password expired. Please change your password.',
                'errors' => [
                    'password' => ['Your temporary password has expired. Please contact your administrator for new credentials.'],
                ],
                'requires_password_reset' => true,
            ], 401);
        }

        return $next($request);
    }
}
