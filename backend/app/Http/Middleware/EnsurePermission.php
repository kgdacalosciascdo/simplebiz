<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $companyId = $request->attributes->get('company')?->id;
        if (! $request->user()?->hasPermission($permission, $companyId)) {
            return ApiResponse::error('You are not authorized to perform this action.', 403);
        }

        return $next($request);
    }
}
