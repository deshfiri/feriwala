<?php

namespace App\Http\Controllers\Api\Mobile\V1\Websites\Concerns;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Models\User;
use App\Support\Concurrency\Exceptions\LockTimeout;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * What every website endpoint does before it touches anything (§31.3).
 *
 * The website is found among the signed-in person's own account's, in the
 * query, so another partner's public id is a 404 rather than a refusal that
 * confirms the shop exists.
 */
trait ResolvesOwnWebsite
{
    use ResolvesBusinessAccount;

    protected function websiteFor(Request $request, string $website): Website
    {
        $record = $this->overview->findForAccount($this->businessAccountFor($request), $website);

        abort_if($record === null, 404);

        return $record;
    }

    protected function person(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }

    /**
     * Run one refusable step, turning a refusal into a `422` on the field it concerns.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $step
     * @return TReturn
     */
    protected function attempt(callable $step, string $busyField = 'website'): mixed
    {
        try {
            return $step();
        } catch (WebsiteRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        } catch (LockTimeout) {
            throw ValidationException::withMessages([$busyField => __('website.refused.busy')]);
        }
    }
}
