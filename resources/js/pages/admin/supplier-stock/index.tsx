import { Form, Head } from '@inertiajs/react';
import { Boxes } from 'lucide-react';
import SubmitButton from '@/components/forms/submit-button';
import DataTable from '@/components/data-table/data-table';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/admin/supplier-stock';
import { store as decision } from '@/routes/admin/supplier-stock/decision';
import type { Column, Paginator } from '@/types';

type Row = {
    id: string;
    supplier: string;
    product_name: string;
    current_quantity: number;
    requested_quantity: number;
    note: string | null;
};

export default function AdminSupplierStockIndex({
    updates,
    can_edit,
}: {
    updates: Paginator<Row>;
    can_edit: boolean;
}) {
    const { t, locale } = useTranslation();

    const columns: Column<Row>[] = [
        {
            key: 'product',
            header: t('supplier.admin.stock.columns.product'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate font-medium">
                        {row.product_name}
                    </div>
                    <div className="text-muted-foreground truncate text-xs">
                        {row.supplier}
                    </div>
                </div>
            ),
        },
        {
            key: 'current',
            header: t('supplier.admin.stock.columns.current'),
            align: 'end',
            cell: (row) => row.current_quantity.toLocaleString(locale),
        },
        {
            key: 'requested',
            header: t('supplier.admin.stock.columns.requested'),
            align: 'end',
            cell: (row) => row.requested_quantity.toLocaleString(locale),
        },
        {
            key: 'actions',
            header: '',
            alwaysVisible: true,
            align: 'end',
            cell: (row) =>
                can_edit ? (
                    <div className="flex justify-end gap-2">
                        {(['approve', 'reject'] as const).map((outcome) => (
                            <Form
                                key={outcome}
                                {...decision.form(row.id)}
                                transform={(data) => ({
                                    ...data,
                                    decision: outcome,
                                })}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <SubmitButton
                                        processing={processing}
                                        size="sm"
                                        variant={
                                            outcome === 'approve'
                                                ? 'default'
                                                : 'outline'
                                        }
                                    >
                                        {t(`supplier.admin.stock.${outcome}`)}
                                    </SubmitButton>
                                )}
                            </Form>
                        ))}
                    </div>
                ) : null,
        },
    ];

    return (
        <>
            <Head title={t('supplier.admin.stock.title')} />

            <PageContainer>
                <PageHeader
                    title={t('supplier.admin.stock.title')}
                    description={t('supplier.admin.stock.description')}
                />

                <DataTable
                    columns={columns}
                    paginator={updates}
                    rowKey={(row) => row.id}
                    caption={t('supplier.admin.stock.title')}
                    onlyReload={['updates']}
                    emptyState={
                        <EmptyState
                            icon={Boxes}
                            title={t('supplier.admin.stock.empty_title')}
                            description={t(
                                'supplier.admin.stock.empty_description',
                            )}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

AdminSupplierStockIndex.layout = {
    breadcrumbs: [{ title: 'Supplier availability', href: index() }],
};
