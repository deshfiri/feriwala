<?php

namespace App\Http\Middleware;

use App\Domain\Supplier\Models\Supplier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Supplier-domain funnel gate (D25, P13-1).
 *
 * Only an approved, un-suspended Supplier may submit listing requests or
 * reach the operational Supplier screens (rates, stock, approved products).
 * Applied to every such route rather than left to the navigation: hiding a
 * link is not a guard, and a request that reaches the controller by any
 * other path must be refused here regardless of what the client sent.
 */
class EnsureSupplierIsOperational
{
    public function handle(Request $request, Closure $next): Response
    {
        $supplier = $request->user('supplier');

        if (! $supplier instanceof Supplier || ! $supplier->isOperational()) {
            abort(403, __('Your Supplier account is not yet approved for this action.'));
        }

        return $next($request);
    }
}
