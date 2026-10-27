<?php

namespace App\Domain\Cases;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Models\User;

/**
 * What a case is waiting for, and who may do it (D35).
 *
 * One place says, for a kind of case at a stage, **the single next step and the
 * permission it needs** — so the queue's "required action" column, the case
 * header and the action buttons all agree, and none of them offers a step the
 * server would refuse. A step the viewer may not take is still named (somebody
 * has to), but is only offered to those who hold its permission.
 *
 * The server stays authoritative: every action re-checks the permission and the
 * stage itself. This is how screens choose what to show, never what is allowed.
 */
class CaseNextAction
{
    /**
     * @param  string  $kind  `return`, `exchange` or `damage`
     * @return array{key: string, permission: string}|null
     */
    public static function for(string $kind, string $status, int $workflowVersion = 2): ?array
    {
        // A return placed before the case workflow keeps the desk it had.
        if ($kind !== 'damage' && $workflowVersion < 2) {
            return null;
        }

        $name = fn (PermissionModule $module, PermissionAction $action) => PermissionCatalogue::name($module, $action);

        if ($kind === 'damage') {
            return match ($status) {
                'reported', 'awaiting_receipt' => ['key' => 'receive_parcel', 'permission' => $name(PermissionModule::Damage, PermissionAction::Inspect)],
                'received' => ['key' => 'start_inspection', 'permission' => $name(PermissionModule::Damage, PermissionAction::Inspect)],
                'under_inspection' => ['key' => 'inspect', 'permission' => $name(PermissionModule::Damage, PermissionAction::Inspect)],
                'liability_review' => ['key' => 'assign_liability', 'permission' => $name(PermissionModule::Damage, PermissionAction::AssignLiability)],
                'financial_posting_pending' => ['key' => 'finalize', 'permission' => $name(PermissionModule::Damage, PermissionAction::Finalize)],
                'dispatch_requested' => ['key' => 'dispatch', 'permission' => $name(PermissionModule::Damage, PermissionAction::Dispatch)],
                'dispatched' => ['key' => 'confirm_receipt', 'permission' => $name(PermissionModule::Damage, PermissionAction::Dispatch)],
                default => null,
            };
        }

        $reviewModule = $kind === 'exchange' ? PermissionModule::Exchange : PermissionModule::ReturnCase;

        return match ($status) {
            'requested' => ['key' => 'review', 'permission' => $name($reviewModule, PermissionAction::Review)],
            'waiting_for_parcel' => ['key' => 'receive_parcel', 'permission' => $name(PermissionModule::ReturnCase, PermissionAction::Receive)],
            'parcel_received' => ['key' => 'start_inspection', 'permission' => $name(PermissionModule::ReturnCase, PermissionAction::Inspect)],
            'under_inspection' => ['key' => 'inspect', 'permission' => $name(PermissionModule::ReturnCase, PermissionAction::Inspect)],
            'inspection_approved' => ['key' => 'settle', 'permission' => $name(PermissionModule::ReturnCase, PermissionAction::Refund)],
            'settlement_pending' => ['key' => 'finish_refund', 'permission' => $name(PermissionModule::ReturnCase, PermissionAction::Refund)],
            default => null,
        };
    }

    /**
     * The next step, only if `$viewer` may take it.
     *
     * @return array{key: string, label: string}|null
     */
    public static function offeredTo(User $viewer, string $kind, string $status, int $workflowVersion = 2): ?array
    {
        $next = self::for($kind, $status, $workflowVersion);

        if ($next === null || ! $viewer->can($next['permission'])) {
            return null;
        }

        return ['key' => $next['key'], 'label' => (string) __('cases.next.'.$next['key'])];
    }

    /**
     * Who the case is waiting for, in words, when it is not the viewer.
     */
    public static function waitingOn(string $kind, string $status, int $workflowVersion = 2): ?string
    {
        $next = self::for($kind, $status, $workflowVersion);

        return $next === null ? null : (string) __('cases.next.'.$next['key']);
    }
}
