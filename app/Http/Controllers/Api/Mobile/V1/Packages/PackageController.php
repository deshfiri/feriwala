<?php

namespace App\Http\Controllers\Api\Mobile\V1\Packages;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Billing\Actions\CalculateActivationQuote;
use App\Domain\Package\Actions\SelectPackage;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Resources\Mobile\Packages\PackageResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Package selection and comparison (§8.2). Open before activation (§5.4).
 *
 * Every package shows its total activation cost, not just its own fee — the
 * registration fee is charged alongside it, and a comparison that omitted it
 * would surprise the user at checkout.
 */
class PackageController extends MobileController
{
    use ResolvesBusinessAccount;

    /**
     * Features shown side by side, the same rows for every package.
     *
     * @var list<PackageFeature>
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

    /**
     * Packages
     *
     * The packages open to the public, each with its fee, what activation would
     * cost in total today and the compared features. `selected_slug` is the
     * package this account has chosen and not yet paid for, if any.
     *
     * @response array{data: list<PackageResource>, selected_slug: string|null}
     */
    public function index(Request $request, CalculateActivationQuote $quotes): AnonymousResourceCollection
    {
        $account = $this->businessAccountFor($request);

        $selected = $account->packages()
            ->where('status', UserPackageStatus::PendingPayment)
            ->first();

        $packages = Package::query()
            ->publiclyListed()
            ->with('features')
            ->get()
            ->map(fn (Package $package) => [
                'package' => $package,
                'quote' => $quotes->handle($package, account: $account),
                'features' => self::COMPARED,
            ]);

        return PackageResource::collection($packages)
            ->additional(['selected_slug' => $selected?->package?->slug]);
    }

    /**
     * Choose a package
     *
     * Records the choice (replacing any earlier unpaid one) and moves the account to
     * payment. Answers `200` with the slug and `next: checkout`. `404` for an unknown
     * slug, `422` when the package is not currently available.
     *
     * @response array{selected_slug: string, next: string}
     */
    public function select(Request $request, string $package, SelectPackage $select): JsonResponse
    {
        $account = $this->businessAccountFor($request);

        $chosen = Package::query()->where('slug', $package)->firstOrFail();

        try {
            $select->handle($account, $chosen);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['package' => $exception->getMessage()]);
        }

        return new JsonResponse(['selected_slug' => $chosen->slug, 'next' => 'checkout']);
    }
}
