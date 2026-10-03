import { Link } from '@inertiajs/react';
import { ArrowLeft, ImageOff, MoreVertical, Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/admin/catalog/products';
import type { ProductDetail } from '@/types';

type Props = {
    product: ProductDetail | null;
    category: string | null;
    imageUrl: string | null;
    saveLabel: string;
    processing: boolean;
    canSave: boolean;
    canDeleteDraft: boolean;
    onDelete: () => void;
    deleteError?: ReactNode;
};

/**
 * The compact identity bar atop the product workspace: enough to recognise
 * which product this is and act on it, with everything else behind the tabs
 * below (new UI pass — visual only, no change to what saves, who may act, or
 * what each action does).
 */
export default function ProductHeader({
    product,
    category,
    imageUrl,
    saveLabel,
    processing,
    canSave,
    canDeleteDraft,
    onDelete,
    deleteError,
}: Props) {
    const { t } = useTranslation();
    const editing = product !== null;

    return (
        <div className="space-y-3">
            <Link
                href={index()}
                className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 text-sm"
            >
                <ArrowLeft className="size-4" aria-hidden="true" />
                {t('catalog.products.back')}
            </Link>

            <div className="bg-card flex flex-wrap items-center gap-4 rounded-xl border p-4">
                <div className="bg-muted flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-lg border">
                    {imageUrl ? (
                        <img
                            src={imageUrl}
                            alt=""
                            className="size-full object-cover"
                        />
                    ) : (
                        <ImageOff
                            className="text-muted-foreground size-5"
                            aria-hidden="true"
                        />
                    )}
                </div>

                <div className="min-w-0 flex-1 space-y-1">
                    <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                        <h1 className="truncate text-lg font-semibold tracking-tight">
                            {editing
                                ? product.name
                                : t('catalog.products.create_title')}
                        </h1>
                        {editing && (
                            <StatusPill
                                tone={product.status_tone}
                                label={t(
                                    `catalog.products.status.${product.status}`,
                                )}
                            />
                        )}
                    </div>
                    <p className="text-muted-foreground flex flex-wrap items-center gap-x-2 gap-y-0.5 font-mono text-xs">
                        {editing && <span>{product.sku}</span>}
                        {editing && category && (
                            <span aria-hidden="true">·</span>
                        )}
                        {category && (
                            <span className="font-sans">{category}</span>
                        )}
                    </p>
                </div>

                <div className="flex shrink-0 items-center gap-2">
                    {canSave && (
                        <Button type="submit" disabled={processing}>
                            {saveLabel}
                        </Button>
                    )}

                    {canDeleteDraft && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    aria-label={t(
                                        'catalog.products.more_actions',
                                    )}
                                >
                                    <MoreVertical
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    variant="destructive"
                                    onSelect={onDelete}
                                >
                                    <Trash2
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t('catalog.products.delete')}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </div>
            </div>

            {deleteError}
        </div>
    );
}
