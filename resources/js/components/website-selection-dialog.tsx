import { useForm } from '@inertiajs/react';
import { ImageOff } from 'lucide-react';
import { useRef, useState } from 'react';
import InputError from '@/components/input-error';
import MoneyField from '@/components/forms/money-field';
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
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import WebsiteProductController from '@/actions/App/Http/Controllers/Erp/WebsiteProductController';
import type { WebsiteSelection } from '@/types/website';

type Category = { id: string; name: string };

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 w-full rounded-lg border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none disabled:opacity-50';

/**
 * An instant as a `datetime-local` value, in the browser's own time.
 */
function toLocalInput(instant: string | null): string {
    if (!instant) {
        return '';
    }

    const date = new Date(instant);
    const pad = (value: number) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/**
 * A `datetime-local` value as an instant with its offset, or null when empty.
 */
function toInstant(value: string): string | null {
    return value !== '' ? new Date(value).toISOString() : null;
}

/**
 * Editing one selected product of a storefront, in a dialog (§15, §15.1).
 *
 * Presentation only: it sends the same fields to the same update route as the
 * inline editor it replaced, and shows whatever the server refuses — the
 * allowed range and every lock are the server's, shown and never enforced here.
 *
 * **Mount it only while editing, keyed by the selection.** The form is seeded
 * from the selection's saved values on mount, so every opening starts from what
 * is saved and nothing of a previously edited product can remain; unmounting
 * on close is the reset. It closes only on a successful save, and with unsaved
 * changes asks first.
 */
export default function WebsiteSelectionDialog({
    websiteId,
    row,
    categories,
    onClose,
}: {
    websiteId: string;
    row: WebsiteSelection;
    categories: Category[];
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const locked = (field: string) => row.terms.locked_fields.includes(field);
    const priceLocked = !row.terms.allows_user_pricing || locked('price');
    const promotionLocked = locked('promotional_price');
    const [confirmingDiscard, setConfirmingDiscard] = useState(false);
    // The control that opened this dialog. There is no `DialogTrigger` to give
    // focus back to, so without this a keyboard user is dropped on the page.
    const [opener] = useState(() =>
        document.activeElement instanceof HTMLElement
            ? document.activeElement
            : null,
    );
    const cancelButton = useRef<HTMLButtonElement>(null);
    // Set synchronously, because `processing` only flips on the next render and
    // a double click lands before it.
    const submitting = useRef(false);

    const form = useForm({
        price: row.price?.amount ?? '',
        promotional_price: row.promotional_price?.amount ?? '',
        promotion_starts_at: toLocalInput(row.promotion_starts_at),
        promotion_ends_at: toLocalInput(row.promotion_ends_at),
        promo_title: row.promo_title ?? '',
        website_category_id: row.category?.id ?? '',
        display_order: String(row.display_order),
        is_featured: row.is_featured,
        marketing_description: row.marketing_description ?? '',
    });

    // A locked field is not sent, as a disabled input never was; the window is
    // typed in the browser's own time and sent as an instant, so the server
    // stores exactly the moment meant (C4).
    form.transform((data) => ({
        ...(priceLocked ? {} : { price: data.price }),
        ...(promotionLocked
            ? {}
            : {
                  promotional_price: data.promotional_price,
                  promotion_starts_at: toInstant(data.promotion_starts_at),
                  promotion_ends_at: toInstant(data.promotion_ends_at),
              }),
        ...(locked('promo_title') ? {} : { promo_title: data.promo_title }),
        ...(locked('website_category')
            ? {}
            : { website_category_id: data.website_category_id }),
        ...(locked('display_order')
            ? {}
            : { display_order: data.display_order }),
        ...(locked('is_featured') ? {} : { is_featured: data.is_featured }),
        ...(locked('marketing_description')
            ? {}
            : { marketing_description: data.marketing_description }),
    }));

    const requestClose = () => {
        if (form.processing || submitting.current) {
            return;
        }

        if (form.isDirty) {
            setConfirmingDiscard(true);

            return;
        }

        onClose();
    };

    const save = (event: { preventDefault: () => void }) => {
        event.preventDefault();

        if (submitting.current) {
            return;
        }

        submitting.current = true;

        // Through the route itself, so the verb is the one the route declares.
        form.submit(
            WebsiteProductController.update({
                website: websiteId,
                selection: row.id,
            }),
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onFinish: () => {
                    submitting.current = false;
                },
            },
        );
    };

    const ids = {
        price: `selection-price-${row.id}`,
        promo: `selection-promo-${row.id}`,
        starts: `selection-starts-${row.id}`,
        ends: `selection-ends-${row.id}`,
        title: `selection-title-${row.id}`,
        category: `selection-category-${row.id}`,
        order: `selection-order-${row.id}`,
        description: `selection-description-${row.id}`,
    };

    return (
        <>
            <Dialog
                open
                onOpenChange={(open) => {
                    if (!open) {
                        requestClose();
                    }
                }}
            >
                <DialogContent
                    data-test="website-selection-dialog"
                    className="flex max-h-[90dvh] w-full max-w-[calc(100%-1rem)] flex-col gap-0 overflow-hidden p-0 sm:max-w-2xl"
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();

                        if (opener?.isConnected) {
                            opener.focus();
                        }
                    }}
                    onEscapeKeyDown={(event) => {
                        if (form.processing) {
                            event.preventDefault();
                        }
                    }}
                    onInteractOutside={(event) => {
                        if (form.processing) {
                            event.preventDefault();
                        }
                    }}
                >
                    <DialogHeader className="border-b px-4 pt-5 pb-4 sm:px-6">
                        <div className="flex min-w-0 items-center gap-3 pr-6">
                            <div className="bg-muted flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-lg">
                                {row.product.image ? (
                                    <img
                                        src={row.product.image}
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
                            <div className="min-w-0 space-y-0.5 text-left">
                                <DialogTitle className="line-clamp-2 leading-snug break-words">
                                    {row.product.name}
                                </DialogTitle>
                                <p
                                    className="text-muted-foreground truncate text-xs"
                                    data-test="selection-dialog-sku"
                                >
                                    {t('partner_products.details.bpc')}{' '}
                                    {row.product.sku}
                                </p>
                            </div>
                        </div>
                        <DialogDescription>
                            {t('website.products.edit_description')}
                        </DialogDescription>
                    </DialogHeader>

                    <form
                        onSubmit={save}
                        className="flex min-h-0 flex-1 flex-col"
                        noValidate
                    >
                        <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-4 sm:px-6">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-1.5">
                                    <Label htmlFor={ids.price}>
                                        {t('website.products.price')}
                                    </Label>
                                    <fieldset
                                        disabled={priceLocked}
                                        className="m-0 min-w-0 border-0 p-0"
                                    >
                                        <MoneyField
                                            id={ids.price}
                                            name="price"
                                            value={form.data.price}
                                            onChange={(value) =>
                                                form.setData('price', value)
                                            }
                                            aria-invalid={
                                                form.errors.price
                                                    ? true
                                                    : undefined
                                            }
                                        />
                                    </fieldset>
                                    {row.terms.minimum && (
                                        <p className="text-muted-foreground text-xs">
                                            {t('website.products.bounds', {
                                                minimum:
                                                    row.terms.minimum.formatted,
                                                maximum:
                                                    row.terms.maximum
                                                        ?.formatted ?? '—',
                                            })}
                                        </p>
                                    )}
                                    <InputError message={form.errors.price} />
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor={ids.promo}>
                                        {t(
                                            'website.products.promotional_price',
                                        )}
                                    </Label>
                                    <fieldset
                                        disabled={promotionLocked}
                                        className="m-0 min-w-0 border-0 p-0"
                                    >
                                        <MoneyField
                                            id={ids.promo}
                                            name="promotional_price"
                                            value={form.data.promotional_price}
                                            onChange={(value) =>
                                                form.setData(
                                                    'promotional_price',
                                                    value,
                                                )
                                            }
                                            aria-invalid={
                                                form.errors.promotional_price
                                                    ? true
                                                    : undefined
                                            }
                                        />
                                    </fieldset>
                                    <InputError
                                        message={form.errors.promotional_price}
                                    />
                                </div>

                                {(
                                    [
                                        ['promotion_starts_at', ids.starts],
                                        ['promotion_ends_at', ids.ends],
                                    ] as const
                                ).map(([field, id]) => (
                                    <div key={field} className="grid gap-1.5">
                                        <Label htmlFor={id}>
                                            {t(`website.products.${field}`)}
                                        </Label>
                                        <Input
                                            id={id}
                                            name={field}
                                            type="datetime-local"
                                            value={form.data[field]}
                                            onChange={(event) =>
                                                form.setData(
                                                    field,
                                                    event.target.value,
                                                )
                                            }
                                            disabled={promotionLocked}
                                        />
                                        <InputError
                                            message={form.errors[field]}
                                        />
                                    </div>
                                ))}

                                <p className="text-muted-foreground text-xs sm:col-span-2">
                                    {t(
                                        'website.products.promotion_window_help',
                                    )}
                                </p>

                                <div className="grid gap-1.5">
                                    <Label htmlFor={ids.title}>
                                        {t('website.products.promo_title')}
                                    </Label>
                                    <Input
                                        id={ids.title}
                                        name="promo_title"
                                        value={form.data.promo_title}
                                        onChange={(event) =>
                                            form.setData(
                                                'promo_title',
                                                event.target.value,
                                            )
                                        }
                                        maxLength={120}
                                        disabled={locked('promo_title')}
                                    />
                                    <InputError
                                        message={form.errors.promo_title}
                                    />
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor={ids.category}>
                                        {t('website.products.category')}
                                    </Label>
                                    <select
                                        id={ids.category}
                                        name="website_category_id"
                                        value={form.data.website_category_id}
                                        onChange={(event) =>
                                            form.setData(
                                                'website_category_id',
                                                event.target.value,
                                            )
                                        }
                                        className={selectClass}
                                        disabled={locked('website_category')}
                                    >
                                        <option value="">
                                            {t(
                                                'website.products.uncategorised',
                                            )}
                                        </option>
                                        {categories.map((category) => (
                                            <option
                                                key={category.id}
                                                value={category.id}
                                            >
                                                {category.name}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        message={
                                            form.errors.website_category_id
                                        }
                                    />
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor={ids.order}>
                                        {t('website.products.display_order')}
                                    </Label>
                                    <Input
                                        id={ids.order}
                                        name="display_order"
                                        type="number"
                                        min={0}
                                        value={form.data.display_order}
                                        onChange={(event) =>
                                            form.setData(
                                                'display_order',
                                                event.target.value,
                                            )
                                        }
                                        disabled={locked('display_order')}
                                    />
                                    <InputError
                                        message={form.errors.display_order}
                                    />
                                </div>

                                <label className="flex items-center gap-2 self-end pb-2 text-sm">
                                    <input
                                        type="checkbox"
                                        name="is_featured"
                                        checked={form.data.is_featured}
                                        onChange={(event) =>
                                            form.setData(
                                                'is_featured',
                                                event.target.checked,
                                            )
                                        }
                                        disabled={locked('is_featured')}
                                    />
                                    {t('website.products.featured')}
                                </label>
                            </div>

                            <div className="grid gap-1.5">
                                <Label htmlFor={ids.description}>
                                    {t(
                                        'website.products.marketing_description',
                                    )}
                                </Label>
                                <textarea
                                    id={ids.description}
                                    name="marketing_description"
                                    rows={3}
                                    maxLength={1000}
                                    value={form.data.marketing_description}
                                    onChange={(event) =>
                                        form.setData(
                                            'marketing_description',
                                            event.target.value,
                                        )
                                    }
                                    className="border-input bg-background w-full rounded-lg border px-3 py-2 text-sm disabled:opacity-50"
                                    disabled={locked('marketing_description')}
                                />
                                <InputError
                                    message={form.errors.marketing_description}
                                />
                            </div>

                            <InputError
                                message={
                                    (form.errors as Record<string, string>)
                                        .website
                                }
                            />
                        </div>

                        <DialogFooter className="border-t px-4 py-3 sm:px-6 [&>button]:w-full sm:[&>button]:w-auto">
                            <Button
                                ref={cancelButton}
                                type="button"
                                variant="outline"
                                onClick={requestClose}
                                disabled={form.processing}
                            >
                                {t('common.actions.cancel')}
                            </Button>
                            <Button
                                type="submit"
                                disabled={form.processing}
                                aria-busy={form.processing || undefined}
                            >
                                {form.processing && <Spinner />}
                                {t('website.products.save')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={confirmingDiscard}
                onOpenChange={setConfirmingDiscard}
            >
                <DialogContent
                    className="sm:max-w-md"
                    onCloseAutoFocus={(event) => {
                        // Keep editing: back into the editing dialog.
                        event.preventDefault();
                        cancelButton.current?.focus();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {t('website.products.discard_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('website.products.discard_body')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setConfirmingDiscard(false)}
                        >
                            {t('website.products.keep_editing')}
                        </Button>
                        <Button variant="destructive" onClick={onClose}>
                            {t('website.products.discard')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
