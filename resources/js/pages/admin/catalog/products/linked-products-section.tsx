import { Link, router } from '@inertiajs/react';
import { ExternalLink, Link2, Unlink } from 'lucide-react';
import { useState } from 'react';
import ProductLinkController from '@/actions/App/Http/Controllers/Admin/ProductLinkController';
import AlertError from '@/components/alert-error';
import FormField from '@/components/forms/form-field';
import ProductSearchDialog from '@/components/product-links/product-search-dialog';
import ProductSummary from '@/components/product-links/product-summary';
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
import { edit } from '@/routes/admin/catalog/products';
import type {
    DirectProductLink,
    LinkedProductsState,
    ProductDetail,
    ProductLinkSummary,
} from '@/types';
import ReasonTextarea from '@/components/forms/reason-textarea';

type Props = {
    product: ProductDetail;
    linked: LinkedProductsState;
};

const textareaClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Same Product links, as seen from one Product (Admin Product workspace).
 *
 * Every Product here is an independent record: there is no master, parent or
 * primary. Direct connections can be unlinked one by one; Products that are only
 * reachable through them are shown as indirect and have no unlink of their own —
 * the only way to disconnect one is to remove the direct link responsible.
 */
export default function LinkedProductsSection({ product, linked }: Props) {
    const { t } = useTranslation();
    const [searching, setSearching] = useState(false);
    const [candidate, setCandidate] = useState<ProductLinkSummary | null>(null);
    const [unlinking, setUnlinking] = useState<DirectProductLink | null>(null);

    const excluded = [
        product.id,
        ...linked.direct.map((link) => link.product.id),
    ];

    return (
        <SectionCard
            title={t('product_links.section.title')}
            description={t('product_links.section.description')}
            actions={
                linked.can.link && (
                    <Button
                        type="button"
                        size="sm"
                        onClick={() => setSearching(true)}
                    >
                        <Link2 className="size-4" aria-hidden="true" />
                        {t('product_links.section.link')}
                    </Button>
                )
            }
        >
            <div className="space-y-6">
                <div className="space-y-2">
                    <h3 className="text-sm font-medium">
                        {t('product_links.section.current')}
                    </h3>
                    <div className="bg-muted/40 rounded-md border p-3">
                        <ProductSummary product={linked.current} />
                    </div>
                </div>

                <div className="space-y-2">
                    <h3 className="text-sm font-medium">
                        {t('product_links.section.direct')}
                    </h3>

                    {linked.direct.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            {t('product_links.section.none')}
                        </p>
                    ) : (
                        <ul className="space-y-3">
                            {linked.direct.map((link) => (
                                <DirectLinkRow
                                    key={link.id}
                                    productId={product.id}
                                    link={link}
                                    canUnlink={linked.can.unlink}
                                    onUnlink={() => setUnlinking(link)}
                                />
                            ))}
                        </ul>
                    )}
                </div>

                {linked.indirect.length > 0 && (
                    <div className="space-y-2">
                        <h3 className="text-sm font-medium">
                            {t('product_links.section.indirect')}
                        </h3>
                        <p className="text-muted-foreground text-xs">
                            {t('product_links.section.indirect_help')}
                        </p>
                        <ul className="divide-border divide-y rounded-md border">
                            {linked.indirect.map((entry) => (
                                <li key={entry.product.id} className="p-3">
                                    <ProductSummary
                                        product={entry.product}
                                        action={
                                            <div className="flex flex-col items-end gap-2">
                                                <StatusPill
                                                    tone="neutral"
                                                    label={t(
                                                        'product_links.section.indirect_badge',
                                                        {
                                                            count: entry.distance,
                                                        },
                                                    )}
                                                />
                                                <ViewButton
                                                    productId={entry.product.id}
                                                />
                                            </div>
                                        }
                                    />
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>

            <ProductSearchDialog
                open={searching}
                onOpenChange={setSearching}
                excludeIds={excluded}
                onPick={(picked) => {
                    setSearching(false);
                    setCandidate(picked);
                }}
            />

            <LinkConfirmDialog
                productId={product.id}
                candidate={candidate}
                onClose={() => setCandidate(null)}
            />

            <UnlinkConfirmDialog
                productId={product.id}
                link={unlinking}
                onClose={() => setUnlinking(null)}
            />
        </SectionCard>
    );
}

function ViewButton({ productId }: { productId: string }) {
    const { t } = useTranslation();

    return (
        <Button variant="ghost" size="sm" asChild>
            <Link href={edit.url(productId)}>
                <ExternalLink className="size-4" aria-hidden="true" />
                {t('product_links.section.view')}
            </Link>
        </Button>
    );
}

function DirectLinkRow({
    productId,
    link,
    canUnlink,
    onUnlink,
}: {
    productId: string;
    link: DirectProductLink;
    canUnlink: boolean;
    onUnlink: () => void;
}) {
    const { t } = useTranslation();
    const hasVariants =
        link.my_variants.length > 0 || link.their_variants.length > 0;

    return (
        <li className="space-y-3 rounded-md border p-3">
            <ProductSummary
                product={link.product}
                action={
                    <div className="flex flex-col items-end gap-2">
                        <StatusPill
                            tone="info"
                            label={t('product_links.section.direct_badge')}
                        />
                        <div className="flex items-center gap-1">
                            <ViewButton productId={link.product.id} />
                            {canUnlink && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={onUnlink}
                                >
                                    <Unlink
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t('product_links.section.unlink')}
                                </Button>
                            )}
                        </div>
                    </div>
                }
            />

            <p className="text-muted-foreground text-xs">
                {t('product_links.section.linked_by', {
                    name: link.linked_by ?? '—',
                    date: new Date(link.linked_at).toLocaleDateString(),
                })}
                {link.reason ? ` · ${link.reason}` : ''}
            </p>

            {hasVariants && (
                <VariantMatches
                    productId={productId}
                    link={link}
                    canEdit={canUnlink}
                />
            )}
        </li>
    );
}

/**
 * Variations are never assumed interchangeable. A linked Product's variation
 * is only a substitute for one of this Product's once staff match the two here.
 */
function VariantMatches({
    productId,
    link,
    canEdit,
}: {
    productId: string;
    link: DirectProductLink;
    canEdit: boolean;
}) {
    const { t } = useTranslation();
    const [mine, setMine] = useState('');
    const [theirs, setTheirs] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);

    const add = () => {
        router.post(
            ProductLinkController.mapVariants.url({
                product: productId,
                link: link.id,
            }),
            { variant: mine || null, other_variant: theirs || null },
            {
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setError(undefined);
                },
                onSuccess: () => {
                    setMine('');
                    setTheirs('');
                },
                onError: (errors) => setError(errors.variant),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const remove = (mappingId: string) =>
        router.delete(
            ProductLinkController.unmapVariants.url({
                product: productId,
                link: link.id,
                mapping: mappingId,
            }),
            { preserveScroll: true },
        );

    return (
        <div className="space-y-2 border-t pt-3">
            <h4 className="text-xs font-medium">
                {t('product_links.variants.title')}
            </h4>
            <p className="text-muted-foreground text-xs">
                {t('product_links.variants.help')}
            </p>

            {link.variant_matches.length === 0 ? (
                <p className="text-muted-foreground text-xs">
                    {t('product_links.variants.none')}
                </p>
            ) : (
                <ul className="divide-border divide-y rounded-md border text-sm">
                    {link.variant_matches.map((match) => (
                        <li
                            key={match.id}
                            className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                        >
                            <span>
                                {match.mine?.label ??
                                    t('product_links.variants.product_itself')}
                                {' ↔ '}
                                {match.theirs?.label ??
                                    t('product_links.variants.product_itself')}
                            </span>
                            {canEdit && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => remove(match.id)}
                                >
                                    {t('product_links.variants.remove')}
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {canEdit && (
                <div className="space-y-2">
                    {error && <AlertError errors={[error]} />}
                    <div className="grid gap-2 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                        {link.my_variants.length > 0 && (
                            <div className="grid gap-1.5">
                                <label
                                    className="text-xs"
                                    htmlFor={`mine-${link.id}`}
                                >
                                    {t('product_links.variants.this_product')}
                                </label>
                                <select
                                    id={`mine-${link.id}`}
                                    value={mine}
                                    onChange={(event) =>
                                        setMine(event.target.value)
                                    }
                                    className={selectClass}
                                >
                                    <option value="">
                                        {t('product_links.variants.choose')}
                                    </option>
                                    {link.my_variants.map((variant) => (
                                        <option
                                            key={variant.id}
                                            value={variant.id}
                                        >
                                            {variant.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}
                        {link.their_variants.length > 0 && (
                            <div className="grid gap-1.5">
                                <label
                                    className="text-xs"
                                    htmlFor={`theirs-${link.id}`}
                                >
                                    {t('product_links.variants.linked_product')}
                                </label>
                                <select
                                    id={`theirs-${link.id}`}
                                    value={theirs}
                                    onChange={(event) =>
                                        setTheirs(event.target.value)
                                    }
                                    className={selectClass}
                                >
                                    <option value="">
                                        {t('product_links.variants.choose')}
                                    </option>
                                    {link.their_variants.map((variant) => (
                                        <option
                                            key={variant.id}
                                            value={variant.id}
                                        >
                                            {variant.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={
                                processing ||
                                (link.my_variants.length > 0 && mine === '') ||
                                (link.their_variants.length > 0 &&
                                    theirs === '')
                            }
                            onClick={add}
                        >
                            {t('product_links.variants.match')}
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}

function LinkConfirmDialog({
    productId,
    candidate,
    onClose,
}: {
    productId: string;
    candidate: ProductLinkSummary | null;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);

    const confirm = () => {
        if (candidate === null) {
            return;
        }

        router.post(
            ProductLinkController.store.url(productId),
            { product_id: candidate.id, reason: reason || null },
            {
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setError(undefined);
                },
                onSuccess: () => {
                    setReason('');
                    onClose();
                },
                onError: (errors) => setError(errors.product_id),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog
            open={candidate !== null}
            onOpenChange={(open) => {
                if (!open && !processing) {
                    setError(undefined);
                    onClose();
                }
            }}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {t('product_links.confirm_link.title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('product_links.confirm_link.description')}
                    </DialogDescription>
                </DialogHeader>

                {candidate && (
                    <div className="rounded-md border p-3">
                        <ProductSummary product={candidate} />
                    </div>
                )}

                {error && <AlertError errors={[error]} />}

                <FormField label={t('product_links.reason_label')}>
                    {(field) => (
                        <ReasonTextarea
                            context="product"
                            {...field}
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            rows={2}
                            maxLength={1000}
                            className={textareaClass}
                        />
                    )}
                </FormField>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={onClose}
                        disabled={processing}
                    >
                        {t('common.actions.cancel')}
                    </Button>
                    <Button
                        type="button"
                        onClick={confirm}
                        disabled={processing}
                    >
                        {t('product_links.confirm_link.confirm')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function UnlinkConfirmDialog({
    productId,
    link,
    onClose,
}: {
    productId: string;
    link: DirectProductLink | null;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);

    const confirm = () => {
        if (link === null) {
            return;
        }

        router.delete(
            ProductLinkController.destroy.url({
                product: productId,
                link: link.id,
            }),
            {
                data: { reason: reason || null },
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setError(undefined);
                },
                onSuccess: () => {
                    setReason('');
                    onClose();
                },
                onError: (errors) => setError(errors.link),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog
            open={link !== null}
            onOpenChange={(open) => {
                if (!open && !processing) {
                    setError(undefined);
                    onClose();
                }
            }}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {t('product_links.confirm_unlink.title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('product_links.confirm_unlink.description')}
                    </DialogDescription>
                </DialogHeader>

                {link && (
                    <div className="rounded-md border p-3">
                        <ProductSummary product={link.product} />
                    </div>
                )}

                {error && <AlertError errors={[error]} />}

                <FormField label={t('product_links.reason_label')}>
                    {(field) => (
                        <ReasonTextarea
                            context="product"
                            {...field}
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            rows={2}
                            maxLength={1000}
                            className={textareaClass}
                        />
                    )}
                </FormField>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={onClose}
                        disabled={processing}
                    >
                        {t('common.actions.cancel')}
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        onClick={confirm}
                        disabled={processing}
                    >
                        {t('product_links.confirm_unlink.confirm')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
