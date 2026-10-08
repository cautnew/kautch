<?php

namespace App\DTOs\Route;

use App\Enums\HttpMethod;
use App\Enums\RouteDestinationType;
use App\Http\Requests\Route\StoreRouteRequest;
use App\Models\Route;

final readonly class CreateRouteData
{
    public function __construct(
        public string $path,
        public HttpMethod $httpMethod,
        public RouteDestinationType $destinationType,
        public ?string $pageId,
        public ?string $assetId,
        public ?string $redirectToRouteId,
        public ?string $redirectUrl,
        public ?int $httpStatus,
    ) {}

    public static function fromRequest(StoreRouteRequest $request): self
    {
        $destinationType = RouteDestinationType::from($request->string('destination_type')->toString());

        return new self(
            path: Route::normalizePath($request->string('path')->toString()),
            httpMethod: HttpMethod::from($request->filled('http_method') ? $request->string('http_method')->toString() : 'GET'),
            destinationType: $destinationType,
            pageId: $request->filled('page_id') ? $request->string('page_id')->toString() : null,
            assetId: $request->filled('asset_id') ? $request->string('asset_id')->toString() : null,
            redirectToRouteId: $request->filled('redirect_to_route_id') ? $request->string('redirect_to_route_id')->toString() : null,
            redirectUrl: $request->filled('redirect_url') ? $request->string('redirect_url')->toString() : null,
            httpStatus: $request->filled('http_status')
                ? (int) $request->input('http_status')
                : ($destinationType === RouteDestinationType::Redirect ? 301 : 200),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'http_method' => $this->httpMethod,
            'destination_type' => $this->destinationType,
            'page_id' => $this->pageId,
            'asset_id' => $this->assetId,
            'redirect_to_route_id' => $this->redirectToRouteId,
            'redirect_url' => $this->redirectUrl,
            'http_status' => $this->httpStatus,
        ];
    }
}
