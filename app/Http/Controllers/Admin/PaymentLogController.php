<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Billing\PaymentLogRedactor;
use App\Domain\Billing\Policies\BillingSettingsPolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payments, and every exchange with a gateway behind each one (§42, §26.4).
 *
 * The screen somebody opens when the money and the records disagree. It answers
 * three questions in order: which payments need a person, what state is this one
 * in, and what actually happened to it.
 *
 * Payments needing reconciliation are the reason it exists. A verified payment
 * that arrived after its checkout closed activates nothing and refunds nothing
 * on its own — it waits here, and it must not be able to hide among ordinary
 * failures, so it is a filter of its own rather than a colour on a row.
 *
 * Everything shown from `payment_logs` was redacted on the way in. There is
 * nothing here to leak, which is the point.
 */
class PaymentLogController extends Controller
{
    /** Columns the table may sort by. A whitelist, not the parameter itself. */
    protected const SORTABLE = ['created_at', 'amount', 'status'];

    public const PER_PAGE = 25;

    public function __construct(
        protected PaymentLogRedactor $redactor,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        abort_unless(BillingSettingsPolicy::canView($actor), 403);

        $status = $request->string('status')->toString();
        $gateway = $request->string('gateway')->toString();

        $payments = Payment::query()
            ->with(['businessAccount:id,name', 'invoice:id,payment_id,number,public_id'])
            ->when($request->string('search')->toString(), fn (Builder $query, string $search) => $query
                ->where(fn (Builder $inner) => $inner
                    ->where('reference', 'ilike', "%{$search}%")
                    ->orWhere('gateway_reference', 'ilike', "%{$search}%")
                    ->orWhereHas('businessAccount', fn (Builder $account) => $account
                        ->where('name', 'ilike', "%{$search}%"))))
            // "Needs a person" is its own filter rather than one status among
            // twenty: it is the only one where money is sitting unresolved.
            ->when($status === 'reconciliation', fn (Builder $query) => $query
                ->where('status', PaymentStatus::ReconciliationRequired))
            ->when(
                $status !== '' && $status !== 'reconciliation' && PaymentStatus::tryFrom($status) !== null,
                fn (Builder $query) => $query->where('status', $status),
            )
            ->when($gateway !== '', fn (Builder $query) => $query->where('gateway', $gateway))
            ->when(
                in_array($request->string('sort')->toString(), self::SORTABLE, true),
                fn (Builder $query) => $query->reorder(
                    $request->string('sort')->toString(),
                    $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc',
                ),
                fn (Builder $query) => $query->orderByDesc('id'),
            )
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Payment $payment) => $this->summary($payment));

        return Inertia::render('admin/payments/index', [
            'payments' => $payments,
            'statuses' => array_map(fn (PaymentStatus $case) => [
                'value' => $case->value,
                'label' => $case->label(),
            ], PaymentStatus::cases()),
            'gateways' => Payment::query()
                ->whereNotNull('gateway')
                ->distinct()
                ->orderBy('gateway')
                ->pluck('gateway')
                ->all(),
            'filters' => ['status' => $status, 'gateway' => $gateway],
            'needs_reconciliation' => Payment::query()
                ->where('status', PaymentStatus::ReconciliationRequired)
                ->count(),
        ]);
    }

    public function show(Request $request, string $payment): Response
    {
        $actor = $this->actor($request);

        abort_unless(BillingSettingsPolicy::canView($actor), 403);

        $record = Payment::query()
            ->with(['businessAccount:id,name,public_id', 'invoice', 'allocations'])
            ->where('public_id', $payment)
            ->firstOrFail();

        $logs = PaymentLog::query()
            ->where('payment_id', $record->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(function (PaymentLog $entry) {
                /*
                 * Redacted once on the way in and again on the way out, and the
                 * second pass is not the same as the first: the stored row keeps
                 * a secret's field name carrying `[redacted]`, so an
                 * investigator can tell "no signature was sent" from "the
                 * signature was stripped". A browser gets neither the value nor
                 * the name — a rendered page ends up in tickets, screenshots and
                 * chat threads, and travels further than the row it came from.
                 */
                $payload = $this->redactor->forBrowser($entry->context ?? []);

                return [
                    'id' => $entry->public_id,
                    'direction' => $entry->direction,
                    'event' => $entry->event,
                    'outcome' => $entry->outcome,
                    'http_status' => $entry->http_status,
                    'gateway_reference' => $entry->gateway_reference,
                    'ip_address' => $entry->ip_address,
                    'at' => $entry->created_at->toIso8601String(),

                    'context' => $payload['fields'],

                    // Said out loud, so a withheld field reads as withheld
                    // rather than as a gateway that never sent one.
                    'withheld' => $payload['withheld'],
                ];
            })
            ->all();

        return Inertia::render('admin/payments/show', [
            'payment' => array_merge($this->summary($record), [
                'purpose_label' => $record->purpose->label(),
                // Formatted server-side, like every other amount (§36.1).
                'settled_amount' => $record->settled_amount?->jsonSerialize(),
                'initiated_at' => $record->initiated_at?->toIso8601String(),
                'expires_at' => $record->expires_at?->toIso8601String(),
                'failed_at' => $record->failed_at?->toIso8601String(),
                'cancelled_at' => $record->cancelled_at?->toIso8601String(),
                'failure_reason' => $record->failure_reason,
                'reconciliation_reason' => $record->reconciliation_reason,
                'reconciliation_required_at' => $record->reconciliation_required_at?->toIso8601String(),
            ]),
            'logs' => $logs,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(Payment $payment): array
    {
        return [
            'id' => $payment->public_id,
            'reference' => $payment->reference,
            'account' => $payment->businessAccount?->name,
            'gateway' => $payment->gateway,

            // The provider's own transaction. The thing to quote at a gateway's
            // support desk, and the thing to search for when they quote it back.
            'gateway_reference' => $payment->gateway_reference,

            'amount' => $payment->amount->jsonSerialize(),
            'currency' => $payment->amount->currency->value,
            'status' => $payment->status->value,
            'status_label' => $payment->status->label(),
            'status_tone' => $payment->status->tone(),
            'needs_reconciliation' => $payment->needsReconciliation(),
            'invoice_number' => $payment->invoice?->number,
            'created_at' => $payment->created_at?->toIso8601String(),
            'completed_at' => $payment->completed_at?->toIso8601String(),
        ];
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
