<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
                'errors' => null,
            ], 401);
        }

        if (!in_array((string) $user->role, $roles, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden - insufficient role.',
                'errors' => [
                    'role' => ['You do not have permission for this resource.'],
                ],
            ], 403);
        }

        return $next($request);
    }
}
