<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier;

use App\Domain\Supplier\Models\Supplier;
use Illuminate\Http\Request;

/**
 * The Supplier behind a mobile token.
 *
 * `mobile.access:supplier` has already refused every other kind of caller, so
 * the token's owner is a Supplier. It is read from the request, never from the
 * `supplier` session guard, which a bearer-token request leaves empty.
 */
trait ResolvesSupplier
{
    protected function supplierFor(Request $request): Supplier
    {
        /** @var Supplier $supplier */
        $supplier = $request->user();

        return $supplier;
    }
}
