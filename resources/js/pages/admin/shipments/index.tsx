import { Head, Link } from '@inertiajs/react';
import { Printer, Truck } from 'lucide-react';
import { useState } from 'react';
import ShipmentController from '@/actions/App/Http/Controllers/Admin/ShipmentController';
import DataTable from '@/components/data-table/data-table';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import {
    ShippingLabelSizePicker,
    shippingLabelUrl,
    useShippingLabelSize,
} from '@/components/shipping-label';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import type { StatusTone } from '@/lib/status';
import { index as ordersIndex } from '@/routes/admin/orders';
import type { Column, Paginator } from '@/types';

type ShipmentRow = {
    id: string;
    reference: string;
    order: { id: string; reference: string };
    provider: string;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    tracking_number: string | null;
    delivery_charge: Money;
    created_at: string | null;
    label: {
        status: string;
        status_label: string;
        status_tone: StatusTone;
        printable: boolean;
    };
};

type Props = {
    shipments: Paginator<ShipmentRow>;
    filters: { status: string | null };
    statuses: { value: string; label: string }[];
    label_batch_url: string;
};

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 rounded-lg border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Every shipment raised on the platform (Advanced Order Management batch,
 * Commit 5; §21). Only the manual courier is actually reachable today (D8) --
 * every row here was created by staff entering a tracking number and a
 * charge by hand, not by an API call to a courier.
 */
export default function AdminShipments({
    shipments,
    filters,
    statuses,
    label_batch_url,
}: Props) {
    const { t, locale } = useTranslation();
    const { setFilter } = useTableQuery({ only: ['shipments', 'filters'] });
    const filtered = Boolean(filters.status);
    const [selected, setSelected] = useState<Set<string | number>>(
        () => new Set(),
    );
    const [labelSize, setLabelSize] = useShippingLabelSize();

    /**
     * One print page for every selected parcel with a label: one label per
     * page, each with its own Storefront's frozen branding.
     */
    const batchUrl = (ids: Set<string | number>) => {
        const printable = shipments.data.filter(
            (row) => ids.has(row.id) && row.label.printable,
        );
        const url = new URL(label_batch_url, window.location.origin);

        printable.forEach((row) =>
            url.searchParams.append('shipments[]', row.id),
        );

        return printable.length === 0
            ? null
            : shippingLabelUrl(url.toString(), labelSize);
    };

    const columns: Column<ShipmentRow>[] = [
        {
            key: 'reference',
            header: t('courier.admin.columns.reference'),
            cell: (row) => (
                <Link
                    href={ShipmentController.show(row.id)}
                    className="font-mono text-sm font-medium underline-offset-4 hover:underline"
                >
                    {row.reference}
                </Link>
            ),
        },
        {
            key: 'order',
            header: t('courier.admin.columns.order'),
            cell: (row) => (
                <Link
                    href={ordersIndex()}
                    className="font-mono text-sm underline-offset-4 hover:underline"
                >
                    {row.order.reference}
                </Link>
            ),
        },
        {
            key: 'provider',
            header: t('courier.admin.columns.provider'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="text-sm">{row.provider}</div>
                    {row.tracking_number && (
                        <div className="text-muted-foreground truncate font-mono text-xs">
                            {row.tracking_number}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'status',
            header: t('courier.admin.columns.status'),
            cell: (row) => (
                <StatusPill tone={row.status_tone} label={row.status_label} />
            ),
        },
        {
            key: 'label',
            header: t('shipping_label.panel.title'),
            priority: 'secondary',
            cell: (row) => (
                <StatusPill
                    tone={row.label.status_tone}
                    label={row.label.status_label}
                />
            ),
        },
        {
            key: 'delivery_charge',
            header: t('courier.admin.columns.delivery_charge'),
            align: 'end',
            cell: (row) => <MoneyAmount amount={row.delivery_charge} />,
        },
        {
            key: 'created_at',
            header: t('courier.admin.columns.created_at'),
            priority: 'secondary',
            cell: (row) =>
                row.created_at ? (
                    <span className="text-sm">
                        {new Date(row.created_at).toLocaleString(locale)}
                    </span>
                ) : null,
        },
    ];

    return (
        <>
            <Head title={t('courier.admin.title')} />

            <PageContainer>
                <PageHeader
                    title={t('courier.admin.title')}
                    description={t('courier.admin.description')}
                />

                <DataTable
                    columns={columns}
                    paginator={shipments}
                    rowKey={(row) => row.id}
                    selection={{ selected, onChange: setSelected }}
                    bulkActions={(ids) => {
                        const url = batchUrl(ids);

                        return (
                            <div className="flex flex-wrap items-center gap-2">
                                <ShippingLabelSizePicker
                                    size={labelSize}
                                    onChange={setLabelSize}
                                />
                                {url ? (
                                    <Button asChild size="sm">
                                        <a
                                            href={url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            <Printer aria-hidden="true" />
                                            {t(
                                                'shipping_label.panel.print_selected',
                                            )}
                                        </a>
                                    </Button>
                                ) : (
                                    <span className="text-muted-foreground text-sm">
                                        {t(
                                            'shipping_label.panel.none_selected',
                                        )}
                                    </span>
                                )}
                            </div>
                        );
                    }}
                    caption={t('courier.admin.caption')}
                    onlyReload={['shipments', 'filters']}
                    filters={
                        <select
                            aria-label={t('courier.admin.filter_status')}
                            value={filters.status ?? ''}
                            onChange={(event) =>
                                setFilter(
                                    'status',
                                    event.target.value || undefined,
                                )
                            }
                            className={selectClass}
                        >
                            <option value="">
                                {t('courier.admin.all_statuses')}
                            </option>
                            {statuses.map((status) => (
                                <option key={status.value} value={status.value}>
                                    {status.label}
                                </option>
                            ))}
                        </select>
                    }
                    renderCard={(row) => (
                        <div className="space-y-2">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <Link
                                        href={ShipmentController.show(row.id)}
                                        className="block truncate font-mono text-sm font-medium underline-offset-4 hover:underline"
                                    >
                                        {row.reference}
                                    </Link>
                                    <div className="text-muted-foreground truncate text-xs">
                                        {row.order.reference}
                                    </div>
                                </div>
                                <MoneyAmount amount={row.delivery_charge} />
                            </div>
                            <StatusPill
                                tone={row.status_tone}
                                label={row.status_label}
                            />
                        </div>
                    )}
                    emptyState={
                        <EmptyState
                            icon={Truck}
                            title={t(
                                filtered
                                    ? 'courier.admin.no_matches'
                                    : 'courier.admin.empty',
                            )}
                            description={t(
                                filtered
                                    ? 'courier.admin.no_matches_help'
                                    : 'courier.admin.empty_help',
                            )}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

AdminShipments.layout = {
    breadcrumbs: [
        {
            title: 'nav.shipments',
            href: ShipmentController.index(),
        },
    ],
};
