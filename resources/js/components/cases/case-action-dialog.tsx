import { Form } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import FormField from '@/components/forms/form-field';
import MoneyField from '@/components/forms/money-field';
import NativeSelect from '@/components/forms/native-select';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
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
import type { CaseAction, CaseItem, InspectionPanel } from '@/types/cases';

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

type Context = {
    kind: 'return' | 'exchange' | 'damage';
    items: CaseItem[];
    inspection: InspectionPanel;
    couriers?: { code: string; name: string }[];
};

/**
 * One step of a case, confirmed (D35).
 *
 * Before anything is posted the dialog restates, from the server's own figures,
 * **what is about to happen**: the product and quantity, who bears it, the money
 * that will move and in which direction, what happens to the stock, and — when the
 * step needs one — the reason it requires. It then collects only what that step
 * needs, nothing more, and posts to the route the server named.
 *
 * Nothing is added or compared here. The server repeats every check and is the
 * boundary: this only lets a person see what they are agreeing to, and catches
 * an obviously incomplete form before a round trip.
 */
export default function CaseActionDialog({
    action,
    context,
    open,
    onClose,
}: {
    action: CaseAction;
    context: Context;
    open: boolean;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const c = action.confirmation;
    const [blocked, setBlocked] = useState(false);

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{action.label}</DialogTitle>
                    <DialogDescription>
                        {t('cases.dialog.description')}
                    </DialogDescription>
                </DialogHeader>

                <section
                    aria-label={t('cases.dialog.summary')}
                    className="bg-surface-subtle space-y-2 rounded-lg border px-4 py-3 text-sm"
                >
                    <ul className="space-y-0.5">
                        {c.items.map((item) => (
                            <li
                                key={`${item.name}-${item.variant ?? ''}`}
                                className="break-words"
                            >
                                <span className="font-medium">{item.name}</span>
                                {item.variant
                                    ? ` · ${item.variant}`
                                    : ''} ×{' '}
                                {item.quantity}
                            </li>
                        ))}
                    </ul>

                    {c.responsible_label && (
                        <div className="flex justify-between gap-3">
                            <span className="text-muted-foreground">
                                {t('cases.dialog.responsible')}
                            </span>
                            <span className="font-medium">
                                {c.responsible_label}
                            </span>
                        </div>
                    )}

                    {c.amounts.map((row) => (
                        <div
                            key={row.key}
                            className="flex justify-between gap-3"
                        >
                            <span className="text-muted-foreground">
                                {t(`cases.financials.rows.${row.key}`)}
                            </span>
                            <MoneyAmount
                                amount={row.amount}
                                direction={row.direction}
                            />
                        </div>
                    ))}

                    {c.shows_disposition && (
                        <p className="text-muted-foreground text-xs">
                            {t(`cases.dialog.stock.${action.key}`)}
                        </p>
                    )}

                    {c.reason_required && (
                        <p className="text-muted-foreground text-xs">
                            {t('cases.dialog.reason_required')}
                        </p>
                    )}
                </section>

                <Form
                    action={action.url}
                    method="post"
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="grid gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            {Object.entries(action.fields).map(
                                ([name, value]) =>
                                    name === 'party_label' ? null : (
                                        <input
                                            key={name}
                                            type="hidden"
                                            name={name}
                                            value={value}
                                        />
                                    ),
                            )}

                            <ActionFields
                                action={action}
                                context={context}
                                errors={errors}
                                onBlocked={setBlocked}
                            />

                            <InputError message={errors.case} />

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
                                    variant={
                                        action.tone === 'danger'
                                            ? 'destructive'
                                            : 'default'
                                    }
                                    disabled={processing || blocked}
                                >
                                    {processing && (
                                        <Spinner className="size-3.5" />
                                    )}
                                    {t('cases.dialog.confirm', {
                                        action: action.label,
                                    })}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function Reason({
    name = 'reason',
    label,
    minLength,
    errors,
    required = true,
}: {
    name?: string;
    label?: string;
    minLength?: number | null;
    errors: Record<string, string>;
    required?: boolean;
}) {
    const { t } = useTranslation();

    return (
        <FormField
            label={label ?? t('cases.fields.reason')}
            error={errors[name]}
            required={required}
        >
            {(field) => (
                <textarea
                    {...field}
                    name={name}
                    rows={3}
                    required={required}
                    minLength={minLength ?? undefined}
                    maxLength={1000}
                    className={controlClass}
                />
            )}
        </FormField>
    );
}

