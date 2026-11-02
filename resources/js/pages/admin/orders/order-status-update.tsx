import { Link, useForm } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';
import FormField from '@/components/forms/form-field';
import TextArea from '@/components/forms/text-area';
import Notice from '@/components/notice';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import {
    AXES,
    AXIS_LABEL_KEY,
    AXIS_ROUTE,
    NEXT_AXIS,
    REASON_REQUIRED,
    STATUS_GROUP,
    usualNextStep,
    type LifecycleAxis,
    type OrderLifecycle,
} from '@/pages/admin/orders/order-lifecycle';
import type { OrderShipment } from '@/pages/admin/orders/shipments-panel';
import { show as shipmentShow } from '@/routes/admin/shipments';
import { show as storeOrderShow } from '@/routes/admin/store-orders';

/**
 * Changing an order's status, by hand, on the track that changed.
 *
 * One form, no dialogs: pick the track, pick where it moves to, say what that
 * will do (the page tells you before you save, not after), add a note where one
 * is required, save. The moves offered are exactly the ones the server derived
 * from the state machine, and a refusal from the server is shown here, beside
 * the form, rather than lost.
 *
 * A Store Order is the exception: it moves through its own named steps on its
 * own page, so this says so and links there instead of offering moves the
 * server would refuse.
 */
