import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    FolderTree,
    Pencil,
    Plus,
    Power,
    ShoppingBag,
    Trash2,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import CategoryController from '@/actions/App/Http/Controllers/Admin/CategoryController';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import type { StatusTone } from '@/lib/status';
import { index as productsIndex } from '@/routes/admin/catalog/products';
import type { CatalogImageLimits } from './brands';
import CategoryDialog from './category-dialog';

export type CategoryRow = {
    id: string;
    name: string;
    slug: string;
    parent_id: string | null;
    parent_name: string | null;
    depth: number;
    description: string | null;
    image_url: string | null;
    image_alt: string | null;
    meta_title: string | null;
    meta_description: string | null;
    meta_keywords: string | null;
    is_active: boolean;
    is_available: boolean;
    sort_order: number;
    products_count: number;
    /** Its own products and its subcategories' — what switching it off hides. */
    products_in_branch: number;
};

type Props = {
    categories: CategoryRow[];
    can: { create: boolean; edit: boolean; delete: boolean };
    limits: CatalogImageLimits;
};

/**
 * The category tree (§11.3).
 *
 * Disabled categories stay listed rather than disappearing. "Why has that range
 * stopped showing on the storefronts" is answered by seeing it switched off; a
 * list that hides it answers nothing.
 *
 * A subcategory under a disabled parent is shown as unavailable even when its
 * own switch is on, because that is what customers actually see — and a screen
 * that showed it as live would be lying about the storefront.
 */
