<?php

use App\Enums\WebsiteRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Website;

it('computes host as the root domain when there is no subdomain', function () {
    $website = Website::factory()->create(['domain' => 'example.com', 'subdomain' => '']);

    expect($website->host)->toBe('example.com');
});

it('computes host as subdomain + domain when a subdomain is set', function () {
    $website = Website::factory()->create(['domain' => 'example.com', 'subdomain' => 'blog']);

    expect($website->host)->toBe('blog.example.com');
});

it('resolves the users who created, last updated and deleted it', function () {
    $creator = User::factory()->create();
    $updater = User::factory()->create();
    $deleter = User::factory()->create();

    $website = Website::factory()->create(['created_by' => $creator->id, 'updated_by' => $updater->id]);
    $website->asActor($deleter)->delete();

    $trashed = Website::withTrashed()->find($website->id);

    expect($trashed->creator->is($creator))->toBeTrue();
    expect($trashed->updater->is($updater))->toBeTrue();
    expect($trashed->deleter->is($deleter))->toBeTrue();
});

it('scopes to a given domain', function () {
    Website::factory()->create(['domain' => 'match.test']);
    Website::factory()->create(['domain' => 'other.test']);

    $results = Website::query()->forDomain('match.test')->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->domain)->toBe('match.test');
});

it('scopes to a website by its root domain when there is no subdomain', function () {
    Website::factory()->create(['domain' => 'match.test', 'subdomain' => '']);
    Website::factory()->create(['domain' => 'other.test', 'subdomain' => '']);

    $results = Website::query()->forHost('match.test')->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->domain)->toBe('match.test');
});

it('scopes to a website by subdomain + domain host', function () {
    Website::factory()->create(['domain' => 'example.com', 'subdomain' => 'blog']);
    Website::factory()->create(['domain' => 'example.com', 'subdomain' => '']);

    $results = Website::query()->forHost('blog.example.com')->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->subdomain)->toBe('blog');
});

it('does not match a root-domain website against an unrelated subdomain host', function () {
    Website::factory()->create(['domain' => 'example.com', 'subdomain' => '']);

    $results = Website::query()->forHost('blog.example.com')->get();

    expect($results)->toHaveCount(0);
});

it('lists users through the website_users pivot with role/timestamps', function () {
    $website = Website::factory()->create();
    $user = User::factory()->create();
    createWebsiteMembership($website, $user, WebsiteRole::Editor);

    $found = $website->users()->first();

    expect($found->is($user))->toBeTrue();
    // The pivot's own columns aren't cast through WebsiteUser's enum cast —
    // only the dedicated WebsiteUser model (used by roleOn(), etc.) is.
    expect($found->pivot->role)->toBe(WebsiteRole::Editor->value);
});

it('exposes its audit log entries', function () {
    // Creating the website itself already produces one "create" AuditLog row
    // (Website uses the Auditable trait) — assert the explicit one is
    // included rather than an exact count coupled to that side effect.
    $website = Website::factory()->create();
    $log = AuditLog::factory()->forModel($website)->create(['action' => 'update']);

    expect($website->auditLogs()->pluck('id'))->toContain($log->id);
});

it('reports its own id as the audit scope', function () {
    $website = Website::factory()->create();

    expect($website->auditWebsiteId())->toBe($website->id);
});
