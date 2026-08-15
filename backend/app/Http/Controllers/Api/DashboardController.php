<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use App\Support\ApiResponse;
use App\Support\CompanyContext;
use Illuminate\Http\Request;

final class DashboardController extends Controller
{
    public function __construct(private readonly CompanyContext $context, private readonly DashboardService $dashboard) {}

    public function show(Request $request)
    {
        return ApiResponse::success($this->dashboard->compose($this->context->get(), $request));
    }
}
