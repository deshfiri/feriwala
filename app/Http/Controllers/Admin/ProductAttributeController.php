<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\ManageAttributes;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shared attributes variations are built from (§11.1).
 *
 * Behind the catalogue policy like everything else in it: §12 names "product
 * variations" among what a regular user may not create, and an attribute is
 * what a variation is made of.
 */
class ProductAttributeController extends Controller
{
    public function __construct(
        protected ManageAttributes $attributes,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canViewAny($actor), 403);

        $usage = DB::table('product_variant_values')
            ->selectRaw('product_attribute_value_id, COUNT(*) AS uses')
            ->groupBy('product_attribute_value_id')
            ->pluck('uses', 'product_attribute_value_id');

        return Inertia::render('admin/catalog/attributes', [
            'attributes' => ProductAttribute::query()
                ->with('values')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (ProductAttribute $attribute) => [
                    'id' => $attribute->public_id,
                    'name' => $attribute->name,
                    'values' => $attribute->values
                        ->map(fn (ProductAttributeValue $value) => [
                            'id' => $value->public_id,
                            'value' => $value->value,
                            'uses' => (int) ($usage[$value->id] ?? 0),
                        ])
                        ->all(),
                    'uses' => (int) $attribute->values->sum(fn (ProductAttributeValue $value) => $usage[$value->id] ?? 0),
                ])
                ->all(),

            'can' => [
                'create' => CatalogPolicy::canCreate($actor),
                'edit' => CatalogPolicy::canEdit($actor),
                'delete' => CatalogPolicy::canDelete($actor),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canCreate($actor), 403);

        $name = $this->validatedName($request);

        $attribute = $this->attributes->create($actor, $name);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.attributes.created', ['name' => $attribute->name])]);

        return back();
    }

    public function update(Request $request, string $attribute): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canEdit($actor), 403);

        $record = $this->attribute($attribute);
        $name = $this->validatedName($request, $record);

        $this->attributes->rename($actor, $record, $name);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.attributes.updated', ['name' => $name])]);

        return back();
    }

    public function destroy(Request $request, string $attribute): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canDelete($actor), 403);

        try {
            $this->attributes->delete($actor, $this->attribute($attribute));
        } catch (CatalogRefused $refused) {
            return back()->withErrors(['attribute' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.attributes.deleted')]);

        return back();
    }

    public function storeValue(Request $request, string $attribute): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canCreate($actor), 403);

        $record = $this->attribute($attribute);
        $text = $this->validatedValue($request, $record);

        $this->attributes->addValue($actor, $record, $text);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.attributes.value_added', ['value' => $text])]);

        return back();
    }

    public function updateValue(Request $request, string $value): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canEdit($actor), 403);

        $record = $this->value($value);
        $text = $this->validatedValue($request, $record->attribute, $record);

        $this->attributes->renameValue($actor, $record, $text);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.attributes.value_updated', ['value' => $text])]);

        return back();
    }

    public function destroyValue(Request $request, string $value): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canDelete($actor), 403);

        try {
            $this->attributes->deleteValue($actor, $this->value($value));
        } catch (CatalogRefused $refused) {
            return back()->withErrors(['value' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.attributes.value_deleted')]);

        return back();
    }

    /**
     * Unique whatever the casing, matching the functional index: "Colour" and
     * "colour" as two attributes split one storefront filter in two.
     */
    protected function validatedName(Request $request, ?ProductAttribute $existing = null): string
    {
        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:80',
                function (string $attribute, mixed $value, Closure $fail) use ($existing): void {
                    $existingId = $existing?->id;

                    $taken = ProductAttribute::query()
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $value))])
                        ->when($existingId !== null, fn (Builder $query) => $query->whereKeyNot($existingId))
                        ->exists();

                    if ($taken) {
                        $fail(__('catalog.attributes.name_taken'));
                    }
                },
            ],
        ]);

        return trim((string) $validated['name']);
    }

    protected function validatedValue(Request $request, ProductAttribute $attribute, ?ProductAttributeValue $existing = null): string
    {
        $validated = $request->validate([
            'value' => [
                'required', 'string', 'max:80',
                function (string $field, mixed $value, Closure $fail) use ($attribute, $existing): void {
                    $existingId = $existing?->id;

                    $taken = ProductAttributeValue::query()
                        ->where('product_attribute_id', $attribute->id)
                        ->whereRaw('LOWER(value) = ?', [mb_strtolower(trim((string) $value))])
                        ->when($existingId !== null, fn (Builder $query) => $query->whereKeyNot($existingId))
                        ->exists();

                    if ($taken) {
                        $fail(__('catalog.attributes.value_taken'));
                    }
                },
            ],
        ]);

        return trim((string) $validated['value']);
    }

    protected function attribute(string $publicId): ProductAttribute
    {
        /** @var ProductAttribute $attribute */
        $attribute = ProductAttribute::query()->where('public_id', $publicId)->firstOrFail();

        return $attribute;
    }

    protected function value(string $publicId): ProductAttributeValue
    {
        /** @var ProductAttributeValue $value */
        $value = ProductAttributeValue::query()->with('attribute')->where('public_id', $publicId)->firstOrFail();

        return $value;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