function ActionFields({
    action,
    context,
    errors,
    onBlocked,
}: {
    action: CaseAction;
    context: Context;
    errors: Record<string, string>;
    onBlocked: (blocked: boolean) => void;
}) {
    const { t } = useTranslation();
    const minimum = action.confirmation.reason_minimum;

    switch (action.key) {
        case 'approve':
        case 'reject':
        case 'reject_inspection':
        case 'change_responsibility':
        case 'record_collection':
            return <Reason minLength={minimum} errors={errors} />;

        case 'reject_refund':
            return <Reason name="note" minLength={minimum} errors={errors} />;

        case 'approve_refund':
            return (
                <Reason
                    name="note"
                    errors={errors}
                    required={false}
                    label={t('cases.fields.note')}
                />
            );

        case 'settle_manually':
            return (
                <Reason
                    name="how"
                    label={t('cases.fields.how_settled')}
                    minLength={minimum}
                    errors={errors}
                />
            );

        case 'receive_parcel':
            return context.kind === 'damage' ? (
                <WarehouseField
                    warehouses={context.inspection.warehouses}
                    errors={errors}
                    required={Boolean(context.inspection.needs_warehouse)}
                />
            ) : (
                <Reason
                    name="note"
                    errors={errors}
                    required={false}
                    label={t('cases.fields.note')}
                />
            );

        case 'settle':
            return <SettlementFields errors={errors} />;

        case 'approve_inspection':
            return (
                <ReturnInspectionFields
                    context={context}
                    errors={errors}
                    onBlocked={onBlocked}
                />
            );

        case 'record_inspection':
            return (
                <DamageInspectionFields
                    context={context}
                    errors={errors}
                    onBlocked={onBlocked}
                />
            );

        case 'assign_liability':
            return <LiabilityFields context={context} errors={errors} />;

        case 'dispatch':
            return (
                <DispatchFields
                    couriers={context.couriers ?? []}
                    errors={errors}
                />
            );

        default:
            // Confirming alone is the whole step: start inspection, finalize, receipt…
            return null;
    }
}

function WarehouseField({
    warehouses,
    errors,
    required,
}: {
    warehouses: { id: string; label: string }[];
    errors: Record<string, string>;
    required: boolean;
}) {
    const { t } = useTranslation();

    return (
        <FormField
            label={t('cases.fields.warehouse')}
            error={errors.warehouse}
            required={required}
        >
            {(field) => (
                <NativeSelect
                    {...field}
                    name="warehouse"
                    required={required}
                    defaultValue={warehouses[0]?.id ?? ''}
                >
                    {!required && (
                        <option value="">
                            {t('cases.fields.no_warehouse')}
                        </option>
                    )}
                    {warehouses.map((warehouse) => (
                        <option key={warehouse.id} value={warehouse.id}>
                            {warehouse.label}
                        </option>
                    ))}
                </NativeSelect>
            )}
        </FormField>
    );
}

function SettlementFields({ errors }: { errors: Record<string, string> }) {
    const { t } = useTranslation();

    return (
        <div className="grid gap-3">
            <FormField
                label={t('cases.fields.adjustment')}
                description={t('cases.fields.adjustment_help')}
                error={errors.adjustment}
            >
                {(field) => <MoneyField {...field} name="adjustment" />}
            </FormField>
            <FormField
                label={t('cases.fields.adjustment_note')}
                error={errors.adjustment_note}
            >
                {(field) => (
                    <textarea
                        {...field}
                        name="adjustment_note"
                        rows={2}
                        maxLength={1000}
                        className={controlClass}
                    />
                )}
            </FormField>
        </div>
    );
}

