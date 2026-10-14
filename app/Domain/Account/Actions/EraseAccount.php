<?php

namespace App\Domain\Account\Actions;

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Data\AccountStatusChange;
use App\Domain\Account\Data\ErasureResult;
use App\Domain\Account\DeletionAuthority;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\DeletionRequestStatus;
use App\Domain\Account\Enums\UserStatus;
use App\Domain\Account\Erasure\ErasureRun;
use App\Domain\Account\Models\AccountDeletionRequest;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Storage\ManagedStorage;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Website\Actions\MoveWebsiteStatus;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Enums\WebsiteStatusChangeSource;
use App\Domain\Website\Models\Website;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Erase an account: remove what is safe to remove, anonymize what must stay.
 *
 * Feriwala keeps its ledger, orders, payments, refunds, withdrawals, stock
 * movements and audit trail whole — they are what the books and the law rest on,
 * and the database enforces it with triggers that this never touches. So an
 * erased account is not a deleted row. The login stays as a *tombstone*: every
 * personal detail replaced, the sign-in closed for good, and a stable reference
 * (`ERASED-…`) in place of the name, so the records that point at it still read
 * and balance and nobody can tell who it was.
 *
 * What goes:
 * - sessions, passkeys, tokens, notifications, saved addresses, invitations,
 *   carts, SMS logs, API credentials and webhook secrets are deleted;
 * - KYC typed values and every uploaded personal file are deleted — files only
 *   after the transaction has committed, through the storage layer, R2 included;
 * - names, contact details, addresses, payout details and customer details in
 *   records that must stay (orders, withdrawals, websites, payout methods) are
 *   replaced.
 *
 * What does not change: amounts, identifiers, statuses and their history, and the
 * accounting links between them — and the audit logs, which the database forbids
 * altering. Each change is attempted on its own; where the database refuses one,
 * the table is reported as retained with the reason rather than forced.
 *
 * One audit line records who did it, which tombstone, which request and the
 * outcome, with no personal data in it.
 *
 * Nobody erases their own account, and a request is decided by someone with
 * authority over it ({@see DeletionAuthority}).
 */
class EraseAccount
{
    public const MINIMUM_REASON = 5;

    public function __construct(
        protected DeletionAuthority $authority,
        protected RecordAuditLog $audit,
        protected ManagedStorage $storage,
        protected ChangeAccountStatus $changeAccountStatus,
        protected MoveWebsiteStatus $moveWebsiteStatus,
    ) {}

    /**
     * What an erasure would do, changing nothing. Run inside a rolled-back
     * transaction purely so the counts are consistent.
     */
    public function preview(User|Supplier $subject): ErasureResult
    {
        $run = new ErasureRun($subject instanceof Supplier ? 'supplier' : 'user', $this->tombstone($subject), preview: true);

        $this->steps($subject, $run, actor: null);

        return $run->result();
    }

