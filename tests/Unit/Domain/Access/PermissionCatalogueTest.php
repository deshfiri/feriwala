<?php

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\PermissionCatalogue;

describe('the specification', function () {
    it('defines exactly the twenty-four permission verbs in §32.2', function () {
        // Twenty from the original list, View settings added by D24, Suspend
        // and Review added by D25 for the Supplier account domain, and
        // Reactivate added when suspending a *trading* business became its own
        // permission rather than riding on `account.reject`.
        expect(PermissionAction::cases())->toHaveCount(24);
    });

    it('defines exactly the twenty-one administrative roles in §32.1', function () {
        // Twenty from the specification, and Supplier Manager added by D25.
        expect(PlatformRole::cases())->toHaveCount(21);
    });

    it('gives every role a label', function () {
        foreach (PlatformRole::cases() as $role) {
            expect($role->label())->not->toBe('');
        }
    });
});

describe('the catalogue', function () {
    it('produces module.action permission names', function () {
        expect(PermissionCatalogue::all())->toContain('withdrawal.approve')
            ->and(PermissionCatalogue::all())->toContain('kyc.view_kyc_documents');
    });

    it('contains no duplicates', function () {
        $all = PermissionCatalogue::all();

        expect($all)->toHaveCount(count(array_unique($all)));
    });

    it('stays far smaller than the full cross-product', function () {
        // 28 modules x 20 verbs would be 560 meaningless permissions. The
        // matrix exists so the list can actually be audited.
        expect(count(PermissionCatalogue::all()))->toBeLessThan(300);
    });

    it('covers every module', function () {
        foreach (PermissionModule::cases() as $module) {
            expect(PermissionCatalogue::forModule($module))->not->toBeEmpty();
        }
    });

    it('rejects a permission that is not in the matrix', function () {
        expect(fn () => PermissionCatalogue::name(
            PermissionModule::Cms,
            PermissionAction::AdjustWallet,
        ))->toThrow(InvalidArgumentException::class);
    });

    it('composes a permission that is in the matrix', function () {
        expect(PermissionCatalogue::name(
            PermissionModule::Withdrawal,
            PermissionAction::ReleasePayment,
        ))->toBe('withdrawal.release_payment');
    });
});

describe('groupedByModule', function () {
    it('tells a dotted module value apart from the plain one it prefixes', function () {
        // Module::SupplierKyc's own value ("supplier.kyc") contains a dot,
        // so "supplier.kyc.view" must resolve to Supplier KYC, not to
        // Supplier with a bogus "kyc.view" action.
        $groups = collect(PermissionCatalogue::groupedByModule([
            'supplier.kyc.view',
            'supplier.kyc.review',
            'supplier.approve',
        ]));

        expect($groups->pluck('module'))
            ->toContain('Supplier KYC')
            ->toContain('Suppliers');

        $supplierKyc = $groups->firstWhere('module', 'Supplier KYC');
        $supplier = $groups->firstWhere('module', 'Suppliers');

        expect($supplierKyc['actions'])->toBe(['Review', 'View'])
            ->and($supplier['actions'])->toBe(['Approve']);
    });

    it('groups every real permission in the catalogue without throwing', function () {
        // A property check standing in for one per role: every permission
        // string the catalogue actually produces must resolve to exactly
        // one module, including every dotted one.
        $groups = PermissionCatalogue::groupedByModule(PermissionCatalogue::all());

        $totalActions = array_sum(array_map(fn (array $group) => count($group['actions']), $groups));

        expect($totalActions)->toBe(count(PermissionCatalogue::all()));
    });

    it('sorts groups by module label and actions within a group', function () {
        $groups = collect(PermissionCatalogue::groupedByModule([
            'sms.manage_settings',
            'sms.view',
            'account.view',
        ]));

        expect($groups->pluck('module')->all())->toBe(['Accounts', 'SMS'])
            ->and($groups->firstWhere('module', 'SMS')['actions'])
            ->toBe(['Manage settings', 'View']);
    });
});

