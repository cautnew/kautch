<?php

namespace App\Http\Middleware;

use App\Models\Page;
use App\Models\PageVersion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolvePageVersion
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $page = $route->parameter('page');
        $version = $route->parameter('version');

        if ($page === null && $version instanceof PageVersion) {
            $page = $version->page;

            if (! $page instanceof Page) {
                abort(404);
            }

            $route->forgetParameter('version');
            $route->setParameter('page', $page);
            $route->setParameter('version', $version);
        }

        if (! $page instanceof Page) {
            abort(404);
        }

        if ($version === null) {
            $version = $page->publishedVersion ?? $page->latestVersion()->firstOrFail();
            $route->setParameter('version', $version);
        }

        return $next($request);
    }
}
