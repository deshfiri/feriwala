import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import CaseTimeline from '@/components/cases/case-timeline';
import EvidenceGallery from '@/components/cases/evidence-gallery';
import FinancialsPanel from '@/components/cases/financials-panel';
import FormField from '@/components/forms/form-field';
import NativeSelect from '@/components/forms/native-select';
import InputError from '@/components/input-error';
import LineThumb from '@/components/line-thumb';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
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
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import { index } from '@/routes/damages';
import { store as deliveryRoute } from '@/routes/damages/delivery';
import { store as receipt } from '@/routes/damages/receipt';
import type {
    EvidenceFile,
    Financials,
    LegacyEvidence,
    PartnerDamageRow,
    TimelineEntry,
} from '@/types/cases';
import { index as addressBook } from '@/routes/addresses';

type Props = {
    damage: PartnerDamageRow & {
        condition_label: string;
        quantity: number;
        status: string;
        description: string | null;
        order_url: string;
        remains_with_customer: boolean;
        dispositions: {
            disposition: string;
            label: string;
            quantity: number;
        }[];
        delivery_request: {
            state: string;
            delivery_charge: Money | null;
            tracking_number: string | null;
            requested_at: string | null;
            dispatched_at: string | null;
            received_at: string | null;
        };
        tracking: { number: string | null; courier: string | null } | null;
        reported_at: string;
    };
    evidence: EvidenceFile[];
    legacy_evidence: LegacyEvidence[];
    financials: Financials;
    timeline: TimelineEntry[];
    delivery: {
        can_request: boolean;
        quote: {
            charge: Money;
            quantity: number;
            wallet_can_cover: boolean;
        } | null;
        addresses: { id: string; label: string; is_default: boolean }[];
    };
    can_confirm_receipt: boolean;
};

/**
 * One damaged item, for the Partner who bears the loss (D35).
 *
 * The safe timeline, the evidence they may see, what it cost them and how, where
 * the item is kept and where its delivery stands. Where the item is held and the
 * loss is posted they may ask for it to be delivered to one of their own saved
 * addresses: the dialog shows the delivery charge the **server** worked out and
 * whether their wallet can cover it, and the request carries only which address —
 * never an amount. The item stays unsellable throughout, and a delivered or
 * dispatched item is never described as back on sale.
 */
