<?php

namespace App\Domain\Audit\Data;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * One action about to be recorded in the audit trail.
 *
 * Immutable. Built at the point the action happens, where the reason and the
 * before/after state are actually known — reconstructing them later from a
 * request is how audit trails end up recording "something changed".
 */
class AuditEntry
{
    /**
     * @param  array<array-key, mixed>|null  $before
     * @param  array<array-key, mixed>|null  $after
     */
    public function __construct(
        public readonly string $action,
        public readonly ?int $actorId = null,
        public readonly string $actorType = 'user',
        public readonly ?string $actorLabel = null,
        public readonly ?string $auditableType = null,
        public readonly int|string|null $auditableId = null,
        public readonly ?array $before = null,
        public readonly ?array $after = null,
        public readonly ?string $reason = null,
        public readonly ?string $note = null,
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
        public readonly int|string|null $accountId = null,
        public readonly ?string $module = null,
        public readonly bool $isSensitive = false,
    ) {}

    /**
     * Build an entry for a change to a model.
     *
     * `before` is taken from the model's original attributes and `after` from
     * what actually changed, so an entry records the change rather than the
     * whole row.
     */
    public static function forModel(
        Model $model,
        PermissionModule $module,
        PermissionAction $action,
        ?string $reason = null,
    ): self {
        return new self(
            action: $module->value.'.'.$action->value,
            auditableType: $model::class,
            auditableId: $model->getKey(),
            before: array_intersect_key($model->getOriginal(), $model->getChanges()),
            after: $model->getChanges(),
            reason: $reason,
            module: $module->value,
            isSensitive: $action->isSensitive(),
        );
    }

    /**
     * Attach the actor and request context.
     *
     * Kept separate from construction so an Action can describe *what* happened
     * without reaching for the request — which also lets the same entry be
     * recorded from a queued job or a scheduled task, where there is no request.
     */
    public function withContext(Request $request): self
    {
        $user = $request->user();

        return new self(
            action: $this->action,
            actorId: $user?->getAuthIdentifier(),
            actorType: $user === null ? 'system' : 'user',
            actorLabel: $this->actorLabel,
            auditableType: $this->auditableType,
            auditableId: $this->auditableId,
            before: $this->before,
            after: $this->after,
            reason: $this->reason,
            note: $this->note,
            ipAddress: $request->ip(),
            userAgent: mb_substr((string) $request->userAgent(), 0, 512),
            accountId: $this->accountId,
            module: $this->module,
            isSensitive: $this->isSensitive,
        );
    }
}
