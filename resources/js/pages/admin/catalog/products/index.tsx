import { Head, Link, router } from '@inertiajs/react';
import { ImageOff, Plus, ShoppingBag, X } from 'lucide-react';
import { useState } from 'react';
import ProductBulkController from '@/actions/App/Http/Controllers/Admin/ProductBulkController';
import AlertError from '@/components/alert-error';
import DataTable from '@/components/data-table/data-table';
import FormField from '@/components/forms/form-field';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
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
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import type { StatusTone } from '@/lib/status';
import { create, edit } from '@/routes/admin/catalog/products';
import type {
    CatalogAbilities,
    CatalogOption,
    Column,
    Paginator,
    ProductBulkOptions,
    ProductBulkResult,
    ProductListFilters,
    ProductRow,
    SalesChannelName,
} from '@/types';

type Props = {
    products: Paginator<ProductRow>;
    filters: ProductListFilters;
    filter_options: { categories: CatalogOption[]; brands: CatalogOption[] };
    can: CatalogAbilities;
    bulk: ProductBulkOptions;
};

/**
 * One bulk action, as the select encodes it: `transition:active`,
 * `channel:wholesale:1`, `feature:0`.
 */
type BulkChoice =
    | { action: 'transition'; status: string; requiresReason: boolean }
    | { action: 'channel'; channel: SalesChannelName; enable: boolean }
    | { action: 'feature'; enable: boolean }
    | { action: 'category' }
    | { action: 'brand' };

const CHANNELS: SalesChannelName[] = ['dropshipping', 'wholesale'];

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-8 rounded-md border px-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * The central catalogue (§11).
 *
 * Server-paginated, searched, filtered and sorted in the database (§39): a
 * catalogue grows to thousands of products, and a browser can only filter what
 * it was sent. The wholesale price is the server's own rendering — the page
 * formats no money.
 *
 * Bulk actions offer only what this person's permissions reach, and every
 * product is still checked by the server for its own move. What the server
 * refused is listed by product with its reason, rather than summarised away.
 */
