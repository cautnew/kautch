<?php

namespace App\Services;

use App\DTOs\Route\RouteChainResult;
use App\Enums\RouteChainOutcome;
use App\Enums\RouteDestinationType;
use App\Exceptions\RouteLoopException;
use App\Exceptions\TooManyRedirectsException;
use App\Repositories\Contracts\RouteRepositoryInterface;

/**
 * Walks chains of redirect routes — the single place that knows how to
 * detect a cycle or an over-long chain, reused both to validate a redirect
 * before it's written (assertNoLoop) and to actually resolve one at read
 * time (RouteResolverService, via walk()).
 */
final class RouteLoopValidator
{
    /**
     * A chain longer than this many redirect hops is rejected outright,
     * regardless of whether it eventually terminates or loops.
     */
    public const MAX_CHAIN_LENGTH = 20;

    public function __construct(
        private readonly RouteRepositoryInterface $routes,
    ) {}

    /**
     * Throws if pointing a redirect at $targetRouteId would create a loop,
     * or a chain longer than MAX_CHAIN_LENGTH. Called before a redirect-type
     * route is created or updated. $sourceRouteId is the route being
     * written — pass null when creating one (it has no id of its own yet to
     * loop back to, so only the length/pre-existing-loop checks apply).
     */
    public function assertNoLoop(?string $sourceRouteId, string $targetRouteId): void
    {
        // +1 for the source -> target hop this assignment itself introduces,
        // so the walk's hop count reflects the *whole* chain's length.
        $result = $this->walk($targetRouteId, $sourceRouteId, startingHops: 1);

        if ($result->outcome === RouteChainOutcome::Loop) {
            throw new RouteLoopException;
        }

        if ($result->outcome === RouteChainOutcome::TooManyRedirects) {
            throw new TooManyRedirectsException;
        }
    }

    /**
     * Follows redirect_to_route_id starting at $startRouteId until it hits a
     * non-redirect route (Resolved), revisits a route already seen in this
     * walk or $originRouteId (Loop), exceeds MAX_CHAIN_LENGTH (TooManyRedirects),
     * or a target route no longer exists (Broken).
     *
     * $originRouteId is the route the chain must not loop back to — pass the
     * id of the route being created/updated when validating a new redirect;
     * omit it when simply resolving an existing, already-valid chain.
     * $startingHops lets a caller account for a hop that precedes
     * $startRouteId (e.g. the source -> target assignment being validated)
     * without that hop's own route existing to walk through.
     */
    public function walk(string $startRouteId, ?string $originRouteId = null, int $startingHops = 0): RouteChainResult
    {
        $currentId = $startRouteId;
        $visited = [];
        $hops = $startingHops;

        while (true) {
            // Checked before resolving the current node so that a chain
            // whose *final* (non-redirect) node only becomes reachable past
            // the limit is still correctly rejected, not silently resolved.
            if ($hops > self::MAX_CHAIN_LENGTH) {
                return RouteChainResult::tooManyRedirects($hops);
            }

            if ($currentId === $originRouteId || isset($visited[$currentId])) {
                return RouteChainResult::loop($hops);
            }

            $route = $this->routes->find($currentId);

            if ($route === null) {
                return RouteChainResult::broken($hops);
            }

            $visited[$currentId] = true;

            if ($route->destination_type !== RouteDestinationType::Redirect) {
                return RouteChainResult::resolved($route, $hops);
            }

            if ($route->redirect_url !== null) {
                return RouteChainResult::externalRedirect($route, $hops);
            }

            if ($route->redirect_to_route_id === null) {
                return RouteChainResult::broken($hops);
            }

            $currentId = $route->redirect_to_route_id;
            $hops++;
        }
    }
}
