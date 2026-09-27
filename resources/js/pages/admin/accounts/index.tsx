import { Head, Link } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import { index, show } from '@/routes/admin/accounts';
import type { AccountDirectoryRow, Column, Paginator } from '@/types';

const ALL = 'all';
const RELOAD_PROPS = ['accounts', 'summary'];

type Summary = {
    total: number;
    kyc_pending: number;
    payment_pending: number;
    approval_pending: number;
    active: number;
    suspended: number;
    expired: number;
    reverification_required: number;
};

/**
 * Every Client/Partner business, as an operations list.
 *
 * The activation queue answers "who is waiting on us". This answers "who are
 * our accounts" — the question that previously had no screen at all, leaving a
 * trading business reachable only by knowing its URL.
 *
 * A directory, not a dossier: no KYC document, payout detail or wallet figure
 * appears here. Those live on the detail page behind their own abilities.
 */
export default function AdminAccountsIndex({
    accounts,
    summary,
    packages,
}: {
    accounts: Paginator<AccountDirectoryRow>;
    summary: Summary;
    packages: { id: string; name: string }[];
}) {
    const { t, locale } = useTranslation();
    const { getFilter, setFilter } = useTableQuery({ only: RELOAD_PROPS });

    const formatDate = (value: string | null) =>
        value === null
            ? '—'
            : new Date(value).toLocaleDateString(locale, {
                  day: 'numeric',
                  month: 'short',
                  year: 'numeric',
              });

    // Each tile is a filter, not an ornament: the number tells a staff member
    // where the work is, and clicking it takes them to exactly those accounts.
    const tiles: {
        key: string;
        label: string;
        count: number;
        state?: string;
    }[] = [
        {
            key: 'kyc_pending',
            label: t('accounts.summary.kyc_pending'),
            count: summary.kyc_pending,
        },
        {
            key: 'payment_pending',
            label: t('accounts.summary.payment_pending'),
            count: summary.payment_pending,
        },
        {
            key: 'approval_pending',
            label: t('accounts.summary.approval_pending'),
            count: summary.approval_pending,
        },
        {
            key: 'active',
            label: t('accounts.summary.active'),
            count: summary.active,
            state: 'trading',
        },
        {
            key: 'suspended',
            label: t('accounts.summary.suspended'),
            count: summary.suspended,
            state: 'halted',
        },
        {
            key: 'expired',
            label: t('accounts.summary.expired'),
            count: summary.expired,
            state: 'expired',
        },
        {
            key: 'reverification_required',
            label: t('accounts.summary.reverification_required'),
            count: summary.reverification_required,
        },
    ];

    const columns: Column<AccountDirectoryRow>[] = [
        {
            key: 'name',
            header: t('accounts.columns.business'),
            sortable: true,
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate font-medium">{row.name}</div>
                    <div className="text-muted-foreground truncate text-xs">
                        {[row.owner, row.email].filter(Boolean).join(' · ')}
                    </div>
                </div>
            ),
        },
        {
            key: 'contact',
            header: t('accounts.columns.contact'),
            priority: 'secondary',
            cell: (row) => (
                <div className="min-w-0 text-xs">
                    {/* Verification is stated in words, never by colour or a
                        bare tick (§33.9). */}
                    <div className="truncate">
                        {row.email ?? '—'}
                        {' · '}
                        {row.email_verified
                            ? t('accounts.verified')
                            : t('accounts.unverified')}
                    </div>
                    <div className="text-muted-foreground truncate">
                        {row.mobile ?? '—'}
                        {' · '}
                        {row.mobile_verified
                            ? t('accounts.verified')
                            : t('accounts.unverified')}
                    </div>
                </div>
            ),
        },
        {
            key: 'package',
            header: t('accounts.columns.package'),
            priority: 'secondary',
            cell: (row) => (
                <div className="min-w-0 text-xs">
                    <div className="truncate">{row.package ?? '—'}</div>
                    <div className="text-muted-foreground truncate">
                        {row.package_status ?? '—'}
                    </div>
                </div>
            ),
        },
        {
            key: 'status',
            header: t('accounts.columns.status'),
            cell: (row) => (
                <StatusPill tone={row.status_tone} label={row.status_label} />
            ),
        },
        {
            key: 'created_at',
            header: t('accounts.columns.registered'),
            sortable: true,
            priority: 'secondary',
            cell: (row) => formatDate(row.registered_at),
        },
        {
            key: 'activated_at',
            header: t('accounts.columns.activated'),
            sortable: true,
            priority: 'secondary',
            cell: (row) => formatDate(row.activated_at),
        },
        {
            key: 'actions',
            header: '',
            alwaysVisible: true,
            align: 'end',
            cell: (row) => (
                <Button variant="ghost" size="sm" asChild>
                    <Link href={show(row.id)}>{t('accounts.open')}</Link>
                </Button>
            ),
        },
    ];

    const filterSelect = (
        key: string,
        label: string,
        options: { value: string; label: string }[],
    ) => (
        <Select
            value={getFilter(key) ?? ALL}
            onValueChange={(value) =>
                setFilter(key, value === ALL ? undefined : value)
            }
        >
            <SelectTrigger size="sm" className="w-44" aria-label={label}>
                <SelectValue placeholder={label} />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL}>{label}</SelectItem>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );

    return (
        <>
            <Head title={t('accounts.title')} />

            <PageContainer>
                <PageHeader
                    title={t('accounts.title')}
                    description={t('accounts.description')}
                />

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-7">
                    {tiles.map((tile) => (
                        <button
                            key={tile.key}
                            type="button"
                            onClick={() =>
                                tile.state
                                    ? setFilter('state', tile.state)
                                    : setFilter('state', undefined)
                            }
                            className="border-border hover:bg-accent focus-visible:ring-ring rounded-lg border p-3 text-left transition focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <div className="text-2xl font-semibold tabular-nums">
                                {tile.count.toLocaleString(locale)}
                            </div>
                            <div className="text-muted-foreground text-xs">
                                {tile.label}
                            </div>
                        </button>
                    ))}
                </div>

                <DataTable
                    columns={columns}
                    paginator={accounts}
                    rowKey={(row) => row.id}
                    caption={t('accounts.title')}
                    searchPlaceholder={t('accounts.search_placeholder')}
                    onlyReload={RELOAD_PROPS}
                    filters={
                        <div className="flex flex-wrap items-center gap-2">
                            {filterSelect(
                                'state',
                                t('accounts.filters.state'),
                                [
                                    {
                                        value: 'trading',
                                        label: t('accounts.states.trading'),
                                    },
                                    {
                                        value: 'onboarding',
                                        label: t('accounts.states.onboarding'),
                                    },
                                    {
                                        value: 'halted',
                                        label: t('accounts.states.halted'),
                                    },
                                    {
                                        value: 'expired',
                                        label: t('accounts.states.expired'),
                                    },
                                    {
                                        value: 'closed',
                                        label: t('accounts.states.closed'),
                                    },
                                ],
                            )}
                            {filterSelect(
                                'facility',
                                t('accounts.filters.facility'),
                                [
                                    {
                                        value: 'wholesale',
                                        label: t(
                                            'accounts.facilities.wholesale',
                                        ),
                                    },
                                    {
                                        value: 'dropshipping',
                                        label: t(
                                            'accounts.facilities.dropshipping',
                                        ),
                                    },
                                    {
                                        value: 'both',
                                        label: t('accounts.facilities.both'),
                                    },
                                ],
                            )}
                            {filterSelect(
                                'kyc_reverification',
                                t('accounts.filters.reverification'),
                                [
                                    {
                                        value: 'required',
                                        label: t(
                                            'accounts.reverification.required',
                                        ),
                                    },
                                    {
                                        value: 'overdue',
                                        label: t(
                                            'accounts.reverification.overdue',
                                        ),
                                    },
                                    {
                                        value: 'none',
                                        label: t(
                                            'accounts.reverification.none',
                                        ),
                                    },
                                ],
                            )}
                            {filterSelect(
                                'package',
                                t('accounts.filters.package'),
                                packages.map((p) => ({
                                    value: p.id,
                                    label: p.name,
                                })),
                            )}
                            {filterSelect(
                                'wallet_restriction',
                                t('accounts.filters.wallet'),
                                [
                                    {
                                        value: 'restricted',
                                        label: t('accounts.wallet.restricted'),
                                    },
                                ],
                            )}
                        </div>
                    }
                    emptyState={
                        <EmptyState
                            icon={Building2}
                            title={t('accounts.empty_title')}
                            description={t('accounts.empty_description')}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

AdminAccountsIndex.layout = {
    breadcrumbs: [
        {
            title: 'nav.accounts',
            href: index(),
        },
    ],
};
