<?php

namespace App\Domain\Access;

use App\Domain\Access\Enums\PermissionAction as Action;
use App\Domain\Access\Enums\PermissionModule as Module;

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

            Module::Account->value => [
                Action::View, Action::Create, Action::Edit,
                Action::Approve, Action::Reject, Action::Verify,
                Action::Export, Action::Archive, Action::ViewSensitiveData,
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
                Action::Export, Action::ManageSettings,
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
