<?php

namespace App\Domain\Cases\Queries;

use App\Domain\Cases\Models\CaseEvidence;
use App\Domain\Damage\Models\DamageResponsibilityChange;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Damage\Models\OrderDamageStatusChange;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Models\OrderReturnStatusChange;
use App\Domain\Order\Models\ReturnResponsibilityChange;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * One timeline for every kind of case (D35), so a return, an exchange and a
 * damage read the same way.
 *
 * Each entry says **what happened, who did it by kind of party, when, why, and —
 * where it was a move — from what to what**. The same entries come from the status
 * history, the attribution history, the charges and the evidence that was added,
 * merged and put in time order.
 *
 * **Two readers.**
 *   - **Staff** see everything: the reason given, the internal note, and who by
 *     name.
 *   - **A Partner** sees only the notes written for them (`public_note`), by kind
 *     of party and never by name, and never the reason a member of staff gave for
 *     a decision about who bears a loss. Evidence and charges show as events with
 *     no staff words in them.
 */
class CaseTimeline
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forReturn(OrderReturn $case, bool $internal): array
    {
        $case->loadMissing(['statusHistory.changedBy:id,name', 'responsibilityChanges.changedBy:id,name', 'charges', 'evidenceFiles']);

        $entries = $case->statusHistory->map(fn (OrderReturnStatusChange $change) => $this->status(
            'status',
            $change->new_status->label(),
            $change->previous_status?->label(),
            $change->new_status->label(),
            $change->source,
            $change->changedBy?->name,
            $change->changed_at,
            $change->reason,
            $change->internal_note,
            $change->public_note,
            $internal,
        ));

        $entries = $entries->merge($case->responsibilityChanges->map(fn (ReturnResponsibilityChange $change) => $this->responsibility(
            $change->previous_party?->label(), $change->new_party->label(), $change->changedBy?->name, $change->changed_at, $change->reason, $internal,
        )));

        $entries = $entries->merge($case->charges->map(fn ($charge) => $this->charge($charge->kind->value, $charge->isReversal(), $charge->posted_at, $internal)));

        return $this->finish($entries->merge($case->evidenceFiles->map(fn (CaseEvidence $evidence) => $this->evidence($evidence, $internal))), $internal);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forDamage(OrderDamage $damage, bool $internal): array
    {
        $damage->loadMissing(['statusHistory.changedBy:id,name', 'responsibilityChanges.changedByUser:id,name', 'charges', 'evidenceFiles']);

        $entries = $damage->statusHistory->map(fn (OrderDamageStatusChange $change) => $this->status(
            'status',
            $change->new_status->label(),
            $change->previous_status?->label(),
            $change->new_status->label(),
            $change->source,
            $change->changedBy?->name,
            $change->changed_at,
            $change->reason,
            $change->internal_note,
            $change->public_note,
            $internal,
        ));

        $entries = $entries->merge($damage->responsibilityChanges->map(fn (DamageResponsibilityChange $change) => $this->responsibility(
            $change->previous_party?->label(), $change->new_party->label(), $change->changedByUser?->name, $change->changed_at, $change->reason, $internal,
        )));

        $entries = $entries->merge($damage->charges->map(fn ($charge) => $this->charge($charge->kind->value, $charge->isReversal(), $charge->posted_at, $internal)));

        return $this->finish($entries->merge($damage->evidenceFiles->map(fn (CaseEvidence $evidence) => $this->evidence($evidence, $internal))), $internal);
    }

    /**
     * @param  Collection<int, array<string, mixed>|null>  $entries
     * @return list<array<string, mixed>>
     */
    protected function finish(Collection $entries, bool $internal): array
    {
        return array_values($entries
            ->filter()
            // A Partner is shown only what was written for them.
            ->when(! $internal, fn (Collection $kept) => $kept->filter(fn (array $entry) => $entry['note'] !== null || $entry['kind'] !== 'status'))
            ->sortBy([['at', 'asc'], ['order', 'asc']])
            ->values()
            ->map(function (array $entry, int $index) {
                unset($entry['order']);

                return ['key' => $index, ...$entry];
            })
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    protected function status(string $kind, string $label, ?string $previous, ?string $new, OrderStatusChangeSource $source, ?string $actor, CarbonInterface $at, ?string $reason, ?string $internalNote, ?string $publicNote, bool $internal): array
    {
        $public = $publicNote === null ? null : (string) __($publicNote);

        return [
            'kind' => $kind,
            'event' => $label,
            'actor_type' => $this->actorType($source),
            'actor' => $internal ? $actor : null,
            'at' => $at->toIso8601String(),
            'previous' => $previous,
            'next' => $new,
            // Staff read the reason and the internal note; a Partner, only the public note.
            'note' => $internal ? $this->join([$reason, $internalNote, $public]) : $public,
            'order' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function responsibility(?string $previous, string $new, ?string $actor, CarbonInterface $at, string $reason, bool $internal): array
    {
        return [
            'kind' => 'responsibility',
            'event' => (string) __('cases.timeline.responsibility'),
            'actor_type' => 'staff',
            'actor' => $internal ? $actor : null,
            'at' => $at->toIso8601String(),
            'previous' => $previous,
            'next' => $new,
            'note' => $internal ? $reason : null,
            'order' => 1,
        ];
    }

    /**
     * A charge that has not been posted has no moment to sit at, and is not an event yet.
     *
     * @return array<string, mixed>|null
     */
    protected function charge(string $kind, bool $reversal, ?CarbonInterface $at, bool $internal): ?array
    {
        if ($at === null) {
            return null;
        }

        return [
            'kind' => 'charge',
            'event' => (string) __('cases.financials.ledger.'.$kind.'.'.($reversal ? 'reversal' : 'charge')),
            'actor_type' => 'staff',
            'actor' => null,
            'at' => $at->toIso8601String(),
            'previous' => null,
            'next' => null,
            'note' => null,
            'order' => 2,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function evidence(CaseEvidence $evidence, bool $internal): ?array
    {
        if ($evidence->isDraft()) {
            return null;
        }

        return [
            'kind' => 'evidence',
            'event' => (string) __('cases.timeline.evidence_added', ['purpose' => $evidence->purpose->label()]),
            'actor_type' => $evidence->uploader_type,
            'actor' => null,
            'at' => ($evidence->submitted_at ?? $evidence->uploaded_at)->toIso8601String(),
            'previous' => null,
            'next' => null,
            'note' => null,
            'order' => 3,
        ];
    }

    /**
     * The kind of party that moved it — never a name for a Partner to read.
     */
    protected function actorType(OrderStatusChangeSource $source): string
    {
        return match ($source) {
            OrderStatusChangeSource::Staff => 'staff',
            OrderStatusChangeSource::Account => 'partner',
            OrderStatusChangeSource::Storefront, OrderStatusChangeSource::Checkout => 'customer',
            default => 'system',
        };
    }

    /**
     * @param  array<int, string|null>  $parts
     */
    protected function join(array $parts): ?string
    {
        $parts = array_values(array_filter($parts, fn (?string $part) => $part !== null && trim($part) !== ''));

        return $parts === [] ? null : implode(' — ', $parts);
    }
}
