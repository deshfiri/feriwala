<?php

namespace App\Domain\Cases\Queries;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Cases\CaseNextAction;
use App\Domain\Damage\Enums\DamageStatus;
use App\Domain\Order\Enums\ReturnStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\DB;

/**
 * Returns, exchanges and damages in one queue, for the staff who work them (D35).
 *
 * **One list, three kinds.** A return and an exchange are rows of `order_returns`
 * (an exchange is a return case that also sent a replacement order); a damage is a
 * row of `order_damages`. They are read as one set so the staff member sees a day's
 * work in one place, ordered by what somebody has to act on first and then by
 * newest — not three screens to remember to check.
 *
 * **Only what the viewer may see.** A kind is in the set only if the viewer holds
 * its view permission — `return.view`, `exchange.view`, `damage.view` — and the
 * counts shown on the tabs are counted from that same set, so a badge never
 * reveals how many cases of a kind the viewer is not allowed to open. Every
 * filter, the search and the order are applied in the database.
 *
 * **Nothing about the customer.** A row carries references, the Partner, the
 * store, the product and the quantity — never a name, number or address.
 */
class CaseQueue
{
    public const PER_PAGE = 25;

    /** Stages of a return that somebody has to act on. */
    protected const RETURN_OPEN = ['requested', 'approved', 'waiting_for_parcel', 'parcel_received', 'under_inspection', 'inspection_approved', 'settlement_pending'];

    /** Stages of a damage that somebody has to act on. */
    protected const DAMAGE_OPEN = ['reported', 'awaiting_receipt', 'received', 'under_inspection', 'liability_review', 'financial_posting_pending', 'dispatch_requested', 'dispatched'];

    /**
     * @param  array{type?: string|null, status?: string|null, partner?: string|null, store?: string|null, responsibility?: string|null, from?: string|null, to?: string|null, search?: string|null}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function page(User $viewer, array $filters, int $page = 1, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $kinds = $this->visibleKinds($viewer);

        if ($kinds === []) {
            return new Paginator([], 0, $perPage, $page);
        }

        $type = in_array($filters['type'] ?? null, $kinds, true) ? $filters['type'] : null;
        $search = $this->term($filters['search'] ?? null);

        $query = DB::query()
            ->fromSub($this->union(), 'cases')
            ->whereIn('kind', $type === null ? $kinds : [$type])
            ->when($this->known($filters['status'] ?? null), fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['partner'] ?? null, fn (Builder $q, string $partner) => $q->where('partner_public_id', $partner))
            ->when($filters['store'] ?? null, fn (Builder $q, string $store) => $q->where('store_public_id', $store))
            ->when(in_array($filters['responsibility'] ?? null, ['partner', 'banij'], true), fn (Builder $q) => $q->where('responsible_party', $filters['responsibility']))
            ->when($this->date($filters['from'] ?? null), fn (Builder $q, string $from) => $q->where('submitted_at', '>=', $from.' 00:00:00'))
            ->when($this->date($filters['to'] ?? null), fn (Builder $q, string $to) => $q->where('submitted_at', '<=', $to.' 23:59:59'))
            ->when($search !== null, fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('reference', 'ilike', "%{$search}%")
                ->orWhere('order_reference', 'ilike', "%{$search}%")
                ->orWhere('exchange_reference', 'ilike', "%{$search}%")
                ->orWhere('partner_name', 'ilike', "%{$search}%")
                ->orWhere('product_name', 'ilike', "%{$search}%")));

        $total = (clone $query)->count();

        // Newest actionable first: what is open comes before what is finished, then newest.
        $rows = $query
            ->selectRaw('cases.*')
            ->orderByRaw($this->actionableSql().' desc')
            ->orderByDesc('submitted_at')
            ->orderByDesc('record_id')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn ($row) => $this->row($viewer, $row))
            ->all();

        return new Paginator($rows, $total, $perPage, $page, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);
    }

    /**
     * What the tabs show: for each kind the viewer may see, how many cases there
     * are and how many are waiting for somebody.
     *
     * @return array<string, array{total: int, actionable: int}>
     */
    public function counts(User $viewer): array
    {
        $kinds = $this->visibleKinds($viewer);
        $all = ['total' => 0, 'actionable' => 0];
        $counts = ['all' => $all];

        foreach ($kinds as $kind) {
            $counts[$kind] = ['total' => 0, 'actionable' => 0];
        }

        if ($kinds === []) {
            return $counts;
        }

        $rows = DB::query()
            ->fromSub($this->union(), 'cases')
            ->whereIn('kind', $kinds)
            ->selectRaw('kind, count(*) as total, sum(case when '.$this->actionableSql().' then 1 else 0 end) as actionable')
            ->groupBy('kind')
            ->get();

        foreach ($rows as $row) {
            $counts[$row->kind] = ['total' => (int) $row->total, 'actionable' => (int) $row->actionable];
            $all['total'] += (int) $row->total;
            $all['actionable'] += (int) $row->actionable;
        }

        $counts['all'] = $all;

        return $counts;
    }

