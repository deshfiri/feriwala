<?php

namespace App\Domain\Account\Data;

use App\Support\Localization\Locale;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The intent to change an interface language.
 *
 * A Data object carries a validated, typed request across the boundary into an
 * Action. By the time one exists, the input has already been validated — so an
 * Action never re-checks shape, only business rules. That separation is what
 * keeps Actions readable as business logic rather than as defensive plumbing.
 *
 * Data objects are immutable. An Action must not be able to alter the intent it
 * was handed.
 */
class LocaleChange
{
    public function __construct(
        public readonly Locale $locale,
        public readonly ?Authenticatable $user,
    ) {}

    /**
     * Whether the choice can be stored against an account rather than only a
     * session.
     */
    public function isForSignedInUser(): bool
    {
        return $this->user !== null;
    }
}
