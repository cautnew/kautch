<?php

namespace App\Providers;

use App\Enums\WebsiteRole;
use App\Models\User;
use App\Models\Website;
use App\Repositories\Contracts\AssetRepositoryInterface;
use App\Repositories\Contracts\LayoutRepositoryInterface;
use App\Repositories\Contracts\PageRepositoryInterface;
use App\Repositories\Contracts\PageVersionRepositoryInterface;
use App\Repositories\Contracts\PageVersionReviewRepositoryInterface;
use App\Repositories\Contracts\PieceRepositoryInterface;
use App\Repositories\Contracts\RouteRepositoryInterface;
use App\Repositories\Contracts\WebsiteRepositoryInterface;
use App\Repositories\Contracts\WebsiteUserRepositoryInterface;
use App\Repositories\Eloquent\AssetRepository;
use App\Repositories\Eloquent\LayoutRepository;
use App\Repositories\Eloquent\PageRepository;
use App\Repositories\Eloquent\PageVersionRepository;
use App\Repositories\Eloquent\PageVersionReviewRepository;
use App\Repositories\Eloquent\PieceRepository;
use App\Repositories\Eloquent\RouteRepository;
use App\Repositories\Eloquent\WebsiteRepository;
use App\Repositories\Eloquent\WebsiteUserRepository;
use App\Support\RequestAuditContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(WebsiteRepositoryInterface::class, WebsiteRepository::class);
        $this->app->bind(WebsiteUserRepositoryInterface::class, WebsiteUserRepository::class);
        $this->app->bind(LayoutRepositoryInterface::class, LayoutRepository::class);
        $this->app->bind(AssetRepositoryInterface::class, AssetRepository::class);
        $this->app->bind(PageRepositoryInterface::class, PageRepository::class);
        $this->app->bind(PageVersionRepositoryInterface::class, PageVersionRepository::class);
        $this->app->bind(PageVersionReviewRepositoryInterface::class, PageVersionReviewRepository::class);
        $this->app->bind(PieceRepositoryInterface::class, PieceRepository::class);
        $this->app->bind(RouteRepositoryInterface::class, RouteRepository::class);

        $this->app->singleton(RequestAuditContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerGates();
        $this->registerPasswordResetUrl();
        $this->registerAuthenticationViews();
    }

    protected function registerAuthenticationViews(): void
    {
        Fortify::loginView(fn () => Inertia::render('login'));
        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('reset-password', [
            'token' => $request->route('token'),
            'email' => $request->input('email'),
        ]));
    }

    /**
     * This application is a headless API with no server-rendered password reset
     * page, so the reset link embedded in the notification email must point to
     * the consuming frontend (config('app.frontend_url')) instead of a named
     * Laravel route.
     */
    protected function registerPasswordResetUrl(): void
    {
        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

            return "{$frontendUrl}/reset-password?token={$token}&email=".urlencode($user->email);
        });
    }

    /**
     * Register website-scoped Gates for capability checks that aren't tied to a
     * single Eloquent model instance (e.g. gating UI navigation/sections).
     * Instance-level CRUD authorization lives in the Policies instead.
     */
    protected function registerGates(): void
    {
        Gate::define('access-website', function (User $user, Website $website): bool {
            return $user->roleOn($website) !== null;
        });

        Gate::define('manage-website-settings', function (User $user, Website $website): bool {
            return in_array($user->roleOn($website), [WebsiteRole::Owner, WebsiteRole::Admin], true);
        });

        Gate::define('review-content', function (User $user, Website $website): bool {
            return in_array(
                $user->roleOn($website),
                [WebsiteRole::Owner, WebsiteRole::Admin, WebsiteRole::Qa],
                true,
            );
        });
    }
}
