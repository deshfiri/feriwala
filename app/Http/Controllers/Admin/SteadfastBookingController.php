<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Courier\Actions\BookSteadfastShipment;
use App\Domain\Courier\Actions\MarkSteadfastBookingNotBooked;
use App\Domain\Courier\Actions\ReconcileSteadfastBooking;
use App\Domain\Courier\Actions\SynchroniseSteadfastShipment;
use App\Domain\Courier\Enums\CourierBookingStatus;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Exceptions\CourierBookingRefused;
use App\Domain\Courier\Exceptions\CourierProviderUnavailable;
use App\Domain\Courier\Models\CourierBookingAttempt;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Order\Models\Order;
use App\Http\Controllers\Controller;
use App\Integrations\Courier\Steadfast\SteadfastException;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Booking a Store Order's parcel with Steadfast, and settling a booking whose
 * outcome is not known.
 *
 * Authorised like every shipment action (`courier.create` and `courier.edit`).
 * Nothing here reads or returns Steadfast's raw answers: the toast carries fixed,
 * translated text.
 */
class SteadfastBookingController extends Controller
{
    public function store(Request $request, string $order, BookSteadfastShipment $book): RedirectResponse
    {
        $actor = $this->actor($request);

        Gate::forUser($actor)->authorize('manageShipments', Shipment::class);

        /** @var Order $record */
        $record = Order::query()->where('public_id', $order)->firstOrFail();

        try {
            $attempt = $book->handle($record, $actor);
        } catch (CourierBookingRefused|CourierProviderUnavailable $exception) {
            throw ValidationException::withMessages(['steadfast' => $exception->getMessage()]);
        }

        $this->announce($attempt);

        return back();
    }

    public function reconcile(Request $request, string $attempt, ReconcileSteadfastBooking $reconcile): RedirectResponse
    {
        $actor = $this->actor($request);

        Gate::forUser($actor)->authorize('manageShipments', Shipment::class);

        $this->announce($reconcile->handle($this->attempt($attempt), $actor));

        return back();
    }

    /**
     * "Check courier status now" for one parcel.
     */
    public function sync(Request $request, string $shipment, SynchroniseSteadfastShipment $synchronise): RedirectResponse
    {
        $actor = $this->actor($request);

        Gate::forUser($actor)->authorize('manageShipments', Shipment::class);

        /** @var Shipment $record */
        $record = Shipment::query()->where('public_id', $shipment)->firstOrFail();

        abort_unless($record->courierProvider->code === CourierProviderCode::Steadfast, 404);

        try {
            $applied = $synchronise->handle($record);
        } catch (SteadfastException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('steadfast.sync.failed', ['reason' => $exception->kind->message()])]);

            return back();
        }

        Inertia::flash('toast', match (true) {
            $applied === null => ['type' => 'warning', 'message' => __('steadfast.sync.not_found')],
            $applied->changed => ['type' => 'success', 'message' => __('steadfast.sync.updated')],
            default => ['type' => 'info', 'message' => __('steadfast.sync.unchanged')],
        });

        return back();
    }

    public function markNotBooked(Request $request, string $attempt, MarkSteadfastBookingNotBooked $mark): RedirectResponse
    {
        $actor = $this->actor($request);

        Gate::forUser($actor)->authorize('manageShipments', Shipment::class);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $mark->handle($this->attempt($attempt), $actor, $validated['reason']);
        } catch (CourierBookingRefused $exception) {
            throw ValidationException::withMessages(['steadfast' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('courier.booking.flash.not_booked')]);

        return back();
    }

    protected function announce(CourierBookingAttempt $attempt): void
    {
        Inertia::flash('toast', match ($attempt->status) {
            CourierBookingStatus::Booked => ['type' => 'success', 'message' => __('courier.booking.flash.booked')],
            CourierBookingStatus::Uncertain => ['type' => 'warning', 'message' => __('courier.booking.flash.uncertain')],
            CourierBookingStatus::Failed => ['type' => 'error', 'message' => __('courier.booking.flash.failed', ['reason' => (string) $attempt->failure_message])],
            CourierBookingStatus::Pending => ['type' => 'info', 'message' => __('courier.booking.flash.pending')],
        });
    }

    protected function attempt(string $publicId): CourierBookingAttempt
    {
        /** @var CourierBookingAttempt $attempt */
        $attempt = CourierBookingAttempt::query()->where('public_id', $publicId)->firstOrFail();

        return $attempt;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
