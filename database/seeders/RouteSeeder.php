<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\Page;
use App\Models\Route;
use App\Models\User;
use App\Models\Website;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Seeds a representative set of Routes on top of DemoContentSeeder's pages
 * and assets: the friendly URL each page would get automatically in
 * production (see App\Listeners\CreateDefaultRouteForPage — not dispatched
 * here, since DemoContentSeeder creates pages via the factory, not
 * PageService::create()), a couple of extra aliases pointing at the same
 * page, friendly download URLs for a few assets, and both a direct and a
 * chained redirect.
 *
 * Assumes DemoContentSeeder has already run. Not self-chained the way
 * WebsiteSeeder/DemoContentSeeder call their own dependencies, because
 * DemoContentSeeder isn't idempotent — calling it again here would
 * duplicate every page, version and piece it creates.
 */
class RouteSeeder extends Seeder
{
    public function run(): void
    {
        $website = Website::query()->where('domain', WebsiteSeeder::domain())->firstOrFail();
        $admin = User::query()->where('email', 'admin.demo@kautch.test')->firstOrFail();

        $pages = Page::query()->where('website_id', $website->id)->get()->keyBy('slug');

        $pageRoutes = $this->seedPageRoutes($website, $pages, $admin);
        $this->seedPageAliases($pages, $admin);
        $assetRoutes = $this->seedAssetRoutes($website, $admin);
        $redirects = $this->seedRedirects($website, $pageRoutes, $admin);

        $this->command?->info(sprintf(
            'Routes seeded: %d page routes, %d asset routes, %d redirects.',
            $pageRoutes->count(),
            $assetRoutes->count(),
            $redirects->count(),
        ));
    }

    /**
     * The default, slug-based route every page gets — mirrors what
     * CreateDefaultRouteForPage would create on the real PageCreated event.
     *
     * @param  Collection<string, Page>  $pages  keyed by slug
     * @return Collection<string, Route> keyed by page slug
     */
    private function seedPageRoutes(Website $website, Collection $pages, User $admin): Collection
    {
        return $pages->map(fn (Page $page): Route => Route::factory()->forPage($page)->create([
            'website_id' => $website->id,
            'path' => Route::normalizePath('/'.$page->slug),
            ...$this->stamps($admin),
        ]));
    }

    /**
     * Extra, human-friendly aliases for pages that already have a default
     * route — demonstrates multiple routes pointing at the same Page.
     *
     * @param  Collection<string, Page>  $pages  keyed by slug
     */
    private function seedPageAliases(Collection $pages, User $admin): void
    {
        $home = $pages->get('pagina-demo-1');
        if ($home !== null) {
            Route::factory()->forPage($home)->create([
                'website_id' => $home->website_id,
                'path' => '/',
                ...$this->stamps($admin),
            ]);
        }

        $landing = $pages->get('pagina-demo-6');
        if ($landing !== null) {
            Route::factory()->forPage($landing)->create([
                'website_id' => $landing->website_id,
                'path' => '/promocao',
                ...$this->stamps($admin),
            ]);
        }
    }

    /**
     * Friendly download URLs for a couple of the demo assets.
     *
     * @return Collection<int, Route>
     */
    private function seedAssetRoutes(Website $website, User $admin): Collection
    {
        $routes = collect();

        $logo = Asset::query()->where('website_id', $website->id)->where('filename', 'layout-asset-1.svg')->first();
        if ($logo !== null) {
            $routes->push(Route::factory()->forAsset($logo)->create([
                'website_id' => $website->id,
                'path' => '/logo.svg',
                ...$this->stamps($admin),
            ]));
        }

        $featured = Asset::query()->where('website_id', $website->id)->where('filename', 'page-1-featured.jpg')->first();
        if ($featured !== null) {
            $routes->push(Route::factory()->forAsset($featured)->create([
                'website_id' => $website->id,
                'path' => '/imagens/pagina-1-destaque.jpg',
                ...$this->stamps($admin),
            ]));
        }

        return $routes;
    }

    /**
     * A direct redirect (an old page URL) plus a two-hop chain —
     * RouteResolverService follows both to their final destination, and
     * RouteLoopValidator is what keeps this kind of thing from ever
     * forming a cycle.
     *
     * @param  Collection<string, Route>  $pageRoutes  keyed by page slug
     * @return Collection<int, Route>
     */
    private function seedRedirects(Website $website, Collection $pageRoutes, User $admin): Collection
    {
        $redirects = collect();

        $pageThree = $pageRoutes->get('pagina-demo-3');
        if ($pageThree !== null) {
            $redirects->push(Route::factory()->redirectingTo($pageThree)->create([
                'website_id' => $website->id,
                'path' => '/pagina-antiga-3',
                ...$this->stamps($admin),
            ]));
        }

        $pageSix = $pageRoutes->get('pagina-demo-6');
        if ($pageSix !== null) {
            $intermediate = Route::factory()->redirectingTo($pageSix)->create([
                'website_id' => $website->id,
                'path' => '/oferta-especial',
                ...$this->stamps($admin),
            ]);
            $redirects->push($intermediate);

            $redirects->push(Route::factory()->redirectingTo($intermediate)->create([
                'website_id' => $website->id,
                'path' => '/campanha-2024',
                ...$this->stamps($admin),
            ]));
        }

        return $redirects;
    }

    /**
     * created_by/updated_by, both explicit: DatabaseSeeder runs under
     * WithoutModelEvents, so HasUserstamps' creating-event fallback (copying
     * created_by into updated_by) never fires here.
     *
     * @return array{created_by: string, updated_by: string}
     */
    private function stamps(User $admin): array
    {
        return ['created_by' => $admin->id, 'updated_by' => $admin->id];
    }
}
