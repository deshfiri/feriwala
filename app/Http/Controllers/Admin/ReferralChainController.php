<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Referral\Actions\AttachReferrer;
use App\Domain\Referral\Exceptions\ReferralRefused;
use App\Domain\Referral\Queries\ReferralHierarchy;
use App\Domain\Referral\Queries\ReferralRecords;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inspecting one account's place in the referral hierarchy (D24, P7-44).
 *
 * Seen with `referral.view`: the account's referrer, its chain upward and its
 * direct referrals. Attaching or correcting a referrer needs
 * `referral.edit`, a reason, and a link no qualifying event has locked.
 */
class ReferralChainController extends Controller
{
    public function show(Request $request, ReferralRecords $records, ReferralHierarchy $hierarchy): Response
    {
        Gate::authorize(PermissionCatalogue::name(PermissionModule::Referral, PermissionAction::View));

        $query = trim($request->string('account')->toString());

        $matches = $query === '' ? collect() : BusinessAccount::query()
            ->where(fn ($either) => $either
                ->where('public_id', $query)
                ->orWhere('name', 'ilike', '%'.addcslashes($query, '%_\\').'%'))
            ->orderBy('name')
            ->limit(10)
            ->get();

        $selected = $matches->firstWhere('public_id', $query) ?? ($matches->count() === 1 ? $matches->first() : null);

        return Inertia::render('admin/referral-chains', [
            'query' => $query,
            'matches' => $selected !== null ? [] : $matches->map(fn (BusinessAccount $account) => [
                'id' => $account->public_id,
                'name' => $account->name,
                'status_label' => $account->status->label(),
            ])->all(),
            'chain' => $selected === null ? null : $records->chain($selected, $hierarchy),
            'can_attach' => $request->user()?->can(PermissionCatalogue::name(PermissionModule::Referral, PermissionAction::Edit)) ?? false,
        ]);
    }

    public function attach(Request $request, string $account, AttachReferrer $attach): RedirectResponse
    {
        Gate::authorize(PermissionCatalogue::name(PermissionModule::Referral, PermissionAction::Edit));

        $validated = $request->validate([
            'referrer' => ['required', 'string', 'size:26'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $referred = BusinessAccount::query()->where('public_id', $account)->first();
        abort_if($referred === null, 404);

        $referrer = BusinessAccount::query()->where('public_id', $validated['referrer'])->first();

        if ($referrer === null) {
            throw ValidationException::withMessages(['referrer' => __('referral.refused.account_not_found')]);
        }

        $actor = $request->user();
        abort_if(! $actor instanceof User, 403);

        try {
            $attach->byStaff($referred, $referrer, $actor, $validated['reason']);
        } catch (ReferralRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('referral.flash.referrer_attached')]);

        return to_route('admin.referral-chains.show', ['account' => $referred->public_id]);
    }
}
