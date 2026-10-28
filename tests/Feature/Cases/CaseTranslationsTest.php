<?php

use App\Domain\Cases\Enums\EvidencePurpose;
use App\Domain\Damage\Enums\DamageCause;
use App\Domain\Damage\Enums\DamageCondition;
use App\Domain\Damage\Enums\DamageDispatchStatus;
use App\Domain\Damage\Enums\DamageSource;
use App\Domain\Damage\Enums\DamageStatus;
use App\Domain\Damage\Enums\DamageValuationBasis;
use App\Domain\Order\Enums\ReturnCaseType;
use App\Domain\Order\Enums\ReturnChargeKind;
use App\Domain\Order\Enums\ReturnChargeStatus;
use App\Domain\Order\Enums\ReturnDisposition;
use App\Domain\Order\Enums\ReturnInitiator;
use App\Domain\Order\Enums\ReturnParty;
use App\Domain\Order\Enums\ReturnReason;
use App\Domain\Order\Enums\ReturnRefundState;
use App\Domain\Order\Enums\ReturnStatus;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;

/*
 * Bangla mode shows no raw enum value and no English internal label for a return,
 * exchange or damage (D35): every enum a person reads has its own Bangla line, and
 * every message the backend can give has one too.
 */

/** @return list<class-string<BackedEnum>> */
function caseTranslationEnums(): array
{
    return [
        ReturnStatus::class, ReturnReason::class, ReturnParty::class, ReturnDisposition::class, ReturnChargeKind::class,
        ReturnChargeStatus::class, ReturnRefundState::class, ReturnCaseType::class, ReturnInitiator::class,
        DamageStatus::class, DamageCause::class, DamageCondition::class, DamageSource::class,
        DamageDispatchStatus::class, DamageValuationBasis::class, EvidencePurpose::class,
    ];
}

it('has a Bangla line for every case of every enum a person reads, and none is English', function () {
    $missing = [];

    foreach (caseTranslationEnums() as $enum) {
        $group = (new ReflectionMethod($enum, 'statusLabelGroup'))->invoke(null);

        foreach ($enum::cases() as $case) {
            $key = "status.{$group}.{$case->value}";
            $bangla = Lang::get($key, [], 'bn', false);

            if (! Lang::hasForLocale($key, 'bn') || ! preg_match('/\p{Bengali}/u', (string) $bangla)) {
                $missing[] = $key;
            }
        }
    }

    expect($missing)->toBe([]);
});

it('says each enum label in the reader\'s language', function () {
    app()->setLocale('bn');

    expect(ReturnReason::NotAsDescribed->label())->toBe('বর্ণনার সঙ্গে মেলে না')
        ->and(ReturnParty::Banij->label())->toBe('বণিজ')
        ->and(ReturnParty::Partner->label())->toBe('পার্টনার')
        ->and(ReturnDisposition::Quarantine->label())->toBe('যাচাইয়ের জন্য আলাদা রাখা')
        ->and(ReturnChargeKind::DamageLoss->label())->toBe('ক্ষতির খরচ')
        ->and(ReturnStatus::UnderInspection->label())->toBe('যাচাই চলছে');

    app()->setLocale('en');

    expect(ReturnReason::NotAsDescribed->label())->toBe('Not as described')
        ->and(ReturnParty::Banij->label())->toBe('Banij');
});

it('keeps the English and Bangla screens\' lines in step, key for key', function () {
    foreach (['cases', 'damages', 'nav', 'status'] as $file) {
        $english = array_keys(Arr::dot(require lang_path("en/{$file}.php")));
        $bangla = array_keys(Arr::dot(require lang_path("bn/{$file}.php")));

        // Some lines belong to other batches; only this batch's groups must match exactly.
        $own = fn (array $keys) => array_values(array_filter($keys, fn ($key) => $file !== 'status'
            || preg_match('/^(return_|damage|case_evidence|return\.)/', $key) === 1));

        expect(array_values(array_diff($own($english), $own($bangla))))->toBe([], "{$file}: in English, not in Bangla")
            ->and(array_values(array_diff($own($bangla), $own($english))))->toBe([], "{$file}: in Bangla, not in English");
    }
});

it('gives every refusal the backend can make a line in both languages', function () {
    $codes = [];

    foreach ([
        'app/Domain/Order/Exceptions/ReturnRefused.php',
        'app/Domain/Damage/Exceptions/DamageRefused.php',
        'app/Domain/Cases/Exceptions/CaseEvidenceRefused.php',
    ] as $file) {
        preg_match_all("/new self\(\s*\d+\s*,\s*'([a-z0-9_]+)'/", (string) file_get_contents(base_path($file)), $found);
        $codes = [...$codes, ...$found[1]];
    }

    expect($codes)->not->toBeEmpty();

    $missing = [];

    foreach (array_unique($codes) as $code) {
        foreach (['en', 'bn'] as $locale) {
            if (! Lang::hasForLocale("cases.errors.{$code}", $locale)) {
                $missing[] = "{$locale}:{$code}";
            }
        }
    }

    expect($missing)->toBe([]);
});
