import { useForm } from '@inertiajs/react';
import { Check, Pencil, X } from 'lucide-react';
import { useRef, useState, type FormEvent, type KeyboardEvent } from 'react';
import OrderRevisionController from '@/actions/App/Http/Controllers/Admin/OrderRevisionController';
import SectionCard from '@/components/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';

type Fields = Record<string, string | null>;

export type OrderRevisionPanel = {
    number: number;
    blocker: string | null;
    blocker_message: string | null;
    can: { edit_customer: boolean; edit_address: boolean };
    form: {
        customer: Fields | null;
        shipping_address: Fields | null;
        billing_address: Fields | null;
        customer_note: string | null;
    };
    history: OrderRevisionEntry[];
};

export type OrderRevisionEntry = {
    id: string;
    number: number;
    status_label: string;
    applied_at: string | null;
    actor: string | null;
    reason: string;
    internal_note: string | null;
    sections: string[];
    payment_consequence: string;
    courier_consequence: string;
    changes: { field: string; before: string | null; after: string | null }[];
    is_current: boolean;
};

type Group = 'customer' | 'shipping_address' | 'billing_address' | 'note';

const newKey = (): string =>
    typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(36).slice(2)}`;

/**
 * Who the order is for and where it goes, each field editable in place (D38).
 *
 * A pencil beside a value turns that one value into an input right where it
 * is, with a short reason underneath — every change is kept as a revision and
 * says why. Saving sends only that field; the server checks the permission,
 * the order's state and that nobody changed the order meanwhile, and the page
 * shows the order as it now is. Only the fields the order actually keeps are
 * listed, and a field the person may not change has no pencil.
 */
export default function EditableOrderDetails({
    orderId,
    revision,
}: {
    orderId: string;
    revision: OrderRevisionPanel;
}) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState<string | null>(null);
    const open = revision.blocker === null;

    const rows = (
        group: Exclude<Group, 'note'>,
        values: Fields | null,
        editable: boolean,
    ) =>
        Object.entries(values ?? {}).map(([field, value]) => (
            <InlineField
                key={`${group}.${field}`}
                id={`${group}.${field}`}
                group={group}
                field={field}
                label={t(`orders.revision.fields.${field}`)}
                value={value}
                editable={open && editable}
                editing={editing === `${group}.${field}`}
                onEdit={setEditing}
                orderId={orderId}
                baseRevision={revision.number}
            />
        ));

    const section = (title: string, children: ReturnType<typeof rows>) =>
        children.length === 0 ? null : (
            <div className="space-y-1">
                <h3 className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                    {title}
                </h3>
                <dl className="divide-y">{children}</dl>
            </div>
        );

    return (
        <SectionCard title={t('orders.customer_card.title')}>
            <div className="space-y-5">
                {section(
                    t('orders.revision.customer'),
                    rows(
                        'customer',
                        revision.form.customer,
                        revision.can.edit_customer,
                    ),
                )}
                {section(
                    t('orders.revision.shipping_address'),
                    rows(
                        'shipping_address',
                        revision.form.shipping_address,
                        revision.can.edit_address,
                    ),
                )}
                {section(
                    t('orders.revision.billing_address'),
                    rows(
                        'billing_address',
                        revision.form.billing_address,
                        revision.can.edit_address,
                    ),
                )}
                {section(t('orders.revision.customer_note'), [
                    <InlineField
                        key="note"
                        id="note"
                        group="note"
                        field="customer_note"
                        label={t('orders.revision.customer_note')}
                        value={revision.form.customer_note}
                        editable={open && revision.can.edit_customer}
                        editing={editing === 'note'}
                        onEdit={setEditing}
                        orderId={orderId}
                        baseRevision={revision.number}
                    />,
                ])}
            </div>
        </SectionCard>
    );
}

function InlineField({
    id,
    group,
    field,
    label,
    value,
    editable,
    editing,
    onEdit,
    orderId,
    baseRevision,
}: {
    id: string;
    group: Group;
    field: string;
    label: string;
    value: string | null;
    editable: boolean;
    editing: boolean;
    onEdit: (id: string | null) => void;
    orderId: string;
    baseRevision: number;
}) {
    const { t } = useTranslation();
    const submitting = useRef(false);
    const form = useForm({
        value: value ?? '',
        reason: '',
        idempotency_key: newKey(),
    });
    const errors = form.errors as Record<string, string>;
    const fieldError =
        errors[group === 'note' ? 'customer_note' : `${group}.${field}`] ??
        errors.revision;

    const start = () => {
        form.setData({
            value: value ?? '',
            reason: '',
            idempotency_key: newKey(),
        });
        form.clearErrors();
        onEdit(id);
    };

    const stop = () => {
        form.clearErrors();
        onEdit(null);
    };

    const save = (event?: FormEvent) => {
        event?.preventDefault();

        if (submitting.current || form.processing) {
            return;
        }

        submitting.current = true;

        form.transform((data) => ({
            ...(group === 'note'
                ? { customer_note: data.value }
                : { [group]: { [field]: data.value } }),
            reason: data.reason.trim(),
            base_revision: baseRevision,
            idempotency_key: data.idempotency_key,
        }));

        form.submit(OrderRevisionController.details(orderId), {
            preserveScroll: true,
            onSuccess: () => onEdit(null),
            onFinish: () => {
                submitting.current = false;
            },
        });
    };

    const keys = (event: KeyboardEvent) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            stop();
        }
    };

    if (!editing) {
        return (
            <div className="flex items-start justify-between gap-3 py-2">
                <div className="min-w-0">
                    <dt className="text-muted-foreground text-xs">{label}</dt>
                    <dd className="text-sm break-words">
                        {value && value.trim() !== '' ? (
                            value
                        ) : (
                            <span className="text-muted-foreground">
                                {t('orders.customer_card.not_given')}
                            </span>
                        )}
                    </dd>
                </div>
                {editable && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-8 shrink-0"
                        onClick={start}
                        aria-label={t('orders.revision.edit_field', {
                            field: label,
                        })}
                        title={t('orders.revision.edit_field', {
                            field: label,
                        })}
                    >
                        <Pencil className="size-4" aria-hidden="true" />
                    </Button>
                )}
            </div>
        );
    }

    return (
        <div className="space-y-2 py-2">
            <dt className="text-muted-foreground text-xs">
                <label htmlFor={`${id}-value`}>{label}</label>
            </dt>
            <dd>
                <form onSubmit={save} onKeyDown={keys} className="space-y-2">
                    <div className="flex items-center gap-2">
                        <Input
                            id={`${id}-value`}
                            value={form.data.value}
                            onChange={(event) =>
                                form.setData('value', event.target.value)
                            }
                            disabled={form.processing}
                            aria-invalid={fieldError ? true : undefined}
                            autoFocus
                            autoComplete="off"
                        />
                        <Button
                            type="submit"
                            size="icon"
                            className="size-9 shrink-0"
                            disabled={
                                form.processing ||
                                form.data.reason.trim().length < 10
                            }
                            aria-label={t('orders.revision.save')}
                            title={t('orders.revision.save')}
                        >
                            {form.processing ? (
                                <Spinner />
                            ) : (
                                <Check className="size-4" aria-hidden="true" />
                            )}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            className="size-9 shrink-0"
                            onClick={stop}
                            disabled={form.processing}
                            aria-label={t('orders.revision.cancel')}
                            title={t('orders.revision.cancel')}
                        >
                            <X className="size-4" aria-hidden="true" />
                        </Button>
                    </div>
                    <Input
                        value={form.data.reason}
                        onChange={(event) =>
                            form.setData('reason', event.target.value)
                        }
                        placeholder={t('orders.revision.reason_placeholder')}
                        aria-label={t('orders.revision.reason')}
                        disabled={form.processing}
                        maxLength={1000}
                        aria-invalid={errors.reason ? true : undefined}
                        className="text-xs"
                    />
                    {(fieldError || errors.reason) && (
                        <p className="text-danger text-xs" role="alert">
                            {fieldError ?? errors.reason}
                        </p>
                    )}
                </form>
            </dd>
        </div>
    );
}
