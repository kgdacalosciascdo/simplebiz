<?php

namespace App\Support;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class IdempotencyService
{
    public function run(Request $request, string $operation, ?int $companyId, Closure $action): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        if (! $key) {
            return $action();
        }
        if (strlen($key) > 180 || ! preg_match('/^[A-Za-z0-9._:-]+$/', $key)) {
            return ApiResponse::error('The Idempotency-Key is invalid.', 422);
        }

        $scope = implode(':', [(string) ($request->user()?->id ?? 'guest'), (string) ($companyId ?? 'global')]);
        $safeInput = $this->fingerprint($request->except(['password', 'password_confirmation', 'token']));
        $requestHash = hash('sha256', json_encode($safeInput, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($request, $operation, $companyId, $action, $key, $scope, $requestHash) {
            $existing = IdempotencyKey::where('scope', $scope)->where('operation', $operation)->where('key', $key)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->request_hash !== $requestHash) {
                    return ApiResponse::error('This idempotency key was already used for a different request.', 409);
                }
                if ($existing->response_status === null) {
                    return ApiResponse::error('The original request is still being processed.', 409);
                }

                return response()->json($existing->response_body, $existing->response_status)->header('Idempotent-Replay', 'true');
            }

            $record = IdempotencyKey::create([
                'company_id' => $companyId,
                'user_id' => $request->user()?->id,
                'key' => $key,
                'operation' => $operation,
                'scope' => $scope,
                'request_hash' => $requestHash,
                'expires_at' => now()->addHours(24),
            ]);

            $response = $action();
            if ($response->getStatusCode() >= 400) {
                $record->delete();

                return $response;
            }

            $body = $response->getData(true);
            $storedBody = $this->sanitizeResponse($body);
            $record->update([
                'response_status' => $response->getStatusCode(),
                'response_body' => $storedBody,
                'response_identity' => data_get($body, 'data.id') ?? (string) Str::uuid(),
            ]);

            return $response;
        });
    }

    private function sanitizeResponse(array $body): array
    {
        $blocked = ['token', 'development_token', 'access_token', 'secret'];
        foreach ($blocked as $key) {
            unset($body[$key]);
        }
        if (isset($body['data']) && is_array($body['data'])) {
            $body['data'] = $this->sanitizeResponse($body['data']);
        }

        return $body;
    }

    private function fingerprint(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            return ['filename' => $value->getClientOriginalName(), 'size' => $value->getSize(), 'mime' => $value->getMimeType(), 'hash' => hash_file('sha256', $value->getRealPath())];
        }
        if (is_array($value)) {
            return array_map(fn ($item) => $this->fingerprint($item), $value);
        }

        return $value;
    }
}
