import { Head, Link } from '@inertiajs/react';
import { Wallet as WalletIcon } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { index, show } from '@/routes/admin/wallets';
import type { Column, Paginator } from '@/types';
import type { WalletSummary } from '@/types/wallet';

/**
 * Finding an account's wallet (§23, §32).
 *
 * Ordered by what is in them, because the question that brings somebody here is
 * usually about money that is or is not there. A wallet short of its required
 * deposit says so in words beside the figure rather than by tinting the row —
 * colour alone carries nothing to a reader who cannot see it (§33.9).
 */
export default function AdminWalletsIndex({
    wallets,
}: {
    wallets: Paginator<WalletSummary>;
}) {
    const { t } = useTranslation();

    const columns: Column<WalletSummary>[] = [
        {
            key: 'account',
            header: t('wallet.columns.account'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate font-medium">
                        {row.account ?? '—'}
                    </div>
                    <div className="text-muted-foreground truncate font-mono text-xs">
                        {row.id}
                    </div>
                </div>
            ),
        },
        {
            key: 'total',
            header: t('wallet.columns.total'),
            align: 'end',
            cell: (row) => <MoneyAmount amount={row.total} />,
        },
        {
            key: 'usable',
            header: t('wallet.columns.available'),
            align: 'end',
            priority: 'secondary',
            cell: (row) => <MoneyAmount amount={row.usable} />,
        },
        {
            key: 'state',
            header: t('wallet.columns.status'),
            cell: (row) => (
                <div className="min-w-0 space-y-1">
                    <StatusPill tone={row.state_tone} label={row.state_label} />

                    {!row.meets_obligation && (
                        <div className="text-muted-foreground text-xs">
                            {t('wallet.admin.below_deposit', {
                                amount: row.obligation_shortfall.formatted,
                            })}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'actions',
            header: '',
            alwaysVisible: true,
            align: 'end',
            cell: (row) => (
                <Button variant="ghost" size="sm" asChild>
                    <Link href={show(row.id)}>{t('wallet.admin.open')}</Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title={t('wallet.admin.title')} />

            <PageContainer>
                <PageHeader
                    title={t('wallet.admin.title')}
                    description={t('wallet.admin.description')}
                />

                <DataTable
                    columns={columns}
                    paginator={wallets}
                    rowKey={(row) => row.id}
                    caption={t('wallet.admin.caption')}
                    searchPlaceholder={t('wallet.admin.search_placeholder')}
                    onlyReload={['wallets']}
                    emptyState={
                        <EmptyState
                            icon={WalletIcon}
                            title={t('wallet.admin.empty_title')}
                            description={t('wallet.admin.empty_description')}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

AdminWalletsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Account wallets',
            href: index(),
        },
    ],
};
