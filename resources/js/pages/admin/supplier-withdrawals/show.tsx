import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import SupplierWithdrawalController from '@/actions/App/Http/Controllers/Admin/SupplierWithdrawalController';
import InputError from '@/components/input-error';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import { index } from '@/routes/admin/supplier-withdrawals';
import { confirm as confirmPassword } from '@/routes/password';
import ReasonTextarea from '@/components/forms/reason-textarea';

type Withdrawal = {
    id: string;
    reference: string;
    supplier: string;
    supplier_id: string;
    amount: Money;
    currency: string;
    status: string;
    status_label: string;
    status_tone: string;
    payout_snapshot: {
        type_label: string | null;
        label: string | null;
        masked_number: string | null;
        bank_name?: string | null;
        branch_name?: string | null;
        district?: string | null;
        routing_number?: string | null;
    };
    failure_reason: string | null;
    external_reference: string | null;
    decision_note: string | null;
    requested_at: string;
    decided_at: string | null;
    paid_at: string | null;
    history: {
        previous_status: string | null;
        new_status: string;
        reason: string | null;
        changed_by: string | null;
        changed_at: string;
    }[];
};

type Props = {
    withdrawal: Withdrawal;
    can: { decide: boolean; reject: boolean; release_payment: boolean };
    password_confirmed: boolean;
};

/**
 * One Supplier withdrawal, and its decision (D25, P13-24).
 *
 * `markPaid` sits behind `RequirePassword` on the route; this screen shows
 * the form only once the password has actually been confirmed, exactly the
 * pattern the referral-commission reversal screen already uses.
 */
