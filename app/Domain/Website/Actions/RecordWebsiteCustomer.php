<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Data\WebsiteCustomerDetails;
use App\Domain\Website\Exceptions\WebsiteCustomerRefused;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCustomer;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A website's customer, found or recorded by their mobile number on that website
 * (contract §6.2, P5-13, P5-23).
 *
 * **Knowing a number grants nothing.** A storefront submitting a number that is
 * already a customer gets the same answer as for a new one, and what it sends
 * never overwrites the name or email already held — otherwise anyone who knew a
 * customer's number could rewrite who they are. Those change only through an
 * explicit update of the customer the storefront already holds the identifier of.
 *
 * Concurrent first orders from one number meet at the unique index; the loser
 * reads the winner's row.
 */
class RecordWebsiteCustomer
{
    public function __construct(
        protected DatabaseManager $database,
    ) {}

    /**
     * The customer with this number on this website, recorded if new.
     */
    public function findOrRecord(Website $website, WebsiteCustomerDetails $details): WebsiteCustomer
    {
        $existing = $this->byMobile($website, $details->mobile);

        if ($existing !== null) {
            return $this->adopt($existing, $details);
        }

        $reference = $details->reference !== null && $this->referenceIsFree($website, $details->reference, null)
            ? $details->reference
            : null;

        try {
            return $this->database->transaction(fn () => WebsiteCustomer::create([
                'website_id' => $website->id,
                'mobile' => $details->mobile,
                'name' => $details->name,
                'email' => $details->email,
                'storefront_customer_reference' => $reference,
                'is_guest' => $details->isGuest,
            ]));
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->byMobile($website, $details->mobile);

            if ($existing === null) {
                throw $exception;
            }

            return $this->adopt($existing, $details);
        }
    }

    /**
     * An explicit change to a customer the storefront holds the identifier of.
     *
     * @param  array{name?: string, mobile?: string, email?: string|null, reference?: string|null, is_guest?: bool}  $changes
     *
     * @throws WebsiteCustomerRefused
     */
    public function update(WebsiteCustomer $customer, array $changes): WebsiteCustomer
    {
        $website = $customer->website;

        if (isset($changes['mobile']) && $changes['mobile'] !== $customer->mobile
            && $this->byMobile($website, $changes['mobile']) !== null) {
            throw WebsiteCustomerRefused::mobileTaken();
        }

        if (array_key_exists('reference', $changes) && $changes['reference'] !== null
            && ! $this->referenceIsFree($website, $changes['reference'], $customer->id)) {
            throw WebsiteCustomerRefused::referenceTaken();
        }

        $attributes = array_filter([
            'name' => $changes['name'] ?? null,
            'mobile' => $changes['mobile'] ?? null,
            'is_guest' => $changes['is_guest'] ?? null,
        ], fn (mixed $value) => $value !== null);

        if (array_key_exists('email', $changes)) {
            $attributes['email'] = $changes['email'];
        }

        if (array_key_exists('reference', $changes)) {
            $attributes['storefront_customer_reference'] = $changes['reference'];
        }

        try {
            $this->database->transaction(fn () => $customer->forceFill($attributes)->save());
        } catch (UniqueConstraintViolationException) {
            throw isset($changes['mobile']) ? WebsiteCustomerRefused::mobileTaken() : WebsiteCustomerRefused::referenceTaken();
        }

        return $customer;
    }

    /**
     * Note an order against the customer.
     */
    public function noteOrder(WebsiteCustomer $customer, CarbonImmutable $at): void
    {
        WebsiteCustomer::query()->whereKey($customer->id)->toBase()->update(['last_order_at' => $at]);
    }

    protected function byMobile(Website $website, string $mobile): ?WebsiteCustomer
    {
        /** @var WebsiteCustomer|null $customer */
        $customer = WebsiteCustomer::query()
            ->where('website_id', $website->id)
            ->where('mobile', $mobile)
            ->first();

        return $customer;
    }

    /**
     * What an unauthenticated submission may add to a customer it does not
     * describe: a storefront reference where none was held, and that the customer
     * now has an account there. Never their name or email.
     */
    protected function adopt(WebsiteCustomer $customer, WebsiteCustomerDetails $details): WebsiteCustomer
    {
        $changes = [];

        if ($customer->storefront_customer_reference === null && $details->reference !== null
            && $this->referenceIsFree($customer->website, $details->reference, $customer->id)) {
            $changes['storefront_customer_reference'] = $details->reference;
        }

        if ($customer->is_guest && ! $details->isGuest) {
            $changes['is_guest'] = false;
        }

        if ($changes !== []) {
            try {
                $this->database->transaction(fn () => $customer->forceFill($changes)->save());
            } catch (UniqueConstraintViolationException) {
                $customer->refresh();
            }
        }

        return $customer;
    }

    protected function referenceIsFree(Website $website, string $reference, ?int $except): bool
    {
        return ! WebsiteCustomer::query()
            ->where('website_id', $website->id)
            ->where('storefront_customer_reference', $reference)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->exists();
    }
}
