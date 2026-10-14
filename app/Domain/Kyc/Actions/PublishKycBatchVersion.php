<?php

namespace App\Domain\Kyc\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Kyc\Exceptions\InvalidKycBatch;
use App\Domain\Kyc\Models\KycBatch;
use App\Domain\Kyc\Models\KycBatchVersion;
use App\Models\User;
use Illuminate\Database\DatabaseManager;

/**
 * Freezes a batch's current structure into a new, immutable version (D30).
 *
 * Editing a batch changes only the live structure the builder works on. Nothing
 * an applicant sees, and nothing a round was opened against, changes until a
 * version is published — and a round already open keeps the version it has.
 *
 * Publishing a batch that has not changed since its last version returns that
 * version rather than minting an identical one: a version number means "this is
 * what changed", not "someone pressed the button".
 */
class PublishKycBatchVersion
{
    public function __construct(
        protected BuildBatchDefinition $definitions,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws InvalidKycBatch when an applicant could never complete it
     */
    public function handle(KycBatch $batch, ?User $by = null): KycBatchVersion
    {
        return $this->database->transaction(function () use ($batch, $by) {
            // Two people publishing at once must not both take the next number.
            /** @var KycBatch $locked */
            $locked = KycBatch::query()->lockForUpdate()->findOrFail($batch->id);

            $definition = $this->definitions->handle($locked);
            $problems = $this->definitions->problems($definition);

            if ($problems !== []) {
                throw new InvalidKycBatch($problems);
            }

            $checksum = hash('sha256', (string) json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $latest = $locked->latestVersion();

            if ($latest !== null && hash_equals($latest->checksum, $checksum)) {
                return $latest;
            }

            $version = $locked->versions()->create([
                'version' => $locked->published_version + 1,
                'definition' => $definition,
                'checksum' => $checksum,
                'published_by' => $by?->id,
                'published_at' => now(),
            ]);

            $locked->forceFill(['published_version' => $version->version])->save();

            // The shape of the change only. No requirement wording, no evidence.
            $this->audit->handle(new AuditEntry(
                action: 'kyc.batch_published',
                actorId: $by?->id,
                actorType: $by === null ? 'system' : 'user',
                auditableType: KycBatch::class,
                auditableId: $locked->id,
                after: [
                    'batch' => $locked->key,
                    'version' => $version->version,
                    'groups' => count($definition['groups']),
                ],
                module: 'kyc',
            ));

            return $version;
        });
    }

    /**
     * The version a new round should open with: the newest, publishing the first
     * one on the spot for a batch that has never had one.
     *
     * That first publish is the legacy path — a batch carried over from before
     * versions existed has its live structure mapped mechanically by the
     * migration, so publishing it unchanged records what applicants were already
     * being asked.
     */
    public function latestOrPublish(KycBatch $batch): KycBatchVersion
    {
        return $batch->latestVersion() ?? $this->handle($batch);
    }
}