describe('immutability guarantees', function () {
    it('offers no way to create, edit, or delete a ledger entry', function () {
        // The ledger is append-only (§23.2). Corrections are new reversal or
        // adjustment entries written through the wallet module.
        $ledger = PermissionCatalogue::forModule(PermissionModule::Ledger);

        expect($ledger)->not->toContain('ledger.create')
            ->and($ledger)->not->toContain('ledger.edit')
            ->and($ledger)->not->toContain('ledger.delete');
    });

    it('offers no way to edit or delete an audit log', function () {
        // §36.2: ordinary administrative users must not edit or delete these.
        $audit = PermissionCatalogue::forModule(PermissionModule::Audit);

        expect($audit)->not->toContain('audit.edit')
            ->and($audit)->not->toContain('audit.delete');
    });

    it('offers no way to delete a KYC submission', function () {
        // Review history is evidence of a decision (§7.3).
        expect(PermissionCatalogue::forModule(PermissionModule::Kyc))
            ->not->toContain('kyc.delete');
    });
});

describe('role grants', function () {
    it('gives Super Admin the entire catalogue', function () {
        expect(PlatformRole::SuperAdmin->grantsEverything())->toBeTrue()
            ->and(PlatformRole::SuperAdmin->permissions())
            ->toBe(PermissionCatalogue::all());
    });

    it('grants no role a permission outside the catalogue', function () {
        foreach (PlatformRole::cases() as $role) {
            foreach ($role->permissions() as $permission) {
                expect(PermissionCatalogue::exists($permission))->toBeTrue(
                    "{$role->value} grants unknown permission {$permission}",
                );
            }
        }
    });

    it('separates booking money from paying it out', function () {
        // The person who records an amount should not also release it.
        expect(PlatformRole::FinanceManager->permissions())
            ->not->toContain('withdrawal.release_payment')
            ->and(PlatformRole::WithdrawalApprover->permissions())
            ->toContain('withdrawal.release_payment');
    });

    it('keeps KYC documents to the KYC manager', function () {
        foreach (PlatformRole::cases() as $role) {
            if (in_array($role, [PlatformRole::SuperAdmin, PlatformRole::KycManager], true)) {
                continue;
            }

            expect($role->permissions())->not->toContain('kyc.view_kyc_documents');
        }
    });

    it('keeps wallet adjustment to the wallet manager', function () {
        foreach (PlatformRole::cases() as $role) {
            if (in_array($role, [PlatformRole::SuperAdmin, PlatformRole::WalletManager], true)) {
                continue;
            }

            expect($role->permissions())->not->toContain('wallet.adjust_wallet');
        }
    });

    it('keeps backups to the backup manager', function () {
        foreach (PlatformRole::cases() as $role) {
            if (in_array($role, [PlatformRole::SuperAdmin, PlatformRole::BackupManager], true)) {
                continue;
            }

            expect($role->permissions())->not->toContain('backup.manage_backups');
        }
    });

    it('gives the report viewer no access to sensitive columns', function () {
        expect(PlatformRole::ReportViewer->permissions())
            ->not->toContain('report.view_sensitive_data');
    });

    it('gives every role at least one permission', function () {
        foreach (PlatformRole::cases() as $role) {
            expect($role->permissions())->not->toBeEmpty("{$role->value} grants nothing");
        }
    });
});

describe('sensitive actions', function () {
    it('marks money movement and personal documents as needing escalation', function () {
        expect(PermissionAction::ReleasePayment->isSensitive())->toBeTrue()
            ->and(PermissionAction::AdjustWallet->isSensitive())->toBeTrue()
            ->and(PermissionAction::ReverseTransaction->isSensitive())->toBeTrue()
            ->and(PermissionAction::ViewKycDocuments->isSensitive())->toBeTrue()
            ->and(PermissionAction::ManageBackups->isSensitive())->toBeTrue();
    });

    it('does not burden ordinary reads with escalation', function () {
        expect(PermissionAction::View->isSensitive())->toBeFalse()
            ->and(PermissionAction::Export->isSensitive())->toBeFalse();
    });
});
