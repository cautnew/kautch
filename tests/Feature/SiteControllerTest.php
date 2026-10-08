<?php

use App\Models\Asset;
use App\Models\Layout;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\Route;
use App\Models\Website;

/**
 * Same helper as ContentControllerTest: creates a page with a published
 * version and points Page::published_version_id at it, mirroring what
 * PageVersionService::publish() does in production.
 */
function publishSitePage(Website $website, Layout $layout): Page
{
    $page = Page::factory()->create(['website_id' => $website->id, 'layout_id' => $layout->id]);
    $version = PageVersion::factory()->published()->create([
        'page_id' => $page->id,
        'layout_id' => $layout->id,
        'version_number' => 1,
    ]);
    $page->asActor($page->created_by)->update(['published_version_id' => $version->id]);

    return $page->fresh();
}

it('falls back to the platform placeholder for a host that matches no website', function () {
    $this->get('http://no-such-website.test/anything')
        ->assertOk()
        ->assertSee('Este CMS está sendo preparado');
});

it('falls back to the platform placeholder for a suspended website\'s host', function () {
    $website = Website::factory()->suspended()->create();

    $this->get('http://'.$website->domain.'/')
        ->assertOk()
        ->assertSee('Este CMS está sendo preparado');
});

it('serves a page route\'s published content on the website\'s own host', function () {
    $website = Website::factory()->create();
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = publishSitePage($website, $layout);
    Route::factory()->forPage($page)->create(['website_id' => $website->id, 'path' => '/sobre']);

    $this->get('http://'.$website->domain.'/sobre')
        ->assertSuccessful()
        ->assertJsonPath('data.page.id', $page->id)
        ->assertJsonPath('data.page.slug', $page->slug);
});

it('resolves a website by subdomain + domain host', function () {
    $website = Website::factory()->withSubdomain('blog')->create();
    $layout = Layout::factory()->create(['website_id' => $website->id]);
    $page = publishSitePage($website, $layout);
    Route::factory()->forPage($page)->create(['website_id' => $website->id, 'path' => '/post']);

    $this->get('http://'.$website->host.'/post')
        ->assertSuccessful()
        ->assertJsonPath('data.page.id', $page->id);
});

it('returns a 404 JSON response for a page route pointing at an unpublished page', function () {
    $website = Website::factory()->create();
    $page = Page::factory()->create(['website_id' => $website->id]);
    Route::factory()->forPage($page)->create(['website_id' => $website->id, 'path' => '/rascunho']);

    $this->get('http://'.$website->domain.'/rascunho')
        ->assertNotFound()
        ->assertJson(['success' => false]);
});

it('redirects an asset route to its download URL', function () {
    $website = Website::factory()->create();
    $asset = Asset::factory()->create(['website_id' => $website->id]);
    Route::factory()->forAsset($asset)->create(['website_id' => $website->id, 'path' => '/logo.svg']);

    $response = $this->get('http://'.$website->domain.'/logo.svg');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain($asset->path);
});

it('redirects an internal redirect route to its final destination on the same host', function () {
    $website = Website::factory()->create();
    $page = Page::factory()->create(['website_id' => $website->id]);
    $target = Route::factory()->forPage($page)->create(['website_id' => $website->id, 'path' => '/novo']);
    Route::factory()->redirectingTo($target, 301)->create(['website_id' => $website->id, 'path' => '/antigo']);

    $this->get('http://'.$website->domain.'/antigo')
        ->assertRedirect('http://'.$website->domain.'/novo')
        ->assertStatus(301);
});

it('redirects an external redirect route to its external URL', function () {
    $website = Website::factory()->create();
    Route::factory()->externalRedirect('https://www.exemplo.com.br/site', 302)->create(['website_id' => $website->id, 'path' => '/vai-embora']);

    $this->get('http://'.$website->domain.'/vai-embora')
        ->assertRedirect('https://www.exemplo.com.br/site')
        ->assertStatus(302);
});

it('returns a 404 JSON response for a path with no matching route on a known website', function () {
    $website = Website::factory()->create();

    $this->get('http://'.$website->domain.'/does-not-exist')
        ->assertNotFound()
        ->assertJson(['success' => false]);
});
