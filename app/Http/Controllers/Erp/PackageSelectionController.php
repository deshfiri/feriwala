<?php

namespace App\Http\Controllers\Erp;

use App\Domain\Billing\Actions\CalculateActivationQuote;
use App\Domain\Package\Actions\SelectPackage;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Package selection and comparison (§8.2).
 *
 * Every package shows its **total activation cost**, not just its own fee — the
 * registration fee is charged alongside it (§5.1), and a comparison that omits
 * it would understate every option by the same amount and surprise the user at
 * checkout.
 */
class PackageSelectionController extends Controller
{
    /**
     * Features shown side by side. A fixed list, so the comparison table has
     * the same rows for every package and a missing entitlement reads as
     * "not included" rather than vanishing.
     */
    protected const COMPARED = [
        PackageFeature::DedicatedWebsite,
        PackageFeature::ProductPublishLimit,
        PackageFeature::OrderLimit,
        PackageFeature::StaffLimit,
        PackageFeature::DomainIncluded,
        PackageFeature::HostingIncluded,
        PackageFeature::ApiAccess,
        PackageFeature::SupportLevel,
    ];

    public function index(Request $request, CalculateActivationQuote $quotes): Response
    {
        /** @var User $user */
        $user = $request->user();

        $selected = $user->packages()
            ->where('status', UserPackageStatus::PendingPayment)
            ->first();

        $packages = Package::query()
            ->publiclyListed()
            ->with('features')
            ->get()
            ->map(function (Package $package) use ($quotes) {
                $quote = $quotes->handle($package);

                return [
                    'slug' => $package->slug,
                    'name' => $package->name,
                    'short_description' => $package->short_description,
                    'fee' => $package->fee_minor->jsonSerialize(),

                    // What they will actually pay today.
                    'activation_total' => $quote->total()->jsonSerialize(),

                    'validity_days' => $package->validity_days,
                    'required_deposit' => $package->required_deposit_minor->jsonSerialize(),

                    'features' => array_map(fn (PackageFeature $feature) => [
                        'key' => $feature->value,
                        'label' => $feature->label(),
                        'value' => $package->feature($feature),
                        'type' => $feature->type()->value,
                    ], self::COMPARED),
                ];
            })
            ->all();

        return Inertia::render('onboarding/packages', [
            'packages' => $packages,
            'selected_slug' => $selected?->package?->slug,
        ]);
    }

    public function select(
        Request $request,
        Package $package,
        SelectPackage $select,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $select->handle($user, $package);

        return to_route('checkout.show');
    }
}
