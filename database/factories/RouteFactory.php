<?php

namespace Database\Factories;

use App\Enums\HttpMethod;
use App\Enums\RouteDestinationType;
use App\Models\Asset;
use App\Models\Page;
use App\Models\Route;
use App\Models\User;
use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Route>
 */
class RouteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'website_id' => Website::factory(),
            'path' => '/'.fake()->unique()->slug(),
            'http_method' => HttpMethod::Get,
            'destination_type' => RouteDestinationType::Page,
            'page_id' => fn (array $attributes) => Page::factory()->create(['website_id' => $attributes['website_id']])->id,
            'asset_id' => null,
            'redirect_to_route_id' => null,
            'redirect_url' => null,
            'http_status' => 200,
            'created_by' => User::factory(),
        ];
    }

    /**
     * Indicate that this route serves the given page.
     */
    public function forPage(Page $page): static
    {
        return $this->state(fn () => [
            'website_id' => $page->website_id,
            'destination_type' => RouteDestinationType::Page,
            'page_id' => $page->id,
            'asset_id' => null,
            'redirect_to_route_id' => null,
            'redirect_url' => null,
        ]);
    }

    /**
     * Indicate that this route serves the given asset.
     */
    public function forAsset(Asset $asset): static
    {
        return $this->state(fn () => [
            'website_id' => $asset->website_id,
            'destination_type' => RouteDestinationType::Asset,
            'page_id' => null,
            'asset_id' => $asset->id,
            'redirect_to_route_id' => null,
            'redirect_url' => null,
            'http_status' => 200,
        ]);
    }

    /**
     * Indicate that this route redirects to the given route.
     */
    public function redirectingTo(Route $target, int $status = 301): static
    {
        return $this->state(fn () => [
            'website_id' => $target->website_id,
            'destination_type' => RouteDestinationType::Redirect,
            'page_id' => null,
            'asset_id' => null,
            'redirect_to_route_id' => $target->id,
            'redirect_url' => null,
            'http_status' => $status,
        ]);
    }

    /**
     * Indicate that this route redirects to an external URL.
     */
    public function externalRedirect(?string $url = null, int $status = 301): static
    {
        return $this->state(fn (array $attributes) => [
            'destination_type' => RouteDestinationType::Redirect,
            'page_id' => null,
            'asset_id' => null,
            'redirect_to_route_id' => null,
            'redirect_url' => $url ?? fake()->url(),
            'http_status' => $status,
        ]);
    }

    /**
     * Indicate that this route responds to a specific HTTP method.
     */
    public function withMethod(HttpMethod $method): static
    {
        return $this->state(fn () => [
            'http_method' => $method,
        ]);
    }
}