export default function AdminCategories({ categories, can, limits }: Props) {
    const { t } = useTranslation();

    const [editing, setEditing] = useState<CategoryRow | null>(null);
    const [creating, setCreating] = useState(false);
    const [search, setSearch] = useState('');

    // Moving is offered only on the whole tree: a filtered one hides neighbours.
    const searching = search.trim() !== '';

    /*
     * Rendered as a tree rather than a flat list: a category's position is the
     * whole point of it, and a list sorted by name would hide which range a
     * subcategory belongs to.
     */
    const tree = useMemo(() => {
        const term = search.trim().toLowerCase();

        const matches = (row: CategoryRow) =>
            term === '' ||
            row.name.toLowerCase().includes(term) ||
            row.slug.toLowerCase().includes(term);

        const roots = categories.filter((row) => row.parent_id === null);

        return roots
            .map((root) => ({
                root,
                children: categories.filter(
                    (row) => row.parent_id === root.id && matches(row),
                ),
            }))
            .filter(
                (branch) => matches(branch.root) || branch.children.length > 0,
            );
    }, [categories, search]);

    /*
     * Only categories that can actually hold a subcategory. The deepest level
     * cannot, and offering it would be offering a choice the server refuses.
     */
    const parentCandidates = useMemo(
        () =>
            categories.filter(
                (row) => row.parent_id === null && row.id !== editing?.id,
            ),
        [categories, editing],
    );

    const state = (row: CategoryRow): { tone: StatusTone; label: string } => {
        if (!row.is_active) {
            return {
                tone: 'neutral',
                label: t('catalog.categories.state_off'),
            };
        }

        if (!row.is_available) {
            return {
                tone: 'warning',
                label: t('catalog.categories.state_parent_off'),
            };
        }

        return { tone: 'success', label: t('catalog.categories.state_live') };
    };

    const toggle = (row: CategoryRow) => {
        if (
            row.is_active &&
            row.products_in_branch > 0 &&
            !window.confirm(
                t('catalog.categories.disable_confirm', {
                    name: row.name,
                    count: row.products_in_branch,
                }),
            )
        ) {
            return;
        }

        router.patch(
            CategoryController.toggle.url(row.id),
            { is_active: !row.is_active },
            { preserveScroll: true },
        );
    };

    /*
     * Moving sends the branch's whole order, never a single swap, so the server
     * applies one decision. Siblings are read from the full list rather than
     * the filtered tree, so a search cannot drop a category out of the order.
     */
    const siblingsOf = (category: CategoryRow) =>
        categories.filter((item) => item.parent_id === category.parent_id);

    const move = (category: CategoryRow, direction: -1 | 1) => {
        const order = siblingsOf(category).map((item) => item.id);
        const from = order.indexOf(category.id);
        const to = from + direction;

        if (from < 0 || to < 0 || to >= order.length) {
            return;
        }

        [order[from], order[to]] = [order[to], order[from]];

        router.post(
            CategoryController.reorder.url(),
            { parent_id: category.parent_id, order },
            { preserveScroll: true },
        );
    };

    const remove = (row: CategoryRow) => {
        if (!window.confirm(t('catalog.categories.delete_confirm'))) {
            return;
        }

        router.delete(CategoryController.destroy.url(row.id), {
            preserveScroll: true,
        });
    };

    const row = (category: CategoryRow, isChild: boolean) => (
        <li
            key={category.id}
            className={
                isChild
                    ? 'border-border flex flex-wrap items-center justify-between gap-3 border-t py-2.5 pr-4 pl-10'
                    : 'flex flex-wrap items-center justify-between gap-3 px-4 py-3'
            }
        >
            <div className="min-w-0 space-y-0.5">
                <p
                    className={
                        isChild ? 'text-sm' : 'text-sm font-medium sm:text-base'
                    }
                >
                    {category.name}
                </p>
                <p className="text-muted-foreground font-mono text-xs">
                    /{category.slug}
                    {category.products_count > 0 && (
                        <span className="font-sans">
                            {' · '}
                            {t('catalog.categories.products_count', {
                                count: category.products_count,
                            })}
                        </span>
                    )}
                </p>
            </div>

            <div className="flex flex-wrap items-center gap-2">
                {/* Label carries the state, never colour alone (§33.9). */}
                <StatusPill
                    tone={state(category).tone}
                    label={state(category).label}
                />

                {/* Its products, including its subcategories', on the Products screen. */}
                <Button variant="ghost" size="sm" asChild>
                    <Link
                        href={productsIndex.url({
                            query: { category: category.id },
                        })}
                    >
                        <ShoppingBag className="size-4" aria-hidden="true" />
                        <span className="sr-only sm:not-sr-only">
                            {t('catalog.categories.view_products')}
                        </span>
                        <span className="text-muted-foreground tabular-nums">
                            {category.products_in_branch}
                        </span>
                    </Link>
                </Button>

                {can.edit && !searching && (
                    <>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            disabled={
                                siblingsOf(category)[0]?.id === category.id
                            }
                            onClick={() => move(category, -1)}
                            aria-label={t('catalog.categories.move_up', {
                                name: category.name,
                            })}
                        >
                            <ArrowUp className="size-4" aria-hidden="true" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            disabled={
                                siblingsOf(category).at(-1)?.id === category.id
                            }
                            onClick={() => move(category, 1)}
                            aria-label={t('catalog.categories.move_down', {
                                name: category.name,
                            })}
                        >
                            <ArrowDown className="size-4" aria-hidden="true" />
                        </Button>
                    </>
                )}

                {can.edit && (
                    <>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setEditing(category)}
                        >
                            <Pencil className="size-4" aria-hidden="true" />
                            <span className="sr-only sm:not-sr-only">
                                {t('common.actions.edit')}
                            </span>
                        </Button>

                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => toggle(category)}
                        >
                            <Power className="size-4" aria-hidden="true" />
                            <span className="sr-only sm:not-sr-only">
                                {t(
                                    category.is_active
                                        ? 'catalog.categories.disable'
                                        : 'catalog.categories.enable',
                                )}
                            </span>
                        </Button>
                    </>
                )}

                {can.delete && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => remove(category)}
                    >
                        <Trash2 className="size-4" aria-hidden="true" />
                        <span className="sr-only">
                            {t('common.actions.delete')}
                        </span>
                    </Button>
                )}
            </div>
        </li>
    );

    return (
        <>
            <Head title={t('catalog.categories.title')} />

            <PageContainer>
                <PageHeader
                    title={t('catalog.categories.title')}
                    description={t('catalog.categories.description')}
                    actions={
                        can.create ? (
                            <Button onClick={() => setCreating(true)}>
                                <Plus className="size-4" aria-hidden="true" />
                                {t('catalog.categories.create')}
                            </Button>
                        ) : undefined
                    }
                />

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder={t('catalog.categories.search')}
                        className="max-w-sm"
                        aria-label={t('catalog.categories.search')}
                    />
                    {can.edit && (
                        <p className="text-muted-foreground text-xs">
                            {t('catalog.categories.assign_help')}
                        </p>
                    )}
                </div>

                {tree.length === 0 ? (
                    <EmptyState
                        icon={FolderTree}
                        title={t(
                            categories.length === 0
                                ? 'catalog.categories.empty'
                                : 'catalog.categories.no_matches',
                        )}
                        description={t(
                            categories.length === 0
                                ? can.create
                                    ? 'catalog.categories.empty_help'
                                    : 'catalog.categories.empty_help_read_only'
                                : 'catalog.categories.no_matches_help',
                        )}
                    />
                ) : (
                    <ul className="bg-card divide-border divide-y overflow-hidden rounded-xl border">
                        {tree.map((branch) => (
                            <li key={branch.root.id}>
                                <ul>
                                    {row(branch.root, false)}
                                    {branch.children.map((child) =>
                                        row(child, true),
                                    )}
                                </ul>
                            </li>
                        ))}
                    </ul>
                )}
            </PageContainer>

            <CategoryDialog
                open={creating || editing !== null}
                onClose={() => {
                    setCreating(false);
                    setEditing(null);
                }}
                category={editing}
                parents={parentCandidates}
                limits={limits}
            />
        </>
    );
}
