<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Supplier\Models\SupplierPayable;
use Throwable;

/**
 * Settle several Supplier payables in one request, each on its own (D25, P13-23).
 *
 * Every payable is processed in {@see SettleSupplierPayable}'s own
 * transaction — never one transaction around the whole batch — so a payable
 * that cannot be settled is reported and skipped rather than rolling back
 * every payable that already succeeded beside it.
 */
class BulkSettleSupplierPayables
{
    public function __construct(
        protected SettleSupplierPayable $settle,
    ) {}

    /**
     * @param  iterable<SupplierPayable>  $payables
     * @return array<int, array{payable_id: int, reference: string, ok: bool, message: ?string}>
     */
    public function handle(iterable $payables, ?int $actorId = null): array
    {
        $results = [];

        foreach ($payables as $payable) {
            try {
                $this->settle->handle($payable, $actorId);

                $results[] = [
                    'payable_id' => $payable->id,
                    'reference' => $payable->reference,
                    'ok' => true,
                    'message' => null,
                ];
            } catch (Throwable $exception) {
                $results[] = [
                    'payable_id' => $payable->id,
                    'reference' => $payable->reference,
                    'ok' => false,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return $results;
    }
}
