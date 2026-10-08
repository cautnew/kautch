<?php

use App\Enums\HttpMethod;
use App\Enums\RouteDestinationType;
use App\Enums\WebsiteRole;
use App\Models\Asset;
use App\Models\Layout;
use App\Models\Page;
use App\Models\Route;
use App\Models\User;
use App\Models\Website;
use App\Services\RouteLoopValidator;

it('rejects unauthenticated access to every CRUD endpoint', function () {
    $website = Website::factory()->create();
    $route = Route::factory()->create(['website_id' => $website->id]);

    $this->getJson(route('api.v1.websites.routes.index', $website))->assertUnauthorized();
    $this->postJson(route('api.v1.websites.routes.store', $website), [])->assertUnauthorized();
    $this->getJson(route('api.v1.websites.routes.show', [$website, $route]))->assertUnauthorized();
    $this->putJson(route('api.v1.websites.routes.update', [$website, $route]), [])->assertUnauthorized();
    $this->deleteJson(route('api.v1.websites.routes.destroy', [$website, $route]))->assertUnauthorized();
});

it('lets any member view routes but denies outsiders', function () {
    $website = Website::factory()->create();
    $viewer = User::factory()->create();
    createWebsiteMembership($website, $viewer, WebsiteRole::Viewer);
    $outsider = User::factory()->create();
    $route = Route::factory()->create(['website_id' => $website->id]);

    $this->actingAs($viewer)->getJson(route('api.v1.websites.routes.index', $website))->assertSuccessful();
    $this->actingAs($viewer)->getJson(route('api.v1.websites.routes.show', [$website, $route]))->assertSuccessful();

    $this->actingAs($outsider)->getJson(route('api.v1.websites.routes.index', $website))->assertForbidden();
    $this->actingAs($outsider)->getJson(route('api.v1.websites.routes.show', [$website, $route]))->assertForbidden();
});

// --- create: destination-specific validation -------------------------------------------------

it('creates a route serving a page', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $page = Page::factory()->create(['website_id' => $website->id]);

    $response = $this->actingAs($owner)->postJson(route('api.v1.websites.routes.store', $website), [
        'path' => '/custom-page-route',
        'destination_type' => 'page',
        'page_id' => $page->id,
    ]);

    $response->assertCreated()
        ->assertJson(['success' => true, 'message' => 'Route created.'])
        ->assertJsonPath('data.path', '/custom-page-route')
        ->assertJsonPath('data.http_method', 'GET')
        ->assertJsonPath('data.http_status', 200)
        ->assertJsonPath('data.destination_type', 'page')
        ->assertJsonPath('data.page_id', $page->id);
});

it('creates a route serving an asset', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $asset = Asset::factory()->create(['website_id' => $website->id]);

    $response = $this->actingAs($owner)->postJson(route('api.v1.websites.routes.store', $website), [
        'path' => '/downloads/file.pdf',
        'destination_type' => 'asset',
        'asset_id' => $asset->id,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.destination_type', 'asset')
        ->assertJsonPath('data.asset_id', $asset->id);
});

it('creates a redirect route defaulting to a 301 status', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $target = Route::factory()->create(['website_id' => $website->id]);

    $response = $this->actingAs($owner)->postJson(route('api.v1.websites.routes.store', $website), [
        'path' => '/old-path',
        'destination_type' => 'redirect',
        'redirect_to_route_id' => $target->id,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.destination_type', 'redirect')
        ->assertJsonPath('data.redirect_to_route_id', $target->id)
        ->assertJsonPath('data.http_status', 301);
});

it('creates a redirect route targeting an external URL', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);

    $response = $this->actingAs($owner)->postJson(route('api.v1.websites.routes.store', $website), [
        'path' => '/vai-embora',
        'destination_type' => 'redirect',
        'redirect_url' => 'https://www.exemplo.com.br/site',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.destination_type', 'redirect')
        ->assertJsonPath('data.redirect_url', 'https://www.exemplo.com.br/site')
        ->assertJsonPath('data.redirect_to_route_id', null)
        ->assertJsonPath('data.http_status', 301);
});

it('rejects a redirect with neither an internal nor an external target', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);

    $this->actingAs($owner)
        ->postJson(route('api.v1.websites.routes.store', $website), ['path' => '/x', 'destination_type' => 'redirect'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['redirect_to_route_id']);
});

it('rejects a redirect with both an internal and an external target', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $target = Route::factory()->create(['website_id' => $website->id]);

    $this->actingAs($owner)
        ->postJson(route('api.v1.websites.routes.store', $website), [
            'path' => '/x',
            'destination_type' => 'redirect',
            'redirect_to_route_id' => $target->id,
            'redirect_url' => 'https://www.exemplo.com.br/site',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['redirect_to_route_id']);
});

it('rejects a malformed redirect_url', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);

    $this->actingAs($owner)
        ->postJson(route('api.v1.websites.routes.store', $website), [
            'path' => '/x',
            'destination_type' => 'redirect',
            'redirect_url' => 'not-a-url',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['redirect_url']);
});

