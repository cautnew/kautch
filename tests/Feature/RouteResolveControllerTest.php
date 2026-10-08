<?php

use App\Enums\HttpMethod;
use App\Models\Asset;
use App\Models\Page;
use App\Models\Route;
use App\Models\User;
use App\Models\Website;
use App\Services\RouteLoopValidator;

it('does not require authentication', function () {
    $website = Website::factory()->create();
    $page = Page::factory()->create(['website_id' => $website->id]);
    Route::factory()->forPage($page)->create(['website_id' => $website->id, 'path' => '/home']);

    $this->getJson(route('api.v1.websites.routes.resolve', $website).'?path=/home')
        ->assertSuccessful();
});

it('resolves a path to a page', function () {
    $website = Website::factory()->create();
    $page = Page::factory()->create(['website_id' => $website->id]);
    Route::factory()->forPage($page)->create(['website_id' => $website->id, 'path' => '/about']);

    $this->getJson(route('api.v1.websites.routes.resolve', $website).'?path=/about')
        ->assertSuccessful()
        ->assertJsonPath('data.outcome', 'page')
        ->assertJsonPath('data.http_status', 200)
        ->assertJsonPath('data.page.id', $page->id);
});

it('resolves a path to an asset', function () {
    $website = Website::factory()->create();
    $asset = Asset::factory()->create(['website_id' => $website->id]);
    Route::factory()->forAsset($asset)->create(['website_id' => $website->id, 'path' => '/logo.png']);

    $this->getJson(route('api.v1.websites.routes.resolve', $website).'?path=/logo.png')
        ->assertSuccessful()
        ->assertJsonPath('data.outcome', 'asset')
        ->assertJsonPath('data.asset.id', $asset->id);
});

it('resolves a redirect to its immediate target path', function () {
    $website = Website::factory()->create();
    $page = Page::factory()->create(['website_id' => $website->id]);
    $target = Route::factory()->forPage($page)->create(['website_id' => $website->id, 'path' => '/new-home']);
    Route::factory()->redirectingTo($target, 301)->create(['website_id' => $website->id, 'path' => '/old-home']);

    $this->getJson(route('api.v1.websites.routes.resolve', $website).'?path=/old-home')
        ->assertSuccessful()
        ->assertJsonPath('data.outcome', 'redirect')
        ->assertJsonPath('data.http_status', 301)
        ->assertJsonPath('data.redirect_to', '/new-home');
});

it('resolves a redirect to an external URL', function () {
    $website = Website::factory()->create();
    Route::factory()->externalRedirect('https://www.exemplo.com.br/site', 302)->create(['website_id' => $website->id, 'path' => '/vai-embora']);

    $this->getJson(route('api.v1.websites.routes.resolve', $website).'?path=/vai-embora')
        ->assertSuccessful()
        ->assertJsonPath('data.outcome', 'external_redirect')
        ->assertJsonPath('data.http_status', 302)
        ->assertJsonPath('data.redirect_to', 'https://www.exemplo.com.br/site');
});

it('follows an internal redirect chain that ends at an external URL', function () {
    $website = Website::factory()->create();
    $external = Route::factory()->externalRedirect('https://www.exemplo.com.br/destino')->create(['website_id' => $website->id]);
    Route::factory()->redirectingTo($external)->create(['website_id' => $website->id, 'path' => '/entrada']);

    $this->getJson(route('api.v1.websites.routes.resolve', $website).'?path=/entrada')
        ->assertSuccessful()
        ->assertJsonPath('data.outcome', 'external_redirect')
        ->assertJsonPath('data.redirect_to', 'https://www.exemplo.com.br/destino');
});

it('follows a chain of redirects to the final destination', function () {
    $website = Website::factory()->create();
    $page = Page::factory()->create(['website_id' => $website->id]);
    $final = Route::factory()->forPage($page)->create(['website_id' => $website->id, 'path' => '/final']);
    $middle = Route::factory()->redirectingTo($final)->create(['website_id' => $website->id, 'path' => '/middle']);
    Route::factory()->redirectingTo($middle)->create(['website_id' => $website->id, 'path' => '/first']);

    $this->getJson(route('api.v1.websites.routes.resolve', $website).'?path=/first')
        ->assertSuccessful()
        ->assertJsonPath('data.outcome', 'redirect')
        ->assertJsonPath('data.redirect_to', '/final');
});

it('returns not_found for an unknown path', function () {
    $website = Website::factory()->create();

    $this->getJson(route('api.v1.websites.routes.resolve', $website).'?path=/does-not-exist')
        ->assertSuccessful()
        ->assertJsonPath('data.outcome', 'not_found')
        ->assertJsonPath('data.http_status', 404);
});

it('matches the requested HTTP method, not just the path', function () {
    $website = Website::factory()->create();
    $page = Page::factory()->create(['website_id' => $website->id]);
    Route::factory()->forPage($page)->withMethod(HttpMethod::Post)->create(['website_id' => $website->id, 'path' => '/submit']);

    $this->getJson(route('api.v1.websites.routes.resolve', $website).'?path=/submit&method=GET')
        ->assertJsonPath('data.outcome', 'not_found');

    $this->getJson(route('api.v1.websites.routes.resolve', $website).'?path=/submit&method=POST')
        ->assertJsonPath('data.outcome', 'page');
});

it('normalizes trailing slashes when resolving', function () {
    $website = Website::factory()->create();
    $page = Page::factory()->create(['website_id' => $website->id]);
    Route::factory()->forPage($page)->create(['website_id' => $website->id, 'path' => '/trailing']);

    $this->getJson(route('api.v1.websites.routes.resolve', $website).'?path=/trailing/')
        ->assertJsonPath('data.outcome', 'page');
});

it('reports loop_detected when a chain (created outside normal validation) loops back on itself', function () {
    $website = Website::factory()->create();
    $c = Route::factory()->create(['website_id' => $website->id]);
    $a = Route::factory()->redirectingTo($c)->create(['website_id' => $website->id, 'path' => '/a']);
    $b = Route::factory()->redirectingTo($a)->create(['website_id' => $website->id, 'path' => '/b']);
    $actor = User::factory()->create();
    $a->asActor($actor)->forceFill(['redirect_to_route_id' => $b->id])->save();

    $this->getJson(route('api.v1.websites.routes.resolve', $website).'?path=/a')
        ->assertSuccessful()
        ->assertJsonPath('data.outcome', 'loop_detected')
        ->assertJsonPath('data.http_status', 508);
});

it('reports too_many_redirects when a chain (created outside normal validation) exceeds the maximum', function () {
    $website = Website::factory()->create();
    $page = Page::factory()->create(['website_id' => $website->id]);
    $chain = Route::factory()->forPage($page)->create(['website_id' => $website->id, 'path' => '/deep-destination']);

    for ($i = 0; $i <= RouteLoopValidator::MAX_CHAIN_LENGTH; $i++) {
        $chain = Route::factory()->redirectingTo($chain)->create(['website_id' => $website->id]);
    }

    $this->getJson(route('api.v1.websites.routes.resolve', $website).'?path='.$chain->path)
        ->assertSuccessful()
        ->assertJsonPath('data.outcome', 'too_many_redirects')
        ->assertJsonPath('data.http_status', 508);
});

it('validates the path is required', function () {
    $website = Website::factory()->create();

    $this->getJson(route('api.v1.websites.routes.resolve', $website))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['path']);
});
