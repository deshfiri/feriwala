import { Form, Head, router } from '@inertiajs/react';
import { Lock, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import SubmitButton from '@/components/forms/submit-button';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { archive } from '@/routes/supplier/listing-lots';
import { archive as archiveEntry } from '@/routes/supplier/listing-lots/items';
import { store as submission } from '@/routes/supplier/listing-lots/submission';
import ListingLotEntryDialog from './entry-dialog';
import ListingLotMediaManager from './media-manager';
import type { AttributeOption, Lot, LotEntry } from './types';

export default function SupplierListingLotWorkspace({
    lot,
    options,
}: {
    lot: Lot;
    options: {
        categories: { value: string; label: string }[];
        brands: { value: string; label: string }[];
        attributes: AttributeOption[];
    };
}) {
    const { t, locale } = useTranslation();
    const [editing, setEditing] = useState<LotEntry | null | 'new'>(null);

    const remove = (entry: LotEntry) => {
        if (
            !window.confirm(t('supplier.listing_lots.workspace.remove_entry'))
        ) {
            return;
        }

        router.post(
            archiveEntry([lot.id, entry.id]).url,
            {},
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title={lot.title ?? lot.reference} />

            <PageContainer width="narrow">
                <PageHeader
                    title={lot.title ?? lot.reference}
                    description={lot.reference}
                    actions={
                        <StatusPill
                            tone={lot.status_tone}
                            label={lot.status_label}
                        />
                    }
                />

                {lot.is_draft && (
                    <p className="text-muted-foreground text-sm">
                        {t('supplier.listing_lots.workspace.draft_notice')}
                    </p>
                )}

                {lot.entries.map((entry) => (
                    <SectionCard
                        key={entry.id}
                        title={entry.product_name}
                        description={entry.status_label}
                        actions={
                            <>
                                <StatusPill
                                    tone={entry.status_tone}
                                    label={entry.status_label}
                                />
                                {entry.is_editable && (
                                    <>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setEditing(entry)}
                                        >
                                            <Pencil
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            {t('common.actions.edit')}
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => remove(entry)}
                                        >
                                            <Trash2
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            {t(
                                                'supplier.listing_lots.workspace.remove_entry',
                                            )}
                                        </Button>
                                    </>
                                )}
                            </>
                        }
                    >
                        <div className="space-y-4">
                            {entry.decision_note && (
                                <p className="text-sm">{entry.decision_note}</p>
                            )}

                            <ul className="divide-border divide-y">
                                {entry.items.map((item) => (
                                    <li
                                        key={item.id}
                                        className="space-y-1 py-3 first:pt-0 last:pb-0"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <span className="text-sm font-medium">
                                                {item.variant_label ??
                                                    entry.product_name}{' '}
                                                · {item.supplier_sku}
                                            </span>
                                            <StatusPill
                                                tone="neutral"
                                                label={item.supply_mode_label}
                                            />
                                        </div>
                                        <p className="text-muted-foreground text-sm">
                                            {t(
                                                'supplier.listings.supplier_rate',
                                            )}
                                            :{' '}
                                            <MoneyAmount
                                                amount={item.supplier_rate}
                                            />
                                            {item.supply_mode ===
                                                'ready_stock' &&
                                                item.available_quantity !==
                                                    null && (
                                                    <>
                                                        {' '}
                                                        ·{' '}
                                                        {t(
                                                            'supplier.listings.available_quantity',
                                                        )}
                                                        :{' '}
                                                        {item.available_quantity.toLocaleString(
                                                            locale,
                                                        )}
                                                    </>
                                                )}
                                            {item.supply_mode !==
                                                'ready_stock' &&
                                                item.fulfilment_capacity !==
                                                    null && (
                                                    <>
                                                        {' '}
                                                        ·{' '}
                                                        {t(
                                                            'supplier.listing_lots.supply_mode.fulfilment_capacity',
                                                        )}
                                                        :{' '}
                                                        {item.fulfilment_capacity.toLocaleString(
                                                            locale,
                                                        )}
                                                    </>
                                                )}
                                        </p>
                                    </li>
                                ))}
                            </ul>

                            <ListingLotMediaManager
                                listingId={entry.id}
                                items={entry.media}
                            />
                        </div>
                    </SectionCard>
                ))}

                {lot.is_draft && (
                    <Button variant="outline" onClick={() => setEditing('new')}>
                        <Plus className="size-4" aria-hidden="true" />
                        {t('supplier.listing_lots.workspace.add_entry')}
                    </Button>
                )}

                {lot.entries.length > 0 ? (
                    <div className="flex flex-wrap gap-3">
                        <Form {...submission.form(lot.id)}>
                            {({ processing, errors }) => (
                                <div className="space-y-2">
                                    <SubmitButton processing={processing}>
                                        {t(
                                            'supplier.listing_lots.workspace.submit_batch',
                                        )}
                                    </SubmitButton>
                                    {errors.lot && (
                                        <p
                                            role="alert"
                                            className="text-danger text-sm font-medium"
                                        >
                                            {errors.lot}
                                        </p>
                                    )}
                                </div>
                            )}
                        </Form>
                    </div>
                ) : (
                    lot.is_draft && (
                        <Form {...archive.form(lot.id)}>
                            {({ processing }) => (
                                <SubmitButton
                                    processing={processing}
                                    variant="outline"
                                >
                                    {t(
                                        'supplier.listing_lots.workspace.discard_batch',
                                    )}
                                </SubmitButton>
                            )}
                        </Form>
                    )
                )}

                {!lot.is_draft && lot.entries.every((e) => !e.is_editable) && (
                    <p className="text-muted-foreground flex items-center gap-2 text-sm">
                        <Lock className="size-4" aria-hidden="true" />
                        {t('supplier.listing_lots.workspace.locked')}
                    </p>
                )}
            </PageContainer>

            {editing !== null && (
                <ListingLotEntryDialog
                    lotId={lot.id}
                    entry={editing === 'new' ? null : editing}
                    options={options}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}
