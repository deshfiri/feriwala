<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Enums\AccountPermission;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Order\Queries\CustomerDirectory;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The business's customers, store by store (D29).
 *
 * Read-only, behind `order:view`. Reached only through the account's own
 * websites and stores; another business's customer is not found.
 */
class CustomerController extends Controller
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected CustomerDirectory $customers,
    ) {}

    public function index(Request $request): Response
    {
        $account = $this->readerAccount($request);
        $search = trim($request->string('search')->toString());
        $source = $request->string('source')->toString();
        $store = trim($request->string('store')->toString());

        $filters = [
            'search' => $search === '' ? null : mb_substr($search, 0, 120),
            'source' => in_array($source, ['website', 'external'], true) ? $source : null,
            'store' => $store === '' ? null : mb_substr($store, 0, 64),
        ];

        return Inertia::render('customers/index', [
            'customers' => $this->customers->paginate($account, $filters),
            'filters' => $filters,
            'stores' => $this->customers->storeOptions($account),
        ]);
    }

    public function show(Request $request, string $source, string $customer): Response
    {
        $detail = $this->customers->detail($this->readerAccount($request), $source, $customer);

        abort_if($detail === null, 404);

        return Inertia::render('customers/show', ['customer' => $detail]);
    }

    protected function readerAccount(Request $request): BusinessAccount
    {
        $account = $this->businessAccountFor($request);

        $user = $request->user();

        abort_unless($user instanceof User && ($user->accountRole()?->hasPermission(AccountPermission::ViewOrders) ?? false), 403);

        return $account;
    }
}
