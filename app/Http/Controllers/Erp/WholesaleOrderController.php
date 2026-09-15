<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Queries\WholesaleOrderTracking;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * An account's own wholesale orders (§10.2, P4-12).
 *
 * **Self-scoped through the signed-in person's own account** (§31.3). There is no
 * account in any URL; an order is found among this account's wholesale orders or
 * not at all, so a reference belonging to anybody else is a 404 rather than a
 * refusal that confirms it exists.
 *
 * Orders stay readable after the package stops including wholesale: an order
 * somebody paid for is theirs to follow whatever they buy next.
 */
class WholesaleOrderController extends Controller
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected WholesaleOrderTracking $tracking,
    ) {}

    public function index(Request $request): Response
    {
        $account = $this->businessAccountFor($request);
        $orders = $this->tracking->paginate($account);

        return Inertia::render('wholesale/orders/index', [
            'orders' => [
                'data' => $orders->getCollection()->map(fn (Order $order) => $this->tracking->summary($order))->all(),
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function show(Request $request, string $order): Response
    {
        $account = $this->businessAccountFor($request);
        $record = $this->tracking->find($account, $order);

        abort_if($record === null, 404);

        return Inertia::render('wholesale/orders/show', [
            'order' => $this->tracking->detail($record),
        ]);
    }
}
