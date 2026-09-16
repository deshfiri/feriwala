<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Models\Product;
use App\Domain\Package\Models\Package;
use App\Domain\Website\Models\WebsiteProductPriceRule;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What partners may charge for what they sell (§15.1, P5-5).
 *
 * The administrator's half: whether a partner may price at all, the floor, the
 * ceiling, the suggested price, how far above the floor they may go, and which
 * of §15.1's settings are locked.
 *
 * **Opened and closed rather than edited**, like fee and tax rules. A bound
 * that changed in place would rewrite what a partner was allowed to charge last
 * month, and the prices they set under it are already on invoices.
 *
 * Behind `website.manage_settings`: deciding what every partner may charge is
 * not the same job as administering one storefront.
 */
class WebsitePricingController extends Controller
{
    public function __construct(
        protected RecordAuditLog $audit,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize($this->ability());

        $rules = WebsiteProductPriceRule::query()
            ->with(['product:id,public_id,name,sku', 'package:id,name'])
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('admin/website-pricing', [
            // The whole paginator, so the table has its range and page size.
            'rules' => $rules->through(fn (WebsiteProductPriceRule $rule) => [
                'id' => $rule->public_id,
                'product' => $rule->product === null ? null : [
                    'name' => $rule->product->name,
                    'sku' => $rule->product->sku,
                ],
                'package' => $rule->package?->name,
                'allows_user_pricing' => $rule->allows_user_pricing,
                'minimum' => $rule->min_price_minor?->jsonSerialize(),
                'maximum' => $rule->max_price_minor?->jsonSerialize(),
                'suggested' => $rule->suggested_price_minor?->jsonSerialize(),
                'max_margin_percent' => $rule->max_margin_percent,
                'locked_fields' => $rule->locked_fields,
                'effective_from' => $rule->effective_from->toIso8601String(),
                'effective_to' => $rule->effective_to?->toIso8601String(),
            ]),
            'lockable_fields' => array_map(fn (string $field) => [
                'value' => $field,
                'label' => __('website.fields.'.$field),
            ], WebsiteProductPriceRule::LOCKABLE_FIELDS),
            'packages' => Package::query()
                ->orderBy('name')
                ->get(['public_id', 'name'])
                ->map(fn (Package $package) => ['value' => $package->public_id, 'label' => $package->name])
                ->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize($this->ability());

        $validated = $request->validate([
            'product' => ['nullable', 'string', 'size:26'],
            'package' => ['nullable', 'string', 'size:26'],
            'allows_user_pricing' => ['required', 'boolean'],
            'min_price_minor' => ['nullable', 'integer', 'min:0'],
            'max_price_minor' => ['nullable', 'integer', 'min:0', 'gte:min_price_minor'],
            'suggested_price_minor' => ['nullable', 'integer', 'min:0'],
            'max_margin_percent' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'locked_fields' => ['nullable', 'array'],
            'locked_fields.*' => ['string', 'in:'.implode(',', WebsiteProductPriceRule::LOCKABLE_FIELDS)],
            'effective_from' => ['required', 'date'],
        ]);

        $productId = $validated['product'] ?? null;
        $packageId = $validated['package'] ?? null;

        $product = $productId === null
            ? null
            : Product::query()->where('public_id', $productId)->first();

        $package = $packageId === null
            ? null
            : Package::query()->where('public_id', $packageId)->first();

        // A rule naming something that does not exist would silently become
        // the global rule — the broadest one there is.
        if (($productId !== null && $product === null) || ($packageId !== null && $package === null)) {
            throw ValidationException::withMessages([
                $product === null && $productId !== null ? 'product' : 'package' => __('website.refused.rule_scope_not_found'),
            ]);
        }

        $rule = WebsiteProductPriceRule::create([
            'product_id' => $product?->id,
            'package_id' => $package?->id,
            'allows_user_pricing' => (bool) $validated['allows_user_pricing'],
            'currency_code' => 'BDT',
            'min_price_minor' => $validated['min_price_minor'] ?? null,
            'max_price_minor' => $validated['max_price_minor'] ?? null,
            'suggested_price_minor' => $validated['suggested_price_minor'] ?? null,
            'max_margin_percent' => $validated['max_margin_percent'] ?? null,
            'locked_fields' => array_values($validated['locked_fields'] ?? []),
            'effective_from' => CarbonImmutable::parse($validated['effective_from']),
            'created_by' => $this->person($request)->id,
        ]);

        $this->audit->handle(new AuditEntry(
            action: 'website.price_rule_opened',
            actorId: $this->person($request)->id,
            auditableType: WebsiteProductPriceRule::class,
            auditableId: $rule->id,
            after: [
                'product_id' => $rule->product_id,
                'package_id' => $rule->package_id,
                'allows_user_pricing' => $rule->allows_user_pricing,
            ],
            module: 'website',
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.rule_opened')]);

        return to_route('admin.website-pricing.index');
    }

    /**
     * Close a rule rather than delete it: what it allowed, it allowed.
     */
    public function close(Request $request, string $rule): RedirectResponse
    {
        Gate::authorize($this->ability());

        /** @var WebsiteProductPriceRule|null $record */
        $record = WebsiteProductPriceRule::query()->where('public_id', $rule)->first();

        abort_if($record === null, 404);

        $record->forceFill(['effective_to' => CarbonImmutable::now()])->save();

        $this->audit->handle(new AuditEntry(
            action: 'website.price_rule_closed',
            actorId: $this->person($request)->id,
            auditableType: WebsiteProductPriceRule::class,
            auditableId: $record->id,
            after: ['effective_to' => $record->effective_to?->toIso8601String()],
            module: 'website',
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.rule_closed')]);

        return to_route('admin.website-pricing.index');
    }

    protected function ability(): string
    {
        return PermissionCatalogue::name(PermissionModule::Website, PermissionAction::ManageSettings);
    }

    protected function person(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
