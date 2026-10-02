<?php

namespace App\Domain\Storage\Actions;

use App\Domain\Storage\Data\R2ConnectionTestResult;
use App\Domain\Storage\R2StorageSettings;
use App\Integrations\Storage\R2Manager;

/**
 * "Test connection" for the Storage -> Cloudflare R2 settings screen.
 *
 * Deliberately separate from {@see ConfigureR2Storage}: an administrator must
 * be able to try a set of credentials before committing to them, and this
 * runs the exact same live check {@see ConfigureR2Storage} itself runs before
 * persisting anything -- never a cached "last verified" flag.
 */
class TestR2Connection
{
    public function __construct(
        protected R2StorageSettings $settings,
        protected R2Manager $manager,
    ) {}

    /**
     * @param  array<string, mixed>  $fields  form fields not yet saved, merged over what is already stored
     */
    public function handle(array $fields = []): R2ConnectionTestResult
    {
        $candidate = $this->settings->candidate($fields);

        foreach (['account_id', 'access_key_id', 'secret_access_key', 'bucket', 'endpoint'] as $required) {
            if (($candidate[$required] ?? null) === null || $candidate[$required] === '') {
                return R2ConnectionTestResult::failure(sprintf(
                    '%s is required before a connection can be tested.',
                    ucfirst(str_replace('_', ' ', $required)),
                ));
            }
        }

        return $this->manager->testConnection($candidate);
    }
}
