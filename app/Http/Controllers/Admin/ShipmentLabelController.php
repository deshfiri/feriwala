<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Courier\Actions\IssueShipmentLabel;
use App\Domain\Courier\Actions\ReissueShipmentLabel;
use App\Domain\Courier\Exceptions\ShipmentLabelRefused;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Courier\Models\ShipmentLabel;
use App\Http\Controllers\Concerns\RendersShipmentLabels;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Staff printing, issuing and correcting shipping labels.
 *
 * Printing is anyone who may see shipments (`courier.view`); issuing and
 * correcting are the shipment managers (`courier.create` + `courier.edit`).
 * Nothing here calls a courier: a label is read from, and issued from, Banij's
 * own frozen records.
 */
class ShipmentLabelController extends Controller
{
    use RendersShipmentLabels;

    /** The most labels one batch prints. */
    public const BATCH_LIMIT = 100;

    /**
     * One parcel's current label, or an earlier version by number.
     */
    public function show(Request $request, string $shipment): Response
    {
        Gate::forUser($this->actor($request))->authorize('viewAny', Shipment::class);

        $record = $this->shipment($shipment);
        $version = $request->integer('version');

        /** @var ShipmentLabel|null $label */
        $label = ShipmentLabel::query()
            ->where('shipment_id', $record->id)
            ->when($version > 0, fn ($query) => $query->where('version', $version), fn ($query) => $query->whereNull('superseded_at'))
            ->first();

        abort_if($label === null, 404);

        return $this->renderLabels($request, [$label], 'label-'.$record->reference);
    }

    /**
     * Several parcels' labels, one page each. Each label carries its own
     * Storefront's frozen branding, and they are grouped by Storefront so a
     * stack of printed labels never interleaves two stores.
     */
    public function batch(Request $request): Response
    {
        Gate::forUser($this->actor($request))->authorize('viewAny', Shipment::class);

        $validated = $request->validate([
            'shipments' => ['required', 'array', 'min:1', 'max:'.self::BATCH_LIMIT],
            'shipments.*' => ['string', 'max:32'],
        ]);

        $shipments = Shipment::query()
            ->whereIn('public_id', array_values(array_unique($validated['shipments'])))
            ->with('currentLabel')
            ->get();

        $missing = $shipments->filter(fn (Shipment $shipment) => $shipment->currentLabel === null)
            ->map(fn (Shipment $shipment) => $shipment->reference)
            ->values()
            ->all();

        $labels = $shipments->map(fn (Shipment $shipment) => $shipment->currentLabel)
            ->filter()
            ->sortBy(fn (ShipmentLabel $label) => [$label->website_id, $label->shipment_id])
            ->values();

        abort_if($labels->isEmpty(), 404);

        return $this->renderLabels($request, $labels, 'labels-'.now()->format('Ymd-His'), $missing);
    }

    /**
     * Issue -- or retry issuing -- a parcel's first label.
     */
    public function store(Request $request, string $shipment, IssueShipmentLabel $issue): RedirectResponse
    {
        $actor = $this->actor($request);

        Gate::forUser($actor)->authorize('manageShipments', Shipment::class);

        try {
            $issue->handle($this->shipment($shipment), $actor);
        } catch (ShipmentLabelRefused $refused) {
            throw ValidationException::withMessages(['label' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('shipping_label.flash.issued')]);

        return back();
    }

    /**
     * "Regenerate corrected label": a new version with a reason; the current
     * one is kept, marked as replaced.
     */
    public function reissue(Request $request, string $shipment, ReissueShipmentLabel $reissue): RedirectResponse
    {
        $actor = $this->actor($request);

        Gate::forUser($actor)->authorize('manageShipments', Shipment::class);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $label = $reissue->handle($this->shipment($shipment), $actor, $validated['reason']);
        } catch (ShipmentLabelRefused $refused) {
            throw ValidationException::withMessages(['label' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('shipping_label.flash.reissued', ['version' => $label->version])]);

        return back();
    }

    protected function shipment(string $publicId): Shipment
    {
        /** @var Shipment $shipment */
        $shipment = Shipment::query()->where('public_id', $publicId)->firstOrFail();

        return $shipment;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
