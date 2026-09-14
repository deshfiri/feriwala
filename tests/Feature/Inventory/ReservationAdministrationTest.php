<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Actions\OverrideReservation;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\StockReservations;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Reservations, administered (P3-26, contract §6.1.2).
 *
 * What is held, for which reference, until when — and the two overrides the
 * contract allows an authorised person, releasing and extending, each with a
 * reason and written to the audit log. The windows themselves are configurable,
 * bounded, and never retrospective.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::InventoryManager);
    $this->viewer = testPlatformStaff(PlatformRole::ProductManager);

    $category = Category::create(['name' => 'Kitchen']);
    $this->kettle = Product::create(['name' => 'Kettle', 'sku' => 'FW-KT', 'category_id' => $category->id]);
    $warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
    $this->item = StockItem::create(['warehouse_id' => $warehouse->id, 'product_id' => $this->kettle->id]);
    app(StockLedger::class)->move($this->item, null, StockBucket::Available, 20, StockMovementType::Adjustment);

    $reservations = app(StockReservations::class);
    $this->active = $reservations->reserve($this->kettle, null, 3, ReservationKind::OnlinePayment, 'ORD-1');
    $this->committed = $reservations->reserve($this->kettle, null, 2, ReservationKind::CashOnDelivery, 'ORD-2');
    $reservations->commit($this->committed);
});

describe('the reservations screen', function () {
    it('lists what is held, active first, with the windows and the override offered only to those who may', function () {
        $this->actingAs($this->manager)
            ->get(route('admin.inventory.reservations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/inventory/reservations')
                ->has('reservations.data', 2)
                ->where('reservations.data.0.reference', 'ORD-1')
                ->where('reservations.data.0.sku', 'FW-KT')
                ->where('reservations.data.0.warehouse', 'DHK')
                ->where('reservations.data.0.status_label', __('inventory.reservation_statuses.active'))
                ->where('reservations.data.1.status', 'committed')
                ->where('windows.online_minutes', 15)
                ->where('windows.cod_hours', 24)
                ->where('can.override', true));

        $this->actingAs($this->viewer)
            ->get(route('admin.inventory.reservations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.override', false));
    });

    it('filters by status, kind and reference in the database, and ignores values it does not know', function () {
        $filter = fn (array $query) => $this->actingAs($this->manager)->get(route('admin.inventory.reservations.index', $query));

        $filter(['status' => 'committed'])->assertInertia(fn (Assert $page) => $page->has('reservations.data', 1)->where('reservations.data.0.reference', 'ORD-2'));
        $filter(['kind' => 'online_payment'])->assertInertia(fn (Assert $page) => $page->has('reservations.data', 1)->where('reservations.data.0.reference', 'ORD-1'));
        $filter(['search' => 'ord-2'])->assertInertia(fn (Assert $page) => $page->has('reservations.data', 1));
        $filter(['status' => 'lost', 'kind' => 'barter'])->assertInertia(fn (Assert $page) => $page
            ->has('reservations.data', 2)
            ->where('filters.status', null)
            ->where('filters.kind', null));
    });

    it('refuses a partner and staff without inventory', function () {
        $this->actingAs(testBusinessAccount(AccountStatus::Active)->owner)
            ->get(route('admin.inventory.reservations.index'))
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('inventory/forbidden'));

        $this->actingAs(testPlatformStaff(PlatformRole::SmsManager))
            ->get(route('admin.inventory.reservations.index'))
            ->assertForbidden();
    });
});

