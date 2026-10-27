<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Cases\Actions\StoreCaseEvidence;
use App\Domain\Cases\Queries\AdminCasePayload;
use App\Domain\Cases\Queries\CaseQueue;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Order\Models\OrderReturn;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Returns, exchanges and damages in one operational workspace (D35).
 *
 * One queue for a day's work and one case screen for any of the three, so staff
 * learn one place. What a person sees is decided by what they hold: a kind of
 * case is in the queue, the counts and the filters only if they hold its view
 * permission, and each case screen offers only the next steps they may take.
 *
 * A return placed before the case workflow keeps the desk it had: it is listed,
 * and opening it goes to that page. Nothing here changes a case — every step is
 * a POST to the action controllers, which check the permission and the stage
 * again.
 */
class CaseController extends Controller
{
    public function __construct(
        protected CaseQueue $queue,
        protected AdminCasePayload $payload,
    ) {}

    public function index(Request $request): Response
    {
        $viewer = $this->viewer($request);

        abort_if($this->queue->visibleKinds($viewer) === [], 403);

        $filters = [
            'type' => $request->query('type') === null ? null : (string) $request->query('type'),
            'status' => $request->query('status') === null ? null : (string) $request->query('status'),
            'partner' => $request->query('partner') === null ? null : (string) $request->query('partner'),
            'store' => $request->query('store') === null ? null : (string) $request->query('store'),
            'responsibility' => $request->query('responsibility') === null ? null : (string) $request->query('responsibility'),
            'from' => $request->query('from') === null ? null : (string) $request->query('from'),
            'to' => $request->query('to') === null ? null : (string) $request->query('to'),
            'search' => $request->query('search') === null ? null : (string) $request->query('search'),
        ];

        $page = max(1, (int) $request->query('page', 1));

        return Inertia::render('admin/cases/index', [
            'cases' => $this->queue->page($viewer, $filters, $page),
            'filters' => $filters,
            'counts' => $this->queue->counts($viewer),
            'options' => [
                ...$this->queue->filterOptions($viewer),
                'statuses' => $this->queue->statuses($viewer),
                'kinds' => $this->queue->visibleKinds($viewer),
            ],
        ]);
    }

    public function showReturn(Request $request, string $return): Response|RedirectResponse
    {
        $viewer = $this->viewer($request);

        /** @var OrderReturn $case */
        $case = OrderReturn::query()->where('public_id', $return)->firstOrFail();

        $module = $case->isExchange() ? PermissionModule::Exchange : PermissionModule::ReturnCase;

        abort_unless($viewer->can(PermissionCatalogue::name($module, PermissionAction::View)), 403);

        // A return placed before the case workflow keeps the screen it had.
        if (! $case->usesCaseWorkflow()) {
            return redirect()->route('admin.returns.show', $case->public_id);
        }

        return Inertia::render('admin/cases/show', [
            ...$this->payload->forReturn($viewer, $case),
            'evidence_limits' => $this->limits(),
        ]);
    }

    public function showDamage(Request $request, string $damage): Response
    {
        $viewer = $this->viewer($request);

        /** @var OrderDamage $record */
        $record = OrderDamage::query()->where('public_id', $damage)->firstOrFail();

        abort_unless($viewer->can(PermissionCatalogue::name(PermissionModule::Damage, PermissionAction::View)), 403);

        return Inertia::render('admin/cases/show', [
            ...$this->payload->forDamage($viewer, $record),
            'evidence_limits' => $this->limits(),
        ]);
    }

    /**
     * What the evidence uploader tells the person before they choose a file.
     *
     * @return array{max_bytes: int, max_per_case: int, mime_types: list<string>}
     */
    protected function limits(): array
    {
        $store = app(StoreCaseEvidence::class);

        return [
            'max_bytes' => $store->maxBytes(),
            'max_per_case' => $store->maxPerCase(),
            'mime_types' => array_keys((array) config('case_evidence.mime_types')),
        ];
    }

    protected function viewer(Request $request): User
    {
        $viewer = $request->user();

        abort_if(! $viewer instanceof User, 403);

        return $viewer;
    }
}
