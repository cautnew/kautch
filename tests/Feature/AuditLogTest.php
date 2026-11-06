<?php

use App\Enums\WebsiteRole;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Layout;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

// --- login / logout -----------------------------------------------------------------------

it('records a login audit entry with the request ip and user agent', function () {
    $user = User::factory()->create();

    $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
        ->withHeaders(['User-Agent' => 'AuditTestAgent/1.0'])
        ->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

    $response->assertSuccessful();

    $log = AuditLog::where('action', 'login')->where('user_id', $user->id)->sole();
    expect($log->auditable_type)->toBe(User::class);
    expect($log->auditable_id)->toBe($user->id);
    expect($log->website_id)->toBeNull();
    expect($log->ip_address)->toBe('203.0.113.5');
    expect($log->user_agent)->toBe('AuditTestAgent/1.0');
});

it('does not record a login audit entry on failed credentials', function () {
    $user = User::factory()->create();

    $this->postJson(route('api.v1.auth.login'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertUnprocessable();

    expect(AuditLog::where('action', 'login')->exists())->toBeFalse();
});

it('records a logout audit entry', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson(route('api.v1.auth.logout'))
        ->assertSuccessful();

    $log = AuditLog::where('action', 'logout')->where('user_id', $user->id)->sole();
    expect($log->auditable_type)->toBe(User::class);
});

// --- generic create / update / delete (Auditable trait) ------------------------------------

it('records a create audit entry when a website is created', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('api.v1.websites.store'), [
        'name' => 'Audited Site',
        'domain' => 'audited.example',
    ]);
    $response->assertCreated();
    $websiteId = $response->json('data.id');

    $log = AuditLog::where('action', 'create')
        ->where('auditable_type', Website::class)
        ->where('auditable_id', $websiteId)
        ->sole();

    expect($log->website_id)->toBe($websiteId);
    expect($log->user_id)->toBe($user->id);
    expect($log->new_values['domain'])->toBe('audited.example');
    expect($log->old_values)->toBeNull();

    // Creating a website also creates an owner WebsiteUser membership — audited too.
    expect(AuditLog::where('action', 'create')->where('auditable_type', WebsiteUser::class)->exists())->toBeTrue();
});

it('records an update audit entry with old and new values', function () {
    $user = User::factory()->create();
    $website = Website::factory()->create(['name' => 'Before']);
    createWebsiteMembership($website, $user, WebsiteRole::Owner);

    $this->actingAs($user)
        ->putJson(route('api.v1.websites.update', $website), ['name' => 'After'])
        ->assertSuccessful();

    $log = AuditLog::where('action', 'update')
        ->where('auditable_type', Website::class)
        ->where('auditable_id', $website->id)
        ->sole();

    expect($log->old_values['name'])->toBe('Before');
    expect($log->new_values['name'])->toBe('After');
});

it('records a delete audit entry', function () {
    $user = User::factory()->create();
    $website = Website::factory()->create();
    createWebsiteMembership($website, $user, WebsiteRole::Owner);

    $this->actingAs($user)
        ->deleteJson(route('api.v1.websites.destroy', $website))
        ->assertSuccessful();

    $log = AuditLog::where('action', 'delete')
        ->where('auditable_type', Website::class)
        ->where('auditable_id', $website->id)
        ->sole();

    expect($log->website_id)->toBe($website->id);
});

it('does not record an update audit entry when nothing but updated_at changed', function () {
    $website = Website::factory()->create();
    $countBefore = AuditLog::count();

    $website->touch();

    expect(AuditLog::count())->toBe($countBefore);
});

// --- upload (Asset) --------------------------------------------------------------------------

it('records an upload audit entry (not a generic create) when an asset is uploaded', function () {
    $user = User::factory()->create();
    $website = Website::factory()->create();
    createWebsiteMembership($website, $user, WebsiteRole::Owner);
    $layout = Layout::factory()->create(['website_id' => $website->id]);

    $response = $this->actingAs($user)->postJson(route('api.v1.websites.assets.store', $website), [
        'file' => UploadedFile::fake()->image('photo.jpg'),
        'layout_id' => $layout->id,
    ]);
    $response->assertCreated();

    $log = AuditLog::where('auditable_type', Asset::class)
        ->where('auditable_id', $response->json('data.id'))
        ->sole();

    expect($log->action)->toBe('upload');
});

// --- approve / reject / publish (PageVersion workflow) --------------------------------------

it('records approve, then a separate reject, as distinct action names', function () {
    $website = Website::factory()->create();
    $qa = User::factory()->create();
    createWebsiteMembership($website, $qa, WebsiteRole::Qa);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->underReview()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);

    $this->actingAs($qa)
        ->postJson(route('api.v1.versions.approve', ['page' => $version->page_id, 'version' => $version]), ['notes' => 'ok'])
        ->assertSuccessful();

    $approveLog = AuditLog::where('action', 'approve')
        ->where('auditable_type', PageVersion::class)
        ->where('auditable_id', $version->id)
        ->sole();
    expect($approveLog->new_values['status'])->toBe('approved');
    expect($approveLog->website_id)->toBe($website->id);

    expect(AuditLog::where('action', 'update')->where('auditable_id', $version->id)->exists())->toBeFalse();
});

it('records a publish action when an admin publishes an approved version', function () {
    $website = Website::factory()->create();
    $admin = User::factory()->create();
    createWebsiteMembership($website, $admin, WebsiteRole::Admin);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->approved()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);

    $this->actingAs($admin)
        ->postJson(route('api.v1.versions.publish', ['page' => $version->page_id, 'version' => $version]))
        ->assertSuccessful();

    $log = AuditLog::where('action', 'publish')
        ->where('auditable_type', PageVersion::class)
        ->where('auditable_id', $version->id)
        ->sole();

    expect($log->new_values['status'])->toBe('published');
});

it('records a reject action with a generic update fallback for unrelated status transitions', function () {
    $website = Website::factory()->create();
    $qa = User::factory()->create();
    $editor = User::factory()->create();
    createWebsiteMembership($website, $qa, WebsiteRole::Qa);
    createWebsiteMembership($website, $editor, WebsiteRole::Editor);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->underReview()->create(['page_id' => $page->id, 'layout_id' => $layout->id]);

    $this->actingAs($qa)
        ->postJson(route('api.v1.versions.reject', ['page' => $version->page_id, 'version' => $version]), ['notes' => 'needs work'])
        ->assertSuccessful();

    expect(AuditLog::where('action', 'reject')->where('auditable_id', $version->id)->exists())->toBeTrue();

    // Submitting the now-rejected version back for review has no dedicated
    // action name requested — it should fall back to generic "update".
    $this->actingAs($editor)
        ->postJson(route('api.v1.versions.submit-review', ['page' => $version->page_id, 'version' => $version]))
        ->assertSuccessful();

    $updateLog = AuditLog::where('action', 'update')
        ->where('auditable_id', $version->id)
        ->sole();
    expect($updateLog->new_values['status'])->toBe('under_review');
});