    /**
     * @throws AuthorizationException when the actor may not erase this account
     * @throws InvalidArgumentException when the reason or the typed confirmation is missing or wrong, or it is already erased
     */
    public function handle(
        User|Supplier $subject,
        User $actor,
        string $reason,
        string $confirmation,
        ?AccountDeletionRequest $request = null,
    ): ErasureResult {
        if (($refusal = $this->authority->refusal($actor, $subject)) !== null) {
            throw new AuthorizationException($refusal);
        }

        if ($subject->getAttribute('erased_at') !== null) {
            throw new InvalidArgumentException('This account has already been erased.');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < self::MINIMUM_REASON) {
            throw new InvalidArgumentException('Erasing an account needs a recorded reason.');
        }

        if (! hash_equals(mb_strtolower($this->confirmationFor($subject)), mb_strtolower(trim($confirmation)))) {
            throw new InvalidArgumentException('The confirmation does not match this account.');
        }

        $this->guardLastActiveSuperAdmin($subject);

        $result = DB::transaction(function () use ($subject, $actor, $reason, $request) {
            // Lock the row, so two people cannot erase the same account at once.
            $subject::query()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();

            $run = new ErasureRun($subject instanceof Supplier ? 'supplier' : 'user', $this->tombstone($subject), preview: false);

            $this->steps($subject, $run, $actor);

            $result = $run->result();

            if ($request !== null) {
                $request->forceFill([
                    'status' => DeletionRequestStatus::Approved,
                    'decided_by' => $actor->id,
                    'decided_at' => now(),
                ])->save();
            }

            $this->audit->handle(new AuditEntry(
                action: 'account.erased',
                actorId: $actor->id,
                auditableType: $subject::class,
                auditableId: $subject->getKey(),
                // Counts and table names, the tombstone and the request id: no
                // name, no email, no number.
                after: [...$result->summary(), 'request' => $request?->public_id],
                reason: $reason,
                module: 'account',
                isSensitive: true,
            ));

            return $result;
        });

        // Only now: the rows that named the files are gone and committed. A file
        // that cannot be removed is reported, never allowed to undo an erasure.
        foreach ($result->files as $file) {
            try {
                $this->storage->resolveNamedDisk($file['disk'])->delete($file['path']);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $result;
    }

    /**
     * What the person confirming types: an email for a person, the reference for
     * a Supplier — something the screen shows and a reviewer can read back.
     */
    public function confirmationFor(User|Supplier $subject): string
    {
        return $subject instanceof Supplier ? (string) $subject->reference : (string) $subject->email;
    }

    public function tombstone(User|Supplier|BusinessAccount $subject): string
    {
        return 'ERASED-'.strtoupper(substr((string) $subject->public_id, -8));
    }

    protected function steps(User|Supplier $subject, ErasureRun $run, ?User $actor): void
    {
        $subject instanceof Supplier
            ? $this->supplierSteps($subject, $run, $actor)
            : $this->userSteps($subject, $run, $actor);
    }

    protected function userSteps(User $user, ErasureRun $run, ?User $actor): void
    {
        $id = $user->id;
        $email = $user->email;
        $morph = $user->getMorphClass();
        $accountIds = BusinessAccount::withTrashed()->where('owner_id', $id)->pluck('id')->all();
        $tombstone = $this->tombstone($user);
        $in = fn (Builder $q, string $column, array $values) => $q->whereIn($column, $values ?: [0]);

        // 1. Files first: once the rows are deleted nothing says where they were.
        $run->collectFiles('kyc_documents', fn (Builder $q) => $q->whereIn('kyc_submission_id', DB::table('kyc_submissions')->select('id')->whereIn('business_account_id', $accountIds ?: [0])));
        $run->collectFiles('stored_files', fn (Builder $q) => $q->where(fn ($w) => $w->where('fileable_type', $morph)->where('fileable_id', $id)
            ->orWhere(fn ($a) => $a->where('fileable_type', (new BusinessAccount)->getMorphClass())->whereIn('fileable_id', $accountIds ?: [0]))));

        // 2. Personal and operational data that can simply go.
        $run->purge('authenticated_sessions', fn (Builder $q) => $q->where('user_id', $id));
        $run->purge('passkeys', fn (Builder $q) => $q->where('user_id', $id));
        $run->purge('sessions', fn (Builder $q) => $q->where('user_id', $id));
        $run->purge('notifications', fn (Builder $q) => $q->where('notifiable_type', $morph)->where('notifiable_id', $id));
        $run->purge('password_reset_tokens', fn (Builder $q) => $q->where('email', $email));
        $run->purge('staff_contact_verifications', fn (Builder $q) => $q->where('identity_type', 'user')->where('identity_id', $id));
        $run->purge('user_addresses', fn (Builder $q) => $q->where('user_id', $id), ['label', 'contact_name', 'contact_mobile', 'line_1', 'line_2', 'area', 'city', 'district', 'postcode']);
        $run->purge('account_invitations', fn (Builder $q) => $q->where('email', $email), ['email', 'mobile', 'token']);
        $run->purge('model_has_roles', fn (Builder $q) => $q->where('model_type', $morph)->where('model_id', $id));
        $run->purge('model_has_permissions', fn (Builder $q) => $q->where('model_type', $morph)->where('model_id', $id));
        $run->purge('business_account_members', fn (Builder $q) => $q->where(fn ($w) => $w->where('user_id', $id)->orWhereIn('business_account_id', $accountIds ?: [0])));
        $run->purge('carts', fn (Builder $q) => $q->where(fn ($w) => $w->where('user_id', $id)->orWhereIn('business_account_id', $accountIds ?: [0])));
        $run->purge('product_user_eligibility', fn (Builder $q) => $in($q, 'business_account_id', $accountIds));
        $run->purge('sms_messages', fn (Builder $q) => $q->where(fn ($w) => $w->where('user_id', $id)->orWhereIn('business_account_id', $accountIds ?: [0])), ['recipient', 'body']);

        // 3. KYC: the typed values and the uploaded documents.
        $submissions = fn () => DB::table('kyc_submissions')->select('id')->whereIn('business_account_id', $accountIds ?: [0]);
        $run->purge('kyc_submission_fields', fn (Builder $q) => $q->whereIn('kyc_submission_id', $submissions()));
        $run->purge('kyc_documents', fn (Builder $q) => $q->whereIn('kyc_submission_id', $submissions()), ['original_name', 'path', 'checksum']);
        $run->purge('stored_files', fn (Builder $q) => $q->where(fn ($w) => $w->where('fileable_type', $morph)->where('fileable_id', $id)
            ->orWhere(fn ($a) => $a->where('fileable_type', (new BusinessAccount)->getMorphClass())->whereIn('fileable_id', $accountIds ?: [0]))), ['path']);

        if ($accountIds !== []) {
            $this->accountSteps($accountIds, $run);
        }

        // 4. The login itself becomes the tombstone.
        $run->update('users', fn (Builder $q) => $q->where('id', $id), [
            'name' => 'Deleted account',
            'email' => strtolower($tombstone).'@deleted.invalid',
            'email_verified_at' => null,
            'password' => $run->isPreview() ? '' : Hash::make(Str::random(64)),
            'remember_token' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'mobile' => null,
            'mobile_verified_at' => null,
            'date_of_birth' => null,
            'gender' => null,
            'nationality' => null,
            'referral_code' => null,
            'identity_status' => UserStatus::Closed->value,
            'identity_status_changed_at' => now(),
            'erased_at' => now(),
            'tombstone_reference' => $tombstone,
        ], essential: true);

        if (! $run->isPreview()) {
            $this->closeAccounts($accountIds, $actor, $run);
        }
    }

    /**
     * What belongs to the business account the person owned.
     *
     * @param  array<int, int>  $accountIds
     */
    protected function accountSteps(array $accountIds, ErasureRun $run): void
    {
        $orders = fn () => DB::table('orders')->select('id')->whereIn('business_account_id', $accountIds);
        $websites = fn () => DB::table('websites')->select('id')->whereIn('business_account_id', $accountIds);

        // Details of the account's customers, inside orders that must stay.
        $run->blank('orders', fn (Builder $q) => $q->whereIn('business_account_id', $accountIds), ['customer', 'billing_address', 'shipping_address', 'customer_note']);
        $run->blank('order_returns', fn (Builder $q) => $q->whereIn('business_account_id', $accountIds), ['customer_note']);
        $run->collectFiles('shipments', fn (Builder $q) => $q->whereIn('order_id', $orders()), 'label_path', null, 'local');
        $run->blank('shipments', fn (Builder $q) => $q->whereIn('order_id', $orders()), ['label_path']);
        $run->blank('payment_logs', fn (Builder $q) => $q->whereIn('business_account_id', $accountIds), ['ip_address']);
        $run->blank('account_withdrawals', fn (Builder $q) => $q->whereIn('business_account_id', $accountIds), ['payout_snapshot']);

        // Where money was to be sent, and where goods were to be sent.
        $run->purge('payout_methods', fn (Builder $q) => $q->where('owner_type', 'business_account')->whereIn('owner_id', $accountIds), ['label', 'details', 'last_four', 'fingerprint']);
        $run->purge('shared_addresses', fn (Builder $q) => $q->where('owner_type', 'business_account')->whereIn('owner_id', $accountIds), ['contact_name', 'contact_mobile', 'detailed_address', 'landmark', 'postcode', 'location_snapshot']);

        // The business's websites and the people who bought on them.
        $run->purge('website_credentials', fn (Builder $q) => $q->whereIn('website_id', $websites()), ['name', 'secret', 'secret_hint', 'previous_secret']);
        $run->purge('website_webhook_endpoints', fn (Builder $q) => $q->whereIn('website_id', $websites()), ['url', 'secret', 'secret_hint', 'previous_secret']);
        $run->blank('webhook_deliveries', fn (Builder $q) => $q->whereIn('website_id', $websites()), ['payload']);
        $run->blank('website_customers', fn (Builder $q) => $q->whereIn('website_id', $websites()), ['name', 'mobile', 'email', 'storefront_customer_reference']);
        $run->collectFiles('websites', fn (Builder $q) => $q->whereIn('business_account_id', $accountIds), 'logo_path', null, 'public');
        $run->collectFiles('websites', fn (Builder $q) => $q->whereIn('business_account_id', $accountIds), 'banner_path', null, 'public');
        $run->blank('websites', fn (Builder $q) => $q->whereIn('business_account_id', $accountIds), ['tagline', 'about', 'logo_path', 'banner_path', 'contact_email', 'contact_phone', 'contact_address', 'domain']);

        // What each website was called, and the address it was served from.
        $run->update('websites', fn (Builder $q) => $q->whereIn('business_account_id', $accountIds), [
            'name' => 'Deleted website',
            'slug' => DB::raw("'erased-' || id"),
            'subdomain' => DB::raw("'erased-' || id"),
        ]);
        $run->note('Order, payment, wallet, ledger, refund, withdrawal and stock records were kept; only the personal details inside them were replaced.');

        // The business account becomes a tombstone too.
        foreach ($accountIds as $accountId) {
            $public = (string) DB::table('business_accounts')->where('id', $accountId)->value('public_id');
            $tomb = 'ERASED-'.strtoupper(substr($public, -8));

            $run->update('business_accounts', fn (Builder $q) => $q->where('id', $accountId), [
                'name' => 'Deleted account '.$tomb,
                'slug' => strtolower($tomb),
                'erased_at' => now(),
                'tombstone_reference' => $tomb,
            ], essential: true);
        }
    }

    protected function supplierSteps(Supplier $supplier, ErasureRun $run, ?User $actor): void
    {
        $id = $supplier->id;
        $email = $supplier->email;
        $morph = $supplier->getMorphClass();
        $tombstone = $this->tombstone($supplier);
        $submissions = fn () => DB::table('supplier_kyc_submissions')->select('id')->where('supplier_id', $id);
        $listings = fn () => DB::table('supplier_product_listings')->select('id')->where('supplier_id', $id);

        $run->collectFiles('supplier_kyc_documents', fn (Builder $q) => $q->whereIn('supplier_kyc_submission_id', $submissions()));
        $run->collectFiles('supplier_listing_media', fn (Builder $q) => $q->whereIn('supplier_product_listing_id', $listings()));
        $run->collectFiles('stored_files', fn (Builder $q) => $q->where('fileable_type', $morph)->where('fileable_id', $id));

        $run->purge('supplier_password_reset_tokens', fn (Builder $q) => $q->where('email', $email));
        $run->purge('notifications', fn (Builder $q) => $q->where('notifiable_type', $morph)->where('notifiable_id', $id));
        $run->purge('supplier_kyc_fields', fn (Builder $q) => $q->whereIn('supplier_kyc_submission_id', $submissions()));
        $run->purge('supplier_kyc_documents', fn (Builder $q) => $q->whereIn('supplier_kyc_submission_id', $submissions()), ['original_name', 'path', 'checksum']);
        $run->purge('supplier_listing_media', fn (Builder $q) => $q->whereIn('supplier_product_listing_id', $listings()), ['path', 'alt_text']);
        $run->purge('stored_files', fn (Builder $q) => $q->where('fileable_type', $morph)->where('fileable_id', $id), ['path']);

        $run->purge('payout_methods', fn (Builder $q) => $q->where('owner_type', 'supplier')->where('owner_id', $id), ['label', 'details', 'last_four', 'fingerprint']);
        $run->purge('shared_addresses', fn (Builder $q) => $q->where('owner_type', 'supplier')->where('owner_id', $id), ['contact_name', 'contact_mobile', 'detailed_address', 'landmark', 'postcode', 'location_snapshot']);
        $run->blank('supplier_withdrawals', fn (Builder $q) => $q->where('supplier_id', $id), ['payout_snapshot']);
        $run->note('Wallet, ledger, payable, withdrawal and order-allocation records were kept; only the personal details inside them were replaced.');

        // `suppliers.email` is locked once written by a database guard, and erasing
        // does not get past it: the address stays, the login is closed and the
        // password is unusable. It is reported as retained rather than forced.
        $run->retain('suppliers.email', 'locked once written by a database guard; the supplier is closed and cannot sign in');

        $run->update('suppliers', fn (Builder $q) => $q->where('id', $id), [
            'email_verified_at' => null,
            // Required, unique and E.164: +999 is an unassigned country code, so this
            // can never be a real number, and the id keeps it unique.
            'mobile' => '+999'.str_pad((string) $id, 10, '0', STR_PAD_LEFT),
            'mobile_verified_at' => null,
            'password' => $run->isPreview() ? '' : Hash::make(Str::random(64)),
            'remember_token' => null,
            'business_name' => 'Deleted supplier '.$tombstone,
            'contact_person_name' => 'Deleted',
            'business_address' => 'Deleted',
            'trade_licence_number' => null,
            'tax_identification_number' => null,
            'payout_details' => null,
            'review_note' => null,
            'erased_at' => now(),
            'tombstone_reference' => $tombstone,
        ], essential: true);

        if (! $run->isPreview()) {
            $this->closeSupplier($supplier, $actor, $run);
        }
    }

    /**
     * Close the business accounts, and their websites, through their own status
     * machines, so the history records it and nothing keeps trading.
     *
     * @param  array<int, int>  $accountIds
     */
    protected function closeAccounts(array $accountIds, ?User $actor, ErasureRun $run): void
    {
        foreach (BusinessAccount::withTrashed()->whereIn('id', $accountIds)->get() as $account) {
            foreach (Website::query()->where('business_account_id', $account->id)->get() as $website) {
                if ($website->status !== WebsiteStatus::Closed && $website->canTransitionTo(WebsiteStatus::Closed)) {
                    $this->moveWebsiteStatus->handle($website, WebsiteStatus::Closed, WebsiteStatusChangeSource::Staff, new StatusChange(actorId: $actor?->id, reason: 'Account erased.'));
                }
            }

            if ($account->status !== AccountStatus::Closed && $account->canTransitionTo(AccountStatus::Closed)) {
                $this->changeAccountStatus->handle($account, new AccountStatusChange(to: AccountStatus::Closed, changedBy: $actor?->id, reason: 'Account erased.'));
            } elseif ($account->status !== AccountStatus::Closed) {
                $run->note('The account could not be moved to Closed from its current status; its sign-in is closed regardless.');
            }
        }
    }

    protected function closeSupplier(Supplier $supplier, ?User $actor, ErasureRun $run): void
    {
        $fresh = Supplier::query()->findOrFail($supplier->id);

        if ($fresh->status === SupplierStatus::Closed) {
            return;
        }

        if (! $fresh->canTransitionTo(SupplierStatus::Closed)) {
            $run->note('The Supplier could not be moved to Closed from its current status; its sign-in is closed regardless.');

            return;
        }

        $fresh->transitionWithHistory(
            SupplierStatus::Closed,
            new StatusChange(actorId: $actor?->id, reason: 'Account erased.'),
            ['source' => SupplierStatusChangeSource::Staff],
        );
    }

    protected function guardLastActiveSuperAdmin(User|Supplier $subject): void
    {
        if (! $subject instanceof User || ! $subject->hasRole(PlatformRole::SuperAdmin->value)) {
            return;
        }

        $another = User::role(PlatformRole::SuperAdmin->value)
            ->where('id', '!=', $subject->id)
            ->where('identity_status', UserStatus::Active->value)
            ->exists();

        if (! $another) {
            throw new InvalidArgumentException('The platform must always have at least one active Super Admin.');
        }
    }
}
