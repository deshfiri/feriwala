<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Domain\Sourcing\Policies\ProductSupplierPolicy;
use App\Domain\Sourcing\Queries\ProductLinkSummaries;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every Product, page by page, for the right-hand panels that pick Products:
 * the Product a Supplier listing connects to, the Products a Supplier is
 * assigned, the Products content is released to.
 */
class ProductBrowseController extends Controller
{
    public function __construct(protected ProductLinkSummaries $summaries) {}

    /**
     * Open to anyone who may see the catalogue, assign Suppliers to Products or
     * approve a Supplier listing — the people who make those choices.
     */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();

        abort_unless($actor instanceof User, 403);

        abort_unless(
            ProductSupplierPolicy::canAssign($actor)
            || CatalogPolicy::canViewAny($actor)
            || $actor->can(PermissionCatalogue::name(PermissionModule::SupplierListing, PermissionAction::Approve)),
            403,
        );

        $validated = $request->validate([
            'q' => ['nullable', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($this->summaries->listing((string) ($validated['q'] ?? ''), (int) ($validated['page'] ?? 1)));
    }
}
