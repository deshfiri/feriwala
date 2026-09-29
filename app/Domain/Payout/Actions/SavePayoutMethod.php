<?php

namespace App\Domain\Payout\Actions;

use App\Domain\Address\Actions\SaveSharedAddress;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Payout\Enums\PayoutMethodStatus;
use App\Domain\Payout\Enums\PayoutMethodType;
use App\Domain\Payout\Enums\PayoutOwnerType;
use App\Domain\Payout\Exceptions\PayoutMethodRefused;
use App\Domain\Payout\Models\PayoutMethod;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;

/**
 * Create or update a payout method for either owner kind (D25, P13-24;
 * generalized from the Supplier-only original of this class).
 *
 * Ownership is a parameter, never resolved from `auth()` in here — the
 * controller resolves the acting `BusinessAccount` or Supplier from its own
 * guard and passes the id down, the same convention
 * {@see SaveSharedAddress} already uses.
 *
 * `details` is encrypted at rest by the model's own cast; this action never
 * returns it, only `last_four`. `fingerprint` is a keyed HMAC of the account
 * number (never the number itself, and never reversible) — its only job is
 * refusing the same account registered twice for the same owner, via the
 * partial unique index the migration adds; nothing here or anywhere else
 * decrypts it back to a number.
 *
 * A method is never deleted, only archived: a withdrawal snapshots a
 * method's details onto itself at request time, so archiving or editing one
 * afterwards changes nothing about a withdrawal already made against it.
 */
class SavePayoutMethod
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
        protected SetDefaultPayoutMethod $setDefault,
    ) {}

    /**
     * @param  array<string, mixed>  $details  the sensitive fields {@see PayoutMethodType::detailFields()} names for this type
     */
    public function handle(
        PayoutOwnerType $ownerType,
        int $ownerId,
        PayoutMethodType $type,
        string $label,
        array $details,
        ?int $bdBankId = null,
        ?int $bdBankBranchId = null,
        bool $makeDefault = false,
        ?PayoutMethod $existing = null,
    ): PayoutMethod {
        $accountNumber = (string) ($details[$type->numberField()] ?? '');
        $lastFour = mb_substr($accountNumber, -4);
        $fingerprint = self::fingerprint($ownerType, $ownerId, $type, $accountNumber);

        $attributes = [
            'type' => $type,
            'label' => $label,
            'bd_bank_id' => $type->requiresBankBranch() ? $bdBankId : null,
            'bd_bank_branch_id' => $type->requiresBankBranch() ? $bdBankBranchId : null,
            'details' => $details,
            'last_four' => $lastFour,
            'fingerprint' => $fingerprint,
        ];

        $existingId = $existing?->id;

        $duplicate = PayoutMethod::query()
            ->where('owner_type', $ownerType->value)
            ->where('owner_id', $ownerId)
            ->where('fingerprint', $fingerprint)
            ->where('status', PayoutMethodStatus::Active)
            ->when($existingId !== null, fn ($query) => $query->where('id', '!=', $existingId))
            ->exists();

        if ($duplicate) {
            throw PayoutMethodRefused::duplicateAccount();
        }

        try {
            $method = $this->database->transaction(function () use ($ownerType, $ownerId, $existing, $attributes) {
                if ($existing !== null) {
                    /** @var PayoutMethod $method */
                    $method = PayoutMethod::query()
                        ->where('owner_type', $ownerType->value)
                        ->where('owner_id', $ownerId)
                        ->lockForUpdate()
                        ->findOrFail($existing->id);

                    $method->forceFill([
                        ...$attributes,
                        // A changed number is not the number that was verified.
                        'verified_at' => null,
                        'verified_by' => null,
                    ])->save();

                    $action = 'payout_method.updated';
                } else {
                    $method = PayoutMethod::create([
                        'owner_type' => $ownerType->value,
                        'owner_id' => $ownerId,
                        ...$attributes,
                        'is_default' => false,
                        'status' => PayoutMethodStatus::Active,
                    ]);

                    $action = 'payout_method.created';
                }

                $this->audit->handle(new AuditEntry(
                    action: $action,
                    auditableType: PayoutMethod::class,
                    auditableId: $method->id,
                    after: ['type' => $attributes['type']->value, 'last_four' => $attributes['last_four']],
                    accountId: $ownerId,
                    isSensitive: true,
                ));

                return $method->refresh();
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'payout_methods_fingerprint_unique_per_owner')) {
                throw PayoutMethodRefused::duplicateAccount();
            }

            throw $exception;
        }

        return $makeDefault ? $this->setDefault->handle($method) : $method;
    }

    /**
     * A keyed, non-reversible hash used only to refuse the same account
     * registered twice for one owner — never to recover the number. Scoped
     * to owner + type, so a Supplier and a BusinessAccount who happen to
     * share a bank account (a family business, a sole proprietor's personal
     * and business accounts) never collide with each other.
     */
    public static function fingerprint(PayoutOwnerType $ownerType, int $ownerId, PayoutMethodType $type, string $accountNumber): string
    {
        $normalized = preg_replace('/\s+/', '', $accountNumber) ?? '';

        return hash_hmac(
            'sha256',
            $ownerType->value.'|'.$ownerId.'|'.$type->value.'|'.$normalized,
            Crypt::getKey(),
        );
    }
}
