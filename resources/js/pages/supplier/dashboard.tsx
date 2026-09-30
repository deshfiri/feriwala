import { Head, Link } from '@inertiajs/react';
import { Banknote, Boxes, ClipboardList, PackageCheck } from 'lucide-react';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import GreetingBanner from '@/components/dashboard/greeting-banner';
import SectionCard from '@/components/section-card';
import StatCard, { StatCardGrid } from '@/components/stat-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import type { StatusTone } from '@/lib/status';
import { dashboard } from '@/routes/supplier';
import { create as kyc } from '@/routes/supplier/kyc';
import { index as listings } from '@/routes/supplier/listings';
import { index as offers } from '@/routes/supplier/offers';
import { index as payables } from '@/routes/supplier/payables';
import { index as stock } from '@/routes/supplier/stock';
import { mobile } from '@/routes/supplier/verification';
import { show as wallet } from '@/routes/supplier/wallet';
import { index as withdrawals } from '@/routes/supplier/withdrawals';

const TONES: Record<string, StatusTone> = {
    approved: 'success',
    rejected: 'danger',
    suspended: 'danger',
    closed: 'danger',
    correction_required: 'warning',
    under_review: 'info',
    kyc_pending: 'info',
    verification_pending: 'info',
    draft: 'neutral',
};

type Snapshot = {
    active_listings: number;
    pending_listings: number;
    active_offers: number;
    stock_available: number;
    withdrawals_pending: number;
    payables: { pending: Money; eligible: Money; settled: Money };
    wallet: {
        total: Money;
        available: Money;
        reserved: Money;
        recovery: Money;
        has_outstanding_recovery: boolean;
    } | null;
};

