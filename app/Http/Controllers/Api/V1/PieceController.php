<?php

namespace App\Http\Controllers\Api\V1;

use App\DTOs\Piece\CreatePieceData;
use App\DTOs\Piece\UpdatePieceData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Piece\StorePieceRequest;
use App\Http\Requests\Piece\UpdatePieceRequest;
use App\Http\Resources\PieceResource;
use App\Http\Responses\ApiResponse;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\Piece;
use App\Services\PieceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Authorize;

class PieceController extends Controller
{
    public function __construct(
        private readonly PieceService $pieces,
    ) {}

    /**
     * The full piece tree for the version (root pieces, with every descendant
     * recursively eager-loaded).
     *
     * Piece::class must be given explicitly: passing only the 'version' route
     * param would let Laravel infer the policy from PageVersion's class
     * (PageVersionPolicy) instead of the PiecePolicy::viewAny() we want here.
     */
    #[Authorize('viewAny', [Piece::class, 'version'])]
    public function index(Page $page, PageVersion $version): JsonResponse
    {
        return ApiResponse::success(PieceResource::collection($this->pieces->tree($version)));
    }

    /**
     * Add a new piece to the version's tree (at the root, or nested under a
     * parent via parent_piece_id — only while the version is still editable).
     */
    #[Authorize('create', [Piece::class, 'version'])]
    public function store(StorePieceRequest $request, Page $page, PageVersion $version): JsonResponse
    {
        $piece = $this->pieces->create($version, $request->user(), CreatePieceData::fromRequest($request));

        return ApiResponse::success(new PieceResource($piece), 'Piece created.', 201);
    }

    /**
     * Show a single piece, together with its full (recursively loaded) subtree.
     */
    #[Authorize('view', 'piece')]
    public function show(Piece $piece): JsonResponse
    {
        return ApiResponse::success(new PieceResource($this->pieces->find($piece)));
    }

    /**
     * Update a piece's placement (parent/slot/position) or payload
     * (content/settings/asset). Its type is immutable.
     */
    #[Authorize('update', 'piece')]
    public function update(UpdatePieceRequest $request, Piece $piece): JsonResponse
    {
        $piece = $this->pieces->update($piece, $request->user(), UpdatePieceData::fromRequest($request));

        return ApiResponse::success(new PieceResource($piece), 'Piece updated.');
    }

    /**
     * Delete a piece. Its entire subtree is cascade-deleted with it.
     */
    #[Authorize('delete', 'piece')]
    public function destroy(Request $request, Piece $piece): JsonResponse
    {
        $this->pieces->delete($piece, $request->user());

        return ApiResponse::success(message: 'Piece deleted.');
    }
}
