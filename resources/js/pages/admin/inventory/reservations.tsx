import { Form, Head, Link } from '@inertiajs/react';
import { CalendarClock, Lock, Unlock } from 'lucide-react';
import { useState } from 'react';
import ReservationController from '@/actions/App/Http/Controllers/Admin/ReservationController';
import DataTable from '@/components/data-table/data-table';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import type { StatusTone } from '@/lib/status';
import { index } from '@/routes/admin/inventory/reservations';
import { show as stockItem } from '@/routes/admin/inventory/stock';
import type { Column, Paginator } from '@/types';
import ReasonTextarea from '@/components/forms/reason-textarea';

type ReservationRow = {
    id: string;
    reference: string;
    sku: string;
    product: string;
    item_id: string;
    warehouse: string;
    quantity: number;
    kind: string;
    kind_label: string;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    expires_at: string;
    ended_at: string | null;
    release_reason: string | null;
    overridden_by: string | null;
};

type Props = {
    reservations: Paginator<ReservationRow>;
    filters: {
        search: string | null;
        status: string | null;
        kind: string | null;
    };
    windows: {
        online_minutes: number;
        cod_hours: number;
        online_bounds: [number, number];
        cod_bounds: [number, number];
        defaults: [number, number];
    };
    stock_enforced: boolean;
    max_extension_hours: number;
    can: { override: boolean };
};

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 rounded-lg border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Stock reservations: what is held, for which reference, until when (contract
 * §6.1.2, P3-26).
 *
 * Active reservations come first, soonest to run out at the top, because those
 * are the ones somebody may act on. The two overrides the contract allows — free
 * the stock now, or hold it longer — are offered only to those who may make
 * them, each asks for a reason, and each is written to the audit log.
 */
