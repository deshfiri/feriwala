<?php

namespace App\Domain\Package\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycDocumentTypeScope;
use App\Domain\Package\Entitlements;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Exceptions\PackageInUse;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Models\User;
use Illuminate\Database\DatabaseManager;

/**
 * Creating and retiring packages (§8.1).
 *
 * §8 allows N packages, created and archived freely — but "freely" stops where
 * something else resolves through one. A KYC scope rule names a package by slug
 * (§7.2); archiving that package without saying so leaves a rule that matches
 * nobody, and the first anyone hears of it is an applicant asked for the wrong
 * documents.
 *
 * So retiring is guarded and the guard **names what is in the way**. The
 * alternative — cascading the change into other people's configuration — makes
 * a package edit silently rewrite verification rules, which is worse than being
 * told to go and change them.
 *
 * Historical KYC rounds are untouched either way. They carry a captured
 * snapshot rather than a live lookup, so a package going inactive cannot change
 * what a past round was judged on.
 */
class ManagePackages
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $features  PackageFeature value => raw value
     * @param  array<int, array<string, mixed>>  $charges
     */
    public function create(array $attributes, array $features, array $charges, User $by): Package
    {
        return $this->database->transaction(function () use ($attributes, $features, $charges, $by) {
            $package = Package::create([
                ...$attributes,
                'sort_order' => $attributes['sort_order'] ?? $this->nextSortOrder(),
            ]);

            $this->syncFeatures($package, $features);
            $this->syncCharges($package, $charges);

            $this->record('package.created', $package, $by, after: $package->getAttributes());

            return $package->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $features
     * @param  array<int, array<string, mixed>>  $charges
     */
    public function update(
        Package $package,
        array $attributes,
        array $features,
        array $charges,
        User $by,
    ): Package {
        return $this->database->transaction(function () use ($package, $attributes, $features, $charges, $by) {
            $before = $package->getOriginal();

            $package->fill($attributes)->save();

            $this->syncFeatures($package, $features);
            $this->syncCharges($package, $charges);

            $this->record(
                'package.updated',
                $package,
                $by,
                before: array_intersect_key($before, $package->getChanges()),
                after: $package->getChanges(),
            );

            return $package->refresh();
        });
    }

    /**
     * Take a package off sale without retiring it.
     *
     * Deliberately unguarded. Accounts already on it keep their entitlements —
     * {@see Entitlements} reads the subscription, not the
     * package's availability — and a KYC rule naming it still resolves. This is
     * "stop selling", which is exactly the reversible step an administrator
     * should be able to take without a negotiation.
     */
    public function setActive(Package $package, bool $isActive, User $by): Package
    {
        return $this->update($package, ['is_active' => $isActive], [], [], $by);
    }

    /**
     * Retire a package for good (§8.1).
     *
     * @throws PackageInUse when something still resolves through it
     */
    public function archive(Package $package, User $by): Package
    {
        return $this->database->transaction(function () use ($package, $by) {
            $this->refuseIfReferenced($package);

            $package->forceFill(['is_active' => false, 'is_public' => false])->save();
            $package->delete();

            $this->record('package.archived', $package, $by, after: [
                'archived_at' => $package->deleted_at?->toIso8601String(),
            ]);

            return $package;
        });
    }

    /**
     * The KYC rules and subscriptions standing in the way of retiring this.
     *
     * Public because the screen shows them **before** the button is pressed. A
     * guard the administrator only meets on submit is one they meet after
     * writing the change they now have to undo.
     *
     * @return array<string, array<int, string>>
     */
    public function blockers(Package $package): array
    {
        $rules = $this->referencingKycRequirements($package);
        $subscriptions = $this->activeSubscriptionCount($package);

        return array_filter([
            'kyc_requirements' => $rules,
            'subscriptions' => $subscriptions > 0 ? [(string) $subscriptions] : [],
        ]);
    }

    /**
     * @throws PackageInUse
     */
    protected function refuseIfReferenced(Package $package): void
    {
        $requirements = $this->referencingKycRequirements($package);

        if ($requirements !== []) {
            throw PackageInUse::byKycRules($package->name, $requirements);
        }

        $subscriptions = $this->activeSubscriptionCount($package);

        if ($subscriptions > 0) {
            throw PackageInUse::bySubscriptions($package->name, $subscriptions);
        }
    }

    /**
     * Names of the KYC requirements whose scope rules point at this package.
     *
     * Only **live** requirements count. A rule on an archived requirement
     * resolves for nobody already, so it is not a reason to keep a package.
     *
     * @return array<int, string>
     */
    protected function referencingKycRequirements(Package $package): array
    {
        return KycDocumentType::query()
            ->notArchived()
            ->whereIn('id', KycDocumentTypeScope::query()
                ->where('package_public_id', $package->public_id)
                ->select('kyc_document_type_id'))
            ->orderBy('sort_order')
            ->pluck('name')
            ->all();
    }

    protected function activeSubscriptionCount(Package $package): int
    {
        return UserPackage::query()
            ->where('package_id', $package->id)
            // Only subscriptions that still grant something. An expired or
            // cancelled one is history, and history does not keep a package on
            // the books.
            ->whereIn('status', array_filter(
                UserPackageStatus::cases(),
                fn (UserPackageStatus $status) => $status->entitles(),
            ))
            ->count();
    }

    /**
     * Replace the feature values wholesale.
     *
     * An absent feature is not "unchanged" — it is "this package does not grant
     * it", and the enum's own default then applies. Merging would make a
     * facility impossible to withdraw through the form that granted it.
     *
     * @param  array<string, mixed>  $features
     */
    protected function syncFeatures(Package $package, array $features): void
    {
        $package->features()->delete();

        foreach ($features as $key => $value) {
            $feature = PackageFeature::tryFrom((string) $key);

            if ($feature === null || $value === null || $value === '') {
                continue;
            }

            $package->features()->create([
                'feature' => $feature->value,
                'value' => $feature->type()->serialise(
                    $feature->type()->cast((string) $value)
                ),
            ]);
        }

        $package->unsetRelation('features');
    }

    /**
     * @param  array<int, array<string, mixed>>  $charges
     */
    protected function syncCharges(Package $package, array $charges): void
    {
        $package->charges()->delete();

        foreach ($charges as $charge) {
            if (blank($charge['charge_type'] ?? null)) {
                continue;
            }

            $package->charges()->create([
                'charge_type' => $charge['charge_type'],
                'amount_minor' => (int) ($charge['amount_minor'] ?? 0),
                'currency_code' => $package->currency_code,
                'frequency' => $charge['frequency'] ?? 'once',
            ]);
        }

        $package->unsetRelation('charges');
    }

    protected function nextSortOrder(): int
    {
        return (int) Package::query()->withTrashed()->max('sort_order') + 1;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    protected function record(
        string $action,
        Package $package,
        User $by,
        ?array $before = null,
        ?array $after = null,
    ): void {
        $this->audit->handle(new AuditEntry(
            action: $action,
            actorId: $by->id,
            auditableType: Package::class,
            auditableId: $package->id,
            before: $before,
            after: $after,
            module: 'package',
        ));
    }
}
