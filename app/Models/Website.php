<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use Database\Factories\WebsiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'domain', 'subdomain', 'locale', 'timezone', 'status', 'created_by', 'deleted_by'])]
class Website extends Model
{
    /** @use HasFactory<WebsiteFactory> */
    use Auditable, HasFactory, HasUserstamps, HasUuids, SoftDeletes;

    /**
     * Layouts registered for this website.
     *
     * @return HasMany<Layout, $this>
     */
    public function layouts(): HasMany
    {
        return $this->hasMany(Layout::class);
    }

    /**
     * Pages belonging to this website.
     *
     * @return HasMany<Page, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }

    /**
     * Assets uploaded directly under this website's scope.
     *
     * @return HasMany<Asset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /**
     * Membership records linking users to this website.
     *
     * @return HasMany<WebsiteUser, $this>
     */
    public function websiteUsers(): HasMany
    {
        return $this->hasMany(WebsiteUser::class);
    }

    /**
     * URL routes registered for this website.
     *
     * @return HasMany<Route, $this>
     */
    public function routes(): HasMany
    {
        return $this->hasMany(Route::class);
    }

    /**
     * Users with access to this website.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'website_users')
            ->withPivot(['role', 'invited_by', 'invited_at', 'accepted_at'])
            ->withTimestamps();
    }

    /**
     * Audit log entries scoped to this website.
     *
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * The user who created this website.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user who last updated this website.
     *
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The user who deleted this website, if it has been deleted.
     *
     * @return BelongsTo<User, $this>
     */
    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * The full public host, combining subdomain and domain (e.g. "blog.example.com").
     *
     * @return Attribute<string, never>
     */
    protected function host(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->subdomain !== '' && $this->subdomain !== null
                ? "{$this->subdomain}.{$this->domain}"
                : $this->domain,
        );
    }

    /**
     * Scope a query to only include active websites.
     *
     * @param  Builder<Website>  $query
     * @return Builder<Website>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope a query to a given domain (matching either root or any subdomain).
     *
     * @param  Builder<Website>  $query
     * @return Builder<Website>
     */
    public function scopeForDomain(Builder $query, string $domain): Builder
    {
        return $query->where('domain', $domain);
    }

    /**
     * Scope a query to the website whose host (subdomain + domain, per the
     * host accessor above) matches the given request host exactly. Splits
     * on the *first* dot only, so a domain that itself contains dots (e.g.
     * "example.co.uk") is still recovered correctly from "blog.example.co.uk".
     *
     * @param  Builder<Website>  $query
     * @return Builder<Website>
     */
    public function scopeForHost(Builder $query, string $host): Builder
    {
        return $query->where(function (Builder $query) use ($host): void {
            $query->where('domain', $host)->where(function (Builder $query): void {
                $query->whereNull('subdomain')->orWhere('subdomain', '');
            });

            if (str_contains($host, '.')) {
                [$subdomain, $domain] = explode('.', $host, 2);

                $query->orWhere(function (Builder $query) use ($subdomain, $domain): void {
                    $query->where('subdomain', $subdomain)->where('domain', $domain);
                });
            }
        });
    }

    /**
     * A website is its own audit scope (it has no website_id column of its own).
     */
    public function auditWebsiteId(): ?string
    {
        return $this->id;
    }
}