export default function AdminReservations({
    reservations,
    filters,
    windows,
    stock_enforced,
    max_extension_hours,
    can,
}: Props) {
    const { t, locale } = useTranslation();
    const { setFilter } = useTableQuery({ only: ['reservations', 'filters'] });
    const [overriding, setOverriding] = useState<{
        row: ReservationRow;
        mode: 'release' | 'extend';
    } | null>(null);

    const at = (value: string) => new Date(value).toLocaleString(locale);
    const filtered = Boolean(filters.search || filters.status || filters.kind);

    const timing = (row: ReservationRow) =>
        row.ended_at === null
            ? t('inventory.reservations.expires', { time: at(row.expires_at) })
            : t('inventory.reservations.ended', { time: at(row.ended_at) });

    const actions = (row: ReservationRow) =>
        can.override && row.status === 'active' ? (
            <div className="flex flex-wrap justify-end gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setOverriding({ row, mode: 'extend' })}
                >
                    <CalendarClock className="size-4" aria-hidden="true" />
                    {t('inventory.reservations.extend')}
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setOverriding({ row, mode: 'release' })}
                >
                    <Unlock className="size-4" aria-hidden="true" />
                    {t('inventory.reservations.release')}
                </Button>
            </div>
        ) : null;

    const columns: Column<ReservationRow>[] = [
        {
            key: 'reference',
            header: t('inventory.reservations.columns.reference'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate font-mono text-sm font-medium">
                        {row.reference}
                    </div>
                    <Link
                        href={stockItem(row.item_id)}
                        className="text-muted-foreground truncate text-xs underline-offset-4 hover:underline"
                    >
                        {row.sku} · {row.warehouse}
                    </Link>
                </div>
            ),
        },
        {
            key: 'quantity',
            header: t('inventory.reservations.columns.quantity'),
            align: 'end',
            cell: (row) => <span className="tabular-nums">{row.quantity}</span>,
        },
        {
            key: 'kind',
            header: t('inventory.reservations.columns.kind'),
            priority: 'secondary',
            cell: (row) => <span className="text-sm">{row.kind_label}</span>,
        },
        {
            key: 'status',
            header: t('inventory.reservations.columns.status'),
            cell: (row) => (
                <div className="min-w-0 space-y-1">
                    <StatusPill
                        tone={row.status_tone}
                        label={row.status_label}
                    />
                    {row.overridden_by && (
                        <div className="text-muted-foreground text-xs">
                            {t('inventory.reservations.overridden_by', {
                                name: row.overridden_by,
                            })}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'when',
            header: t('inventory.reservations.columns.when'),
            priority: 'secondary',
            cell: (row) => (
                <div className="min-w-0 space-y-1">
                    <div className="text-sm">{timing(row)}</div>
                    {row.release_reason && (
                        <div className="text-muted-foreground text-xs">
                            {row.release_reason}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'actions',
            header: '',
            align: 'end',
            alwaysVisible: true,
            cell: actions,
        },
    ];

    return (
        <>
            <Head title={t('inventory.reservations.title')} />

            <PageContainer>
                <PageHeader
                    title={t('inventory.reservations.title')}
                    description={t('inventory.reservations.description')}
                />

                <SectionCard
                    title={t('inventory.reservations.windows_title')}
                    description={t(
                        'inventory.reservations.windows_description',
                    )}
                >
                    {can.override ? (
                        <Form
                            {...ReservationController.windows.form()}
                            options={{ preserveScroll: true }}
                            className="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-start"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="window-online">
                                            {t(
                                                'inventory.reservations.online_minutes',
                                            )}
                                        </Label>
                                        <Input
                                            id="window-online"
                                            name="online_minutes"
                                            type="number"
                                            min={windows.online_bounds[0]}
                                            max={windows.online_bounds[1]}
                                            step={1}
                                            required
                                            defaultValue={
                                                windows.online_minutes
                                            }
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            {t(
                                                'inventory.reservations.online_help',
                                                {
                                                    min: windows
                                                        .online_bounds[0],
                                                    max: windows
                                                        .online_bounds[1],
                                                    default:
                                                        windows.defaults[0],
                                                },
                                            )}
                                        </p>
                                        <InputError
                                            message={errors.online_minutes}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="window-cod">
                                            {t(
                                                'inventory.reservations.cod_hours',
                                            )}
                                        </Label>
                                        <Input
                                            id="window-cod"
                                            name="cod_hours"
                                            type="number"
                                            min={windows.cod_bounds[0]}
                                            max={windows.cod_bounds[1]}
                                            step={1}
                                            required
                                            defaultValue={windows.cod_hours}
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            {t(
                                                'inventory.reservations.cod_help',
                                                {
                                                    min: windows.cod_bounds[0],
                                                    max: windows.cod_bounds[1],
                                                    default:
                                                        windows.defaults[1],
                                                },
                                            )}
                                        </p>
                                        <InputError
                                            message={errors.cod_hours}
                                        />
                                    </div>

                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="sm:mt-6"
                                    >
                                        {processing && <Spinner />}
                                        {t(
                                            'inventory.reservations.windows_save',
                                        )}
                                    </Button>
                                </>
                            )}
                        </Form>
                    ) : (
                        <dl className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <dt className="text-muted-foreground text-xs">
                                    {t('inventory.reservations.online_minutes')}
                                </dt>
                                <dd className="text-lg font-semibold tabular-nums">
                                    {windows.online_minutes}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">
                                    {t('inventory.reservations.cod_hours')}
                                </dt>
                                <dd className="text-lg font-semibold tabular-nums">
                                    {windows.cod_hours}
                                </dd>
                            </div>
                        </dl>
                    )}
                </SectionCard>

                <SectionCard
                    title={t('inventory.reservations.enforcement_title')}
                    description={t(
                        'inventory.reservations.enforcement_description',
                    )}
                >
                    {can.override ? (
                        <Form
                            {...ReservationController.enforcement.form()}
                            options={{ preserveScroll: true }}
                            className="grid gap-4 sm:grid-cols-[1fr_2fr_auto] sm:items-start"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="stock-enforced">
                                            {t(
                                                'inventory.reservations.enforcement_label',
                                            )}
                                        </Label>
                                        <select
                                            id="stock-enforced"
                                            name="enforced"
                                            defaultValue={
                                                stock_enforced ? '1' : '0'
                                            }
                                            className={selectClass}
                                        >
                                            <option value="1">
                                                {t(
                                                    'inventory.reservations.enforcement_on',
                                                )}
                                            </option>
                                            <option value="0">
                                                {t(
                                                    'inventory.reservations.enforcement_off',
                                                )}
                                            </option>
                                        </select>
                                        <InputError message={errors.enforced} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="stock-enforced-reason">
                                            {t('inventory.reservations.reason')}
                                        </Label>
                                        <ReasonTextarea
                                            context="stock"
                                            id="stock-enforced-reason"
                                            name="reason"
                                            rows={2}
                                            minLength={10}
                                            maxLength={1000}
                                            required
                                            className={controlClass}
                                        />
                                        <InputError message={errors.reason} />
                                    </div>

                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="sm:mt-6"
                                    >
                                        {processing && <Spinner />}
                                        {t(
                                            'inventory.reservations.windows_save',
                                        )}
                                    </Button>
                                </>
                            )}
                        </Form>
                    ) : (
                        <p className="text-sm">
                            {t(
                                stock_enforced
                                    ? 'inventory.reservations.enforcement_on'
                                    : 'inventory.reservations.enforcement_off',
                            )}
                        </p>
                    )}
                </SectionCard>

                <DataTable
                    columns={columns}
                    paginator={reservations}
                    rowKey={(row) => row.id}
                    caption={t('inventory.reservations.caption')}
                    searchPlaceholder={t('inventory.reservations.search')}
                    onlyReload={['reservations', 'filters']}
                    filters={
                        <>
                            <select
                                aria-label={t(
                                    'inventory.reservations.filter_status',
                                )}
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
                                    {t('inventory.reservations.all_statuses')}
                                </option>
                                {[
                                    'active',
                                    'committed',
                                    'released',
                                    'expired',
                                ].map((status) => (
                                    <option key={status} value={status}>
                                        {t(
                                            `inventory.reservation_statuses.${status}`,
                                        )}
                                    </option>
                                ))}
                            </select>

                            <select
                                aria-label={t(
                                    'inventory.reservations.filter_kind',
                                )}
                                value={filters.kind ?? ''}
                                onChange={(event) =>
                                    setFilter(
                                        'kind',
                                        event.target.value || undefined,
                                    )
                                }
                                className={selectClass}
                            >
                                <option value="">
                                    {t('inventory.reservations.all_kinds')}
                                </option>
                                {['online_payment', 'cod'].map((kind) => (
                                    <option key={kind} value={kind}>
                                        {t(
                                            `inventory.reservation_kinds.${kind}`,
                                        )}
                                    </option>
                                ))}
                            </select>
                        </>
                    }
                    renderCard={(row) => (
                        <div className="space-y-2">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <div className="truncate font-mono text-sm font-medium">
                                        {row.reference}
                                    </div>
                                    <div className="text-muted-foreground truncate text-xs">
                                        {row.quantity} × {row.sku} ·{' '}
                                        {row.warehouse} · {row.kind_label}
                                    </div>
                                </div>
                                <StatusPill
                                    tone={row.status_tone}
                                    label={row.status_label}
                                />
                            </div>
                            <p className="text-muted-foreground text-xs">
                                {timing(row)}
                            </p>
                            {actions(row)}
                        </div>
                    )}
                    emptyState={
                        <EmptyState
                            icon={Lock}
                            title={t(
                                filtered
                                    ? 'inventory.reservations.no_matches'
                                    : 'inventory.reservations.empty',
                            )}
                            description={t(
                                filtered
                                    ? 'inventory.reservations.no_matches_help'
                                    : 'inventory.reservations.empty_help',
                            )}
                        />
                    }
                />
            </PageContainer>

            {can.override && overriding && (
                <Dialog
                    open
                    onOpenChange={(next) => !next && setOverriding(null)}
                >
                    <DialogContent className="sm:max-w-lg">
                        <DialogHeader>
                            <DialogTitle>
                                {t(
                                    overriding.mode === 'release'
                                        ? 'inventory.reservations.release_title'
                                        : 'inventory.reservations.extend_title',
                                    { reference: overriding.row.reference },
                                )}
                            </DialogTitle>
                            <DialogDescription>
                                {t(
                                    overriding.mode === 'release'
                                        ? 'inventory.reservations.release_description'
                                        : 'inventory.reservations.extend_description',
                                    {
                                        quantity: overriding.row.quantity,
                                        sku: overriding.row.sku,
                                    },
                                )}
                            </DialogDescription>
                        </DialogHeader>

                        <Form
                            {...(overriding.mode === 'release'
                                ? ReservationController.release.form(
                                      overriding.row.id,
                                  )
                                : ReservationController.extend.form(
                                      overriding.row.id,
                                  ))}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setOverriding(null)}
                            className="space-y-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    {overriding.mode === 'extend' && (
                                        <div className="grid gap-2">
                                            <Label htmlFor="override-until">
                                                {t(
                                                    'inventory.reservations.expires_at',
                                                )}
                                            </Label>
                                            <Input
                                                id="override-until"
                                                name="expires_at"
                                                type="datetime-local"
                                                required
                                            />
                                            <p className="text-muted-foreground text-xs">
                                                {t(
                                                    'inventory.reservations.expires_at_help',
                                                    {
                                                        hours: max_extension_hours,
                                                    },
                                                )}
                                            </p>
                                            <InputError
                                                message={errors.expires_at}
                                            />
                                        </div>
                                    )}

                                    <div className="grid gap-2">
                                        <Label htmlFor="override-reason">
                                            {t('inventory.reservations.reason')}
                                        </Label>
                                        <ReasonTextarea
                                            context="stock"
                                            id="override-reason"
                                            name="reason"
                                            rows={3}
                                            minLength={10}
                                            maxLength={1000}
                                            required
                                            className={controlClass}
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            {t(
                                                'inventory.reservations.reason_help',
                                            )}
                                        </p>
                                        <InputError message={errors.reason} />
                                    </div>

                                    <DialogFooter>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() => setOverriding(null)}
                                        >
                                            {t('common.actions.cancel')}
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            {t(
                                                overriding.mode === 'release'
                                                    ? 'inventory.reservations.release'
                                                    : 'inventory.reservations.extend',
                                            )}
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            )}
        </>
    );
}

AdminReservations.layout = {
    breadcrumbs: [
        {
            title: 'nav.reservations',
            href: index(),
        },
    ],
};
