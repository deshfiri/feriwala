<?php

namespace App\Domain\Access;

use App\Domain\Access\Enums\PermissionAction as Action;
use App\Domain\Access\Enums\PermissionModule as Module;
use Illuminate\Support\Str;

/**
 * Which verbs each module accepts, and therefore every permission that exists.
 *
 * Generating the full cross-product of 28 modules × 20 verbs would produce 560
 * permissions, most of them meaningless — "adjust wallet on the CMS", "publish
 * an audit log". A permission list nobody can read is a permission list nobody
 * audits, so the matrix is declared deliberately.
 *
 * Adding a module means adding a row here. Nothing generates permissions at
 * runtime; the catalogue is the single source of truth, seeded into the database
 * and asserted by tests.
 */
class PermissionCatalogue
{
    /**
     * Verbs almost every module supports.
     *
     * @return array<int, Action>
     */
    protected static function crud(): array
    {
        return [Action::View, Action::Create, Action::Edit, Action::Delete];
    }

    /**
     * The matrix.
     *
     * @return array<string, array<int, Action>>
     */
    public static function matrix(): array
    {
        return [
            Module::Cms->value => [
                ...self::crud(),
                Action::Publish, Action::Unpublish, Action::Archive,
            ],

            /*
             * `reject` and `suspend` are different decisions and are kept
             * apart on purpose.
             *
             * `reject` belongs to the activation queue: an applicant at the
             * gate is declined and has never traded. `suspend` stops a
             * business that **is** trading — its invited staff lose the ERP
             * with it, its websites stop taking orders — and `reactivate`
             * undoes that. Authorising the second through the first meant
             * whoever worked the approval queue could halt a live business,
             * which is a much larger power than the queue needs.
             */
            Module::Account->value => [
                Action::View, Action::Create, Action::Edit,
                Action::Approve, Action::Reject, Action::Verify,
                Action::Export, Action::Archive, Action::ViewSensitiveData,
                Action::Suspend, Action::Reactivate,

                // Whether mobile verification is a required onboarding step
                // at all (§5.1) -- a policy decision, not a per-applicant
                // one, so it sits apart from Approve/Reject/Verify.
                Action::ManageSettings,
            ],

            // KYC is never deletable — submissions and their review history are
            // evidence of a decision and must survive (§7.3, §36.2).
            Module::Kyc->value => [
                Action::View, Action::Edit, Action::Create,
                Action::Approve, Action::Reject, Action::Verify,
                Action::Export, Action::ViewKycDocuments, Action::ManageSettings,
            ],

            Module::Package->value => [
                ...self::crud(),
                Action::Archive, Action::Approve, Action::Export, Action::ManageSettings,
            ],

            Module::Payment->value => [
                Action::View, Action::Create, Action::Verify,
                Action::Approve, Action::Reject, Action::ReverseTransaction,
                Action::Export, Action::ManageSettings, Action::ManageIntegrations,
            ],

            Module::Wallet->value => [
                Action::View, Action::AdjustWallet, Action::ReverseTransaction,
                Action::Approve, Action::Export, Action::ManageSettings,
            ],

            // The ledger is immutable (§23.2). No create, edit, or delete is
            // offered here at all — corrections go through wallet adjustment and
            // reversal, which write new entries.
            Module::Ledger->value => [
                Action::View, Action::Export, Action::ViewSensitiveData,
            ],

            Module::Catalog->value => [
                ...self::crud(),
                Action::Archive, Action::Publish, Action::Unpublish, Action::Export,
            ],

            Module::Inventory->value => [
                Action::View, Action::Edit, Action::Approve, Action::Export,
            ],

            Module::Wholesale->value => [
                Action::View, Action::Create, Action::Edit, Action::Export,
            ],

            Module::Dropshipping->value => [
                Action::View, Action::Publish, Action::Unpublish, Action::Export,
            ],

            Module::Website->value => [
                ...self::crud(),
                Action::Approve, Action::Archive, Action::Export,
                Action::ManageSettings, Action::ManageIntegrations,
            ],

            Module::Order->value => [
                Action::View, Action::Create, Action::Edit,
                Action::Approve, Action::Reject, Action::Verify,
                Action::Archive, Action::Export, Action::ManageSettings,
            ],

            Module::Fulfillment->value => [
                Action::View, Action::Create, Action::Edit, Action::Approve, Action::Export,
            ],

            Module::Courier->value => [
                ...self::crud(),
                Action::Approve, Action::Export, Action::ManageIntegrations,
            ],

            Module::Commission->value => [
                Action::View, Action::Create, Action::Edit,
                Action::Approve, Action::Reject, Action::ReverseTransaction,
                Action::Export, Action::ManageSettings,
            ],

            Module::Referral->value => [
                Action::View, Action::Create, Action::Edit,
                Action::Approve, Action::Reject, Action::ReverseTransaction,
                Action::Export, Action::ManageSettings, Action::ViewSettings,
            ],

            Module::Withdrawal->value => [
                Action::View, Action::Edit,
                Action::Approve, Action::Reject, Action::ReleasePayment,
                Action::Export, Action::ManageSettings,
            ],

            Module::Settlement->value => [
                Action::View, Action::Create, Action::Approve, Action::Export,
            ],

            Module::Notification->value => [
                Action::View, Action::Create, Action::Edit, Action::ManageSettings,
            ],

            Module::Sms->value => [
                ...self::crud(),
                Action::Export, Action::ManageSettings, Action::ManageIntegrations,
            ],

            Module::Report->value => [
                Action::View, Action::Export, Action::ViewSensitiveData,
            ],

            Module::Access->value => [
                ...self::crud(),
                Action::ManageSettings,
            ],

            Module::Seo->value => [
                Action::View, Action::Edit, Action::ManageSettings,
            ],

            Module::Backup->value => [
                Action::View, Action::Create, Action::ManageBackups,
            ],

            // Audit logs are append-only and not editable by ordinary
            // administrators (§36.2), so no edit or delete exists.
            Module::Audit->value => [
                Action::ViewAuditLogs, Action::Export,
            ],

            Module::System->value => [
                Action::View, Action::ManageSettings, Action::ManageIntegrations,
            ],

            Module::Integration->value => [
                Action::View, Action::ManageIntegrations, Action::ManageSettings,
            ],

            // The Supplier account domain (D25). Exactly the literal
            // permission strings the batch specifies:
            //   supplier.view / supplier.approve / supplier.suspend
            Module::Supplier->value => [
                Action::View, Action::Approve, Action::Suspend,
            ],

            //   supplier.kyc.view / supplier.kyc.review
            Module::SupplierKyc->value => [
                Action::View, Action::Review,
            ],

            //   supplier_listing.view / .review / .approve
            Module::SupplierListing->value => [
                Action::View, Action::Review, Action::Approve,
            ],

            //   supplier_pricing.view / .edit — never granted to Product
            //   Manager by default (D25): a partner-visible catalogue role
            //   holding a permission that reaches a Supplier's confidential
            //   rate would be exactly the leak this batch exists to prevent.
            Module::SupplierPricing->value => [
                Action::View, Action::Edit,
            ],

            //   supplier_stock.view / .edit
            Module::SupplierStock->value => [
                Action::View, Action::Edit,
            ],

            //   supplier_payable.view / .approve — view (seeing what is owed)
            //   and approve (settling it, P13-23) are deliberately separate
            //   permission strings, so a role can hold one without the other
            //   ("Supplier payable viewing and settlement must remain
            //   permission separated").
            Module::SupplierPayable->value => [
                Action::View, Action::Approve,
            ],

            // Weight-tier delivery-charge rules and the global knobs
            // CalculateDeliveryCharge applies on top of them (beta-critical
            // batch, Commit 2) -- a settings-only module, the same shape
            // KycDocumentType's own module uses.
            Module::DeliverySettings->value => [
                Action::View, Action::Edit, Action::ManageSettings,
            ],

            // Product Sourcing Groups: which catalogue products fulfil the
            // same order. `create`/`edit` shape groups and their mappings;
            // `archive` switches a group off. Nothing is deletable -- mapping
            // history is what explains an earlier allocation.
            Module::SourcingGroup->value => [
                Action::View, Action::Create, Action::Edit, Action::Archive,
            ],
        ];
    }

