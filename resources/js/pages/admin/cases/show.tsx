import { Form, Head, Link } from '@inertiajs/react';
import { AlertTriangle, Paperclip } from 'lucide-react';
import { useState } from 'react';
import CaseActionDialog from '@/components/cases/case-action-dialog';
import CaseTimeline from '@/components/cases/case-timeline';
import LinkedRef from '@/components/cases/linked-ref';
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
import SectionTabs from '@/components/section-tabs';
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
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { index as casesIndex } from '@/routes/admin/cases';
import type { AdminCaseProps, CaseAction, CaseItem } from '@/types/cases';

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

const TABS = [
    'overview',
    'items',
    'evidence',
    'inspection',
    'financials',
    'linked',
    'timeline',
    'accounting',
] as const;

type Tab = (typeof TABS)[number];

/**
 * One case — a return, an exchange or a damage — in one workspace (D35).
 *
 * A compact header says what it is and what it is waiting for; the sections are
 * tabs, so no control has to sit in one long form. **Only the next valid steps are
 * offered**, as the server listed them for this person at this stage — there is no
 * status to pick — and each opens a confirmation that restates, from the server's
 * own figures, the product and quantity, who bears it, the money that will move and
 * what happens to the stock.
 */
export default function AdminCase(props: AdminCaseProps) {
    const { t, locale } = useTranslation();
    const [tab, setTab] = useState<Tab>('overview');
    const [acting, setActing] = useState<CaseAction | null>(null);
    const c = props.case;
    const when = (value: string | null | undefined) =>
        value ? new Date(value).toLocaleString(locale) : '—';

    const context = {
        kind: c.kind,
        items: props.items,
        inspection: props.inspection,
        couriers: props.couriers,
    };

    return (
        <>
            <Head title={c.reference} />

            <PageContainer>
                <PageHeader
                    title={c.reference}
                    description={`${t(`cases.kinds.${c.kind}`)} · ${c.partner}${c.store ? ` · ${c.store}` : ''}`}
                    meta={
                        <StatusPill
                            tone={c.status_tone}
                            label={c.status_label}
                        />
                    }
                    back={{ href: casesIndex(), label: t('cases.back') }}
                />

                {props.warnings.map((warning) => (
                    <div
                        key={warning.key}
                        role="alert"
                        className="border-warning/30 bg-warning-subtle text-warning-foreground flex gap-2 rounded-lg border p-3 text-sm"
                    >
                        <AlertTriangle
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0"
                        />
                        <p>{warning.text}</p>
                    </div>
                ))}

                <section
                    aria-label={t('cases.next_steps')}
                    className="bg-surface-subtle flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4"
                >
                    <div className="min-w-0 space-y-0.5">
                        <div className="text-muted-foreground text-xs font-medium uppercase">
                            {t('cases.next_steps')}
                        </div>
                        <div className="text-sm">
                            {c.next_action
                                ? c.next_action.label
                                : c.waiting_for
                                  ? t('cases.waiting_for', {
                                        step: c.waiting_for,
                                    })
                                  : t('cases.nothing_waiting')}
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {props.actions.map((action) => (
                            <Button
                                key={`${action.key}-${action.fields.decision ?? ''}`}
                                type="button"
                                size="sm"
                                variant={
                                    action.tone === 'danger'
                                        ? 'destructive'
                                        : action.tone === 'primary'
                                          ? 'default'
                                          : 'outline'
                                }
                                onClick={() => setActing(action)}
                            >
                                {action.label}
                            </Button>
                        ))}
                    </div>
                </section>

                <SectionTabs
                    items={TABS.map((key) => ({
                        key,
                        label: t(`cases.sections.${key}`),
                    }))}
                    active={tab}
                    onChange={(key) => setTab(key as Tab)}
                />

                <div
                    role="tabpanel"
                    id={`panel-${tab}`}
                    aria-labelledby={`tab-${tab}`}
                    className="space-y-4"
                >
                    {tab === 'overview' && (
                        <SectionCard title={t('cases.sections.overview')}>
                            <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                                <Fact
                                    label={t('cases.facts.type')}
                                    value={t(`cases.kinds.${c.kind}`)}
                                />
                                <Fact
                                    label={t('cases.facts.status')}
                                    value={c.status_label}
                                />
                                <Fact
                                    label={t('cases.facts.original_order')}
                                    value={<LinkedRef item={c.order} />}
                                />
                                {c.exchange_order && (
                                    <Fact
                                        label={t('cases.facts.exchange_order')}
                                        value={
                                            <LinkedRef
                                                item={c.exchange_order}
                                            />
                                        }
                                    />
                                )}
                                {c.return && (
                                    <Fact
                                        label={t('cases.facts.return')}
                                        value={
                                            <Link
                                                href={c.return.url}
                                                className="font-mono underline-offset-4 hover:underline"
                                            >
                                                {c.return.reference}
                                            </Link>
                                        }
                                    />
                                )}
                                <Fact
                                    label={t('cases.facts.partner')}
                                    value={c.partner}
                                />
                                <Fact
                                    label={t('cases.facts.store')}
                                    value={c.store ?? '—'}
                                />
                                <Fact
                                    label={t('cases.facts.created')}
                                    value={when(c.created_at)}
                                />
                                <Fact
                                    label={t('cases.facts.responsibility')}
                                    value={
                                        c.responsibility_label ??
                                        t('cases.facts.not_decided')
                                    }
                                />
                                <Fact
                                    label={t('cases.facts.initiated_by')}
                                    value={c.initiated_by ?? '—'}
                                />
                                {c.reason_label && (
                                    <Fact
                                        label={t('cases.facts.reason')}
                                        value={c.reason_label}
                                    />
                                )}
                                {c.source_label && (
                                    <Fact
                                        label={t('cases.facts.source')}
                                        value={c.source_label}
                                    />
                                )}
                                {c.cash_on_delivery !== undefined && (
                                    <Fact
                                        label={t('cases.facts.paid')}
                                        value={t(
                                            c.cash_on_delivery
                                                ? 'cases.facts.on_delivery'
                                                : 'cases.facts.online',
                                        )}
                                    />
                                )}
                            </dl>

                            {c.remains_with_customer && (
                                <p className="bg-muted mt-4 rounded-lg p-3 text-sm">
                                    {t('cases.remains_with_customer')}
                                </p>
                            )}

                            {c.description && (
                                <div className="mt-4 space-y-1">
                                    <h3 className="text-sm font-medium">
                                        {t('cases.facts.description')}
                                    </h3>
                                    <p className="text-sm break-words whitespace-pre-line">
                                        {c.description}
                                    </p>
                                </div>
                            )}

                            {props.delivery && (
                                <div className="mt-4 space-y-1">
                                    <h3 className="text-sm font-medium">
                                        {t('cases.facts.delivery_to_partner')}
                                    </h3>
                                    <p className="text-sm">
                                        {props.delivery.status_label}
                                        {props.delivery.tracking_number
                                            ? ` · ${props.delivery.tracking_number}${props.delivery.courier ? ` (${props.delivery.courier})` : ''}`
                                            : ''}
                                    </p>
                                </div>
                            )}
                        </SectionCard>
                    )}

                    {tab === 'items' && (
                        <SectionCard title={t('cases.sections.items')}>
                            <ul className="divide-border divide-y">
                                {props.items.map((item) => (
                                    <ItemRow
                                        key={item.id}
                                        item={item}
                                        kind={c.kind}
                                        openDamageUrl={props.open_damage_url}
                                        conditions={props.inspection.conditions}
                                    />
                                ))}
                            </ul>
                        </SectionCard>
                    )}

                    {tab === 'evidence' && (
                        <SectionCard title={t('cases.sections.evidence')}>
                            <div className="space-y-5">
                                <EvidenceGallery
                                    files={props.evidence}
                                    legacy={props.legacy_evidence}
                                />
                                {props.evidence_purposes.length > 0 && (
                                    <StaffEvidenceForm
                                        url={props.evidence_url}
                                        purposes={props.evidence_purposes}
                                        limits={props.evidence_limits}
                                    />
                                )}
                            </div>
                        </SectionCard>
                    )}

                    {tab === 'inspection' && (
                        <SectionCard title={t('cases.sections.inspection')}>
                            <InspectionSummary props={props} when={when} />
                        </SectionCard>
                    )}

                    {tab === 'financials' && (
                        <SectionCard title={t('cases.sections.financials')}>
                            <FinancialsPanel
                                financials={props.financials}
                                audience="staff"
                            />
                        </SectionCard>
                    )}

                    {tab === 'linked' && (
                        <SectionCard title={t('cases.sections.linked')}>
                            <ul className="space-y-2 text-sm">
                                <li>
                                    {t('cases.facts.original_order')}:{' '}
                                    <LinkedRef item={c.order} />
                                </li>
                                {c.exchange_order && (
                                    <li>
                                        {t('cases.facts.exchange_order')}:{' '}
                                        <LinkedRef item={c.exchange_order} />
                                    </li>
                                )}
                                {c.previous_exchange && (
                                    <li>
                                        {t('cases.facts.previous_exchange')}:{' '}
                                        <LinkedRef item={c.previous_exchange} />
                                    </li>
                                )}
                                {c.next_exchange && (
                                    <li>
                                        {t('cases.facts.next_exchange')}:{' '}
                                        <LinkedRef item={c.next_exchange} />
                                    </li>
                                )}
                                {c.return && (
                                    <li>
                                        {t('cases.facts.return')}:{' '}
                                        <Link
                                            href={c.return.url}
                                            className="font-mono underline-offset-4 hover:underline"
                                        >
                                            {c.return.reference}
                                        </Link>
                                    </li>
                                )}
                                {props.linked.damages.map((damage) => (
                                    <li key={damage.reference}>
                                        {t('cases.kinds.damage')}:{' '}
                                        <Link
                                            href={damage.url}
                                            className="font-mono underline-offset-4 hover:underline"
                                        >
                                            {damage.reference}
                                        </Link>{' '}
                                        ({damage.status_label})
                                    </li>
                                ))}
                            </ul>
                        </SectionCard>
                    )}

                    {tab === 'timeline' && (
                        <SectionCard title={t('cases.sections.timeline')}>
                            <CaseTimeline entries={props.timeline} />
                        </SectionCard>
                    )}

                    {tab === 'accounting' && (
                        <SectionCard title={t('cases.sections.accounting')}>
                            <FinancialsPanel
                                financials={props.financials}
                                audience="staff"
                                section="ledger"
                            />
                        </SectionCard>
                    )}
                </div>
            </PageContainer>

            {acting && (
                <CaseActionDialog
                    action={acting}
                    context={context}
                    open
                    onClose={() => setActing(null)}
                />
            )}
        </>
    );
}

