import { Head, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import MenuItemController from '@/actions/App/Http/Controllers/Admin/Cms/MenuItemController';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { CmsAdminMenu, CmsAdminMenuItem } from '@/types';
import MenuItemDialog from './menu-item-dialog';

type Props = {
    menus: CmsAdminMenu[];
    can: { edit: boolean };
};

/**
 * The three public-site menus — header, footer, legal (§4, §34, Stage 7).
 * There is exactly one of each; adding an item to a menu that has no row
 * yet creates it on first use (see MenuItemController::menuFor()).
 */
export default function CmsMenusIndex({ menus, can }: Props) {
    const { t } = useTranslation();
    const [dialog, setDialog] = useState<{
        location: string;
        item: CmsAdminMenuItem | null;
    } | null>(null);

    const move = (
        menu: CmsAdminMenu,
        itemRow: CmsAdminMenuItem,
        direction: -1 | 1,
    ) => {
        const siblings = menu.items.filter(
            (row) => row.parent_id === itemRow.parent_id,
        );
        const order = siblings.map((row) => row.id);
        const from = order.indexOf(itemRow.id);
        const to = from + direction;

        if (from < 0 || to < 0 || to >= order.length) {
            return;
        }

        [order[from], order[to]] = [order[to], order[from]];

        router.post(
            MenuItemController.reorder.url(menu.location),
            { parent_id: itemRow.parent_id, order },
            { preserveScroll: true },
        );
    };

    const remove = (menu: CmsAdminMenu, itemRow: CmsAdminMenuItem) => {
        if (!window.confirm(t('cms.menus_index.remove_confirm'))) {
            return;
        }

        router.delete(
            MenuItemController.destroy.url([menu.location, itemRow.id]),
            {
                preserveScroll: true,
            },
        );
    };

    return (
        <>
            <Head title={t('cms.menus_index.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('cms.menus_index.title')}
                    description={t('cms.menus_index.description')}
                />

                {menus.map((menu) => (
                    <SectionCard
                        key={menu.location}
                        title={t(`cms.menus_index.location_${menu.location}`)}
                        actions={
                            can.edit ? (
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        setDialog({
                                            location: menu.location,
                                            item: null,
                                        })
                                    }
                                >
                                    <Plus className="size-4" />
                                    {t('cms.menus_index.add_item')}
                                </Button>
                            ) : undefined
                        }
                    >
                        {menu.items.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                {t('cms.menus_index.empty')}
                            </p>
                        ) : (
                            <ul className="divide-border divide-y">
                                {menu.items.map((itemRow, index) => (
                                    <li
                                        key={itemRow.id}
                                        className="flex flex-wrap items-center justify-between gap-3 py-3"
                                    >
                                        <div className="min-w-0 space-y-0.5">
                                            <p className="text-sm font-medium">
                                                {itemRow.label_en}
                                                {!itemRow.is_enabled && (
                                                    <span className="text-muted-foreground ms-2 text-xs">
                                                        (
                                                        {t(
                                                            'cms.menus_index.disabled',
                                                        )}
                                                        )
                                                    </span>
                                                )}
                                            </p>
                                            <p className="text-muted-foreground font-mono text-xs">
                                                {itemRow.href ??
                                                    itemRow.route_name ??
                                                    itemRow.external_url}
                                            </p>
                                        </div>

                                        {can.edit && (
                                            <div className="flex items-center gap-1">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    disabled={index === 0}
                                                    onClick={() =>
                                                        move(menu, itemRow, -1)
                                                    }
                                                >
                                                    <ArrowUp className="size-4" />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    disabled={
                                                        index ===
                                                        menu.items.length - 1
                                                    }
                                                    onClick={() =>
                                                        move(menu, itemRow, 1)
                                                    }
                                                >
                                                    <ArrowDown className="size-4" />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() =>
                                                        setDialog({
                                                            location:
                                                                menu.location,
                                                            item: itemRow,
                                                        })
                                                    }
                                                >
                                                    <Pencil className="size-4" />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() =>
                                                        remove(menu, itemRow)
                                                    }
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </SectionCard>
                ))}
            </PageContainer>

            {dialog !== null && (
                <MenuItemDialog
                    open
                    onOpenChange={(open) => !open && setDialog(null)}
                    location={dialog.location}
                    item={dialog.item}
                />
            )}
        </>
    );
}
