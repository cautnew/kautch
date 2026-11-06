<?php

namespace App\Http\Resources;

use App\Models\PageVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PageVersion
 *
 * @property-read string|null $approved_by
 */
class PageVersionListResource extends JsonResource
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
            'page_id' => $this->page_id,
            'layout_id' => $this->layout_id,
            'version_number' => $this->version_number,
            'status' => $this->status->value,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'approved_by' => $this->approved_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
