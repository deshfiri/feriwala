import { Deferred, Head } from '@inertiajs/react';
import AreaTrend from '@/components/charts/area-trend';
import RadialBreakdown from '@/components/charts/radial-breakdown';
import ChartSkeleton, {
    FigureSkeleton,
} from '@/components/dashboard/chart-skeleton';
import HeroCard from '@/components/dashboard/hero-card';
import Panel from '@/components/dashboard/panel';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import { dashboard } from '@/routes';
import type {
    DashboardGreeting,
    DashboardSpend,
    DashboardStanding,
} from '@/types';

type Props = {
    greeting: DashboardGreeting;
    standing: DashboardStanding;
    /** What this account could spend right now — the wallet page's own "usable" figure. Null when no wallet exists yet. */
    walletUsable: Money | null;
    /** Deferred — undefined until the follow-up request lands. */
    spend?: DashboardSpend;
};

/**
 * The ERP home screen (§33.3).
 *
 * Everything here has an activated business account: `business.activated` sends
 * an unactivated one to the stepper and platform staff to their own queues, so
 * there is no funnel and no admin panel on this page.
 *
 * The two-thirds / one-third split repeats down the page — the wide column
 * carries what changes over time, the narrow one carries the figure that
 * summarises it.
 */
export default function Dashboard({
    greeting,
    standing,
    walletUsable,
    spend,
}: Props) {
    const { t } = useTranslation();

    const monthsCovered = spend?.months ?? 6;
    const period = t('dashboard.spend.description', { months: monthsCovered });

    return (
        <>
            <Head title={t('dashboard.title')} />

            <PageContainer>
                <div className="grid gap-4 md:gap-6 lg:grid-cols-3">
                    <div className="lg:col-span-2">
                        <HeroCard greeting={greeting} standing={standing} />
                    </div>

                    <div className="grid gap-4 md:gap-6">
                        <Panel title={t('dashboard.wallet.usable')}>
                            {walletUsable ? (
                                <MoneyAmount
                                    amount={walletUsable}
                                    size="large"
                                />
                            ) : (
                                <span className="text-muted-foreground text-sm">
                                    —
                                </span>
                            )}
                        </Panel>

                        <Panel
                            title={t('dashboard.spend.total')}
                            description={period}
                        >
                            <Deferred
                                data="spend"
                                fallback={<FigureSkeleton />}
                            >
                                {() =>
                                    spend ? (
                                        <MoneyAmount
                                            amount={spend.total}
                                            size="large"
                                        />
                                    ) : null
                                }
                            </Deferred>
                        </Panel>
                    </div>
                </div>

                <div className="grid gap-4 md:gap-6 lg:grid-cols-3">
                    <Panel
                        className="lg:col-span-2"
                        title={t('dashboard.spend.title')}
                        description={period}
                    >
                        <Deferred
                            data="spend"
                            fallback={<ChartSkeleton height={260} />}
                        >
                            {() =>
                                spend ? (
                                    <AreaTrend
                                        series={spend.series}
                                        ticks={spend.ticks}
                                        height={260}
                                        emptyMessage={t(
                                            'dashboard.spend.empty',
                                        )}
                                    />
                                ) : null
                            }
                        </Deferred>
                    </Panel>

                    <Panel title={t('dashboard.spend.breakdown_title')}>
                        <Deferred
                            data="spend"
                            fallback={<ChartSkeleton height={180} />}
                        >
                            {() =>
                                spend ? (
                                    <RadialBreakdown
                                        // Past five the rings stop ranking
                                        // anything and a table does it better.
                                        slices={spend.breakdown.slice(0, 5)}
                                        size={150}
                                        emptyMessage={t(
                                            'dashboard.spend.breakdown_empty',
                                        )}
                                    />
                                ) : null
                            }
                        </Deferred>
                    </Panel>
                </div>
            </PageContainer>
        </>
    );
}

Dashboard.layout = () => ({
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
});
