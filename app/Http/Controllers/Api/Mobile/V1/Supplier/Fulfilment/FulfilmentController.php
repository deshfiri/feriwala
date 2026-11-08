<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\Fulfilment;

use App\Domain\Supplier\Actions\AdvanceSupplierFulfilmentCommitment;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierFulfilmentCommitment;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Controllers\Api\Mobile\V1\Supplier\ResolvesSupplier;
use App\Http\Requests\Mobile\Supplier\Fulfilment\AdvanceFulfilmentRequest;
use App\Http\Resources\Mobile\Supplier\Fulfilment\SupplierFulfilmentDetailResource;
use App\Http\Resources\Mobile\Supplier\Fulfilment\SupplierFulfilmentResource;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A Supplier's own fulfilment commitments.
 *
 * Reached through the signed-in Supplier's own offers, so another Supplier's
 * commitment is a `404` and can never be acted on. The moves are exactly the
 * web's: the same action, the same legal transitions.
 */
class FulfilmentController extends MobileController
{
    use ResolvesSupplier;

    public function __construct(protected AdvanceSupplierFulfilmentCommitment $advance) {}

    /**
     * Fulfilment commitments
     *
     * The supplier's commitments, newest first, 20 to a page. `available_actions`
     * lists the moves the supplier may make now.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $supplier = $this->supplierFor($request);

        return SupplierFulfilmentResource::collection(
            SupplierFulfilmentCommitment::query()
                ->whereHas('offer', fn ($query) => $query->where('supplier_id', $supplier->id))
                ->with(['offer', 'allocation.orderItem'])
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString(),
        );
    }

    /**
     * Fulfilment commitment detail
     *
     * One commitment with its status history. `404` for another supplier's.
     */
    public function show(Request $request, string $commitment): SupplierFulfilmentDetailResource
    {
        $model = $this->commitmentFor($this->supplierFor($request), $commitment, ['offer', 'allocation.orderItem', 'statusHistory']);

        return new SupplierFulfilmentDetailResource($model);
    }

    /**
     * Advance a commitment
     *
     * `confirm`, `decline`, `start_preparing` or `mark_ready`. Decline requires a
     * `reason` and cancels the commitment; a supplier never marks one failed.
     * `422` on `action` when the move is not legal from the current status.
     * `404` for another supplier's commitment. Returns the updated commitment.
     */
    public function advance(AdvanceFulfilmentRequest $request, string $commitment): SupplierFulfilmentDetailResource
    {
        $model = $this->commitmentFor($this->supplierFor($request), $commitment, ['offer']);

        $validated = $request->validated();

        $expectedReadyAt = isset($validated['expected_ready_at'])
            ? CarbonImmutable::parse($validated['expected_ready_at'])
            : null;

        try {
            match ($validated['action']) {
                'confirm' => $this->advance->confirm($model, null, SupplierStatusChangeSource::Supplier, $expectedReadyAt),
                'decline' => $this->advance->cancel($model, null, $validated['reason'], SupplierStatusChangeSource::Supplier),
                'start_preparing' => $this->advance->startPreparing($model, null, SupplierStatusChangeSource::Supplier),
                'mark_ready' => $this->advance->markReady($model, null, SupplierStatusChangeSource::Supplier),
                default => throw new InvalidArgumentException("Unknown fulfilment commitment action [{$validated['action']}]."),
            };
        } catch (InvalidArgumentException|IllegalStateTransition $exception) {
            throw ValidationException::withMessages(['action' => $exception->getMessage()]);
        }

        return new SupplierFulfilmentDetailResource(
            $this->commitmentFor($this->supplierFor($request), $commitment, ['offer', 'allocation.orderItem', 'statusHistory']),
        );
    }

    /**
     * @param  array<int, string>  $with
     */
    protected function commitmentFor(Supplier $supplier, string $publicId, array $with = []): SupplierFulfilmentCommitment
    {
        /** @var SupplierFulfilmentCommitment $model */
        $model = SupplierFulfilmentCommitment::query()
            ->whereHas('offer', fn ($query) => $query->where('supplier_id', $supplier->id))
            ->where('public_id', $publicId)
            ->with($with)
            ->firstOrFail();

        return $model;
    }
}
