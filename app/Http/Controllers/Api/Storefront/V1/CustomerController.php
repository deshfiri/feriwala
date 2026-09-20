<?php

namespace App\Http\Controllers\Api\Storefront\V1;

use App\Domain\Website\Actions\RecordWebsiteCustomer;
use App\Domain\Website\Api\StorefrontError;
use App\Domain\Website\Data\WebsiteCustomerDetails;
use App\Domain\Website\Exceptions\WebsiteCustomerRefused;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCustomer;
use App\Support\Localization\MobileNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * A storefront's own customers (contract §6.2, P5-13).
 *
 * Guest checkout is the default path and never creates an ERP account. A
 * customer is keyed on their normalised mobile number **on this website**: the
 * same number on another partner's shop is another customer, and nothing here
 * reaches across.
 *
 * **Submitting a number that is already known grants nothing.** The answer is
 * the same either way — the customer's identifier and nothing more — and what
 * is already recorded is not overwritten by it. Changing a customer needs the
 * identifier the storefront was given, and there is deliberately no endpoint
 * that reads a customer back: order history behind a phone number is exactly
 * what the contract's OTP gate exists to stop.
 */
class CustomerController extends StorefrontController
{
    public function __construct(
        protected RecordWebsiteCustomer $customers,
        protected MobileNumber $mobiles,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $website = $this->website($request);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:254'],
            'storefront_customer_reference' => ['nullable', 'string', 'max:64'],
            'is_guest' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->invalid($request, $validator->errors()->toArray());
        }

        /** @var array<string, mixed> $input */
        $input = $validator->validated();
        $mobile = $this->mobiles->normalise((string) $input['phone']);

        if ($mobile === null) {
            return StorefrontError::respond($request, 422, 'invalid_mobile_number', 'That mobile number could not be resolved.');
        }

        $customer = $this->customers->findOrRecord($website, new WebsiteCustomerDetails(
            name: trim((string) $input['name']),
            mobile: $mobile,
            email: $input['email'] ?? null,
            reference: $input['storefront_customer_reference'] ?? null,
            isGuest: (bool) ($input['is_guest'] ?? true),
        ));

        return new JsonResponse(['id' => $customer->public_id], 201);
    }

    public function update(Request $request, string $customer): JsonResponse
    {
        $website = $this->website($request);
        $record = $this->find($website, $customer);

        if ($record === null) {
            return StorefrontError::respond($request, 404, 'not_found', 'No such customer on this website.');
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:160'],
            'phone' => ['sometimes', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'storefront_customer_reference' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_guest' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->invalid($request, $validator->errors()->toArray());
        }

        /** @var array<string, mixed> $input */
        $input = $validator->validated();
        $changes = [];

        if (isset($input['name'])) {
            $changes['name'] = trim((string) $input['name']);
        }

        if (isset($input['phone'])) {
            $mobile = $this->mobiles->normalise((string) $input['phone']);

            if ($mobile === null) {
                return StorefrontError::respond($request, 422, 'invalid_mobile_number', 'That mobile number could not be resolved.');
            }

            $changes['mobile'] = $mobile;
        }

        if (array_key_exists('email', $input)) {
            $changes['email'] = $input['email'];
        }

        if (array_key_exists('storefront_customer_reference', $input)) {
            $changes['reference'] = $input['storefront_customer_reference'];
        }

        if (isset($input['is_guest'])) {
            $changes['is_guest'] = (bool) $input['is_guest'];
        }

        try {
            $this->customers->update($record, $changes);
        } catch (WebsiteCustomerRefused $refused) {
            return StorefrontError::respond($request, $refused->status, $refused->errorCode, $refused->getMessage());
        }

        return new JsonResponse(['id' => $record->public_id]);
    }

    protected function find(Website $website, string $publicId): ?WebsiteCustomer
    {
        /** @var WebsiteCustomer|null $customer */
        $customer = WebsiteCustomer::query()
            ->where('website_id', $website->id)
            ->where('public_id', $publicId)
            ->first();

        return $customer;
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    protected function invalid(Request $request, array $errors): JsonResponse
    {
        return StorefrontError::respond($request, 422, 'validation_failed', 'The customer could not be read.', ['fields' => $errors]);
    }
}
