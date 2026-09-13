import { Form } from '@inertiajs/react';
import BrandController from '@/actions/App/Http/Controllers/Admin/BrandController';
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
import type { BrandRow, CatalogImageLimits } from './brands';

type Props = {
    open: boolean;
    onClose: () => void;
    brand: BrandRow | null;
    limits: CatalogImageLimits;
};

/**
 * Writing one brand (§11.3).
 *
 * The accepted formats and size come from the server rather than being typed
 * here, so the help text cannot promise something the upload check refuses.
 * The `accept` attribute only narrows the file picker; the server reads the
 * file's own bytes regardless.
 */
export default function BrandDialog({ open, onClose, brand, limits }: Props) {
    const { t } = useTranslation();

    const editing = brand !== null;

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        {t(
                            editing
                                ? 'catalog.brands.edit_title'
                                : 'catalog.brands.create_title',
                        )}
                    </DialogTitle>
                    <DialogDescription>
                        {t('catalog.brands.dialog_description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...(editing
                        ? BrandController.update.form(brand.id)
                        : BrandController.store.form())}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="space-y-5"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2 sm:col-span-2">
                                    <Label htmlFor="brand-name">
                                        {t('catalog.brands.name')}
                                    </Label>
                                    <Input
                                        id="brand-name"
                                        name="name"
                                        required
                                        maxLength={120}
                                        defaultValue={brand?.name ?? ''}
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2 sm:col-span-2">
                                    <Label htmlFor="brand-slug">
                                        {t('catalog.brands.slug')}
                                    </Label>
                                    <Input
                                        id="brand-slug"
                                        name="slug"
                                        maxLength={140}
                                        placeholder={t(
                                            'catalog.brands.slug_placeholder',
                                        )}
                                        defaultValue={brand?.slug ?? ''}
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        {t('catalog.brands.slug_help')}
                                    </p>
                                    <InputError message={errors.slug} />
                                </div>

                                <div className="grid gap-2 sm:col-span-2">
                                    <Label htmlFor="brand-description">
                                        {t('catalog.brands.field_description')}
                                    </Label>
                                    <textarea
                                        id="brand-description"
                                        name="description"
                                        rows={3}
                                        maxLength={5000}
                                        defaultValue={brand?.description ?? ''}
                                        className="border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                    />
                                    <InputError message={errors.description} />
                                </div>
                            </div>

                            <fieldset className="space-y-4 rounded-lg border p-4">
                                <legend className="px-1 text-sm font-medium">
                                    {t('catalog.brands.logo')}
                                </legend>

                                {brand?.logo_url && (
                                    <div className="flex flex-wrap items-center gap-3">
                                        <img
                                            src={brand.logo_url}
                                            alt={brand.logo_alt ?? brand.name}
                                            className="bg-muted size-14 rounded-md border object-contain"
                                        />
                                        <label className="flex items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                name="remove_logo"
                                                value="1"
                                                className="size-4"
                                            />
                                            {t('catalog.brands.remove_logo')}
                                        </label>
                                    </div>
                                )}

                                <div className="grid gap-2">
                                    <Label
                                        htmlFor="brand-logo"
                                        className="sr-only"
                                    >
                                        {t('catalog.brands.logo')}
                                    </Label>
                                    <Input
                                        id="brand-logo"
                                        name="logo"
                                        type="file"
                                        accept={limits.image_types.join(',')}
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        {t('catalog.brands.logo_help', {
                                            size: limits.image_max_kb,
                                        })}
                                    </p>
                                    <InputError message={errors.logo} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="brand-logo-alt">
                                        {t('catalog.brands.logo_alt')}
                                    </Label>
                                    <Input
                                        id="brand-logo-alt"
                                        name="logo_alt"
                                        maxLength={255}
                                        defaultValue={brand?.logo_alt ?? ''}
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        {t('catalog.brands.logo_alt_help')}
                                    </p>
                                    <InputError message={errors.logo_alt} />
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
                                            : 'catalog.brands.create',
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
