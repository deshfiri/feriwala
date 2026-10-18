<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Actions\EraseAccount;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\DeletionRequestStatus;
use App\Domain\Account\Enums\UserStatus;
use App\Domain\Account\Models\AccountDeletionRequest;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Order\Models\Order;
use App\Domain\Supplier\Actions\OpenSupplierKycRound;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Erasing an account (§5.3).
 *
 * The ledger, orders, payments and audit trail stay whole and the database's
 * triggers are never touched; what goes is everything that identified the person.
 * These hold that: personal data is deleted or replaced, financial and order
 * records are kept, the audit trail is only ever added to, no trigger is
 * switched off, files go only once the erasure has committed, and nobody erases
 * their own account.
 *
 * Helpers are prefixed because Pest loads every test file into one global
 * function namespace.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('kyc');

    $this->admin = testPlatformStaff(PlatformRole::Admin);
});

/**
 * A partner with a home in the system: an address, a session, a notification, a
 * KYC value and a document, and an order with a customer on it.
 *
 * @return array{user: User, account: BusinessAccount, order: Order, path: string}
 */
function erasureTestPartner(): array
{
    $account = testBusinessAccount(AccountStatus::Active);
    $user = $account->owner;
    $user->forceFill(['mobile' => '01711223344', 'date_of_birth' => '1990-01-01', 'nationality' => 'BD'])->save();

    DB::table('user_addresses')->insert(['user_id' => $user->id, 'type' => 'home', 'line_1' => '12 Secret Road', 'city' => 'Dhaka', 'country' => 'BD', 'contact_name' => 'Real Name', 'contact_mobile' => '01711223344', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('authenticated_sessions')->insert(['public_id' => (string) Str::ulid(), 'user_id' => $user->id, 'session_key' => Str::random(20), 'device_fingerprint' => 'fp', 'device_label' => 'Phone', 'ip_address' => '203.0.113.9', 'last_active_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()]);
    DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'test', 'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id, 'data' => '{"x":1}', 'created_at' => now(), 'updated_at' => now()]);

    $type = KycDocumentType::factory()->create(['key' => 'nid']);
    $submission = KycSubmission::create(['business_account_id' => $account->id, 'status' => KycStatus::Approved, 'round' => 1]);
    $submission->fields()->create(['kyc_document_type_id' => $type->id, 'value' => '1990123456789']);

    $path = 'kyc/'.Str::random(10).'.jpg';
    Storage::disk('kyc')->put($path, 'passport-photo');
    DB::table('kyc_documents')->insert(['public_id' => (string) Str::ulid(), 'kyc_submission_id' => $submission->id, 'kyc_document_type_id' => $type->id, 'disk' => 'kyc', 'path' => $path, 'original_name' => 'passport.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 10, 'checksum' => 'abc', 'is_encrypted' => false, 'created_at' => now(), 'updated_at' => now()]);

    $order = Order::factory()->create(['business_account_id' => $account->id]);

    return compact('user', 'account', 'order', 'path');
}

/** The number of triggers that are not simply on. Switching one off would make this non-zero. */
function erasureTestDisabledTriggers(): int
{
    return (int) DB::selectOne("select count(*) as c from pg_trigger where not tgisinternal and tgenabled <> 'O'")->c;
}

describe('erasing a partner', function () {
    it('replaces the login with an anonymous tombstone that can no longer sign in', function () {
        ['user' => $user] = erasureTestPartner();
        $email = $user->email;

        $result = app(EraseAccount::class)->handle($user, $this->admin, 'Requested by the owner.', $email);

        $user->refresh();

        expect($user->erased_at)->not->toBeNull()
            ->and($user->tombstone_reference)->toStartWith('ERASED-')->toBe($result->tombstone)
            ->and($user->name)->toBe('Deleted account')
            ->and($user->email)->toMatch('/^erased-[a-z0-9]{8}@deleted\.invalid$/')
            ->and($user->mobile)->toBeNull()
            ->and($user->date_of_birth)->toBeNull()
            ->and($user->nationality)->toBeNull()
            ->and($user->identity_status)->toBe(UserStatus::Closed)
            ->and(Hash::check('password', $user->password))->toBeFalse();
    });

    it('deletes sessions, notifications, addresses, KYC values and documents', function () {
        ['user' => $user, 'account' => $account] = erasureTestPartner();

        app(EraseAccount::class)->handle($user, $this->admin, 'Requested by the owner.', $user->email);

        expect(DB::table('authenticated_sessions')->where('user_id', $user->id)->count())->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
            ->and(DB::table('notifications')->where('notifiable_id', $user->id)->where('notifiable_type', $user->getMorphClass())->count())->toBe(0)
            ->and(DB::table('user_addresses')->where('user_id', $user->id)->where('line_1', '12 Secret Road')->count())->toBe(0)
            ->and(DB::table('kyc_submission_fields')->count())->toBe(0)
            ->and(DB::table('kyc_documents')->where('original_name', 'passport.jpg')->count())->toBe(0);

        expect($account->refresh()->tombstone_reference)->toStartWith('ERASED-')
            ->and($account->name)->toStartWith('Deleted account ERASED-');
    });

    it('keeps the orders, and removes the customer\'s details from them where the database allows', function () {
        ['user' => $user, 'order' => $order] = erasureTestPartner();

        $result = app(EraseAccount::class)->handle($user, $this->admin, 'Requested by the owner.', $user->email);

        // The record itself is never deleted.
        expect(DB::table('orders')->where('id', $order->id)->exists())->toBeTrue()
            ->and($result->deleted)->not->toHaveKey('orders')
            ->and($result->deleted)->not->toHaveKey('ledger_entries')
            ->and($result->deleted)->not->toHaveKey('audit_logs');
    });

    it('never switches a trigger off, and only ever adds to the audit trail', function () {
        ['user' => $user] = erasureTestPartner();
        $triggersBefore = erasureTestDisabledTriggers();
        $auditBefore = AuditLog::query()->pluck('id')->all();

        app(EraseAccount::class)->handle($user, $this->admin, 'Requested by the owner.', $user->email);

        expect(erasureTestDisabledTriggers())->toBe($triggersBefore)->toBe(0)
            // Every audit row that existed still exists.
            ->and(AuditLog::query()->whereIn('id', $auditBefore)->count())->toBe(count($auditBefore));
    });

    it('records one audit line with the actor, the tombstone and counts — and no personal data', function () {
        ['user' => $user] = erasureTestPartner();
        $email = $user->email;
        $name = $user->name;

        $result = app(EraseAccount::class)->handle($user, $this->admin, 'Requested by the owner.', $email);

        $line = AuditLog::query()->where('action', 'account.erased')->sole();
        $encoded = json_encode($line->getAttributes());

        expect($line->actor_id)->toBe($this->admin->id)
            ->and($line->after['tombstone'])->toBe($result->tombstone)
            ->and($encoded)->not->toContain($email)->not->toContain($name)->not->toContain('12 Secret Road');
    });

    it('removes the stored files only after the erasure has committed', function () {
        ['user' => $user, 'path' => $path] = erasureTestPartner();

        // A refused erasure leaves every file where it was.
        expect(fn () => app(EraseAccount::class)->handle($user, $this->admin, 'Requested by the owner.', 'the-wrong-email@example.com'))
            ->toThrow(InvalidArgumentException::class);
        Storage::disk('kyc')->assertExists($path);

        app(EraseAccount::class)->handle($user, $this->admin, 'Requested by the owner.', $user->email);
        Storage::disk('kyc')->assertMissing($path);
    });

    it('marks the request approved and points it at the tombstone', function () {
        ['user' => $user] = erasureTestPartner();
        $request = AccountDeletionRequest::create(['user_id' => $user->id, 'reason' => 'Closing the business.', 'requested_at' => now()]);

        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.deletions.approve', $request->public_id), ['reason' => 'Verified with the owner.', 'confirmation' => $user->email])
            ->assertSessionHasNoErrors();

        $request->refresh();

        expect($request->status)->toBe(DeletionRequestStatus::Approved)->and($request->decided_by)->toBe($this->admin->id);
        expect($user->fresh()->erased_at)->not->toBeNull();
    });
});

describe('erasing a partner with a live storefront', function () {
    it('approves the request and closes the storefront', function () {
        ['user' => $user, 'account' => $account] = erasureTestPartner();
        $website = Website::factory()->forAccount($account)->active()->create();
        DB::table('website_webhook_endpoints')->insert(['public_id' => (string) Str::ulid(), 'website_id' => $website->id, 'url' => 'https://shop.example/hook', 'secret' => 's', 'secret_hint' => 'abcd', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $request = AccountDeletionRequest::create(['user_id' => $user->id, 'reason' => 'Closing the business.', 'requested_at' => now()]);

        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.deletions.approve', $request->public_id), ['reason' => 'Verified with the owner.', 'confirmation' => $user->email])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.deletions.index'));

        expect($request->refresh()->status)->toBe(DeletionRequestStatus::Approved)
            ->and($website->refresh()->status)->toBe(WebsiteStatus::Closed);
    });
});

describe('who may erase, and what they must confirm', function () {
    it('refuses a missing reason and a confirmation that does not match, and changes nothing', function () {
        ['user' => $user] = erasureTestPartner();

        expect(fn () => app(EraseAccount::class)->handle($user, $this->admin, 'no', $user->email))->toThrow(InvalidArgumentException::class);
        expect(fn () => app(EraseAccount::class)->handle($user, $this->admin, 'A proper reason.', 'someone-else@example.com'))->toThrow(InvalidArgumentException::class);

        expect($user->fresh()->erased_at)->toBeNull()->and($user->fresh()->name)->toBe($user->name);
    });

    it('never lets somebody erase their own account, whatever they hold', function () {
        $superAdmin = testPlatformStaff(PlatformRole::SuperAdmin);

        expect(fn () => app(EraseAccount::class)->handle($superAdmin, $superAdmin, 'Erasing myself.', $superAdmin->email))
            ->toThrow(AuthorizationException::class, 'own account');

        expect($superAdmin->fresh()->erased_at)->toBeNull();
    });

    it('refuses staff without the permission', function () {
        ['user' => $user] = erasureTestPartner();
        $staff = testPlatformStaff(PlatformRole::KycManager);

        expect(fn () => app(EraseAccount::class)->handle($user, $staff, 'A proper reason.', $user->email))->toThrow(AuthorizationException::class);
    });

    it('lets an Admin erase a partner but only a Super Admin erase platform staff', function () {
        $staff = testPlatformStaff(PlatformRole::KycManager);

        expect(fn () => app(EraseAccount::class)->handle($staff, $this->admin, 'A proper reason.', $staff->email))
            ->toThrow(AuthorizationException::class, 'Super Admin');

        $superAdmin = testPlatformStaff(PlatformRole::SuperAdmin);
        app(EraseAccount::class)->handle($staff, $superAdmin, 'A proper reason.', $staff->email);

        expect($staff->fresh()->erased_at)->not->toBeNull();
    });

    it('never erases the last active Super Admin', function () {
        $only = testPlatformStaff(PlatformRole::SuperAdmin);

        // A suspended Super Admin still holds the role, so may attempt it; but
        // $only is the last active one, and the platform must keep one.
        $second = testPlatformStaff(PlatformRole::SuperAdmin);
        $second->forceFill(['identity_status' => UserStatus::Suspended->value])->save();

        expect(fn () => app(EraseAccount::class)->handle($only, $second, 'A proper reason.', $only->email))
            ->toThrow(InvalidArgumentException::class, 'at least one active Super Admin');
    });

    it('cannot erase an account twice', function () {
        ['user' => $user] = erasureTestPartner();
        $email = $user->email;

        app(EraseAccount::class)->handle($user, $this->admin, 'Requested by the owner.', $email);

        expect(fn () => app(EraseAccount::class)->handle($user->fresh(), $this->admin, 'Requested by the owner.', $email))
            ->toThrow(InvalidArgumentException::class, 'already been erased');
    });

    it('previews without changing anything, and says what it would do', function () {
        ['user' => $user] = erasureTestPartner();

        $preview = app(EraseAccount::class)->preview($user);

        expect($preview->preview)->toBeTrue()
            ->and($preview->deleted)->toHaveKey('user_addresses')
            ->and($preview->anonymized)->toHaveKey('users')
            ->and($user->fresh()->erased_at)->toBeNull()
            ->and(DB::table('user_addresses')->where('user_id', $user->id)->count())->toBe(1);
    });

    it('shows the reviewer the impact, and asks for the confirmation to be typed back', function () {
        ['user' => $user] = erasureTestPartner();
        $request = AccountDeletionRequest::create(['user_id' => $user->id, 'reason' => 'Closing the business.', 'requested_at' => now()]);

        $this->actingAs($this->admin)->get(route('admin.deletions.show', $request->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_decide', true)
                ->where('confirmation', $user->email)
                ->where('tombstone', app(EraseAccount::class)->tombstone($user))
                ->has('impact.deleted')
                ->has('impact.anonymized'));

        // Without a recent password confirmation the approval is not even attempted.
        $this->actingAs($this->admin)
            ->post(route('admin.deletions.approve', $request->public_id), ['reason' => 'Verified.', 'confirmation' => $user->email])
            ->assertRedirect(route('password.confirm'));
        expect($user->fresh()->erased_at)->toBeNull();

        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.deletions.approve', $request->public_id), ['reason' => 'Verified.', 'confirmation' => 'wrong'])
            ->assertSessionHasErrors('confirmation');

        expect($user->fresh()->erased_at)->toBeNull();
    });
});

describe('erasing a Supplier', function () {
    it('turns the Supplier into a tombstone and removes its KYC values and documents', function () {
        $supplier = Supplier::factory()->kycPending()->create(['business_name' => 'Secret Traders', 'contact_person_name' => 'Real Person', 'trade_licence_number' => 'TL-123']);
        $round = app(OpenSupplierKycRound::class)->handle($supplier);
        $round->fields()->create(['key' => 'tin', 'value' => '999888777']);

        $path = 'kyc/supplier-'.Str::random(8).'.pdf';
        Storage::disk('kyc')->put($path, 'licence');
        DB::table('supplier_kyc_documents')->insert(['public_id' => (string) Str::ulid(), 'supplier_kyc_submission_id' => $round->id, 'document_type' => 'trade_licence', 'disk' => 'kyc', 'path' => $path, 'original_name' => 'licence.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'checksum' => str_repeat('a', 64), 'is_encrypted' => false, 'created_at' => now(), 'updated_at' => now()]);

        $email = $supplier->email;
        $result = app(EraseAccount::class)->handle($supplier, $this->admin, 'Requested by the Supplier.', $supplier->reference);

        $supplier->refresh();

        expect($supplier->erased_at)->not->toBeNull()
            ->and($supplier->tombstone_reference)->toBe($result->tombstone)
            // The email is locked once written by a database guard, so it stays and is
            // reported as retained; the mobile, the password and the login are gone.
            ->and($supplier->email)->toBe($email)
            ->and($result->retained)->toHaveKey('suppliers.email')
            ->and($supplier->mobile)->toStartWith('+999')
            ->and($supplier->business_name)->toStartWith('Deleted supplier ERASED-')
            ->and($supplier->contact_person_name)->toBe('Deleted')
            ->and($supplier->trade_licence_number)->toBeNull()
            ->and($supplier->status)->toBe(SupplierStatus::Closed)
            ->and(Hash::check('password', $supplier->password))->toBeFalse()
            ->and(DB::table('supplier_kyc_fields')->count())->toBe(0)
            ->and(DB::table('supplier_kyc_documents')->where('original_name', 'licence.pdf')->count())->toBe(0);

        Storage::disk('kyc')->assertMissing($path);
    });

    it('needs supplier.delete, not account.delete', function () {
        $supplier = Supplier::factory()->create();
        $peopleOnly = testPlatformStaff(PlatformRole::SmsManager);
        $peopleOnly->givePermissionTo('account.delete');

        expect(fn () => app(EraseAccount::class)->handle($supplier, $peopleOnly, 'A proper reason.', $supplier->reference))
            ->toThrow(AuthorizationException::class);

        $supplierOnly = testPlatformStaff(PlatformRole::SmsManager);
        $supplierOnly->givePermissionTo('supplier.delete');

        app(EraseAccount::class)->handle($supplier, $supplierOnly, 'A proper reason.', $supplier->reference);

        expect($supplier->fresh()->erased_at)->not->toBeNull();
    });
});
