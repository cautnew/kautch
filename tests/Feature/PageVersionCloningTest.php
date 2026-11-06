<?php

use App\Enums\PageVersionStatus;
use App\Enums\PieceType;
use App\Enums\WebsiteRole;
use App\Events\PageVersionCloned;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Layout;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\Piece;
use App\Models\User;
use App\Models\Website;
use App\Repositories\Contracts\PieceRepositoryInterface;
use App\Services\PageVersionCloningService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

function makePublishedVersion(Website $website, Layout $layout, Page $page, ?User $creator = null): PageVersion
{
    return PageVersion::factory()->published()->create([
        'page_id' => $page->id,
        'layout_id' => $layout->id,
        'seo_snapshot' => ['title' => 'Original title', 'description' => 'Original description'],
        'created_by' => $creator?->id ?? User::factory(),
    ]);
}

// --- pieces: create/update/delete against a published version ---------------------------------

it('creating a piece against a published version clones it into a new draft and creates the piece there', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = makePublishedVersion($website, $layout, $page, $owner);
    $existingPiece = Piece::factory()->paragraph()->create(['page_version_id' => $version->id]);

    $response = $this->actingAs($owner)->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), [
        'type' => 'heading',
        'content' => ['level' => 1, 'text' => 'New heading'],
    ]);

    $response->assertCreated();
    $newVersionId = $response->json('data.page_version_id');

    expect($newVersionId)->not->toBe($version->id);
    $draft = PageVersion::find($newVersionId);
    expect($draft->status)->toBe(PageVersionStatus::Draft);
    expect($draft->cloned_from_id)->toBe($version->id);
    expect($draft->version_number)->toBe($version->version_number + 1);

    // The draft has both the cloned pre-existing piece AND the new one.
    expect(Piece::where('page_version_id', $draft->id)->count())->toBe(2);

    // The published version is completely untouched.
    expect($version->fresh()->status)->toBe(PageVersionStatus::Published);
    expect(Piece::where('page_version_id', $version->id)->count())->toBe(1);
    expect(Piece::find($existingPiece->id)->page_version_id)->toBe($version->id);
});

it('creating a nested piece against a published version remaps parent_piece_id to the clone', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = makePublishedVersion($website, $layout, $page, $owner);
    $wrapper = Piece::factory()->twoColumnsWrapper()->create(['page_version_id' => $version->id]);

    $response = $this->actingAs($owner)->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), [
        'type' => 'paragraph',
        'parent_piece_id' => $wrapper->id,
        'slot' => 'left',
        'content' => ['text' => 'Nested'],
    ]);

    $response->assertCreated();
    $newParentId = $response->json('data.parent_piece_id');

    expect($newParentId)->not->toBe($wrapper->id);
    $clonedWrapper = Piece::find($newParentId);
    expect($clonedWrapper->type)->toBe(PieceType::TwoColumnsWrapper);
    expect($clonedWrapper->page_version_id)->not->toBe($version->id);

    // The original wrapper on the published version has no children.
    expect(Piece::where('parent_piece_id', $wrapper->id)->count())->toBe(0);
});

it('updating a piece on a published version clones it and updates only the clone', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = makePublishedVersion($website, $layout, $page, $owner);
    $piece = Piece::factory()->paragraph()->create(['page_version_id' => $version->id, 'content' => ['text' => 'Original']]);

    $response = $this->actingAs($owner)->putJson(route('api.v1.pieces.update', $piece), [
        'content' => ['text' => 'Changed'],
    ]);

    $response->assertSuccessful()->assertJsonPath('data.content.text', 'Changed');

    $clonedPieceId = $response->json('data.id');
    expect($clonedPieceId)->not->toBe($piece->id);
    expect($response->json('data.page_version_id'))->not->toBe($version->id);

    // The original piece (still on the published version) is untouched.
    expect($piece->fresh()->content['text'])->toBe('Original');
    expect($version->fresh()->status)->toBe(PageVersionStatus::Published);
});