    /**
     * Every permission name that exists, e.g. `withdrawal.approve`.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        $permissions = [];

        foreach (self::matrix() as $module => $actions) {
            foreach ($actions as $action) {
                $permissions[] = $module.'.'.$action->value;
            }
        }

        sort($permissions);

        return array_values(array_unique($permissions));
    }

    /**
     * Permission names for one module.
     *
     * @return array<int, string>
     */
    public static function forModule(Module $module): array
    {
        return array_map(
            fn (Action $action) => $module->value.'.'.$action->value,
            self::matrix()[$module->value] ?? [],
        );
    }

    /**
     * A flat list of permission names, grouped by module label with each
     * module's action labels sorted -- the shape both the Roles screen and
     * the staff directory's effective-permission view need, built once so
     * the two cannot drift apart.
     *
     * @param  array<int, string>  $permissionNames
     * @return array<int, array{module: string, actions: array<int, string>}>
     */
    public static function groupedByModule(array $permissionNames): array
    {
        $byModule = [];

        foreach ($permissionNames as $permission) {
            [$moduleLabel, $actionLabel] = self::describe($permission);
            $byModule[$moduleLabel][] = $actionLabel;
        }

        $groups = [];

        foreach ($byModule as $moduleLabel => $actionLabels) {
            sort($actionLabels);

            $groups[] = [
                'module' => $moduleLabel,
                'actions' => $actionLabels,
            ];
        }

        usort($groups, fn (array $a, array $b) => $a['module'] <=> $b['module']);

        return $groups;
    }