export default function AdminProducts({
    products,
    filters,
    filter_options,
    can,
    bulk,
}: Props) {
    const { t } = useTranslation();
    const { search, setFilter, clearAll } = useTableQuery({
        only: ['products', 'filters'],
    });

    const [selected, setSelected] = useState<Set<string | number>>(
        () => new Set(),
    );
    const [choice, setChoice] = useState('');
    const [confirming, setConfirming] = useState(false);
    const [result, setResult] = useState<ProductBulkResult | null>(null);

    const offersBulk =
        bulk.transitions.length > 0 ||
        bulk.enable_channels ||
        bulk.disable_channels ||
        bulk.feature ||
        bulk.assign;

    const filtered =
        search !== '' || Object.values(filters).some((value) => value !== null);

    const columns: Column<ProductRow>[] = [
        {
            key: 'name',
            header: t('catalog.products.columns.product'),
            sortable: true,
            cell: (row) => (
                <div className="flex min-w-0 items-center gap-3">
                    <ListingImage row={row} />
                    <div className="min-w-0 space-y-0.5">
                        <Link
                            href={edit(row.id)}
                            className="block truncate font-medium hover:underline"
                        >
                            {row.name}
                        </Link>
                        <div className="flex flex-wrap items-center gap-1.5">
                            <span className="text-muted-foreground font-mono text-xs">
                                {row.sku}
                            </span>
                            {row.is_featured && (
                                <StatusPill
                                    tone="info"
                                    label={t('catalog.products.featured')}
                                />
                            )}
                        </div>
                    </div>
                </div>
            ),
        },
        {
            key: 'category',
            header: t('catalog.products.columns.category'),
            priority: 'secondary',
            cell: (row) => row.category,
        },
        {
            key: 'brand',
            header: t('catalog.products.columns.brand'),
            priority: 'secondary',
            cell: (row) => row.brand ?? '—',
        },
        {
            key: 'channels',
            header: t('catalog.products.columns.channels'),
            priority: 'secondary',
            cell: (row) => <ChannelPills row={row} />,
        },
        {
            key: 'wholesale_price_minor',
            header: t('catalog.products.columns.wholesale_price'),
            align: 'end',
            sortable: true,
            cell: (row) => <MoneyAmount amount={row.wholesale_price} />,
        },
        {
            key: 'status',
            header: t('catalog.products.columns.status'),
            cell: (row) => (
                <ProductStatusPill status={row.status} tone={row.status_tone} />
            ),
        },
        {
            key: 'actions',
            header: '',
            align: 'end',
            alwaysVisible: true,
            cell: (row) => <RowLinks row={row} />,
        },
    ];

    const parsed = parseChoice(choice, bulk);

    const describe = (value: BulkChoice): string => {
        switch (value.action) {
            case 'transition':
                return t(`catalog.products.transitions.${value.status}`);
            case 'channel':
                return t(
                    value.enable
                        ? 'catalog.products.bulk.enable_channel'
                        : 'catalog.products.bulk.disable_channel',
                    { channel: t(`catalog.channels.${value.channel}`) },
                );
            case 'feature':
                return t(
                    value.enable
                        ? 'catalog.products.bulk.feature'
                        : 'catalog.products.bulk.unfeature',
                );
            case 'category':
                return t('catalog.products.bulk.assign_category');
            case 'brand':
                return t('catalog.products.bulk.assign_brand');
        }
    };

    return (
        <>
            <Head title={t('catalog.products.title')} />

            <PageContainer>
                <PageHeader
                    title={t('catalog.products.title')}
                    description={t('catalog.products.description')}
                    actions={
                        can.create ? (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t('catalog.products.create')}
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />

                {result && result.refused.length > 0 && (
                    <div className="space-y-2">
                        <AlertError
                            title={t('catalog.products.bulk.result_title')}
                            errors={result.refused.map((refusal) =>
                                refusal.name === null
                                    ? refusal.reason
                                    : `${refusal.name} (${refusal.sku}) — ${refusal.reason}`,
                            )}
                        />
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setResult(null)}
                        >
                            <X className="size-4" aria-hidden="true" />
                            {t('catalog.products.bulk.dismiss')}
                        </Button>
                    </div>
                )}

                <DataTable
                    columns={columns}
                    paginator={products}
                    rowKey={(row) => row.id}
                    caption={t('catalog.products.caption')}
                    searchPlaceholder={t('catalog.products.search')}
                    onlyReload={['products', 'filters']}
                    selection={
                        offersBulk
                            ? { selected, onChange: setSelected }
                            : undefined
                    }
                    bulkActions={
                        offersBulk
                            ? (rows) => (
                                  <>
                                      <select
                                          aria-label={t(
                                              'catalog.products.bulk.label',
                                          )}
                                          value={choice}
                                          onChange={(event) =>
                                              setChoice(event.target.value)
                                          }
                                          className={selectClass}
                                      >
                                          <option value="">
                                              {t(
                                                  'catalog.products.bulk.choose',
                                              )}
                                          </option>
                                          <BulkOptions bulk={bulk} />
                                      </select>
                                      <Button
                                          size="sm"
                                          disabled={
                                              parsed === null ||
                                              rows.size > bulk.max
                                          }
                                          onClick={() => setConfirming(true)}
                                      >
                                          {t('catalog.products.bulk.apply')}
                                      </Button>
                                      <Button
                                          size="sm"
                                          variant="ghost"
                                          onClick={() => setSelected(new Set())}
                                      >
                                          {t('catalog.products.bulk.clear')}
                                      </Button>
                                  </>
                              )
                            : undefined
                    }
                    filters={
                        <>
                            <select
                                aria-label={t(
                                    'catalog.products.filters.status',
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
                                    {t('catalog.products.filters.all_statuses')}
                                </option>
                                {LIFECYCLE.map((status) => (
                                    <option key={status} value={status}>
                                        {t(`catalog.products.status.${status}`)}
                                    </option>
                                ))}
                            </select>

                            <select
                                aria-label={t(
                                    'catalog.products.filters.category',
                                )}
                                value={filters.category ?? ''}
                                onChange={(event) =>
                                    setFilter(
                                        'category',
                                        event.target.value || undefined,
                                    )
                                }
                                className={`${selectClass} max-w-48`}
                            >
                                <option value="">
                                    {t(
                                        'catalog.products.filters.all_categories',
                                    )}
                                </option>
                                {filter_options.categories.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>

                            <select
                                aria-label={t('catalog.products.filters.brand')}
                                value={filters.brand ?? ''}
                                onChange={(event) =>
                                    setFilter(
                                        'brand',
                                        event.target.value || undefined,
                                    )
                                }
                                className={`${selectClass} max-w-40`}
                            >
                                <option value="">
                                    {t('catalog.products.filters.all_brands')}
                                </option>
                                {filter_options.brands.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>

                            <select
                                aria-label={t(
                                    'catalog.products.filters.channel',
                                )}
                                value={filters.channel ?? ''}
                                onChange={(event) =>
                                    setFilter(
                                        'channel',
                                        event.target.value || undefined,
                                    )
                                }
                                className={selectClass}
                            >
                                <option value="">
                                    {t('catalog.products.filters.all_channels')}
                                </option>
                                {CHANNELS.flatMap((channel) => [
                                    `${channel}_enabled`,
                                    `${channel}_disabled`,
                                ]).map((status) => (
                                    <option key={status} value={status}>
                                        {t(`catalog.products.status.${status}`)}
                                    </option>
                                ))}
                            </select>

                            <select
                                aria-label={t(
                                    'catalog.products.filters.featured',
                                )}
                                value={filters.featured ?? ''}
                                onChange={(event) =>
                                    setFilter(
                                        'featured',
                                        event.target.value || undefined,
                                    )
                                }
                                className={selectClass}
                            >
                                <option value="">
                                    {t('catalog.products.filters.featured_any')}
                                </option>
                                <option value="yes">
                                    {t('catalog.products.filters.featured_yes')}
                                </option>
                                <option value="no">
                                    {t('catalog.products.filters.featured_no')}
                                </option>
                            </select>

                            {filtered && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={clearAll}
                                >
                                    <X className="size-4" aria-hidden="true" />
                                    {t('catalog.products.filters.clear')}
                                </Button>
                            )}
                        </>
                    }
                    renderCard={(row) => (
                        <div className="space-y-2">
                            <div className="flex items-start justify-between gap-3">
                                <div className="flex min-w-0 items-start gap-3">
                                    <ListingImage row={row} />
                                    <div className="min-w-0 space-y-0.5">
                                        <Link
                                            href={edit(row.id)}
                                            className="block truncate font-medium hover:underline"
                                        >
                                            {row.name}
                                        </Link>
                                        <p className="text-muted-foreground font-mono text-xs">
                                            {row.sku}
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            {row.category}
                                            {row.brand ? ` · ${row.brand}` : ''}
                                        </p>
                                    </div>
                                </div>
                                <div className="flex shrink-0 flex-col items-end gap-1">
                                    <MoneyAmount amount={row.wholesale_price} />
                                    <ProductStatusPill
                                        status={row.status}
                                        tone={row.status_tone}
                                    />
                                    {row.is_featured && (
                                        <StatusPill
                                            tone="info"
                                            label={t(
                                                'catalog.products.featured',
                                            )}
                                        />
                                    )}
                                </div>
                            </div>
                            <ChannelPills row={row} />
                            <div className="flex items-center justify-between gap-2">
                                {offersBulk && (
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            checked={selected.has(row.id)}
                                            onChange={() => {
                                                const next = new Set(selected);

                                                if (next.has(row.id)) {
                                                    next.delete(row.id);
                                                } else {
                                                    next.add(row.id);
                                                }

                                                setSelected(next);
                                            }}
                                            className="size-4"
                                        />
                                        <span className="sr-only">
                                            {row.name}
                                        </span>
                                    </label>
                                )}
                                <RowLinks row={row} />
                            </div>
                        </div>
                    )}
                    emptyState={
                        <EmptyState
                            icon={ShoppingBag}
                            title={t(
                                filtered
                                    ? 'catalog.products.no_matches'
                                    : 'catalog.products.empty',
                            )}
                            description={t(
                                filtered
                                    ? 'catalog.products.no_matches_help'
                                    : 'catalog.products.empty_help',
                            )}
                        />
                    }
                />
            </PageContainer>

            {confirming && parsed && (
                <BulkConfirmDialog
                    choice={parsed}
                    options={filter_options}
                    label={describe(parsed)}
                    ids={Array.from(selected).map(String)}
                    max={bulk.max}
                    onClose={() => setConfirming(false)}
                    onDone={(done) => {
                        setResult(done);
                        setSelected(new Set());
                        setChoice('');
                        setConfirming(false);
                    }}
                />
            )}
        </>
    );
}

const LIFECYCLE = [
    'draft',
    'pending_review',
    'active',
    'inactive',
    'out_of_stock',
    'discontinued',
    'archived',
];

function parseChoice(
    value: string,
    bulk: ProductBulkOptions,
): BulkChoice | null {
    const [action, first, second] = value.split(':');

    if (action === 'transition') {
        const target = bulk.transitions.find((item) => item.value === first);

        return target
            ? {
                  action,
                  status: target.value,
                  requiresReason: target.requires_reason,
              }
            : null;
    }

    if (action === 'channel' && CHANNELS.includes(first as SalesChannelName)) {
        return {
            action,
            channel: first as SalesChannelName,
            enable: second === '1',
        };
    }

    if (action === 'feature') {
        return { action, enable: first === '1' };
    }

    if ((action === 'category' || action === 'brand') && bulk.assign) {
        return { action };
    }

    return null;
}

function BulkOptions({ bulk }: { bulk: ProductBulkOptions }) {
    const { t } = useTranslation();

    return (
        <>
            {bulk.transitions.length > 0 && (
                <optgroup label={t('catalog.products.bulk.lifecycle')}>
                    {bulk.transitions.map((target) => (
                        <option
                            key={target.value}
                            value={`transition:${target.value}`}
                        >
                            {t(`catalog.products.transitions.${target.value}`)}
                        </option>
                    ))}
                </optgroup>
            )}

            {(bulk.enable_channels || bulk.disable_channels) && (
                <optgroup label={t('catalog.products.bulk.channels')}>
                    {CHANNELS.flatMap((channel) => [
                        bulk.enable_channels && (
                            <option
                                key={`${channel}-on`}
                                value={`channel:${channel}:1`}
                            >
                                {t('catalog.products.bulk.enable_channel', {
                                    channel: t(`catalog.channels.${channel}`),
                                })}
                            </option>
                        ),
                        bulk.disable_channels && (
                            <option
                                key={`${channel}-off`}
                                value={`channel:${channel}:0`}
                            >
                                {t('catalog.products.bulk.disable_channel', {
                                    channel: t(`catalog.channels.${channel}`),
                                })}
                            </option>
                        ),
                    ])}
                </optgroup>
            )}

            {bulk.assign && (
                <optgroup label={t('catalog.products.bulk.placement')}>
                    <option value="category">
                        {t('catalog.products.bulk.assign_category')}
                    </option>
                    <option value="brand">
                        {t('catalog.products.bulk.assign_brand')}
                    </option>
                </optgroup>
            )}

            {bulk.feature && (
                <optgroup label={t('catalog.products.bulk.featuring')}>
                    <option value="feature:1">
                        {t('catalog.products.bulk.feature')}
                    </option>
                    <option value="feature:0">
                        {t('catalog.products.bulk.unfeature')}
                    </option>
                </optgroup>
            )}
        </>
    );
}

/**
 * Confirms a bulk action and sends it.
 *
 * The result arrives as flash data and is handed back product by product, so
 * the list can say exactly which products were left alone and why.
 */
function BulkConfirmDialog({
    choice,
    options,
    label,
    ids,
    max,
    onClose,
    onDone,
}: {
    choice: BulkChoice;
    /** Where products may be filed; switched-off ones are labelled, not hidden. */
    options: { categories: CatalogOption[]; brands: CatalogOption[] };
    label: string;
    ids: string[];
    max: number;
    onClose: () => void;
    onDone: (result: ProductBulkResult | null) => void;
}) {
    const { t } = useTranslation();
    const [reason, setReason] = useState('');
    const [target, setTarget] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const submit = () => {
        let received: ProductBulkResult | null = null;

        router.post(
            ProductBulkController.url(),
            {
                products: ids,
                action: choice.action,
                ...(choice.action === 'transition'
                    ? { status: choice.status, reason: reason || null }
                    : {}),
                ...(choice.action === 'channel'
                    ? { channel: choice.channel, enable: choice.enable }
                    : {}),
                ...(choice.action === 'feature'
                    ? { enable: choice.enable }
                    : {}),
                ...(choice.action === 'category' ? { category: target } : {}),
                // A blank brand is "no brand", sent as null so it is present.
                ...(choice.action === 'brand'
                    ? { brand: target === '' ? null : target }
                    : {}),
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onFlash: (flash) => {
                    received =
                        (flash.bulk_result as ProductBulkResult | undefined) ??
                        null;
                },
                onError: (failed) => setErrors(failed),
                onSuccess: () => onDone(received),
            },
        );
    };

    const reasonRequired =
        choice.action === 'transition' && choice.requiresReason;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {t('catalog.products.bulk.confirm_title', {
                            count: ids.length,
                        })}
                    </DialogTitle>
                    <DialogDescription>
                        {t('catalog.products.bulk.confirm_help')}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
                    <p className="text-sm font-medium">
                        {t('catalog.products.bulk.confirm_action', {
                            action: label,
                        })}
                    </p>

                    {choice.action === 'transition' && (
                        <FormField
                            label={t('catalog.products.bulk.reason')}
                            hint={t('catalog.products.bulk.reason_help')}
                            required={reasonRequired}
                            error={errors.reason}
                        >
                            {(field) => (
                                <textarea
                                    {...field}
                                    rows={3}
                                    maxLength={2000}
                                    required={reasonRequired}
                                    value={reason}
                                    onChange={(event) =>
                                        setReason(event.target.value)
                                    }
                                    className="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                />
                            )}
                        </FormField>
                    )}

                    {(choice.action === 'category' ||
                        choice.action === 'brand') && (
                        <FormField
                            label={t(
                                choice.action === 'category'
                                    ? 'catalog.products.bulk.target_category'
                                    : 'catalog.products.bulk.target_brand',
                            )}
                            required={choice.action === 'category'}
                            error={errors[choice.action]}
                        >
                            {(field) => (
                                <select
                                    {...field}
                                    value={target}
                                    onChange={(event) =>
                                        setTarget(event.target.value)
                                    }
                                    className="border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                >
                                    <option value="">
                                        {t(
                                            choice.action === 'category'
                                                ? 'catalog.products.bulk.choose_target'
                                                : 'catalog.products.bulk.no_brand',
                                        )}
                                    </option>
                                    {(choice.action === 'category'
                                        ? options.categories
                                        : options.brands
                                    ).map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.is_available
                                                ? option.label
                                                : t(
                                                      'catalog.products.bulk.switched_off',
                                                      { name: option.label },
                                                  )}
                                        </option>
                                    ))}
                                </select>
                            )}
                        </FormField>
                    )}

                    {Object.keys(errors).filter((key) => key !== 'reason')
                        .length > 0 && (
                        <AlertError
                            errors={Object.entries(errors)
                                .filter(([key]) => key !== 'reason')
                                .map(([, message]) => message)}
                        />
                    )}

                    <p className="text-muted-foreground text-xs">
                        {t('catalog.products.bulk.limit', { max })}
                    </p>
                </div>

                <DialogFooter>
                    <Button variant="ghost" onClick={onClose}>
                        {t('common.actions.cancel')}
                    </Button>
                    <Button
                        onClick={submit}
                        disabled={
                            processing ||
                            (reasonRequired && reason.trim() === '') ||
                            (choice.action === 'category' && target === '')
                        }
                    >
                        {t('catalog.products.bulk.apply')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * The listing image, or a placeholder that says there is none in words.
 */
function ListingImage({ row }: { row: ProductRow }) {
    const { t } = useTranslation();

    return row.image_url ? (
        <img
            src={row.image_url}
            alt=""
            className="bg-muted size-10 shrink-0 rounded-md border object-cover"
        />
    ) : (
        <span
            className="bg-muted text-muted-foreground flex size-10 shrink-0 items-center justify-center rounded-md border"
            title={t('catalog.products.no_image')}
        >
            <ImageOff className="size-4" aria-hidden="true" />
            <span className="sr-only">{t('catalog.products.no_image')}</span>
        </span>
    );
}

function ChannelPills({ row }: { row: ProductRow }) {
    const { t } = useTranslation();

    return (
        <div className="flex flex-wrap gap-1">
            {row.channels.map((channel) => (
                <StatusPill
                    key={channel.channel}
                    tone={channel.tone}
                    label={t(`catalog.products.status.${channel.status}`)}
                />
            ))}
        </div>
    );
}

/**
 * Straight to the editor's media manager and variation builder, with how much
 * each already holds.
 */
function RowLinks({ row }: { row: ProductRow }) {
    const { t } = useTranslation();

    return (
        <div className="flex flex-wrap items-center justify-end gap-1">
            <Button variant="ghost" size="sm" asChild>
                <Link href={`${edit.url(row.id)}#media`}>
                    {t('catalog.products.manage_media')}
                    <span className="text-muted-foreground tabular-nums">
                        {row.media_count}
                    </span>
                </Link>
            </Button>
            <Button variant="ghost" size="sm" asChild>
                <Link href={`${edit.url(row.id)}#variants`}>
                    {t('catalog.products.manage_variants')}
                    <span className="text-muted-foreground tabular-nums">
                        {row.variants_count}
                    </span>
                </Link>
            </Button>
        </div>
    );
}

/**
 * A product's status, always in words (§33.9).
 */
export function ProductStatusPill({
    status,
    tone,
}: {
    status: string;
    tone: StatusTone;
}) {
    const { t } = useTranslation();

    return (
        <StatusPill
            tone={tone}
            label={t(`catalog.products.status.${status}`)}
        />
    );
}