function ReturnInspectionFields({
    context,
    errors,
    onBlocked,
}: {
    context: Context;
    errors: Record<string, string>;
    onBlocked: (blocked: boolean) => void;
}) {
    const { t } = useTranslation();
    const [counts, setCounts] = useState<Record<string, string>>(() =>
        Object.fromEntries(
            context.items.map((item) => [
                item.id,
                String(item.approved ?? item.requested),
            ]),
        ),
    );

    const invalid = (item: CaseItem) => {
        const value = Number(counts[item.id]);

        return (
            !Number.isInteger(value) ||
            value < 0 ||
            value > (item.approved ?? item.requested)
        );
    };

    const anyInvalid = context.items.some(invalid);
    const none = context.items.every((item) => Number(counts[item.id]) === 0);

    // Tells the dialog whether the form is complete enough to send; the server checks again.
    const blocked = anyInvalid || none;
    useEffect(() => onBlocked(blocked), [blocked, onBlocked]);

    return (
        <div className="grid gap-4">
            <WarehouseField
                warehouses={context.inspection.warehouses}
                errors={errors}
                required
            />

            {context.items.map((item) => (
                <fieldset
                    key={item.id}
                    className="border-border grid gap-2 rounded-lg border p-3"
                >
                    <legend className="px-1 text-sm font-medium">
                        {item.name}
                        {item.variant ? ` · ${item.variant}` : ''}
                    </legend>
                    <FormField
                        label={t('cases.fields.inspected_quantity')}
                        hint={t('cases.fields.up_to', {
                            count: String(item.approved ?? item.requested),
                        })}
                        error={
                            invalid(item)
                                ? t('cases.fields.quantity_invalid')
                                : errors[`lines.${item.id}.quantity`]
                        }
                    >
                        {(field) => (
                            <Input
                                {...field}
                                type="number"
                                inputMode="numeric"
                                min={0}
                                max={item.approved ?? item.requested}
                                name={`lines[${item.id}][quantity]`}
                                value={counts[item.id]}
                                onChange={(event) =>
                                    setCounts((all) => ({
                                        ...all,
                                        [item.id]: event.target.value,
                                    }))
                                }
                                className="w-28"
                            />
                        )}
                    </FormField>
                    <FormField
                        label={t('cases.fields.disposition')}
                        error={errors[`lines.${item.id}.disposition`]}
                    >
                        {(field) => (
                            <NativeSelect
                                {...field}
                                name={`lines[${item.id}][disposition]`}
                                defaultValue="restock"
                            >
                                {context.inspection.dispositions.map(
                                    (option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ),
                                )}
                            </NativeSelect>
                        )}
                    </FormField>
                    <FormField label={t('cases.fields.note')}>
                        {(field) => (
                            <Input
                                {...field}
                                name={`lines[${item.id}][note]`}
                                maxLength={500}
                            />
                        )}
                    </FormField>
                </fieldset>
            ))}

            <FormField
                label={t('cases.fields.inspection_note')}
                error={errors.note}
            >
                {(field) => (
                    <textarea
                        {...field}
                        name="note"
                        rows={2}
                        maxLength={1000}
                        className={controlClass}
                    />
                )}
            </FormField>
            <InputError message={errors.lines} />
        </div>
    );
}

