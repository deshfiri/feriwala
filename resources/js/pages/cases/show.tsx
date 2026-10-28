import { Form, Head } from '@inertiajs/react';
import CaseTimeline from '@/components/cases/case-timeline';
import EvidenceGallery from '@/components/cases/evidence-gallery';
import FinancialsPanel from '@/components/cases/financials-panel';
import LinkedRef from '@/components/cases/linked-ref';
import InputError from '@/components/input-error';
import LineThumb from '@/components/line-thumb';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { cancel as cancelRoute, index } from '@/routes/cases';
import type { PartnerCaseDetail } from '@/types/cases';

/**
 * One return or exchange, for the Partner it belongs to (D35).
 *
 * The request and its items, the evidence, the charges and the refund, the linked
 * original and exchange orders, and a plain timeline — and at the top, what has to
 * happen next and whether that is the Partner's to do. The money shown is theirs
 * alone: where Banij bears a cost it says it was covered, with no figure of Banij's
 * accounting, and there is no staff name or note anywhere.
 */
export default function PartnerCase({
    case: c,
    items,
    evidence,
    legacy_evidence,
    financials,
    refund,
    timeline,
}: PartnerCaseDetail) {
    const { t, locale } = useTranslation();
    const when = (value: string) => new Date(value).toLocaleString(locale);

    return (
        <>
            <Head title={c.reference} />

            <PageContainer>
                <PageHeader
                    title={c.reference}
                    description={`${c.kind_label} · ${c.order.reference}`}
                    meta={
                        <StatusPill
                            tone={c.status_tone}
                            label={c.status_label}
                        />
                    }
                    back={{ href: index(), label: t('cases.partner.back') }}
                    actions={
                        c.can_cancel ? (
                            <Form
                                {...cancelRoute.form(c.id)}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            {t('cases.partner.withdraw')}
                                        </Button>
                                        <InputError message={errors.case} />
                                    </>
                                )}
                            </Form>
                        ) : undefined
                    }
                />

                <section
                    aria-label={t('cases.partner.next_step')}
                    className={`rounded-lg border p-4 text-sm ${c.next_step.action_needed ? 'border-warning/40 bg-warning-subtle' : 'bg-surface-subtle'}`}
                >
                    <div className="text-muted-foreground text-xs font-medium uppercase">
                        {t('cases.partner.next_step')}
                    </div>
                    <p className="mt-0.5 font-medium">{c.next_step.label}</p>
                </section>

                <div className="grid gap-4 lg:grid-cols-[2fr_1fr]">
                    <div className="space-y-4">
                        <SectionCard title={t('cases.partner.request')}>
                            <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                                <div>
                                    <dt className="text-muted-foreground text-xs">
                                        {t('cases.facts.type')}
                                    </dt>
                                    <dd>{c.kind_label}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">
                                        {t('cases.facts.reason')}
                                    </dt>
                                    <dd>{c.reason_label}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">
                                        {t('cases.facts.created')}
                                    </dt>
                                    <dd>{when(c.created_at)}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">
                                        {t('cases.facts.responsibility')}
                                    </dt>
                                    <dd>
                                        {c.responsibility_label ??
                                            t('cases.partner.not_decided')}
                                    </dd>
                                </div>
                            </dl>
                            {c.description && (
                                <p className="mt-3 text-sm break-words whitespace-pre-line">
                                    {c.description}
                                </p>
                            )}
                        </SectionCard>

                        <SectionCard title={t('cases.sections.items')}>
                            <ul className="divide-border divide-y">
                                {items.map((item) => (
                                    <li
                                        key={item.id}
                                        className="flex items-start gap-3 py-3 text-sm"
                                    >
                                        {item.image ? (
                                            <img
                                                src={item.image.url}
                                                alt={
                                                    item.image.alt ?? item.name
                                                }
                                                loading="lazy"
                                                className="size-12 shrink-0 rounded-lg border object-cover"
                                            />
                                        ) : (
                                            <LineThumb name={item.name} />
                                        )}
                                        <div className="min-w-0 flex-1">
                                            <div className="font-medium break-words">
                                                {item.name}
                                            </div>
                                            <div className="text-muted-foreground text-xs">
                                                {item.variant
                                                    ? `${item.variant} · `
                                                    : ''}
                                                {item.sku}
                                            </div>
                                            <div className="text-xs">
                                                {t('cases.items.quantities', {
                                                    requested: String(
                                                        item.requested,
                                                    ),
                                                    approved:
                                                        item.approved === null
                                                            ? '—'
                                                            : String(
                                                                  item.approved,
                                                              ),
                                                    received:
                                                        item.received === null
                                                            ? '—'
                                                            : String(
                                                                  item.received,
                                                              ),
                                                })}
                                            </div>
                                            {item.replaced_with && (
                                                <div className="text-xs">
                                                    {t(
                                                        'cases.items.replaced_with',
                                                        {
                                                            name: item
                                                                .replaced_with
                                                                .name,
                                                            count: String(
                                                                item
                                                                    .replaced_with
                                                                    .quantity,
                                                            ),
                                                        },
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </SectionCard>

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
                        <SectionCard title={t('cases.partner.linked_orders')}>
                            <ul className="space-y-2 text-sm">
                                <li>
                                    {t('cases.facts.original_order')}:{' '}
                                    <LinkedRef item={c.links.order} />
                                </li>
                                {c.links.exchange_order && (
                                    <li>
                                        {t('cases.facts.exchange_order')}:{' '}
                                        <LinkedRef
                                            item={c.links.exchange_order}
                                        />
                                    </li>
                                )}
                                {c.links.previous_exchange && (
                                    <li>
                                        {t('cases.facts.previous_exchange')}:{' '}
                                        <LinkedRef
                                            item={c.links.previous_exchange}
                                        />
                                    </li>
                                )}
                                {c.links.next_exchange && (
                                    <li>
                                        {t('cases.facts.next_exchange')}:{' '}
                                        <LinkedRef
                                            item={c.links.next_exchange}
                                        />
                                    </li>
                                )}
                            </ul>
                        </SectionCard>

                        <SectionCard title={t('cases.partner.charges')}>
                            <FinancialsPanel
                                financials={financials}
                                audience="partner"
                            />
                        </SectionCard>

                        <SectionCard title={t('cases.partner.refund')}>
                            <div className="space-y-1 text-sm">
                                <div className="flex items-center justify-between gap-3">
                                    <span className="text-muted-foreground">
                                        {refund.state_label}
                                    </span>
                                    {refund.amount && (
                                        <MoneyAmount
                                            amount={refund.amount}
                                            direction="credit"
                                        />
                                    )}
                                </div>
                                {refund.refunded_at && (
                                    <p className="text-muted-foreground text-xs">
                                        {when(refund.refunded_at)}
                                    </p>
                                )}
                                {refund.is_a_matter_for_you && (
                                    <p className="text-muted-foreground text-xs">
                                        {t('cases.partner.refund_note')}
                                    </p>
                                )}
                            </div>
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
        </>
    );
}

PartnerCase.layout = {
    breadcrumbs: [{ title: 'nav.returns_exchanges', href: index() }],
};
