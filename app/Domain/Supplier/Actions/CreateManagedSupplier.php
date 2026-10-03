<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Account\Actions\ManagePasswordSetup;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Opens a Supplier account on someone's behalf (staff-created).
 *
 * Goes through {@see RegisterSupplier} -- the same validation, E.164 mobile
 * normalisation, uniqueness and status history as self-registration -- on the
 * Supplier guard's own table, never the Client/Partner one. The login secret is
 * random and never shown; the Supplier receives a password-setup link
 * ({@see ManagePasswordSetup}) against the `suppliers` broker. Email and
 * mobile start unverified.
 */
class CreateManagedSupplier
{
    public function __construct(
        protected RegisterSupplier $register,
        protected ManagePasswordSetup $passwordSetup,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input  business_name, contact_person_name, business_address, email, mobile, reason; optionally trade_licence_number, tax_identification_number
     *
     * @throws ValidationException
     */
    public function handle(User $actor, array $input): Supplier
    {
        validator($input, ['reason' => ['required', 'string', 'max:1000']])->validate();

        $secret = Str::password(40);

        $supplier = $this->register->handle([
            ...collect($input)->except('reason')->all(),
            'password' => $secret,
            'password_confirmation' => $secret,
        ]);

        $this->audit->handle(new AuditEntry(
            action: 'supplier.created_by_staff',
            actorId: $actor->id,
            auditableType: Supplier::class,
            auditableId: $supplier->id,
            after: ['business_name' => $supplier->business_name, 'status' => $supplier->status->value],
            reason: (string) $input['reason'],
            module: 'supplier',
            isSensitive: true,
        ));

        $this->passwordSetup->issue($supplier, ManagePasswordSetup::SUPPLIERS, $actor, 'Account opened by staff: '.$input['reason']);

        return $supplier;
    }
}
