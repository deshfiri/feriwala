<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * The staff directory of Client/Partner business accounts.
 *
 * A directory, not a dossier: this asserts both that staff can find an
 * account and that finding one hands over nothing it should not.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = testPlatformStaff(PlatformRole::Admin);
});

function directoryAccount(AccountStatus $status, string $name): BusinessAccount
{
    $account = testBusinessAccount($status);
    $account->forceFill(['name' => $name])->save();

    return $account;
}

describe('reaching the directory', function () {
    it('lists every business with the identity a staff member searches by', function () {
        $account = directoryAccount(AccountStatus::Active, 'Karim Textiles');

        $this->actingAs($this->staff)
            ->get(route('admin.accounts.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/accounts/index')
                ->where('accounts.data.0.name', 'Karim Textiles')
                ->where('accounts.data.0.id', $account->public_id)
                ->has('summary')
                ->has('packages'));
    });

    it('refuses a staff member without the account permission', function () {
        $stranger = User::factory()->staff()->create();

        $this->actingAs($stranger)->get(route('admin.accounts.index'))->assertForbidden();
    });

    it('refuses a client or partner signing in to their own account', function () {
        // A business identity must never reach a staff route (§31.3).
        $account = directoryAccount(AccountStatus::Active, 'Karim Textiles');

        $this->actingAs($account->owner)->get(route('admin.accounts.index'))->assertForbidden();
    });

    it('never lets a signed-in supplier reach the staff directory', function () {
        /*
         * A Supplier authenticates on its own guard (D25), so it is not
         * signed in on `web` at all and the route sends it to login rather
         * than refusing it — one gate earlier than a 403, and the reason the
         * assertion is "not this page" rather than a specific status.
         */
        supplierTestSignIn(Supplier::factory()->create());

        $response = $this->get(route('admin.accounts.index'));

        expect($response->getStatusCode())->not->toBe(200)
            ->and($response->getStatusCode())->toBeIn([302, 403]);
    });
});

describe('what a row may carry', function () {
    it('never sends a database id, a kyc document, a payout detail or a wallet figure', function () {
        directoryAccount(AccountStatus::Active, 'Karim Textiles');

        $response = $this->actingAs($this->staff)->get(route('admin.accounts.index'));

        $row = $response->viewData('page')['props']['accounts']['data'][0];

        // §8: public ids in anything a URL or payload carries.
        expect($row)->not->toHaveKey('business_account_id')
            ->and($row['id'])->not->toBeNumeric();

        // A directory hands over none of the dossier's confidential fields.
        foreach (['kyc', 'documents', 'payout', 'wallet', 'balance', 'password'] as $forbidden) {
            expect(implode(' ', array_keys($row)))->not->toContain($forbidden);
        }
    });
});

describe('searching', function () {
    it('finds an account by its public id, business name, owner, email and mobile', function () {
        $account = directoryAccount(AccountStatus::Active, 'Karim Textiles');
        $owner = $account->owner;
        directoryAccount(AccountStatus::Active, 'Unrelated Trading');

        $searches = [
            $account->public_id,
            'Karim Text',
            $owner->name,
            $owner->email,
            $owner->mobile,
        ];

        foreach ($searches as $search) {
            $this->actingAs($this->staff)
                ->get(route('admin.accounts.index', ['search' => $search]))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->has('accounts.data', 1)
                    ->where('accounts.data.0.id', $account->public_id));
        }
    });

    it('returns nothing rather than everything for a search that matches no one', function () {
        directoryAccount(AccountStatus::Active, 'Karim Textiles');

        $this->actingAs($this->staff)
            ->get(route('admin.accounts.index', ['search' => 'zzzz-no-such-business']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('accounts.data', 0));
    });
});

