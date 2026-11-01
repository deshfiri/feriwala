import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import type { StatusTone } from '@/lib/status';

export type StaffCourierData = {
    enabled: boolean;
    booking: {
        id: string;
        status: 'pending' | 'booked' | 'failed' | 'uncertain';
        status_label: string;
        message: string | null;
        at: string;
    } | null;
    shipment: {
        id: string;
        consignment_id: string | null;
        tracking_number: string | null;
        raw_status: string | null;
        raw_status_label: string | null;
        status: string;
        status_label: string;
        status_tone: StatusTone;
        charge: Money | null;
        last_checked_at: string | null;
        sync_error: string | null;
        needs_review: boolean;
        can_sync: boolean;
        sync_url: string;
    } | null;
    can: { book: boolean; reconcile: boolean; mark_not_booked: boolean };
    urls: {
        book: string;
        reconcile: string | null;
        not_booked: string | null;
    };
};

export type PartnerCourierData = {
    name: string;
    tracking_number: string | null;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    updated_at: string | null;
    events: { label: string; at: string }[];
};

const bookingTone: Record<
    NonNullable<StaffCourierData['booking']>['status'],
    StatusTone
> = {
    pending: 'info',
    booked: 'success',
    failed: 'danger',
    uncertain: 'warning',
};

function when(value: string | null): string | null {
    return value === null ? null : new Date(value).toLocaleString();
}

function Row({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-wrap justify-between gap-2 text-sm">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right font-medium">{children}</dd>
        </div>
    );
}

/**
 * Steadfast booking, for staff, on a Store Order: book, the state of the
 * booking, Steadfast's own status beside Banij's, and what to do about a booking
 * whose outcome is not known.
 *
 * Credentials, webhook details and raw responses are never here: the page is
 * told only fixed, translated messages.
 */
