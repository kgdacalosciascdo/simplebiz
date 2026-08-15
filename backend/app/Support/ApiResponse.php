<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    public static function success(mixed $data = null, int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => $meta,
        ], $status);
    }

    public static function error(string $message, int $status, array $errors = []): JsonResponse
    {
        $meta = [];
        $correlationId = request()?->attributes->get('correlation_id');
        if ($correlationId) {
            $meta['correlation_id'] = $correlationId;
        }

        return response()->json([
            'message' => $message,
            'errors' => $errors,
            'meta' => $meta,
        ], $status);
    }
}
