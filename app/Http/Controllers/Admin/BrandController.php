<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\ManageBrands;
use App\Domain\Catalog\CatalogImageStore;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SaveBrandRequest;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Product brands (§11.3).
 *
 * A paginated table rather than the category screen's tree: brands are flat and
 * a catalogue can hold hundreds of them, so searching and paging happen in the
 * database (§39) rather than in a browser holding every row.
 *
 * Every write asks {@see CatalogPolicy} first. §12 makes catalogue authorship a
 * platform privilege, and a business account holder holds no platform
 * permission — so a partner reaching any of these meets a 403, not a screen with
 * its buttons hidden.
 */
class BrandController extends Controller
{
    public const PER_PAGE = 25;

    /**
     * Columns the table may sort by. Anything else is ignored rather than
     * passed to `orderBy`, because a sort parameter is somebody's input.
     *
     * @var array<int, string>
     */
    public const SORTABLE = ['name', 'sort_order', 'created_at'];

    public function __construct(
        protected ManageBrands $brands,
        protected CatalogImageStore $images,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canViewAny($actor), 403);

        $search = trim($request->string('search')->toString());
        $status = $request->string('status')->toString();
        $sort = $request->string('sort')->toString();
        $direction = $request->string('direction')->toString() === 'desc' ? 'desc' : 'asc';

        $brands = Brand::query()
            ->when($search !== '', fn (Builder $query) => $query->where(
                fn (Builder $inner) => $inner
                    ->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%')
                    ->orWhere('slug', 'ilike', '%'.addcslashes($search, '%_\\').'%'),
            ))
            ->when($status === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->when(
                in_array($sort, self::SORTABLE, true),
                fn (Builder $query) => $query->orderBy($sort, $direction),
                fn (Builder $query) => $query->orderBy('sort_order'),
            )
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Brand $brand) => $this->summary($brand));

        return Inertia::render('admin/catalog/brands', [
            'brands' => $brands,
            'filters' => [
                'status' => in_array($status, ['active', 'inactive'], true) ? $status : null,
            ],
            'can' => [
                'create' => CatalogPolicy::canCreate($actor),
                'edit' => CatalogPolicy::canEdit($actor),
                'delete' => CatalogPolicy::canDelete($actor),
            ],
            'limits' => [
                'image_max_kb' => (int) (CatalogImageStore::MAX_BYTES / 1024),
                'image_types' => CatalogImageStore::ACCEPTED_MIME_TYPES,
            ],
        ]);
    }

    public function store(SaveBrandRequest $request): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canCreate($actor), 403);

        try {
            $brand = $this->brands->create($actor, $this->attributes($request), $request->file('logo'));
        } catch (CatalogRefused $refused) {
            throw ValidationException::withMessages(['logo' => $refused->getMessage()]);
        }

        return back()->with('success', __('catalog.brands.created', ['name' => $brand->name]));
    }

    public function update(SaveBrandRequest $request, string $brand): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canEdit($actor), 403);

        try {
            $updated = $this->brands->update(
                $actor,
                $this->brand($brand),
                $this->attributes($request),
                $request->file('logo'),
            );
        } catch (CatalogRefused $refused) {
            throw ValidationException::withMessages(['logo' => $refused->getMessage()]);
        }

        return back()->with('success', __('catalog.brands.updated', ['name' => $updated->name]));
    }

    /**
     * Switch a brand on or off (§11.3).
     *
     * Its own endpoint, matching categories: taking a brand off every partner
     * storefront is a decision rather than a side effect of editing its name.
     */
    public function toggle(Request $request, string $brand): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canEdit($actor), 403);

        $validated = $request->validate(['is_active' => ['required', 'boolean']]);

        $updated = $this->brands->setActive($actor, $this->brand($brand), (bool) $validated['is_active']);

        return back()->with('success', __(
            $updated->is_active ? 'catalog.brands.enabled' : 'catalog.brands.disabled',
            ['name' => $updated->name],
        ));
    }

    public function destroy(Request $request, string $brand): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canDelete($actor), 403);

        try {
            $this->brands->delete($actor, $this->brand($brand));
        } catch (CatalogRefused $refused) {
            return back()->withErrors(['brand' => $refused->getMessage()]);
        }

        return back()->with('success', __('catalog.brands.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(Brand $brand): array
    {
        return [
            'id' => $brand->public_id,
            'name' => $brand->name,
            'slug' => $brand->slug,
            'description' => $brand->description,
            'logo_url' => $this->images->url($brand->logo_path),
            'logo_alt' => $brand->logo_alt,
            'is_active' => $brand->is_active,
            'sort_order' => $brand->sort_order,

            // Filled in when products exist (P3-3). Present now so the screen
            // has one shape rather than two.
            'products_count' => 0,
        ];
    }

    /**
     * The validated fields, with the file left to its own argument.
     *
     * `remove_logo` is read through `boolean()` because a multipart form sends
     * it as the string "1", and the action compares it strictly — a loose
     * truthiness check is how "0" ends up deleting somebody's logo.
     *
     * @return array<string, mixed>
     */
    protected function attributes(SaveBrandRequest $request): array
    {
        return [
            ...Arr::except($request->validated(), ['logo']),
            'remove_logo' => $request->boolean('remove_logo'),
        ];
    }

    protected function brand(string $publicId): Brand
    {
        /** @var Brand $brand */
        $brand = Brand::query()->where('public_id', $publicId)->firstOrFail();

        return $brand;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
