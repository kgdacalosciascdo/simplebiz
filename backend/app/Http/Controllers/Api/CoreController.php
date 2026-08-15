<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CoreSearchService;
use App\Services\NotificationService;
use App\Support\ApiResponse;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Illuminate\Http\Request;

class CoreController extends Controller
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly CoreSearchService $search,
        private readonly NotificationService $notifications,
        private readonly IdempotencyService $idempotency,
    ) {}

    public function search(Request $request)
    {
        $input = $request->validate(['q' => ['required', 'string', 'min:2', 'max:120'], 'limit' => ['nullable', 'integer', 'between:1,50']]);

        return ApiResponse::success($this->search->search($request, $this->context->get(), $request->user(), $input['q'], $input['limit'] ?? 20));
    }

    public function notifications(Request $request)
    {
        return ApiResponse::success($this->notifications->inbox($request, $this->context->get(), $request->user()));
    }

    public function readNotification(Request $request, string $id)
    {
        return $this->idempotency->run($request, 'core.notifications.read', $this->context->id(), fn () => ApiResponse::success($this->notifications->markRead($request, $this->context->get(), $request->user(), $id)));
    }

    public function readAllNotifications(Request $request)
    {
        return $this->idempotency->run($request, 'core.notifications.read-all', $this->context->id(), fn () => ApiResponse::success($this->notifications->markAllRead($request, $this->context->get(), $request->user())));
    }
}
