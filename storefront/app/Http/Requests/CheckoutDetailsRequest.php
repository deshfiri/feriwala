<?php

namespace App\Http\Requests;

use App\Services\Site;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The details a customer typed at checkout.
 *
 * Input is trimmed and cut to the column's length rather than refused for being
 * long; what fails is wording a customer can fix. Every message is the shop's own
 * sentence, already translated, so a view prints `$errors->first('phone')` as is.
 */
class CheckoutDetailsRequest extends FormRequest
{
    protected $redirectRoute = 'checkout.form';

    /** @var list<string> what the shop takes */
    private array $methods = [];

    /** @var list<string> names of the gateways behind "online"; empty when the ERP names none */
    private array $gateways = [];

    /** Field => longest accepted value. */
    private const LENGTHS = [
        'name' => 160, 'phone' => 32, 'email' => 254, 'line1' => 255, 'line2' => 255, 'city' => 120,
        'district' => 120, 'postcode' => 20, 'area_id' => 80, 'note' => 1000, 'payment_method' => 20, 'gateway' => 40,
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // The options are read fresh: a gateway may have been switched on or off since the form was drawn.
        $site = app(Site::class)->get(fresh: true);

        $this->methods = array_values((array) $site['payment_methods']);
        $this->gateways = array_column((array) $site['gateways'], 'name');

        $clean = [];

        foreach (self::LENGTHS as $field => $max) {
            $value = $this->input($field);
            $clean[$field] = mb_substr(trim(is_scalar($value) ? (string) $value : ''), 0, $max);
        }

        // Only an online payment goes through a gateway; the one chosen must be
        // one the ERP offered, and a lone gateway needs no choosing.
        if ($clean['payment_method'] !== 'online' || $this->gateways === []) {
            $clean['gateway'] = '';
        } elseif ($clean['gateway'] === '' && count($this->gateways) === 1) {
            $clean['gateway'] = $this->gateways[0];
        }

        $this->merge($clean);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', $this->check(fn (string $v): bool => mb_strlen($v) >= 2, 'checkout.error.name')],
            'phone' => ['required', $this->check(function (string $v): bool {
                $digits = preg_replace('/\D/', '', $v) ?? '';

                return preg_match('/^[0-9+\-\s()]+$/', $v) === 1 && strlen($digits) >= 10 && strlen($digits) <= 15;
            }, 'checkout.error.phone')],
            'email' => ['nullable', $this->check(fn (string $v): bool => filter_var($v, FILTER_VALIDATE_EMAIL) !== false, 'checkout.error.email')],
            'line1' => ['required'],
            'line2' => ['nullable'],
            'city' => ['required'],
            'district' => ['nullable'],
            'postcode' => ['nullable'],
            'area_id' => ['nullable'],
            'note' => ['nullable'],
            'gateway' => ['nullable'],
            'payment_method' => ['required', function (string $attribute, mixed $value, Closure $fail): void {
                if (! in_array($value, $this->methods, true)) {
                    $fail(t('checkout.error.payment_method'));
                } elseif ($value === 'online' && $this->gateways !== [] && ! in_array($this->input('gateway'), $this->gateways, true)) {
                    $fail(t('checkout.error.gateway'));
                }
            }],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => t('checkout.error.name'),
            'phone.required' => t('checkout.error.phone'),
            'line1.required' => t('checkout.error.address'),
            'city.required' => t('checkout.error.city'),
            'payment_method.required' => t('checkout.error.payment_method'),
        ];
    }

    /**
     * The validated details, every field present as a string.
     *
     * @return array<string, string>
     */
    public function details(): array
    {
        $validated = $this->validated();
        $details = [];

        foreach (array_keys(self::LENGTHS) as $field) {
            $details[$field] = (string) ($validated[$field] ?? '');
        }

        return $details;
    }

    /**
     * @param  callable(string): bool  $passes
     */
    private function check(callable $passes, string $messageKey): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($passes, $messageKey): void {
            if (! $passes((string) $value)) {
                $fail(t($messageKey));
            }
        };
    }
}
