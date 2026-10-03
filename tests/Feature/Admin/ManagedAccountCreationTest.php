<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Actions\ManagePasswordSetup;
use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Notifications\SupplierResetPassword;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/*
 * Staff-created Client/Partner and Supplier accounts: the same identities and
 * relationships self-registration builds, a password nobody ever sees, and a
 * setup link that is expiring, single-use, replaceable and revocable.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->admin = testPlatformStaff(PlatformRole::Admin);
});

function managedPartnerPayload(array $overrides = []): array
{
    return [
        'name' => 'Rahim Uddin',
        'business_name' => 'Rahim Traders',
        'email' => 'rahim@example.com',
        'mobile' => '+8801712345678',
        'country' => 'BD',
        'reason' => 'Walk-in customer onboarded at the office.',
        ...$overrides,
    ];
}

function managedSupplierPayload(array $overrides = []): array
{
    return [
        'business_name' => 'Acme Wholesale',
        'contact_person_name' => 'Jamal Uddin',
        'business_address' => '12 Motijheel, Dhaka',
        'email' => 'acme@example.com',
        'mobile' => '+8801812345678',
        'trade_licence_number' => 'TL-100',
        'reason' => 'Supplier onboarded by phone.',
        ...$overrides,
    ];
}

/** The reset token the person was emailed, read from the notification itself. */
function managedSetupToken(object $identity, string $notification): string
{
    $token = null;

    Notification::assertSentTo($identity, $notification, function ($sent) use (&$token) {
        $token = $sent->token;

        return true;
    });

    return $token;
}

/**
 * Posts to a password-reset route as a visitor: the reset pages are guest-only,
 * so the staff session that created the account is signed out first.
 */
function managedReset(string $route, array $body): TestResponse
{
    Auth::guard('web')->logout();
    Auth::guard('supplier')->logout();

    return test()->post(route($route), $body);
}

