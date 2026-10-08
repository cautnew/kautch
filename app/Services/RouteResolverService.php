<?php

namespace App\Services;

use App\DTOs\Route\RouteResolution;
use App\Enums\HttpMethod;
use App\Enums\RouteChainOutcome;
use App\Models\Website;
use App\Repositories\Contracts\RouteRepositoryInterface;

/**
 * The single place a path+method gets turned into "what does this actually
 * serve" — a page, an asset, a (possibly chained) redirect, or nothing.
 * Redirect chains are fully walked up front (via RouteLoopValidator) so a
 * loop or an over-long chain is reported as its own outcome instead of
 * silently bouncing the caller through 3xx responses forever.
 */
final class RouteResolverService
{
    public function __construct(
        private readonly RouteRepositoryInterface $routes,
        private readonly RouteLoopValidator $loopValidator,
    ) {}

    public function resolve(Website $website, string $path, HttpMethod $method = HttpMethod::Get): RouteResolution
    {
        $matched = $this->routes->findMatching($website, $path, $method);

        if ($matched === null) {
            return RouteResolution::notFound();
        }

        if (! $matched->is_redirect) {
            return RouteResolution::destination($matched);
        }

        if ($matched->redirect_url !== null) {
            return RouteResolution::externalRedirect($matched, $matched);
        }

        if ($matched->redirect_to_route_id === null) {
            return RouteResolution::broken($matched);
        }

        $chain = $this->loopValidator->walk($matched->redirect_to_route_id, originRouteId: $matched->id, startingHops: 1);

        return match ($chain->outcome) {
            RouteChainOutcome::Resolved => RouteResolution::redirect($matched, $chain->route),
            RouteChainOutcome::ExternalRedirect => RouteResolution::externalRedirect($matched, $chain->route),
            RouteChainOutcome::Loop => RouteResolution::loopDetected($matched),
            RouteChainOutcome::TooManyRedirects => RouteResolution::tooManyRedirects($matched),
            RouteChainOutcome::Broken => RouteResolution::broken($matched),
        };
    }
}
