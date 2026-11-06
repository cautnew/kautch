<?php

use App\Enums\WebsiteRole;
use App\Models\Layout;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\PageVersionReview;
use App\Models\User;
use App\Models\Website;
use App\Services\PageVersionService;

it('rejects unauthenticated access to every endpoint', function () {
    $website = Website::factory()->create();
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);

    $this->getJson(route('api.v1.pages.versions.index', $page))->assertUnauthorized();
    $this->postJson(route('api.v1.pages.versions.store', $page), [])->assertUnauthorized();
    $this->getJson(route('api.v1.versions.show', ['page' => $version->page_id, 'version' => $version]))->assertUnauthorized();
    $this->deleteJson(route('api.v1.versions.destroy', ['page' => $version->page_id, 'version' => $version]))->assertUnauthorized();
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
    $this->actingAs($viewer)->getJson(route('api.v1.versions.show', ['page' => $version->page_id, 'version' => $version]))->assertSuccessful();

    $this->actingAs($outsider)->getJson(route('api.v1.pages.versions.index', $page))->assertForbidden();
    $this->actingAs($outsider)->getJson(route('api.v1.versions.show', ['page' => $version->page_id, 'version' => $version]))->assertForbidden();
});

it('lists only version metadata with the last updater and latest approver', function () {
    $website = Website::factory()->create();
    $viewer = User::factory()->create();
    createWebsiteMembership($website, $viewer, WebsiteRole::Viewer);
    $page = Page::factory()->create(['website_id' => $website->id]);
    $creator = User::factory()->create();
    $updater = User::factory()->create();
    $qa = User::factory()->create();
    $draft = PageVersion::factory()->create([
        'page_id' => $page->id,
        'version_number' => 1,
    ]);
    $rejected = PageVersion::factory()->rejected()->create([
        'page_id' => $page->id,
        'version_number' => 2,
    ]);
    $approved = PageVersion::factory()->approved($qa)->create([
        'page_id' => $page->id,
        'version_number' => 3,
        'created_by' => $creator->id,
    ]);
    $approved->asActor($updater)->update(['seo_snapshot' => ['title' => 'Updated']]);
    $approved->refresh();
    PageVersionReview::factory()->approved()->create([
        'page_version_id' => $approved->id,
        'created_at' => now()->subDay(),
    ]);
    PageVersionReview::factory()->rejected()->create([
        'page_version_id' => $approved->id,
        'created_at' => now()->addSecond(),
    ]);
    PageVersion::factory()->create();

    $this->actingAs($viewer)
        ->getJson(route('api.v1.pages.versions.index', $page))
        ->assertSuccessful()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0', [
            'id' => $approved->id,
            'page_id' => $page->id,
            'layout_id' => $approved->layout_id,
            'version_number' => 3,
            'status' => 'approved',
            'created_by' => $creator->id,
            'updated_by' => $updater->id,
            'approved_by' => $qa->id,
            'created_at' => $approved->created_at->toIso8601String(),
            'updated_at' => $approved->updated_at->toIso8601String(),
        ])
        ->assertJsonPath('data.1.id', $rejected->id)
        ->assertJsonPath('data.1.approved_by', null)
        ->assertJsonPath('data.2.id', $draft->id)
        ->assertJsonPath('data.2.approved_by', null);

    $versions = app(PageVersionService::class)->listForPage($page);

    foreach ($versions as $version) {
        expect($version->getAttributes())->not->toHaveKeys(['layout_snapshot', 'seo_snapshot'])
            ->and($version->relationLoaded('reviews'))->toBeFalse();
    }
});

it('returns the complete version and review history from the details route', function () {
    $website = Website::factory()->create();
    $viewer = User::factory()->create();
    createWebsiteMembership($website, $viewer, WebsiteRole::Viewer);
    $page = Page::factory()->create(['website_id' => $website->id]);
    $qa = User::factory()->create();
    $version = PageVersion::factory()->approved($qa, 'Ready to publish.')->create(['page_id' => $page->id]);
    $review = $version->reviews()->first();

    $this->actingAs($viewer)
        ->getJson(route('api.v1.versions.show', ['page' => $version->page_id, 'version' => $version->id]))
        ->assertSuccessful()
        ->assertJsonPath('data.id', $version->id)
        ->assertJsonPath('data.page_id', $page->id)
        ->assertJsonPath('data.layout_snapshot', $version->layout_snapshot)
        ->assertJsonPath('data.seo_snapshot', $version->seo_snapshot)
        ->assertJsonPath('data.reviews.0.id', $review->id)
        ->assertJsonPath('data.reviews.0.qa_user_id', $qa->id)
        ->assertJsonPath('data.reviews.0.notes', 'Ready to publish.')
        ->assertJsonStructure(['data' => [
            'cloned_from_id', 'is_published', 'is_editable', 'created_by',
            'published_at', 'published_by', 'created_at', 'updated_at',
        ]]);
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

    $response = $this->actingAs($actor)->deleteJson(route('api.v1.versions.destroy', ['page' => $version->page_id, 'version' => $version]));

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
        ->deleteJson(route('api.v1.versions.destroy', ['page' => $version->page_id, 'version' => $version]))
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
        ->deleteJson(route('api.v1.versions.destroy', ['page' => $version->page_id, 'version' => $version]))
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

    $response = $this->actingAs($owner)->putJson(route('api.v1.versions.update', ['page' => $version->page_id, 'version' => $version]), [
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
        ->putJson(route('api.v1.versions.update', ['page' => $version->page_id, 'version' => $version]), [])
        ->assertSuccessful()
        ->assertJsonPath('data.id', $version->id)
        ->assertJsonPath('data.seo_snapshot.title', 'Unchanged');

    expect($version->fresh()->updated_at->eq($updatedAtBefore))->toBeTrue();
});
