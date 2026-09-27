import { Head, router } from '@inertiajs/react';
import { Pencil, Plus, Star, Warehouse as WarehouseIcon } from 'lucide-react';
import { useState } from 'react';
import WarehouseController from '@/actions/App/Http/Controllers/Admin/WarehouseController';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/admin/inventory/warehouses';
import type { WarehouseRow } from '@/types/inventory';
import WarehouseDialog from './warehouse-dialog';

type Props = {
    warehouses: WarehouseRow[];
    can: { edit: boolean };
};

/**
 * Where central stock is held (§19, D15).
 *
 * Listed in the order stock is looked for: the default first, then by
 * priority. Which one is the default, and which are switched off, is said in
 * words beside the name (§33.9).
 */
export default function AdminWarehouses({ warehouses, can }: Props) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState<WarehouseRow | null>(null);
    const [creating, setCreating] = useState(false);

    const makeDefault = (warehouse: WarehouseRow) => {
        if (
            !window.confirm(
                t('inventory.warehouses.make_default_confirm', {
                    name: warehouse.name,
                }),
            )
        ) {
            return;
        }

        router.patch(
            WarehouseController.makeDefault.url(warehouse.id),
            {},
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title={t('inventory.warehouses.title')} />

            <PageContainer>
                <PageHeader
                    title={t('inventory.warehouses.title')}
                    description={t('inventory.warehouses.description')}
                    actions={
                        can.edit ? (
                            <Button onClick={() => setCreating(true)}>
                                <Plus className="size-4" aria-hidden="true" />
                                {t('inventory.warehouses.create')}
                            </Button>
                        ) : undefined
                    }
                />

                {warehouses.length === 0 ? (
                    <EmptyState
                        icon={WarehouseIcon}
                        title={t('inventory.warehouses.empty')}
                        description={t(
                            can.edit
                                ? 'inventory.warehouses.empty_help'
                                : 'inventory.warehouses.empty_help_read_only',
                        )}
                    />
                ) : (
                    <ul
                        aria-label={t('inventory.warehouses.caption')}
                        className="bg-card divide-border divide-y rounded-xl border"
                    >
                        {warehouses.map((warehouse) => (
                            <li
                                key={warehouse.id}
                                className="flex flex-wrap items-start justify-between gap-3 px-5 py-4"
                            >
                                <div className="min-w-0 space-y-1.5">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">
                                            {warehouse.name}
                                        </span>
                                        <span className="text-muted-foreground font-mono text-xs">
                                            {warehouse.code}
                                        </span>
                                        {warehouse.is_default && (
                                            <StatusPill
                                                tone="info"
                                                icon={Star}
                                                label={t(
                                                    'inventory.warehouses.default',
                                                )}
                                            />
                                        )}
                                        <StatusPill
                                            tone={
                                                warehouse.is_active
                                                    ? 'success'
                                                    : 'neutral'
                                            }
                                            label={t(
                                                warehouse.is_active
                                                    ? 'inventory.warehouses.active'
                                                    : 'inventory.warehouses.inactive',
                                            )}
                                        />
                                    </div>

                                    {warehouse.address && (
                                        <p className="text-muted-foreground text-sm">
                                            {warehouse.address}
                                        </p>
                                    )}

                                    <p className="text-muted-foreground text-xs">
                                        {t(
                                            'inventory.warehouses.priority_label',
                                            { priority: warehouse.priority },
                                        )}{' '}
                                        ·{' '}
                                        {t('inventory.warehouses.items_count', {
                                            count: warehouse.stock_items_count,
                                        })}
                                    </p>
                                </div>

                                {can.edit && (
                                    <div className="flex flex-wrap items-center gap-2">
                                        {!warehouse.is_default &&
                                            warehouse.is_active && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        makeDefault(warehouse)
                                                    }
                                                >
                                                    <Star
                                                        className="size-4"
                                                        aria-hidden="true"
                                                    />
                                                    {t(
                                                        'inventory.warehouses.make_default',
                                                    )}
                                                </Button>
                                            )}
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            aria-label={t(
                                                'inventory.warehouses.edit',
                                                { name: warehouse.name },
                                            )}
                                            onClick={() =>
                                                setEditing(warehouse)
                                            }
                                        >
                                            <Pencil
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                        </Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </PageContainer>

            {can.edit && (
                <WarehouseDialog
                    open={creating || editing !== null}
                    warehouse={editing}
                    onClose={() => {
                        setCreating(false);
                        setEditing(null);
                    }}
                />
            )}
        </>
    );
}

AdminWarehouses.layout = {
    breadcrumbs: [
        {
            title: 'nav.warehouses',
            href: index(),
        },
    ],
};