it('reparenting a piece on a published version remaps both the piece and its new parent/asset to the clone', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = makePublishedVersion($website, $layout, $page, $owner);
    $wrapper = Piece::factory()->twoColumnsWrapper()->create(['page_version_id' => $version->id]);
    // A page-version-owned asset — this one *does* get cloned along with the version.
    $asset = Asset::factory()->forPageVersion($version)->create();
    $piece = Piece::factory()->image()->create(['page_version_id' => $version->id]);

    $response = $this->actingAs($owner)->putJson(route('api.v1.pieces.update', $piece), [
        'parent_piece_id' => $wrapper->id,
        'slot' => 'left',
        'asset_id' => $asset->id,
    ]);

    $response->assertSuccessful();
    $clonedParentId = $response->json('data.parent_piece_id');
    $clonedAssetId = $response->json('data.asset_id');

    // Both references were remapped to their equivalents on the new draft,
    // not left pointing at the original (published-version) rows.
    expect($clonedParentId)->not->toBe($wrapper->id);
    expect(Piece::find($clonedParentId)->page_version_id)->not->toBe($version->id);
    expect($clonedAssetId)->not->toBe($asset->id);
    expect(Asset::find($clonedAssetId)->page_version_id)->not->toBe($version->id);

    // The original piece is untouched.
    expect($piece->fresh()->parent_piece_id)->toBeNull();
});

it('deleting a piece on a published version clones it and deletes only the clone', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = makePublishedVersion($website, $layout, $page, $owner);
    $piece = Piece::factory()->paragraph()->create(['page_version_id' => $version->id]);

    $this->actingAs($owner)
        ->deleteJson(route('api.v1.pieces.destroy', $piece))
        ->assertSuccessful();

    // The original piece (still on the published version) survives.
    expect(Piece::find($piece->id))->not->toBeNull();
    expect(Piece::where('page_version_id', $version->id)->count())->toBe(1);

    $draft = PageVersion::where('cloned_from_id', $version->id)->sole();
    expect($draft->status)->toBe(PageVersionStatus::Draft);
    expect(Piece::where('page_version_id', $draft->id)->count())->toBe(0);
});

it('clones a multi-level tree preserving structure, slots and order', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = makePublishedVersion($website, $layout, $page, $owner);

    $outer = Piece::factory()->twoColumnsWrapper()->create(['page_version_id' => $version->id, 'position' => 0]);
    $inner = Piece::factory()->twoColumnsWrapper()->childOf($outer, 'left')->create();
    $leafA = Piece::factory()->paragraph()->childOf($inner, 'left')->create(['content' => ['text' => 'A']]);
    $leafB = Piece::factory()->heading()->childOf($outer, 'right')->create();

    $target = Piece::factory()->paragraph()->create(['page_version_id' => $version->id, 'position' => 1]);

    $this->actingAs($owner)->putJson(route('api.v1.pieces.update', $target), [
        'content' => ['text' => 'trigger clone'],
    ])->assertSuccessful();

    $draft = PageVersion::where('cloned_from_id', $version->id)->sole();
    expect(Piece::where('page_version_id', $draft->id)->count())->toBe(5);

    $clonedOuter = Piece::where('page_version_id', $draft->id)->whereNull('parent_piece_id')
        ->where('type', PieceType::TwoColumnsWrapper->value)->sole();
    $clonedInner = Piece::where('parent_piece_id', $clonedOuter->id)->where('slot', 'left')->sole();
    $clonedLeafA = Piece::where('parent_piece_id', $clonedInner->id)->sole();
    $clonedLeafB = Piece::where('parent_piece_id', $clonedOuter->id)->where('slot', 'right')->sole();

    expect($clonedInner->type)->toBe(PieceType::TwoColumnsWrapper);
    expect($clonedLeafA->content['text'])->toBe('A');
    expect($clonedLeafB->type)->toBe(PieceType::Heading);

    // The original published tree is fully intact.
    expect(Piece::where('page_version_id', $version->id)->count())->toBe(5);
    expect(Piece::find($outer->id))->not->toBeNull();
    expect(Piece::find($leafA->id)->content['text'])->toBe('A');
    expect(Piece::find($leafB->id))->not->toBeNull();
});

