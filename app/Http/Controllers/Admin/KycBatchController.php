<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Kyc\Actions\ManageKycBatches;
use App\Domain\Kyc\Models\KycBatch;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kyc\SaveKycBatchRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Verification batches (§7.2): create, change, pause, archive, delete, reorder.
 *
 * The screen they live on is the criteria screen, which lists batches with the
 * criteria inside them ({@see KycDocumentTypeController::index()}). Batches use
 * the same permission as criteria — `kyc.manage_settings` — because changing
 * what a batch holds and changing who it applies to are one decision.
 */
class KycBatchController extends Controller
{
    public function store(SaveKycBatchRequest $request, ManageKycBatches $manage): RedirectResponse
    {
        Gate::authorize('create', KycDocumentType::class);

        $manage->create($request->batchAttributes(), $request->ruleRows(), $this->actor($request));

        return back()->with('success', __('Batch created.'));
    }

    public function update(SaveKycBatchRequest $request, KycBatch $batch, ManageKycBatches $manage): RedirectResponse
    {
        Gate::authorize('create', KycDocumentType::class);

        try {
            $manage->update($batch, $request->batchAttributes(), $request->ruleRows(), $this->actor($request));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }

        return back()->with('success', __('Batch updated.'));
    }

    public function setActive(Request $request, KycBatch $batch, ManageKycBatches $manage): RedirectResponse
    {
        Gate::authorize('create', KycDocumentType::class);

        $validated = $request->validate(['is_active' => ['required', 'boolean']]);

        try {
            $manage->setActive($batch, (bool) $validated['is_active'], $this->actor($request));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['is_active' => $exception->getMessage()]);
        }

        return back()->with('success', __('Batch updated.'));
    }

    public function archive(Request $request, KycBatch $batch, ManageKycBatches $manage): RedirectResponse
    {
        Gate::authorize('create', KycDocumentType::class);

        $manage->archive($batch, $this->actor($request));

        return back()->with('success', __('Batch archived.'));
    }

    public function destroy(Request $request, KycBatch $batch, ManageKycBatches $manage): RedirectResponse
    {
        Gate::authorize('create', KycDocumentType::class);

        try {
            $manage->delete($batch, $this->actor($request));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['batch' => $exception->getMessage()]);
        }

        return back()->with('success', __('Batch deleted.'));
    }

    public function reorder(Request $request, ManageKycBatches $manage): RedirectResponse
    {
        Gate::authorize('create', KycDocumentType::class);

        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['required', 'string'],
        ]);

        $manage->reorder($validated['order'], $this->actor($request));

        return back()->with('success', __('Order saved.'));
    }

    protected function actor(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
