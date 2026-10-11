import { Head, router } from '@inertiajs/react';
import { ArchiveX, RotateCcw, ShieldAlert, Trash2 } from 'lucide-react';
import { useState } from 'react';
import ProductTrashController from '@/actions/App/Http/Controllers/Admin/ProductTrashController';
import AlertError from '@/components/alert-error';
import DataTable from '@/components/data-table/data-table';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import ForceDeleteDialog from './force-delete-dialog';
import { useTranslation } from '@/hooks/use-translation';
import { index as productsIndex } from '@/routes/admin/catalog/products';
import type { Column, Paginator, TrashedProductRow } from '@/types';

type Props = {
    products: Paginator<TrashedProductRow>;
    can: { delete: boolean; force_delete: boolean };
};

/**
 * Every product moved out of circulation (urgent product-management fix).
 *
 * A separate screen from the active catalogue, not `withTrashed()` shown
 * inline: a trashed product may still carry real business history, and
 * Permanent Delete is refused — with the specific reason why, not just a
 * disabled button — whenever it does. Restoring puts it straight back where
 * it was, with the same public id and the same history.
 */
export default function ProductTrash({ products, can }: Props) {
    const { t } = useTranslation();
    const { search } = useTableQuery({ only: ['products'] });
    const [error, setError] = useState<string | undefined>();

    const columns: Column<TrashedProductRow>[] = [
        {
            key: 'name',
            header: t('catalog.products.columns.product'),
            cell: (row) => (
                <div className="min-w-0 space-y-0.5">
                    <p className="truncate font-medium">{row.name}</p>
                    <p className="text-muted-foreground truncate text-xs">
                        {t('catalog.browse.sku', { sku: row.sku })} ·{' '}
                        {row.category}
                        {row.brand ? ` · ${row.brand}` : ''}
                    </p>
                </div>
            ),
        },
        {
            key: 'status',
            header: t('catalog.products.columns.status'),
            cell: (row) => (
                <StatusPill
                    tone={row.status_tone}
                    label={t(`catalog.products.status.${row.status}`)}
                />
            ),
        },
        {
            key: 'deleted_at',
            header: t('catalog.products.trash.deleted_at'),
            cell: (row) => (
                <span className="text-sm">
                    {new Date(row.deleted_at).toLocaleString()}
                </span>
            ),
        },
        {
            key: 'deleted_by',
            header: t('catalog.products.trash.deleted_by'),
            cell: (row) => (
                <span className="text-sm">
                    {row.deleted_by ?? t('catalog.products.trash.system')}
                </span>
            ),
        },
        {
            key: 'deletion_reason',
            header: t('catalog.products.trash.deletion_reason'),
            cell: (row) => (
                <p className="text-muted-foreground max-w-xs truncate text-sm">
                    {row.deletion_reason}
                </p>
            ),
        },
        {
            key: 'actions',
            header: '',
            align: 'end',
            alwaysVisible: true,
            cell: (row) => (
                <RowActions row={row} can={can} setError={setError} />
            ),
        },
    ];

    return (
        <>
            <Head title={t('catalog.products.trash.title')} />

            <PageContainer>
                <PageHeader
                    title={t('catalog.products.trash.title')}
                    description={t('catalog.products.trash.description')}
                    back={{
                        href: productsIndex(),
                        label: t('catalog.products.trash.back'),
                    }}
                />

                {error && <AlertError errors={[error]} />}

                <DataTable
                    columns={columns}
                    paginator={products}
                    rowKey={(row) => row.id}
                    caption={t('catalog.products.trash.title')}
                    searchPlaceholder={t('catalog.products.search')}
                    onlyReload={['products']}
                    emptyState={
                        <EmptyState
                            icon={ArchiveX}
                            title={
                                search !== ''
                                    ? t('catalog.products.trash.no_matches')
                                    : t('catalog.products.trash.empty')
                            }
                            description={
                                search !== ''
                                    ? t(
                                          'catalog.products.trash.no_matches_help',
                                      )
                                    : t('catalog.products.trash.empty_help')
                            }
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

function RowActions({
    row,
    can,
    setError,
}: {
    row: TrashedProductRow;
    can: { delete: boolean; force_delete: boolean };
    setError: (error: string | undefined) => void;
}) {
    const { t } = useTranslation();
    const [forceOpen, setForceOpen] = useState(false);

    if (!can.delete) {
        return null;
    }

    const restore = () => {
        setError(undefined);

        router.post(
            ProductTrashController.restore.url(row.id),
            {},
            {
                preserveScroll: true,
                onError: (errors) => setError(errors.product),
            },
        );
    };

    const permanentlyDelete = () => {
        if (
            !window.confirm(
                t('catalog.products.trash.permanent_delete_confirm', {
                    name: row.name,
                }),
            )
        ) {
            return;
        }

        setError(undefined);

        router.delete(ProductTrashController.destroy.url(row.id), {
            preserveScroll: true,
            onError: (errors) => setError(errors.product),
        });
    };

    return (
        <div className="flex flex-wrap items-center justify-end gap-1">
            <Button variant="ghost" size="sm" onClick={restore}>
                <RotateCcw className="size-4" aria-hidden="true" />
                {t('catalog.products.trash.restore')}
            </Button>
            <Button variant="ghost" size="sm" onClick={permanentlyDelete}>
                <Trash2 className="text-danger size-4" aria-hidden="true" />
                {t('catalog.products.trash.permanent_delete')}
            </Button>
            {can.force_delete && (
                <>
                    <Button
                        variant="destructive"
                        size="sm"
                        onClick={() => setForceOpen(true)}
                    >
                        <ShieldAlert className="size-4" aria-hidden="true" />
                        {t('catalog.products.force_delete.button')}
                    </Button>
                    <ForceDeleteDialog
                        row={row}
                        open={forceOpen}
                        onOpenChange={setForceOpen}
                    />
                </>
            )}
        </div>
    );
}
