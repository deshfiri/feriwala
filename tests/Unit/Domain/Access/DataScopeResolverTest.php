<?php

use App\Domain\Access\DataScopeResolver;
use App\Domain\Access\Enums\DataScope;
use App\Domain\Access\Enums\PermissionModule;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A viewer holding an explicit set of permission names.
 *
 * @param  array<int, string>  $permissions
 */
function viewerWith(array $permissions): Authenticatable
{
    return new class($permissions) implements Authenticatable, Authorizable
    {
        /** @param array<int, string> $permissions */
        public function __construct(private array $permissions) {}

        public function can($abilities, $arguments = []): bool
        {
            return in_array($abilities, $this->permissions, true);
        }

        public function cant($abilities, $arguments = []): bool
        {
            return ! $this->can($abilities, $arguments);
        }

        public function cannot($abilities, $arguments = []): bool
        {
            return $this->cant($abilities, $arguments);
        }

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return 7;
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return 'remember_token';
        }
    };
}

function dataScopeResolver(): DataScopeResolver
{
    return new DataScopeResolver;
}

describe('visibility', function () {
    it('shows a guest nothing', function () {
        expect(dataScopeResolver()->for(null, PermissionModule::Order))->toBe(DataScope::None);
    });

    it('shows everything to a viewer holding the module view permission', function () {
        $staff = viewerWith(['order.view']);

        expect(dataScopeResolver()->for($staff, PermissionModule::Order))->toBe(DataScope::All);
    });

    it('shows only their own rows to an ordinary account', function () {
        $partner = viewerWith([]);

        expect(dataScopeResolver()->for($partner, PermissionModule::Order))->toBe(DataScope::Own);
    });

    it('does not let a permission on one module widen another', function () {
        // Being allowed to view orders must not expose the ledger.
        $staff = viewerWith(['order.view']);

        expect(dataScopeResolver()->for($staff, PermissionModule::Ledger))->toBe(DataScope::Own);
    });

    it('falls back to own rows when the viewer cannot be asked about permissions', function () {
        // Fortify permits non-Authorizable authenticatables. The safe answer is
        // the restrictive one, never the generous one.
        $odd = new class implements Authenticatable
        {
            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthIdentifier(): int
            {
                return 1;
            }

            public function getAuthPasswordName(): string
            {
                return 'password';
            }

            public function getAuthPassword(): string
            {
                return '';
            }

            public function getRememberToken(): string
            {
                return '';
            }

            public function setRememberToken($value): void {}

            public function getRememberTokenName(): string
            {
                return 'remember_token';
            }
        };

        expect(dataScopeResolver()->for($odd, PermissionModule::Wallet))->toBe(DataScope::Own);
    });

    it('reports whether a scope is restricted', function () {
        expect(DataScope::All->isRestricted())->toBeFalse()
            ->and(DataScope::Own->isRestricted())->toBeTrue()
            ->and(DataScope::None->isRestricted())->toBeTrue();
    });
});

describe('export', function () {
    it('is refused to a guest', function () {
        expect(dataScopeResolver()->canExport(null, PermissionModule::Report))->toBeFalse();
    });

    it('requires its own permission, separate from viewing', function () {
        // Reading a report on screen is not permission to carry the whole
        // dataset out of the system.
        $reader = viewerWith(['report.view']);

        expect(dataScopeResolver()->for($reader, PermissionModule::Report))->toBe(DataScope::All)
            ->and(dataScopeResolver()->canExport($reader, PermissionModule::Report))->toBeFalse();
    });

    it('is allowed when the export permission is held', function () {
        $exporter = viewerWith(['report.view', 'report.export']);

        expect(dataScopeResolver()->canExport($exporter, PermissionModule::Report))->toBeTrue();
    });
});

describe('sensitive columns', function () {
    it('are hidden from a viewer who may read the module', function () {
        // Reading a settlement report is not the same as reading the bank
        // details inside it.
        $reader = viewerWith(['report.view', 'report.export']);

        expect(dataScopeResolver()->canSeeSensitive($reader, PermissionModule::Report))->toBeFalse();
    });

    it('are shown when the permission is held', function () {
        $finance = viewerWith(['report.view', 'report.view_sensitive_data']);

        expect(dataScopeResolver()->canSeeSensitive($finance, PermissionModule::Report))->toBeTrue();
    });

    it('are refused for a module that does not define the permission', function () {
        // Inventory has no sensitive-data permission in the catalogue, so no
        // grant can accidentally unlock one.
        $anyone = viewerWith(['inventory.view_sensitive_data']);

        expect(dataScopeResolver()->canSeeSensitive($anyone, PermissionModule::Inventory))->toBeFalse();
    });
});
