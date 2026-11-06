<?php

use App\Enums\PageVersionStatus;
use App\Enums\WebsiteRole;
use App\Models\Asset;
use App\Models\Layout;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\Piece;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\DB;

function makeEditableVersion(Website $website): PageVersion
{
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);

    return PageVersion::factory()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);
}

it('rejects unauthenticated access to every endpoint', function () {
    $website = Website::factory()->create();
    $version = makeEditableVersion($website);
    $piece = Piece::factory()->create(['page_version_id' => $version->id]);

    $this->getJson(route('api.v1.versions.pieces.index', ['page' => $version->page_id, 'version' => $version]))->assertUnauthorized();
    $this->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), [])->assertUnauthorized();
    $this->getJson(route('api.v1.pieces.show', $piece))->assertUnauthorized();
    $this->putJson(route('api.v1.pieces.update', $piece), [])->assertUnauthorized();
    $this->deleteJson(route('api.v1.pieces.destroy', $piece))->assertUnauthorized();
});

it('lets any member view the tree and a single piece but denies outsiders', function () {
    $website = Website::factory()->create();
    $viewer = User::factory()->create();
    createWebsiteMembership($website, $viewer, WebsiteRole::Viewer);
    $outsider = User::factory()->create();
    $version = makeEditableVersion($website);
    $piece = Piece::factory()->create(['page_version_id' => $version->id]);

    $this->actingAs($viewer)->getJson(route('api.v1.versions.pieces.index', ['page' => $version->page_id, 'version' => $version]))->assertSuccessful();
    $this->actingAs($viewer)->getJson(route('api.v1.pieces.show', $piece))->assertSuccessful();

    $this->actingAs($outsider)->getJson(route('api.v1.versions.pieces.index', ['page' => $version->page_id, 'version' => $version]))->assertForbidden();
    $this->actingAs($outsider)->getJson(route('api.v1.pieces.show', $piece))->assertForbidden();
});

// --- create: type-specific content validation ------------------------------------------------

it('creates a root heading piece', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);

    $response = $this->actingAs($owner)->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), [
        'type' => 'heading',
        'content' => ['level' => 2, 'text' => 'Welcome'],
    ]);

    $response->assertCreated()
        ->assertJson(['success' => true, 'message' => 'Piece created.'])
        ->assertJsonPath('data.type', 'heading')
        ->assertJsonPath('data.content.text', 'Welcome')
        ->assertJsonPath('data.is_root', true)
        ->assertJsonPath('data.position', 0);
});

it('validates heading requires level and text', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);

    $this->actingAs($owner)
        ->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), ['type' => 'heading', 'content' => []])
        ->assertUnprocessable()
        ->assertJson(['success' => false])
        ->assertJsonValidationErrors(['content.level', 'content.text']);
});

it('validates paragraph requires text', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);

    $this->actingAs($owner)
        ->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), ['type' => 'paragraph', 'content' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['content.text']);
});

it('validates list requires a non-empty items array', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);

    $this->actingAs($owner)
        ->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), ['type' => 'list', 'content' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['content.items']);

    $this->actingAs($owner)
        ->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), [
            'type' => 'list',
            'content' => ['items' => ['One', 'Two']],
        ])
        ->assertCreated()
        ->assertJsonPath('data.content.items', ['One', 'Two']);
});

it('requires an asset_id for image pieces and validates it belongs to the website', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);

    $this->actingAs($owner)
        ->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), ['type' => 'image'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['asset_id']);

    $otherWebsite = Website::factory()->create();
    $foreignAsset = Asset::factory()->create(['website_id' => $otherWebsite->id]);

    $this->actingAs($owner)
        ->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), [
            'type' => 'image',
            'asset_id' => $foreignAsset->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['asset_id']);

    $asset = Asset::factory()->create(['website_id' => $website->id]);

    $this->actingAs($owner)
        ->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), [
            'type' => 'image',
            'asset_id' => $asset->id,
            'content' => ['caption' => 'A picture'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.asset_id', $asset->id);
});

it('creates container pieces (two_columns_wrapper, text_image_wrapper) without content requirements', function (string $type) {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);

    $this->actingAs($owner)
        ->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), ['type' => $type])
        ->assertCreated()
        ->assertJsonPath('data.type', $type);
})->with([
    'two_columns_wrapper' => ['two_columns_wrapper'],
    'text_image_wrapper' => ['text_image_wrapper'],
]);

// --- tree structure: parent/slot rules -----------------------------------------------------

