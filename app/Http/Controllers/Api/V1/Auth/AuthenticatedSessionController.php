<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\DTOs\Auth\LoginData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\AuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthenticatedSessionController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $authentication,
    ) {}

    /**
     * Log the user in and issue a new API token.
     *
     * @response array{success: bool, message: string, data: array{user: UserResource, token: string, token_type: string}}
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $result = $this->authentication->login(LoginData::fromRequest($request));

        return ApiResponse::success([
            'user' => new UserResource($result['user']),
            'token' => $result['token'],
            'token_type' => 'Bearer',
        ], 'Login successful.');
    }

    /**
     * Log the user out by revoking the token used for this request.
     */
    public function destroy(Request $request): JsonResponse
    {
        $this->authentication->logout($request->user());

        return ApiResponse::success(message: 'Logout successful.');
    }
}
