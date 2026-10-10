<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\ConfigureProductDeletion;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Domain\Catalog\ProductDeletionRule;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Which product statuses may be deleted: every status, or drafts only.
 *
 * Seen by anyone who may open the catalogue; changed only with
 * `catalog.manage_settings` (administrators, and anyone a role grants it to).
 */
class ProductDeletionSettingsController extends Controller
{
    public function __construct(
        protected ProductDeletionRule $rule,
        protected ConfigureProductDeletion $configure,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canViewAny($actor), 403);

        return Inertia::render('admin/product-deletion-settings', [
            'settings' => ['scope' => $this->rule->scope()],
            'scopes' => ProductDeletionRule::SCOPES,
            'can' => ['manage' => CatalogPolicy::canManageSettings($actor)],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canManageSettings($actor), 403);

        $validated = $request->validate([
            'scope' => ['required', Rule::in(ProductDeletionRule::SCOPES)],
        ]);

        $this->configure->handle($actor, $validated['scope']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('product_deletion_settings.saved')]);

        return back();
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
