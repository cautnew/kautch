<?php

use App\Enums\PageVersionStatus;
use App\Enums\WebsiteRole;
use App\Models\Layout;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use App\Models\Website;

it('rejects unauthenticated access to every workflow endpoint', function () {
    $website = Website::factory()->create();
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);

    $this->postJson(route('api.v1.versions.submit-review', ['page' => $version->page_id, 'version' => $version]))->assertUnauthorized();
    $this->postJson(route('api.v1.versions.approve', ['page' => $version->page_id, 'version' => $version]))->assertUnauthorized();
    $this->postJson(route('api.v1.versions.reject', ['page' => $version->page_id, 'version' => $version]))->assertUnauthorized();
    $this->postJson(route('api.v1.versions.publish', ['page' => $version->page_id, 'version' => $version]))->assertUnauthorized();
});

// --- submit-review: draft|rejected -> under_review -----------------------------------------

it('submits a draft version for review', function () {
    $website = Website::factory()->create();
    $editor = User::factory()->create();
    createWebsiteMembership($website, $editor, WebsiteRole::Editor);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);

    $this->actingAs($editor)
        ->postJson(route('api.v1.versions.submit-review', ['page' => $version->page_id, 'version' => $version]))
        ->assertSuccessful()
        ->assertJson(['success' => true, 'message' => 'Page version submitted for review.'])
        ->assertJsonPath('data.status', 'under_review');

    expect($version->fresh()->status)->toBe(PageVersionStatus::UnderReview);
});

it('allows resubmitting a rejected version for review', function () {
    $website = Website::factory()->create();
    $editor = User::factory()->create();
    createWebsiteMembership($website, $editor, WebsiteRole::Editor);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->rejected()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);

    $this->actingAs($editor)
        ->postJson(route('api.v1.versions.submit-review', ['page' => $version->page_id, 'version' => $version]))
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'under_review');
});

it('does not allow submitting a version that is not draft or rejected', function (PageVersionStatus $status) {
    $website = Website::factory()->create();
    $editor = User::factory()->create();
    createWebsiteMembership($website, $editor, WebsiteRole::Editor);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->create(['page_id' => $page->id, 'layout_id' => $layout->id, 'status' => $status]);

    $this->actingAs($editor)
        ->postJson(route('api.v1.versions.submit-review', ['page' => $version->page_id, 'version' => $version]))
        ->assertForbidden();
})->with([
    'under_review' => [PageVersionStatus::UnderReview],
    'approved' => [PageVersionStatus::Approved],
]);

it('only allows owner, admin and editor to submit for review', function (WebsiteRole $role, bool $allowed) {
    $website = Website::factory()->create();
    $actor = User::factory()->create();
    createWebsiteMembership($website, $actor, $role);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);

    $response = $this->actingAs($actor)->postJson(route('api.v1.versions.submit-review', ['page' => $version->page_id, 'version' => $version]));

    $allowed ? $response->assertSuccessful() : $response->assertForbidden();
})->with([
    'owner' => [WebsiteRole::Owner, true],
    'admin' => [WebsiteRole::Admin, true],
    'editor' => [WebsiteRole::Editor, true],
    'qa' => [WebsiteRole::Qa, false],
    'viewer' => [WebsiteRole::Viewer, false],
]);

// --- approve: under_review -> approved (QA only) --------------------------------------------

it('lets qa approve a version under review, with optional notes', function () {
    $website = Website::factory()->create();
    $qa = User::factory()->create();
    createWebsiteMembership($website, $qa, WebsiteRole::Qa);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->underReview()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);

    $this->actingAs($qa)
        ->postJson(route('api.v1.versions.approve', ['page' => $version->page_id, 'version' => $version]), ['notes' => 'Looks good.'])
        ->assertSuccessful()
        ->assertJson(['success' => true, 'message' => 'Page version approved.'])
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.reviews.0.decision', 'approved')
        ->assertJsonPath('data.reviews.0.qa_user_id', $qa->id)
        ->assertJsonPath('data.reviews.0.notes', 'Looks good.');

    $version->refresh();
    expect($version->status)->toBe(PageVersionStatus::Approved);
    expect($version->reviews)->toHaveCount(1);
    expect($version->reviews->first()->qa_user_id)->toBe($qa->id);
    expect($version->reviews->first()->created_at)->not->toBeNull();
});