describe('a Client/Partner account', function () {
    it('creates the person, the business, the owner membership and a setup link', function () {
        $this->actingAs($this->admin)->post(route('admin.accounts.store'), managedPartnerPayload())
            ->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'rahim@example.com')->firstOrFail();
        $account = BusinessAccount::query()->where('owner_id', $user->id)->firstOrFail();

        expect($account->name)->toBe('Rahim Traders')
            ->and($account->status)->toBe(AccountStatus::Registered)
            ->and($account->memberships()->where('user_id', $user->id)->value('role'))->toBe(AccountRole::Owner)
            ->and($user->email_verified_at)->toBeNull()
            ->and($user->mobile_verified_at)->toBeNull()
            ->and($user->referral_code)->not->toBeNull()
            ->and(DB::table('password_reset_tokens')->where('email', 'rahim@example.com')->count())->toBe(1);

        Notification::assertSentTo($user, ResetPassword::class);
    });

    it('lets the owner set their own password once, and never reveals one to staff', function () {
        $response = $this->actingAs($this->admin)->post(route('admin.accounts.store'), managedPartnerPayload());

        $user = User::query()->where('email', 'rahim@example.com')->firstOrFail();
        $token = managedSetupToken($user, ResetPassword::class);

        // Nothing staff can read carries the password hash or the token.
        $page = $this->get($response->headers->get('Location'));
        expect(json_encode($page->viewData('page')))->not->toContain($user->password)->not->toContain($token);

        auth()->logout();

        managedReset('password.update', [
            'token' => $token, 'email' => 'rahim@example.com',
            'password' => testStrongPassword(), 'password_confirmation' => testStrongPassword(),
        ])->assertSessionHasNoErrors();

        expect(Hash::check(testStrongPassword(), $user->fresh()->password))->toBeTrue()
            ->and(DB::table('password_reset_tokens')->where('email', 'rahim@example.com')->count())->toBe(0);

        // Single use: the same link does nothing the second time.
        managedReset('password.update', [
            'token' => $token, 'email' => 'rahim@example.com',
            'password' => 'Another!Passw0rd#2026', 'password_confirmation' => 'Another!Passw0rd#2026',
        ])->assertSessionHasErrors('email');
    });

    it('refuses an expired link', function () {
        $this->actingAs($this->admin)->post(route('admin.accounts.store'), managedPartnerPayload());
        $user = User::query()->where('email', 'rahim@example.com')->firstOrFail();
        $token = managedSetupToken($user, ResetPassword::class);

        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();

        managedReset('password.update', [
            'token' => $token, 'email' => 'rahim@example.com',
            'password' => testStrongPassword(), 'password_confirmation' => testStrongPassword(),
        ])->assertSessionHasErrors('email');
    });

    it('replaces the old link when a new one is sent, and revokes on request', function () {
        $this->actingAs($this->admin)->post(route('admin.accounts.store'), managedPartnerPayload());
        $user = User::query()->where('email', 'rahim@example.com')->firstOrFail();
        $account = $user->ownedAccount;
        $first = managedSetupToken($user, ResetPassword::class);

        Notification::fake();
        $this->post(route('admin.accounts.setup-link.store', $account), ['reason' => 'They lost the email.'])->assertSessionHasNoErrors();
        $second = managedSetupToken($user, ResetPassword::class);

        expect($second)->not->toBe($first);

        managedReset('password.update', [
            'token' => $first, 'email' => 'rahim@example.com',
            'password' => testStrongPassword(), 'password_confirmation' => testStrongPassword(),
        ])->assertSessionHasErrors('email');

        $this->actingAs($this->admin)->delete(route('admin.accounts.setup-link.destroy', $account), ['reason' => 'Sent to the wrong address.'])->assertSessionHasNoErrors();

        expect(DB::table('password_reset_tokens')->where('email', 'rahim@example.com')->count())->toBe(0);

        $this->post(route('admin.accounts.setup-link.store', $account), [])->assertSessionHasErrors('reason');
    });

    it('rejects a duplicate email or mobile, a bad referral code and a missing reason', function () {
        $existing = User::factory()->create(['email' => 'taken@example.com', 'mobile' => '+8801999999999']);

        $this->actingAs($this->admin)->post(route('admin.accounts.store'), managedPartnerPayload(['email' => 'taken@example.com']))
            ->assertSessionHasErrors('email');
        $this->post(route('admin.accounts.store'), managedPartnerPayload(['mobile' => $existing->mobile]))
            ->assertSessionHasErrors('mobile');
        $this->post(route('admin.accounts.store'), managedPartnerPayload(['referral_code' => 'NOSUCHCODE']))
            ->assertSessionHasErrors('referral_code');
        $this->post(route('admin.accounts.store'), managedPartnerPayload(['reason' => '']))
            ->assertSessionHasErrors('reason');

        expect(User::query()->where('email', 'rahim@example.com')->exists())->toBeFalse();
    });

    it('audits the creation and the setup link with the actor and reason, and no secret', function () {
        $this->actingAs($this->admin)->post(route('admin.accounts.store'), managedPartnerPayload());
        $user = User::query()->where('email', 'rahim@example.com')->firstOrFail();
        $token = managedSetupToken($user, ResetPassword::class);

        $created = AuditLog::query()->where('action', 'account.created_by_staff')->firstOrFail();
        $sent = AuditLog::query()->where('action', 'password_setup.sent')->firstOrFail();

        expect($created->actor_id)->toBe($this->admin->id)
            ->and($created->reason)->toBe('Walk-in customer onboarded at the office.')
            ->and($sent->actor_id)->toBe($this->admin->id)
            ->and($sent->reason)->toContain('Account opened by staff');

        foreach (AuditLog::query()->get() as $row) {
            expect(json_encode($row->getAttributes()))->not->toContain($token)->not->toContain($user->password);
        }
    });

    it('is refused to staff without account.create and to Client/Partner sessions', function () {
        $this->actingAs(testPlatformStaff(PlatformRole::OrderManager))->post(route('admin.accounts.store'), managedPartnerPayload())->assertForbidden();
        $this->get(route('admin.accounts.create'))->assertForbidden();

        $this->actingAs(testBusinessAccount()->owner)->post(route('admin.accounts.store'), managedPartnerPayload())->assertForbidden();

        expect(User::query()->where('email', 'rahim@example.com')->exists())->toBeFalse();
    });

    it('shows the create screen to staff who may open accounts', function () {
        $this->actingAs($this->admin)->get(route('admin.accounts.create'))->assertOk();
    });
});