    /**
     * The Partners and stores that have cases, for the filters — only those in a
     * kind the viewer may see.
     *
     * @return array{partners: list<array{id: string, name: string}>, stores: list<array{id: string, name: string}>}
     */
    public function filterOptions(User $viewer): array
    {
        $kinds = $this->visibleKinds($viewer);

        if ($kinds === []) {
            return ['partners' => [], 'stores' => []];
        }

        $base = DB::query()->fromSub($this->union(), 'cases')->whereIn('kind', $kinds);

        return [
            'partners' => array_values((clone $base)->whereNotNull('partner_public_id')
                ->select('partner_public_id as id', 'partner_name as name')->distinct()->orderBy('name')->limit(200)->get()
                ->map(fn (object $row) => ['id' => (string) $row->id, 'name' => (string) $row->name])->all()),
            'stores' => array_values((clone $base)->whereNotNull('store_public_id')
                ->select('store_public_id as id', 'store_name as name')->distinct()->orderBy('name')->limit(200)->get()
                ->map(fn (object $row) => ['id' => (string) $row->id, 'name' => (string) $row->name])->all()),
        ];
    }

    /**
     * Every status a filter may name, labelled in the viewer's language.
     *
     * @return list<array{value: string, label: string, kind: string}>
     */
    public function statuses(User $viewer): array
    {
        $kinds = $this->visibleKinds($viewer);
        $options = [];

        if (array_intersect(['return', 'exchange'], $kinds) !== []) {
            foreach (ReturnStatus::cases() as $status) {
                $options[] = ['value' => $status->value, 'label' => $status->label(), 'kind' => 'return'];
            }
        }

        if (in_array('damage', $kinds, true)) {
            foreach (DamageStatus::cases() as $status) {
                $options[] = ['value' => $status->value, 'label' => $status->label(), 'kind' => 'damage'];
            }
        }

        return $options;
    }

    /**
     * The kinds of case this person holds the view permission for.
     *
     * @return list<'return'|'exchange'|'damage'>
     */
    public function visibleKinds(User $viewer): array
    {
        $kinds = [];

        foreach ([
            'return' => PermissionModule::ReturnCase,
            'exchange' => PermissionModule::Exchange,
            'damage' => PermissionModule::Damage,
        ] as $kind => $module) {
            if ($viewer->can(PermissionCatalogue::name($module, PermissionAction::View))) {
                $kinds[] = $kind;
            }
        }

        return $kinds;
    }