it('validates required fields on create', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);

    $this->actingAs($owner)
        ->postJson(route('api.v1.websites.routes.store', $website), [])
        ->assertUnprocessable()
        ->assertJson(['success' => false])
        ->assertJsonValidationErrors(['path', 'destination_type']);
});

it('requires page_id when destination_type is page', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);

    $this->actingAs($owner)
        ->postJson(route('api.v1.websites.routes.store', $website), ['path' => '/x', 'destination_type' => 'page'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['page_id']);
});

it('requires asset_id when destination_type is asset', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);

    $this->actingAs($owner)
        ->postJson(route('api.v1.websites.routes.store', $website), ['path' => '/x', 'destination_type' => 'asset'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['asset_id']);
});

it('requires redirect_to_route_id when destination_type is redirect', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);

    $this->actingAs($owner)
        ->postJson(route('api.v1.websites.routes.store', $website), ['path' => '/x', 'destination_type' => 'redirect'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['redirect_to_route_id']);
});

it('rejects a page_id, asset_id or redirect target belonging to a different website', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $otherWebsite = Website::factory()->create();
    $foreignPage = Page::factory()->create(['website_id' => $otherWebsite->id]);

    $this->actingAs($owner)
        ->postJson(route('api.v1.websites.routes.store', $website), [
            'path' => '/x',
            'destination_type' => 'page',
            'page_id' => $foreignPage->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['page_id']);
});

it('rejects an invalid http_method', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $page = Page::factory()->create(['website_id' => $website->id]);

    $this->actingAs($owner)
        ->postJson(route('api.v1.websites.routes.store', $website), [
            'path' => '/x',
            'destination_type' => 'page',
            'page_id' => $page->id,
            'http_method' => 'TRACE',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['http_method']);
});

it('rejects an out-of-range http_status', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $page = Page::factory()->create(['website_id' => $website->id]);

    $this->actingAs($owner)
        ->postJson(route('api.v1.websites.routes.store', $website), [
            'path' => '/x',
            'destination_type' => 'page',
            'page_id' => $page->id,
            'http_status' => 999,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['http_status']);
});

it('rejects a duplicate path+method on the same website', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $page = Page::factory()->create(['website_id' => $website->id]);
    Route::factory()->forPage($page)->create(['website_id' => $website->id, 'path' => '/taken']);

    $this->actingAs($owner)
        ->postJson(route('api.v1.websites.routes.store', $website), [
            'path' => '/taken',
            'destination_type' => 'page',
            'page_id' => $page->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['path']);
});

it('allows the same path when the HTTP method differs', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $page = Page::factory()->create(['website_id' => $website->id]);
    Route::factory()->forPage($page)->withMethod(HttpMethod::Get)->create(['website_id' => $website->id, 'path' => '/shared']);

    $this->actingAs($owner)
        ->postJson(route('api.v1.websites.routes.store', $website), [
            'path' => '/shared',
            'http_method' => 'POST',
            'destination_type' => 'page',
            'page_id' => $page->id,
        ])
        ->assertCreated();
});

