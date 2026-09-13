import { Form } from '@inertiajs/react';
import CategoryController from '@/actions/App/Http/Controllers/Admin/CategoryController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import type { CatalogImageLimits } from './brands';
import type { CategoryRow } from './categories';

type Props = {
    open: boolean;
    onClose: () => void;
    category: CategoryRow | null;
    /** Candidates for the parent field — a category cannot parent itself. */
    parents: CategoryRow[];
    limits: CatalogImageLimits;
};

/**
 * Writing one category (§11.3).
 *
 * The parent selector offers only categories that can actually hold one: not
 * this category, not its own subcategories, and nothing already at the deepest
 * level. Offering a choice the server will refuse is how somebody loses a form
 * they had finished filling in.
 */
export default function CategoryDialog({
    open,
    onClose,
    category,
    parents,
    limits,
}: Props) {
    const { t } = useTranslation();

    const editing = category !== null;

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {t(
                            editing
                                ? 'catalog.categories.edit_title'
                                : 'catalog.categories.create_title',
                        )}
                    </DialogTitle>
                    <DialogDescription>
                        {t('catalog.categories.dialog_description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...(editing
                        ? CategoryController.update.form(category.id)
                        : CategoryController.store.form())}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="space-y-5"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2 sm:col-span-2">
                                    <Label htmlFor="category-name">
                                        {t('catalog.categories.name')}
                                    </Label>
                                    <Input
                                        id="category-name"
                                        name="name"
                                        required
                                        maxLength={120}
                                        defaultValue={category?.name ?? ''}
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="category-slug">
                                        {t('catalog.categories.slug')}
                                    </Label>
                                    <Input
                                        id="category-slug"
                                        name="slug"
                                        maxLength={140}
                                        placeholder={t(
                                            'catalog.categories.slug_placeholder',
                                        )}
                                        defaultValue={category?.slug ?? ''}
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        {t('catalog.categories.slug_help')}
                                    </p>
                                    <InputError message={errors.slug} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="category-parent">
                                        {t('catalog.categories.parent')}
                                    </Label>
                                    <select
                                        id="category-parent"
                                        name="parent_id"
                                        defaultValue={category?.parent_id ?? ''}
                                        className="border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                    >
                                        <option value="">
                                            {t('catalog.categories.no_parent')}
                                        </option>
                                        {parents.map((parent) => (
                                            <option
                                                key={parent.id}
                                                value={parent.id}
                                            >
                                                {parent.name}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.parent_id} />
                                </div>

                                <div className="grid gap-2 sm:col-span-2">
                                    <Label htmlFor="category-description">
                                        {t(
                                            'catalog.categories.field_description',
                                        )}
                                    </Label>
                                    <textarea
                                        id="category-description"
                                        name="description"
                                        rows={3}
                                        maxLength={5000}
                                        defaultValue={
                                            category?.description ?? ''
                                        }
                                        className="border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                    />
                                    <InputError message={errors.description} />
                                </div>

                                {/*
                                    The tile partner storefronts render. The
                                    formats and size come from the server so the
                                    help text cannot promise what the upload
                                    check refuses.
                                */}
                                <div className="grid gap-2 sm:col-span-2">
                                    <Label htmlFor="category-image">
                                        {t('catalog.categories.image')}
                                    </Label>
                                    {category?.image_url && (
                                        <div className="flex flex-wrap items-center gap-3">
                                            <img
                                                src={category.image_url}
                                                alt={
                                                    category.image_alt ??
                                                    category.name
                                                }
                                                className="bg-muted size-14 rounded-md border object-cover"
                                            />
                                            <label className="flex items-center gap-2 text-sm">
                                                <input
                                                    type="checkbox"
                                                    name="remove_image"
                                                    value="1"
                                                    className="size-4"
                                                />
                                                {t(
                                                    'catalog.categories.remove_image',
                                                )}
                                            </label>
                                        </div>
                                    )}
                                    <Input
                                        id="category-image"
                                        name="image"
                                        type="file"
                                        accept={limits.image_types.join(',')}
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        {t('catalog.categories.image_help', {
                                            size: limits.image_max_kb,
                                        })}
                                    </p>
                                    <InputError message={errors.image} />
                                </div>
                            </div>

                            {/*
                                What a partner storefront puts in the page head
                                (§11.3, §34.3). Separate from the description,
                                because a description is for a shopper and these
                                are for a search engine.
                            */}
                            <fieldset className="space-y-4 rounded-lg border p-4">
                                <legend className="px-1 text-sm font-medium">
                                    {t('catalog.categories.seo')}
                                </legend>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="category-meta-title">
                                            {t('catalog.categories.meta_title')}
                                        </Label>
                                        <Input
                                            id="category-meta-title"
                                            name="meta_title"
                                            maxLength={70}
                                            defaultValue={
                                                category?.meta_title ?? ''
                                            }
                                        />
                                        <InputError
                                            message={errors.meta_title}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="category-meta-keywords">
                                            {t(
                                                'catalog.categories.meta_keywords',
                                            )}
                                        </Label>
                                        <Input
                                            id="category-meta-keywords"
                                            name="meta_keywords"
                                            maxLength={255}
                                            defaultValue={
                                                category?.meta_keywords ?? ''
                                            }
                                        />
                                        <InputError
                                            message={errors.meta_keywords}
                                        />
                                    </div>

                                    <div className="grid gap-2 sm:col-span-2">
                                        <Label htmlFor="category-meta-description">
                                            {t(
                                                'catalog.categories.meta_description',
                                            )}
                                        </Label>
                                        <textarea
                                            id="category-meta-description"
                                            name="meta_description"
                                            rows={2}
                                            maxLength={200}
                                            defaultValue={
                                                category?.meta_description ?? ''
                                            }
                                            className="border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                        />
                                        <InputError
                                            message={errors.meta_description}
                                        />
                                    </div>

                                    <div className="grid gap-2 sm:col-span-2">
                                        <Label htmlFor="category-image-alt">
                                            {t('catalog.categories.image_alt')}
                                        </Label>
                                        <Input
                                            id="category-image-alt"
                                            name="image_alt"
                                            maxLength={255}
                                            defaultValue={
                                                category?.image_alt ?? ''
                                            }
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            {t(
                                                'catalog.categories.image_alt_help',
                                            )}
                                        </p>
                                        <InputError
                                            message={errors.image_alt}
                                        />
                                    </div>
                                </div>
                            </fieldset>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={onClose}
                                >
                                    {t('common.actions.cancel')}
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {t(
                                        editing
                                            ? 'common.actions.save'
                                            : 'catalog.categories.create',
                                    )}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
