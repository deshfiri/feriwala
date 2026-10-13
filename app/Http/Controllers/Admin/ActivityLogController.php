<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Actions\PurgeAuditLogs;
use App\Domain\Audit\AuditRetention;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The record of what staff and the system did (§36.2).
 *
 * Read-only: the log is append-only in the model and in the database, so there
 * is nothing here to edit or remove. Reading it is `audit.view_audit_logs`, held
 * by an Admin and by anybody granted it; a partner is refused whatever else they
 * hold. Searching and paging happen in the database (§39), and every filter is
 * held to the values it may take.
 */
class ActivityLogController extends Controller
{
    public const PER_PAGE = 25;

    public function __construct(
        protected AuditRetention $retention,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $request->user();

        abort_unless(
            $actor instanceof User
                && ! CatalogPolicy::isBusinessIdentity($actor)
                && $actor->can(PermissionCatalogue::name(PermissionModule::Audit, PermissionAction::ViewAuditLogs)),
            403,
        );

        $filters = $this->filters($request);
        $canPurge = $actor->can(PermissionCatalogue::name(PermissionModule::Audit, PermissionAction::Delete));
        $personName = $filters['person'] === null || $filters['person'] === PurgeAuditLogs::SYSTEM
            ? null
            : User::query()->where('public_id', $filters['person'])->value('name');

        return Inertia::render('admin/activity-log/index', [
            'entries' => $this->query($filters)
                ->paginate(self::PER_PAGE)
                ->withQueryString()
                ->through(fn (AuditLog $log) => [
                    'id' => $log->public_id,
                    'action' => $log->action,
                    'module' => $log->module,
                    'actor' => data_get($log, 'actor.name') ?? $log->actor_label,
                    'actor_type' => $log->actor_type,
                    'subject' => $log->auditable_type === null ? null : class_basename($log->auditable_type),
                    'note' => $log->note,
                    'reason' => $log->reason,
                    'before' => $log->before,
                    'after' => $log->after,
                    'ip_address' => $log->ip_address,
                    'is_sensitive' => $log->is_sensitive,
                    'created_at' => $log->created_at->toIso8601String(),
                ]),
            'filters' => $filters,
            'person_name' => $personName,
            'retention_days' => $this->retention->days(),
            'can' => [
                'purge' => $canPurge,
                'manage_retention' => $actor->can(PermissionCatalogue::name(PermissionModule::Audit, PermissionAction::ManageSettings)),
            ],
            'summary' => $this->summary(),
            'people' => $this->people(),
            'modules' => AuditLog::query()
                ->whereNotNull('module')
                ->distinct()
                ->orderBy('module')
                ->pluck('module')
                ->all(),
        ]);
    }

