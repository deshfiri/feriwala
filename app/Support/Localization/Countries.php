<?php

namespace App\Support\Localization;

use Illuminate\Contracts\Config\Repository;

/**
 * The countries an account, address or rule may name (§5.2, §7.2).
 *
 * One list, read from `config/countries.php`, so a KYC scope rule, a shipping
 * address and a courier zone cannot come to disagree about whether "BD" is a
 * country we serve. Free text was the alternative, and it produces scope rules
 * nobody can resolve — "Bangladsh" looks configured and matches nobody.
 *
 * **Two questions, deliberately separate.** "May something new be scoped to
 * this?" is {@see selectable()}; "does this code mean anything?" is
 * {@see resolves()}. A retired market answers no to the first and yes to the
 * second, which is what lets Feriwala stop selling somewhere without turning
 * every address already stored there into unreadable data.
 *
 * Codes are normalised to upper case on the way in, because a code arrives from
 * a form, a seeder and an API and only one of those is certain to be tidy.
 */
class Countries
{
    public function __construct(
        protected Repository $config,
    ) {}

    /**
     * Markets open for new selections.
     *
     * @return array<string, string> code => English name
     */
    public function all(): array
    {
        /** @var array<string, string> $countries */
        $countries = $this->config->get('countries.supported', []);

        return $countries;
    }

    /**
     * Markets no longer sold into, kept resolvable for what already names them.
     *
     * @return array<string, string>
     */
    public function retired(): array
    {
        /** @var array<string, string> $retired */
        $retired = $this->config->get('countries.retired', []);

        return $retired;
    }

    /**
     * Every code that has ever been offered — the set a stored value may hold.
     *
     * @return array<string, string>
     */
    public function known(): array
    {
        return [...$this->retired(), ...$this->all()];
    }

    /**
     * Codes a **new** selection may use.
     *
     * @return array<int, string>
     */
    public function codes(): array
    {
        return array_keys($this->all());
    }

    /**
     * Whether something new may be scoped to this country.
     */
    public function selectable(?string $code): bool
    {
        return $code !== null && array_key_exists($this->normalise($code), $this->all());
    }

    /**
     * Whether this code means anything at all — including a retired market.
     *
     * What existing data is read against. A stored code must never stop
     * resolving because a market closed.
     */
    public function resolves(?string $code): bool
    {
        return $code !== null && array_key_exists($this->normalise($code), $this->known());
    }

    /**
     * The name for a code, retired markets included.
     */
    public function nameFor(?string $code): ?string
    {
        return $code === null ? null : ($this->known()[$this->normalise($code)] ?? null);
    }

    public function default(): string
    {
        /** @var string $default */
        $default = $this->config->get('countries.default', 'BD');

        return $default;
    }

    /**
     * Options for a picker, home market first and the rest by name.
     *
     * Retired markets are absent: a picker is where new selections are made,
     * and offering a country we no longer serve invites one.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function options(): array
    {
        $countries = $this->all();
        $default = $this->default();

        $rest = $countries;
        unset($rest[$default]);
        asort($rest);

        $ordered = isset($countries[$default])
            ? [$default => $countries[$default]] + $rest
            : $rest;

        return array_map(
            fn (string $code, string $name) => ['value' => $code, 'label' => $name],
            array_keys($ordered),
            array_values($ordered),
        );
    }

    public function normalise(string $code): string
    {
        return mb_strtoupper(trim($code));
    }
}
