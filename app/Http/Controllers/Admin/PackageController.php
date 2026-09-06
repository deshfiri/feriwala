<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Package\Actions\ManagePackages;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Exceptions\PackageInUse;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\PackageCharge;
use App\Http\Controllers\Controller;
use App\Http\Requests\Package\SavePackageRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The package catalogue (§8.1).
 *
 * Archived packages are listed alongside live ones rather than hidden. A
 * subscription, a payment and an invoice all name the package they were for, so
 * "which plan was Karim Traders on in March" has to remain answerable — and a
 * catalogue that quietly drops retired plans is one where it is not.
 */
class PackageController extends Controller
{
    public function index(Request $request, ManagePackages $manage): Response
    {
        Gate::authorize('viewAny', Package::class);

        $packages = Package::query()
            ->withTrashed()
            ->with(['features', 'charges'])
            ->withCount('subscriptions')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        /** @var User $actor */
        $actor = $request->user();

        return Inertia::render('admin/packages/index', [
            'packages' => $packages->map(fn (Package $package) => [
                'id' => $package->slug,
                'name' => $package->name,
                'short_description' => $package->short_description,
                'description' => $package->description,

                // Rendered by MoneyAmount, which formats server-side (§36.1).
                'fee' => $package->fee_minor->jsonSerialize(),

                // The same amount as an integer, for the form's number field.
                'fee_minor' => $package->fee_minor->minorUnits,
                'registration_fee_minor' => $package->registration_fee_minor?->minorUnits,
                'renewal_fee_minor' => $package->renewal_fee_minor?->minorUnits,
                'required_deposit_minor' => $package->required_deposit_minor->minorUnits,
                'minimum_balance_minor' => $package->minimum_balance_minor->minorUnits,
                'currency_code' => $package->currency_code,

                'validity_days' => $package->validity_days,
                'renewal_frequency' => $package->renewal_frequency,
                'grace_period_days' => $package->grace_period_days,

                'available_from' => $package->available_from?->toIso8601String(),
                'available_until' => $package->available_until?->toIso8601String(),

                'is_active' => $package->is_active,
                'is_public' => $package->is_public,
                'is_archived' => $package->trashed(),
                'sort_order' => $package->sort_order,

                'subscriptions_count' => $package->subscriptions_count,

                'features' => $package->features
                    ->mapWithKeys(fn ($row) => [$row->feature => $row->value])
                    ->all(),

                'charges' => $package->charges->map(fn (PackageCharge $charge) => [
                    'charge_type' => $charge->charge_type,
                    'amount_minor' => $charge->amount_minor->minorUnits,
                    'frequency' => $charge->frequency,
                ])->values(),

                /*
                 * What stands in the way of retiring it, shown **before** the
                 * button is pressed. A guard an administrator only meets on
                 * submit is one they meet after writing a change they now have
                 * to undo.
                 */
                'blockers' => $package->trashed() ? [] : $manage->blockers($package),

                'can' => [
                    // Permission and invariant together: Super Admin passes
                    // every policy through Gate::before, so asking the policy
                    // alone would offer Edit on an archived package.
                    'update' => ! $package->trashed() && $actor->can('update', $package),
                    'archive' => ! $package->trashed() && $actor->can('archive', $package),
                ],
            ]),

            'features' => array_map(fn (PackageFeature $feature) => [
                'key' => $feature->value,
                'label' => $feature->label(),
                'type' => $feature->type()->value,
            ], PackageFeature::cases()),

            'charge_types' => SavePackageRequest::CHARGE_TYPES,
            'frequencies' => SavePackageRequest::FREQUENCIES,

            'can' => [
                'create' => $actor->can('create', Package::class),
            ],
        ]);
    }

    public function store(SavePackageRequest $request, ManagePackages $manage): RedirectResponse
    {
        Gate::authorize('create', Package::class);

        $manage->create(
            $request->packageAttributes(),
            $request->featureValues(),
            $request->chargeRows(),
            $this->actor($request),
        );

        return back()->with('success', __('Package created.'));
    }

    public function update(
        SavePackageRequest $request,
        Package $package,
        ManagePackages $manage,
    ): RedirectResponse {
        Gate::authorize('update', $package);

        $manage->update(
            $package,
            $request->packageAttributes(),
            $request->featureValues(),
            $request->chargeRows(),
            $this->actor($request),
        );

        return back()->with('success', __('Package updated.'));
    }

    /**
     * Take a package off sale, reversibly.
     */
    public function setActive(
        Request $request,
        Package $package,
        ManagePackages $manage,
    ): RedirectResponse {
        Gate::authorize('update', $package);

        $validated = $request->validate(['is_active' => ['required', 'boolean']]);

        $manage->setActive($package, (bool) $validated['is_active'], $this->actor($request));

        return back()->with('success', __('Package updated.'));
    }

    public function archive(
        Request $request,
        Package $package,
        ManagePackages $manage,
    ): RedirectResponse {
        Gate::authorize('archive', $package);

        try {
            $manage->archive($package, $this->actor($request));
        } catch (PackageInUse $exception) {
            // The message names the rules and counts standing in the way, which
            // is what an administrator needs to clear them.
            throw ValidationException::withMessages(['package' => $exception->getMessage()]);
        }

        return back()->with('success', __('Package archived.'));
    }

    protected function actor(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
