<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Enums\Gender;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Models\User;
use App\Support\Localization\Countries;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Staff correcting a Client/Partner's details on their behalf: the business
 * name and the owner's name, email, mobile, date of birth, gender, country and
 * nationality.
 *
 * The same rules registration applies, with email and mobile unique against
 * everybody else. Contact verification is left as it stands: staff edit what
 * the owner has told them, and a channel staff already confirmed can only be
 * confirmed by staff once, so clearing it would strand the owner. The audit
 * entry keeps every changed field before and after, with the reason.
 *
 * Identifiers -- partner number, slug, public IDs, referral code -- and
 * anything with money, status or history attached are not edited here; each
 * has its own guarded action.
 */
class UpdatePartnerDetailsByStaff
{
    /** Owner fields this edits, as stored on `users`. */
    public const OWNER_FIELDS = ['name', 'email', 'mobile', 'date_of_birth', 'gender', 'country', 'nationality'];

    public function __construct(
        protected DatabaseManager $database,
        protected RecordAuditLog $audit,
        protected Countries $countries,
    ) {}

    /**
     * @param  array<string, mixed>  $input  business_name, name, email, mobile, reason; optionally date_of_birth, gender, country, nationality
     *
     * @throws ValidationException
     */
    public function handle(User $actor, BusinessAccount $account, array $input): void
    {
        /** @var User $owner */
        $owner = $account->owner()->firstOrFail();

        $data = validator($input, [
            'business_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($owner->id)],
            'mobile' => ['required', 'string', 'max:20', Rule::unique(User::class, 'mobile')->ignore($owner->id)],
            'date_of_birth' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'gender' => ['nullable', Rule::in(Gender::values())],
            'country' => ['required', Rule::in($this->countries->codes())],
            'nationality' => ['nullable', 'string', 'max:64'],
            'reason' => ['required', 'string', 'max:1000'],
        ])->validate();

        if (trim($data['reason']) === '') {
            throw new InvalidArgumentException('A reason is required and is recorded against this change.');
        }

        $this->database->transaction(function () use ($actor, $account, $owner, $data) {
            /** @var BusinessAccount $lockedAccount */
            $lockedAccount = BusinessAccount::query()->lockForUpdate()->findOrFail($account->id);
            /** @var User $lockedOwner */
            $lockedOwner = User::query()->lockForUpdate()->findOrFail($owner->id);

            $lockedAccount->name = $data['business_name'];

            foreach (self::OWNER_FIELDS as $field) {
                $lockedOwner->{$field} = $data[$field] ?? null;
            }

            $before = [];
            $after = [];

            if ($lockedAccount->isDirty('name')) {
                $before['business_name'] = $lockedAccount->getOriginal('name');
                $after['business_name'] = $lockedAccount->name;
            }

            foreach (self::OWNER_FIELDS as $field) {
                if ($lockedOwner->isDirty($field)) {
                    $before[$field] = $this->plain($lockedOwner->getOriginal($field));
                    $after[$field] = $this->plain($lockedOwner->{$field});
                }
            }

            if ($after === []) {
                return;
            }

            $lockedAccount->save();
            $lockedOwner->save();

            $this->audit->handle(new AuditEntry(
                action: 'account.details_updated_by_staff',
                actorId: $actor->id,
                auditableType: BusinessAccount::class,
                auditableId: $lockedAccount->id,
                before: $before,
                after: $after,
                reason: $data['reason'],
                accountId: $lockedAccount->id,
                module: 'account',
                isSensitive: true,
            ));
        });
    }

    /**
     * A stored value as the audit log should show it: dates as plain dates,
     * enums by value.
     */
    protected function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            $value instanceof \BackedEnum => $value->value,
            default => $value,
        };
    }
}
