import { Head, Link } from '@inertiajs/react';
import {
    ClipboardList,
    Package,
    ShieldCheck,
    Truck,
    UserCheck,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import AreaTrend from '@/components/charts/area-trend';
import RadialBreakdown from '@/components/charts/radial-breakdown';
import PageContainer from '@/components/page-container';
import Panel from '@/components/dashboard/panel';
import StatCard, {
    StatCardGrid,
    type StatCardTone,
} from '@/components/stat-card';
import EmptyState from '@/components/states/empty-state';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes/admin';
import type {
    AdminDashboardCard,
    ChartSeries,
    ChartSlice,
    DashboardGreeting,
} from '@/types';

type Props = {
    greeting: DashboardGreeting;
    attention: number;
    cards: AdminDashboardCard[];
    /** Null when this viewer holds no permission the trend would be shown under. */
    trend: ChartSeries[] | null;
    breakdown: ChartSlice[];
};

const ICONS: Record<string, LucideIcon> = {
    kyc: ShieldCheck,
    activations: UserCheck,
    orders: Package,
    supplier_kyc: Truck,
    supplier_listings: ClipboardList,
};

const TONES: Record<string, StatCardTone> = {
    kyc: 'info',
    activations: 'success',
    orders: 'brand',
    supplier_kyc: 'warning',
    supplier_listings: 'neutral',
};

const TREND_DAYS = 14;

/**
 * The Admin/Staff portal's home screen.
 *
 * Every card, the trend and the breakdown all come from the same
 * permission-scoped figures DashboardController computes — nothing here
 * decides who may see what, it only lays out what already passed that
 * decision. The trend and breakdown are real counts, not invented analytics:
 * the trend is a plain day-by-day order count, and the breakdown is the same
 * card figures reshaped as a proportion, so it can never disagree with them.
 */
export default function AdminDashboard({
    greeting,
    attention,
    cards,
    trend,
    breakdown,
}: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('dashboard.admin.title')} />

            <PageContainer>
                <section className="bg-card border-border rounded-xl border p-6 shadow-sm">
                    <h1 className="text-2xl font-semibold tracking-tight text-balance">
                        {t(`dashboard.greeting.${greeting.period}`, {
                            name: greeting.name,
                        })}
                    </h1>
                    <p className="text-muted-foreground mt-1.5 text-sm text-balance">
                        {attention > 0
                            ? t('dashboard.admin.hero.attention', {
                                  count: attention,
                              })
                            : t('dashboard.admin.hero.clear')}
                    </p>
                </section>

                {cards.length === 0 ? (
                    <EmptyState description={t('dashboard.admin.empty')} />
                ) : (
                    <>
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
                                        tone={TONES[card.key]}
                                        className="hover:bg-accent/50 transition-colors"
                                    />
                                </Link>
                            ))}
                        </StatCardGrid>

                        <div className="grid gap-4 md:gap-6 lg:grid-cols-3">
                            {trend && (
                                <Panel
                                    className="lg:col-span-2"
                                    title={t('dashboard.admin.trend.title')}
                                    description={t(
                                        'dashboard.admin.trend.description',
                                        { days: TREND_DAYS },
                                    )}
                                >
                                    <AreaTrend
                                        series={trend}
                                        height={240}
                                        emptyMessage={t(
                                            'dashboard.admin.trend.empty',
                                        )}
                                    />
                                </Panel>
                            )}

                            <Panel
                                className={trend ? undefined : 'lg:col-span-3'}
                                title={t('dashboard.admin.breakdown.title')}
                            >
                                <RadialBreakdown
                                    slices={breakdown}
                                    size={150}
                                    emptyMessage={t(
                                        'dashboard.admin.breakdown.empty',
                                    )}
                                />
                            </Panel>
                        </div>
                    </>
                )}
            </PageContainer>
        </>
    );
}

AdminDashboard.layout = {
    breadcrumbs: [{ title: 'nav.dashboard', href: dashboard() }],
};