export function StaffCourierPanel({ courier }: { courier: StaffCourierData }) {
    const { t } = useTranslation();
    const book = useForm({});
    const reconcile = useForm({});
    const notBooked = useForm({ reason: '' });
    const sync = useForm({});
    const { booking, shipment } = courier;

    const submitBook = (event: FormEvent) => {
        event.preventDefault();

        if (window.confirm(t('steadfast.panel.book_confirm'))) {
            book.post(courier.urls.book, { preserveScroll: true });
        }
    };

    const submitNotBooked = (event: FormEvent) => {
        event.preventDefault();

        if (courier.urls.not_booked) {
            notBooked.post(courier.urls.not_booked, { preserveScroll: true });
        }
    };

    return (
        <SectionCard title={t('steadfast.panel.title')}>
            <div className="space-y-4">
                {!courier.enabled && !shipment && (
                    <p className="text-muted-foreground text-sm">
                        {t('steadfast.panel.disabled')}
                    </p>
                )}

                {booking && (
                    <div className="space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-muted-foreground text-sm">
                                {t('steadfast.panel.booking')}
                            </span>
                            {/* Labelled, never colour alone (§33.9). */}
                            <StatusPill
                                tone={bookingTone[booking.status]}
                                label={booking.status_label}
                            />
                        </div>
                        {booking.message && (
                            <p className="text-sm">{booking.message}</p>
                        )}
                        {booking.status === 'uncertain' && (
                            <p className="text-muted-foreground text-sm">
                                {t('steadfast.panel.uncertain_help')}
                            </p>
                        )}
                    </div>
                )}

                {!booking && !shipment && courier.enabled && (
                    <p className="text-muted-foreground text-sm">
                        {t('steadfast.panel.not_booked')}
                    </p>
                )}

                {shipment && (
                    <dl className="space-y-1.5">
                        <Row label={t('steadfast.panel.consignment')}>
                            {shipment.consignment_id ?? '—'}
                        </Row>
                        <Row label={t('steadfast.panel.tracking')}>
                            {shipment.tracking_number ?? '—'}
                        </Row>
                        <Row label={t('steadfast.panel.steadfast_status')}>
                            {shipment.raw_status === null
                                ? '—'
                                : (shipment.raw_status_label ??
                                  `${t('steadfast.panel.unrecognised')}: ${shipment.raw_status}`)}
                        </Row>
                        <Row label={t('steadfast.panel.banij_status')}>
                            <StatusPill
                                tone={shipment.status_tone}
                                label={shipment.status_label}
                            />
                        </Row>
                        <Row label={t('steadfast.panel.charge')}>
                            {shipment.charge ? (
                                <MoneyAmount amount={shipment.charge} />
                            ) : (
                                t('steadfast.panel.charge_unknown')
                            )}
                        </Row>
                        <Row label={t('steadfast.panel.last_checked')}>
                            {when(shipment.last_checked_at) ??
                                t('steadfast.panel.never_checked')}
                        </Row>
                    </dl>
                )}

                {shipment?.needs_review && (
                    <p role="alert" className="text-danger text-sm font-medium">
                        {t('steadfast.panel.review')}
                        {shipment.consignment_id === null &&
                            ` ${t('steadfast.panel.review_unrecognised_tracking')}`}
                    </p>
                )}

                {shipment?.sync_error && (
                    <p className="text-muted-foreground text-sm">
                        {shipment.sync_error}
                    </p>
                )}

                <div className="flex flex-wrap gap-2">
                    {courier.can.book && (
                        <form onSubmit={submitBook}>
                            <Button type="submit" disabled={book.processing}>
                                {t('steadfast.panel.book')}
                            </Button>
                        </form>
                    )}

                    {courier.can.reconcile && courier.urls.reconcile && (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={reconcile.processing}
                            onClick={() =>
                                reconcile.post(
                                    courier.urls.reconcile as string,
                                    {
                                        preserveScroll: true,
                                    },
                                )
                            }
                        >
                            {t('steadfast.panel.reconcile')}
                        </Button>
                    )}

                    {shipment?.can_sync && (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={sync.processing}
                            onClick={() =>
                                sync.post(shipment.sync_url, {
                                    preserveScroll: true,
                                })
                            }
                        >
                            {t('steadfast.panel.check_now')}
                        </Button>
                    )}
                </div>

                <InputError
                    message={(book.errors as Record<string, string>).steadfast}
                />

                {courier.can.mark_not_booked && (
                    <form
                        onSubmit={submitNotBooked}
                        className="space-y-2 border-t pt-3"
                    >
                        <label
                            htmlFor="steadfast-not-booked-reason"
                            className="text-sm font-medium"
                        >
                            {t('steadfast.panel.not_booked_action')}
                        </label>
                        <Input
                            id="steadfast-not-booked-reason"
                            value={notBooked.data.reason}
                            onChange={(event) =>
                                notBooked.setData('reason', event.target.value)
                            }
                            placeholder={t('steadfast.panel.not_booked_reason')}
                            maxLength={500}
                            required
                        />
                        <InputError message={notBooked.errors.reason} />
                        <Button
                            type="submit"
                            size="sm"
                            variant="outline"
                            disabled={notBooked.processing}
                        >
                            {t('steadfast.panel.not_booked_submit')}
                        </Button>
                    </form>
                )}

                <p className="text-muted-foreground text-xs">
                    {t('steadfast.panel.cancel_note')}
                </p>
            </div>
        </SectionCard>
    );
}

/**
 * The Partner's view of their parcel: the courier, its tracking code, Banij's own
 * status and when it changed. No raw courier value, error, review note,
 * credential or provider payload ever reaches this component.
 */
export function PartnerCourierCard({
    courier,
}: {
    courier: PartnerCourierData;
}) {
    const { t } = useTranslation();

    return (
        <SectionCard title={t('steadfast.partner.title')}>
            <div className="space-y-3">
                <dl className="space-y-1.5">
                    <Row label={t('steadfast.partner.name')}>
                        {courier.name}
                    </Row>
                    <Row label={t('steadfast.partner.tracking')}>
                        {courier.tracking_number ?? '—'}
                    </Row>
                    <Row label={t('steadfast.partner.status')}>
                        <StatusPill
                            tone={courier.status_tone}
                            label={courier.status_label}
                        />
                    </Row>
                    {courier.updated_at && (
                        <Row label={t('steadfast.partner.updated')}>
                            {when(courier.updated_at)}
                        </Row>
                    )}
                </dl>

                {courier.events.length > 0 && (
                    <ol className="border-t pt-2 text-sm">
                        {courier.events.map((event, index) => (
                            <li
                                key={`${event.at}-${index}`}
                                className="flex justify-between gap-3 py-1"
                            >
                                <span>{event.label}</span>
                                <span className="text-muted-foreground">
                                    {when(event.at)}
                                </span>
                            </li>
                        ))}
                    </ol>
                )}
            </div>
        </SectionCard>
    );
}
