import { Head, Link } from '@inertiajs/react';
import {
    Banknote,
    CreditCard,
    ListChecks,
    MessageSquare,
    type LucideIcon,
    Network,
    Package as PackageIcon,
    Palette,
    Receipt,
    Scale,
    ScrollText,
    Settings2,
} from 'lucide-react';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import EmptyState from '@/components/states/empty-state';
import { useTranslation } from '@/hooks/use-translation';
import { settings as settingsHub } from '@/routes/admin';

type SettingsItem = {
    key: string;
    title: string;
    description: string;
    href: string;
};

type SettingsSection = {
    key: string;
    label: string;
    items: SettingsItem[];
};

type Props = {
    sections: SettingsSection[];
};

const ICONS: Record<string, LucideIcon> = {
    billing_rules: Receipt,
    payment_gateways: CreditCard,
    payments: ScrollText,
    deposit_rules: Scale,
    withdrawal_limits: Banknote,
    website_pricing: Scale,
    referral_settings: Network,
    packages: PackageIcon,
    kyc_requirements: ListChecks,
    sms: MessageSquare,
    branding: Palette,
};

/**
 * A single, permission-filtered index of every real platform settings screen
 * (commit-order item 4).
 *
 * A navigational wrapper: every card links to a screen that already has its
 * own route, controller and permission gate. This page adds nothing of its
 * own — it never shows a section the viewer's own permissions did not already
 * unlock, because SettingsController filtered before it ever reached here.
 */
export default function SettingsIndex({ sections }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('settings.hub.title')} />

            <PageContainer>
                <PageHeader
                    title={t('settings.hub.title')}
                    description={t('settings.hub.description')}
                />

                {sections.length === 0 ? (
                    <EmptyState
                        icon={Settings2}
                        title={t('settings.hub.empty_title')}
                        description={t('settings.hub.empty_description')}
                    />
                ) : (
                    sections.map((section) => (
                        <SectionCard key={section.key} title={section.label}>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {section.items.map((item) => {
                                    const Icon = ICONS[item.key] ?? Settings2;

                                    return (
                                        <Link
                                            key={item.key}
                                            href={item.href}
                                            className="border-border hover:bg-accent/50 focus-visible:ring-ring group flex flex-col gap-2 rounded-xl border p-4 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                                        >
                                            <span className="bg-accent text-accent-foreground inline-flex size-9 items-center justify-center rounded-lg">
                                                <Icon
                                                    aria-hidden="true"
                                                    className="size-4.5"
                                                />
                                            </span>
                                            <span className="text-sm font-medium">
                                                {item.title}
                                            </span>
                                            <span className="text-muted-foreground text-xs text-pretty">
                                                {item.description}
                                            </span>
                                        </Link>
                                    );
                                })}
                            </div>
                        </SectionCard>
                    ))
                )}
            </PageContainer>
        </>
    );
}

SettingsIndex.layout = {
    breadcrumbs: [{ title: 'settings.hub.title', href: settingsHub() }],
};