export default function AdminSupplierWithdrawalShow({
    withdrawal,
    can,
    password_confirmed: passwordConfirmed,
}: Props) {
    const { t, locale } = useTranslation();
    const [rejecting, setRejecting] = useState(false);
    const [failing, setFailing] = useState(false);

    return (
        <>
            <Head title={withdrawal.reference} />

            <PageContainer>
                <PageHeader
                    title={withdrawal.reference}
                    description={`${withdrawal.supplier} · ${withdrawal.payout_snapshot.type_label} · ${withdrawal.payout_snapshot.masked_number}`}
                    actions={
                        <>
                            <StatusPill
                                tone={withdrawal.status_tone as never}
                                label={withdrawal.status_label}
                            />
                            <Button variant="outline" size="sm" asChild>
                                <Link href={index()}>
                                    <ArrowLeft
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t('supplier.admin.withdrawals.back')}
                                </Link>
                            </Button>
                        </>
                    }
                />

                <SectionCard
                    title={t('supplier.admin.withdrawals.columns.amount')}
                >
                    <MoneyAmount amount={withdrawal.amount} size="large" />

                    <p className="text-muted-foreground mt-4 text-sm">
                        {t('supplier.admin.withdrawals.payout_destination')}:{' '}
                        {withdrawal.payout_snapshot.label} (
                        {withdrawal.payout_snapshot.masked_number})
                    </p>

                    {withdrawal.payout_snapshot.bank_name && (
                        <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                            <div>
                                <dt className="text-muted-foreground text-xs">
                                    {t('supplier.admin.withdrawals.bank')}
                                </dt>
                                <dd>{withdrawal.payout_snapshot.bank_name}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">
                                    {t('supplier.admin.withdrawals.branch')}
                                </dt>
                                <dd>
                                    {withdrawal.payout_snapshot.branch_name ??
                                        t(
                                            'supplier.admin.withdrawals.not_available',
                                        )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">
                                    {t('supplier.admin.withdrawals.district')}
                                </dt>
                                <dd>
                                    {withdrawal.payout_snapshot.district ??
                                        t(
                                            'supplier.admin.withdrawals.not_available',
                                        )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">
                                    {t(
                                        'supplier.admin.withdrawals.routing_number',
                                    )}
                                </dt>
                                <dd className="font-mono">
                                    {withdrawal.payout_snapshot
                                        .routing_number ??
                                        t(
                                            'supplier.admin.withdrawals.not_available',
                                        )}
                                </dd>
                            </div>
                        </dl>
                    )}

                    {withdrawal.external_reference && (
                        <p className="text-muted-foreground mt-2 text-xs">
                            {t('supplier.withdrawals.external_reference')}:{' '}
                            <span className="font-mono">
                                {withdrawal.external_reference}
                            </span>
                        </p>
                    )}

                    {withdrawal.failure_reason && (
                        <p
                            className="text-warning-foreground mt-2 text-sm font-medium"
                            role="status"
                        >
                            {withdrawal.failure_reason}
                        </p>
                    )}
                </SectionCard>

                {(withdrawal.status === 'requested' ||
                    withdrawal.status === 'under_review' ||
                    withdrawal.status === 'approved' ||
                    withdrawal.status === 'processing') && (
                    <SectionCard title={t('supplier.admin.withdrawals.title')}>
                        <div className="flex flex-wrap gap-2">
                            {can.decide &&
                                (withdrawal.status === 'requested' ||
                                    withdrawal.status === 'under_review') && (
                                    <Form
                                        {...SupplierWithdrawalController.approve.form(
                                            withdrawal.id,
                                        )}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                {processing && <Spinner />}
                                                {t(
                                                    'supplier.admin.withdrawals.approve',
                                                )}
                                            </Button>
                                        )}
                                    </Form>
                                )}

                            {can.reject &&
                                (withdrawal.status === 'requested' ||
                                    withdrawal.status === 'under_review') && (
                                    <Button
                                        variant="outline"
                                        onClick={() => setRejecting(true)}
                                    >
                                        {t('supplier.admin.withdrawals.reject')}
                                    </Button>
                                )}

                            {can.decide && withdrawal.status === 'approved' && (
                                <Form
                                    {...SupplierWithdrawalController.process.form(
                                        withdrawal.id,
                                    )}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            {t(
                                                'supplier.admin.withdrawals.process',
                                            )}
                                        </Button>
                                    )}
                                </Form>
                            )}

                            {can.release_payment &&
                                withdrawal.status === 'processing' &&
                                (passwordConfirmed ? (
                                    <MarkPaidForm
                                        withdrawalId={withdrawal.id}
                                    />
                                ) : (
                                    <Button variant="outline" asChild>
                                        <Link href={confirmPassword()}>
                                            {t(
                                                'supplier.admin.withdrawals.confirm_password',
                                            )}
                                        </Link>
                                    </Button>
                                ))}

                            {can.reject &&
                                withdrawal.status === 'processing' && (
                                    <Button
                                        variant="outline"
                                        onClick={() => setFailing(true)}
                                    >
                                        {t(
                                            'supplier.admin.withdrawals.mark_failed',
                                        )}
                                    </Button>
                                )}
                        </div>
                    </SectionCard>
                )}

                <SectionCard title={t('supplier.admin.withdrawals.history')}>
                    <ol className="divide-border divide-y text-sm">
                        {withdrawal.history.map((change, position) => (
                            <li
                                key={position}
                                className="py-2 first:pt-0 last:pb-0"
                            >
                                <div className="flex flex-wrap justify-between gap-2">
                                    <span>
                                        {change.new_status} ·{' '}
                                        {change.changed_by ?? '—'}
                                    </span>
                                    <span className="text-muted-foreground text-xs">
                                        {new Date(
                                            change.changed_at,
                                        ).toLocaleString(locale)}
                                    </span>
                                </div>
                                {change.reason && (
                                    <p className="text-muted-foreground">
                                        {change.reason}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ol>
                </SectionCard>
            </PageContainer>

            {rejecting && (
                <ReasonDialog
                    title={t('supplier.admin.withdrawals.reject')}
                    label={t('supplier.admin.withdrawals.reject_reason')}
                    action={SupplierWithdrawalController.reject.form(
                        withdrawal.id,
                    )}
                    onClose={() => setRejecting(false)}
                />
            )}

            {failing && (
                <ReasonDialog
                    title={t('supplier.admin.withdrawals.mark_failed')}
                    label={t('supplier.admin.withdrawals.failed_reason')}
                    action={SupplierWithdrawalController.markFailed.form(
                        withdrawal.id,
                    )}
                    onClose={() => setFailing(false)}
                />
            )}
        </>
    );
}

function MarkPaidForm({ withdrawalId }: { withdrawalId: string }) {
    const { t } = useTranslation();

    return (
        <Form
            {...SupplierWithdrawalController.markPaid.form(withdrawalId)}
            options={{ preserveScroll: true }}
            className="flex flex-wrap items-end gap-2"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-1.5">
                        <Label htmlFor="external-reference">
                            {t('supplier.admin.withdrawals.external_reference')}
                        </Label>
                        <Input
                            id="external-reference"
                            name="external_reference"
                            required
                            maxLength={120}
                            className="h-9 w-56"
                        />
                        <InputError message={errors.external_reference} />
                    </div>
                    <Button type="submit" disabled={processing}>
                        {processing && <Spinner />}
                        {t('supplier.admin.withdrawals.mark_paid')}
                    </Button>
                </>
            )}
        </Form>
    );
}

function ReasonDialog({
    title,
    label,
    action,
    onClose,
}: {
    title: string;
    label: string;
    action: ReturnType<typeof SupplierWithdrawalController.reject.form>;
    onClose: () => void;
}) {
    const { t } = useTranslation();

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{label}</DialogDescription>
                </DialogHeader>

                <Form
                    {...action}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onClose()}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="reason">{label}</Label>
                                <ReasonTextarea
                                    context="supplier"
                                    id="reason"
                                    name="reason"
                                    rows={3}
                                    minLength={5}
                                    maxLength={500}
                                    required
                                    className="border-input bg-background w-full rounded-lg border px-3 py-2 text-sm"
                                />
                                <InputError message={errors.reason} />
                            </div>

                            <DialogFooter className="gap-2 sm:gap-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={onClose}
                                >
                                    {t('common.actions.cancel')}
                                </Button>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    {title}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

AdminSupplierWithdrawalShow.layout = {
    breadcrumbs: [{ title: 'nav.supplier_withdrawals', href: index() }],
};