    /**
     * Clear the log: everything, or one person's (or the system's) entries,
     * optionally only those older than some days. Needs `audit.delete`, the
     * person's password and a reason; the purge is recorded permanently (D29).
     */
    public function purge(Request $request, PurgeAuditLogs $purge): RedirectResponse
    {
        $actor = $request->user();

        abort_unless(
            $actor instanceof User
                && ! CatalogPolicy::isBusinessIdentity($actor)
                && $actor->can(PermissionCatalogue::name(PermissionModule::Audit, PermissionAction::Delete)),
            403,
        );

        $validated = $request->validate([
            'password' => ['required', 'string', 'current_password'],
            'reason' => ['required', 'string', 'min:'.PurgeAuditLogs::MINIMUM_REASON, 'max:1000'],
            'scope' => ['required', Rule::in(['everyone', 'person'])],
            'person' => ['required_if:scope,person', 'nullable', 'string', 'regex:/^(system|[0-9A-Za-z]{26})$/'],
            'older_than_days' => ['nullable', 'integer', 'min:1', 'max:'.AuditRetention::MAXIMUM_DAYS],
            'confirm' => ['accepted'],
        ]);

        $olderThanDays = $validated['older_than_days'] ?? null;

        try {
            $deleted = $purge->handle(
                $actor,
                $validated['scope'] === 'person' ? $validated['person'] : null,
                $olderThanDays === null ? null : now()->subDays((int) $olderThanDays),
                $validated['reason'],
            );
        } catch (InvalidArgumentException $invalid) {
            throw ValidationException::withMessages(['reason' => $invalid->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('audit.purge.done', ['count' => $deleted])]);

        return to_route('admin.activity-log.index');
    }

    /**
     * The headline figures above the feed, for today and the last seven days.
     *
     * @return array{today: int, sensitive_today: int, people_today: int, week: int}
     */
    protected function summary(): array
    {
        $today = AuditLog::query()->where('created_at', '>=', now()->startOfDay());

        return [
            'today' => (clone $today)->count(),
            'sensitive_today' => (clone $today)->where('is_sensitive', true)->count(),
            'people_today' => (clone $today)->whereNotNull('actor_id')->distinct()->count('actor_id'),
            'week' => AuditLog::query()->where('created_at', '>=', now()->subDays(7))->count(),
        ];
    }

    /**
     * Who has been active in the last thirty days, most recent first, so the log
     * can be opened one person at a time. Staff only: a business member's
     * actions are theirs to see, not a platform roster entry.
     *
     * @return array{staff: list<array{id: string, name: string, week: int, total: int, last_at: string}>, system: int}
     */
    protected function people(): array
    {
        $since = now()->subDays(30);

        $rows = AuditLog::query()
            ->selectRaw('actor_id, count(*) as total, count(*) filter (where created_at >= ?) as week, max(created_at) as last_at', [now()->subDays(7)])
            ->whereNotNull('actor_id')
            ->where('created_at', '>=', $since)
            ->groupBy('actor_id')
            ->orderByDesc('last_at')
            ->limit(24)
            ->get();

        $users = User::query()
            ->whereIn('id', $rows->pluck('actor_id'))
            ->whereDoesntHave('accountMembership')
            ->get(['id', 'public_id', 'name'])
            ->keyBy('id');

        $staff = [];

        foreach ($rows as $row) {
            $user = $users->get($row->actor_id);

            if ($user === null) {
                continue;
            }

            $staff[] = [
                'id' => $user->public_id,
                'name' => $user->name,
                'week' => (int) $row->getAttribute('week'),
                'total' => (int) $row->getAttribute('total'),
                'last_at' => CarbonImmutable::parse((string) $row->getAttribute('last_at'))->toIso8601String(),
            ];
        }

        return [
            'staff' => $staff,
            'system' => AuditLog::query()->whereNull('actor_id')->where('created_at', '>=', $since)->count(),
        ];
    }

    /**
     * @return array{search: string|null, person: string|null, module: string|null, sensitive: bool, from: string|null, to: string|null}
     */
    protected function filters(Request $request): array
    {
        $search = trim($request->string('search')->toString());
        $person = $request->string('person')->toString();
        $module = $request->string('module')->toString();
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        return [
            'search' => $search === '' ? null : mb_substr($search, 0, 100),
            'person' => $person === 'system' || preg_match('/^[0-9A-Za-z]{26}$/', $person) === 1 ? $person : null,
            'module' => preg_match('/^[a-z_]{1,32}$/', $module) === 1 ? $module : null,
            'sensitive' => $request->boolean('sensitive'),
            'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1 ? $from : null,
            'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1 ? $to : null,
        ];
    }

    /**
     * @param  array{search: string|null, person: string|null, module: string|null, sensitive: bool, from: string|null, to: string|null}  $filters
     * @return EloquentBuilder<AuditLog>
     */
    protected function query(array $filters): EloquentBuilder
    {
        $term = $filters['search'] === null
            ? null
            : '%'.addcslashes($filters['search'], '%_\\').'%';

        return AuditLog::query()
            ->with('actor:id,name')
            ->when($term !== null, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('action', 'ilike', $term)
                ->orWhere('actor_label', 'ilike', $term)
                ->orWhere('reason', 'ilike', $term)
                ->orWhere('note', 'ilike', $term)
                ->orWhereHas('actor', fn (Builder $actor) => $actor->where('name', 'ilike', $term))))
            ->when($filters['person'] === 'system', fn (Builder $query) => $query->whereNull('actor_id'))
            ->when(
                $filters['person'] !== null && $filters['person'] !== 'system',
                fn (Builder $query) => $query->whereHas('actor', fn (Builder $actor) => $actor->where('public_id', $filters['person'])),
            )
            ->when($filters['module'] !== null, fn (Builder $query) => $query->where('module', $filters['module']))
            ->when($filters['sensitive'], fn (Builder $query) => $query->where('is_sensitive', true))
            ->when($filters['from'] !== null, fn (Builder $query) => $query->where('created_at', '>=', $filters['from'].' 00:00:00'))
            ->when($filters['to'] !== null, fn (Builder $query) => $query->where('created_at', '<=', $filters['to'].' 23:59:59'))
            ->orderByDesc('id');
    }
}
