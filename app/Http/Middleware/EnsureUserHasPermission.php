<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard for a fixed role permission (see App\Models\Permission). A user
 * without it is sent back to the start screen (the point of sale) instead of
 * seeing an error page.
 */
class EnsureUserHasPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if ($request->user()?->can($permission)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return redirect()->route('pos.index')->with('error', __('No tienes permiso para entrar a esa sección.'));
    }
}
