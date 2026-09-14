import { Form, Head, router, usePage } from '@inertiajs/react';
import { Pencil, Plus, SlidersHorizontal, Trash2 } from 'lucide-react';
import { useState } from 'react';
import ProductAttributeController from '@/actions/App/Http/Controllers/Admin/ProductAttributeController';
import AlertError from '@/components/alert-error';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
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
import type { AttributeRow, CatalogAbilities } from '@/types';

type Props = {
    attributes: AttributeRow[];
    can: CatalogAbilities;
};

type Renaming =
    | { kind: 'create' }
    | { kind: 'attribute'; id: string; current: string }
    | { kind: 'value'; id: string; current: string };

/**
 * The shared attributes variations are built from (§11.1).
 *
 * A value in use shows how many variations carry it and offers no remove button:
 * the server refuses the removal anyway, and a button that sometimes lies teaches
 * people to stop trusting the screen.
 */
export default function AdminAttributes({ attributes, can }: Props) {
    const { t } = useTranslation();
    const page = usePage<{ errors: Record<string, string> }>();

    const [renaming, setRenaming] = useState<Renaming | null>(null);

    const refusal = page.props.errors?.attribute ?? page.props.errors?.value;

    const removeAttribute = (attribute: AttributeRow) => {
        if (!window.confirm(t('catalog.attributes.delete_confirm'))) {
            return;
        }

        router.delete(ProductAttributeController.destroy.url(attribute.id), {
            preserveScroll: true,
        });
    };

    const removeValue = (id: string) => {
        if (!window.confirm(t('catalog.attributes.delete_value_confirm'))) {
            return;
        }

        router.delete(ProductAttributeController.destroyValue.url(id), {
            preserveScroll: true,
        });
    };

    return (
        <>
            <Head title={t('catalog.attributes.title')} />

            <PageContainer>
                <PageHeader
                    title={t('catalog.attributes.title')}
                    description={t('catalog.attributes.description')}
                    actions={
                        can.create ? (
                            <Button
                                onClick={() => setRenaming({ kind: 'create' })}
                            >
                                <Plus className="size-4" aria-hidden="true" />
                                {t('catalog.attributes.create')}
                            </Button>
                        ) : undefined
                    }
                />

                {refusal && <AlertError errors={[refusal]} />}

                {attributes.length === 0 ? (
                    <EmptyState
                        icon={SlidersHorizontal}
                        title={t('catalog.attributes.empty')}
                        description={t(
                            can.create
                                ? 'catalog.attributes.empty_help'
                                : 'catalog.attributes.empty_help_read_only',
                        )}
                    />
                ) : (
                    <ul className="bg-card divide-border divide-y rounded-xl border">
                        {attributes.map((attribute) => (
                            <li key={attribute.id} className="space-y-3 p-4">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h2 className="font-medium">
                                            {attribute.name}
                                        </h2>
                                        <StatusPill
                                            tone={
                                                attribute.uses > 0
                                                    ? 'info'
                                                    : 'neutral'
                                            }
                                            label={
                                                attribute.uses > 0
                                                    ? t(
                                                          'catalog.attributes.uses',
                                                          {
                                                              count: attribute.uses,
                                                          },
                                                      )
                                                    : t(
                                                          'catalog.attributes.unused',
                                                      )
                                            }
                                        />
                                    </div>

                                    <div className="flex flex-wrap items-center gap-1">
                                        {can.edit && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setRenaming({
                                                        kind: 'attribute',
                                                        id: attribute.id,
                                                        current: attribute.name,
                                                    })
                                                }
                                            >
                                                <Pencil
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                                {t('catalog.attributes.rename')}
                                            </Button>
                                        )}
                                        {can.delete && attribute.uses === 0 && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    removeAttribute(attribute)
                                                }
                                            >
                                                <Trash2
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                                {t('catalog.attributes.remove')}
                                            </Button>
                                        )}
                                    </div>
                                </div>

                                {attribute.values.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">
                                        {t('catalog.attributes.no_values')}
                                    </p>
                                ) : (
                                    <ul className="flex flex-wrap gap-2">
                                        {attribute.values.map((value) => (
                                            <li
                                                key={value.id}
                                                className="bg-muted/50 flex items-center gap-1 rounded-md border py-1 pr-1 pl-2.5 text-sm"
                                            >
                                                <span>{value.value}</span>
                                                {value.uses > 0 && (
                                                    <span className="text-muted-foreground text-xs tabular-nums">
                                                        ·{' '}
                                                        {t(
                                                            'catalog.attributes.uses',
                                                            {
                                                                count: value.uses,
                                                            },
                                                        )}
                                                    </span>
                                                )}
                                                {can.edit && (
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-6"
                                                        aria-label={`${t('catalog.attributes.rename')} ${value.value}`}
                                                        onClick={() =>
                                                            setRenaming({
                                                                kind: 'value',
                                                                id: value.id,
                                                                current:
                                                                    value.value,
                                                            })
                                                        }
                                                    >
                                                        <Pencil className="size-3" />
                                                    </Button>
                                                )}
                                                {can.delete &&
                                                    value.uses === 0 && (
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="size-6"
                                                            aria-label={`${t('catalog.attributes.remove')} ${value.value}`}
                                                            onClick={() =>
                                                                removeValue(
                                                                    value.id,
                                                                )
                                                            }
                                                        >
                                                            <Trash2 className="size-3" />
                                                        </Button>
                                                    )}
                                            </li>
                                        ))}
                                    </ul>
                                )}

                                {can.create && (
                                    <Form
                                        {...ProductAttributeController.storeValue.form(
                                            attribute.id,
                                        )}
                                        options={{ preserveScroll: true }}
                                        resetOnSuccess
                                        className="flex max-w-md flex-wrap items-start gap-2"
                                    >
                                        {({ errors, processing }) => (
                                            <>
                                                <div className="min-w-40 flex-1">
                                                    <Label
                                                        htmlFor={`value-${attribute.id}`}
                                                        className="sr-only"
                                                    >
                                                        {t(
                                                            'catalog.attributes.value',
                                                        )}
                                                    </Label>
                                                    <Input
                                                        id={`value-${attribute.id}`}
                                                        name="value"
                                                        maxLength={80}
                                                        placeholder={t(
                                                            'catalog.attributes.value_placeholder',
                                                        )}
                                                    />
                                                    <InputError
                                                        message={errors.value}
                                                    />
                                                </div>
                                                <Button
                                                    type="submit"
                                                    variant="outline"
                                                    disabled={processing}
                                                >
                                                    <Plus
                                                        className="size-4"
                                                        aria-hidden="true"
                                                    />
                                                    {t(
                                                        'catalog.attributes.add_value',
                                                    )}
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </PageContainer>

            <NameDialog renaming={renaming} onClose={() => setRenaming(null)} />
        </>
    );
}

/**
 * One text field in a dialog: a new attribute, or a rename of an attribute or
 * a value.
 */
function NameDialog({
    renaming,
    onClose,
}: {
    renaming: Renaming | null;
    onClose: () => void;
}) {
    const { t } = useTranslation();

    if (renaming === null) {
        return null;
    }

    const isValue = renaming.kind === 'value';
    const field = isValue ? 'value' : 'name';

    const form =
        renaming.kind === 'create'
            ? ProductAttributeController.store.form()
            : renaming.kind === 'attribute'
              ? ProductAttributeController.update.form(renaming.id)
              : ProductAttributeController.updateValue.form(renaming.id);

    const title = t(
        renaming.kind === 'create'
            ? 'catalog.attributes.create_title'
            : isValue
              ? 'catalog.attributes.rename_value_title'
              : 'catalog.attributes.rename_title',
    );

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>
                        {t('catalog.attributes.description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...form}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="attribute-name">
                                    {t(
                                        isValue
                                            ? 'catalog.attributes.value'
                                            : 'catalog.attributes.name',
                                    )}
                                </Label>
                                <Input
                                    id="attribute-name"
                                    name={field}
                                    required
                                    maxLength={80}
                                    placeholder={t(
                                        isValue
                                            ? 'catalog.attributes.value_placeholder'
                                            : 'catalog.attributes.name_placeholder',
                                    )}
                                    defaultValue={
                                        renaming.kind === 'create'
                                            ? ''
                                            : renaming.current
                                    }
                                />
                                <InputError message={errors[field]} />
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={onClose}
                                >
                                    {t('common.actions.cancel')}
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {t('common.actions.save')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
