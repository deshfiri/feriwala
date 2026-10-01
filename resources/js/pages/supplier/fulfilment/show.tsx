import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import TextArea from '@/components/forms/text-area';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import type { StatusTone } from '@/lib/status';
import { advance, index } from '@/routes/supplier/fulfilment';

type Action = 'confirm' | 'decline' | 'start_preparing' | 'mark_ready';

type Commitment = {
    id: string;
    reference: string;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    is_terminal: boolean;
    product_name: string;
    sku: string;
    quantity: number;
    supply_mode: string;
    supply_mode_label: string;
    lead_time_days: number | null;
    due_at: string | null;
    confirmation_due_at: string | null;
    needs_expected_ready_at: boolean;
    expected_ready_at: string | null;
    failed_reason: string | null;
    cancelled_reason: string | null;
    available_actions: Action[];
    history:
        | {
              previous_status: string | null;
              new_status: string;
              changed_by: string | null;
              changed_at: string;
              reason: string | null;
          }[]
        | null;
};

const REASON_REQUIRED: Action[] = ['decline'];

/**
 * A Supplier's own fulfilment commitment, with the self-service actions
 * {@see \App\Http\Controllers\Supplier\FulfilmentController::advance()}
 * derives straight from the state machine — no route this Supplier may
 * legally take is ever missing a button.
 */
export default function SupplierFulfilmentShow({
    commitment,
}: {
    commitment: Commitment;
}) {
    const { t, locale } = useTranslation();
    const [pendingAction, setPendingAction] = useState<Action | null>(null);

    const form = useForm({ action: '', reason: '', expected_ready_at: '' });

    const at = (value: string | null) =>
        value ? new Date(value).toLocaleString(locale) : '—';

    const submit = (action: Action) => {
        if (
            REASON_REQUIRED.includes(action) ||
            (action === 'confirm' && commitment.needs_expected_ready_at)
        ) {
            setPendingAction(action);

            return;
        }

        form.transform(() => ({ action }));
        form.post(advance(commitment.id).url, { preserveScroll: true });
    };

    const submitWithDetails = (event: FormEvent) => {
        event.preventDefault();

        if (pendingAction === null) {
            return;
        }

        form.transform((data) => ({
            action: pendingAction,
            reason: data.reason,
            expected_ready_at: data.expected_ready_at || undefined,
        }));
        form.post(advance(commitment.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                setPendingAction(null);
                form.reset();
            },
        });
    };

    return (
        <>
            <Head title={commitment.product_name} />

            <PageContainer width="narrow">
                <PageHeader
                    title={commitment.product_name}
                    description={commitment.reference}
                    actions={
                        <div className="flex items-center gap-2">
                            <StatusPill
                                tone={commitment.status_tone}
                                label={commitment.status_label}
                            />
                            <Button variant="outline" size="sm" asChild>
                                <Link href={index()}>
                                    <ArrowLeft
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t('supplier.fulfilment.back')}
                                </Link>
                            </Button>
                        </div>
                    }
                />

                <SectionCard title={t('supplier.fulfilment.details')}>
                    <dl className="grid gap-4 text-sm sm:grid-cols-3">
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t('supplier.fulfilment.quantity')}
                            </dt>
                            <dd>
                                {commitment.quantity.toLocaleString(locale)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t('supplier.fulfilment.supply_mode')}
                            </dt>
                            <dd>{commitment.supply_mode_label}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t('supplier.fulfilment.confirm_by_header')}
                            </dt>
                            <dd>{at(commitment.confirmation_due_at)}</dd>
                        </div>
                    </dl>

                    {commitment.failed_reason && (
                        <p className="text-destructive mt-3 text-sm">
                            {t('supplier.fulfilment.failed_reason', {
                                reason: commitment.failed_reason,
                            })}
                        </p>
                    )}
                    {commitment.cancelled_reason && (
                        <p className="text-muted-foreground mt-3 text-sm">
                            {t('supplier.fulfilment.cancelled_reason', {
                                reason: commitment.cancelled_reason,
                            })}
                        </p>
                    )}

                    {commitment.available_actions.length > 0 && (
                        <div className="mt-4 flex flex-wrap gap-2">
                            {commitment.available_actions.map((action) => (
                                <Button
                                    key={action}
                                    type="button"
                                    size="sm"
                                    variant={
                                        action === 'decline'
                                            ? 'outline'
                                            : 'default'
                                    }
                                    onClick={() => submit(action)}
                                >
                                    {t(`supplier.fulfilment.actions.${action}`)}
                                </Button>
                            ))}
                        </div>
                    )}
                </SectionCard>

                {commitment.history && commitment.history.length > 0 && (
                    <SectionCard title={t('supplier.fulfilment.history')}>
                        <ol className="border-border space-y-3 border-l pl-4">
                            {commitment.history.map((entry, position) => (
                                <li key={position}>
                                    <p className="text-sm font-medium">
                                        {entry.new_status}
                                    </p>
                                    <p className="text-muted-foreground text-xs">
                                        <time dateTime={entry.changed_at}>
                                            {at(entry.changed_at)}
                                        </time>
                                        {entry.changed_by &&
                                            ` · ${entry.changed_by}`}
                                    </p>
                                    {entry.reason && (
                                        <p className="mt-1 text-sm">
                                            {entry.reason}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ol>
                    </SectionCard>
                )}
            </PageContainer>

            <Dialog
                open={pendingAction !== null}
                onOpenChange={(open) => !open && setPendingAction(null)}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {pendingAction &&
                                t(
                                    `supplier.fulfilment.actions.${pendingAction}`,
                                )}
                        </DialogTitle>
                        <DialogDescription>
                            {t('supplier.fulfilment.reason_description')}
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submitWithDetails} className="space-y-4">
                        {pendingAction === 'confirm' &&
                            commitment.needs_expected_ready_at && (
                                <div className="grid gap-2">
                                    <Label htmlFor="expected-ready-at">
                                        {t(
                                            'supplier.fulfilment.expected_ready_at',
                                        )}
                                    </Label>
                                    <Input
                                        id="expected-ready-at"
                                        type="date"
                                        value={form.data.expected_ready_at}
                                        onChange={(e) =>
                                            form.setData(
                                                'expected_ready_at',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        {t(
                                            'supplier.fulfilment.expected_ready_at_help',
                                        )}
                                    </p>
                                </div>
                            )}

                        {pendingAction === 'decline' && (
                            <FormField
                                label={t('supplier.fulfilment.reason')}
                                error={form.errors.reason}
                                required
                            >
                                {(field) => (
                                    <TextArea
                                        {...field}
                                        rows={3}
                                        value={form.data.reason}
                                        onChange={(e) =>
                                            form.setData(
                                                'reason',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                )}
                            </FormField>
                        )}

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
                                        `supplier.fulfilment.actions.${pendingAction}`,
                                    )}
                            </SubmitButton>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
