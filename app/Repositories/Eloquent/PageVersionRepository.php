<?php

namespace App\Repositories\Eloquent;

use App\Enums\ReviewDecision;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\PageVersionReview;
use App\Repositories\Contracts\PageVersionRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class PageVersionRepository implements PageVersionRepositoryInterface
{
    public function paginateForPage(Page $page, int $perPage = 15): LengthAwarePaginator
    {
        return $page->versions()
            ->select([
                'id', 'page_id', 'layout_id', 'version_number', 'status',
                'created_by', 'updated_by', 'created_at', 'updated_at',
            ])
            ->addSelect([
                'approved_by' => PageVersionReview::query()
                    ->select('qa_user_id')
                    ->whereColumn('page_version_id', 'page_versions.id')
                    ->where('decision', ReviewDecision::Approved)
                    ->latest()
                    ->latest('id')
                    ->limit(1),
            ])
            ->latest('version_number')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Page $page, array $attributes): PageVersion
    {
        return DB::transaction(function () use ($page, $attributes): PageVersion {
            $nextVersionNumber = (int) $page->versions()->lockForUpdate()->max('version_number') + 1;

            return $page->versions()->create([
                ...$attributes,
                'version_number' => $nextVersionNumber,
            ])->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(PageVersion $pageVersion, array $attributes): PageVersion
    {
        $pageVersion->update($attributes);

        return $pageVersion->fresh();
    }

    /**
     * Soft-deletes the version and, explicitly, every piece belonging to it —
     * pieces.page_version_id's cascade FK only fires on a hard DELETE, never
     * on the soft-delete UPDATE both models now perform.
     */
    public function delete(PageVersion $pageVersion): void
    {
        $pageVersion->pieces()->get()->each->delete();

        $pageVersion->delete();
    }
}
