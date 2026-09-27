import { Head, Link } from '@inertiajs/react';
import { Banknote, ClipboardList, Coins } from 'lucide-react';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatCard, { StatCardGrid } from '@/components/stat-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import type { StatusTone } from '@/lib/status';
import { create as kyc } from '@/routes/supplier/kyc';
import { index as listings } from '@/routes/supplier/listings';
import { show as wallet } from '@/routes/supplier/wallet';
import { index as withdrawals } from '@/routes/supplier/withdrawals';
import { mobile } from '@/routes/supplier/verification';

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
    withdrawals_pending: number;
    wallet_available: Money | null;
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
                <PageHeader title={t('supplier.dashboard.title')} />

                <SectionCard title={t('supplier.dashboard.status')}>
                    <div className="space-y-4">
                        <StatusPill
                            tone={TONES[status] ?? 'neutral'}
                            label={statusLabel}
                        />

                        <div>
                            <h3 className="text-sm font-semibold">
                                {t('supplier.dashboard.next_step')}
                            </h3>
                            <p className="text-muted-foreground mt-1 text-sm">
                                {t(`supplier.dashboard.steps.${status}`)}
                            </p>
                        </div>

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
                    </div>
                </SectionCard>

                {snapshot && (
                    <StatCardGrid className="sm:grid-cols-3 lg:grid-cols-3">
                        <Link
                            href={listings()}
                            className="focus-visible:ring-ring rounded-xl focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <StatCard
                                label={t(
                                    'supplier.dashboard.snapshot.active_listings',
                                )}
                                value={snapshot.active_listings}
                                icon={ClipboardList}
                                tone="brand"
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

                        <Link
                            href={wallet()}
                            className="focus-visible:ring-ring rounded-xl focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <StatCard
                                label={t(
                                    'supplier.dashboard.snapshot.wallet_available',
                                )}
                                value={
                                    snapshot.wallet_available ? (
                                        <MoneyAmount
                                            amount={snapshot.wallet_available}
                                            size="large"
                                        />
                                    ) : (
                                        '—'
                                    )
                                }
                                icon={Coins}
                                tone="success"
                                className="hover:bg-accent/50 transition-colors"
                            />
                        </Link>
                    </StatCardGrid>
                )}
            </PageContainer>
        </>
    );
}