describe('a Supplier account', function () {
    beforeEach(function () {
        $this->manager = testPlatformStaff(PlatformRole::SupplierManager);
    });

    it('creates a Supplier on its own guard and table with a setup link', function () {
        $this->actingAs($this->manager)->post(route('admin.suppliers.store'), managedSupplierPayload())
            ->assertSessionHasNoErrors();

        $supplier = Supplier::query()->where('email', 'acme@example.com')->firstOrFail();

        expect($supplier->status)->toBe(SupplierStatus::VerificationPending)
            ->and($supplier->email_verified_at)->toBeNull()
            ->and($supplier->mobile_verified_at)->toBeNull()
            ->and($supplier->statusHistory()->count())->toBe(2)
            ->and(DB::table('supplier_password_reset_tokens')->where('email', 'acme@example.com')->count())->toBe(1)
            // Never in the Client/Partner identity tables.
            ->and(User::query()->where('email', 'acme@example.com')->exists())->toBeFalse()
            ->and(DB::table('password_reset_tokens')->where('email', 'acme@example.com')->count())->toBe(0);

        Notification::assertSentTo($supplier, SupplierResetPassword::class);
    });

    it('lets the Supplier set their own password once, through the Supplier flow', function () {
        $this->actingAs($this->manager)->post(route('admin.suppliers.store'), managedSupplierPayload());
        $supplier = Supplier::query()->where('email', 'acme@example.com')->firstOrFail();
        $token = managedSetupToken($supplier, SupplierResetPassword::class);

        $body = [
            'token' => $token, 'email' => 'acme@example.com',
            'password' => testStrongPassword(), 'password_confirmation' => testStrongPassword(),
        ];

        managedReset('supplier.password.update', $body)->assertSessionHasNoErrors();

        expect(Hash::check(testStrongPassword(), $supplier->fresh()->password))->toBeTrue();

        managedReset('supplier.password.update', $body)->assertSessionHasErrors('email');

        // The Client/Partner reset route never accepts a Supplier's token.
        managedReset('password.update', $body)->assertSessionHasErrors('email');
    });

    it('replaces and revokes the Supplier link, with a mandatory reason', function () {
        $this->actingAs($this->manager)->post(route('admin.suppliers.store'), managedSupplierPayload());
        $supplier = Supplier::query()->where('email', 'acme@example.com')->firstOrFail();

        $this->post(route('admin.suppliers.setup-link.store', $supplier), [])->assertSessionHasErrors('reason');
        $this->post(route('admin.suppliers.setup-link.store', $supplier), ['reason' => 'Lost the email.'])->assertSessionHasNoErrors();

        expect(DB::table('supplier_password_reset_tokens')->where('email', 'acme@example.com')->count())->toBe(1);

        $this->delete(route('admin.suppliers.setup-link.destroy', $supplier), ['reason' => 'Wrong address.'])->assertSessionHasNoErrors();

        expect(DB::table('supplier_password_reset_tokens')->where('email', 'acme@example.com')->count())->toBe(0)
            ->and(AuditLog::query()->whereIn('action', ['password_setup.sent', 'password_setup.revoked'])->count())->toBe(3);
    });

    it('rejects duplicates, bad numbers and a missing reason', function () {
        Supplier::factory()->create(['email' => 'taken@example.com', 'mobile' => '+8801777777777']);

        $this->actingAs($this->manager)->post(route('admin.suppliers.store'), managedSupplierPayload(['email' => 'taken@example.com']))
            ->assertSessionHasErrors('email');
        $this->post(route('admin.suppliers.store'), managedSupplierPayload(['mobile' => '+8801777777777']))
            ->assertSessionHasErrors('mobile');
        $this->post(route('admin.suppliers.store'), managedSupplierPayload(['reason' => '']))
            ->assertSessionHasErrors('reason');

        expect(Supplier::query()->where('email', 'acme@example.com')->exists())->toBeFalse();
    });

    it('is refused to staff without supplier.create, to partners and to Supplier sessions', function () {
        $this->actingAs($this->admin)->post(route('admin.suppliers.store'), managedSupplierPayload())->assertForbidden();
        $this->actingAs(testBusinessAccount()->owner)->post(route('admin.suppliers.store'), managedSupplierPayload())->assertForbidden();

        expect(Supplier::query()->where('email', 'acme@example.com')->exists())->toBeFalse();
    });

    it('keeps a Supplier session off the create screen', function () {
        supplierTestSignIn(Supplier::factory()->create());

        $this->get(route('admin.suppliers.create'))->assertRedirect(route('login'));
    });

    it('shows the create screen to Supplier managers', function () {
        $this->actingAs($this->manager)->get(route('admin.suppliers.create'))->assertOk();
    });
});

it('never lets a setup link be issued without a reason', function () {
    expect(fn () => app(ManagePasswordSetup::class)->issue(User::factory()->create(), ManagePasswordSetup::USERS, $this->admin, '  '))
        ->toThrow(InvalidArgumentException::class);
});