export default function OrderStatusUpdate({
    orderId,
    lifecycle,
    isStoreOrder,
    shipments,
    canManageShipments,
}: {
    orderId: string;
    lifecycle: OrderLifecycle;
    isStoreOrder: boolean;
    shipments: OrderShipment[];
    canManageShipments: boolean;
}) {
    const { t } = useTranslation();
    // Start where the work is: the first track that has something to do and is
    // not waiting on an earlier one.
    const [axis, setAxis] = useState<LifecycleAxis>(
        AXES.find(
            (candidate) =>
                lifecycle[candidate].can_manage &&
                lifecycle[candidate].locked_reason === null &&
                lifecycle[candidate].available_actions.length > 0,
        ) ?? 'fulfillment',
    );
    const [savedTrack, setSavedTrack] = useState<LifecycleAxis | null>(null);
    const form = useForm({ status: '', reason: '' });
    const submitting = useRef(false);

    const summary = lifecycle[axis];
    const group = STATUS_GROUP[axis];
    // What was chosen, only while the server still offers it: a page that has
    // since refreshed may no longer allow the move that was picked.
    const target = summary.available_actions.includes(form.data.status)
        ? form.data.status
        : '';
    const needsNote = REASON_REQUIRED[axis].includes(target);
    const noteMissing = needsNote && form.data.reason.trim() === '';
    const usual = usualNextStep(axis, summary);
    const errors = form.errors as Record<string, string>;

    const chooseAxis = (next: LifecycleAxis) => {
        if (form.processing || next === axis) {
            return;
        }

        form.reset();
        form.clearErrors();
        setSavedTrack(null);
        setAxis(next);
    };

    const choose = (status: string) => {
        form.setData('status', status);
        form.clearErrors();
        setSavedTrack(null);
    };

    // After a step is saved, the next one may have just become possible.
    const following = NEXT_AXIS[axis];
    const canContinue =
        savedTrack === axis &&
        following !== undefined &&
        lifecycle[following].can_manage &&
        lifecycle[following].locked_reason === null &&
        lifecycle[following].available_actions.length > 0;

    const save = (event: FormEvent) => {
        event.preventDefault();

        // `processing` only turns true on the next render, so a burst of clicks
        // in one tick would all pass it; this is set the moment one goes.
        if (
            submitting.current ||
            form.processing ||
            target === '' ||
            noteMissing
        ) {
            return;
        }

        submitting.current = true;

        form.transform((data) => ({
            status: target,
            ...(data.reason.trim() === ''
                ? {}
                : { reason: data.reason.trim() }),
        }));

        form.submit(AXIS_ROUTE[axis](orderId), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setSavedTrack(axis);
            },
            onFinish: () => {
                submitting.current = false;
            },
        });
    };

    return (
        <SectionCard
            title={t('orders.admin.status_update.title')}
            description={t('orders.admin.status_update.description')}
        >
            {isStoreOrder ? (
                <Notice
                    tone="info"
                    role="note"
                    inset
                    title={t('orders.admin.status_update.store_order_title')}
                    action={
                        <Button asChild size="sm" variant="outline">
                            <Link href={storeOrderShow(orderId)}>
                                {t(
                                    'orders.admin.status_update.store_order_link',
                                )}
                            </Link>
                        </Button>
                    }
                >
                    <p>{t('orders.admin.status_update.store_order_body')}</p>
                </Notice>
            ) : (
                <div className="space-y-5">
                    <div
                        role="group"
                        aria-label={t('orders.admin.status_update.track')}
                        className="grid grid-cols-[repeat(auto-fit,minmax(9.5rem,1fr))] gap-2"
                    >
                        {AXES.map((candidate, position) => (
                            <button
                                key={candidate}
                                type="button"
                                aria-pressed={candidate === axis}
                                disabled={form.processing}
                                onClick={() => chooseAxis(candidate)}
                                className={cn(
                                    'focus-visible:ring-ring flex flex-col items-start gap-1 rounded-lg border px-3 py-2 text-start focus-visible:ring-2 focus-visible:outline-none disabled:opacity-60',
                                    candidate === axis
                                        ? 'border-primary bg-surface-subtle'
                                        : 'hover:bg-surface-subtle',
                                )}
                            >
                                <span className="flex w-full flex-wrap items-center justify-between gap-x-2 gap-y-0.5 text-sm font-medium">
                                    <span>
                                        {position + 1}.{' '}
                                        {t(AXIS_LABEL_KEY[candidate])}
                                    </span>
                                    {lifecycle[candidate].locked_reason && (
                                        <span className="text-muted-foreground flex items-center gap-1 text-xs font-normal">
                                            <Lock
                                                className="size-3"
                                                aria-hidden="true"
                                            />
                                            {t(
                                                'orders.admin.status_update.locked_tag',
                                            )}
                                        </span>
                                    )}
                                </span>
                                <span className="text-muted-foreground text-xs">
                                    {lifecycle[candidate].status_label}
                                </span>
                            </button>
                        ))}
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-muted-foreground text-sm">
                            {t('orders.admin.status_update.current')}
                        </span>
                        <StatusPill
                            tone={summary.status_tone}
                            label={summary.status_label}
                        />
                    </div>
                    <p className="text-muted-foreground text-sm">
                        {t(`orders.admin.status_update.axis.${axis}`)}
                    </p>

                    {(axis === 'courier' || axis === 'delivery') && (
                        <CourierContext
                            shipments={shipments}
                            canManageShipments={canManageShipments}
                        />
                    )}

                    {summary.locked_reason && (
                        <Notice
                            tone="warning"
                            role="note"
                            inset
                            title={t(
                                'orders.admin.status_update.locked_title',
                                { track: t(AXIS_LABEL_KEY[axis]) },
                            )}
                            action={
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() => chooseAxis('fulfillment')}
                                >
                                    {t('orders.admin.status_update.go_to', {
                                        track: t(AXIS_LABEL_KEY.fulfillment),
                                    })}
                                </Button>
                            }
                        >
                            <p>{summary.locked_reason}</p>
                        </Notice>
                    )}

                    {canContinue && following && (
                        <Notice
                            tone="success"
                            role="status"
                            inset
                            title={t(
                                'orders.admin.status_update.continue_title',
                                { track: t(AXIS_LABEL_KEY[following]) },
                            )}
                            action={
                                <Button
                                    type="button"
                                    size="sm"
                                    onClick={() => chooseAxis(following)}
                                >
                                    {t(
                                        'orders.admin.status_update.continue_to',
                                        { track: t(AXIS_LABEL_KEY[following]) },
                                    )}
                                </Button>
                            }
                        />
                    )}

                    {/*
                      Outside the form on purpose. A refusal often means the
                      page was out of date, and the answer then refreshes this
                      track — which may have no moves left, and no form to
                      carry the message.
                    */}
                    {errors.status && (
                        <Notice
                            tone="danger"
                            role="alert"
                            inset
                            title={t('orders.admin.status_update.refused')}
                        >
                            <p>{errors.status}</p>
                        </Notice>
                    )}

                    {!summary.can_manage ? (
                        <Notice tone="neutral" role="note" inset>
                            {t('orders.admin.status_update.no_permission')}
                        </Notice>
                    ) : summary.available_actions.length === 0 ? (
                        <Notice tone="neutral" role="note" inset>
                            {t('orders.admin.status_update.final', {
                                status: summary.status_label,
                            })}
                        </Notice>
                    ) : (
                        <form onSubmit={save} className="space-y-4" noValidate>
                            <fieldset
                                disabled={form.processing}
                                className="min-w-0 space-y-2"
                            >
                                <legend className="mb-2 text-sm font-medium">
                                    {t('orders.admin.status_update.move_to')}
                                </legend>

                                {summary.available_actions.map((status) => {
                                    const noteRequired =
                                        REASON_REQUIRED[axis].includes(status);

                                    return (
                                        <label
                                            key={status}
                                            className={cn(
                                                'flex cursor-pointer items-start gap-3 rounded-lg border px-3 py-2.5',
                                                'has-[:focus-visible]:ring-ring has-[:focus-visible]:ring-2',
                                                target === status
                                                    ? 'border-primary bg-surface-subtle'
                                                    : 'hover:bg-surface-subtle',
                                            )}
                                        >
                                            <input
                                                type="radio"
                                                name="status"
                                                value={status}
                                                checked={target === status}
                                                onChange={() => choose(status)}
                                                className="mt-1"
                                            />
                                            <span className="min-w-0 space-y-0.5">
                                                <span className="flex flex-wrap items-center gap-2">
                                                    <span className="text-sm font-medium">
                                                        {t(
                                                            `status.${group}.${status}`,
                                                        )}
                                                    </span>
                                                    {status === usual && (
                                                        <span className="text-info text-xs font-medium">
                                                            {t(
                                                                'orders.admin.status_update.usual_next',
                                                            )}
                                                        </span>
                                                    )}
                                                    {noteRequired && (
                                                        <span className="text-muted-foreground text-xs">
                                                            {t(
                                                                'orders.admin.status_update.note_required_tag',
                                                            )}
                                                        </span>
                                                    )}
                                                </span>
                                                <span className="text-muted-foreground block text-xs">
                                                    {t(
                                                        `orders.admin.status_update.effects.${group}.${status}`,
                                                    )}
                                                </span>
                                            </span>
                                        </label>
                                    );
                                })}
                            </fieldset>

                            {target !== '' && (
                                <FormField
                                    label={t('orders.admin.status_update.note')}
                                    required={needsNote}
                                    description={t(
                                        needsNote
                                            ? 'orders.admin.status_update.note_help_required'
                                            : 'orders.admin.status_update.note_help_optional',
                                    )}
                                    error={errors.reason}
                                >
                                    {(field) => (
                                        <TextArea
                                            {...field}
                                            rows={3}
                                            maxLength={1000}
                                            value={form.data.reason}
                                            onChange={(event) =>
                                                form.setData(
                                                    'reason',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    )}
                                </FormField>
                            )}

                            <div className="flex flex-wrap items-center justify-end gap-2">
                                {target !== '' && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        disabled={form.processing}
                                        onClick={() => {
                                            form.reset();
                                            form.clearErrors();
                                        }}
                                    >
                                        {t('orders.admin.status_update.clear')}
                                    </Button>
                                )}
                                <Button
                                    type="submit"
                                    variant={
                                        target === 'cancelled'
                                            ? 'destructive'
                                            : 'default'
                                    }
                                    disabled={
                                        target === '' ||
                                        noteMissing ||
                                        form.processing
                                    }
                                    aria-busy={form.processing || undefined}
                                >
                                    {form.processing && <Spinner />}
                                    {target === ''
                                        ? t(
                                              'orders.admin.status_update.save_choose',
                                          )
                                        : t('orders.admin.status_update.save', {
                                              status: t(
                                                  `status.${group}.${target}`,
                                              ),
                                          })}
                                </Button>
                            </div>
                        </form>
                    )}
                </div>
            )}
        </SectionCard>
    );
}

