import { Form, router } from '@inertiajs/react';
import { FileText, Megaphone, Paperclip, Trash2 } from 'lucide-react';
import ProductContentController from '@/actions/App/Http/Controllers/Admin/ProductContentController';
import FormField from '@/components/forms/form-field';
import SectionCard from '@/components/section-card';
import EmptyState from '@/components/states/empty-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import type { ContentLimits, ContentRow, ProductDetail } from '@/types';

type Props = {
    product: ProductDetail;
    content: ContentRow[];
    limits: ContentLimits;
    canEdit: boolean;
};

const textareaClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Updates an administrator publishes against a product, time to time: a
 * title, optional details, and at most one attachment. Every operating
 * partner reads these from the product's own page — the same way they
 * already read its images, with no further gate of its own.
 *
 * Published immediately. There is no draft or schedule here, only a running
 * feed, so the form is a single post action rather than a status machine.
 */
export default function ContentSection({
    product,
    content,
    limits,
    canEdit,
}: Props) {
    const { t } = useTranslation();

    const remove = (item: ContentRow) => {
        if (!window.confirm(t('catalog.content.delete_confirm'))) {
            return;
        }

        router.delete(
            ProductContentController.destroy.url({
                product: product.id,
                content: item.id,
            }),
            { preserveScroll: true },
        );
    };

    return (
        <SectionCard
            title={t('catalog.content.title')}
            description={t('catalog.content.description')}
        >
            <div className="space-y-5">
                {content.length === 0 ? (
                    <EmptyState
                        icon={Megaphone}
                        title={t('catalog.content.empty')}
                        description={t(
                            canEdit
                                ? 'catalog.content.empty_help'
                                : 'catalog.content.empty_help_read_only',
                        )}
                    />
                ) : (
                    <ol className="space-y-4">
                        {content.map((item) => (
                            <li
                                key={item.id}
                                className="bg-card space-y-2 rounded-lg border p-4"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <p className="font-medium">
                                            {item.title}
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            {new Date(
                                                item.published_at,
                                            ).toLocaleDateString()}
                                            {item.creator &&
                                                ' · ' +
                                                    t(
                                                        'catalog.content.posted_by',
                                                        {
                                                            name: item.creator,
                                                        },
                                                    )}
                                        </p>
                                    </div>

                                    {canEdit && (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="size-8"
                                            onClick={() => remove(item)}
                                        >
                                            <Trash2
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            <span className="sr-only">
                                                {t('common.actions.delete')}
                                            </span>
                                        </Button>
                                    )}
                                </div>

                                {item.body && (
                                    <p className="text-sm whitespace-pre-line">
                                        {item.body}
                                    </p>
                                )}

                                {item.attachment && (
                                    <div>
                                        {item.attachment.is_image &&
                                        item.attachment.url ? (
                                            <img
                                                src={item.attachment.url}
                                                alt=""
                                                className="max-h-48 rounded-md border object-cover"
                                            />
                                        ) : (
                                            item.attachment.url && (
                                                <a
                                                    href={item.attachment.url}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="text-primary inline-flex items-center gap-1.5 text-sm underline"
                                                >
                                                    <Paperclip
                                                        className="size-4"
                                                        aria-hidden="true"
                                                    />
                                                    {t(
                                                        'catalog.content.post_file',
                                                    )}
                                                </a>
                                            )
                                        )}
                                    </div>
                                )}
                            </li>
                        ))}
                    </ol>
                )}

                {canEdit && (
                    <Form
                        {...ProductContentController.store.form(product.id)}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="grid gap-3 rounded-lg border border-dashed p-4"
                    >
                        {({ errors, processing, progress }) => (
                            <>
                                <FormField
                                    label={t('catalog.content.post_title')}
                                    error={errors.title}
                                    required
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            name="title"
                                            maxLength={255}
                                        />
                                    )}
                                </FormField>

                                <FormField
                                    label={t('catalog.content.post_body')}
                                    error={errors.body}
                                >
                                    {(field) => (
                                        <textarea
                                            {...field}
                                            name="body"
                                            rows={3}
                                            maxLength={5000}
                                            className={textareaClass}
                                        />
                                    )}
                                </FormField>

                                <div className="grid gap-3 sm:grid-cols-2">
                                    <FormField
                                        label={t('catalog.content.post_image')}
                                        error={errors.image}
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="image"
                                                type="file"
                                                accept={limits.image_types.join(
                                                    ',',
                                                )}
                                            />
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t('catalog.content.post_file')}
                                        error={errors.file}
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="file"
                                                type="file"
                                                accept={limits.file_types.join(
                                                    ',',
                                                )}
                                            />
                                        )}
                                    </FormField>
                                </div>

                                <p className="text-muted-foreground text-xs">
                                    {t('catalog.content.attachment_help', {
                                        image: limits.image_max_mb,
                                        file: limits.file_max_mb,
                                    })}
                                </p>

                                <div className="flex items-center gap-3">
                                    <Button type="submit" disabled={processing}>
                                        <FileText
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        {t('catalog.content.publish')}
                                    </Button>
                                    {progress && (
                                        <progress
                                            value={progress.percentage}
                                            max={100}
                                            className="h-2 flex-1"
                                        />
                                    )}
                                </div>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </SectionCard>
    );
}
