import { Head, Link } from '@inertiajs/react';
import { Network } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import { commissionStatusTone, describeRule } from '@/lib/referral';
import { show as chains } from '@/routes/admin/referral-chains';
import { index } from '@/routes/admin/referral-commissions';
import { show as event } from '@/routes/admin/referral-events';
import type { Column, Paginator } from '@/types';
import type { CommissionRow } from '@/types/referral';

type Option = { value: string; label: string };

type Props = {
    commissions: Paginator<CommissionRow>;
    filters: {
        account: string | null;
        level: number | null;
        status: string | null;
        trigger: string | null;
        from: string | null;
        to: string | null;
    };
    statuses: Option[];
    triggers: Option[];
};

const controlClass =
    'border-input bg-background focus-visible:ring-ring h-9 rounded-lg border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Every referral commission on the platform (D24, P7-44).
 *
 * Filtered on the server by account (either end), level, status, trigger and
 * date. Each row leads to its qualifying event, where the whole chain and any
 * reversal is.
 */
export default function ReferralCommissions({
    commissions,
    filters,
    statuses,
    triggers,
}: Props) {
    const { t, locale } = useTranslation();
    const { setFilter } = useTableQuery({ only: ['commissions', 'filters'] });

    const when = (value: string) => new Date(value).toLocaleString(locale);
    const levelLabel = (row: CommissionRow) =>
        row.is_joining_reward
            ? t('referral.commissions.joining')
            : t('referral.mine.level', { level: String(row.level) });

    const columns: Column<CommissionRow>[] = [
        {
            key: 'when',
            header: t('referral.commissions.when'),
            cell: (row) => (
                <span className="text-sm whitespace-nowrap">
                    {when(row.created_at)}
                </span>
            ),
        },
        {
            key: 'beneficiary',
            header: t('referral.commissions.beneficiary'),
            cell: (row) => (
                <Link
                    href={chains({ query: { account: row.beneficiary.id } })}
                    className="text-sm font-medium underline-offset-4 hover:underline"
                >
                    {row.beneficiary.name}
                </Link>
            ),
        },
        {
            key: 'source',
            header: t('referral.commissions.source'),
            cell: (row) => (
                <Link
                    href={chains({ query: { account: row.source.id } })}
                    className="text-sm underline-offset-4 hover:underline"
                >
                    {row.source.name}
                </Link>
            ),
        },
        {
            key: 'level',
            header: t('referral.commissions.level'),
            cell: (row) => (
                <div className="text-sm">
                    <div>{levelLabel(row)}</div>
                    <div className="text-muted-foreground text-xs">
                        {describeRule(row.rule)}
                    </div>
                </div>
            ),
        },
        {
            key: 'amount',
            header: t('referral.commissions.amount'),
            align: 'end',
            cell: (row) => <MoneyAmount amount={row.amount} />,
        },
        {
            key: 'status',
            header: t('referral.commissions.status'),
            cell: (row) => (
                <div className="space-y-1">
                    <StatusPill
                        tone={commissionStatusTone(row.status)}
                        label={row.status_label}
                    />
                    {row.skip_reason && (
                        <div className="text-muted-foreground text-xs">
                            {row.skip_reason}
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
                    <Link href={event(row.event)}>
                        {t('referral.commissions.open_event')}
                    </Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title={t('referral.commissions.title')} />

            <PageContainer>
                <PageHeader
                    title={t('referral.commissions.title')}
                    description={t('referral.commissions.description')}
                    actions={
                        <Button variant="outline" size="sm" asChild>
                            <Link href={chains()}>
                                {t('referral.commissions.chains')}
                            </Link>
                        </Button>
                    }
                />

                <DataTable
                    columns={columns}
                    paginator={commissions}
                    rowKey={(row) => row.id}
                    caption={t('referral.commissions.title')}
                    searchPlaceholder={t('referral.commissions.search')}
                    onlyReload={['commissions', 'filters']}
                    filters={
                        <div className="flex flex-wrap gap-2">
                            <select
                                aria-label={t('referral.commissions.status')}
                                value={filters.status ?? ''}
                                onChange={(e) =>
                                    setFilter(
                                        'status',
                                        e.target.value || undefined,
                                    )
                                }
                                className={`${controlClass} w-36`}
                            >
                                <option value="">
                                    {t('referral.commissions.all_statuses')}
                                </option>
                                {statuses.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            <select
                                aria-label={t('referral.settings.trigger')}
                                value={filters.trigger ?? ''}
                                onChange={(e) =>
                                    setFilter(
                                        'trigger',
                                        e.target.value || undefined,
                                    )
                                }
                                className={`${controlClass} w-44`}
                            >
                                <option value="">
                                    {t('referral.commissions.all_triggers')}
                                </option>
                                {triggers.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            <input
                                type="number"
                                min={0}
                                max={100}
                                aria-label={t(
                                    'referral.commissions.level_filter',
                                )}
                                placeholder={t(
                                    'referral.commissions.any_level',
                                )}
                                defaultValue={filters.level ?? ''}
                                onChange={(e) =>
                                    setFilter(
                                        'level',
                                        e.target.value || undefined,
                                    )
                                }
                                className={`${controlClass} w-24`}
                            />
                            <input
                                type="date"
                                aria-label={t('referral.commissions.from')}
                                defaultValue={filters.from ?? ''}
                                onChange={(e) =>
                                    setFilter(
                                        'from',
                                        e.target.value || undefined,
                                    )
                                }
                                className={`${controlClass} w-36`}
                            />
                            <input
                                type="date"
                                aria-label={t('referral.commissions.to')}
                                defaultValue={filters.to ?? ''}
                                onChange={(e) =>
                                    setFilter('to', e.target.value || undefined)
                                }
                                className={`${controlClass} w-36`}
                            />
                        </div>
                    }
                    renderCard={(row) => (
                        <div className="space-y-2">
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <div className="truncate text-sm font-medium">
                                        {row.beneficiary.name}
                                    </div>
                                    <div className="text-muted-foreground text-xs">
                                        {levelLabel(row)} ·{' '}
                                        {describeRule(row.rule)}
                                    </div>
                                </div>
                                <MoneyAmount amount={row.amount} />
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusPill
                                    tone={commissionStatusTone(row.status)}
                                    label={row.status_label}
                                />
                                <span className="text-muted-foreground text-xs">
                                    {when(row.created_at)}
                                </span>
                            </div>
                            <Link
                                href={event(row.event)}
                                className="text-sm underline-offset-4 hover:underline"
                            >
                                {t('referral.commissions.open_event')}
                            </Link>
                        </div>
                    )}
                    emptyState={
                        <EmptyState
                            icon={Network}
                            title={t('referral.commissions.empty')}
                            description={t('referral.commissions.empty_help')}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

ReferralCommissions.layout = {
    breadcrumbs: [{ title: 'Referral commissions', href: index() }],
};
