import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import OrderController from '@/actions/App/Http/Controllers/Admin/OrderController';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import TextArea from '@/components/forms/text-area';
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

export type FulfilmentCommitmentAction =
    | 'confirm'
    | 'start_preparing'
    | 'mark_ready'
    | 'fail'
    | 'cancel';

export type FulfilmentCommitment = {
    id: string;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    is_terminal: boolean;
    is_staff_managed: boolean;
    supply_mode: 'ready_stock' | 'on_demand' | 'pre_order';
    supply_mode_label: string;
    lead_time_days: number | null;
    fulfilment_capacity: number | null;
    quantity: number;
    due_at: string | null;
    confirmed_at: string | null;
    failed_reason: string | null;
    failed_at: string | null;
    cancelled_reason: string | null;
    cancelled_at: string | null;
    can_manage: boolean;
    available_actions: FulfilmentCommitmentAction[];
    history: {
        previous_status: string | null;
        new_status: string;
        changed_by: string | null;
        changed_at: string;
        reason: string | null;
    }[];
};

const REASON_REQUIRED: FulfilmentCommitmentAction[] = ['fail', 'cancel'];

/**
 * A Supplier's fulfilment commitment on one order line — the capacity
 * reservation standing in for physical stock on an on_demand/pre_order
 * allocation (Supplier Bulk Product Listing batch, correction 7).
 *
 * Every transition here is staff-initiated: {@see
 * AdvanceSupplierFulfilmentCommitment} has no Supplier-facing counterpart in
 * this batch, so the panel says so plainly rather than implying a Supplier
 * can confirm their own commitment from somewhere else. That self-service
 * step is an Advanced Order Management dependency, not built yet.
 */
export default function FulfilmentCommitmentPanel({
    orderId,
    itemId,
    commitment,
}: {
    orderId: string;
    itemId: string;
    commitment: FulfilmentCommitment;
}) {
    const { t, locale } = useTranslation();
    const [pendingAction, setPendingAction] =
        useState<FulfilmentCommitmentAction | null>(null);

    const form = useForm({
        action: '' as FulfilmentCommitmentAction | '',
        reason: '',
    });

    const submit = (action: FulfilmentCommitmentAction) => {
        if (REASON_REQUIRED.includes(action)) {
            setPendingAction(action);

            return;
        }

        form.transform(() => ({ action }));
        form.post(
            OrderController.advanceFulfilmentCommitment.url({
                order: orderId,
                item: itemId,
                commitment: commitment.id,
            }),
            { preserveScroll: true },
        );
    };

    const submitWithReason = (event: FormEvent) => {
        event.preventDefault();

        if (pendingAction === null) {
            return;
        }

        form.transform((data) => ({
            action: pendingAction,
            reason: data.reason,
        }));
        form.post(
            OrderController.advanceFulfilmentCommitment.url({
                order: orderId,
                item: itemId,
                commitment: commitment.id,
            }),
            {
                preserveScroll: true,
                onSuccess: () => {
                    setPendingAction(null);
                    form.reset();
                },
            },
        );
    };

    return (
        <div className="border-border mt-2 space-y-2 rounded-lg border border-dashed p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm font-medium">
                    {t('orders.admin.fulfilment_commitment.title')}
                </p>
                <StatusPill
                    tone={commitment.status_tone}
                    label={commitment.status_label}
                />
            </div>

            <p className="text-muted-foreground text-xs">
                {commitment.supply_mode_label}
                {commitment.lead_time_days !== null &&
                    ` · ${t('orders.admin.allocation.lead_time', { days: commitment.lead_time_days })}`}
                {commitment.fulfilment_capacity !== null &&
                    ` · ${t('orders.admin.allocation.capacity', { capacity: commitment.fulfilment_capacity })}`}
                {commitment.due_at &&
                    ` · ${t('orders.admin.fulfilment_commitment.due', { date: new Date(commitment.due_at).toLocaleDateString(locale) })}`}
            </p>

            <p className="text-muted-foreground text-xs italic">
                {t('orders.admin.fulfilment_commitment.staff_managed_notice')}
            </p>

            {commitment.failed_reason && (
                <p className="text-destructive text-xs">
                    {t('orders.admin.fulfilment_commitment.failed_reason', {
                        reason: commitment.failed_reason,
                    })}
                </p>
            )}
            {commitment.cancelled_reason && (
                <p className="text-muted-foreground text-xs">
                    {t('orders.admin.fulfilment_commitment.cancelled_reason', {
                        reason: commitment.cancelled_reason,
                    })}
                </p>
            )}

            {commitment.can_manage &&
                commitment.available_actions.length > 0 && (
                    <div className="flex flex-wrap gap-2 pt-1">
                        {commitment.available_actions.map((action) => (
                            <Button
                                key={action}
                                type="button"
                                size="sm"
                                variant={
                                    action === 'fail' || action === 'cancel'
                                        ? 'outline'
                                        : 'default'
                                }
                                onClick={() => submit(action)}
                            >
                                {t(
                                    `orders.admin.fulfilment_commitment.actions.${action}`,
                                )}
                            </Button>
                        ))}
                    </div>
                )}

            {commitment.history.length > 0 && (
                <ol className="border-border space-y-1.5 border-l pt-1 pl-3 text-xs">
                    {commitment.history.map((entry, position) => (
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
                open={pendingAction !== null}
                onOpenChange={(open) => !open && setPendingAction(null)}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {pendingAction &&
                                t(
                                    `orders.admin.fulfilment_commitment.actions.${pendingAction}`,
                                )}
                        </DialogTitle>
                        <DialogDescription>
                            {t(
                                'orders.admin.fulfilment_commitment.reason_description',
                            )}
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submitWithReason} className="space-y-4">
                        <FormField
                            label={t(
                                'orders.admin.fulfilment_commitment.reason',
                            )}
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
                                onClick={() => setPendingAction(null)}
                            >
                                {t('common.actions.cancel')}
                            </Button>
                            <SubmitButton processing={form.processing}>
                                {pendingAction &&
                                    t(
                                        `orders.admin.fulfilment_commitment.actions.${pendingAction}`,
                                    )}
                            </SubmitButton>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