it('nests a child under a two_columns_wrapper using left/right slots', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);
    $wrapper = Piece::factory()->twoColumnsWrapper()->create(['page_version_id' => $version->id]);

    $this->actingAs($owner)
        ->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), [
            'type' => 'paragraph',
            'parent_piece_id' => $wrapper->id,
            'slot' => 'left',
            'content' => ['text' => 'Left column'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.slot', 'left')
        ->assertJsonPath('data.parent_piece_id', $wrapper->id);
});

it('nests a child under a text_image_wrapper using text/image slots', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);
    $wrapper = Piece::factory()->textImageWrapper()->create(['page_version_id' => $version->id]);

    $this->actingAs($owner)
        ->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), [
            'type' => 'paragraph',
            'parent_piece_id' => $wrapper->id,
            'slot' => 'text',
            'content' => ['text' => 'Caption text'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.slot', 'text');
});

it('rejects a slot outside the parent container allowed slots', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);
    $wrapper = Piece::factory()->twoColumnsWrapper()->create(['page_version_id' => $version->id]);

    $this->actingAs($owner)
        ->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), [
            'type' => 'paragraph',
            'parent_piece_id' => $wrapper->id,
            'slot' => 'center',
            'content' => ['text' => 'Nope'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['slot']);
});

it('rejects nesting a piece under a leaf type (non-container) parent', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);
    $leaf = Piece::factory()->paragraph()->create(['page_version_id' => $version->id]);

    $this->actingAs($owner)
        ->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), [
            'type' => 'paragraph',
            'parent_piece_id' => $leaf->id,
            'slot' => 'left',
            'content' => ['text' => 'Nope'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['parent_piece_id']);
});

it('rejects a slot on a root-level piece', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);

    $this->actingAs($owner)
        ->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), [
            'type' => 'paragraph',
            'slot' => 'left',
            'content' => ['text' => 'Nope'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['slot']);
});

it('creates a root piece assigned to a region declared by the layout', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);

    $this->actingAs($owner)
        ->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), [
            'type' => 'paragraph',
            'region' => 'header',
            'content' => ['text' => 'In the header'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.region', 'header');
});

it('rejects a region the layout does not declare', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);

    $this->actingAs($owner)
        ->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), [
            'type' => 'paragraph',
            'region' => 'not-a-real-region',
            'content' => ['text' => 'Nope'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['region']);
});

it('rejects a region on a non-root piece', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);
    $wrapper = Piece::factory()->twoColumnsWrapper()->create(['page_version_id' => $version->id]);

    $this->actingAs($owner)
        ->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), [
            'type' => 'paragraph',
            'parent_piece_id' => $wrapper->id,
            'slot' => 'left',
            'region' => 'header',
            'content' => ['text' => 'Nope'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['region']);
});

it('rejects a parent_piece_id belonging to a different page version', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);
    $otherVersion = makeEditableVersion($website);
    $foreignWrapper = Piece::factory()->twoColumnsWrapper()->create(['page_version_id' => $otherVersion->id]);

    $this->actingAs($owner)
        ->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), [
            'type' => 'paragraph',
            'parent_piece_id' => $foreignWrapper->id,
            'slot' => 'left',
            'content' => ['text' => 'Nope'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['parent_piece_id']);
});

it('auto-increments sibling position when not provided, independently per parent', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);

    $first = $this->actingAs($owner)->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), [
        'type' => 'paragraph',
        'content' => ['text' => 'First'],
    ])->assertJsonPath('data.position', 0);

    $this->actingAs($owner)->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), [
        'type' => 'paragraph',
        'content' => ['text' => 'Second'],
    ])->assertJsonPath('data.position', 1);

    $wrapper = Piece::factory()->twoColumnsWrapper()->create(['page_version_id' => $version->id]);

    $this->actingAs($owner)->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), [
        'type' => 'paragraph',
        'parent_piece_id' => $wrapper->id,
        'slot' => 'left',
        'content' => ['text' => 'Nested first'],
    ])->assertJsonPath('data.position', 0);
});

it('honors an explicit position when provided', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);

    $this->actingAs($owner)
        ->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), [
            'type' => 'paragraph',
            'content' => ['text' => 'Pinned'],
            'position' => 42,
        ])
        ->assertJsonPath('data.position', 42);
});

// --- recursive eager loading -----------------------------------------------------------------

