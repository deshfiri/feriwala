<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Actions\EraseAccount;
use App\Domain\Account\Actions\RejectAccountDeletion;
use App\Domain\Account\DeletionAuthority;
use App\Domain\Account\Enums\DeletionRequestStatus;
use App\Domain\Account\Models\AccountDeletionRequest;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Requests to have an account erased, and the decision on each (§5.3).
 *
 * Who sees what follows who may decide it: `account.delete` opens the requests
 * from people and partners, `supplier.delete` those from Suppliers. Approving
 * erases the account — password-confirmed, with the reason and the account's own
 * email or reference typed back — and nobody decides their own.
 */
class DeletionRequestController extends Controller
{
    public function __construct(
        protected DeletionAuthority $authority,
        protected EraseAccount $erase,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $mayPeople = $this->may($actor, PermissionModule::Account);
        $maySuppliers = $this->may($actor, PermissionModule::Supplier);

        abort_unless($mayPeople || $maySuppliers, 403);

        $status = $request->string('status')->toString();
        $search = $request->string('search')->toString();

        $requests = AccountDeletionRequest::query()
            ->with(['user:id,public_id,name,email', 'supplier:id,public_id,business_name,reference'])
            ->where(function ($query) use ($mayPeople, $maySuppliers) {
                $mayPeople && $query->orWhereNotNull('user_id');
                $maySuppliers && $query->orWhereNotNull('supplier_id');
            })
            // Pending by default: the queue is what is waiting.
            ->where('status', in_array($status, array_map(fn (DeletionRequestStatus $s) => $s->value, DeletionRequestStatus::cases()), true) ? $status : DeletionRequestStatus::Pending->value)
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->whereHas('user', fn ($u) => $u->where('name', 'ilike', "%{$search}%")->orWhere('email', 'ilike', "%{$search}%"))
                ->orWhereHas('supplier', fn ($s) => $s->where('business_name', 'ilike', "%{$search}%")->orWhere('reference', 'ilike', "%{$search}%"))))
            ->orderBy('requested_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AccountDeletionRequest $deletion) => $this->row($deletion));

        return Inertia::render('admin/deletions/index', [
            'requests' => $requests,
            'statuses' => array_map(fn (DeletionRequestStatus $s) => ['value' => $s->value, 'label' => __('deletion.status.'.$s->value)], DeletionRequestStatus::cases()),
        ]);
    }

    public function show(Request $request, AccountDeletionRequest $deletionRequest): Response
    {
        $actor = $this->actor($request);
        $this->authoriseView($actor, $deletionRequest);

        $subject = $deletionRequest->subject();
        abort_if($subject === null, 404);

        $refusal = $deletionRequest->isPending() ? $this->authority->refusal($actor, $subject) : __('deletion.admin.already_decided');
        $impact = $deletionRequest->isPending() && $refusal === null ? $this->erase->preview($subject) : null;

        return Inertia::render('admin/deletions/show', [
            'request' => $this->row($deletionRequest) + [
                'reason' => $deletionRequest->reason,
                'decision_note' => $deletionRequest->decision_note,
            ],
            // What the reviewer is asked to type back. It is shown, because the
            // point is to make them look at which account this is.
            'confirmation' => $this->erase->confirmationFor($subject),
            'tombstone' => $this->erase->tombstone($subject),
            'can_decide' => $refusal === null,
            'refusal' => $refusal,
            'impact' => $impact === null ? null : [
                'deleted' => $impact->deleted,
                'anonymized' => $impact->anonymized,
                'files' => count($impact->files),
                'notes' => $impact->notes,
            ],
        ]);
    }

    public function approve(Request $request, AccountDeletionRequest $deletionRequest): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authoriseView($actor, $deletionRequest);

        $subject = $deletionRequest->subject();
        abort_if($subject === null, 404);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:'.EraseAccount::MINIMUM_REASON, 'max:1000'],
            'confirmation' => ['required', 'string', 'max:255'],
        ]);

        if (! $deletionRequest->isPending()) {
            throw ValidationException::withMessages(['confirmation' => __('deletion.admin.already_decided')]);
        }

        try {
            $this->erase->handle($subject, $actor, $validated['reason'], $validated['confirmation'], $deletionRequest);
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['confirmation' => $exception->getMessage()]);
        }

        return to_route('admin.deletions.index')->with('success', __('deletion.admin.erased'));
    }

    public function reject(Request $request, AccountDeletionRequest $deletionRequest, RejectAccountDeletion $reject): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authoriseView($actor, $deletionRequest);

        $validated = $request->validate(['note' => ['required', 'string', 'max:1000']]);

        try {
            $reject->handle($deletionRequest, $actor, $validated['note']);
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['note' => $exception->getMessage()]);
        }

        return to_route('admin.deletions.index')->with('success', __('deletion.admin.rejected'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(AccountDeletionRequest $deletion): array
    {
        $supplier = $deletion->supplier;
        $user = $deletion->user;

        return [
            'id' => $deletion->public_id,
            'kind' => $deletion->isForSupplier() ? 'supplier' : ($user !== null && $this->authority->isPlatformStaff($user) ? 'platform_staff' : ($user !== null && BusinessAccount::query()->where('owner_id', $user->id)->exists() ? 'partner' : 'member')),
            'name' => $supplier instanceof Supplier ? $supplier->business_name : $user?->name,
            'email' => $user instanceof User ? $user->email : $supplier?->reference,
            'status' => $deletion->status->value,
            'status_label' => __('deletion.status.'.$deletion->status->value),
            'requested_at' => $deletion->requested_at->toIso8601String(),
            'waiting_days' => (int) $deletion->requested_at->startOfDay()->diffInDays(now()->startOfDay()),
        ];
    }

    protected function authoriseView(User $actor, AccountDeletionRequest $deletion): void
    {
        abort_unless($this->may($actor, $deletion->isForSupplier() ? PermissionModule::Supplier : PermissionModule::Account), 403);
    }

    protected function may(User $actor, PermissionModule $module): bool
    {
        return $actor->can(PermissionCatalogue::name($module, PermissionAction::Delete));
    }

    protected function actor(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