export default function MyDamage({
    damage,
    evidence,
    legacy_evidence,
    financials,
    timeline,
    delivery,
    can_confirm_receipt,
}: Props) {
    const { t, locale } = useTranslation();
    const [asking, setAsking] = useState(false);
    const when = (value: string | null) =>
        value ? new Date(value).toLocaleString(locale) : '—';

    return (
        <>
            <Head title={damage.reference} />

            <PageContainer>
                <PageHeader
                    title={damage.reference}
                    description={`${damage.product.name}${damage.product.variation ? ` · ${damage.product.variation}` : ''}`}
                    meta={
                        <StatusPill tone="info" label={damage.status_label} />
                    }
                    back={{ href: index(), label: t('damages.back') }}
                    actions={
                        delivery.can_request ? (
                            <Button size="sm" onClick={() => setAsking(true)}>
                                {t('damages.request_delivery')}
                            </Button>
                        ) : can_confirm_receipt ? (
                            <Form
                                {...receipt.form(damage.id)}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <Button
                                            type="submit"
                                            size="sm"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            {t('damages.confirm_received')}
                                        </Button>
                                        <InputError message={errors.case} />
                                    </>
                                )}
                            </Form>
                        ) : undefined
                    }
                />

                {damage.remains_with_customer && (
                    <p
                        role="status"
                        className="bg-muted rounded-lg p-3 text-sm"
                    >
                        {t('damages.remains_with_customer')}
                    </p>
                )}

                <div className="grid gap-4 lg:grid-cols-[2fr_1fr]">
                    <div className="space-y-4">
                        <SectionCard title={t('damages.summary')}>
                            <div className="flex items-start gap-3">
                                {damage.image ? (
                                    <img
                                        src={damage.image.url}
                                        alt={
                                            damage.image.alt ??
                                            damage.product.name
                                        }
                                        loading="lazy"
                                        className="size-16 shrink-0 rounded-lg border object-cover"
                                    />
                                ) : (
                                    <LineThumb name={damage.product.name} />
                                )}
                                <dl className="grid min-w-0 flex-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                                    <Fact
                                        label={t('damages.order')}
                                        value={
                                            <Link
                                                href={damage.order_url}
                                                className="font-mono underline-offset-4 hover:underline"
                                            >
                                                {damage.order_number}
                                            </Link>
                                        }
                                    />
                                    <Fact
                                        label={t('damages.quantity')}
                                        value={String(damage.quantity)}
                                    />
                                    <Fact
                                        label={t('damages.condition')}
                                        value={damage.condition_label}
                                    />
                                    <Fact
                                        label={t('damages.cause')}
                                        value={damage.cause_label ?? '—'}
                                    />
                                    <Fact
                                        label={t('damages.responsibility')}
                                        value={
                                            damage.responsibility_label ?? '—'
                                        }
                                    />
                                    <Fact
                                        label={t('damages.loss')}
                                        value={
                                            damage.approved_loss ? (
                                                <MoneyAmount
                                                    amount={
                                                        damage.approved_loss
                                                    }
                                                    direction="debit"
                                                />
                                            ) : (
                                                '—'
                                            )
                                        }
                                    />
                                    <Fact
                                        label={t('damages.storage')}
                                        value={t(
                                            `damages.storage_states.${damage.storage_state}`,
                                        )}
                                    />
                                    <Fact
                                        label={t('damages.delivery')}
                                        value={t(
                                            `damages.delivery_states.${damage.delivery_request.state}`,
                                        )}
                                    />
                                </dl>
                            </div>

                            {damage.description && (
                                <p className="mt-3 text-sm break-words whitespace-pre-line">
                                    {damage.description}
                                </p>
                            )}

                            {damage.dispositions.length > 0 && (
                                <div className="mt-4">
                                    <h3 className="text-sm font-medium">
                                        {t('damages.disposition')}
                                    </h3>
                                    <ul className="text-muted-foreground list-disc pl-5 text-sm">
                                        {damage.dispositions.map((row) => (
                                            <li key={row.disposition}>
                                                {row.label}: {row.quantity}
                                            </li>
                                        ))}
                                    </ul>
                                    <p className="text-muted-foreground mt-1 text-xs">
                                        {t('damages.never_sellable')}
                                    </p>
                                </div>
                            )}
                        </SectionCard>

                        {damage.delivery_request.state !== 'not_available' &&
                            damage.delivery_request.state !== 'available' && (
                                <SectionCard
                                    title={t('damages.delivery_title')}
                                >
                                    <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                                        <Fact
                                            label={t('damages.delivery')}
                                            value={t(
                                                `damages.delivery_states.${damage.delivery_request.state}`,
                                            )}
                                        />
                                        {damage.delivery_request
                                            .delivery_charge && (
                                            <Fact
                                                label={t(
                                                    'damages.delivery_charge',
                                                )}
                                                value={
                                                    <MoneyAmount
                                                        amount={
                                                            damage
                                                                .delivery_request
                                                                .delivery_charge
                                                        }
                                                        direction="debit"
                                                    />
                                                }
                                            />
                                        )}
                                        <Fact
                                            label={t('damages.requested_at')}
                                            value={when(
                                                damage.delivery_request
                                                    .requested_at,
                                            )}
                                        />
                                        <Fact
                                            label={t('damages.dispatched_at')}
                                            value={when(
                                                damage.delivery_request
                                                    .dispatched_at,
                                            )}
                                        />
                                        <Fact
                                            label={t('damages.received_at')}
                                            value={when(
                                                damage.delivery_request
                                                    .received_at,
                                            )}
                                        />
                                        {damage.tracking?.number && (
                                            <Fact
                                                label={t('damages.tracking')}
                                                value={`${damage.tracking.number}${damage.tracking.courier ? ` (${damage.tracking.courier})` : ''}`}
                                            />
                                        )}
                                    </dl>
                                </SectionCard>
                            )}

                        <SectionCard title={t('cases.sections.evidence')}>
                            <EvidenceGallery
                                files={evidence}
                                legacy={legacy_evidence}
                            />
                        </SectionCard>

                        <SectionCard title={t('cases.sections.timeline')}>
                            <CaseTimeline entries={timeline} />
                        </SectionCard>
                    </div>

                    <div className="space-y-4">
                        <SectionCard title={t('damages.deduction')}>
                            <FinancialsPanel
                                financials={financials}
                                audience="partner"
                            />
                        </SectionCard>
                        {financials.ledger.length > 0 && (
                            <SectionCard
                                title={t('cases.partner.wallet_entries')}
                            >
                                <FinancialsPanel
                                    financials={financials}
                                    audience="partner"
                                    section="ledger"
                                />
                            </SectionCard>
                        )}
                    </div>
                </div>
            </PageContainer>

            {asking && (
                <DeliveryDialog
                    damageId={damage.id}
                    delivery={delivery}
                    onClose={() => setAsking(false)}
                />
            )}
        </>
    );
}

