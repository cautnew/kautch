<?php

namespace App\Models;

use App\Enums\HttpMethod;
use App\Enums\RouteDestinationType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use Database\Factories\RouteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'website_id', 'path', 'http_method', 'destination_type',
    'page_id', 'asset_id', 'redirect_to_route_id', 'redirect_url', 'http_status',
    'created_by', 'deleted_by',
])]
class Route extends Model
{
    /** @use HasFactory<RouteFactory> */
    use Auditable, HasFactory, HasUserstamps, HasUuids, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'http_method' => HttpMethod::class,
            'destination_type' => RouteDestinationType::class,
            'http_status' => 'integer',
        ];
    }

    /**
     * The website this route belongs to.
     *
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * The page this route serves, when destination_type is "page".
     *
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * The asset this route serves, when destination_type is "asset".
     *
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * The route this one redirects to, when destination_type is "redirect".
     *
     * @return BelongsTo<Route, $this>
     */
    public function redirectTo(): BelongsTo
    {
        return $this->belongsTo(Route::class, 'redirect_to_route_id');
    }

    /**
     * Other routes that redirect to this one.
     *
     * @return HasMany<Route, $this>
     */
    public function redirectedBy(): HasMany
    {
        return $this->hasMany(Route::class, 'redirect_to_route_id');
    }

    /**
     * The user who created this route.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user who last updated this route.
     *
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The user who deleted this route, if it has been deleted.
     *
     * @return BelongsTo<User, $this>
     */
    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * Whether this route redirects to another route.
     *
     * @return Attribute<bool, never>
     */
    protected function isRedirect(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->destination_type === RouteDestinationType::Redirect,
        );
    }

    /**
     * Normalizes a raw path into this table's canonical form: a single
     * leading slash, no trailing slash (except the root path itself), and
     * no surrounding whitespace.
     */
    public static function normalizePath(string $path): string
    {
        $trimmed = '/'.trim($path, "/ \t\n\r\0\x0B");

        return $trimmed === '/' ? $trimmed : rtrim($trimmed, '/');
    }

    /**
     * Scope a query to routes belonging to a given website.
     *
     * @param  Builder<Route>  $query
     * @return Builder<Route>
     */
    public function scopeForWebsite(Builder $query, string $websiteId): Builder
    {
        return $query->where('website_id', $websiteId);
    }

    /**
     * Scope a query to routes of a given destination type.
     *
     * @param  Builder<Route>  $query
     * @return Builder<Route>
     */
    public function scopeOfDestinationType(Builder $query, RouteDestinationType $type): Builder
    {
        return $query->where('destination_type', $type);
    }

    /**
     * Scope a query to routes matching a given path + HTTP method.
     *
     * @param  Builder<Route>  $query
     * @return Builder<Route>
     */
    public function scopeMatching(Builder $query, string $path, HttpMethod $method): Builder
    {
        return $query->where('path', self::normalizePath($path))->where('http_method', $method);
    }

    /**
     * A route's own creation/update is domain-specifically about the URL
     * it maps, not the model in the abstract.
     */
    public function auditActionForCreate(): string
    {
        return $this->destination_type === RouteDestinationType::Redirect ? 'create_redirect' : 'create_route';
    }
}
