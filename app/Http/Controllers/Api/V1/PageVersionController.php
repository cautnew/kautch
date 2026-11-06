<?php

namespace App\Http\Controllers\Api\V1;

use App\DTOs\PageVersion\ApprovePageVersionData;
use App\DTOs\PageVersion\CreatePageVersionData;
use App\DTOs\PageVersion\RejectPageVersionData;
use App\DTOs\PageVersion\UpdatePageVersionData;
use App\Http\Controllers\Controller;
use App\Http\Requests\PageVersion\ApprovePageVersionRequest;
use App\Http\Requests\PageVersion\RejectPageVersionRequest;
use App\Http\Requests\PageVersion\StorePageVersionRequest;
use App\Http\Requests\PageVersion\UpdatePageVersionRequest;
use App\Http\Resources\PageVersionListResource;
use App\Http\Resources\PageVersionResource;
use App\Http\Responses\ApiResponse;
use App\Models\Page;
use App\Models\PageVersion;
use App\Services\PageVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Authorize;

class PageVersionController extends Controller
{
    public function __construct(
        private readonly PageVersionService $versions,
    ) {}

    /**
     * List the page's versions.
     *
     * PageVersion::class must be given explicitly: passing only the 'page' route
     * param would let Laravel infer the policy from Page's class (PagePolicy)
     * instead of the PageVersionPolicy::viewAny() we actually want here.
     */
    #[Authorize('viewAny', [PageVersion::class, 'page'])]
    public function index(Page $page): JsonResponse
    {
        return ApiResponse::success(PageVersionListResource::collection($this->versions->listForPage($page)));
    }

    /**
     * Create a new draft version for the page.
     */
    #[Authorize('create', [PageVersion::class, 'page'])]
    public function store(StorePageVersionRequest $request, Page $page): JsonResponse
    {
        $version = $this->versions->create($page, $request->user(), CreatePageVersionData::fromRequest($request));

        return ApiResponse::success(new PageVersionResource($version), 'Page version created.', 201);
    }

    /**
     * Show a single page version.
     */
    #[Authorize('view', 'version')]
    public function show(Page $page, PageVersion $version): JsonResponse
    {
        return ApiResponse::success(new PageVersionResource($version));
    }

    /**
     * Update a version's seo snapshot and/or layout. If the version is
     * published, the change transparently lands on an auto-cloned draft
     * instead of the published version itself.
     */
    #[Authorize('update', 'version')]
    public function update(UpdatePageVersionRequest $request, Page $page, PageVersion $version): JsonResponse
    {
        $version = $this->versions->update($version, $request->user(), UpdatePageVersionData::fromRequest($request));

        return ApiResponse::success(new PageVersionResource($version), 'Page version updated.');
    }

    /**
     * Delete (soft-delete) a page version. Published versions can never be deleted.
     */
    #[Authorize('delete', 'version')]
    public function destroy(Page $page, PageVersion $version): JsonResponse
    {
        $this->versions->delete($version);

        return ApiResponse::success(message: 'Page version deleted.');
    }

    /**
     * Submit a draft (or a previously rejected version) for QA review.
     */
    #[Authorize('submitForReview', 'version')]
    public function submitReview(Request $request, Page $page, PageVersion $version): JsonResponse
    {
        $version = $this->versions->submitForReview($version);

        return ApiResponse::success(new PageVersionResource($version), 'Page version submitted for review.');
    }

    /**
     * QA approves a version under review, clearing it for publication.
     */
    #[Authorize('approve', 'version')]
    public function approve(ApprovePageVersionRequest $request, Page $page, PageVersion $version): JsonResponse
    {
        $data = ApprovePageVersionData::fromRequest($request);
        $version = $this->versions->approve($version, $request->user(), $data->notes);

        return ApiResponse::success(new PageVersionResource($version), 'Page version approved.');
    }

    /**
     * QA rejects a version under review, sending it back for edits.
     */
    #[Authorize('reject', 'version')]
    public function reject(RejectPageVersionRequest $request, Page $page, PageVersion $version): JsonResponse
    {
        $data = RejectPageVersionData::fromRequest($request);
        $version = $this->versions->reject($version, $request->user(), $data->notes);

        return ApiResponse::success(new PageVersionResource($version), 'Page version rejected.');
    }

    /**
     * Publish an approved version. Editors can never publish, only admin/owner.
     */
    #[Authorize('publish', 'version')]
    public function publish(Request $request, Page $page, PageVersion $version): JsonResponse
    {
        $version = $this->versions->publish($version, $request->user());

        return ApiResponse::success(new PageVersionResource($version), 'Page version published.');
    }
}
