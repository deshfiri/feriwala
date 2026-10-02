import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import ShipmentController from '@/actions/App/Http/Controllers/Admin/ShipmentController';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import TextArea from '@/components/forms/text-area';
import DetailList, { DetailItem } from '@/components/detail-list';
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
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import type { StatusTone } from '@/lib/status';
import { index } from '@/routes/admin/shipments';

type ShipmentPackage = {
    weight: string | null;
    length: string | null;
    width: string | null;
    height: string | null;
    package_size: string | null;
};

type TrackingEvent = {
    event_code: string;
    description: string | null;
    occurred_at: string;
    source: string;
};

type HistoryEntry = {
    previous_status: string | null;
    new_status: string;
    changed_by: string | null;
    changed_at: string;
    reason: string | null;
};

type ShipmentDetail = {
    id: string;
    reference: string;
    order: { id: string; reference: string };
    provider: string;
    tracking_number: string | null;
    delivery_charge: Money;
    cod_amount: Money | null;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    is_terminal: boolean;
    can_manage: boolean;
    available_actions: string[];
    pickup_requested_at: string | null;
    cancelled_by: string | null;
    cancellation_reason: string | null;
    cancelled_at: string | null;
    packages: ShipmentPackage[];
    tracking_events: TrackingEvent[];
    history: HistoryEntry[];
};

const REASON_REQUIRED = ['cancelled', 'failed_delivery'];

/**
 * One shipment's full record (Advanced Order Management batch, Commit 5;
 * §21) -- staff advance it by hand, since the manual courier driver has no
 * webhook to tell it anything itself (D8).
 */