/**
 * The courier side of a delivery update: which courier, which consignment —
 * the facts staff are usually copying from somewhere else when they change a
 * delivery status.
 */
function CourierContext({
    shipments,
    canManageShipments,
}: {
    shipments: OrderShipment[];
    canManageShipments: boolean;
}) {
    const { t } = useTranslation();

    return (
        <div className="bg-surface-subtle rounded-lg border px-4 py-3">
            <p className="mb-2 text-sm font-medium">
                {t('orders.admin.status_update.shipments_title')}
            </p>

            {shipments.length === 0 ? (
                <p className="text-muted-foreground text-sm">
                    {t(
                        canManageShipments
                            ? 'orders.admin.status_update.no_shipments_hint'
                            : 'orders.admin.status_update.no_shipments',
                    )}
                </p>
            ) : (
                <ul className="divide-border divide-y">
                    {shipments.map((shipment) => (
                        <li
                            key={shipment.id}
                            className="flex flex-wrap items-center justify-between gap-2 py-2 first:pt-0 last:pb-0"
                        >
                            <div className="min-w-0 space-y-0.5">
                                <p className="text-sm font-medium">
                                    {shipment.provider}
                                </p>
                                <p className="text-muted-foreground font-mono text-xs">
                                    {shipment.tracking_number ??
                                        t(
                                            'orders.admin.status_update.no_tracking',
                                        )}
                                </p>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusPill
                                    tone={shipment.status_tone}
                                    label={shipment.status_label}
                                />
                                <Button asChild size="sm" variant="ghost">
                                    <Link href={shipmentShow(shipment.id)}>
                                        {t(
                                            'orders.admin.status_update.open_shipment',
                                        )}
                                    </Link>
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