it('returns the full recursive tree for a version in a single pieces query', function () {
    $website = Website::factory()->create();
    $viewer = User::factory()->create();
    createWebsiteMembership($website, $viewer, WebsiteRole::Viewer);
    $version = makeEditableVersion($website);

    $outerWrapper = Piece::factory()->twoColumnsWrapper()->create(['page_version_id' => $version->id, 'position' => 0]);
    $innerWrapper = Piece::factory()->twoColumnsWrapper()->childOf($outerWrapper, 'left')->create();
    $leafUnderInner = Piece::factory()->paragraph()->childOf($innerWrapper, 'left')->create();
    $leafUnderOuter = Piece::factory()->heading()->childOf($outerWrapper, 'right')->create();

    DB::enableQueryLog();

    $response = $this->actingAs($viewer)->getJson(route('api.v1.versions.pieces.index', ['page' => $version->page_id, 'version' => $version]));

    $pieceQueries = collect(DB::getQueryLog())
        ->filter(fn (array $entry) => str_contains($entry['query'], 'pieces'));
    DB::flushQueryLog();

    $response->assertSuccessful();
    expect($pieceQueries)->toHaveCount(1);

    $tree = $response->json('data');
    expect($tree)->toHaveCount(1);
    expect($tree[0]['id'])->toBe($outerWrapper->id);
    expect($tree[0]['children'])->toHaveCount(2);

    $left = collect($tree[0]['children'])->firstWhere('slot', 'left');
    expect($left['id'])->toBe($innerWrapper->id);
    expect($left['children'])->toHaveCount(1);
    expect($left['children'][0]['id'])->toBe($leafUnderInner->id);

    $right = collect($tree[0]['children'])->firstWhere('slot', 'right');
    expect($right['id'])->toBe($leafUnderOuter->id);
});

it('shows a single piece together with its full recursive subtree', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);

    $wrapper = Piece::factory()->twoColumnsWrapper()->create(['page_version_id' => $version->id]);
    $child = Piece::factory()->paragraph()->childOf($wrapper, 'left')->create();
    $grandchild = Piece::factory()->heading()->childOf($child, null)->create();

    // $child is a leaf type, so nesting under it should never have been allowed via the API,
    // but the model layer itself doesn't forbid it — verify the resource still walks whatever
    // tree actually exists in the database.
    $response = $this->actingAs($owner)->getJson(route('api.v1.pieces.show', $wrapper));

    $response->assertSuccessful();
    $data = $response->json('data');
    expect($data['id'])->toBe($wrapper->id);
    expect($data['children'][0]['id'])->toBe($child->id);
    expect($data['children'][0]['children'][0]['id'])->toBe($grandchild->id);
});

// --- update -----------------------------------------------------------------------------------

it('updates a piece content, settings and position', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);
    $piece = Piece::factory()->paragraph()->create(['page_version_id' => $version->id]);

    $response = $this->actingAs($owner)->putJson(route('api.v1.pieces.update', $piece), [
        'content' => ['text' => 'Updated text'],
        'settings' => ['align' => 'center'],
        'position' => 5,
    ]);

    $response->assertSuccessful()
        ->assertJson(['success' => true, 'message' => 'Piece updated.'])
        ->assertJsonPath('data.content.text', 'Updated text')
        ->assertJsonPath('data.settings.align', 'center')
        ->assertJsonPath('data.position', 5);
});

it('updates a root piece to a region declared by the layout', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);
    $piece = Piece::factory()->paragraph()->create(['page_version_id' => $version->id]);

    $this->actingAs($owner)
        ->putJson(route('api.v1.pieces.update', $piece), ['region' => 'footer'])
        ->assertSuccessful()
        ->assertJsonPath('data.region', 'footer');
});

it('rejects updating a root piece to a region the layout does not declare', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);
    $piece = Piece::factory()->paragraph()->create(['page_version_id' => $version->id]);

    $this->actingAs($owner)
        ->putJson(route('api.v1.pieces.update', $piece), ['region' => 'not-a-real-region'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['region']);
});

it('rejects assigning a region while reparenting a piece into a container', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);
    $wrapper = Piece::factory()->twoColumnsWrapper()->create(['page_version_id' => $version->id]);
    $piece = Piece::factory()->paragraph()->create(['page_version_id' => $version->id, 'region' => 'header']);

    $this->actingAs($owner)
        ->putJson(route('api.v1.pieces.update', $piece), [
            'parent_piece_id' => $wrapper->id,
            'slot' => 'left',
            'region' => 'header',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['region']);
});

