import { Head, Link } from '@inertiajs/react';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { StatusTone } from '@/lib/status';
import { create as kyc } from '@/routes/supplier/kyc';
import { index as listings } from '@/routes/supplier/listings';
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

export default function SupplierDashboard({
    status,
    statusLabel,
    isOperational,
}: {
    status: string;
    statusLabel: string;
    isOperational: boolean;
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('supplier.dashboard.title')} />

            <div className="space-y-6">
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
            </div>
        </>
    );
}