    /**
     * The module and action label for one permission name -- catalogue
     * permissions resolve through the {@see Module}/{@see Action} enums as
     * usual; a *custom* permission naming something the closed catalogue
     * does not is grouped and labelled from its own literal text instead of
     * thrown at, since Role and Permission management lets an administrator
     * hold a custom permission on a custom role.
     *
     * @return array{0: string, 1: string}
     */
    public static function describe(string $permission): array
    {
        $module = self::moduleForOrNull($permission);

        if ($module !== null) {
            $actionValue = substr($permission, strlen($module->value) + 1);

            return [$module->label(), Action::tryFrom($actionValue)?->label() ?? Str::headline($actionValue)];
        }

        [$prefix, $rest] = array_pad(explode('.', $permission, 2), 2, '');

        return [
            Str::headline($prefix),
            Str::headline($rest !== '' ? $rest : $permission),
        ];
    }

    /**
     * The module a permission name belongs to.
     *
     * Not a plain split on the first dot: {@see Module::SupplierKyc}'s own
     * value ("supplier.kyc") contains one, so "supplier.kyc.view" would
     * otherwise parse as module "supplier", action "kyc.view" -- a module
     * that exists too, so the mistake would not even throw. Matching the
     * *longest* module value that prefixes the permission is what tells
     * "supplier.kyc.view" apart from a real "supplier.approve".
     */
    protected static function moduleFor(string $permission): Module
    {
        return self::moduleForOrNull($permission) ?? throw new \InvalidArgumentException(
            "[{$permission}] does not belong to any known module."
        );
    }

    /**
     * The same longest-prefix match as {@see moduleFor()}, but null for a
     * custom permission naming something outside every known module rather
     * than throwing.
     */
    public static function moduleForOrNull(string $permission): ?Module
    {
        $matches = array_filter(
            Module::cases(),
            fn (Module $module) => str_starts_with($permission, $module->value.'.'),
        );

        usort($matches, fn (Module $a, Module $b) => strlen($b->value) <=> strlen($a->value));

        return $matches[0] ?? null;
    }

    /**
     * Compose a single permission name, failing loudly if that combination is
     * not in the matrix — a typo in a policy should surface in tests, not as a
     * check that silently never passes.
     */
    public static function name(Module $module, Action $action): string
    {
        $name = $module->value.'.'.$action->value;

        if (! in_array($name, self::all(), true)) {
            throw new \InvalidArgumentException(
                "[{$name}] is not a permission. Add it to the catalogue matrix first."
            );
        }

        return $name;
    }

    /**
     * Whether a permission name exists.
     */
    public static function exists(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }
}