AdminCase.layout = {
    breadcrumbs: [{ title: 'nav.cases', href: casesIndex() }],
};

function Fact({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="min-w-0">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="break-words">{value}</dd>
        </div>
    );
}

function ItemRow({
    item,
    kind,
    openDamageUrl,
    conditions,
}: {
    item: CaseItem;
    kind: 'return' | 'exchange' | 'damage';
    openDamageUrl: string | null;
    conditions?: { value: string; label: string }[];
}) {
    const { t } = useTranslation();
    const [opening, setOpening] = useState(false);

    // A damage can be opened from goods that came back and are held apart, and
    // never from a supplier-supplied item — its stock has no such movement.
    const canOpenDamage =
        kind !== 'damage' &&
        openDamageUrl !== null &&
        item.restored &&
        (item.disposition === 'quarantine' || item.disposition === 'damaged') &&
        !item.supplier_backed;

    return (
        <li className="flex flex-col gap-3 py-4 sm:flex-row sm:items-start">
            {item.image ? (
                <img
                    src={item.image.url}
                    alt={item.image.alt ?? item.name}
                    loading="lazy"
                    className="size-14 shrink-0 rounded-lg border object-cover"
                />
            ) : (
                <LineThumb name={item.name} />
            )}
            <div className="min-w-0 flex-1 space-y-1 text-sm">
                <div className="font-medium break-words">{item.name}</div>
                <div className="text-muted-foreground text-xs">
                    {item.variant ? `${item.variant} · ` : ''}
                    {item.sku}
                </div>
                <div className="text-xs">
                    {kind === 'damage'
                        ? t('cases.items.damaged_quantity', {
                              count: String(item.requested),
                          })
                        : t('cases.items.quantities', {
                              requested: String(item.requested),
                              approved:
                                  item.approved === null
                                      ? '—'
                                      : String(item.approved),
                              received:
                                  item.received === null
                                      ? '—'
                                      : String(item.received),
                          })}
                    {item.condition_label ? ` · ${item.condition_label}` : ''}
                </div>
                {item.disposition_label && (
                    <div className="text-xs">
                        {t('cases.items.disposition')}: {item.disposition_label}
                    </div>
                )}
                {item.note && (
                    <p className="text-xs break-words">{item.note}</p>
                )}
                {item.replaced_with && (
                    <div className="text-xs">
                        {t('cases.items.replaced_with', {
                            name: item.replaced_with.name,
                            count: String(item.replaced_with.quantity),
                        })}
                    </div>
                )}
                {item.supplier_backed && (
                    <p className="text-warning-foreground text-xs">
                        {t('cases.items.supplier_backed')}
                    </p>
                )}
            </div>
            <div className="flex shrink-0 items-start gap-3">
                <MoneyAmount amount={item.unit_price} size="small" />
                {canOpenDamage && openDamageUrl && (
                    <>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => setOpening(true)}
                        >
                            {t('cases.actions.open_damage')}
                        </Button>
                        <OpenDamageDialog
                            open={opening}
                            onClose={() => setOpening(false)}
                            url={openDamageUrl}
                            item={item}
                            conditions={conditions ?? []}
                        />
                    </>
                )}
            </div>
        </li>
    );
}

