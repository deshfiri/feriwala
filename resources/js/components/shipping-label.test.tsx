// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type {
    PartnerShippingLabel,
    StaffShippingLabel,
} from './shipping-label';

vi.mock('@inertiajs/react', () => ({
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        setData: vi.fn(),
        post: vi.fn(),
        reset: vi.fn(),
        processing: false,
        errors: {},
    }),
    router: { post: vi.fn() },
    usePage: () => ({ props: { translations: {}, permissions: {} } }),
}));

const { PartnerShippingLabels, StaffShippingLabelPanel, shippingLabelUrl } =
    await import('./shipping-label');

function shippingLabelStaff(
    overrides: Partial<StaffShippingLabel> = {},
): StaffShippingLabel {
    return {
        shipment: 'shp_1',
        reference: 'SHP-1',
        status: 'ready',
        status_label: 'Ready',
        status_tone: 'success',
        message: null,
        version: 1,
        issued_at: '2026-11-25T10:00:00+06:00',
        print_url: '/admin/shipments/shp_1/label',
        issue_url: '/admin/shipments/shp_1/label',
        reissue_url: '/admin/shipments/shp_1/label/corrections',
        can: { print: true, issue: false, reissue: false },
        versions: [],
        ...overrides,
    };
}

describe('shipping label', () => {
    beforeEach(() => window.localStorage.clear());

    it('opens the print page at the chosen size and prints it straight away', () => {
        expect(shippingLabelUrl('/admin/shipments/shp_1/label', '3x3')).toBe(
            `${window.location.origin}/admin/shipments/shp_1/label?size=3x3&autoprint=1`,
        );
    });

    it('offers Print and Save as PDF at both sizes, each opening the same page per size', () => {
        render(<StaffShippingLabelPanel label={shippingLabelStaff()} />);

        const prints = screen
            .getAllByRole('link', { name: /shipping_label.panel.print_size/ })
            .map((link) => link.getAttribute('href'));
        const pdfs = screen
            .getAllByRole('link', { name: /shipping_label.panel.pdf_size/ })
            .map((link) => link.getAttribute('href'));

        expect(prints).toEqual([
            expect.stringContaining('size=3x3'),
            expect.stringContaining('size=3x4'),
        ]);
        // The PDF is the printed page saved: it cannot differ from the print.
        expect(pdfs).toEqual(prints);
    });

    it('offers no printing while the label waits, and says why', () => {
        render(
            <StaffShippingLabelPanel
                label={shippingLabelStaff({
                    status: 'needs_information',
                    status_label: 'Needs information',
                    status_tone: 'warning',
                    message: 'Label waiting for courier information.',
                    version: null,
                    can: { print: false, issue: true, reissue: false },
                })}
            />,
        );

        expect(
            screen.getByText('Label waiting for courier information.'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('link', {
                name: /shipping_label.panel.print_size/,
            }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'shipping_label.panel.retry' }),
        ).toBeInTheDocument();
    });

    it('gives a Partner a print link only for a ready parcel', () => {
        const labels: PartnerShippingLabel[] = [
            {
                shipment: 'shp_1',
                reference: 'SHP-1',
                ready: true,
                parcel: null,
                print_url: '/store-orders/ord_1/shipments/shp_1/label',
            },
            {
                shipment: 'shp_2',
                reference: 'SHP-2',
                ready: false,
                parcel: null,
                print_url: null,
            },
        ];

        render(<PartnerShippingLabels labels={labels} />);

        // Print 3 × 3 and Print 3 × 4 for the ready parcel only.
        expect(
            screen.getAllByRole('link', {
                name: /shipping_label.panel.print_size/,
            }),
        ).toHaveLength(2);
        expect(
            screen.getByText('shipping_label.panel.partner_waiting'),
        ).toBeInTheDocument();
    });
});
