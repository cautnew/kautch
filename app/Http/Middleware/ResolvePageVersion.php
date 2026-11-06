<?php

namespace App\Http\Middleware;

use App\Models\Page;
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
        $page = $request->route('page');

        if (! $page instanceof Page) {
            abort(404);
        }

        if ($request->route('version') === null) {
            $version = $page->publishedVersion ?? $page->latestVersion()->firstOrFail();
            $request->route()->setParameter('version', $version);
        }

        return $next($request);
    }
}
