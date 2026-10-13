<?php

namespace App\Domain\Audit\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRetention;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Clears the activity log — the one guarded way an audit entry is ever deleted
 * (D29, approved by the Project Owner).
 *
 * Three ways in, all through here:
 *
 *  - an authorised person clearing everything,
 *  - an authorised person clearing one staff member's entries (or the system's),
 *  - the nightly sweep removing entries older than the retention setting.
 *
 * What keeps this from being a quiet way to erase a trail:
 *
 *  - the right to do it is `audit.delete`, held by nobody who was not given it;
 *  - a person must state a reason, and the controller confirms their password;
 *  - the database refuses every DELETE except inside the transaction opened
 *    here, and refuses to delete a purge record at all;
 *  - the purge itself is written as `audit.logs_purged` — who, when, which
 *    scope, how many, and why — and is never itself purged;
 *  - UPDATE and TRUNCATE stay refused, as before.
 */
class PurgeAuditLogs
{
    /** A reason shorter than this is not one anybody can act on later. */
    public const MINIMUM_REASON = 10;

    /** The scope that names the system's own entries (no actor). */
    public const SYSTEM = 'system';

    public function __construct(
        protected RecordAuditLog $audit,
        protected AuditRetention $retention,
    ) {}

    /**
     * @param  string|null  $person  null for everyone, {@see self::SYSTEM}, or a user's public id
     * @param  CarbonInterface|null  $olderThan  only entries created before this moment
     * @return int how many entries were removed
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException when the reason is too short or the person is unknown
     */
    public function handle(User $actor, ?string $person, ?CarbonInterface $olderThan, string $reason): int
    {
        if (CatalogPolicy::isBusinessIdentity($actor)
            || ! $actor->can(PermissionCatalogue::name(PermissionModule::Audit, PermissionAction::Delete))) {
            throw new AuthorizationException('You may not clear the activity log.');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < self::MINIMUM_REASON) {
            throw new InvalidArgumentException('Clearing the activity log needs a reason of at least '.self::MINIMUM_REASON.' characters.');
        }

        $personId = null;
        $personName = null;

        if ($person !== null && $person !== self::SYSTEM) {
            $user = User::query()->where('public_id', $person)->first(['id', 'name']);

            if ($user === null) {
                throw new InvalidArgumentException('That person does not exist.');
            }

            $personId = $user->id;
            $personName = $user->name;
        }

        return $this->purge(
            $personId,
            $person === self::SYSTEM,
            $olderThan,
            new AuditEntry(
                action: 'audit.logs_purged',
                actorId: $actor->id,
                before: null,
                reason: $reason,
                module: 'audit',
                isSensitive: true,
            ),
            [
                'scope' => $person === null ? 'everyone' : ($person === self::SYSTEM ? 'system' : 'person'),
                'person' => $personName,
                'older_than' => $olderThan?->toIso8601String(),
            ],
        );
    }

    /**
     * The nightly sweep: remove entries older than the retention setting. Does
     * nothing while retention is off.
     */
    public function purgeExpired(): int
    {
        $days = $this->retention->days();

        if ($days === null) {
            return 0;
        }

        $cutoff = now()->subDays($days);

        return $this->purge(
            null,
            false,
            $cutoff,
            new AuditEntry(
                action: 'audit.logs_purged',
                actorType: 'system',
                actorLabel: 'Retention policy',
                reason: "Entries older than {$days} days, per the activity log retention setting.",
                module: 'audit',
                isSensitive: true,
            ),
            ['scope' => 'retention', 'person' => null, 'older_than' => $cutoff->toIso8601String()],
        );
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    protected function purge(?int $personId, bool $systemOnly, ?CarbonInterface $olderThan, AuditEntry $entry, array $scope): int
    {
        return DB::transaction(function () use ($personId, $systemOnly, $olderThan, $entry, $scope) {
            // The database refuses a DELETE on this table unless this is set,
            // and only for this transaction.
            DB::statement("SELECT set_config('feriwala.purge_audit_logs', 'on', true)");

            $deleted = DB::table('audit_logs')
                ->where('action', '<>', 'audit.logs_purged')
                ->when($personId !== null, fn ($query) => $query->where('actor_id', $personId))
                ->when($systemOnly, fn ($query) => $query->whereNull('actor_id'))
                ->when($olderThan !== null, fn ($query) => $query->where('created_at', '<', $olderThan))
                ->delete();

            DB::statement("SELECT set_config('feriwala.purge_audit_logs', 'off', true)");

            $this->audit->handle(new AuditEntry(
                action: $entry->action,
                actorId: $entry->actorId,
                actorType: $entry->actorType,
                actorLabel: $entry->actorLabel,
                before: ['deleted' => $deleted],
                after: $scope,
                reason: $entry->reason,
                module: $entry->module,
                isSensitive: true,
            ));

            return $deleted;
        });
    }
}
