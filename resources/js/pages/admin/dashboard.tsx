import { Head, Link } from '@inertiajs/react';
import {
    ClipboardList,
    type LucideIcon,
    Package,
    ShieldCheck,
    Truck,
    UserCheck,
} from 'lucide-react';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import StatCard, { StatCardGrid } from '@/components/stat-card';
import EmptyState from '@/components/states/empty-state';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes/admin';
import type { AdminDashboardCard, DashboardGreeting } from '@/types';

type Props = {
    greeting: DashboardGreeting;
    cards: AdminDashboardCard[];
};

const ICONS: Record<string, LucideIcon> = {
    kyc: ShieldCheck,
    activations: UserCheck,
    orders: Package,
    supplier_kyc: Truck,
    supplier_listings: ClipboardList,
};

/**
 * The Admin/Staff portal's home screen.
 *
 * Every card the backend sends is one this viewer already holds the
 * permission for (see DashboardController) — nothing here decides who may
 * see what, it only lays out what already passed that decision.
 */
export default function AdminDashboard({ greeting, cards }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('dashboard.admin.title')} />

            <PageContainer>
                <PageHeader
                    title={t(`dashboard.greeting.${greeting.period}`, {
                        name: greeting.name,
                    })}
                    description={t('dashboard.admin.description')}
                />

                {cards.length === 0 ? (
                    <EmptyState description={t('dashboard.admin.empty')} />
                ) : (
                    <StatCardGrid>
                        {cards.map((card) => (
                            <Link
                                key={card.key}
                                href={card.href}
                                className="focus-visible:ring-ring rounded-xl focus-visible:ring-2 focus-visible:outline-none"
                            >
                                <StatCard
                                    label={card.label}
                                    value={card.value}
                                    icon={ICONS[card.key]}
                                    className="hover:bg-accent/50 transition-colors"
                                />
                            </Link>
                        ))}
                    </StatCardGrid>
                )}
            </PageContainer>
        </>
    );
}

AdminDashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
