// @vitest-environment jsdom

import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type {
    PartnerCourierData,
    StaffCourierData,
} from './store-order-courier';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        setData: vi.fn(),
        post,
        processing: false,
        errors: {},
    }),
    usePage: () => ({ props: { translations: {}, permissions: {} } }),
}));

const { PartnerCourierCard, StaffCourierPanel } =
    await import('./store-order-courier');

const shipment: NonNullable<StaffCourierData['shipment']> = {
    id: 'shp_1',
    consignment_id: '1424107',
    tracking_number: '15BAEB8A',
    raw_status: 'in_review',
    raw_status_label: 'In review',
    status: 'assigned',
    status_label: 'Assigned',
    status_tone: 'info',
    charge: null,
    last_checked_at: null,
    sync_error: null,
    needs_review: false,
    can_sync: true,
    sync_url: '/admin/shipments/shp_1/steadfast-sync',
};

function staff(overrides: Partial<StaffCourierData> = {}): StaffCourierData {
    return {
        enabled: true,
        booking: null,
        shipment: null,
        can: { book: false, reconcile: false, mark_not_booked: false },
        urls: {
            book: '/admin/orders/ord_1/steadfast-booking',
            reconcile: null,
            not_booked: null,
        },
        ...overrides,
    };
}

beforeEach(() => {
    post.mockReset();
    vi.restoreAllMocks();
});

describe('the staff panel', () => {
    it('offers booking only when booking is allowed, and asks first', () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);

        const { rerender } = render(<StaffCourierPanel courier={staff()} />);

        expect(
            screen.queryByRole('button', { name: 'steadfast.panel.book' }),
        ).toBeNull();

        rerender(
            <StaffCourierPanel
                courier={staff({
                    can: {
                        book: true,
                        reconcile: false,
                        mark_not_booked: false,
                    },
                })}
            />,
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'steadfast.panel.book' }),
        );

        expect(confirm).toHaveBeenCalledOnce();
        // Declining the confirmation sends nothing: a booking may be chargeable.
        expect(post).not.toHaveBeenCalled();

        confirm.mockReturnValue(true);
        fireEvent.click(
            screen.getByRole('button', { name: 'steadfast.panel.book' }),
        );

        expect(post).toHaveBeenCalledWith(
            '/admin/orders/ord_1/steadfast-booking',
            { preserveScroll: true },
        );
    });

    it('shows both statuses, the references, and an unreported charge as such', () => {
        render(<StaffCourierPanel courier={staff({ shipment })} />);

        expect(screen.getByText('1424107')).toBeTruthy();
        expect(screen.getByText('15BAEB8A')).toBeTruthy();
        expect(screen.getByText('In review')).toBeTruthy();
        expect(screen.getByText('Assigned')).toBeTruthy();
        expect(screen.getByText('steadfast.panel.charge_unknown')).toBeTruthy();
        expect(screen.getByText('steadfast.panel.never_checked')).toBeTruthy();
    });

    it('shows a status Banij does not recognise beside its raw value', () => {
        render(
            <StaffCourierPanel
                courier={staff({
                    shipment: {
                        ...shipment,
                        raw_status: 'out_for_delivery',
                        raw_status_label: null,
                        needs_review: true,
                    },
                })}
            />,
        );

        expect(
            screen.getByText('steadfast.panel.unrecognised: out_for_delivery'),
        ).toBeTruthy();
        expect(screen.getByRole('alert').textContent).toContain(
            'steadfast.panel.review',
        );
    });

    it('lets a person settle an uncertain booking, and only then', () => {
        const uncertain = staff({
            booking: {
                id: 'att_1',
                status: 'uncertain',
                status_label: 'Booking outcome unknown',
                message: null,
                at: '2026-11-01T10:00:00Z',
            },
            can: { book: false, reconcile: true, mark_not_booked: true },
            urls: {
                book: '/b',
                reconcile: '/admin/courier/booking-attempts/att_1/reconcile',
                not_booked: '/admin/courier/booking-attempts/att_1/not-booked',
            },
        });

        const { unmount } = render(<StaffCourierPanel courier={uncertain} />);

        expect(
            screen.getByRole('button', { name: 'steadfast.panel.reconcile' }),
        ).toBeTruthy();
        expect(
            screen.getByRole('button', {
                name: 'steadfast.panel.not_booked_submit',
            }),
        ).toBeTruthy();
        expect(
            screen.queryByRole('button', { name: 'steadfast.panel.book' }),
        ).toBeNull();

        fireEvent.click(
            screen.getByRole('button', { name: 'steadfast.panel.reconcile' }),
        );

        expect(post).toHaveBeenCalledWith(
            '/admin/courier/booking-attempts/att_1/reconcile',
            { preserveScroll: true },
        );

        unmount();
        render(<StaffCourierPanel courier={staff()} />);

        expect(
            screen.queryByRole('button', {
                name: 'steadfast.panel.not_booked_submit',
            }),
        ).toBeNull();
    });

    it('says what a booking failed with, in words it was given', () => {
        render(
            <StaffCourierPanel
                courier={staff({
                    booking: {
                        id: 'att_2',
                        status: 'failed',
                        status_label: 'Booking failed',
                        message: 'Steadfast did not accept the API key.',
                        at: '2026-11-01T10:00:00Z',
                    },
                })}
            />,
        );

        expect(screen.getByText('Booking failed')).toBeTruthy();
        expect(
            screen.getByText('Steadfast did not accept the API key.'),
        ).toBeTruthy();
    });

    it("tells staff a Steadfast parcel is cancelled in Steadfast's own panel", () => {
        render(<StaffCourierPanel courier={staff({ shipment })} />);

        expect(screen.getByText('steadfast.panel.cancel_note')).toBeTruthy();
    });
});

describe('the Partner card', () => {
    const partner: PartnerCourierData = {
        name: 'Steadfast Courier',
        tracking_number: '15BAEB8A',
        status: 'in_transit',
        status_label: 'In transit',
        status_tone: 'info',
        updated_at: '2026-11-01T10:00:00Z',
        events: [
            { label: 'Assigned', at: '2026-11-01T08:00:00Z' },
            { label: 'In transit', at: '2026-11-01T10:00:00Z' },
        ],
    };

    it('shows the courier, the tracking code, the status and the timeline', () => {
        render(<PartnerCourierCard courier={partner} />);

        expect(screen.getByText('Steadfast Courier')).toBeTruthy();
        expect(screen.getByText('15BAEB8A')).toBeTruthy();
        expect(screen.getAllByText('In transit').length).toBeGreaterThan(0);
        expect(screen.getByText('Assigned')).toBeTruthy();
    });

    it('has no controls and nothing for staff', () => {
        render(<PartnerCourierCard courier={partner} />);

        expect(screen.queryAllByRole('button')).toHaveLength(0);
        expect(screen.queryByRole('alert')).toBeNull();
        expect(screen.queryByText(/steadfast\.panel/)).toBeNull();
    });
});
