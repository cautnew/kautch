<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * Builds the application's single consistent JSON envelope for every API response:
 *
 *   { "success": true,  "message": "...", "data": {...} }
 *   { "success": false, "message": "...", "errors": {...} }
 */
final class ApiResponse
{
    public static function success(mixed $data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        if ($data === null) {
            return response()->json([
                'success' => true,
                'message' => $message,
            ], $status);
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    /**
     * @param  array<string, array<int, string>>|null  $errors
     */
    public static function error(string $message, ?array $errors = null, int $status = 422): JsonResponse
    {
        return response()->json(array_filter([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], fn (mixed $value): bool => $value !== null), $status);
    }
}
