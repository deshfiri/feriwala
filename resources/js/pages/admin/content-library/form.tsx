import { Head, Link, router } from '@inertiajs/react';
import { Eye, PenLine, Plus, Send, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import ContentLibraryController from '@/actions/App/Http/Controllers/Admin/ContentLibraryController';
import BlockEditor from '@/components/content-library/block-editor';
import BlockView from '@/components/content-library/block-view';
import FormField from '@/components/forms/form-field';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import ProductPickerSheet from '@/components/content-library/product-picker-sheet';
import ProductSummary from '@/components/product-links/product-summary';
import SectionCard from '@/components/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/admin/content-library';
import type { ProductLinkSummary } from '@/types';
import type {
    EditorBlock,
    LibraryLimits,
    PresentedBlock,
} from '@/types/content-library';

type StoredBlock = {
    type: 'text' | 'image' | 'video' | 'link';
    text?: string;
    file_id?: string;
    url?: string;
    mime_type?: string;
    alt?: string | null;
    caption?: string | null;
    label?: string;
    description?: string | null;
};

type Props = {
    item: {
        id: string;
        title: string;
        blocks: StoredBlock[];
        products: ProductLinkSummary[];
    } | null;
    limits: LibraryLimits;
    can: { publish: boolean; edit: boolean; delete: boolean };
};

const newKey = () => Math.random().toString(36).slice(2, 10);

function toEditor(block: StoredBlock): EditorBlock {
    const key = newKey();

    switch (block.type) {
        case 'image':
            return {
                key,
                type: 'image',
                file_id: block.file_id ?? '',
                url: block.url ?? '',
                alt: block.alt ?? '',
                caption: block.caption ?? '',
            };
        case 'video':
            return {
                key,
                type: 'video',
                file_id: block.file_id ?? '',
                url: block.url ?? '',
                source: block.file_id ? 'upload' : 'link',
                caption: block.caption ?? '',
            };
        case 'link':
            return {
                key,
                type: 'link',
                url: block.url ?? '',
                label: block.label ?? '',
                description: block.description ?? '',
            };
        default:
            return { key, type: 'text', text: block.text ?? '' };
    }
}

/** What the server stores: only the keys each block type owns. */
function toPayload(block: EditorBlock) {
    switch (block.type) {
        case 'text':
            return { type: 'text', text: block.text };
        case 'image':
            return {
                type: 'image',
                file_id: block.file_id,
                alt: block.alt,
                caption: block.caption,
            };
        case 'video':
            return block.source === 'upload'
                ? {
                      type: 'video',
                      file_id: block.file_id,
                      caption: block.caption,
                  }
                : { type: 'video', url: block.url, caption: block.caption };
        case 'link':
            return {
                type: 'link',
                url: block.url,
                label: block.label,
                description: block.description,
            };
    }
}

/** The editor's blocks as a reader would see them, for the preview tab. */
function toPreview(blocks: EditorBlock[]): PresentedBlock[] {
    const shown: PresentedBlock[] = [];

    for (const block of blocks) {
        if (block.type === 'text' && block.text.trim() !== '') {
            shown.push({ type: 'text', text: block.text });
        } else if (block.type === 'image' && block.url !== '') {
            shown.push({
                type: 'image',
                url: block.url,
                alt: block.alt || null,
                caption: block.caption || null,
            });
        } else if (block.type === 'video' && block.url !== '') {
            shown.push({
                type: 'video',
                url: block.url,
                embed_url: null,
                mime_type: block.source === 'upload' ? 'video/mp4' : null,
                caption: block.caption || null,
            });
        } else if (block.type === 'link' && block.url !== '') {
            shown.push({
                type: 'link',
                url: block.url,
                label: block.label || block.url,
                description: block.description || null,
            });
        }
    }

    return shown;
}

/**
 * Release content: write it in the editor, choose the Products it is for,
 * publish. Editing an existing item changes what partners see at once.
 */
export default function ContentLibraryForm({ item, limits, can }: Props) {
    const { t } = useTranslation();
    const editing = item !== null;

    const [title, setTitle] = useState(item?.title ?? '');
    const [blocks, setBlocks] = useState<EditorBlock[]>(
        () => item?.blocks.map(toEditor) ?? [],
    );
    const [products, setProducts] = useState<ProductLinkSummary[]>(
        item?.products ?? [],
    );
    const [searching, setSearching] = useState(false);
    const [tab, setTab] = useState<'write' | 'preview'>('write');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const preview = useMemo(() => toPreview(blocks), [blocks]);

    const blockError =
        errors.blocks ??
        Object.entries(errors).find(([key]) => key.startsWith('blocks.'))?.[1];

    const submit = () => {
        const data = {
            title,
            product_ids: products.map((product) => product.id),
            blocks: blocks.map(toPayload),
        };

        const options = {
            preserveScroll: true,
            onStart: () => {
                setProcessing(true);
                setErrors({});
            },
            onError: (bag: Record<string, string>) => setErrors(bag),
            onFinish: () => setProcessing(false),
        };

        if (editing) {
            router.patch(
                ContentLibraryController.update.url(item.id),
                data,
                options,
            );
        } else {
            router.post(ContentLibraryController.store.url(), data, options);
        }
    };

    const canSave = editing ? can.edit : can.publish;
    const ready =
        title.trim() !== '' && blocks.length > 0 && products.length > 0;

    return (
        <>
            <Head
                title={
                    editing
                        ? t('content_library.edit_title')
                        : t('content_library.release_title')
                }
            />

            <PageContainer width="narrow">
                <PageHeader
                    title={
                        editing
                            ? t('content_library.edit_title')
                            : t('content_library.release_title')
                    }
                    description={t('content_library.form_description')}
                    actions={
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={index()}>
                                {t('content_library.back')}
                            </Link>
                        </Button>
                    }
                />

                <SectionCard title={t('content_library.sections.content')}>
                    <div className="space-y-4">
                        <FormField
                            label={t('content_library.fields.title')}
                            error={errors.title}
                            required
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    value={title}
                                    maxLength={255}
                                    onChange={(event) =>
                                        setTitle(event.target.value)
                                    }
                                />
                            )}
                        </FormField>

                        <div
                            role="tablist"
                            className="bg-muted inline-flex rounded-lg p-1"
                        >
                            {(
                                [
                                    ['write', PenLine],
                                    ['preview', Eye],
                                ] as const
                            ).map(([key, Icon]) => (
                                <button
                                    key={key}
                                    type="button"
                                    role="tab"
                                    aria-selected={tab === key}
                                    onClick={() => setTab(key)}
                                    className={`inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm ${
                                        tab === key
                                            ? 'bg-background font-medium shadow-sm'
                                            : 'text-muted-foreground'
                                    }`}
                                >
                                    <Icon
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t(`content_library.tabs.${key}`)}
                                </button>
                            ))}
                        </div>

                        {tab === 'write' ? (
                            <BlockEditor
                                blocks={blocks}
                                onChange={setBlocks}
                                limits={limits}
                                error={blockError}
                            />
                        ) : (
                            <div className="space-y-3 rounded-lg border p-4">
                                <p className="font-medium">
                                    {title || t('content_library.untitled')}
                                </p>
                                {preview.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">
                                        {t('content_library.preview_empty')}
                                    </p>
                                ) : (
                                    <BlockView blocks={preview} />
                                )}
                            </div>
                        )}
                    </div>
                </SectionCard>

                <SectionCard
                    title={t('content_library.sections.products')}
                    description={t('content_library.products_help')}
                    actions={
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => setSearching(true)}
                        >
                            <Plus className="size-4" aria-hidden="true" />
                            {t('content_library.select_products')}
                        </Button>
                    }
                >
                    {errors.product_ids && (
                        <p role="alert" className="text-danger mb-2 text-sm">
                            {errors.product_ids}
                        </p>
                    )}

                    {products.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            {t('content_library.no_products')}
                        </p>
                    ) : (
                        <ul className="divide-border divide-y rounded-md border">
                            {products.map((product) => (
                                <li key={product.id} className="p-3">
                                    <ProductSummary
                                        product={product}
                                        action={
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="size-7"
                                                onClick={() =>
                                                    setProducts(
                                                        products.filter(
                                                            (candidate) =>
                                                                candidate.id !==
                                                                product.id,
                                                        ),
                                                    )
                                                }
                                                aria-label={t(
                                                    'content_library.remove_product',
                                                    { name: product.name },
                                                )}
                                            >
                                                <X className="size-4" />
                                            </Button>
                                        }
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>

                <div className="flex flex-wrap items-center justify-end gap-3">
                    <p className="text-muted-foreground text-xs">
                        {t('content_library.publish_note', {
                            count: products.length,
                        })}
                    </p>
                    <Button
                        type="button"
                        disabled={!canSave || !ready || processing}
                        onClick={submit}
                    >
                        <Send className="size-4" aria-hidden="true" />
                        {editing
                            ? t('content_library.save')
                            : t('content_library.publish')}
                    </Button>
                </div>
            </PageContainer>

            <ProductPickerSheet
                open={searching}
                onOpenChange={setSearching}
                selected={products}
                onChange={setProducts}
                searchUrl={ContentLibraryController.products.url()}
            />
        </>
    );
}

ContentLibraryForm.layout = {
    breadcrumbs: [
        { title: 'Content library', href: index() },
        { title: 'Release content' },
    ],
};
