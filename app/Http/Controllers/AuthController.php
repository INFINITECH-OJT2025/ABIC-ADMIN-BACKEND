<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const ALLOWED_ROLES = ['super_admin', 'super_admin_viewer', 'admin'];

    public function loginInfo(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Please use POST /api/login for authentication.',
            'errors' => null,
        ], 401);
    }

    public function login(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:8'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        }

        $email = Str::lower($validated['email']);
        $key = $email . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'success' => false,
                'message' => 'Too many login attempts. Try again later.',
                'errors' => null,
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        if (!Auth::validate(['email' => $email, 'password' => $validated['password']])) {
            RateLimiter::hit($key, 60);
            $this->logActivity($request, null, 'AUTH', 'LOGIN', 'FAILED', null, $email);

            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password',
                'errors' => [
                    'credentials' => ['The provided credentials do not match our records.'],
                ],
            ], 401);
        }

        RateLimiter::clear($key);
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User account was not found.',
                'errors' => [
                    'credentials' => ['Unable to locate the authenticated account.'],
                ],
            ], 401);
        }

        if (!in_array((string) $user->role, self::ALLOWED_ROLES, true)) {
            $this->logActivity($request, $user, 'AUTH', 'LOGIN', 'FAILED', 'Role not allowed');

            return response()->json([
                'success' => false,
                'message' => 'Access denied for this account role.',
                'errors' => [
                    'role' => ['Only super_admin, super_admin_viewer, and admin are allowed in this system.'],
                ],
            ], 403);
        }

        if (($user->account_status ?? 'active') === 'suspended') {
            return response()->json([
                'success' => false,
                'message' => 'Account suspended',
                'errors' => [
                    'account' => ['Your account has been suspended. Please contact your administrator.'],
                ],
            ], 403);
        }

        $requiresPasswordChange = $user->last_password_change === null || ($user->account_status ?? null) === 'inactive';

        if (!$requiresPasswordChange && (($user->password_expires_at ?? null) || ($user->account_status ?? null) === 'inactive')) {
            $this->clearPasswordExpiration($user);
        }

        $user->tokens()->delete();

        $abilities = match ((string) $user->role) {
            'super_admin' => ['*'],
            'admin' => ['admin'],
            'super_admin_viewer' => ['viewer'],
            default => ['basic'],
        };

        $token = $user
            ->createToken('auth_token', $abilities, now()->addDays(7))
            ->plainTextToken;

        $this->logActivity($request, $user, 'AUTH', 'LOGIN', 'SUCCESS');

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => 60 * 60 * 24 * 7,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'account_status' => $user->account_status ?? 'active',
                    'first_login' => $user->last_password_change === null,
                    'requires_password_change' => $requiresPasswordChange,
                    'email_verified_at' => $user->email_verified_at,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ],
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Not authenticated.',
                'errors' => null,
            ], 401);
        }

        $user->currentAccessToken()?->delete();
        $this->logActivity($request, $user, 'AUTH', 'LOGOUT', 'SUCCESS');

        return response()->json([
            'success' => true,
            'message' => 'Logout successful',
            'data' => null,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Not authenticated.',
                'errors' => null,
            ], 401);
        }

        if (!in_array((string) $user->role, self::ALLOWED_ROLES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied for this account role.',
                'errors' => [
                    'role' => ['Only super_admin, super_admin_viewer, and admin are allowed in this system.'],
                ],
            ], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'User retrieved successfully',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'account_status' => $user->account_status ?? 'active',
                'first_login' => $user->last_password_change === null,
                'password_expires_at' => $user->password_expires_at ?? null,
                'email_verified_at' => $user->email_verified_at,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ],
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Not authenticated.',
                'errors' => null,
            ], 401);
        }

        try {
            $validated = $request->validate([
                'current_password' => ['required', 'string'],
                'new_password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        }

        if (!Hash::check($validated['current_password'], $user->password)) {
            $this->logActivity($request, $user, 'AUTH', 'CHANGE_PASSWORD', 'FAILED', 'Current password mismatch');

            return response()->json([
                'success' => false,
                'message' => 'Invalid current password',
                'errors' => [
                    'current_password' => ['The current password is incorrect.'],
                ],
            ], 422);
        }

        if (Hash::check($validated['new_password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'New password cannot be the same as your current password',
                'errors' => [
                    'new_password' => ['New password must be different from your current password.'],
                ],
            ], 422);
        }

        $user->update([
            'password' => Hash::make($validated['new_password']),
            'password_expires_at' => null,
            'is_password_expired' => false,
            'last_password_change' => now(),
            'account_status' => 'active',
        ]);

        $user->tokens()->delete();
        $this->logActivity($request, $user, 'AUTH', 'CHANGE_PASSWORD', 'SUCCESS');

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully. Please login with your new password.',
            'data' => null,
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => ['required', 'email'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        }

        $status = Password::sendResetLink(['email' => $validated['email']]);
        $sendOk = $status === Password::RESET_LINK_SENT;

        $this->logActivity(
            $request,
            null,
            'AUTH',
            'FORGOT_PASSWORD',
            $sendOk ? 'SUCCESS' : 'FAILED',
            null,
            $validated['email']
        );

        return response()->json([
            'success' => true,
            'message' => 'If the email exists, a reset link has been sent.',
            'errors' => null,
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'token' => ['required', 'string'],
                'email' => ['required', 'email'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        }

        $status = Password::reset(
            [
                'token' => $validated['token'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'password_confirmation' => $request->input('password_confirmation'),
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                    'password_expires_at' => null,
                    'is_password_expired' => false,
                    'last_password_change' => now(),
                    'account_status' => 'active',
                ])->save();

                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            $this->logActivity(
                $request,
                null,
                'AUTH',
                'RESET_PASSWORD',
                'FAILED',
                'Invalid or expired reset token',
                $validated['email']
            );

            return response()->json([
                'success' => false,
                'message' => 'This password reset token is invalid or has expired.',
                'errors' => [
                    'token' => ['The provided token is invalid or has expired.'],
                ],
            ], 422);
        }

        $user = User::where('email', $validated['email'])->first();
        $this->logActivity($request, $user, 'AUTH', 'RESET_PASSWORD', 'SUCCESS');

        return response()->json([
            'success' => true,
            'message' => 'Password has been reset successfully.',
            'errors' => null,
        ]);
    }

    private function clearPasswordExpiration(User $user): void
    {
        $user->update([
            'password_expires_at' => null,
            'is_password_expired' => false,
            'last_password_change' => now(),
            'account_status' => 'active',
        ]);
    }

    private function logActivity(
        Request $request,
        ?User $user,
        string $activityType,
        string $action,
        string $status,
        ?string $reason = null,
        ?string $attemptedEmail = null
    ): void {
        try {
            $email = $user?->email ?? $attemptedEmail;
            $name = $user?->name;

            ActivityLog::create([
                'activity_type' => $activityType,
                'action' => $action,
                'status' => $status,
                'title' => str_replace('_', ' ', $action),
                'description' => $reason
                    ?? ($status === 'SUCCESS' ? "{$action} succeeded" : "{$action} failed"),
                'user_id' => $user?->id ? (string) $user->id : null,
                'user_name' => $name,
                'user_email' => $email,
                'target_id' => $user?->id ? (string) $user->id : null,
                'target_type' => $user ? User::class : null,
                'metadata' => [
                    'role' => $user?->role,
                    'page_url' => $request->header('X-Page-URL'),
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to write auth activity log.', [
                'action' => $action,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
