<?php

namespace App\Domain\Website\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\LocalAddress;
use App\Domain\Website\Models\Website;
use App\Domain\Website\RequestedAddress;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A partner's request for a website, from the address they typed.
 *
 * What the web `WebsiteController::store` does between the request and
 * {@see RequestWebsite}: read the address as asked for — a local development
 * URL taken as it is, a plain label Feriwala hosts (which must be free and not
 * reserved), or anything else (their own domain, a URL) kept for staff to
 * arrange — and then raise the website and its charges. Refusals surface as
 * validation errors on the field they concern.
 *
 * @throws ValidationException
 */
class SubmitWebsiteRequest
{
    public function __construct(
        protected RequestWebsite $request,
    ) {}

    public function handle(BusinessAccount $account, User $person, string $name, string $address): Website
    {
        $local = LocalAddress::parse($address);
        $requestedAddress = null;

        if ($local !== null) {
            if (Website::query()->where('domain', $local['host'])->exists()) {
                throw ValidationException::withMessages(['subdomain' => WebsiteRefused::subdomainTaken()->getMessage()]);
            }

            $subdomain = LocalAddress::label($local['host']);
        } else {
            $normalised = RequestedAddress::normalize($address);

            if ($normalised === '') {
                throw ValidationException::withMessages(['subdomain' => __('validation.required', ['attribute' => 'address'])]);
            }

            if (RequestedAddress::isPlainLabel($normalised)) {
                // A plain label is the one Feriwala hosts: it must be free.
                validator(
                    ['subdomain' => $normalised],
                    ['subdomain' => [
                        Rule::notIn((array) config('website.reserved_subdomains', [])),
                        Rule::unique('websites', 'subdomain'),
                    ]],
                )->validate();

                $subdomain = $normalised;
            } else {
                $requestedAddress = $normalised;
                $subdomain = RequestedAddress::label($normalised);
            }
        }

        try {
            $website = $this->request->handle($account, $person, $name, $subdomain);
        } catch (WebsiteRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        if ($local !== null) {
            $website->forceFill(['domain' => $local['host']])->save();
        } elseif ($requestedAddress !== null) {
            $website->forceFill(['requested_address' => $requestedAddress])->save();
        }

        return $website;
    }
}