export default function AdminShipmentShow({
    shipment,
}: {
    shipment: ShipmentDetail;
}) {
    const { t, locale } = useTranslation();
    const [pendingAction, setPendingAction] = useState<string | null>(null);

    const form = useForm({ status: '', reason: '' });

    const datetime = (value: string | null) =>
        value ? new Date(value).toLocaleString(locale) : '—';

    const submit = (status: string) => {
        if (REASON_REQUIRED.includes(status)) {
            setPendingAction(status);

            return;
        }

        form.transform(() => ({ status }));
        form.post(ShipmentController.advanceStatus.url(shipment.id), {
            preserveScroll: true,
        });
    };

    const submitWithReason = (event: FormEvent) => {
        event.preventDefault();

        if (pendingAction === null) {
            return;
        }

        form.transform((data) => ({
            status: pendingAction,
            reason: data.reason,
        }));
        form.post(ShipmentController.advanceStatus.url(shipment.id), {
            preserveScroll: true,
            onSuccess: () => {
                setPendingAction(null);
                form.reset();
            },
        });
    };

    return (
        <>
            <Head title={shipment.reference} />

            <PageContainer>
                <Link
                    href={index()}
                    className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 text-sm"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    {t('courier.admin.back')}
                </Link>

                <PageHeader
                    title={shipment.reference}
                    description={t('courier.admin.for_order', {
                        reference: shipment.order.reference,
                    })}
                    actions={
                        <StatusPill
                            tone={shipment.status_tone}
                            label={shipment.status_label}
                        />
                    }
                />

                <SectionCard title={t('courier.admin.details_title')}>
                    <DetailList columns={3}>
                        <DetailItem label={t('courier.admin.fields.provider')}>
                            {shipment.provider}
                        </DetailItem>
                        <DetailItem
                            label={t('courier.admin.fields.tracking_number')}
                        >
                            {shipment.tracking_number ?? '—'}
                        </DetailItem>
                        <DetailItem
                            label={t('courier.admin.fields.delivery_charge')}
                        >
                            <MoneyAmount amount={shipment.delivery_charge} />
                        </DetailItem>
                        {shipment.cod_amount && (
                            <DetailItem
                                label={t('courier.admin.fields.cod_amount')}
                            >
                                <MoneyAmount amount={shipment.cod_amount} />
                            </DetailItem>
                        )}
                        <DetailItem
                            label={t(
                                'courier.admin.fields.pickup_requested_at',
                            )}
                        >
                            {datetime(shipment.pickup_requested_at)}
                        </DetailItem>
                    </DetailList>

                    {shipment.cancellation_reason && (
                        <p className="text-destructive mt-4 text-sm">
                            {t('courier.admin.cancelled_reason', {
                                reason: shipment.cancellation_reason,
                            })}
                        </p>
                    )}

                    {shipment.can_manage &&
                        shipment.available_actions.length > 0 && (
                            <div className="mt-4 flex flex-wrap gap-2">
                                {shipment.available_actions.map((action) => (
                                    <Button
                                        key={action}
                                        type="button"
                                        size="sm"
                                        variant={
                                            action === 'cancelled' ||
                                            action === 'failed_delivery'
                                                ? 'outline'
                                                : 'default'
                                        }
                                        onClick={() => submit(action)}
                                    >
                                        {t(`courier.admin.actions.${action}`)}
                                    </Button>
                                ))}
                            </div>
                        )}
                </SectionCard>

                <SectionCard title={t('courier.admin.packages_title')}>
                    {shipment.packages.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            {t('courier.admin.no_packages')}
                        </p>
                    ) : (
                        <div className="space-y-3">
                            {shipment.packages.map((pkg, packageIndex) => (
                                <DetailList key={packageIndex} columns={3}>
                                    <DetailItem
                                        label={t('courier.admin.fields.weight')}
                                    >
                                        {pkg.weight ?? '—'}
                                    </DetailItem>
                                    <DetailItem
                                        label={t(
                                            'courier.admin.fields.dimensions',
                                        )}
                                    >
                                        {pkg.length && pkg.width && pkg.height
                                            ? `${pkg.length} x ${pkg.width} x ${pkg.height}`
                                            : '—'}
                                    </DetailItem>
                                    <DetailItem
                                        label={t(
                                            'courier.admin.fields.package_size',
                                        )}
                                    >
                                        {pkg.package_size ?? '—'}
                                    </DetailItem>
                                </DetailList>
                            ))}
                        </div>
                    )}
                </SectionCard>

                <SectionCard title={t('courier.admin.tracking_title')}>
                    {shipment.tracking_events.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            {t('courier.admin.no_tracking_events')}
                        </p>
                    ) : (
                        <ol className="border-border space-y-2 border-l pl-3 text-sm">
                            {shipment.tracking_events.map((event, position) => (
                                <li key={position}>
                                    <span className="font-medium">
                                        {event.event_code}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {' · '}
                                        {datetime(event.occurred_at)}
                                    </span>
                                    {event.description && (
                                        <p className="text-muted-foreground">
                                            {event.description}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ol>
                    )}
                </SectionCard>

                <SectionCard title={t('courier.admin.history_title')}>
                    {shipment.history.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            {t('courier.admin.no_history')}
                        </p>
                    ) : (
                        <ol className="border-border space-y-2 border-l pl-3 text-sm">
                            {shipment.history.map((entry, position) => (
                                <li key={position}>
                                    <span className="font-medium">
                                        {entry.new_status}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {' · '}
                                        {datetime(entry.changed_at)}
                                        {entry.changed_by &&
                                            ` · ${entry.changed_by}`}
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
                </SectionCard>
            </PageContainer>

            <Dialog
                open={pendingAction !== null}
                onOpenChange={(open) => !open && setPendingAction(null)}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {pendingAction &&
                                t(`courier.admin.actions.${pendingAction}`)}
                        </DialogTitle>
                        <DialogDescription>
                            {t('courier.admin.reason_description')}
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submitWithReason} className="space-y-4">
                        <FormField
                            label={t('courier.admin.reason')}
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
                                    t(`courier.admin.actions.${pendingAction}`)}
                            </SubmitButton>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

AdminShipmentShow.layout = {
    breadcrumbs: [{ title: 'nav.shipments', href: index() }],
};