export default function SupplierDashboard({
    status,
    statusLabel,
    isOperational,
    snapshot,
}: {
    status: string;
    statusLabel: string;
    isOperational: boolean;
    snapshot: Snapshot | null;
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('supplier.dashboard.title')} />

            <PageContainer>
                <GreetingBanner
                    heading={t('supplier.dashboard.title')}
                    wave={false}
                    message={t(`supplier.dashboard.steps.${status}`)}
                >
                    <StatusPill
                        tone={TONES[status] ?? 'neutral'}
                        label={statusLabel}
                    />

                    <div className="flex flex-wrap gap-2">
                        {status === 'verification_pending' && (
                            <Button asChild>
                                <Link href={mobile()}>
                                    {t('supplier.verification.title')}
                                </Link>
                            </Button>
                        )}
                        {[
                            'kyc_pending',
                            'correction_required',
                            'under_review',
                            'rejected',
                        ].includes(status) && (
                            <Button asChild>
                                <Link href={kyc()}>
                                    {t('supplier.dashboard.open_kyc')}
                                </Link>
                            </Button>
                        )}
                        {isOperational && (
                            <Button asChild>
                                <Link href={listings()}>
                                    {t('supplier.dashboard.open_listings')}
                                </Link>
                            </Button>
                        )}
                    </div>
                </GreetingBanner>

                {snapshot && (
                    <>
                        <StatCardGrid>
                            <Link
                                href={listings()}
                                className="focus-visible:ring-ring rounded-xl focus-visible:ring-2 focus-visible:outline-none"
                            >
                                <StatCard
                                    label={t(
                                        'supplier.dashboard.snapshot.active_listings',
                                    )}
                                    value={snapshot.active_listings}
                                    hint={t(
                                        'supplier.dashboard.snapshot.pending_listings',
                                        { count: snapshot.pending_listings },
                                    )}
                                    icon={ClipboardList}
                                    tone="brand"
                                    className="hover:bg-accent/50 transition-colors"
                                />
                            </Link>

                            <Link
                                href={offers()}
                                className="focus-visible:ring-ring rounded-xl focus-visible:ring-2 focus-visible:outline-none"
                            >
                                <StatCard
                                    label={t(
                                        'supplier.dashboard.snapshot.active_offers',
                                    )}
                                    value={snapshot.active_offers}
                                    icon={PackageCheck}
                                    tone="info"
                                    className="hover:bg-accent/50 transition-colors"
                                />
                            </Link>

                            <Link
                                href={stock()}
                                className="focus-visible:ring-ring rounded-xl focus-visible:ring-2 focus-visible:outline-none"
                            >
                                <StatCard
                                    label={t(
                                        'supplier.dashboard.snapshot.stock_available',
                                    )}
                                    value={snapshot.stock_available}
                                    icon={Boxes}
                                    tone="neutral"
                                    className="hover:bg-accent/50 transition-colors"
                                />
                            </Link>

                            <Link
                                href={withdrawals()}
                                className="focus-visible:ring-ring rounded-xl focus-visible:ring-2 focus-visible:outline-none"
                            >
                                <StatCard
                                    label={t(
                                        'supplier.dashboard.snapshot.withdrawals_pending',
                                    )}
                                    value={snapshot.withdrawals_pending}
                                    icon={Banknote}
                                    tone="warning"
                                    className="hover:bg-accent/50 transition-colors"
                                />
                            </Link>
                        </StatCardGrid>

                        <div className="grid gap-4 md:gap-6 lg:grid-cols-2">
                            <Link
                                href={payables()}
                                className="focus-visible:ring-ring rounded-xl focus-visible:ring-2 focus-visible:outline-none"
                            >
                                <SectionCard
                                    title={t(
                                        'supplier.dashboard.snapshot.payables',
                                    )}
                                    className="hover:bg-accent/30 transition-colors"
                                >
                                    <dl className="grid grid-cols-3 gap-4">
                                        {(
                                            [
                                                'pending',
                                                'eligible',
                                                'settled',
                                            ] as const
                                        ).map((key) => (
                                            <div key={key}>
                                                <dt className="text-muted-foreground text-xs">
                                                    {t(
                                                        `supplier.dashboard.snapshot.payables_${key}`,
                                                    )}
                                                </dt>
                                                <dd className="tabular mt-1 font-semibold">
                                                    <MoneyAmount
                                                        amount={
                                                            snapshot.payables[
                                                                key
                                                            ]
                                                        }
                                                    />
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                </SectionCard>
                            </Link>

                            <Link
                                href={wallet()}
                                className="focus-visible:ring-ring rounded-xl focus-visible:ring-2 focus-visible:outline-none"
                            >
                                <SectionCard
                                    title={t(
                                        'supplier.dashboard.snapshot.wallet',
                                    )}
                                    className="hover:bg-accent/30 transition-colors"
                                >
                                    {(() => {
                                        const walletBalances = snapshot.wallet;

                                        if (!walletBalances) {
                                            return (
                                                <p className="text-muted-foreground text-sm">
                                                    {t(
                                                        'supplier.dashboard.snapshot.wallet_none',
                                                    )}
                                                </p>
                                            );
                                        }

                                        return (
                                            <dl className="grid grid-cols-3 gap-4">
                                                {(
                                                    [
                                                        'available',
                                                        'reserved',
                                                        'recovery',
                                                    ] as const
                                                ).map((key) => (
                                                    <div key={key}>
                                                        <dt className="text-muted-foreground text-xs">
                                                            {t(
                                                                `supplier.dashboard.snapshot.wallet_${key}`,
                                                            )}
                                                        </dt>
                                                        <dd className="tabular mt-1 font-semibold">
                                                            <MoneyAmount
                                                                amount={
                                                                    walletBalances[
                                                                        key
                                                                    ]
                                                                }
                                                            />
                                                        </dd>
                                                    </div>
                                                ))}
                                            </dl>
                                        );
                                    })()}
                                </SectionCard>
                            </Link>
                        </div>
                    </>
                )}
            </PageContainer>
        </>
    );
}

SupplierDashboard.layout = {
    breadcrumbs: [{ title: 'supplier.nav.dashboard', href: dashboard() }],
};
