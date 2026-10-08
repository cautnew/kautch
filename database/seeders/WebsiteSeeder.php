<?php

namespace Database\Seeders;

use App\Enums\WebsiteRole;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteUser;
use Illuminate\Database\Seeder;

/**
 * Seeds the single base website every other seeder builds on top of (see
 * DemoContentSeeder), owned by the AdminSeeder bootstrap account.
 *
 * Idempotent: safe to run standalone or as a dependency of other seeders —
 * re-running it never produces a second website or a duplicate membership.
 */
class WebsiteSeeder extends Seeder
{
    /**
     * The demo website's domain is the app's own reachable host (APP_URL),
     * not an arbitrary fake one — that's what actually lets the seeded
     * routes/pages be visited in a browser without any extra DNS/hosts-file
     * setup (a domain like "kautch-demo.test" would need to be registered
     * with the local dev environment's router before it resolves at all).
     */
    public static function domain(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_HOST) ?? 'localhost';
    }

    public function run(): void
    {
        $this->call(AdminSeeder::class);

        $owner = User::query()->where('email', AdminSeeder::EMAIL)->firstOrFail();

        $website = Website::query()->firstOrCreate(
            ['domain' => self::domain()],
            [
                'name' => 'Kautch Demo',
                'subdomain' => '',
                'locale' => 'pt-BR',
                'timezone' => 'America/Sao_Paulo',
                'status' => 'active',
                'created_by' => $owner->id,
                'updated_by' => $owner->id,
            ],
        );

        WebsiteUser::query()->firstOrCreate(
            ['website_id' => $website->id, 'user_id' => $owner->id],
            [
                'role' => WebsiteRole::Owner,
                'invited_by' => null,
                'invited_at' => now(),
                'accepted_at' => now(),
                'created_by' => $owner->id,
                'updated_by' => $owner->id,
            ],
        );

        $this->command?->info("Website ready: {$website->name} ({$website->domain}), owned by {$owner->email}");
    }
}