it('does not require notes to approve', function () {
    $website = Website::factory()->create();
    $qa = User::factory()->create();
    createWebsiteMembership($website, $qa, WebsiteRole::Qa);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->underReview()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);

    $this->actingAs($qa)
        ->postJson(route('api.v1.versions.approve', ['page' => $version->page_id, 'version' => $version]), [])
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'approved');
});

it('does not allow approving a version that is not under review', function () {
    $website = Website::factory()->create();
    $qa = User::factory()->create();
    createWebsiteMembership($website, $qa, WebsiteRole::Qa);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);

    $this->actingAs($qa)
        ->postJson(route('api.v1.versions.approve', ['page' => $version->page_id, 'version' => $version]))
        ->assertForbidden();
});

it('only allows qa (and, via owner-supremacy, the owner) to approve — never editor or admin', function (WebsiteRole $role, bool $allowed) {
    $website = Website::factory()->create();
    $actor = User::factory()->create();
    createWebsiteMembership($website, $actor, $role);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->underReview()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);

    $response = $this->actingAs($actor)->postJson(route('api.v1.versions.approve', ['page' => $version->page_id, 'version' => $version]));

    $allowed ? $response->assertSuccessful() : $response->assertForbidden();
})->with([
    'owner' => [WebsiteRole::Owner, true],
    'admin' => [WebsiteRole::Admin, false],
    'editor' => [WebsiteRole::Editor, false],
    'qa' => [WebsiteRole::Qa, true],
    'viewer' => [WebsiteRole::Viewer, false],
]);

// --- reject: under_review -> rejected (QA only, notes required) -----------------------------

it('lets qa reject a version under review with required notes', function () {
    $website = Website::factory()->create();
    $qa = User::factory()->create();
    createWebsiteMembership($website, $qa, WebsiteRole::Qa);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->underReview()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);

    $this->actingAs($qa)
        ->postJson(route('api.v1.versions.reject', ['page' => $version->page_id, 'version' => $version]), ['notes' => 'Broken image links.'])
        ->assertSuccessful()
        ->assertJson(['success' => true, 'message' => 'Page version rejected.'])
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.reviews.0.decision', 'rejected')
        ->assertJsonPath('data.reviews.0.notes', 'Broken image links.');

    expect($version->fresh()->status)->toBe(PageVersionStatus::Rejected);
});

it('requires notes to reject a version', function () {
    $website = Website::factory()->create();
    $qa = User::factory()->create();
    createWebsiteMembership($website, $qa, WebsiteRole::Qa);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->underReview()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);

    $this->actingAs($qa)
        ->postJson(route('api.v1.versions.reject', ['page' => $version->page_id, 'version' => $version]), [])
        ->assertUnprocessable()
        ->assertJson(['success' => false])
        ->assertJsonValidationErrors(['notes']);
});

it('does not allow rejecting a version that is not under review', function () {
    $website = Website::factory()->create();
    $qa = User::factory()->create();
    createWebsiteMembership($website, $qa, WebsiteRole::Qa);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->approved()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);

    $this->actingAs($qa)
        ->postJson(route('api.v1.versions.reject', ['page' => $version->page_id, 'version' => $version]), ['notes' => 'Too late.'])
        ->assertForbidden();
});

it('only allows qa (and, via owner-supremacy, the owner) to reject — never editor or admin', function (WebsiteRole $role, bool $allowed) {
    $website = Website::factory()->create();
    $actor = User::factory()->create();
    createWebsiteMembership($website, $actor, $role);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->underReview()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);

    $response = $this->actingAs($actor)->postJson(route('api.v1.versions.reject', ['page' => $version->page_id, 'version' => $version]), ['notes' => 'Fix this.']);

    $allowed ? $response->assertSuccessful() : $response->assertForbidden();
})->with([
    'owner' => [WebsiteRole::Owner, true],
    'admin' => [WebsiteRole::Admin, false],
    'editor' => [WebsiteRole::Editor, false],
    'qa' => [WebsiteRole::Qa, true],
    'viewer' => [WebsiteRole::Viewer, false],
]);

// --- publish: approved -> published (admin/owner only, never editor) ------------------------

