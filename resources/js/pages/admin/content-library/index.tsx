import { Head, Link, router } from '@inertiajs/react';
import {
    FilePlus2,
    Image as ImageIcon,
    Library,
    Pencil,
    Trash2,
} from 'lucide-react';
import ContentLibraryController from '@/actions/App/Http/Controllers/Admin/ContentLibraryController';
import DataTable from '@/components/data-table/data-table';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import { create, edit } from '@/routes/admin/content-library';
import type { Column, Paginator } from '@/types';
import type { LibraryRow } from '@/types/content-library';

type Props = {
    items: Paginator<LibraryRow>;
    can: { publish: boolean; edit: boolean; delete: boolean };
};

/**
 * Everything released through the Content Library, newest first. New content
 * starts from "Release content": write it, choose the Products, publish.
 */
export default function ContentLibraryIndex({ items, can }: Props) {
    const { t } = useTranslation();
    const { search } = useTableQuery({ only: ['items'] });

    const remove = (row: LibraryRow) => {
        if (
            !window.confirm(
                t('content_library.delete_confirm', { title: row.title }),
            )
        ) {
            return;
        }

        router.delete(ContentLibraryController.destroy.url(row.id), {
            preserveScroll: true,
        });
    };

    const columns: Column<LibraryRow>[] = [
        {
            key: 'title',
            header: t('content_library.columns.title'),
            cell: (row) => (
                <div className="min-w-0 space-y-0.5">
                    <p className="truncate font-medium">{row.title}</p>
                    <p className="text-muted-foreground text-xs">
                        {Object.entries(row.block_counts)
                            .map(
                                ([type, count]) =>
                                    `${count} ${t(`content_library.editor.type.${type}`).toLowerCase()}`,
                            )
                            .join(' · ')}
                    </p>
                </div>
            ),
        },
        {
            key: 'products',
            header: t('content_library.columns.products'),
            cell: (row) => (
                <div className="min-w-0 text-sm">
                    <p className="truncate">
                        {row.products.map((product) => product.name).join(', ')}
                    </p>
                    {row.products_count > row.products.length && (
                        <p className="text-muted-foreground text-xs">
                            {t('content_library.more_products', {
                                count: row.products_count - row.products.length,
                            })}
                        </p>
                    )}
                </div>
            ),
        },
        {
            key: 'published',
            header: t('content_library.columns.published'),
            cell: (row) => (
                <div className="text-sm">
                    <p>{new Date(row.published_at).toLocaleString()}</p>
                    {row.published_by && (
                        <p className="text-muted-foreground text-xs">
                            {row.published_by}
                        </p>
                    )}
                </div>
            ),
        },
        {
            key: 'actions',
            header: '',
            align: 'end',
            alwaysVisible: true,
            cell: (row) => (
                <div className="flex justify-end gap-1">
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={edit.url(row.id)}>
                            <Pencil className="size-4" aria-hidden="true" />
                            {can.edit
                                ? t('common.actions.edit')
                                : t('content_library.open')}
                        </Link>
                    </Button>
                    {can.delete && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => remove(row)}
                        >
                            <Trash2 className="size-4" aria-hidden="true" />
                            <span className="sr-only">
                                {t('common.actions.delete')}
                            </span>
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title={t('content_library.title')} />

            <PageContainer>
                <PageHeader
                    title={t('content_library.title')}
                    description={t('content_library.description')}
                    actions={
                        can.publish && (
                            <Button size="sm" asChild>
                                <Link href={create()}>
                                    <FilePlus2
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t('content_library.release')}
                                </Link>
                            </Button>
                        )
                    }
                />

                <DataTable
                    columns={columns}
                    paginator={items}
                    rowKey={(row) => row.id}
                    caption={t('content_library.title')}
                    searchPlaceholder={t('content_library.search')}
                    onlyReload={['items']}
                    emptyState={
                        <EmptyState
                            icon={search !== '' ? ImageIcon : Library}
                            title={
                                search !== ''
                                    ? t('content_library.no_matches')
                                    : t('content_library.empty')
                            }
                            description={
                                search !== ''
                                    ? t('content_library.no_matches_help')
                                    : t(
                                          can.publish
                                              ? 'content_library.empty_help'
                                              : 'content_library.empty_help_read_only',
                                      )
                            }
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

ContentLibraryIndex.layout = {
    breadcrumbs: [{ title: 'Content library' }],
};
