<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Queries\PendingActivationQuery;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Queries\DailyOrderVolume;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Enums\SupplierKycStatus;
use App\Domain\Supplier\Models\SupplierKycSubmission;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Navigation\HomeRoute;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Admin/Staff portal's home screen.
 *
 * There was no admin dashboard at all before this — staff landed directly on
 * whichever queue {@see HomeRoute} sent them to. This
 * adds an overview without changing that landing priority (a separate
 * decision), reachable from the Overview navigation group like the
 * Client/Partner and Supplier dashboards.
 *
 * Every card is a plain count against the same permission the screen it links
 * to already requires, so a viewer never sees a figure for a queue they could
 * not otherwise open — the dashboard adds no authorization surface of its
 * own. There is no money figure here yet: a platform-wide revenue aggregate is
 * a real, separate query to get right (§36.1) rather than one assembled for
 * the first cut of this screen.
 *
 * The trend chart and the breakdown are real data, not invented analytics:
 * the trend is a straight day-by-day order count ({@see DailyOrderVolume}),
 * shown only to someone who already holds `order.view`; the breakdown is the
 * same five card counts reshaped as a proportion of what is waiting on this
 * viewer, so it can never disagree with the cards above it.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, PendingActivationQuery $pendingActivations, DailyOrderVolume $orderVolume): Response
    {
        $user = $this->actor($request);

        $cards = array_values(array_filter([
            $this->kycCard($user),
            $this->activationsCard($user, $pendingActivations),
            $this->ordersCard($user),
            $this->supplierKycCard($user),
            $this->supplierListingsCard($user),
        ]));

        return Inertia::render('admin/dashboard', [
            'greeting' => $this->greeting($user),
            'attention' => array_sum(array_column($cards, 'value')),
            'cards' => $cards,
            'trend' => $user->can(PermissionCatalogue::name(PermissionModule::Order, PermissionAction::View))
                ? [$orderVolume->series()]
                : null,
            'breakdown' => $this->breakdown($cards),
        ]);
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }

    /**
     * @return array{name: string, period: string}
     */
    protected function greeting(User $user): array
    {
        $hour = (int) now()->format('G');

        return [
            'name' => explode(' ', trim($user->name))[0],
            'period' => match (true) {
                $hour < 12 => 'morning',
                $hour < 17 => 'afternoon',
                default => 'evening',
            },
        ];
    }

    /**
     * @return array{key: string, label: string, value: int, href: string}|null
     */
    protected function kycCard(User $user): ?array
    {
        if (! $user->can(PermissionCatalogue::name(PermissionModule::Kyc, PermissionAction::View))) {
            return null;
        }

        // Mirrors KycStatus::awaitsReview() — a reviewer still has to act on
        // Submitted or UnderReview, and nothing else.
        $count = KycSubmission::query()
            ->whereIn('status', [KycStatus::Submitted->value, KycStatus::UnderReview->value])
            ->count();

        return [
            'key' => 'kyc',
            'label' => __('dashboard.admin.cards.kyc'),
            'value' => $count,
            'href' => route('admin.kyc.index'),
        ];
    }

    /**
     * @return array{key: string, label: string, value: int, href: string}|null
     */
    protected function activationsCard(User $user, PendingActivationQuery $query): ?array
    {
        if (! $user->can(PermissionCatalogue::name(PermissionModule::Account, PermissionAction::View))) {
            return null;
        }

        return [
            'key' => 'activations',
            'label' => __('dashboard.admin.cards.activations'),
            'value' => $query->builder()->count(),
            'href' => route('admin.activations.index'),
        ];
    }

    /**
     * @return array{key: string, label: string, value: int, href: string}|null
     */
    protected function ordersCard(User $user): ?array
    {
        if (! $user->can(PermissionCatalogue::name(PermissionModule::Order, PermissionAction::View))) {
            return null;
        }

        $count = Order::query()
            ->where('status', OrderStatus::PendingConfirmation->value)
            ->count();

        return [
            'key' => 'orders',
            'label' => __('dashboard.admin.cards.orders'),
            'value' => $count,
            'href' => route('admin.orders.index'),
        ];
    }

    /**
     * @return array{key: string, label: string, value: int, href: string}|null
     */
    protected function supplierKycCard(User $user): ?array
    {
        if (! $user->can(PermissionCatalogue::name(PermissionModule::SupplierKyc, PermissionAction::View))) {
            return null;
        }

        $count = SupplierKycSubmission::query()
            ->whereIn('status', [SupplierKycStatus::Submitted->value, SupplierKycStatus::UnderReview->value])
            ->count();

        return [
            'key' => 'supplier_kyc',
            'label' => __('dashboard.admin.cards.supplier_kyc'),
            'value' => $count,
            'href' => route('admin.suppliers.index'),
        ];
    }

    /**
     * @return array{key: string, label: string, value: int, href: string}|null
     */
    protected function supplierListingsCard(User $user): ?array
    {
        if (! $user->can(PermissionCatalogue::name(PermissionModule::SupplierListing, PermissionAction::View))) {
            return null;
        }

        $count = SupplierProductListing::query()
            ->whereIn('status', [ListingStatus::Submitted->value, ListingStatus::UnderReview->value])
            ->count();

        return [
            'key' => 'supplier_listings',
            'label' => __('dashboard.admin.cards.supplier_listings'),
            'value' => $count,
            'href' => route('admin.supplier-listings.index'),
        ];
    }

    /**
     * The visible cards, reshaped as a proportion of what is waiting on this
     * viewer — the same numbers, never a separate figure that could drift
     * from them.
     *
     * @param  array<int, array{key: string, label: string, value: int, href: string}>  $cards
     * @return array<int, array{key: string, label: string, value: int, formatted: string, tone: int}>
     */
    protected function breakdown(array $cards): array
    {
        return array_map(
            fn (array $card, int $index) => [
                'key' => $card['key'],
                'label' => $card['label'],
                'value' => $card['value'],
                'formatted' => (string) $card['value'],
                'tone' => ($index % 5) + 1,
            ],
            $cards,
            array_keys($cards),
        );
    }
}
