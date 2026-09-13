import { Form } from '@inertiajs/react';
import { useState } from 'react';
import ProductStatusController from '@/actions/App/Http/Controllers/Admin/ProductStatusController';
import AlertError from '@/components/alert-error';
import FormField from '@/components/forms/form-field';
import SectionCard from '@/components/section-card';
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
import { useTranslation } from '@/hooks/use-translation';
import type {
    ProductDetail,
    ProductStatusChangeRow,
    ProductTransition,
} from '@/types';

type Props = {
    product: ProductDetail;
    transitions: ProductTransition[];
    history: ProductStatusChangeRow[];
};

/**
 * Where a product stands in its lifecycle, and where it may go next (§11.2).
 *
 * Only the moves this person may make are offered — the server decides which,
 * move by move, because activating, archiving and taking a product off sale are
 * separate permissions. Every move asks for confirmation, and a move that
 * retires the product asks for a reason, because somebody will later want to
 * know why it stopped being sold.
 */
export default function StatusPanel({ product, transitions, history }: Props) {
    const { t } = useTranslation();
    const [moving, setMoving] = useState<ProductTransition | null>(null);

    return (
        <SectionCard
            title={t('catalog.products.lifecycle.title')}
            description={t('catalog.products.lifecycle.description')}
        >
            <div className="space-y-4">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-muted-foreground text-sm">
                        {t('catalog.products.lifecycle.current')}
                    </span>
                    <StatusPill
                        tone={product.status_tone}
                        label={t(`catalog.products.status.${product.status}`)}
                    />
                    {product.published_at && (
                        <span className="text-muted-foreground text-xs">
                            {t('catalog.products.lifecycle.first_published', {
                                date: new Date(
                                    product.published_at,
                                ).toLocaleDateString(),
                            })}
                        </span>
                    )}
                </div>

                {transitions.length > 0 ? (
                    <div className="flex flex-wrap gap-2">
                        {transitions.map((transition) => (
                            <Button
                                key={transition.value}
                                variant={
                                    transition.value === 'active'
                                        ? 'default'
                                        : 'outline'
                                }
                                size="sm"
                                onClick={() => setMoving(transition)}
                            >
                                {t(
                                    `catalog.products.transitions.${transition.value}`,
                                )}
                            </Button>
                        ))}
                    </div>
                ) : (
                    <p className="text-muted-foreground text-sm">
                        {t('catalog.products.lifecycle.no_moves')}
                    </p>
                )}

                {history.length > 0 && (
                    <div className="space-y-2">
                        <h3 className="text-sm font-medium">
                            {t('catalog.products.lifecycle.history')}
                        </h3>
                        <ol className="divide-border divide-y rounded-md border">
                            {history.map((change) => (
                                <li
                                    key={change.id}
                                    className="space-y-1 px-3 py-2 text-sm"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        {change.from && (
                                            <>
                                                <span className="text-muted-foreground">
                                                    {t(
                                                        `catalog.products.status.${change.from}`,
                                                    )}
                                                </span>
                                                <span aria-hidden="true">
                                                    →
                                                </span>
                                            </>
                                        )}
                                        <StatusPill
                                            tone={change.to_tone}
                                            label={t(
                                                `catalog.products.status.${change.to}`,
                                            )}
                                        />
                                        <span className="text-muted-foreground text-xs">
                                            {change.actor ??
                                                t(
                                                    'catalog.products.lifecycle.system',
                                                )}{' '}
                                            ·{' '}
                                            {new Date(
                                                change.at,
                                            ).toLocaleString()}
                                        </span>
                                    </div>
                                    {change.reason && (
                                        <p className="text-muted-foreground text-xs">
                                            {change.reason}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ol>
                    </div>
                )}
            </div>

            {moving && (
                <Dialog open onOpenChange={(open) => !open && setMoving(null)}>
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>
                                {t(
                                    `catalog.products.transitions.${moving.value}`,
                                )}
                            </DialogTitle>
                            <DialogDescription>
                                {t('catalog.products.lifecycle.confirm', {
                                    name: product.name,
                                    status: t(
                                        `catalog.products.status.${moving.value}`,
                                    ),
                                })}
                            </DialogDescription>
                        </DialogHeader>

                        <Form
                            {...ProductStatusController.update.form(product.id)}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setMoving(null)}
                            className="space-y-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="status"
                                        value={moving.value}
                                    />

                                    {errors.status && (
                                        <AlertError errors={[errors.status]} />
                                    )}

                                    <FormField
                                        label={t(
                                            'catalog.products.lifecycle.reason',
                                        )}
                                        required={moving.requires_reason}
                                        error={errors.reason}
                                    >
                                        {(field) => (
                                            <textarea
                                                {...field}
                                                name="reason"
                                                rows={3}
                                                maxLength={2000}
                                                required={
                                                    moving.requires_reason
                                                }
                                                className="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                            />
                                        )}
                                    </FormField>

                                    <DialogFooter>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() => setMoving(null)}
                                        >
                                            {t('common.actions.cancel')}
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {t(
                                                `catalog.products.transitions.${moving.value}`,
                                            )}
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            )}
        </SectionCard>
    );
}
