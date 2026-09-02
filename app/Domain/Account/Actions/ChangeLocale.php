<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Data\LocaleChange;
use App\Http\Middleware\SetLocale;
use App\Support\Localization\Locale;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Model;

/**
 * Records a user's choice of interface language.
 *
 * This is the reference shape for an Action (see app/Domain/README.md):
 *
 *   - one public `handle()`, named for the business operation
 *   - takes a Data object, not raw request input
 *   - returns a value rather than a response — the caller decides how to reply
 *   - collaborators arrive through the constructor, so it can be tested without
 *     a request or an HTTP layer at all
 *
 * The choice goes to the session always, and to the account as well when there
 * is one, so a signed-in person keeps their language across devices while a
 * guest still keeps it for the visit.
 */
class ChangeLocale
{
    public function __construct(
        protected Session $session,
    ) {}

    public function handle(LocaleChange $change): Locale
    {
        $this->session->put(SetLocale::SESSION_KEY, $change->locale->value);

        if ($change->isForSignedInUser()) {
            $this->persistToAccount($change);
        }

        return $change->locale;
    }

    /**
     * Store the preference on the user record.
     *
     * The `locale` column arrives with the account module. Until it exists, a
     * signed-in user's choice simply lives in the session — the right
     * degradation rather than an error. Presence is decided from the attributes
     * already loaded on the model, so this costs no extra query.
     */
    protected function persistToAccount(LocaleChange $change): void
    {
        $user = $change->user;

        if (! $user instanceof Model) {
            return;
        }

        if (! array_key_exists('locale', $user->getAttributes())) {
            return;
        }

        $user->forceFill(['locale' => $change->locale->value])->save();
    }
}
