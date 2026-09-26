<?php

namespace App\Support\Status;

use App\Support\StateMachine\TransitionableState;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * A stable, translation-safe label for a status enum (§ shared navigation
 * registry / RBAC batch — Bangla status-label gap).
 *
 * The enum's own `->value` is what a migration's CHECK constraint, a query,
 * or a status-history row holds — nothing here changes it, and nothing here
 * runs at write time. This only decides what a person reads: it looks up
 * `status.<group>.<value>` in the request's current locale and falls back to
 * a plain humanized version of the value itself if no translation line
 * exists yet, so a status added without one still reads as words instead of
 * a raw dotted key or a blank label.
 *
 * One trait, one lookup rule, used by every status enum that implements
 * {@see TransitionableState} and is actually shown
 * to an end user — each consuming enum supplies only the one thing that
 * differs between them (`statusLabelGroup()`), so the translation maps live
 * in `lang/{locale}/status.php` once rather than being copied per enum or
 * duplicated again in a React page.
 */
trait HasTranslatedLabel
{
    /**
     * The lang-file segment this enum's labels live under — looked up as
     * `status.<group>.<value>`, e.g. `status.account.active`.
     */
    abstract protected static function statusLabelGroup(): string;

    public function label(): string
    {
        $key = 'status.'.static::statusLabelGroup().'.'.$this->value;

        return Lang::has($key) ? __($key) : self::humanize($this->value);
    }

    /**
     * A safe, readable stand-in for a status with no translation line yet.
     * Never the raw dotted key, which would look like a bug to a reader
     * rather than a missing translation.
     */
    protected static function humanize(string $value): string
    {
        return Str::of($value)->replace('_', ' ')->ucfirst()->toString();
    }
}
