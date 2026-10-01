import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import OrderController from '@/actions/App/Http/Controllers/Admin/OrderController';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import TextArea from '@/components/forms/text-area';
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
import type { StatusTone } from '@/lib/status';

export type LifecycleAxisSummary = {
    status: string;
    status_label: string;
    status_tone: StatusTone;
    is_terminal: boolean;
    can_manage: boolean;
    available_actions: string[];
    history: {
        previous_status: string | null;
        new_status: string;
        changed_by: string | null;
        changed_at: string;
        reason: string | null;
    }[];
};

export type OrderLifecycle = {
    fulfillment: LifecycleAxisSummary;
    delivery: LifecycleAxisSummary;
    courier: LifecycleAxisSummary;
};

/** Target statuses that always need a reason — mirrors each Advance action's own `REASON_REQUIRED`. */
const REASON_REQUIRED: Record<keyof OrderLifecycle, string[]> = {
    fulfillment: ['on_hold', 'cancelled'],
    delivery: ['on_hold', 'cancelled', 'failed_delivery', 'return_requested'],
    courier: ['cancelled', 'failed_delivery'],
};

const AXIS_URL: Record<keyof OrderLifecycle, (orderId: string) => string> = {
    fulfillment: (orderId) =>
        OrderController.advanceFulfilmentStatus.url({ order: orderId }),
    delivery: (orderId) =>
        OrderController.advanceDeliveryStatus.url({ order: orderId }),
    courier: (orderId) =>
        OrderController.advanceCourierStatus.url({ order: orderId }),
};

/**
 * The order's own fulfilment, delivery and courier axes (§18, §20, §21) —
 * three independent state machines beside the order's own status, each
 * showing exactly the moves {@see \App\Http\Controllers\Admin\OrderController::lifecycleSummary()}
 * derived from the state machine itself, so no transition route is ever
 * reachable only from the backend.
 */
export default function OrderLifecyclePanel({
    orderId,
    lifecycle,
}: {
    orderId: string;
    lifecycle: OrderLifecycle;
}) {
    const { t } = useTranslation();

    return (
        <SectionCard title={t('orders.admin.lifecycle.title')}>
            <div className="grid gap-5 sm:grid-cols-3">
                <LifecycleAxis
                    axis="fulfillment"
                    label={t('orders.admin.lifecycle.fulfilment_label')}
                    orderId={orderId}
                    summary={lifecycle.fulfillment}
                    statusGroup="order_fulfillment"
                />
                <LifecycleAxis
                    axis="delivery"
                    label={t('orders.admin.lifecycle.delivery_label')}
                    orderId={orderId}
                    summary={lifecycle.delivery}
                    statusGroup="order_delivery"
                />
                <LifecycleAxis
                    axis="courier"
                    label={t('orders.admin.lifecycle.courier_label')}
                    orderId={orderId}
                    summary={lifecycle.courier}
                    statusGroup="order_courier"
                />
            </div>
        </SectionCard>
    );
}

function LifecycleAxis({
    axis,
    label,
    orderId,
    summary,
    statusGroup,
}: {
    axis: keyof OrderLifecycle;
    label: string;
    orderId: string;
    summary: LifecycleAxisSummary;
    statusGroup: 'order_fulfillment' | 'order_delivery' | 'order_courier';
}) {
    const { t, locale } = useTranslation();
    const [pendingTarget, setPendingTarget] = useState<string | null>(null);

    const form = useForm({ status: '', reason: '' });

    const submit = (target: string) => {
        if (REASON_REQUIRED[axis].includes(target)) {
            setPendingTarget(target);

            return;
        }

        form.transform(() => ({ status: target }));
        form.post(AXIS_URL[axis](orderId), { preserveScroll: true });
    };

    const submitWithReason = (event: FormEvent) => {
        event.preventDefault();

        if (pendingTarget === null) {
            return;
        }

        form.transform((data) => ({
            status: pendingTarget,
            reason: data.reason,
        }));
        form.post(AXIS_URL[axis](orderId), {
            preserveScroll: true,
            onSuccess: () => {
                setPendingTarget(null);
                form.reset();
            },
        });
    };

    return (
        <div className="space-y-2">
            <div className="flex items-center justify-between gap-2">
                <p className="text-sm font-medium">{label}</p>
                <StatusPill
                    tone={summary.status_tone}
                    label={summary.status_label}
                />
            </div>

            {summary.can_manage && summary.available_actions.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {summary.available_actions.map((target) => (
                        <Button
                            key={target}
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => submit(target)}
                        >
                            {t('orders.admin.lifecycle.move_to', {
                                status: t(`status.${statusGroup}.${target}`),
                            })}
                        </Button>
                    ))}
                </div>
            )}

            {summary.history.length > 0 && (
                <ol className="border-border space-y-1.5 border-l pt-1 pl-3 text-xs">
                    {summary.history.map((entry, position) => (
                        <li key={position}>
                            <span className="font-medium">
                                {entry.new_status}
                            </span>
                            <span className="text-muted-foreground">
                                {' · '}
                                {new Date(entry.changed_at).toLocaleString(
                                    locale,
                                )}
                                {entry.changed_by && ` · ${entry.changed_by}`}
                            </span>
                            {entry.reason && (
                                <p className="text-muted-foreground">
                                    {entry.reason}
                                </p>
                            )}
                        </li>
                    ))}
                </ol>
            )}

            <Dialog
                open={pendingTarget !== null}
                onOpenChange={(open) => !open && setPendingTarget(null)}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {pendingTarget &&
                                t(`status.${statusGroup}.${pendingTarget}`)}
                        </DialogTitle>
                        <DialogDescription>
                            {t('orders.admin.lifecycle.reason_description')}
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submitWithReason} className="space-y-4">
                        <FormField
                            label={t('orders.admin.lifecycle.reason')}
                            error={form.errors.reason}
                            required
                        >
                            {(field) => (
                                <TextArea
                                    {...field}
                                    rows={3}
                                    value={form.data.reason}
                                    onChange={(e) =>
                                        form.setData('reason', e.target.value)
                                    }
                                    required
                                />
                            )}
                        </FormField>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => setPendingTarget(null)}
                            >
                                {t('common.actions.cancel')}
                            </Button>
                            <SubmitButton processing={form.processing}>
                                {pendingTarget &&
                                    t(`status.${statusGroup}.${pendingTarget}`)}
                            </SubmitButton>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
