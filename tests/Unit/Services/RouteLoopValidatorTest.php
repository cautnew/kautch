<?php

use App\Enums\RouteChainOutcome;
use App\Enums\RouteDestinationType;
use App\Exceptions\RouteLoopException;
use App\Exceptions\TooManyRedirectsException;
use App\Models\Page;
use App\Models\Route;
use App\Models\Website;
use App\Services\RouteLoopValidator;

/**
 * Builds a chain of $count redirect routes ending at a real page-serving
 * route, oldest (the final destination) first: [destination, ...redirects],
 * so index 0 is what everything ultimately resolves to and the last entry
 * is the "entry point" a caller would typically start walking from.
 *
 * @return array<int, Route>
 */
function buildRedirectChain(Website $website, int $redirectCount): array
{
    $page = Page::factory()->create(['website_id' => $website->id]);
    $chain = [Route::factory()->forPage($page)->create(['website_id' => $website->id])];

    for ($i = 0; $i < $redirectCount; $i++) {
        $chain[] = Route::factory()->redirectingTo($chain[count($chain) - 1])->create(['website_id' => $website->id]);
    }

    return $chain;
}

it('resolves immediately when the start route is not a redirect', function () {
    $website = Website::factory()->create();
    $page = Page::factory()->create(['website_id' => $website->id]);
    $route = Route::factory()->forPage($page)->create(['website_id' => $website->id]);

    $result = app(RouteLoopValidator::class)->walk($route->id);

    expect($result->outcome)->toBe(RouteChainOutcome::Resolved);
    expect($result->route->is($route))->toBeTrue();
    expect($result->hops)->toBe(0);
});

it('follows a short chain of redirects to its final destination', function () {
    $website = Website::factory()->create();
    [$destination, $hop1, $hop2] = buildRedirectChain($website, 2);

    $result = app(RouteLoopValidator::class)->walk($hop2->id);

    expect($result->outcome)->toBe(RouteChainOutcome::Resolved);
    expect($result->route->is($destination))->toBeTrue();
    expect($result->hops)->toBe(2);
});

it('detects a chain looping back to the walk origin', function () {
    $website = Website::factory()->create();
    $a = Route::factory()->create(['website_id' => $website->id]);
    $b = Route::factory()->redirectingTo($a)->create(['website_id' => $website->id]);
    // Manually rewire $a to redirect to $b, forming a cycle a -> b -> a
    // (bypassing RouteService/RouteLoopValidator, which would normally forbid this).
    $a->asActor($a->created_by)->forceFill(['destination_type' => RouteDestinationType::Redirect, 'page_id' => null, 'redirect_to_route_id' => $b->id])->save();

    $result = app(RouteLoopValidator::class)->walk($a->id, originRouteId: $a->id);

    expect($result->outcome)->toBe(RouteChainOutcome::Loop);
});

it('detects a loop among routes that does not involve the walk origin', function () {
    $website = Website::factory()->create();
    $a = Route::factory()->create(['website_id' => $website->id]);
    $b = Route::factory()->redirectingTo($a)->create(['website_id' => $website->id]);
    $a->asActor($a->created_by)->forceFill(['destination_type' => RouteDestinationType::Redirect, 'page_id' => null, 'redirect_to_route_id' => $b->id])->save();
    $entry = Route::factory()->redirectingTo($b)->create(['website_id' => $website->id]);

    $result = app(RouteLoopValidator::class)->walk($entry->id);

    expect($result->outcome)->toBe(RouteChainOutcome::Loop);
});

it('reports too many redirects once the chain exceeds the maximum length', function () {
    $website = Website::factory()->create();
    $chain = buildRedirectChain($website, RouteLoopValidator::MAX_CHAIN_LENGTH + 1);
    $entry = $chain[count($chain) - 1];

    $result = app(RouteLoopValidator::class)->walk($entry->id);

    expect($result->outcome)->toBe(RouteChainOutcome::TooManyRedirects);
});

