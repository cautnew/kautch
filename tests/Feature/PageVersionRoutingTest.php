<?php

use App\Enums\WebsiteRole;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\Piece;
use App\Models\User;
use App\Models\Website;

dataset('page version endpoints', [
    'details' => ['show', 'getJson', 'draft'],
    'update' => ['update', 'putJson', 'draft'],
    'delete' => ['destroy', 'deleteJson', 'draft'],
    'submit review' => ['submit-review', 'postJson', 'draft'],
    'approve' => ['approve', 'postJson', 'under_review'],
    'reject' => ['reject', 'postJson', 'under_review'],
    'publish' => ['publish', 'postJson', 'approved'],
    'list pieces' => ['pieces.index', 'getJson', 'draft'],
    'create piece' => ['pieces.store', 'postJson', 'draft'],
]);

it('prefers the published version and still allows requesting a newer draft explicitly', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $page = Page::factory()->create(['website_id' => $website->id]);
    $published = PageVersion::factory()->published()->create(['page_id' => $page->id]);
    $page->asActor($owner)->update(['published_version_id' => $published->id]);
    $draft = PageVersion::factory()->create(['page_id' => $page->id, 'version_number' => 2]);

    $this->actingAs($owner)->getJson("/api/v1/pages/{$page->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $published->id);
    $this->getJson("/api/v1/pages/{$page->id}/{$draft->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $draft->id);
});

it('uses the latest non-deleted version when no published version exists', function () {
    $website = Website::factory()->create();
    $viewer = User::factory()->create();
    createWebsiteMembership($website, $viewer, WebsiteRole::Viewer);
    $page = Page::factory()->create(['website_id' => $website->id]);
    PageVersion::factory()->create(['page_id' => $page->id, 'version_number' => 1]);
    $latest = PageVersion::factory()->underReview()->create(['page_id' => $page->id, 'version_number' => 2]);
    $deleted = PageVersion::factory()->create(['page_id' => $page->id, 'version_number' => 3]);
    $deleted->asActor($viewer)->delete();

    $this->actingAs($viewer)->getJson("/api/v1/pages/{$page->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $latest->id);
    $this->getJson("/api/v1/pages/{$page->id}/{$deleted->id}")->assertNotFound();
    $this->getJson("/api/v1/pages/{$page->id}/00000000-0000-4000-8000-000000000000")->assertNotFound();
});

it('returns not found for every current-version endpoint when the page has no versions', function (string $action, string $method) {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $page = Page::factory()->create(['website_id' => $website->id]);

    $this->actingAs($owner)->{$method}(route("api.v1.pages.current-version.{$action}", $page))
        ->assertNotFound();
})->with('page version endpoints');

it('scopes every explicit version endpoint to its page', function (string $action, string $method) {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $page = Page::factory()->create(['website_id' => $website->id]);
    $otherPage = Page::factory()->create(['website_id' => $website->id]);
    $version = PageVersion::factory()->create(['page_id' => $otherPage->id]);

    $this->actingAs($owner)->{$method}(route("api.v1.versions.{$action}", [
        'page' => $page,
        'version' => $version,
    ]))->assertNotFound();
})->with('page version endpoints');

it('authenticates and authorizes every current-version endpoint', function (string $action, string $method, string $status) {
    $page = Page::factory()->create();
    PageVersion::factory()->create(['page_id' => $page->id, 'status' => $status]);
    $url = route("api.v1.pages.current-version.{$action}", $page);

    $this->{$method}($url)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->{$method}($url)->assertForbidden();
})->with('page version endpoints');

it('operates on the latest version for every endpoint when the version id is omitted', function (string $action, string $method, string $status) {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $page = Page::factory()->create(['website_id' => $website->id]);
    $older = PageVersion::factory()->create(['page_id' => $page->id, 'version_number' => 1]);
    $version = PageVersion::factory()->create([
        'page_id' => $page->id,
        'version_number' => 2,
        'status' => $status,
    ]);

    if ($action === 'pieces.index') {
        Piece::factory()->create(['page_version_id' => $older->id]);
        $piece = Piece::factory()->create(['page_version_id' => $version->id]);
    }

    $payload = match ($action) {
        'update' => ['seo' => ['title' => 'Current version']],
        'reject' => ['notes' => 'Needs changes.'],
        'pieces.store' => ['type' => 'paragraph', 'content' => ['text' => 'Current piece']],
        default => [],
    };

    $response = $this->actingAs($owner)->{$method}(
        route("api.v1.pages.current-version.{$action}", $page),
        $payload,
    );

    $response->assertStatus($action === 'pieces.store' ? 201 : 200);

    if ($action === 'destroy') {
        $this->assertSoftDeleted($version);
    } elseif ($action === 'pieces.index') {
        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $piece->id);
    } elseif ($action === 'pieces.store') {
        $response->assertJsonPath('data.page_version_id', $version->id);
    } else {
        $response->assertJsonPath('data.id', $version->id);
        $response->assertJsonPath('data.status', match ($action) {
            'submit-review' => 'under_review',
            'approve' => 'approved',
            'reject' => 'rejected',
            'publish' => 'published',
            default => $status,
        });
    }

    if ($action === 'update') {
        $response->assertJsonPath('data.seo_snapshot.title', 'Current version');
    }

    expect($older->fresh()->status->value)->toBe('draft');
    $this->assertNotSoftDeleted($older);
})->with('page version endpoints');

it('keeps published versions immutable when editing through the implicit version route', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $page = Page::factory()->create(['website_id' => $website->id]);
    $published = PageVersion::factory()->published()->create(['page_id' => $page->id]);
    $page->asActor($owner)->update(['published_version_id' => $published->id]);
    $newerDraft = PageVersion::factory()->create(['page_id' => $page->id, 'version_number' => 2]);
    $snapshot = $published->seo_snapshot;

    $response = $this->actingAs($owner)->putJson("/api/v1/pages/{$page->id}", [
        'seo' => ['title' => 'Cloned draft'],
    ])->assertSuccessful()
        ->assertJsonPath('data.cloned_from_id', $published->id)
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.seo_snapshot.title', 'Cloned draft');

    expect($response->json('data.id'))->not->toBeIn([$published->id, $newerDraft->id])
        ->and($published->fresh()->seo_snapshot)->toBe($snapshot)
        ->and($page->fresh()->published_version_id)->toBe($published->id);
    $this->deleteJson("/api/v1/pages/{$page->id}")->assertForbidden();
});
