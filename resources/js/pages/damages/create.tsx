import { Form, Head, Link } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { useState } from 'react';
import EvidenceDraftUploader from '@/components/cases/evidence-draft-uploader';
import FormField from '@/components/forms/form-field';
import NativeSelect from '@/components/forms/native-select';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/damages';
import { store as storeRoute } from '@/routes/damages';
import {
    destroy as discardEvidence,
    store as uploadEvidence,
} from '@/routes/cases/evidence';
import type { EvidenceFile } from '@/types/cases';

type Line = {
    id: string;
    sku: string;
    name: string;
    variant: string | null;
    sold: number;
    reported: number;
    reportable: number;
    supplier_backed: boolean;
};

type Props = {
    order: { id: string; reference: string; url: string };
    eligibility: {
        eligible: boolean;
        refusal: string | null;
        window_closes_at: string | null;
        lines: Line[];
    };
    conditions: { value: string; label: string }[];
    minimum_description: number;
    drafts: EvidenceFile[];
    evidence_limits: {
        max_bytes: number;
        max_per_case: number;
        mime_types: string[];
    };
    can_report: boolean;
};

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Report a damaged item from one delivered Store Order (D35).
 *
 * Only what can still be reported is offered: a line with units not already
 * reported, on an order that was delivered, inside the reporting window. A line
 * supplied by a Supplier is listed with its reason and no way to report it, because
 * that stock movement is not supported yet. The Partner says what is wrong, how
 * many units, and whether the item stays with the customer or comes back; they
 * never say who is responsible — staff decide that after seeing the goods — and
 * send no figure, because the loss is valued on the server.
 */
