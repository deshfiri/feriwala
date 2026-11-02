<?php

namespace App\Http\Middleware\Mobile;

use App\Domain\Supplier\Models\Supplier;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The mobile counterpart of {@see \App\Http\Middleware\EnsureSupplierIsOperational}
 * (D25, P13-1).
 *
 * The web gate asks the `supplier` session guard, which a bearer-token request
 * never populates, so it cannot be reused. Same question, asked of the token's
 * supplier, answered with JSON: only an approved, un-suspended Supplier may
 * submit listings or reach rates, stock and withdrawals.
 */
class EnsureMobileSupplierIsOperational
{
    public function handle(Request $request, Closure $next): Response
    {
        $supplier = $request->user();

        if ($supplier instanceof Supplier && $supplier->isOperational()) {
            return $next($request);
        }

        return new JsonResponse([
            'code' => 'supplier_not_operational',
            'message' => __('Your Supplier account is not yet approved for this action.'),
        ], Response::HTTP_FORBIDDEN);
    }
}