it('resolves a chain exactly at the maximum length', function () {
    $website = Website::factory()->create();
    $chain = buildRedirectChain($website, RouteLoopValidator::MAX_CHAIN_LENGTH);
    $entry = $chain[count($chain) - 1];
    $destination = $chain[0];

    $result = app(RouteLoopValidator::class)->walk($entry->id);

    expect($result->outcome)->toBe(RouteChainOutcome::Resolved);
    expect($result->route->is($destination))->toBeTrue();
});

it('terminates a walk at a redirect pointing to an external URL', function () {
    $website = Website::factory()->create();
    $external = Route::factory()->externalRedirect('https://www.exemplo.com.br/site')->create(['website_id' => $website->id]);

    $result = app(RouteLoopValidator::class)->walk($external->id);

    expect($result->outcome)->toBe(RouteChainOutcome::ExternalRedirect);
    expect($result->route->is($external))->toBeTrue();
});

it('follows an internal chain that terminates at an external URL', function () {
    $website = Website::factory()->create();
    $external = Route::factory()->externalRedirect('https://www.exemplo.com.br/site')->create(['website_id' => $website->id]);
    $entry = Route::factory()->redirectingTo($external)->create(['website_id' => $website->id]);

    $result = app(RouteLoopValidator::class)->walk($entry->id);

    expect($result->outcome)->toBe(RouteChainOutcome::ExternalRedirect);
    expect($result->route->is($external))->toBeTrue();
    expect($result->hops)->toBe(1);
});

it('reports a broken chain when the redirect target has been soft-deleted', function () {
    $website = Website::factory()->create();
    $page = Page::factory()->create(['website_id' => $website->id]);
    $target = Route::factory()->forPage($page)->create(['website_id' => $website->id]);
    $entry = Route::factory()->redirectingTo($target)->create(['website_id' => $website->id]);
    $target->asActor($target->created_by)->delete();

    $result = app(RouteLoopValidator::class)->walk($entry->redirect_to_route_id);

    expect($result->outcome)->toBe(RouteChainOutcome::Broken);
});

it('assertNoLoop throws for a direct self-reference', function () {
    $website = Website::factory()->create();
    $route = Route::factory()->create(['website_id' => $website->id]);

    expect(fn () => app(RouteLoopValidator::class)->assertNoLoop($route->id, $route->id))
        ->toThrow(RouteLoopException::class);
});

it('assertNoLoop throws for an indirect loop', function () {
    $website = Website::factory()->create();
    $a = Route::factory()->create(['website_id' => $website->id]);
    $b = Route::factory()->redirectingTo($a)->create(['website_id' => $website->id]);

    // Would a -> b -> a create a loop? Yes: validating a's redirect to b.
    expect(fn () => app(RouteLoopValidator::class)->assertNoLoop($a->id, $b->id))
        ->toThrow(RouteLoopException::class);
});

it('assertNoLoop throws when the resulting chain would be too long', function () {
    $website = Website::factory()->create();
    $chain = buildRedirectChain($website, RouteLoopValidator::MAX_CHAIN_LENGTH);
    $entry = $chain[count($chain) - 1];
    $newSource = Route::factory()->create(['website_id' => $website->id]);

    expect(fn () => app(RouteLoopValidator::class)->assertNoLoop($newSource->id, $entry->id))
        ->toThrow(TooManyRedirectsException::class);
});

it('assertNoLoop passes silently for a short, valid chain', function () {
    $website = Website::factory()->create();
    [$destination, $hop1] = buildRedirectChain($website, 1);
    $newSource = Route::factory()->create(['website_id' => $website->id]);

    app(RouteLoopValidator::class)->assertNoLoop($newSource->id, $hop1->id);
})->throwsNoExceptions();

it('assertNoLoop allows a null source (create-time, no self id yet)', function () {
    $website = Website::factory()->create();
    [$destination, $hop1] = buildRedirectChain($website, 1);

    app(RouteLoopValidator::class)->assertNoLoop(null, $hop1->id);
})->throwsNoExceptions();