MyDamage.layout = {
    breadcrumbs: [{ title: 'nav.my_damages', href: index() }],
};

function Fact({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="min-w-0">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="break-words">{value}</dd>
        </div>
    );
}

function DeliveryDialog({
    damageId,
    delivery,
    onClose,
}: {
    damageId: string;
    delivery: Props['delivery'];
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const hasAddress = delivery.addresses.length > 0;
    const covered = delivery.quote?.wallet_can_cover ?? false;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('damages.request_delivery')}</DialogTitle>
                    <DialogDescription>
                        {t('damages.delivery_help')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...deliveryRoute.form(damageId)}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="grid gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            {delivery.quote && (
                                <section
                                    aria-label={t('damages.delivery_charge')}
                                    className="bg-surface-subtle space-y-1 rounded-lg border px-4 py-3 text-sm"
                                >
                                    <div className="flex items-center justify-between gap-3">
                                        <span className="text-muted-foreground">
                                            {t('damages.units_to_send')}
                                        </span>
                                        <span className="tabular-nums">
                                            {delivery.quote.quantity}
                                        </span>
                                    </div>
                                    <div className="flex items-center justify-between gap-3">
                                        <span className="text-muted-foreground">
                                            {t('damages.delivery_charge')}
                                        </span>
                                        <MoneyAmount
                                            amount={delivery.quote.charge}
                                            direction="debit"
                                        />
                                    </div>
                                    <p className="text-muted-foreground text-xs">
                                        {t('damages.charge_is_the_servers')}
                                    </p>
                                    {!covered && (
                                        <p
                                            role="alert"
                                            className="text-danger text-xs"
                                        >
                                            {t('damages.wallet_too_low')}
                                        </p>
                                    )}
                                </section>
                            )}

                            {hasAddress ? (
                                <FormField
                                    label={t('damages.deliver_to')}
                                    error={errors.address}
                                    required
                                >
                                    {(field) => (
                                        <NativeSelect
                                            {...field}
                                            name="address"
                                            required
                                            defaultValue={
                                                delivery.addresses.find(
                                                    (a) => a.is_default,
                                                )?.id ??
                                                delivery.addresses[0].id
                                            }
                                        >
                                            {delivery.addresses.map(
                                                (address) => (
                                                    <option
                                                        key={address.id}
                                                        value={address.id}
                                                    >
                                                        {address.label}
                                                    </option>
                                                ),
                                            )}
                                        </NativeSelect>
                                    )}
                                </FormField>
                            ) : (
                                <p role="alert" className="text-sm">
                                    {t('damages.no_address')}{' '}
                                    <Link
                                        href={addressBook()}
                                        className="underline underline-offset-4"
                                    >
                                        {t('damages.add_address')}
                                    </Link>
                                </p>
                            )}

                            <label className="flex items-start gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="confirmed"
                                    value="1"
                                    required
                                    className="mt-1"
                                />
                                <span>{t('damages.confirm_payment')}</span>
                            </label>
                            <InputError message={errors.confirmed} />

                            <DialogFooter className="gap-2 sm:gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={onClose}
                                    disabled={processing}
                                >
                                    {t('common.actions.cancel')}
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={processing || !hasAddress}
                                >
                                    {processing && (
                                        <Spinner className="size-3.5" />
                                    )}
                                    {t('damages.pay_and_request')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
