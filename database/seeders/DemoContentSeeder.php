<?php

namespace Database\Seeders;

use App\Enums\WebsiteRole;
use App\Models\Asset;
use App\Models\Layout;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\Piece;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds a full, realistic demo tenant on top of WebsiteSeeder's base website:
 *
 *   - 1 Owner, 1 Admin, 1 QA, 2 Editors, 1 Viewer (the Owner comes from
 *     WebsiteSeeder; the other five are created here)
 *   - 3 Layouts
 *   - 20 Assets (10 layout-owned, 10 page-version-owned)
 *   - 10 Pages, each with 3 PageVersions (an archived one, a rejected one,
 *     and the current published one) and a nested piece tree per version
 *
 * Assumes a fresh database — unlike AdminSeeder/WebsiteSeeder this does not
 * attempt to be re-run-safe for the content it creates (pages, versions,
 * pieces, assets), only for the identities (users + their membership).
 */
class DemoContentSeeder extends Seeder
{
    private const LAYOUT_DEFINITIONS = [
        [
            'name' => 'Home Layout',
            'slug' => 'home-layout',
            'description' => "Landing layout for the site's home page.",
            'schema' => ['regions' => ['header', 'hero', 'main', 'footer']],
            'is_default' => true,
        ],
        [
            'name' => 'Article Layout',
            'slug' => 'article-layout',
            'description' => 'Layout for long-form article/blog pages.',
            'schema' => ['regions' => ['header', 'main', 'sidebar', 'footer']],
            'is_default' => false,
        ],
        [
            'name' => 'Landing Layout',
            'slug' => 'landing-layout',
            'description' => 'Conversion-focused layout for campaign pages.',
            'schema' => ['regions' => ['hero', 'main', 'cta', 'footer']],
            'is_default' => false,
        ],
    ];

    private const PAGE_COUNT = 10;

    private const VERSIONS_PER_PAGE = 3;

    /**
     * @var array<string, User|array<int, User>>
     */
    private array $users;

    public function run(): void
    {
        $this->call(WebsiteSeeder::class);

        $website = Website::query()->where('domain', WebsiteSeeder::domain())->firstOrFail();

        $this->users = $this->seedUsers($website);
        $layouts = $this->seedLayouts($website);
        $layoutAssets = $this->seedLayoutAssets($layouts);
        $pages = $this->seedPages($website, $layouts, $layoutAssets);

        $userCount = 4 + count($this->users['editors']); // owner, admin, qa, viewer + editors

        $this->command?->info(sprintf(
            implode(' ', [
                'Demo content seeded:',
                '%d users,',
                '%d layouts,',
                '%d assets,',
                '%d pages,',
                '%d page versions.',
            ]),
            $userCount,
            $layouts->count(),
            $layoutAssets->count() + self::PAGE_COUNT,
            $pages->count(),
            $pages->count() * self::VERSIONS_PER_PAGE,
        ));
    }

    /**
     * The Owner already exists (created by WebsiteSeeder); the other five
     * roles are created here, each with a membership on the demo website.
     *
     * @return array {
     *               owner: User, admin: User, qa: User, viewer: User,
     *               editors: array<int, User>
     *               }
     */
    private function seedUsers(Website $website): array
    {
        $owner = User::query()->where('email', AdminSeeder::EMAIL)->firstOrFail();

        $admin = $this->seedMember($website, $owner, WebsiteRole::Admin, 'admin.demo@kautch.test', 'Ana Administradora');
        $qa = $this->seedMember($website, $owner, WebsiteRole::Qa, 'qa.demo@kautch.test', 'Quintino Revisor');
        $editorOne = $this->seedMember($website, $owner, WebsiteRole::Editor, 'editor1.demo@kautch.test', 'Elisa Editora');
        $editorTwo = $this->seedMember($website, $owner, WebsiteRole::Editor, 'editor2.demo@kautch.test', 'Eduardo Editor');
        $viewer = $this->seedMember($website, $owner, WebsiteRole::Viewer, 'viewer.demo@kautch.test', 'Vitor Visitante');

        return [
            'owner' => $owner,
            'admin' => $admin,
            'qa' => $qa,
            'editors' => [$editorOne, $editorTwo],
            'viewer' => $viewer,
        ];
    }

