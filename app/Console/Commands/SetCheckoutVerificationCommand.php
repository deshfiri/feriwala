<?php

namespace App\Console\Commands;

use App\Domain\Website\Actions\SetCheckoutVerificationEverywhere;
use Illuminate\Console\Command;

/**
 * `php artisan website:sms-verification off --force` — no storefront asks a
 * cash-on-delivery customer for an SMS code, and no Partner can turn it back on.
 * `on` requires it of every one.
 *
 * See {@see SetCheckoutVerificationEverywhere}.
 */
class SetCheckoutVerificationCommand extends Command
{
    protected $signature = 'website:sms-verification
        {state : off = no storefront asks for the code, on = every storefront must}
        {--force : Do it without asking for confirmation}';

    protected $description = 'Turn the SMS order-confirmation code off (or required) for every storefront at once';

    public function handle(SetCheckoutVerificationEverywhere $everywhere): int
    {
        $state = $this->argument('state');

        if (! in_array($state, ['on', 'off'], true)) {
            $this->components->error("Unknown state '{$state}'. Use on or off.");

            return self::FAILURE;
        }

        $required = $state === 'on';

        if (! $this->option('force') && ! $this->confirm($required
            ? 'Require the SMS confirmation code on every storefront?'
            : 'Force the SMS confirmation code OFF on every storefront? Cash-on-delivery customers will no longer be asked for a code, and Partners cannot turn it back on.')) {
            $this->components->warn('Nothing was changed.');

            return self::SUCCESS;
        }

        $result = $everywhere->handle($required);

        $this->components->info($required
            ? 'The SMS confirmation code is now required on every storefront.'
            : sprintf('The SMS confirmation code is forced off for every storefront (%d switched off). Partners cannot turn it back on; run "on" to require it again.', $result['storefronts_changed']));

        return self::SUCCESS;
    }
}