    /**
     * Every case as one set of rows, the same columns from both tables.
     */
    protected function union(): Builder
    {
        $returns = DB::table('order_returns as r')
            ->join('orders as o', 'o.id', '=', 'r.order_id')
            ->join('business_accounts as a', 'a.id', '=', 'r.business_account_id')
            ->leftJoin('websites as w', 'w.id', '=', 'r.website_id')
            ->leftJoin('orders as ex', 'ex.id', '=', 'r.exchange_order_id')
            ->selectRaw("case when r.case_type = 'exchange' then 'exchange' else 'return' end as kind")
            ->selectRaw('r.id as record_id')
            ->selectRaw('r.public_id as public_id')
            ->selectRaw('r.reference as reference')
            ->selectRaw('r.status as status')
            ->selectRaw('r.responsible_party as responsible_party')
            ->selectRaw('r.workflow_version as workflow_version')
            ->selectRaw('r.requested_at as submitted_at')
            ->selectRaw('o.reference as order_reference')
            ->selectRaw('o.public_id as order_public_id')
            ->selectRaw('ex.reference as exchange_reference')
            ->selectRaw('a.public_id as partner_public_id')
            ->selectRaw('a.name as partner_name')
            ->selectRaw('w.public_id as store_public_id')
            ->selectRaw('w.name as store_name')
            ->selectRaw('(select coalesce(sum(i.quantity), 0) from order_return_items i where i.order_return_id = r.id) as quantity')
            ->selectRaw('(select count(*) from order_return_items i where i.order_return_id = r.id) as line_count')
            ->selectRaw('(select oi.product_name from order_return_items i join order_items oi on oi.id = i.order_item_id where i.order_return_id = r.id order by i.id limit 1) as product_name')
            ->selectRaw('(select oi.variant_label from order_return_items i join order_items oi on oi.id = i.order_item_id where i.order_return_id = r.id order by i.id limit 1) as variant_label');

        $damages = DB::table('order_damages as d')
            ->join('orders as o', 'o.id', '=', 'd.order_id')
            ->join('business_accounts as a', 'a.id', '=', 'd.business_account_id')
            ->leftJoin('websites as w', 'w.id', '=', 'd.website_id')
            ->selectRaw("'damage' as kind")
            ->selectRaw('d.id as record_id')
            ->selectRaw('d.public_id as public_id')
            ->selectRaw('d.reference as reference')
            ->selectRaw('d.status as status')
            ->selectRaw('d.responsible_party as responsible_party')
            ->selectRaw('2 as workflow_version')
            ->selectRaw('d.reported_at as submitted_at')
            ->selectRaw('o.reference as order_reference')
            ->selectRaw('o.public_id as order_public_id')
            ->selectRaw('null::varchar as exchange_reference')
            ->selectRaw('a.public_id as partner_public_id')
            ->selectRaw('a.name as partner_name')
            ->selectRaw('w.public_id as store_public_id')
            ->selectRaw('w.name as store_name')
            ->selectRaw('d.quantity::bigint as quantity')
            ->selectRaw('1::bigint as line_count')
            ->selectRaw('d.product_name as product_name')
            ->selectRaw('d.variant_label as variant_label');

        return $returns->unionAll($damages);
    }

    /**
     * Whether a case is still waiting for somebody, in SQL.
     *
     * Built only from the two constant lists above, never from input.
     *
     * @return literal-string
     */
    protected function actionableSql(): string
    {
        $returns = "'".implode("','", self::RETURN_OPEN)."'";
        $damages = "'".implode("','", self::DAMAGE_OPEN)."'";

        return "(case when kind = 'damage' then status in ({$damages}) else (workflow_version >= 2 and status in ({$returns})) end)";
    }

    /**
     * @param  \stdClass  $row  one row of the union above
     * @return array<string, mixed>
     */
    protected function row(User $viewer, object $row): array
    {
        $status = $row->kind === 'damage'
            ? DamageStatus::from($row->status)
            : ReturnStatus::from($row->status);

        $version = (int) $row->workflow_version;

        return [
            'id' => $row->public_id,
            'kind' => $row->kind,
            'legacy' => $row->kind !== 'damage' && $version < 2,
            'reference' => $row->reference,
            'order' => ['reference' => $row->order_reference, 'id' => $row->order_public_id],
            'exchange_order' => $row->exchange_reference,
            'partner' => $row->partner_name,
            'store' => $row->store_name,
            'product' => [
                'name' => $row->product_name,
                'variant' => $row->variant_label,
                'more' => max(0, (int) $row->line_count - 1),
            ],
            'quantity' => (int) $row->quantity,
            'status' => $row->status,
            'status_label' => $status->label(),
            'status_tone' => $status->tone(),
            'responsibility' => $row->responsible_party,
            'submitted_at' => CarbonImmutable::parse($row->submitted_at)->toIso8601String(),
            'next_action' => CaseNextAction::offeredTo($viewer, $row->kind, $row->status, $version),
            'waiting_for' => CaseNextAction::waitingOn($row->kind, $row->status, $version),
        ];
    }

    protected function known(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        return ReturnStatus::tryFrom($status) !== null || DamageStatus::tryFrom($status) !== null ? $status : null;
    }

    protected function date(?string $value): ?string
    {
        return $value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    protected function term(?string $search): ?string
    {
        $search = trim((string) $search);

        // A search term is a literal: the wildcards a LIKE gives meaning to are escaped.
        return $search === '' ? null : addcslashes(mb_substr($search, 0, 120), '%_\\');
    }
}
