<?php

namespace App\Support\Navigation;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Models\AccountInvitation;
use App\Models\User;

/**
 * Where a signed-in person belongs (§5.4, D23).
 *
 * One place, because there are three: signing in, being turned away by the
 * §5.4 funnel gate, and finishing registration. Each was answering "/dashboard"
 * on its own, and for a Feriwala staff member that answer is wrong in a way
 * that locks them out entirely — the dashboard is business ERP, the gate sends
 * them to the onboarding stepper, and the stepper refuses somebody with no
 * business account. Three correct-looking steps produced a 403 at the front
 * door.
 *
 * D23 exists precisely so platform staff need no business account. This is what
 * makes that true at the moment they sign in.
 *
 * The order is deliberate: an account first (most people have one), then an
 * invitation waiting to be accepted, then the administrative screens in the
 * order a person holding several would most likely want them, then a screen
 * every identity can reach whatever else is true of them.
 */
class HomeRoute
{
    /**
     * Administrative landing screens, most-wanted first.
     *
     * Each is a route name and the permission that opens it. A staff member is
     * sent to the first they can actually see, rather than to a fixed page half
     * of them would be refused from.
     *
     * @return array<string, array{0: PermissionModule, 1: PermissionAction}>
     */
    protected static function adminLandings(): array
    {
        return [
            'admin.kyc.index' => [PermissionModule::Kyc, PermissionAction::View],
            'admin.activations.index' => [PermissionModule::Account, PermissionAction::View],
            'admin.packages.index' => [PermissionModule::Package, PermissionAction::View],
            'admin.kyc.document-types.index' => [PermissionModule::Kyc, PermissionAction::ManageSettings],
        ];
    }

    /**
     * The route name this person should land on.
     */
    public function nameFor(?User $user): string
    {
        if ($user === null) {
            return 'login';
        }

        if ($user->businessAccount !== null) {
            return 'dashboard';
        }

        if ($this->openInvitationFor($user) !== null) {
            return 'staff.invitation.show';
        }

        foreach (self::adminLandings() as $route => [$module, $action]) {
            if ($user->can(PermissionCatalogue::name($module, $action))) {
                return $route;
            }
        }

        /*
         * Somebody with no business, no invitation and no administrative
         * permission. Rare — a login whose roles have been withdrawn — but
         * "rare" is not "never", and the alternative is the 403 loop this class
         * exists to prevent. Profile is on the §5.4 allow-list, so it opens for
         * any identity.
         */
        return 'profile.edit';
    }

    /**
     * The URL, with whatever parameter that route needs.
     */
    public function urlFor(?User $user): string
    {
        $name = $this->nameFor($user);

        if ($name === 'staff.invitation.show' && $user !== null) {
            $invitation = $this->openInvitationFor($user);

            if ($invitation !== null) {
                return route($name, $invitation->token, absolute: false);
            }

            $name = 'profile.edit';
        }

        return route($name, absolute: false);
    }

    protected function openInvitationFor(User $user): ?AccountInvitation
    {
        return AccountInvitation::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower($user->email)])
            ->live()
            ->orderByDesc('id')
            ->first();
    }
}
