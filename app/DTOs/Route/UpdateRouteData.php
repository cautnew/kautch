<?php

namespace App\DTOs\Route;

use App\Enums\HttpMethod;
use App\Http\Requests\Route\UpdateRouteRequest;
use App\Models\Route;

final readonly class UpdateRouteData
{
    public function __construct(
        public ?string $path = null,
        public ?HttpMethod $httpMethod = null,
        public ?int $httpStatus = null,
        public ?string $redirectToRouteId = null,
        public ?string $redirectUrl = null,
    ) {}

    public static function fromRequest(UpdateRouteRequest $request): self
    {
        return new self(
            path: $request->filled('path') ? Route::normalizePath($request->string('path')->toString()) : null,
            httpMethod: $request->filled('http_method') ? HttpMethod::from($request->string('http_method')->toString()) : null,
            httpStatus: $request->filled('http_status') ? (int) $request->input('http_status') : null,
            redirectToRouteId: $request->filled('redirect_to_route_id') ? $request->string('redirect_to_route_id')->toString() : null,
            redirectUrl: $request->filled('redirect_url') ? $request->string('redirect_url')->toString() : null,
        );
    }

    /**
     * Only the attributes that were actually provided. Only a redirect
     * route's target may change here — its destination_type/page_id/asset_id
     * are immutable after creation (create a new route instead).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'path' => $this->path,
            'http_method' => $this->httpMethod,
            'http_status' => $this->httpStatus,
            'redirect_to_route_id' => $this->redirectToRouteId,
            'redirect_url' => $this->redirectUrl,
        ], fn (mixed $value): bool => $value !== null);
    }
}
