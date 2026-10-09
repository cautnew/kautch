<?php

use App\Enums\WebsiteRole;
use App\Models\Layout;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use App\Models\Website;

it('rejects unauthenticated access to every endpoint', function () {
    $website = Website::factory()->create();
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);

    $this->getJson(route('api.v1.pages.versions.index', $page))->assertUnauthorized();
    $this->postJson(route('api.v1.pages.versions.store', $page), [])->assertUnauthorized();
    $this->getJson(route('api.v1.versions.show', $version))->assertUnauthorized();
    $this->deleteJson(route('api.v1.versions.destroy', $version))->assertUnauthorized();
});

it('lets any member view versions but denies outsiders', function () {
    $website = Website::factory()->create();
    $viewer = User::factory()->create();
    createWebsiteMembership($website, $viewer, WebsiteRole::Viewer);
    $outsider = User::factory()->create();
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);

    $this->actingAs($viewer)->getJson(route('api.v1.pages.versions.index', $page))->assertSuccessful();
    $this->actingAs($viewer)->getJson(route('api.v1.versions.show', $version))->assertSuccessful();

    $this->actingAs($outsider)->getJson(route('api.v1.pages.versions.index', $page))->assertForbidden();
    $this->actingAs($outsider)->getJson(route('api.v1.versions.show', $version))->assertForbidden();
});

it('creates a draft version with a full layout and seo snapshot', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $layout = Layout::factory()->create([
        'website_id' => $website->id,
        'schema' => ['blocks' => [['type' => 'heading', 'text' => 'Welcome']]],
    ]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);

    $response = $this->actingAs($owner)->postJson(route('api.v1.pages.versions.store', $page), [
        'layout_id' => $layout->id,
        'data' => ['title' => 'Home'],
        'seo' => ['title' => 'Home | Site', 'description' => 'Welcome to the site.'],
    ]);

    $response->assertCreated()
        ->assertJson(['success' => true, 'message' => 'Page version created.'])
        ->assertJsonPath('data.version_number', 1)
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.is_published', false)
        ->assertJsonPath('data.is_editable', true)
        ->assertJsonPath('data.layout_snapshot.name', $layout->name)
        ->assertJsonPath('data.layout_snapshot.schema.blocks.0.text', 'Welcome')
        ->assertJsonPath('data.seo_snapshot.title', 'Home | Site');

    $version = PageVersion::where('page_id', $page->id)->first();
    expect($version->created_by)->toBe($owner->id);
    expect($version->layout_snapshot['schema'])->toEqualCanonicalizing($layout->schema);
});

it('keeps the snapshot unchanged after the layout is later modified', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $layout = Layout::factory()->create(['website_id' => $website->id, 'schema' => ['blocks' => ['original']]]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);

    $this->actingAs($owner)->postJson(route('api.v1.pages.versions.store', $page), [
        'layout_id' => $layout->id,
    ])->assertCreated();

    $version = PageVersion::where('page_id', $page->id)->first();

    $layout->update(['schema' => ['blocks' => ['changed']]]);

    expect($version->fresh()->layout_snapshot['schema'])->toBe(['blocks' => ['original']]);
});

it('increments version_number sequentially per page', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);

    $this->actingAs($owner)->postJson(route('api.v1.pages.versions.store', $page), ['layout_id' => $layout->id])
        ->assertJsonPath('data.version_number', 1);
    $this->actingAs($owner)->postJson(route('api.v1.pages.versions.store', $page), ['layout_id' => $layout->id])
        ->assertJsonPath('data.version_number', 2);
    $this->actingAs($owner)->postJson(route('api.v1.pages.versions.store', $page), ['layout_id' => $layout->id])
        ->assertJsonPath('data.version_number', 3);

    expect(PageVersion::where('page_id', $page->id)->count())->toBe(3);
});

it('starts version numbering independently per page', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $pageA = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $pageB = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);

    $this->actingAs($owner)->postJson(route('api.v1.pages.versions.store', $pageA), ['layout_id' => $layout->id])
        ->assertJsonPath('data.version_number', 1);
    $this->actingAs($owner)->postJson(route('api.v1.pages.versions.store', $pageB), ['layout_id' => $layout->id])
        ->assertJsonPath('data.version_number', 1);
});

