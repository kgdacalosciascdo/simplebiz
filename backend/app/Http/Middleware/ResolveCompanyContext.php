<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use App\Support\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCompanyContext
{
    public function __construct(private readonly CompanyContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $headerCompanyId = $request->header('X-Company-ID');
        $companyId = $headerCompanyId ?: $user?->preferred_company_id;
        $company = $companyId
            ? $user?->companies()->whereKey($companyId)->wherePivot('status', 'active')->first()
            : $user?->companies()->wherePivot('status', 'active')->orderBy('companies.id')->first();

        if (! $company) {
            return $headerCompanyId
                ? ApiResponse::error('The requested company was not found in your active scope.', 404)
                : ApiResponse::error('A valid active company context is required.', 409);
        }

        $this->context->set($company);
        $request->attributes->set('company', $company);

        return $next($request);
    }
}