function DamageInspectionFields({
    context,
    errors,
    onBlocked,
}: {
    context: Context;
    errors: Record<string, string>;
    onBlocked: (blocked: boolean) => void;
}) {
    const { t } = useTranslation();
    const total = context.items[0]?.requested ?? 0;
    const options = context.inspection.dispositions;
    const [counts, setCounts] = useState<Record<string, string>>(() =>
        Object.fromEntries(
            options.map((option) => [
                option.value,
                option.value === 'damaged' ? String(total) : '0',
            ]),
        ),
    );

    const assigned = options.reduce(
        (sum, option) => sum + (Number.parseInt(counts[option.value], 10) || 0),
        0,
    );
    const complete = assigned === total;

    useEffect(() => onBlocked(!complete), [complete, onBlocked]);

    return (
        <div className="grid gap-4">
            <fieldset className="grid gap-2">
                <legend className="text-sm font-medium">
                    {t('cases.fields.unit_dispositions')}
                </legend>
                {options.map((option, index) => (
                    <FormField key={option.value} label={option.label}>
                        {(field) => (
                            <div className="flex items-center gap-2">
                                <input
                                    type="hidden"
                                    name={`dispositions[${index}][disposition]`}
                                    value={option.value}
                                />
                                <Input
                                    {...field}
                                    type="number"
                                    inputMode="numeric"
                                    min={0}
                                    max={total}
                                    name={`dispositions[${index}][quantity]`}
                                    value={counts[option.value]}
                                    onChange={(event) =>
                                        setCounts((all) => ({
                                            ...all,
                                            [option.value]: event.target.value,
                                        }))
                                    }
                                    className="w-28"
                                />
                            </div>
                        )}
                    </FormField>
                ))}
                <p
                    role="status"
                    className={
                        complete
                            ? 'text-muted-foreground text-xs'
                            : 'text-danger text-xs'
                    }
                >
                    {t('cases.fields.units_accounted', {
                        assigned: String(assigned),
                        total: String(total),
                    })}
                </p>
                <InputError message={errors.dispositions} />
            </fieldset>

            <FormField
                label={t('cases.fields.cause')}
                error={errors.cause}
                required
            >
                {(field) => (
                    <NativeSelect
                        {...field}
                        name="cause"
                        required
                        defaultValue=""
                    >
                        <option value="" disabled>
                            {t('cases.fields.choose')}
                        </option>
                        {(context.inspection.causes ?? []).map((cause) => (
                            <option key={cause.value} value={cause.value}>
                                {cause.label}
                            </option>
                        ))}
                    </NativeSelect>
                )}
            </FormField>

            <FormField
                label={t('cases.fields.condition')}
                error={errors.condition}
            >
                {(field) => (
                    <NativeSelect {...field} name="condition" defaultValue="">
                        <option value="">
                            {t('cases.fields.as_reported')}
                        </option>
                        {(context.inspection.conditions ?? []).map(
                            (condition) => (
                                <option
                                    key={condition.value}
                                    value={condition.value}
                                >
                                    {condition.label}
                                </option>
                            ),
                        )}
                    </NativeSelect>
                )}
            </FormField>

            {context.inspection.needs_warehouse && (
                <WarehouseField
                    warehouses={context.inspection.warehouses}
                    errors={errors}
                    required
                />
            )}

            <FormField
                label={t('cases.fields.inspection_note')}
                error={errors.note}
            >
                {(field) => (
                    <textarea
                        {...field}
                        name="note"
                        rows={2}
                        maxLength={1000}
                        className={controlClass}
                    />
                )}
            </FormField>
        </div>
    );
}

function LiabilityFields({
    context,
    errors,
}: {
    context: Context;
    errors: Record<string, string>;
}) {
    const { t } = useTranslation();
    const maximum = context.inspection.valuation?.maximum;

    return (
        <div className="grid gap-4">
            <FormField
                label={t('cases.fields.bears_the_loss')}
                error={errors.party}
                required
            >
                {(field) => (
                    <NativeSelect
                        {...field}
                        name="party"
                        required
                        defaultValue="partner"
                    >
                        <option value="partner">
                            {t('cases.parties.partner')}
                        </option>
                        <option value="banij">
                            {t('cases.parties.banij')}
                        </option>
                    </NativeSelect>
                )}
            </FormField>

            <FormField
                label={t('cases.fields.approved_loss')}
                description={
                    maximum
                        ? t('cases.fields.loss_maximum', {
                              amount: maximum.formatted,
                          })
                        : undefined
                }
                error={errors.loss}
                required
            >
                {(field) => (
                    <MoneyField
                        {...field}
                        name="loss"
                        defaultValue={maximum?.amount ?? '0.00'}
                    />
                )}
            </FormField>

            <Reason minLength={10} errors={errors} />

            <FormField
                label={t('cases.fields.write_off_reason')}
                description={t('cases.fields.write_off_help')}
                error={errors.write_off_reason}
            >
                {(field) => (
                    <textarea
                        {...field}
                        name="write_off_reason"
                        rows={2}
                        maxLength={1000}
                        className={controlClass}
                    />
                )}
            </FormField>
        </div>
    );
}

function DispatchFields({
    couriers,
    errors,
}: {
    couriers: { code: string; name: string }[];
    errors: Record<string, string>;
}) {
    const { t } = useTranslation();

    return (
        <div className="grid gap-4">
            <FormField
                label={t('cases.fields.tracking_number')}
                error={errors.tracking_number}
                required
            >
                {(field) => (
                    <Input
                        {...field}
                        name="tracking_number"
                        required
                        maxLength={120}
                    />
                )}
            </FormField>
            <FormField label={t('cases.fields.courier')} error={errors.courier}>
                {(field) => (
                    <NativeSelect {...field} name="courier" defaultValue="">
                        <option value="">{t('cases.fields.no_courier')}</option>
                        {couriers.map((courier) => (
                            <option key={courier.code} value={courier.code}>
                                {courier.name}
                            </option>
                        ))}
                    </NativeSelect>
                )}
            </FormField>
        </div>
    );
}
