// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { TimelineEntry } from '@/types/stores';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { translations: {} } }),
}));

const { default: OrderTimeline } = await import('./order-timeline');

function entry(overrides: Partial<TimelineEntry> = {}): TimelineEntry {
    return {
        key: 'order_status-1',
        type: 'order_status',
        type_label: 'Order status',
        previous: 'confirmed',
        previous_label: 'Confirmed',
        new: 'shipped',
        new_label: 'Shipped',
        at: '2026-10-02T10:00:00+06:00',
        recorded_at: null,
        source: 'system',
        source_label: 'System',
        actor: null,
        description: null,
        reference: null,
        ...overrides,
    };
}

describe('an order history', () => {
    it('says what changed, from what to what, and who moved it in words', () => {
        render(
            <OrderTimeline
                entries={[
                    entry({
                        source: 'external_store',
                        source_label: 'Your store',
                    }),
                    entry({
                        key: 'order_status-2',
                        source: 'partner',
                        source_label: 'You',
                        actor: 'Karim',
                        description: 'Cancelled at the customer’s request',
                    }),
                ]}
            />,
        );

        expect(screen.getAllByText('Confirmed')).toHaveLength(2);
        expect(screen.getAllByText('Shipped')).toHaveLength(2);
        expect(screen.getByText(/Your store/)).toBeInTheDocument();
        expect(screen.getByText(/orders\.timeline\.by/)).toBeInTheDocument();
        expect(
            screen.getByText('Cancelled at the customer’s request'),
        ).toBeInTheDocument();
    });

    it('names nobody when the server named nobody', () => {
        render(
            <OrderTimeline
                entries={[
                    entry({ source: 'staff', source_label: 'Feriwala staff' }),
                ]}
            />,
        );

        expect(screen.getByText(/Feriwala staff/)).toBeInTheDocument();
        expect(
            screen.queryByText(/orders\.timeline\.by/),
        ).not.toBeInTheDocument();
    });

    it('says so when nothing has been recorded', () => {
        render(<OrderTimeline entries={[]} />);

        expect(screen.getByText('orders.timeline.empty')).toBeInTheDocument();
    });
});