export default function ReportDamage({
    order,
    eligibility,
    conditions,
    minimum_description,
    drafts,
    evidence_limits,
    can_report,
}: Props) {
    const { t, locale } = useTranslation();
    const reportable = eligibility.lines.filter(
        (line) => line.reportable > 0 && !line.supplier_backed,
    );
    const [item, setItem] = useState(reportable[0]?.id ?? '');
    const [quantity, setQuantity] = useState('1');
    const [location, setLocation] = useState('send_back');
    const [description, setDescription] = useState('');
    // One key per form opened: a double submit is the same report, once.
    const [key] = useState(() => crypto.randomUUID());

    const selected = reportable.find((line) => line.id === item);
    const described = description.trim().length >= minimum_description;

    if (!can_report) {
        return (
            <>
                <Head title={t('damages.report.title')} />
                <PageContainer>
                    <PageHeader
                        title={t('damages.report.title')}
                        description={order.reference}
                        back={{
                            href: order.url,
                            label: t('cases.create.back_to_order'),
                        }}
                    />
                    <SectionCard>
                        <div className="flex gap-3 text-sm" role="alert">
                            <AlertTriangle
                                aria-hidden="true"
                                className="text-warning mt-0.5 size-4 shrink-0"
                            />
                            <div className="space-y-2">
                                <p className="font-medium">
                                    {t('damages.report.cannot_report')}
                                </p>
                                <p className="text-muted-foreground">
                                    {eligibility.eligible
                                        ? t('cases.create.cannot_manage')
                                        : t(
                                              `damages.report.refusals.${eligibility.refusal ?? 'order_not_delivered'}`,
                                          )}
                                </p>
                                {eligibility.lines.some(
                                    (line) => line.supplier_backed,
                                ) && (
                                    <p className="text-muted-foreground">
                                        {t('damages.report.supplier_backed')}
                                    </p>
                                )}
                            </div>
                        </div>
                    </SectionCard>
                </PageContainer>
            </>
        );
    }

    return (
        <>
            <Head title={t('damages.report.title')} />

            <PageContainer>
                <PageHeader
                    title={t('damages.report.title')}
                    description={t('damages.report.description', {
                        order: order.reference,
                    })}
                    back={{
                        href: order.url,
                        label: t('cases.create.back_to_order'),
                    }}
                />

                <Form
                    {...storeRoute.form(order.id)}
                    options={{ preserveScroll: true }}
                    className="max-w-3xl space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="idempotency_key"
                                value={key}
                            />

                            <SectionCard title={t('damages.report.what')}>
                                <div className="grid gap-4">
                                    {eligibility.window_closes_at && (
                                        <p className="text-muted-foreground text-xs">
                                            {t('damages.report.window', {
                                                time: new Date(
                                                    eligibility.window_closes_at,
                                                ).toLocaleString(locale),
                                            })}
                                        </p>
                                    )}

                                    <FormField
                                        label={t('damages.report.item')}
                                        error={errors.item}
                                        required
                                    >
                                        {(field) => (
                                            <NativeSelect
                                                {...field}
                                                name="item"
                                                required
                                                value={item}
                                                onChange={(event) => {
                                                    setItem(event.target.value);
                                                    setQuantity('1');
                                                }}
                                            >
                                                {reportable.map((line) => (
                                                    <option
                                                        key={line.id}
                                                        value={line.id}
                                                    >
                                                        {line.name}
                                                        {line.variant
                                                            ? ` · ${line.variant}`
                                                            : ''}{' '}
                                                        ({line.sku})
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t('damages.report.quantity')}
                                        hint={t('damages.report.up_to', {
                                            count: String(
                                                selected?.reportable ?? 0,
                                            ),
                                        })}
                                        error={errors.quantity}
                                        required
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                type="number"
                                                inputMode="numeric"
                                                min={1}
                                                max={selected?.reportable ?? 1}
                                                name="quantity"
                                                required
                                                value={quantity}
                                                onChange={(event) =>
                                                    setQuantity(
                                                        event.target.value,
                                                    )
                                                }
                                                className="w-28"
                                            />
                                        )}
                                    </FormField>

                                    {eligibility.lines
                                        .filter((line) => line.supplier_backed)
                                        .map((line) => (
                                            <p
                                                key={line.id}
                                                className="bg-muted rounded-lg p-3 text-xs"
                                            >
                                                {line.name}:{' '}
                                                {t(
                                                    'damages.report.supplier_backed',
                                                )}
                                            </p>
                                        ))}
                                </div>
                            </SectionCard>

                            <SectionCard title={t('damages.report.why')}>
                                <div className="grid gap-4">
                                    <FormField
                                        label={t('damages.report.condition')}
                                        error={errors.condition}
                                        required
                                    >
                                        {(field) => (
                                            <NativeSelect
                                                {...field}
                                                name="condition"
                                                required
                                                defaultValue=""
                                            >
                                                <option value="" disabled>
                                                    {t(
                                                        'damages.report.choose_condition',
                                                    )}
                                                </option>
                                                {conditions.map((condition) => (
                                                    <option
                                                        key={condition.value}
                                                        value={condition.value}
                                                    >
                                                        {condition.label}
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t('damages.report.describe')}
                                        hint={t(
                                            'damages.report.describe_help',
                                            {
                                                count: String(
                                                    minimum_description,
                                                ),
                                            },
                                        )}
                                        error={errors.description}
                                        required
                                    >
                                        {(field) => (
                                            <textarea
                                                {...field}
                                                name="description"
                                                rows={4}
                                                required
                                                minLength={minimum_description}
                                                maxLength={2000}
                                                value={description}
                                                onChange={(event) =>
                                                    setDescription(
                                                        event.target.value,
                                                    )
                                                }
                                                className={controlClass}
                                            />
                                        )}
                                    </FormField>

                                    <fieldset className="space-y-2">
                                        <legend className="text-sm font-medium">
                                            {t('damages.report.where')}
                                        </legend>
                                        {(
                                            [
                                                'send_back',
                                                'stays_with_customer',
                                            ] as const
                                        ).map((option) => (
                                            <label
                                                key={option}
                                                className="flex cursor-pointer gap-3 rounded-lg border p-3 text-sm"
                                            >
                                                <input
                                                    type="radio"
                                                    name="item_location"
                                                    value={option}
                                                    checked={
                                                        location === option
                                                    }
                                                    onChange={() =>
                                                        setLocation(option)
                                                    }
                                                    className="mt-1"
                                                />
                                                <span>
                                                    <span className="block font-medium">
                                                        {t(
                                                            `damages.report.locations.${option}`,
                                                        )}
                                                    </span>
                                                    <span className="text-muted-foreground block text-xs">
                                                        {t(
                                                            `damages.report.locations_help.${option}`,
                                                        )}
                                                    </span>
                                                </span>
                                            </label>
                                        ))}
                                        <InputError
                                            message={errors.item_location}
                                        />
                                    </fieldset>
                                </div>
                            </SectionCard>

                            <SectionCard
                                title={t('cases.create.evidence')}
                                description={t('cases.create.evidence_help')}
                            >
                                <EvidenceDraftUploader
                                    orderId={order.id}
                                    uploadUrl={uploadEvidence().url}
                                    deleteUrl={(id) => discardEvidence(id).url}
                                    limits={evidence_limits}
                                    initial={drafts}
                                    error={errors.evidence}
                                />
                            </SectionCard>

                            <SectionCard title={t('cases.create.confirm')}>
                                <div className="space-y-3 text-sm">
                                    <p>{t('damages.report.confirm_text')}</p>
                                    <label className="flex items-start gap-2">
                                        <input
                                            type="checkbox"
                                            name="confirmed"
                                            value="1"
                                            required
                                            className="mt-1"
                                        />
                                        <span>
                                            {t('cases.create.confirm_label')}
                                        </span>
                                    </label>
                                    <InputError message={errors.confirmed} />
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            type="submit"
                                            disabled={
                                                processing ||
                                                !selected ||
                                                !described
                                            }
                                        >
                                            {processing && <Spinner />}
                                            {t('damages.report.submit')}
                                        </Button>
                                        <Button asChild variant="ghost">
                                            <Link href={index()}>
                                                {t('common.actions.cancel')}
                                            </Link>
                                        </Button>
                                    </div>
                                </div>
                            </SectionCard>
                        </>
                    )}
                </Form>
            </PageContainer>
        </>
    );
}

ReportDamage.layout = {
    breadcrumbs: [{ title: 'nav.my_damages', href: index() }],
};
