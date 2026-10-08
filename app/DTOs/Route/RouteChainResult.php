<?php

namespace App\DTOs\Route;

use App\Enums\RouteChainOutcome;
use App\Models\Route;

final readonly class RouteChainResult
{
    private function __construct(
        public RouteChainOutcome $outcome,
        public ?Route $route,
        public int $hops,
    ) {}

    public static function resolved(Route $route, int $hops): self
    {
        return new self(RouteChainOutcome::Resolved, $route, $hops);
    }

    /**
     * $route is the redirect whose redirect_url holds the external destination.
     */
    public static function externalRedirect(Route $route, int $hops): self
    {
        return new self(RouteChainOutcome::ExternalRedirect, $route, $hops);
    }

    public static function loop(int $hops): self
    {
        return new self(RouteChainOutcome::Loop, null, $hops);
    }

    public static function tooManyRedirects(int $hops): self
    {
        return new self(RouteChainOutcome::TooManyRedirects, null, $hops);
    }

    public static function broken(int $hops): self
    {
        return new self(RouteChainOutcome::Broken, null, $hops);
    }
}