it('lets admin publish an approved version', function () {
    $website = Website::factory()->create();
    $admin = User::factory()->create();
    createWebsiteMembership($website, $admin, WebsiteRole::Admin);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->approved()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);

    $this->actingAs($admin)
        ->postJson(route('api.v1.versions.publish', ['page' => $version->page_id, 'version' => $version]))
        ->assertSuccessful()
        ->assertJson(['success' => true, 'message' => 'Page version published.'])
        ->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.is_published', true);

    $version->refresh();
    expect($version->status)->toBe(PageVersionStatus::Published);
    expect($version->published_by)->toBe($admin->id);
    expect($version->published_at)->not->toBeNull();
    expect($page->fresh()->published_version_id)->toBe($version->id);
});

it('lets owner publish an approved version', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->approved()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);

    $this->actingAs($owner)
        ->postJson(route('api.v1.versions.publish', ['page' => $version->page_id, 'version' => $version]))
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'published');
});

it('never lets an editor publish, even an approved version', function () {
    $website = Website::factory()->create();
    $editor = User::factory()->create();
    createWebsiteMembership($website, $editor, WebsiteRole::Editor);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->approved()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);

    $this->actingAs($editor)
        ->postJson(route('api.v1.versions.publish', ['page' => $version->page_id, 'version' => $version]))
        ->assertForbidden();

    expect($version->fresh()->status)->toBe(PageVersionStatus::Approved);
});

it('does not allow publishing a version that is not approved', function () {
    $website = Website::factory()->create();
    $admin = User::factory()->create();
    createWebsiteMembership($website, $admin, WebsiteRole::Admin);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->underReview()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);

    $this->actingAs($admin)
        ->postJson(route('api.v1.versions.publish', ['page' => $version->page_id, 'version' => $version]))
        ->assertForbidden();
});

it('only allows admin and owner to publish', function (WebsiteRole $role, bool $allowed) {
    $website = Website::factory()->create();
    $actor = User::factory()->create();
    createWebsiteMembership($website, $actor, $role);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->approved()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);

    $response = $this->actingAs($actor)->postJson(route('api.v1.versions.publish', ['page' => $version->page_id, 'version' => $version]));

    $allowed ? $response->assertSuccessful() : $response->assertForbidden();
})->with([
    'owner' => [WebsiteRole::Owner, true],
    'admin' => [WebsiteRole::Admin, true],
    'editor' => [WebsiteRole::Editor, false],
    'qa' => [WebsiteRole::Qa, false],
    'viewer' => [WebsiteRole::Viewer, false],
]);

it('archives the previously published version and repoints the page when a new version is published', function () {
    $website = Website::factory()->create();
    $admin = User::factory()->create();
    createWebsiteMembership($website, $admin, WebsiteRole::Admin);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);

    $oldVersion = PageVersion::factory()->published()->create([
        'page_id' => $page->id,
        'layout_id' => $layout->id,
        'version_number' => 1,
    ]);
    $page->asActor($page->created_by)->update(['published_version_id' => $oldVersion->id]);

    $newVersion = PageVersion::factory()->approved()->create([
        'page_id' => $page->id,
        'layout_id' => $layout->id,
        'version_number' => 2,
    ]);

    $this->actingAs($admin)
        ->postJson(route('api.v1.versions.publish', ['page' => $newVersion->page_id, 'version' => $newVersion]))
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'published');

    expect($oldVersion->fresh()->status)->toBe(PageVersionStatus::Archived);
    expect($newVersion->fresh()->status)->toBe(PageVersionStatus::Published);
    expect($page->fresh()->published_version_id)->toBe($newVersion->id);
});

it('published versions remain immutable — cannot be deleted or resubmitted', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->published()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);

    $this->actingAs($owner)->deleteJson(route('api.v1.versions.destroy', ['page' => $version->page_id, 'version' => $version]))->assertForbidden();
    $this->actingAs($owner)->postJson(route('api.v1.versions.submit-review', ['page' => $version->page_id, 'version' => $version]))->assertForbidden();
    $this->actingAs($owner)->postJson(route('api.v1.versions.publish', ['page' => $version->page_id, 'version' => $version]))->assertForbidden();

    expect($version->fresh()->status)->toBe(PageVersionStatus::Published);
});
