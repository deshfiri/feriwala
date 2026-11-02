// @vitest-environment jsdom

import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { OrderLifecycle } from './order-lifecycle';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { translations: {} } }),
}));

const { default: OrderProgress } = await import('./order-progress');
const { default: OrderActivity } = await import('./order-activity');

function axis(
    status: string,
    actions: string[] = [],
    history: OrderLifecycle['fulfillment']['history'] = [],
): OrderLifecycle['fulfillment'] {
    return {
        status,
        status_label: `label:${status}`,
        status_tone: 'info',
        is_terminal: false,
        can_manage: true,
        available_actions: actions,
        history,
    };
}

describe('where this order is now', () => {
    it('shows each track’s own status and how far along it is', () => {
        render(
            <OrderProgress
                lifecycle={{
                    fulfillment: axis('packing', ['ready_for_dispatch']),
                    delivery: axis('not_shipped'),
                    courier: axis('unassigned'),
                }}
            />,
        );

        const fulfilment = document.querySelector(
            '[data-test="progress-fulfillment"]',
        )!;

        expect(
            within(fulfilment as HTMLElement).getByText('label:packing'),
        ).toBeInTheDocument();
        expect(
            within(fulfilment as HTMLElement).getByText(
                'orders.admin.progress.step',
            ),
        ).toBeInTheDocument();
        expect(
            screen
                .getAllByRole('progressbar')
                .map((bar) => bar.getAttribute('aria-valuenow')),
        ).toEqual(['5', '1', '1']);
    });

    it('says what the usual next step is where the person may take it', () => {
        render(
            <OrderProgress
                lifecycle={{
                    fulfillment: axis('packing', [
                        'ready_for_dispatch',
                        'on_hold',
                    ]),
                    delivery: axis('not_shipped'),
                    courier: axis('unassigned'),
                }}
            />,
        );

        expect(
            screen.getByText('status.order_fulfillment.ready_for_dispatch'),
        ).toBeInTheDocument();
    });

    it('does not suggest a step to somebody who may not take it', () => {
        render(
            <OrderProgress
                lifecycle={{
                    fulfillment: {
                        ...axis('packing', ['ready_for_dispatch']),
                        can_manage: false,
                    },
                    delivery: axis('not_shipped'),
                    courier: axis('unassigned'),
                }}
            />,
        );

        expect(
            screen.queryByText('status.order_fulfillment.ready_for_dispatch'),
        ).not.toBeInTheDocument();
    });

    it('calls out a hold or a failed delivery first, and shows it as an exception', () => {
        render(
            <OrderProgress
                lifecycle={{
                    fulfillment: axis('on_hold'),
                    delivery: axis('failed_delivery'),
                    courier: axis('unassigned'),
                }}
            />,
        );

        const note = screen.getByRole('note');

        expect(note).toHaveTextContent('orders.admin.progress.attention_title');
        expect(note.querySelectorAll('li')).toHaveLength(2);
        expect(
            screen.getAllByText('orders.admin.progress.exception'),
        ).toHaveLength(2);
    });

    it('is quiet when nothing needs attention', () => {
        render(
            <OrderProgress
                lifecycle={{
                    fulfillment: axis('packing'),
                    delivery: axis('not_shipped'),
                    courier: axis('unassigned'),
                }}
            />,
        );

        expect(screen.queryByRole('note')).not.toBeInTheDocument();
    });

    it('says a finished track is complete', () => {
        render(
            <OrderProgress
                lifecycle={{
                    fulfillment: axis('fulfilled'),
                    delivery: axis('delivered'),
                    courier: axis('delivered'),
                }}
            />,
        );

        expect(
            screen.getAllByText('orders.admin.progress.complete'),
        ).toHaveLength(3);
    });
});

describe('everything that happened to the order', () => {
    const change = (
        previous: string | null,
        next: string,
        at: string,
        by: string | null = 'Rumi',
        reason: string | null = null,
    ) => ({
        previous_status: previous,
        new_status: next,
        changed_by: by,
        changed_at: at,
        reason,
    });

    it('puts the order’s own history and all three tracks in one list, newest first', () => {
        render(
            <OrderActivity
                history={[
                    {
                        previous_status: null,
                        new_status: 'paid',
                        at: '2026-11-01T09:00:00+06:00',
                        source: 'payment_gateway',
                        actor: null,
                        reason: null,
                        internal_note: null,
                    },
                ]}
                lifecycle={{
                    fulfillment: axis(
                        'picking',
                        [],
                        [
                            change(
                                'Processing',
                                'Picking',
                                '2026-11-01T11:00:00+06:00',
                            ),
                        ],
                    ),
                    delivery: axis(
                        'not_shipped',
                        [],
                        [
                            change(
                                'Not shipped',
                                'Courier assigned',
                                '2026-11-01T12:00:00+06:00',
                            ),
                        ],
                    ),
                    courier: axis(
                        'assigned',
                        [],
                        [change(null, 'Assigned', '2026-11-01T10:00:00+06:00')],
                    ),
                }}
            />,
        );

        const items = screen.getAllByRole('listitem');

        expect(items).toHaveLength(4);
        // Newest first, across all four records.
        expect(items[0]).toHaveTextContent(
            'orders.admin.lifecycle.delivery_label',
        );
        expect(items[1]).toHaveTextContent(
            'orders.admin.lifecycle.fulfilment_label',
        );
        expect(items[2]).toHaveTextContent(
            'orders.admin.lifecycle.courier_label',
        );
        expect(items[3]).toHaveTextContent('orders.admin.activity.order');
        expect(items[3]).toHaveTextContent(
            'orders.admin.change_sources.payment_gateway',
        );
        expect(items[3]).toHaveTextContent('orders.admin.history.system');
    });

    it('says who moved what, from what to what, and why', () => {
        render(
            <OrderActivity
                history={[]}
                lifecycle={{
                    fulfillment: axis(
                        'on_hold',
                        [],
                        [
                            change(
                                'Picking',
                                'On hold',
                                '2026-11-01T11:00:00+06:00',
                                'Rumi',
                                'Stock count',
                            ),
                        ],
                    ),
                    delivery: axis('not_shipped'),
                    courier: axis('unassigned'),
                }}
            />,
        );

        const item = screen.getByRole('listitem');

        expect(item).toHaveTextContent('orders.admin.activity.moved');
        expect(item).toHaveTextContent('Rumi');
        expect(item).toHaveTextContent('orders.admin.history.reason');
    });

    it('says when nothing has happened', () => {
        render(
            <OrderActivity
                history={[]}
                lifecycle={{
                    fulfillment: axis('pending_review'),
                    delivery: axis('not_shipped'),
                    courier: axis('unassigned'),
                }}
            />,
        );

        expect(
            screen.getByText('orders.admin.activity.empty'),
        ).toBeInTheDocument();
    });
});