it('reparents a piece into a different container and slot', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);
    $wrapper = Piece::factory()->twoColumnsWrapper()->create(['page_version_id' => $version->id]);
    $piece = Piece::factory()->paragraph()->create(['page_version_id' => $version->id]);

    $this->actingAs($owner)
        ->putJson(route('api.v1.pieces.update', $piece), [
            'parent_piece_id' => $wrapper->id,
            'slot' => 'right',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.parent_piece_id', $wrapper->id)
        ->assertJsonPath('data.slot', 'right');
});

it('rejects reparenting a piece under one of its own descendants (cycle prevention)', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);

    $grandparent = Piece::factory()->twoColumnsWrapper()->create(['page_version_id' => $version->id]);
    $parent = Piece::factory()->twoColumnsWrapper()->childOf($grandparent, 'left')->create();

    $this->actingAs($owner)
        ->putJson(route('api.v1.pieces.update', $grandparent), ['parent_piece_id' => $parent->id, 'slot' => 'left'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['parent_piece_id']);
});

it('rejects reparenting a piece under itself', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);
    $wrapper = Piece::factory()->twoColumnsWrapper()->create(['page_version_id' => $version->id]);

    $this->actingAs($owner)
        ->putJson(route('api.v1.pieces.update', $wrapper), ['parent_piece_id' => $wrapper->id, 'slot' => 'left'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['parent_piece_id']);
});

it('only allows owner, admin and editor to create, update and delete pieces', function (WebsiteRole $role, bool $allowed) {
    $website = Website::factory()->create();
    $actor = User::factory()->create();
    createWebsiteMembership($website, $actor, $role);
    $version = makeEditableVersion($website);

    $createResponse = $this->actingAs($actor)->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), [
        'type' => 'paragraph',
        'content' => ['text' => 'Hello'],
    ]);
    $allowed ? $createResponse->assertCreated() : $createResponse->assertForbidden();

    $piece = Piece::factory()->paragraph()->create(['page_version_id' => $version->id]);

    $updateResponse = $this->actingAs($actor)->putJson(route('api.v1.pieces.update', $piece), [
        'content' => ['text' => 'Changed'],
    ]);
    $allowed ? $updateResponse->assertSuccessful() : $updateResponse->assertForbidden();

    $deleteResponse = $this->actingAs($actor)->deleteJson(route('api.v1.pieces.destroy', $piece));
    $allowed ? $deleteResponse->assertSuccessful() : $deleteResponse->assertForbidden();
})->with([
    'owner' => [WebsiteRole::Owner, true],
    'admin' => [WebsiteRole::Admin, true],
    'editor' => [WebsiteRole::Editor, true],
    'qa' => [WebsiteRole::Qa, false],
    'viewer' => [WebsiteRole::Viewer, false],
]);

it('never allows mutating pieces once the page version is no longer editable', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);
    $version->asActor($version->created_by)->update(['status' => PageVersionStatus::UnderReview]);
    $piece = Piece::factory()->paragraph()->create(['page_version_id' => $version->id]);

    $this->actingAs($owner)
        ->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), ['type' => 'paragraph', 'content' => ['text' => 'x']])
        ->assertForbidden();

    $this->actingAs($owner)
        ->putJson(route('api.v1.pieces.update', $piece), ['content' => ['text' => 'x']])
        ->assertForbidden();

    $this->actingAs($owner)
        ->deleteJson(route('api.v1.pieces.destroy', $piece))
        ->assertForbidden();
});

// --- delete -----------------------------------------------------------------------------------

it('deletes a piece and cascades its entire subtree', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);

    $wrapper = Piece::factory()->twoColumnsWrapper()->create(['page_version_id' => $version->id]);
    $child = Piece::factory()->paragraph()->childOf($wrapper, 'left')->create();
    $grandchild = Piece::factory()->heading()->childOf($child, null)->create();

    $this->actingAs($owner)
        ->deleteJson(route('api.v1.pieces.destroy', $wrapper))
        ->assertSuccessful()
        ->assertJson(['success' => true, 'message' => 'Piece deleted.']);

    expect(Piece::find($wrapper->id))->toBeNull();
    expect(Piece::find($child->id))->toBeNull();
    expect(Piece::find($grandchild->id))->toBeNull();
});

it('never resolves a piece created directly with an invalid type value', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $version = makeEditableVersion($website);

    $this->actingAs($owner)
        ->postJson(route('api.v1.versions.pieces.store', ['page' => $version->page_id, 'version' => $version]), ['type' => 'video', 'content' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['type']);
});