function OpenDamageDialog({
    open,
    onClose,
    url,
    item,
    conditions,
}: {
    open: boolean;
    onClose: () => void;
    url: string;
    item: CaseItem;
    conditions: { value: string; label: string }[];
}) {
    const { t } = useTranslation();
    const options =
        conditions.length > 0
            ? conditions
            : [{ value: 'other', label: t('cases.fields.other') }];

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('cases.actions.open_damage')}</DialogTitle>
                    <DialogDescription>
                        {t('cases.open_damage_help', { name: item.name })}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    action={url}
                    method="post"
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="grid gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <input type="hidden" name="line" value={item.id} />
                            <FormField
                                label={t('cases.fields.damaged_quantity')}
                                error={errors.quantity}
                                required
                            >
                                {(field) => (
                                    <Input
                                        {...field}
                                        type="number"
                                        inputMode="numeric"
                                        name="quantity"
                                        min={1}
                                        max={item.received ?? item.requested}
                                        defaultValue={
                                            item.received ?? item.requested
                                        }
                                        className="w-28"
                                    />
                                )}
                            </FormField>
                            <FormField
                                label={t('cases.fields.condition')}
                                error={errors.condition}
                                required
                            >
                                {(field) => (
                                    <NativeSelect
                                        {...field}
                                        name="condition"
                                        required
                                        defaultValue={options[0].value}
                                    >
                                        {options.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                )}
                            </FormField>
                            <FormField
                                label={t('cases.fields.description')}
                                error={errors.description}
                                required
                            >
                                {(field) => (
                                    <textarea
                                        {...field}
                                        name="description"
                                        rows={3}
                                        required
                                        minLength={10}
                                        maxLength={2000}
                                        className={controlClass}
                                    />
                                )}
                            </FormField>
                            <DialogFooter className="gap-2 sm:gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={onClose}
                                    disabled={processing}
                                >
                                    {t('common.actions.cancel')}
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing && (
                                        <Spinner className="size-3.5" />
                                    )}
                                    {t('cases.actions.open_damage')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function StaffEvidenceForm({
    url,
    purposes,
    limits,
}: {
    url: string;
    purposes: { value: string; label: string }[];
    limits: { max_bytes: number; max_per_case: number; mime_types: string[] };
}) {
    const { t } = useTranslation();
    const megabytes = Math.max(1, Math.floor(limits.max_bytes / (1024 * 1024)));

    return (
        <Form
            action={url}
            method="post"
            options={{ preserveScroll: true }}
            resetOnSuccess
            className="border-border grid gap-3 rounded-lg border p-3"
        >
            {({ errors, processing, progress }) => (
                <>
                    <h3 className="flex items-center gap-2 text-sm font-medium">
                        <Paperclip aria-hidden="true" className="size-4" />
                        {t('cases.evidence.add_to_case')}
                    </h3>
                    <FormField
                        label={t('cases.evidence.purpose')}
                        error={errors.purpose}
                        required
                    >
                        {(field) => (
                            <NativeSelect
                                {...field}
                                name="purpose"
                                required
                                defaultValue={purposes[0]?.value}
                            >
                                {purposes.map((purpose) => (
                                    <option
                                        key={purpose.value}
                                        value={purpose.value}
                                    >
                                        {purpose.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        )}
                    </FormField>
                    <FormField
                        label={t('cases.evidence.file')}
                        hint={t('cases.evidence.hint', {
                            mb: String(megabytes),
                            count: String(limits.max_per_case),
                        })}
                        error={errors.file}
                        required
                    >
                        {(field) => (
                            <Input
                                {...field}
                                type="file"
                                name="file"
                                required
                                accept={limits.mime_types.join(',')}
                            />
                        )}
                    </FormField>
                    <FormField
                        label={t('cases.evidence.note')}
                        error={errors.note}
                    >
                        {(field) => (
                            <Input {...field} name="note" maxLength={500} />
                        )}
                    </FormField>
                    {progress && (
                        <div
                            role="progressbar"
                            aria-valuemin={0}
                            aria-valuemax={100}
                            aria-valuenow={Math.round(progress.percentage ?? 0)}
                            aria-label={t('cases.evidence.uploading')}
                            className="bg-muted h-1.5 overflow-hidden rounded-full"
                        >
                            <div
                                className="bg-primary h-full"
                                style={{
                                    width: `${progress.percentage ?? 0}%`,
                                }}
                            />
                        </div>
                    )}
                    <InputError message={errors.case} />
                    <div>
                        <Button type="submit" size="sm" disabled={processing}>
                            {processing && <Spinner className="size-3.5" />}
                            {t('cases.evidence.upload')}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function InspectionSummary({
    props,
    when,
}: {
    props: AdminCaseProps;
    when: (value: string | null | undefined) => string;
}) {
    const { t } = useTranslation();
    const i = props.inspection;

    if (!i.recorded && !i.valuation) {
        return (
            <p className="text-muted-foreground text-sm">
                {t('cases.inspection.not_yet')}
            </p>
        );
    }

    return (
        <div className="space-y-4 text-sm">
            <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                <Fact
                    label={t('cases.inspection.recorded_at')}
                    value={when(i.inspected_at)}
                />
                {i.outcome && (
                    <Fact
                        label={t('cases.inspection.outcome')}
                        value={t(`cases.inspection.outcomes.${i.outcome}`)}
                    />
                )}
                {i.cause_label && (
                    <Fact
                        label={t('cases.fields.cause')}
                        value={i.cause_label}
                    />
                )}
                {i.note && (
                    <Fact
                        label={t('cases.fields.inspection_note')}
                        value={i.note}
                    />
                )}
            </dl>

            {i.recorded_dispositions && i.recorded_dispositions.length > 0 && (
                <div>
                    <h3 className="font-medium">
                        {t('cases.fields.unit_dispositions')}
                    </h3>
                    <ul className="text-muted-foreground list-disc pl-5">
                        {i.recorded_dispositions.map((row) => (
                            <li key={row.label}>
                                {row.label}: {row.quantity}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {i.valuation && (
                <dl className="bg-surface-subtle grid gap-2 rounded-lg border p-3 sm:grid-cols-2">
                    <Fact
                        label={t('cases.inspection.valuation_basis')}
                        value={i.valuation.basis_label}
                    />
                    <Fact
                        label={t('cases.inspection.maximum_loss')}
                        value={<MoneyAmount amount={i.valuation.maximum} />}
                    />
                    {i.valuation.approved && (
                        <Fact
                            label={t('cases.inspection.approved_loss')}
                            value={
                                <MoneyAmount amount={i.valuation.approved} />
                            }
                        />
                    )}
                    {i.valuation.write_off_reason && (
                        <Fact
                            label={t('cases.fields.write_off_reason')}
                            value={i.valuation.write_off_reason}
                        />
                    )}
                </dl>
            )}

            {props.items.some((item) => item.disposition_label) && (
                <ul className="divide-border divide-y">
                    {props.items.map((item) => (
                        <li
                            key={item.id}
                            className="flex flex-wrap justify-between gap-2 py-2"
                        >
                            <span className="break-words">{item.name}</span>
                            <span className="text-muted-foreground text-xs">
                                {item.disposition_label ?? '—'}
                                {item.received !== null
                                    ? ` · ${t('cases.items.received_count', { count: String(item.received) })}`
                                    : ''}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
