<?php

namespace App\Http\Requests\Asset;

use App\Models\Layout;
use App\Models\PageVersion;
use App\Models\Website;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAssetRequest extends FormRequest
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
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string|Closure>
     */
    public function rules(): array
    {
        /** @var Website $website */
        $website = $this->route('website');

        return [
            'file' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,gif,svg,webp,pdf,mp4,webm',
                'max:51200',
            ],
            /**
             * Required when page_version_id is absent. Cannot be combined with page_version_id.
             * The layout must belong to this website.
             */
            'layout_id' => [
                'required_without:page_version_id',
                'prohibits:page_version_id',
                'bail',
                'nullable',
                'string',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail) use ($website): void {
                    if (! Layout::where('id', $value)->where('website_id', $website->id)->exists()) {
                        $fail('The selected layout does not belong to this website.');
                    }
                },
            ],
            /**
             * Required when layout_id is absent. Cannot be combined with layout_id.
             * The page version must belong to this website.
             */
            'page_version_id' => [
                'required_without:layout_id',
                'prohibits:layout_id',
                'bail',
                'nullable',
                'string',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail) use ($website): void {
                    $pageVersion = PageVersion::find($value);

                    if ($pageVersion === null || $pageVersion->page->website_id !== $website->id) {
                        $fail('The selected page version does not belong to this website.');

                        return;
                    }

                    // Published is allowed: the write transparently lands on an
                    // auto-cloned draft instead (see PageVersionCloningService).
                    if (! $pageVersion->status->isWritable()) {
                        $fail('This page version cannot accept new assets right now.');
                    }
                },
            ],
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
