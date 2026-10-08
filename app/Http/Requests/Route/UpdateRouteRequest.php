<?php

namespace App\Http\Requests\Route;

use App\Enums\HttpMethod;
use App\Enums\RouteDestinationType;
use App\Models\Route;
use App\Models\Website;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRouteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Authorization is handled by the #[Authorize] attribute on the controller action.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A route's destination_type/page_id/asset_id are immutable after
     * creation (create a new route instead) — only where it lives and, for
     * a redirect, what it points at can change here.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Website $website */
        $website = $this->route('website');

        return [
            'path' => ['sometimes', 'string', 'max:2048'],
            'http_method' => ['sometimes', Rule::enum(HttpMethod::class)],
            'http_status' => ['sometimes', 'integer', 'between:100,599'],
            'redirect_to_route_id' => [
                'sometimes',
                'string',
                'uuid',
                Rule::exists('routes', 'id')->where('website_id', $website->id),
            ],
            'redirect_url' => ['sometimes', 'string', 'url', 'max:2048'],
        ];
    }

    /**
     * @param  Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateRedirectTargetOnlyForRedirects($validator);
            $this->validatePathUnique($validator);
        });
    }

    /**
     * redirect_to_route_id/redirect_url only make sense on a route whose
     * destination_type is already "redirect" — not something you can bolt
     * onto a page/asset route — and a single update can only retarget one
     * of the two (whichever isn't given keeps its previous value, cleared
     * by RouteService if the other one changes).
     */
    private function validateRedirectTargetOnlyForRedirects(Validator $validator): void
    {
        $hasRouteTarget = $this->filled('redirect_to_route_id');
        $hasUrlTarget = $this->filled('redirect_url');

        if (! $hasRouteTarget && ! $hasUrlTarget) {
            return;
        }

        /** @var Route $route */
        $route = $this->route('route');

        if ($route->destination_type !== RouteDestinationType::Redirect) {
            $field = $hasRouteTarget ? 'redirect_to_route_id' : 'redirect_url';
            $validator->errors()->add($field, 'Only a redirect route has a target to update.');

            return;
        }

        if ($hasRouteTarget && $hasUrlTarget) {
            $validator->errors()->add('redirect_to_route_id', 'A redirect route can target only one of redirect_to_route_id or redirect_url, not both.');
        }
    }

    /**
     * Same normalized-path uniqueness check as on creation, ignoring this route itself.
     */
    private function validatePathUnique(Validator $validator): void
    {
        $path = $this->input('path');

        if (! $this->has('path') && ! $this->has('http_method')) {
            return;
        }

        /** @var Website $website */
        $website = $this->route('website');
        /** @var Route $route */
        $route = $this->route('route');

        $normalizedPath = is_string($path) ? Route::normalizePath($path) : $route->path;
        $method = $this->filled('http_method') ? $this->input('http_method') : $route->http_method->value;

        $exists = Route::query()
            ->forWebsite($website->id)
            ->where('path', $normalizedPath)
            ->where('http_method', $method)
            ->whereKeyNot($route->id)
            ->exists();

        if ($exists) {
            $validator->errors()->add('path', 'A route for this path and HTTP method already exists on this website.');
        }
    }
}
