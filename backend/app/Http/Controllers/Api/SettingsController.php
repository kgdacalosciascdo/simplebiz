<?php

namespace App\Http\Controllers\Api;

use App\Models\AdminExportRequest;
use App\Models\Company;
use App\Models\CompanyOwnershipTransfer;
use App\Models\UserPreference;
use App\Services\SettingsService;
use App\Support\ApiResponse;
use App\Support\IdempotencyService;
use Illuminate\Http\Request;

class SettingsController
{
    public function __construct(private readonly SettingsService $service, private readonly IdempotencyService $idempotency) {}

    public function workspace(Request $request)
    {
        return ApiResponse::success($this->service->workspace($request, $this->company($request)));
    }

    public function account(Request $request)
    {
        return ApiResponse::success($this->service->account($request, $this->company($request)));
    }

    public function updateProfile(Request $request)
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:120'], 'reason' => ['nullable', 'string', 'max:500']]);

        return $this->idempotency->run($request, 'settings.account.profile.update', $this->company($request)->id, fn () => ApiResponse::success($this->service->saveProfile($request, $input)));
    }

    public function updatePreferences(Request $request)
    {
        $input = $request->validate([
            'locale' => ['nullable', 'string', 'max:12'],
            'timezone' => ['nullable', 'timezone'],
            'date_format' => ['nullable', 'string', 'max:40'],
            'number_format' => ['nullable', 'string', 'max:40'],
            'paper_size' => ['nullable', 'in:A4,Letter'],
            'accessibility' => ['nullable', 'array'],
            'notification_preferences' => ['nullable', 'array'],
            'display_preferences' => ['nullable', 'array'],
            'preferred_branch_id' => ['nullable', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->idempotency->run($request, 'settings.account.preferences.update', $this->company($request)->id, function () use ($request, $input) {
            $result = $this->service->savePreferences($request, $this->company($request), $input);
            if (isset($result['error'])) {
                return ApiResponse::error($result['error'], 422);
            }

            return ApiResponse::success($result);
        });
    }

    public function access(Request $request)
    {
        return ApiResponse::success($this->service->access($request, $this->company($request)));
    }

    public function sessions(Request $request)
    {
        return ApiResponse::success($this->service->sessions($request));
    }

    public function terminateSession(Request $request, int $token)
    {
        return $this->idempotency->run($request, 'settings.security.session.terminate', $this->company($request)->id, function () use ($request, $token) {
            $result = $this->service->terminateSession($request, $token);
            if (isset($result['error'])) {
                return ApiResponse::error($result['error'], 404);
            }

            return ApiResponse::success($result);
        });
    }

    public function terminateOtherSessions(Request $request)
    {
        return $this->idempotency->run($request, 'settings.security.sessions.terminate-other', $this->company($request)->id, fn () => ApiResponse::success($this->service->terminateOtherSessions($request)));
    }

    public function configuration(Request $request)
    {
        return ApiResponse::success($this->service->configuration($this->company($request), $this->servicePreference($request)));
    }

    public function changes(Request $request)
    {
        return ApiResponse::success($this->service->changeSets($this->company($request)));
    }

    public function modules(Request $request)
    {
        return ApiResponse::success($this->service->modules($this->company($request)));
    }

    public function notifications(Request $request)
    {
        return ApiResponse::success($this->service->notifications($this->company($request), $request->user()));
    }

    public function updateNotifications(Request $request)
    {
        $input = $request->validate(['user_preferences' => ['required', 'array'], 'reason' => ['nullable', 'string', 'max:500']]);

        return $this->idempotency->run($request, 'settings.notifications.update', $this->company($request)->id, fn () => ApiResponse::success($this->service->saveNotifications($request, $this->company($request), $input)));
    }

    public function audit(Request $request)
    {
        $result = $this->service->audit($request, $this->company($request));

        return ApiResponse::success($result['items'], 200, $result['meta']);
    }

    public function exports(Request $request)
    {
        return ApiResponse::success(AdminExportRequest::where('company_id', $this->company($request)->id)->latest()->limit(50)->get(['id', 'export_type', 'scope', 'purpose', 'status', 'ready_at', 'expires_at', 'downloaded_at', 'created_at']));
    }

    public function createExport(Request $request)
    {
        $input = $request->validate(['export_type' => ['required', 'in:administration,audit'], 'scope' => ['nullable', 'array'], 'purpose' => ['required', 'string', 'max:500']]);

        return $this->idempotency->run($request, 'settings.exports.create', $this->company($request)->id, fn () => ApiResponse::success($this->service->createExport($request, $this->company($request), $input), 201));
    }

    public function showExport(Request $request, AdminExportRequest $export)
    {
        if ($export->company_id !== $this->company($request)->id) {
            return ApiResponse::error('The requested export is outside the active company scope.', 404);
        }

        return ApiResponse::success($export->only(['id', 'export_type', 'scope', 'purpose', 'status', 'ready_at', 'expires_at', 'downloaded_at', 'created_at']));
    }

    public function downloadExport(Request $request, AdminExportRequest $export)
    {
        if ($export->company_id !== $this->company($request)->id || $export->status !== 'ready' || ($export->expires_at && $export->expires_at->isPast())) {
            return ApiResponse::error('The requested export is unavailable or expired.', 410);
        }
        $export->forceFill(['downloaded_at' => now()])->save();
        $payload = $export->package_payload ?? [];
        $filename = 'simplebiz-'.$export->export_type.'-'.$export->id.'.json';

        return response()->streamDownload(function () use ($payload) {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }, $filename, ['Content-Type' => 'application/json']);
    }

    public function requestOwnershipTransfer(Request $request)
    {
        $input = $request->validate(['user_id' => ['required', 'integer'], 'current_password' => ['required', 'string'], 'confirmation' => ['required', 'in:TRANSFER OWNERSHIP'], 'reason' => ['required', 'string', 'max:500']]);

        return $this->idempotency->run($request, 'settings.owner.transfer.request', $this->company($request)->id, function () use ($request, $input) {
            $result = $this->service->requestOwnershipTransfer($request, $this->company($request), $input);
            if (isset($result['error'])) {
                return ApiResponse::error($result['error'], 403);
            }

            return ApiResponse::success($result, 201);
        });
    }

    public function acceptOwnershipTransfer(Request $request, CompanyOwnershipTransfer $transfer)
    {
        $input = $request->validate(['current_password' => ['required', 'string'], 'confirmation' => ['required', 'in:ACCEPT OWNERSHIP']]);

        return $this->idempotency->run($request, 'settings.owner.transfer.accept', $this->company($request)->id, function () use ($request, $transfer, $input) {
            $result = $this->service->acceptOwnershipTransfer($request, $this->company($request), $transfer, $input);
            if (isset($result['error'])) {
                return ApiResponse::error($result['error'], 403);
            }

            return ApiResponse::success($result);
        });
    }

    private function company(Request $request): Company
    {
        return $request->attributes->get('company');
    }

    private function servicePreference(Request $request)
    {
        return UserPreference::where('user_id', $request->user()->id)->where('company_id', $this->company($request)->id)->first();
    }
}
