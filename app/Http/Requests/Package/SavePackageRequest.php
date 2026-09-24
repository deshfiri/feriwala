<?php

namespace App\Http\Requests\Package;

use App\Domain\Package\Actions\ManagePackages;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Models\Package;
use App\Support\Money\Currency;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Money;
use App\Support\Money\Rules\DecimalAmountRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SavePackageRequest extends FormRequest
{
    /** Charges §8.1 and §16.2 allow a package to carry. */
    public const CHARGE_TYPES = ['website_setup', 'website_maintenance', 'domain', 'hosting'];

    /** How often a charge recurs. */
    public const FREQUENCIES = ['once', 'monthly', 'quarterly', 'yearly'];

    /**
     * Authorisation is the controller's, through the policy — one lookup, one
     * place.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $package = $this->route('package');

        return [
            'name' => ['required', 'string', 'max:120'],

            // The public identifier (§34.2). Unique because a duplicate would
            // make two packages indistinguishable in a URL and in every KYC
            // scope rule that names one.
            'slug' => [
                'required', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/',
                Rule::unique(Package::class, 'slug')
                    ->ignore($package instanceof Package ? $package->id : null),
            ],

            'short_description' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],

            // Entered in Taka by the administrator; parsed into exact Money in
            // packageAttributes() below, at this HTTP boundary (D26, §36.1).
            'fee' => ['required', new DecimalAmountRule],
            'registration_fee' => ['nullable', new DecimalAmountRule],
            'renewal_fee' => ['nullable', new DecimalAmountRule],
            'required_deposit' => ['nullable', new DecimalAmountRule],
            'minimum_balance' => ['nullable', new DecimalAmountRule],
            'currency_code' => ['required', 'string', 'size:3'],

            'validity_days' => ['nullable', 'integer', 'min:1'],
            'renewal_frequency' => ['nullable', Rule::in(self::FREQUENCIES)],
            'grace_period_days' => ['nullable', 'integer', 'min:0'],

            'available_from' => ['nullable', 'date'],
            'available_until' => ['nullable', 'date', 'after:available_from'],

            'is_active' => ['required', 'boolean'],
            'is_public' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],

            // Entitlements, keyed by the enum so a typo cannot invent one.
            'features' => ['sometimes', 'array'],
            'features.*' => ['nullable'],

            'charges' => ['sometimes', 'array'],
            'charges.*.charge_type' => ['required_with:charges.*.amount', Rule::in(self::CHARGE_TYPES)],
            'charges.*.amount' => ['required_with:charges.*.charge_type', new DecimalAmountRule],
            'charges.*.frequency' => ['nullable', Rule::in(self::FREQUENCIES)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $data = $validator->getData();

            foreach (array_keys($data['features'] ?? []) as $key) {
                if (PackageFeature::tryFrom((string) $key) === null) {
                    // A feature nobody can read is one that silently grants
                    // nothing, which is worse than a rejected form.
                    $validator->errors()->add(
                        "features.{$key}",
                        __('“:key” is not a package feature.', ['key' => $key]),
                    );
                }
            }

            /*
             * A renewal fee with no frequency renews on no schedule, and a
             * frequency with no fee renews for free. Either is a package that
             * behaves differently from how it reads. Skipped when the amount
             * itself already failed DecimalAmountRule above — nothing to check
             * a malformed string against.
             */
            $frequency = $data['renewal_frequency'] ?? null;
            $renewalFee = $validator->errors()->has('renewal_fee')
                ? null
                : DecimalAmount::parseOrNull($data['renewal_fee'] ?? null);

            if ($renewalFee !== null && $renewalFee->isPositive() && blank($frequency)) {
                $validator->errors()->add(
                    'renewal_frequency',
                    __('A renewal fee needs a renewal frequency.'),
                );
            }
        });
    }

    /**
     * The package's own columns.
     *
     * Not called `attributes()`: FormRequest already defines that for
     * validation display names, and overriding it would break every error
     * message this form produces.
     *
     * The Taka strings the administrator typed are converted to {@see Money}
     * here, at the HTTP boundary — {@see ManagePackages} receives exactly
     * what the columns expect: a {@see Money} instance MoneyCast accepts
     * directly (D26).
     *
     * @return array<string, mixed>
     */
    public function packageAttributes(): array
    {
        $currency = Currency::tryFrom((string) $this->string('currency_code')) ?? Currency::base();
        $attributes = $this->safe()->except(['features', 'charges', 'fee', 'registration_fee', 'renewal_fee', 'required_deposit', 'minimum_balance']);

        return [
            ...$attributes,
            'fee' => DecimalAmount::parse($this->validated('fee'), $currency),
            'registration_fee' => DecimalAmount::parseOrNull($this->validated('registration_fee'), $currency),
            'renewal_fee' => DecimalAmount::parseOrNull($this->validated('renewal_fee'), $currency),
            'required_deposit' => DecimalAmount::parseOrNull($this->validated('required_deposit'), $currency),
            'minimum_balance' => DecimalAmount::parseOrNull($this->validated('minimum_balance'), $currency),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function featureValues(): array
    {
        /** @var array<string, mixed> $features */
        $features = $this->validated('features') ?? [];

        return $features;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function chargeRows(): array
    {
        /** @var array<int, array<string, mixed>> $charges */
        $charges = $this->validated('charges') ?? [];
        $currency = Currency::tryFrom((string) $this->string('currency_code')) ?? Currency::base();

        return array_map(function (array $charge) use ($currency) {
            $amount = DecimalAmount::parseOrNull($charge['amount'] ?? null, $currency);

            return [
                ...$charge,
                'amount' => $amount ?? Money::zero($currency),
            ];
        }, $charges);
    }
}