    private function seedMember(Website $website, User $inviter, WebsiteRole $role, string $email, string $name): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'email_verified_at' => now(), 'password' => Hash::make('password')],
        );

        WebsiteUser::query()->firstOrCreate(
            ['website_id' => $website->id, 'user_id' => $user->id],
            [
                'role' => $role,
                'invited_by' => $inviter->id,
                'invited_at' => now(),
                'accepted_at' => now(),
                'created_by' => $inviter->id,
                'updated_by' => $inviter->id,
            ],
        );

        return $user;
    }

    /**
     * @return Collection<int, Layout>
     */
    private function seedLayouts(Website $website): Collection
    {
        $admin = $this->users['admin'];

        return collect(self::LAYOUT_DEFINITIONS)->map(fn (array $definition): Layout => Layout::factory()->create([
            'website_id' => $website->id,
            'name' => $definition['name'],
            'slug' => $definition['slug'],
            'description' => $definition['description'],
            'schema' => $definition['schema'],
            'status' => 'active',
            'is_default' => $definition['is_default'],
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]));
    }

    /**
     * 10 assets shared across the layouts (e.g. logos, hero backgrounds) —
     * the other 10 are created per-page, attached to each page's published
     * version (see seedPages()).
     *
     * @param  Collection<int, Layout>  $layouts
     * @return Collection<int, Asset>
     */
    private function seedLayoutAssets(Collection $layouts): Collection
    {
        $admin = $this->users['admin'];

        return collect(range(1, 10))->map(function (int $i) use ($layouts, $admin): Asset {
            $layout = $layouts[($i - 1) % $layouts->count()];

            $factory = $i === 1 ? Asset::factory()->svg() : Asset::factory();

            return $factory->forLayout($layout)
                ->state(fn (array $attributes) => [
                    'metadata' => array_merge($attributes['metadata'] ?? [], [
                        'alt_text' => "Demo asset {$i} for {$layout->name}",
                    ]),
                ])
                ->create([
                    'created_by' => $admin->id,
                    'updated_by' => $admin->id,
                    'filename' => "layout-asset-{$i}.".($i === 1 ? 'svg' : 'jpg'),
                ]);
        });
    }

    /**
     * @param  Collection<int, Layout>  $layouts
     * @param  Collection<int, Asset>  $layoutAssets
     * @return Collection<int, Page>
     */
    private function seedPages(Website $website, Collection $layouts, Collection $layoutAssets): Collection
    {
        $editors = $this->users['editors'];
        $qa = $this->users['qa'];
        $owner = $this->users['owner'];
        $admin = $this->users['admin'];

        return collect(range(1, self::PAGE_COUNT))->map(function (int $i) use (
            $website, $layouts, $layoutAssets, $editors, $qa, $owner, $admin,
        ): Page {
            $layout = $layouts[($i - 1) % $layouts->count()];
            $editor = $editors[($i - 1) % count($editors)];

            $page = Page::factory()->create([
                'website_id' => $website->id,
                'layout_id' => $layout->id,
                'name' => "Página Demo {$i}",
                'slug' => "pagina-demo-{$i}",
                'status' => 'active',
                'created_by' => $editor->id,
                'updated_by' => $editor->id,
            ]);

            // v1: an early draft that was eventually superseded — archived.
            $v1 = PageVersion::factory()->archived($qa)->create([
                'page_id' => $page->id,
                'layout_id' => $layout->id,
                'version_number' => 1,
                'created_by' => $editor->id,
                'updated_by' => $admin->id,
                'published_by' => $admin->id,
            ]);
            $this->seedPiecesForVersion($v1, $layoutAssets->random(), $editor);

            // v2: submitted again, but QA sent it back for changes.
            $v2 = PageVersion::factory()->rejected($qa)->create([
                'page_id' => $page->id,
                'layout_id' => $layout->id,
                'version_number' => 2,
                'created_by' => $editor->id,
                'updated_by' => $qa->id,
            ]);
            $this->seedPiecesForVersion($v2, $layoutAssets->random(), $editor);

            // v3: the current, live, published version.
            $v3 = PageVersion::factory()->published($qa)->create([
                'page_id' => $page->id,
                'layout_id' => $layout->id,
                'version_number' => 3,
                'created_by' => $editor->id,
                'updated_by' => $owner->id,
                'published_by' => $owner->id,
            ]);
            $featuredAsset = Asset::factory()->forPageVersion($v3)
                ->state(fn (array $attributes) => [
                    'metadata' => array_merge($attributes['metadata'] ?? [], [
                        'alt_text' => "Featured image for page {$i}",
                    ]),
                ])
                ->create([
                    'created_by' => $editor->id,
                    'updated_by' => $editor->id,
                    'filename' => "page-{$i}-featured.jpg",
                ]);
            $this->seedPiecesForVersion($v3, $featuredAsset, $editor);

            // Both an explicit updated_by (for DatabaseSeeder's WithoutModelEvents run,
            // where HasUserstamps' listeners never fire) and asActor() (for a standalone
            // run of this seeder, where they do) are needed to cover both invocation paths.
            $page->asActor($owner)->update(['published_version_id' => $v3->id, 'updated_by' => $owner->id]);

            return $page->fresh();
        });
    }

    /**
     * A small, two-level nested tree per version: a heading, an intro
     * paragraph, and a two-column wrapper whose left/right slots hold a
     * paragraph and an image — enough to exercise the tree in every demo page.
     */
    private function seedPiecesForVersion(PageVersion $version, Asset $imageAsset, User $actor): void
    {
        $regions = $version->layout_snapshot['schema']['regions'] ?? [];
        $headingRegion = $regions[0] ?? null;
        $mainRegion = $regions[1] ?? $headingRegion;

        Piece::factory()->heading(1)->create([
            'page_version_id' => $version->id,
            'position' => 0,
            'region' => $headingRegion,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        Piece::factory()->paragraph()->create([
            'page_version_id' => $version->id,
            'position' => 1,
            'region' => $mainRegion,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $wrapper = Piece::factory()->twoColumnsWrapper()->create([
            'page_version_id' => $version->id,
            'position' => 2,
            'region' => $mainRegion,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        Piece::factory()->paragraph()->childOf($wrapper, 'left')->create(['position' => 0, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
        Piece::factory()->image($imageAsset)->childOf($wrapper, 'right')->create(['position' => 1, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
    }
}