// --- seo / layout ------------------------------------------------------------------------------

it('updating seo on a published version clones it and leaves the published seo untouched', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = makePublishedVersion($website, $layout, $page, $owner);

    $response = $this->actingAs($owner)->putJson(route('api.v1.versions.update', ['page' => $version->page_id, 'version' => $version]), [
        'seo' => ['title' => 'New title', 'description' => 'New description'],
    ]);

    $response->assertSuccessful()->assertJsonPath('data.seo_snapshot.title', 'New title');

    $newVersionId = $response->json('data.id');
    expect($newVersionId)->not->toBe($version->id);
    expect($response->json('data.cloned_from_id'))->toBe($version->id);

    expect($version->fresh()->seo_snapshot['title'])->toBe('Original title');
});

it('updating the layout on a published version clones it and regenerates the layout_snapshot', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $layout = Layout::factory()->create(['website_id' => $website->id, 'schema' => ['blocks' => ['old']]]);
    $newLayout = Layout::factory()->create(['website_id' => $website->id, 'schema' => ['blocks' => ['new']]]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = makePublishedVersion($website, $layout, $page, $owner);

    $response = $this->actingAs($owner)->putJson(route('api.v1.versions.update', ['page' => $version->page_id, 'version' => $version]), [
        'layout_id' => $newLayout->id,
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('data.layout_id', $newLayout->id)
        ->assertJsonPath('data.layout_snapshot.schema.blocks.0', 'new');

    expect($version->fresh()->layout_id)->toBe($layout->id);
    expect($version->fresh()->layout_snapshot['schema'])->toBe(['blocks' => ['old']]);
});

it('rejects seo/layout updates while a version is under_review or approved (no clone happens)', function (PageVersionStatus $status) {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->create([
        'page_id' => $page->id,
        'layout_id' => $layout->id,
        'status' => $status,
    ]);
    $countBefore = PageVersion::count();

    $this->actingAs($owner)
        ->putJson(route('api.v1.versions.update', ['page' => $version->page_id, 'version' => $version]), ['seo' => ['title' => 'Nope']])
        ->assertForbidden();

    expect(PageVersion::count())->toBe($countBefore);
})->with([
    'under_review' => [PageVersionStatus::UnderReview],
    'approved' => [PageVersionStatus::Approved],
]);

// --- multiple independent clones ----------------------------------------------------------------

it('produces a separate new draft for each independent edit against the same published version', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = makePublishedVersion($website, $layout, $page, $owner);
    $piece = Piece::factory()->paragraph()->create(['page_version_id' => $version->id, 'content' => ['text' => 'Original']]);

    $firstDraftId = $this->actingAs($owner)->putJson(route('api.v1.pieces.update', $piece), [
        'content' => ['text' => 'Edit A'],
    ])->json('data.page_version_id');

    $secondDraftId = $this->actingAs($owner)->putJson(route('api.v1.pieces.update', $piece), [
        'content' => ['text' => 'Edit B'],
    ])->json('data.page_version_id');

    expect($firstDraftId)->not->toBe($secondDraftId);
    expect(PageVersion::where('cloned_from_id', $version->id)->count())->toBe(2);

    // Each draft's cloned piece independently carries only its own edit — the
    // second clone was built fresh from the untouched published source, not
    // from the first draft.
    $firstDraftPiece = Piece::where('page_version_id', $firstDraftId)->sole();
    $secondDraftPiece = Piece::where('page_version_id', $secondDraftId)->sole();
    expect($firstDraftPiece->content['text'])->toBe('Edit A');
    expect($secondDraftPiece->content['text'])->toBe('Edit B');

    expect($piece->fresh()->content['text'])->toBe('Original');
});

// --- assets (full round trip through a real upload) ---------------------------------------------

it('cloning a version duplicates its page-version-owned assets onto the new draft', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = makePublishedVersion($website, $layout, $page, $owner);
    $asset = Asset::factory()->forPageVersion($version)->create([
        'created_by' => $owner->id,
        'path' => 'assets/original.jpg',
        'disk' => 'public',
    ]);
    $piece = Piece::factory()->paragraph()->create(['page_version_id' => $version->id]);

    $this->actingAs($owner)
        ->putJson(route('api.v1.pieces.update', $piece), ['content' => ['text' => 'trigger']])
        ->assertSuccessful();

    $draft = PageVersion::where('cloned_from_id', $version->id)->sole();
    $clonedAsset = Asset::where('page_version_id', $draft->id)->sole();

    expect($clonedAsset->id)->not->toBe($asset->id);
    expect($clonedAsset->path)->toBe('assets/original.jpg');
    expect($clonedAsset->disk)->toBe('public');
    expect($clonedAsset->created_by)->toBe($owner->id);

    // The original asset is still attached to the published version.
    expect($asset->fresh()->page_version_id)->toBe($version->id);
});

