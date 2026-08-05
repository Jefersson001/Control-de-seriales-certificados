<?php

namespace App\Http\Middleware;

use App\UserPermission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $userPermission = UserPermission::tryFrom($permission);

        abort_unless(
            $userPermission !== null && $request->user()?->hasPermission($userPermission),
            Response::HTTP_FORBIDDEN,
        );

        return $next($request);
    }
}
