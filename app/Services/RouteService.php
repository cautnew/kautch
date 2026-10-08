<?php

namespace App\Services;

use App\DTOs\Route\CreateRouteData;
use App\DTOs\Route\UpdateRouteData;
use App\Enums\RouteDestinationType;
use App\Models\Route;
use App\Models\User;
use App\Models\Website;
use App\Repositories\Contracts\RouteRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class RouteService
{
    public function __construct(
        private readonly RouteRepositoryInterface $routes,
        private readonly RouteLoopValidator $loopValidator,
    ) {}

    public function listForWebsite(Website $website, int $perPage = 15): LengthAwarePaginator
    {
        return $this->routes->paginateForWebsite($website, $perPage);
    }

    /**
     * Resolve a route guaranteed to belong to the given website.
     */
    public function findForWebsite(Website $website, Route $route): Route
    {
        return $this->routes->findForWebsite($website, $route);
    }

    public function create(Website $website, User $creator, CreateRouteData $data): Route
    {
        if ($data->destinationType === RouteDestinationType::Redirect && $data->redirectToRouteId !== null) {
            $this->loopValidator->assertNoLoop(null, $data->redirectToRouteId);
        }

        return $this->routes->create($website, [
            ...$data->toArray(),
            'created_by' => $creator->id,
        ]);
    }

    public function update(Website $website, Route $route, UpdateRouteData $data): Route
    {
        $route = $this->routes->findForWebsite($website, $route);

        $attributes = $data->toArray();

        if ($route->destination_type === RouteDestinationType::Redirect) {
            if (array_key_exists('redirect_to_route_id', $attributes)) {
                $this->loopValidator->assertNoLoop($route->id, $attributes['redirect_to_route_id']);
                // A redirect targets exactly one of the two columns — retargeting
                // internally clears whichever external URL it previously had.
                $attributes['redirect_url'] = null;
            } elseif (array_key_exists('redirect_url', $attributes)) {
                $attributes['redirect_to_route_id'] = null;
            }
        }

        return $this->routes->update($route, $attributes);
    }

    public function delete(Website $website, Route $route): void
    {
        $route = $this->routes->findForWebsite($website, $route);

        $this->routes->delete($route);
    }
}