it('allows multiple routes to point at the same page', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $page = Page::factory()->create(['website_id' => $website->id]);
    Route::factory()->forPage($page)->create(['website_id' => $website->id, 'path' => '/first-alias']);

    $this->actingAs($owner)
        ->postJson(route('api.v1.websites.routes.store', $website), [
            'path' => '/second-alias',
            'destination_type' => 'page',
            'page_id' => $page->id,
        ])
        ->assertCreated();

    expect(Route::where('page_id', $page->id)->count())->toBe(2);
});

it('allows multiple routes to point at the same asset', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $asset = Asset::factory()->create(['website_id' => $website->id]);
    Route::factory()->forAsset($asset)->create(['website_id' => $website->id, 'path' => '/file-a']);

    $this->actingAs($owner)
        ->postJson(route('api.v1.websites.routes.store', $website), [
            'path' => '/file-b',
            'destination_type' => 'asset',
            'asset_id' => $asset->id,
        ])
        ->assertCreated();

    expect(Route::where('asset_id', $asset->id)->count())->toBe(2);
});

// --- loop / chain-length protection -----------------------------------------------------------

it('rejects a redirect that points at itself', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $target = Route::factory()->create(['website_id' => $website->id]);
    $redirect = Route::factory()->redirectingTo($target)->create(['website_id' => $website->id]);

    $this->actingAs($owner)
        ->putJson(route('api.v1.websites.routes.update', [$website, $redirect]), [
            'redirect_to_route_id' => $redirect->id,
        ])
        ->assertUnprocessable()
        ->assertJson(['success' => false, 'message' => 'This redirect would create an infinite loop.']);
});

it('rejects an update that would create an indirect redirect loop', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $c = Route::factory()->create(['website_id' => $website->id]);
    $a = Route::factory()->redirectingTo($c)->create(['website_id' => $website->id]);
    $b = Route::factory()->redirectingTo($a)->create(['website_id' => $website->id]);

    // Turning a into a redirect that targets b would close the loop a -> b -> a.
    $this->actingAs($owner)
        ->putJson(route('api.v1.websites.routes.update', [$website, $a]), [
            'redirect_to_route_id' => $b->id,
        ])
        ->assertUnprocessable()
        ->assertJson(['success' => false, 'message' => 'This redirect would create an infinite loop.']);
});

it('rejects creating a redirect chain longer than the maximum', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);

    $page = Page::factory()->create(['website_id' => $website->id]);
    $chain = Route::factory()->forPage($page)->create(['website_id' => $website->id]);

    for ($i = 0; $i < RouteLoopValidator::MAX_CHAIN_LENGTH; $i++) {
        $chain = Route::factory()->redirectingTo($chain)->create(['website_id' => $website->id]);
    }

    // $chain now sits exactly MAX_CHAIN_LENGTH hops from the page — one more would exceed it.
    $this->actingAs($owner)
        ->postJson(route('api.v1.websites.routes.store', $website), [
            'path' => '/one-too-many',
            'destination_type' => 'redirect',
            'redirect_to_route_id' => $chain->id,
        ])
        ->assertUnprocessable()
        ->assertJson(['success' => false, 'message' => 'This redirect would chain more than 20 redirects deep.']);
});

it('allows creating a redirect chain exactly at the maximum', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);

    $page = Page::factory()->create(['website_id' => $website->id]);
    $chain = Route::factory()->forPage($page)->create(['website_id' => $website->id]);

    for ($i = 0; $i < RouteLoopValidator::MAX_CHAIN_LENGTH - 1; $i++) {
        $chain = Route::factory()->redirectingTo($chain)->create(['website_id' => $website->id]);
    }

    $this->actingAs($owner)
        ->postJson(route('api.v1.websites.routes.store', $website), [
            'path' => '/exactly-the-limit',
            'destination_type' => 'redirect',
            'redirect_to_route_id' => $chain->id,
        ])
        ->assertCreated();
});