// --- event & audit log ---------------------------------------------------------------------------

it('dispatches PageVersionCloned when a clone happens', function () {
    Event::fake([PageVersionCloned::class]);

    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = makePublishedVersion($website, $layout, $page, $owner);
    $piece = Piece::factory()->paragraph()->create(['page_version_id' => $version->id]);

    $this->actingAs($owner)
        ->putJson(route('api.v1.pieces.update', $piece), ['content' => ['text' => 'x']])
        ->assertSuccessful();

    Event::assertDispatched(PageVersionCloned::class, function (PageVersionCloned $event) use ($version, $owner) {
        return $event->source->id === $version->id
            && $event->draft->cloned_from_id === $version->id
            && $event->actor->id === $owner->id;
    });
});

it('records an audit log entry when a clone happens', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = makePublishedVersion($website, $layout, $page, $owner);
    $piece = Piece::factory()->paragraph()->create(['page_version_id' => $version->id]);

    $response = $this->actingAs($owner)
        ->putJson(route('api.v1.pieces.update', $piece), ['content' => ['text' => 'x']]);

    $draftId = $response->json('data.page_version_id');

    $log = AuditLog::where('action', 'version_cloned')
        ->where('auditable_type', PageVersion::class)
        ->where('auditable_id', $draftId)
        ->sole();

    expect($log->website_id)->toBe($website->id);
    expect($log->user_id)->toBe($owner->id);
    expect($log->old_values['source_version_id'])->toBe($version->id);
    expect($log->new_values['draft_version_id'])->toBe($draftId);
});

// --- transactional integrity ----------------------------------------------------------------------

it('rolls back the entire clone if any part of it fails', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = makePublishedVersion($website, $layout, $page, $owner);
    Piece::factory()->paragraph()->create(['page_version_id' => $version->id]);

    $versionCountBefore = PageVersion::count();
    $pieceCountBefore = Piece::count();

    $this->mock(PieceRepositoryInterface::class, function ($mock) {
        $mock->shouldReceive('create')->once()->andThrow(new RuntimeException('boom'));
    });

    $service = app(PageVersionCloningService::class);

    expect(fn () => $service->cloneAsDraft($version, $owner))->toThrow(RuntimeException::class, 'boom');

    expect(PageVersion::count())->toBe($versionCountBefore);
    expect(Piece::count())->toBe($pieceCountBefore);
});

// --- lineage ---------------------------------------------------------------------------------------

it('leaves cloned_from_id null for versions that were not produced by cloning', function () {
    $website = Website::factory()->create();
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);

    expect($version->cloned_from_id)->toBeNull();
});
