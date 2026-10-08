<?php

namespace App\Http\Resources;

use App\DTOs\Route\RouteResolution;
use App\Enums\RouteResolutionOutcome;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RouteResolution
 */
class RouteResolutionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_filter([
            'outcome' => $this->outcome->value,
            'http_status' => $this->httpStatus,
            'path' => $this->matchedRoute?->path,
            'page' => $this->outcome === RouteResolutionOutcome::Page
                ? new PageResource($this->finalRoute->page)
                : null,
            'asset' => $this->outcome === RouteResolutionOutcome::Asset
                ? new AssetResource($this->finalRoute->asset)
                : null,
            'redirect_to' => match ($this->outcome) {
                RouteResolutionOutcome::Redirect => $this->finalRoute->path,
                RouteResolutionOutcome::ExternalRedirect => $this->finalRoute->redirect_url,
                default => null,
            },
        ], fn (mixed $value): bool => $value !== null);
    }
}