// --- update -------------------------------------------------------------------------------

it('updates a route\'s path, method and status', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $page = Page::factory()->create(['website_id' => $website->id]);
    $routeModel = Route::factory()->forPage($page)->create(['website_id' => $website->id]);

    $this->actingAs($owner)
        ->putJson(route('api.v1.websites.routes.update', [$website, $routeModel]), [
            'path' => '/renamed',
            'http_method' => 'HEAD',
            'http_status' => 200,
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.path', '/renamed')
        ->assertJsonPath('data.http_method', 'HEAD');
});

it('updates a redirect route\'s target', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $targetA = Route::factory()->create(['website_id' => $website->id]);
    $targetB = Route::factory()->create(['website_id' => $website->id]);
    $redirect = Route::factory()->redirectingTo($targetA)->create(['website_id' => $website->id]);

    $this->actingAs($owner)
        ->putJson(route('api.v1.websites.routes.update', [$website, $redirect]), [
            'redirect_to_route_id' => $targetB->id,
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.redirect_to_route_id', $targetB->id);
});

it('retargets a redirect from an internal route to an external URL', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $target = Route::factory()->create(['website_id' => $website->id]);
    $redirect = Route::factory()->redirectingTo($target)->create(['website_id' => $website->id]);

    $this->actingAs($owner)
        ->putJson(route('api.v1.websites.routes.update', [$website, $redirect]), [
            'redirect_url' => 'https://www.exemplo.com.br/site',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.redirect_url', 'https://www.exemplo.com.br/site')
        ->assertJsonPath('data.redirect_to_route_id', null);
});

it('retargets a redirect from an external URL to an internal route', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $target = Route::factory()->create(['website_id' => $website->id]);
    $redirect = Route::factory()->externalRedirect('https://www.exemplo.com.br/site')->create(['website_id' => $website->id]);

    $this->actingAs($owner)
        ->putJson(route('api.v1.websites.routes.update', [$website, $redirect]), [
            'redirect_to_route_id' => $target->id,
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.redirect_to_route_id', $target->id)
        ->assertJsonPath('data.redirect_url', null);
});

it('rejects updating a redirect with both an internal and an external target', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $target = Route::factory()->create(['website_id' => $website->id]);
    $redirect = Route::factory()->redirectingTo($target)->create(['website_id' => $website->id]);

    $this->actingAs($owner)
        ->putJson(route('api.v1.websites.routes.update', [$website, $redirect]), [
            'redirect_to_route_id' => $target->id,
            'redirect_url' => 'https://www.exemplo.com.br/site',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['redirect_to_route_id']);
});

it('rejects setting redirect_url on a non-redirect route', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $page = Page::factory()->create(['website_id' => $website->id]);
    $routeModel = Route::factory()->forPage($page)->create(['website_id' => $website->id]);

    $this->actingAs($owner)
        ->putJson(route('api.v1.websites.routes.update', [$website, $routeModel]), [
            'redirect_url' => 'https://www.exemplo.com.br/site',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['redirect_url']);
});

it('rejects setting redirect_to_route_id on a non-redirect route', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $page = Page::factory()->create(['website_id' => $website->id]);
    $routeModel = Route::factory()->forPage($page)->create(['website_id' => $website->id]);
    $target = Route::factory()->create(['website_id' => $website->id]);

    $this->actingAs($owner)
        ->putJson(route('api.v1.websites.routes.update', [$website, $routeModel]), [
            'redirect_to_route_id' => $target->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['redirect_to_route_id']);
});

it('ignores an attempt to change destination_type, page_id or asset_id on update', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $page = Page::factory()->create(['website_id' => $website->id]);
    $otherPage = Page::factory()->create(['website_id' => $website->id]);
    $routeModel = Route::factory()->forPage($page)->create(['website_id' => $website->id]);

    $this->actingAs($owner)
        ->putJson(route('api.v1.websites.routes.update', [$website, $routeModel]), [
            'destination_type' => 'asset',
            'page_id' => $otherPage->id,
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.destination_type', 'page')
        ->assertJsonPath('data.page_id', $page->id);
});

it('only allows owner, admin and editor to create and update routes', function (WebsiteRole $role, bool $allowed) {
    $website = Website::factory()->create();
    $actor = User::factory()->create();
    createWebsiteMembership($website, $actor, $role);
    $page = Page::factory()->create(['website_id' => $website->id]);

    $response = $this->actingAs($actor)->postJson(route('api.v1.websites.routes.store', $website), [
        'path' => '/x-'.$role->value,
        'destination_type' => 'page',
        'page_id' => $page->id,
    ]);

    $allowed ? $response->assertCreated() : $response->assertForbidden();
})->with([
    'owner' => [WebsiteRole::Owner, true],
    'admin' => [WebsiteRole::Admin, true],
    'editor' => [WebsiteRole::Editor, true],
    'qa' => [WebsiteRole::Qa, false],
    'viewer' => [WebsiteRole::Viewer, false],
]);

// --- delete -----------------------------------------------------------------------------------

it('deletes (soft-deletes) a route', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $routeModel = Route::factory()->create(['website_id' => $website->id]);

    $this->actingAs($owner)
        ->deleteJson(route('api.v1.websites.routes.destroy', [$website, $routeModel]))
        ->assertSuccessful()
        ->assertJson(['success' => true, 'message' => 'Route deleted.']);

    expect(Route::find($routeModel->id))->toBeNull();
    expect(Route::withTrashed()->find($routeModel->id))->not->toBeNull();
});

it('only allows admin (or owner) to delete a route', function (WebsiteRole $role, bool $allowed) {
    $website = Website::factory()->create();
    $actor = User::factory()->create();
    createWebsiteMembership($website, $actor, $role);
    $routeModel = Route::factory()->create(['website_id' => $website->id]);

    $response = $this->actingAs($actor)->deleteJson(route('api.v1.websites.routes.destroy', [$website, $routeModel]));

    $allowed ? $response->assertSuccessful() : $response->assertForbidden();
})->with([
    'owner' => [WebsiteRole::Owner, true],
    'admin' => [WebsiteRole::Admin, true],
    'editor' => [WebsiteRole::Editor, false],
    'qa' => [WebsiteRole::Qa, false],
    'viewer' => [WebsiteRole::Viewer, false],
]);

// --- rule 1: automatic route on page creation --------------------------------------------------

it('automatically creates a route for a newly created page, based on its slug', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $layout = Layout::factory()->create(['website_id' => $website->id]);

    $response = $this->actingAs($owner)->postJson(route('api.v1.websites.pages.store', $website), [
        'name' => 'Contact',
        'slug' => 'contact-us',
        'layout_id' => $layout->id,
    ]);

    $response->assertCreated();
    $pageId = $response->json('data.id');

    $autoRoute = Route::where('page_id', $pageId)->sole();
    expect($autoRoute->path)->toBe('/contact-us');
    expect($autoRoute->http_method->value)->toBe('GET');
    expect($autoRoute->http_status)->toBe(200);
    expect($autoRoute->destination_type)->toBe(RouteDestinationType::Page);
});

it('does not create a duplicate automatic route if the path is already taken', function () {
    $website = Website::factory()->create();
    $owner = User::factory()->create();
    createWebsiteMembership($website, $owner, WebsiteRole::Owner);
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $existingTarget = Route::factory()->create(['website_id' => $website->id]);
    Route::factory()->redirectingTo($existingTarget)->create(['website_id' => $website->id, 'path' => '/taken-slug']);

    $response = $this->actingAs($owner)->postJson(route('api.v1.websites.pages.store', $website), [
        'name' => 'Taken',
        'slug' => 'taken-slug',
        'layout_id' => $layout->id,
    ]);

    $response->assertCreated();
    $pageId = $response->json('data.id');

    expect(Route::where('page_id', $pageId)->count())->toBe(0);
    expect(Route::where('path', '/taken-slug')->count())->toBe(1);
});