describe('releasing by hand', function () {
    it('frees the units, records who and why, and writes it to the audit log', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.inventory.reservations.release', $this->active->public_id), ['reason' => 'Customer called to cancel'])
            ->assertSessionHasNoErrors();

        $released = $this->active->fresh();

        expect($released->status)->toBe(StockReservationStatus::Released)
            ->and($released->release_reason)->toBe('Customer called to cancel')
            ->and($released->overridden_by)->toBe($this->manager->id)
            ->and($this->item->refresh()->buckets())->toMatchArray(['available' => 18, 'reserved' => 0, 'processing' => 2])
            ->and(AuditLog::query()
                ->where('action', 'inventory.reservation_released_by_hand')
                ->where('reason', 'Customer called to cancel')
                ->where('is_sensitive', true)
                ->exists())->toBeTrue();
    });

    it('needs a reason, and refuses a reservation that already ended', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.inventory.reservations.release', $this->active->public_id), ['reason' => 'cancel'])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->manager)
            ->post(route('admin.inventory.reservations.release', $this->committed->public_id), ['reason' => 'Customer called to cancel'])
            ->assertSessionHasErrors(['reason' => __('inventory.refused.reservation_ended', ['status' => __('inventory.reservation_statuses.committed')])]);

        expect($this->active->fresh()->status)->toBe(StockReservationStatus::Active)
            ->and($this->item->refresh()->processing)->toBe(2);
    });

    it('refuses staff who may only view, a partner, and a caller that skipped the controller', function () {
        $partner = testBusinessAccount(AccountStatus::Active)->owner;
        $partner->givePermissionTo(['inventory.view', 'inventory.approve']);

        foreach ([$this->viewer, $partner] as $user) {
            $this->actingAs($user)
                ->post(route('admin.inventory.reservations.release', $this->active->public_id), ['reason' => 'Customer called to cancel'])
                ->assertForbidden();
        }

        expect(fn () => app(OverrideReservation::class)->release($this->viewer, $this->active, 'Customer called to cancel'))
            ->toThrow(AuthorizationException::class);

        expect($this->active->fresh()->status)->toBe(StockReservationStatus::Active);
    });
});

describe('extending by hand', function () {
    it('moves the expiry of an active reservation, and audits it', function () {
        $until = CarbonImmutable::now()->addHours(6)->startOfMinute();

        $this->actingAs($this->manager)
            ->patch(route('admin.inventory.reservations.extend', $this->active->public_id), [
                'expires_at' => $until->toIso8601String(),
                'reason' => 'Bank transfer confirmed by phone',
            ])
            ->assertSessionHasNoErrors();

        expect($this->active->fresh()->expires_at->equalTo($until))->toBeTrue()
            ->and($this->active->fresh()->overridden_by)->toBe($this->manager->id)
            ->and(AuditLog::query()->where('action', 'inventory.reservation_extended')->exists())->toBeTrue();
    });

    it('refuses a moment in the past, one beyond a week, and a reservation that already ended', function () {
        $extend = fn (string $reservation, CarbonImmutable $until) => $this->actingAs($this->manager)
            ->patch(route('admin.inventory.reservations.extend', $reservation), [
                'expires_at' => $until->toIso8601String(),
                'reason' => 'Bank transfer confirmed by phone',
            ]);

        $extend($this->active->public_id, CarbonImmutable::now()->subMinute())->assertSessionHasErrors('expires_at');
        $extend($this->active->public_id, CarbonImmutable::now()->addDays(8))->assertSessionHasErrors('expires_at');
        $extend($this->committed->public_id, CarbonImmutable::now()->addHour())
            ->assertSessionHasErrors(['expires_at' => __('inventory.refused.reservation_ended', ['status' => __('inventory.reservation_statuses.committed')])]);
    });
});

describe('the reservation windows', function () {
    it('saves new windows, audits them, and applies them to the next reservation only', function () {
        $originalExpiry = $this->active->expires_at;

        $this->actingAs($this->manager)
            ->put(route('admin.inventory.reservations.windows'), ['online_minutes' => 30, 'cod_hours' => 48])
            ->assertSessionHasNoErrors();

        CarbonImmutable::setTestNow('2026-09-25 10:00:00');
        $next = app(StockReservations::class)->reserve($this->kettle, null, 1, ReservationKind::OnlinePayment, 'ORD-3');
        CarbonImmutable::setTestNow();

        expect($next->expires_at->toDateTimeString())->toBe('2026-09-25 10:30:00')
            ->and($this->active->fresh()->expires_at->equalTo($originalExpiry))->toBeTrue()
            ->and(AuditLog::query()->where('action', 'inventory.reservation_windows_set')->exists())->toBeTrue();
    });

    it('refuses windows outside their bounds, and anybody without approval', function () {
        $this->actingAs($this->manager)
            ->put(route('admin.inventory.reservations.windows'), ['online_minutes' => 1, 'cod_hours' => 500])
            ->assertSessionHasErrors(['online_minutes', 'cod_hours']);

        $this->actingAs($this->viewer)
            ->put(route('admin.inventory.reservations.windows'), ['online_minutes' => 30, 'cod_hours' => 48])
            ->assertForbidden();
    });
});
