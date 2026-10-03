import { Form, Head, Link } from '@inertiajs/react';
import { Layers, Plus } from 'lucide-react';
import { useState } from 'react';
import SourcingGroupController from '@/actions/App/Http/Controllers/Admin/SourcingGroupController';
import DataTable from '@/components/data-table/data-table';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import type { Column, Paginator } from '@/types';

const ALL = 'all';
const RELOAD_PROPS = ['groups'];

type Row = {
    id: string;
    code: string;
    name_en: string;
    name_bn: string;
    is_active: boolean;
    products_count: number;
    mappings_count: number;
};

/**
 * The Product Sourcing Groups list. Which products can fulfil one another's
 * orders is a staff decision made here, never inferred from names or
 * categories.
 */
export default function SourcingGroupsIndex({
    groups,
    can,
}: {
    groups: Paginator<Row>;
    can: { create: boolean };
}) {
    const { t, locale } = useTranslation();
    const { getFilter, setFilter } = useTableQuery({ only: RELOAD_PROPS });
    const [creating, setCreating] = useState(false);

    const columns: Column<Row>[] = [
        {
            key: 'group',
            header: t('sourcing.columns.group'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate font-medium">
                        {locale === 'bn' ? row.name_bn : row.name_en}
                    </div>
                    <div className="text-muted-foreground truncate text-xs">
                        {row.code}
                    </div>
                </div>
            ),
        },
        {
            key: 'products',
            header: t('sourcing.columns.products'),
            cell: (row) => row.products_count,
        },
        {
            key: 'mappings',
            header: t('sourcing.columns.mappings'),
            priority: 'secondary',
            cell: (row) => row.mappings_count,
        },
        {
            key: 'status',
            header: t('sourcing.columns.status'),
            cell: (row) => (
                <StatusPill
                    tone={row.is_active ? 'success' : 'neutral'}
                    label={
                        row.is_active
                            ? t('sourcing.status.active')
                            : t('sourcing.status.inactive')
                    }
                />
            ),
        },
        {
            key: 'actions',
            header: '',
            alwaysVisible: true,
            align: 'end',
            cell: (row) => (
                <Button variant="ghost" size="sm" asChild>
                    <Link
                        href={SourcingGroupController.show.url({
                            group: row.id,
                        })}
                    >
                        {t('sourcing.open')}
                    </Link>
                </Button>
            ),
        },
    ];

    const createButton = can.create ? (
        <Button size="sm" onClick={() => setCreating(true)}>
            <Plus className="size-4" aria-hidden="true" />
            {t('sourcing.create')}
        </Button>
    ) : undefined;

    return (
        <>
            <Head title={t('sourcing.title')} />

            <PageContainer>
                <PageHeader
                    title={t('sourcing.title')}
                    description={t('sourcing.description')}
                    actions={createButton}
                />

                <DataTable
                    columns={columns}
                    paginator={groups}
                    rowKey={(row) => row.id}
                    caption={t('sourcing.title')}
                    searchPlaceholder={t('sourcing.search_placeholder')}
                    onlyReload={RELOAD_PROPS}
                    emptyState={
                        <EmptyState
                            icon={Layers}
                            title={t('sourcing.empty_title')}
                            description={t('sourcing.empty_description')}
                        />
                    }
                    filters={
                        <Select
                            value={getFilter('status') ?? ALL}
                            onValueChange={(value) =>
                                setFilter(
                                    'status',
                                    value === ALL ? undefined : value,
                                )
                            }
                        >
                            <SelectTrigger
                                size="sm"
                                className="w-44"
                                aria-label={t('sourcing.columns.status')}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>
                                    {t('sourcing.all_statuses')}
                                </SelectItem>
                                <SelectItem value="active">
                                    {t('sourcing.status.active')}
                                </SelectItem>
                                <SelectItem value="inactive">
                                    {t('sourcing.status.inactive')}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    }
                />
            </PageContainer>

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {t('sourcing.form.create_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('sourcing.description')}
                        </DialogDescription>
                    </DialogHeader>

                    <Form
                        {...SourcingGroupController.store.form()}
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <FormField
                                    label={t('sourcing.form.code')}
                                    description={t('sourcing.form.code_help')}
                                    error={errors.code}
                                    required
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            name="code"
                                            autoComplete="off"
                                            required
                                        />
                                    )}
                                </FormField>
                                <FormField
                                    label={t('sourcing.form.name_en')}
                                    error={errors.name_en}
                                    required
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            name="name_en"
                                            required
                                        />
                                    )}
                                </FormField>
                                <FormField
                                    label={t('sourcing.form.name_bn')}
                                    error={errors.name_bn}
                                    required
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            name="name_bn"
                                            required
                                        />
                                    )}
                                </FormField>
                                <FormField
                                    label={t('sourcing.form.description')}
                                    error={errors.description}
                                >
                                    {(field) => (
                                        <Input {...field} name="description" />
                                    )}
                                </FormField>
                                <SubmitButton processing={processing}>
                                    {t('sourcing.form.save')}
                                </SubmitButton>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </>
    );
}
