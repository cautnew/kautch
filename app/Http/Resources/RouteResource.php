<?php

namespace App\Http\Resources;

use App\Models\Route;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Route
 */
class RouteResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'website_id' => $this->website_id,
            'path' => $this->path,
            'http_method' => $this->http_method->value,
            'http_status' => $this->http_status,
            'destination_type' => $this->destination_type->value,
            'page_id' => $this->page_id,
            'asset_id' => $this->asset_id,
            'redirect_to_route_id' => $this->redirect_to_route_id,
            'redirect_url' => $this->redirect_url,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