it('validates layout_id is required', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);

    $this->actingAs($owner)
        ->postJson(route('api.v1.pages.versions.store', $page), [])
        ->assertUnprocessable()
        ->assertJson(['success' => false])
        ->assertJsonValidationErrors(['layout_id']);
});

it('rejects a layout that belongs to a different website', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $otherWebsite = Website::factory()->create();
    $foreignLayout = Layout::factory()->create(['website_id' => $otherWebsite->id]);

    $this->actingAs($owner)
        ->postJson(route('api.v1.pages.versions.store', $page), ['layout_id' => $foreignLayout->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['layout_id']);
});

it('only allows owner, admin and editor to create versions', function (WebsiteRole $role, bool $allowed) {
    $website = Website::factory()->create();
    $actor = User::factory()->create();
    createWebsiteMembership($website, $actor, $role);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);

    $response = $this->actingAs($actor)->postJson(route('api.v1.pages.versions.store', $page), [
        'layout_id' => $layout->id,
    ]);

    $allowed ? $response->assertCreated() : $response->assertForbidden();
})->with([
    'owner' => [WebsiteRole::Owner, true],
    'admin' => [WebsiteRole::Admin, true],
    'editor' => [WebsiteRole::Editor, true],
    'qa' => [WebsiteRole::Qa, false],
    'viewer' => [WebsiteRole::Viewer, false],
]);

it('only allows owner and admin to delete versions', function (WebsiteRole $role, bool $allowed) {
    $website = Website::factory()->create();
    $actor = User::factory()->create();
    createWebsiteMembership($website, $actor, $role);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);

    $response = $this->actingAs($actor)->deleteJson(route('api.v1.versions.destroy', $version));

    $allowed ? $response->assertSuccessful() : $response->assertForbidden();
})->with([
    'owner' => [WebsiteRole::Owner, true],
    'admin' => [WebsiteRole::Admin, true],
    'editor' => [WebsiteRole::Editor, false],
    'qa' => [WebsiteRole::Qa, false],
    'viewer' => [WebsiteRole::Viewer, false],
]);

it('soft deletes a draft version', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->create(['page_id' => $page->id, 'layout_id' => $layout->id, 'status' => 'draft']);

    $this->actingAs($owner)
        ->deleteJson(route('api.v1.versions.destroy', $version))
        ->assertSuccessful()
        ->assertJson(['success' => true, 'message' => 'Page version deleted.']);

    $this->assertSoftDeleted($version);
});

it('never allows a published version to be deleted, even by the owner', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->published()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);

    $this->actingAs($owner)
        ->deleteJson(route('api.v1.versions.destroy', $version))
        ->assertForbidden();

    expect($version->fresh())->not->toBeNull();
});

it('updates a draft version seo snapshot directly, with no clone involved', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->create([
        'page_id' => $page->id,
        'layout_id' => $layout->id,
        'seo_snapshot' => ['title' => 'Before'],
    ]);

    $response = $this->actingAs($owner)->putJson(route('api.v1.versions.update', $version), [
        'seo' => ['title' => 'After'],
    ]);

    $response->assertSuccessful()
        ->assertJson(['success' => true, 'message' => 'Page version updated.'])
        ->assertJsonPath('data.id', $version->id)
        ->assertJsonPath('data.seo_snapshot.title', 'After');
});

it('is a no-op when the update payload carries neither layout_id nor seo', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->create([
        'page_id' => $page->id,
        'layout_id' => $layout->id,
        'seo_snapshot' => ['title' => 'Unchanged'],
    ]);
    $updatedAtBefore = $version->updated_at;

    $this->actingAs($owner)
        ->putJson(route('api.v1.versions.update', $version), [])
        ->assertSuccessful()
        ->assertJsonPath('data.id', $version->id)
        ->assertJsonPath('data.seo_snapshot.title', 'Unchanged');

    expect($version->fresh()->updated_at->eq($updatedAtBefore))->toBeTrue();
});
