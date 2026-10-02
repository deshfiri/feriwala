<?php

namespace App\Domain\Courier\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Courier\Models\Shipment;
use App\Models\User;

/**
 * Who may see and act on shipments (§21, Advanced Order Management batch,
 * Commit 5).
 *
 * `Courier` already carries a full CRUD matrix (`courier.view`,
 * `courier.create`, `courier.edit`, …) from before this batch —
 * {@see manageShipments()} composes the two that matter for actually running
 * one rather than inventing a new verb, the same idiom {@see
 * \App\Domain\Order\Policies\OrderPolicy::allocateSource()} already uses.
 */
class ShipmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionCatalogue::name(PermissionModule::Courier, PermissionAction::View));
    }

    public function view(User $user, Shipment $shipment): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Create a shipment, or advance, cancel or otherwise manage an existing
     * one. Creating is a new decision (`courier.create`); every move after
     * that changes one already made (`courier.edit`) -- both are required
     * for either, since a shipment's whole lifecycle is one continuous
     * responsibility rather than two separately grantable halves.
     */
    public function manageShipments(User $user): bool
    {
        return $user->can(PermissionCatalogue::name(PermissionModule::Courier, PermissionAction::Create))
            && $user->can(PermissionCatalogue::name(PermissionModule::Courier, PermissionAction::Edit));
    }
}
