<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A single, permission-filtered index of every real platform settings screen
 * (commit-order item 4).
 *
 * A navigational wrapper, not a new settings surface: every link here already
 * has its own route, controller and permission gate, so this page only
 * groups and links, exactly as {@see DashboardController}'s cards do -- it
 * never shows an item its viewer could not otherwise reach, and adds no
 * authorization of its own.
 *
 * `PermissionCatalogue::matrix()` names several modules with no screen at all
 * today -- CMS, SEO, Backups, Audit logs, generic Notifications, Access
 * (Roles & Permissions, until commit-order item 6), generic Integrations,
 * Reports, Wholesale/Dropshipping settings, Fulfillment, Courier, Commission,
 * Settlement. They are deliberately absent from this list rather than shown
 * as disabled placeholders -- no fake forms.
 */
class SettingsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $actor = $this->actor($request);

        $sections = collect($this->sections())
            ->map(fn (array $section) => [
                'key' => $section['key'],
                'label' => $section['label'],
                'items' => collect($section['items'])
                    ->filter(fn (array $item) => $actor->can($item['permission']))
                    ->map(fn (array $item) => [
                        'key' => $item['key'],
                        'title' => $item['title'],
                        'description' => $item['description'],
                        'href' => route($item['route']),
                    ])
                    ->values(),
            ])
            ->filter(fn (array $section) => $section['items']->isNotEmpty())
            ->values();

        return Inertia::render('admin/settings/index', [
            'sections' => $sections,
        ]);
    }

    /**
     * @return list<array{key: string, label: string, items: list<array{key: string, title: string, description: string, route: string, permission: string}>}>
     */
    protected function sections(): array
    {
        return [
            [
                'key' => 'money',
                'label' => __('settings.hub.sections.money'),
                'items' => [
                    [
                        'key' => 'billing_rules',
                        'title' => __('nav.billing_rules'),
                        'description' => __('settings.hub.items.billing_rules'),
                        'route' => 'admin.billing.index',
                        'permission' => PermissionCatalogue::name(PermissionModule::Payment, PermissionAction::View),
                    ],
                    [
                        'key' => 'payment_gateways',
                        'title' => __('nav.payment_gateways'),
                        'description' => __('settings.hub.items.payment_gateways'),
                        'route' => 'admin.gateways.index',
                        'permission' => PermissionCatalogue::name(PermissionModule::Payment, PermissionAction::View),
                    ],
                    [
                        'key' => 'payments',
                        'title' => __('nav.payments'),
                        'description' => __('settings.hub.items.payments'),
                        'route' => 'admin.gateways.switches',
                        'permission' => PermissionCatalogue::name(PermissionModule::Payment, PermissionAction::View),
                    ],
                    [
                        'key' => 'deposit_rules',
                        'title' => __('nav.deposit_rules'),
                        'description' => __('settings.hub.items.deposit_rules'),
                        'route' => 'admin.deposit-rules.index',
                        'permission' => PermissionCatalogue::name(PermissionModule::Wallet, PermissionAction::View),
                    ],
                    [
                        'key' => 'withdrawal_limits',
                        'title' => __('nav.withdrawal_limits'),
                        'description' => __('settings.hub.items.withdrawal_limits'),
                        'route' => 'admin.withdrawal-limits.index',
                        'permission' => PermissionCatalogue::name(PermissionModule::Withdrawal, PermissionAction::View),
                    ],
                ],
            ],
            [
                'key' => 'platform',
                'label' => __('settings.hub.sections.platform'),
                'items' => [
                    [
                        'key' => 'website_pricing',
                        'title' => __('nav.website_pricing'),
                        'description' => __('settings.hub.items.website_pricing'),
                        'route' => 'admin.website-pricing.index',
                        'permission' => PermissionCatalogue::name(PermissionModule::Website, PermissionAction::ManageSettings),
                    ],
                    [
                        'key' => 'referral_settings',
                        'title' => __('nav.referral_settings'),
                        'description' => __('settings.hub.items.referral_settings'),
                        'route' => 'admin.referral-settings.index',
                        'permission' => PermissionCatalogue::name(PermissionModule::Referral, PermissionAction::ViewSettings),
                    ],
                    [
                        'key' => 'packages',
                        'title' => __('nav.packages'),
                        'description' => __('settings.hub.items.packages'),
                        'route' => 'admin.packages.index',
                        'permission' => PermissionCatalogue::name(PermissionModule::Package, PermissionAction::View),
                    ],
                    [
                        'key' => 'kyc_requirements',
                        'title' => __('nav.kyc_requirements'),
                        'description' => __('settings.hub.items.kyc_requirements'),
                        'route' => 'admin.kyc.document-types.index',
                        'permission' => PermissionCatalogue::name(PermissionModule::Kyc, PermissionAction::ManageSettings),
                    ],
                ],
            ],
            [
                'key' => 'communication',
                'label' => __('settings.hub.sections.communication'),
                'items' => [
                    [
                        'key' => 'sms',
                        'title' => __('nav.sms'),
                        'description' => __('settings.hub.items.sms'),
                        'route' => 'admin.sms.index',
                        'permission' => PermissionCatalogue::name(PermissionModule::Sms, PermissionAction::View),
                    ],
                    [
                        'key' => 'branding',
                        'title' => __('nav.branding'),
                        'description' => __('settings.hub.items.branding'),
                        'route' => 'admin.branding.edit',
                        'permission' => PermissionCatalogue::name(PermissionModule::System, PermissionAction::ManageSettings),
                    ],
                ],
            ],
            [
                'key' => 'integrations',
                'label' => __('settings.hub.sections.integrations'),
                'items' => [
                    [
                        'key' => 'storage',
                        'title' => __('nav.storage_settings'),
                        'description' => __('settings.hub.items.storage'),
                        'route' => 'admin.storage-settings.index',
                        'permission' => PermissionCatalogue::name(PermissionModule::Integration, PermissionAction::View),
                    ],
                ],
            ],
        ];
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