describe('filtering', function () {
    it('groups the 22 statuses into the states staff think in', function () {
        directoryAccount(AccountStatus::Active, 'Trading One');
        directoryAccount(AccountStatus::KycPending, 'Joining One');
        directoryAccount(AccountStatus::Suspended, 'Halted One');

        $expectations = [
            'trading' => 'Trading One',
            'onboarding' => 'Joining One',
            'halted' => 'Halted One',
        ];

        foreach ($expectations as $state => $name) {
            $this->actingAs($this->staff)
                ->get(route('admin.accounts.index', ['state' => $state]))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->has('accounts.data', 1)
                    ->where('accounts.data.0.name', $name));
        }
    });

    it('finds accounts with an outstanding kyc re-verification', function () {
        $reverifying = directoryAccount(AccountStatus::Active, 'Asking Again');
        $settled = directoryAccount(AccountStatus::Active, 'All Done');

        // Round 1 approved, round 2 requested and not yet decided.
        KycSubmission::create([
            'business_account_id' => $reverifying->id,
            'status' => KycStatus::Approved, 'round' => 1, 'reviewed_at' => now(),
        ]);
        KycSubmission::create([
            'business_account_id' => $reverifying->id,
            'status' => KycStatus::Draft, 'round' => 2,
            'requested_at' => now(), 'requested_by' => $this->staff->id,
            'request_reason' => 'Annual re-verification.',
        ]);

        KycSubmission::create([
            'business_account_id' => $settled->id,
            'status' => KycStatus::Approved, 'round' => 1, 'reviewed_at' => now(),
        ]);

        $this->actingAs($this->staff)
            ->get(route('admin.accounts.index', ['kyc_reverification' => 'required']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('accounts.data', 1)
                ->where('accounts.data.0.name', 'Asking Again'));

        $this->actingAs($this->staff)
            ->get(route('admin.accounts.index', ['kyc_reverification' => 'none']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('accounts.data', 1)
                ->where('accounts.data.0.name', 'All Done'));
    });

    it('reads kyc status from the latest round, not any round', function () {
        // An approved first round must not file a re-verifying account under
        // "approved" — the latest round is what describes the account now.
        $account = directoryAccount(AccountStatus::Active, 'Asking Again');

        KycSubmission::create([
            'business_account_id' => $account->id,
            'status' => KycStatus::Approved, 'round' => 1, 'reviewed_at' => now(),
        ]);
        KycSubmission::create([
            'business_account_id' => $account->id,
            'status' => KycStatus::Draft, 'round' => 2,
        ]);

        $this->actingAs($this->staff)
            ->get(route('admin.accounts.index', ['kyc_status' => KycStatus::Approved->value]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('accounts.data', 0));

        $this->actingAs($this->staff)
            ->get(route('admin.accounts.index', ['kyc_status' => KycStatus::Draft->value]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('accounts.data', 1));
    });
});

describe('sorting and pagination', function () {
    it('ignores a sort column that is not on the whitelist', function () {
        directoryAccount(AccountStatus::Active, 'Karim Textiles');

        // A parameter naming any column would let a URL order by, and thereby
        // probe, something the list never shows.
        $this->actingAs($this->staff)
            ->get(route('admin.accounts.index', ['sort' => 'owner_id', 'direction' => 'asc']))
            ->assertOk();
    });

    it('paginates rather than returning every account', function () {
        foreach (range(1, 30) as $index) {
            directoryAccount(AccountStatus::Active, "Business {$index}");
        }

        $this->actingAs($this->staff)
            ->get(route('admin.accounts.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('accounts.data', 25)
                ->where('accounts.total', 30));
    });
});

describe('the summary counts', function () {
    it('counts each group of work waiting', function () {
        directoryAccount(AccountStatus::KycUnderReview, 'Kyc One');
        directoryAccount(AccountStatus::PaymentPending, 'Pay One');
        directoryAccount(AccountStatus::ApprovalPending, 'Approve One');
        directoryAccount(AccountStatus::Active, 'Trading One');
        directoryAccount(AccountStatus::Suspended, 'Halted One');
        directoryAccount(AccountStatus::PackageExpired, 'Expired One');

        $this->actingAs($this->staff)
            ->get(route('admin.accounts.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.kyc_pending', 1)
                ->where('summary.payment_pending', 1)
                ->where('summary.approval_pending', 1)
                ->where('summary.active', 1)
                ->where('summary.suspended', 1)
                ->where('summary.expired', 1)
                ->where('summary.total', 6));
    });
});
