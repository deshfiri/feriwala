// @vitest-environment jsdom

import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { requests } from '@/test/fake-use-form';
import type { OrderLifecycle } from './order-lifecycle';

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');
    const { fakeUseForm } = await import('@/test/fake-use-form');

    return {
        useForm: fakeUseForm,
        Link: ({
            href,
            children,
            ...rest
        }: {
            href: string | { url: string };
            children?: unknown;
        }) =>
            createElement(
                'a',
                { href: typeof href === 'string' ? href : href.url, ...rest },
                children as never,
            ),
        usePage: () => ({ props: { translations: {} } }),
    };
});

const { default: OrderStatusUpdate } = await import('./order-status-update');

function axis(
    status: string,
    actions: string[] = [],
    overrides: Partial<OrderLifecycle['fulfillment']> = {},
): OrderLifecycle['fulfillment'] {
    return {
        status,
        status_label: `label:${status}`,
        status_tone: 'info',
        is_terminal: actions.length === 0,
        can_manage: true,
        available_actions: actions,
        locked_reason: null,
        history: [],
        ...overrides,
    };
}

function lifecycle(overrides: Partial<OrderLifecycle> = {}): OrderLifecycle {
    return {
        fulfillment: axis('picking', ['packing', 'on_hold']),
        delivery: axis('not_shipped', ['courier_assigned', 'cancelled']),
        courier: axis('unassigned', ['assigned', 'cancelled']),
        ...overrides,
    };
}

function panel(props: Partial<Parameters<typeof OrderStatusUpdate>[0]> = {}) {
    return render(
        <OrderStatusUpdate
            orderId="01ORDER"
            lifecycle={lifecycle()}
            isStoreOrder={false}
            shipments={[]}
            canManageShipments={false}
            {...props}
        />,
    );
}

const save = () =>
    screen.getByRole('button', { name: /orders\.admin\.status_update\.save/ });
const choose = (name: string) =>
    fireEvent.click(screen.getByRole('radio', { name: new RegExp(name) }));
const track = (name: string) =>
    fireEvent.click(screen.getByRole('button', { name: new RegExp(name) }));

beforeEach(() => {
    requests.length = 0;
});

describe('choosing what changed', () => {
    it('offers exactly the moves the server allowed, each saying what it will do', () => {
        panel();

        const moves = screen.getAllByRole('radio');

        expect(moves).toHaveLength(2);
        expect(
            screen.getByText(
                'orders.admin.status_update.effects.order_fulfillment.packing',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'orders.admin.status_update.effects.order_fulfillment.on_hold',
            ),
        ).toBeInTheDocument();
        // Nothing the server did not offer.
        expect(
            screen.queryByText(
                'orders.admin.status_update.effects.order_fulfillment.cancelled',
            ),
        ).not.toBeInTheDocument();
    });

    it('marks the usual next step and the moves that need a note', () => {
        panel();

        expect(
            within(
                screen
                    .getByRole('radio', { name: /packing/ })
                    .closest('label')!,
            ).getByText('orders.admin.status_update.usual_next'),
        ).toBeInTheDocument();
        expect(
            within(
                screen
                    .getByRole('radio', { name: /on_hold/ })
                    .closest('label')!,
            ).getByText('orders.admin.status_update.note_required_tag'),
        ).toBeInTheDocument();
    });

    it('starts on the first track that has something to do', () => {
        panel({
            lifecycle: lifecycle({
                fulfillment: axis('fulfilled', []),
                delivery: axis('not_shipped', ['courier_assigned']),
            }),
        });

        expect(
            screen.getByText(
                'orders.admin.status_update.effects.order_delivery.courier_assigned',
            ),
        ).toBeInTheDocument();
    });

    it('moves to another track with a clean form', () => {
        panel();

        choose('on_hold');
        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: 'Stock check' },
        });
        track('orders.admin.lifecycle.delivery_label');

        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        expect(
            screen.getByText(
                'orders.admin.status_update.effects.order_delivery.courier_assigned',
            ),
        ).toBeInTheDocument();
        expect(
            screen
                .getAllByRole('radio')
                .every((r) => !(r as HTMLInputElement).checked),
        ).toBe(true);
    });
});

describe('saving', () => {
    it('cannot save until a status is chosen', () => {
        panel();

        expect(save()).toBeDisabled();
        expect(save()).toHaveTextContent(
            'orders.admin.status_update.save_choose',
        );
    });

    it('sends only the status when no note is needed or given, to the right track’s route', () => {
        panel();

        choose('packing');
        fireEvent.click(save());

        expect(requests).toHaveLength(1);
        expect(requests[0].method).toBe('post');
        expect(requests[0].url).toContain('01ORDER');
        expect(requests[0].url).toMatch(/fulfil/);
        expect(requests[0].payload).toEqual({ status: 'packing' });
    });

    it('uses the delivery and courier routes for those tracks', () => {
        panel();

        track('orders.admin.lifecycle.delivery_label');
        choose('courier_assigned');
        fireEvent.click(save());
        act(() => requests[0].succeed());

        track('orders.admin.lifecycle.courier_label');
        choose('assigned');
        fireEvent.click(save());

        expect(requests[0].url).toMatch(/delivery/);
        expect(requests[1].url).toMatch(/courier/);
        expect(requests[0].url).not.toBe(requests[1].url);
    });

    it('asks for the note a move requires before it can be saved, and sends it trimmed', () => {
        panel();

        choose('on_hold');

        expect(
            screen.getByText('orders.admin.status_update.note_help_required'),
        ).toBeInTheDocument();
        expect(save()).toBeDisabled();

        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: '   ' },
        });
        expect(save()).toBeDisabled();

        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: '  Waiting on a stock count  ' },
        });
        expect(save()).toBeEnabled();

        fireEvent.click(save());

        expect(requests[0].payload).toEqual({
            status: 'on_hold',
            reason: 'Waiting on a stock count',
        });
    });

    it('treats the note as optional where the server does', () => {
        panel();

        choose('packing');

        expect(
            screen.getByText('orders.admin.status_update.note_help_optional'),
        ).toBeInTheDocument();
        expect(save()).toBeEnabled();
    });

    it('is saving once however often it is pressed', () => {
        panel();

        choose('packing');
        fireEvent.click(save());
        fireEvent.click(save());
        fireEvent.submit(save().closest('form')!);

        expect(requests).toHaveLength(1);
        expect(save()).toBeDisabled();
        expect(save()).toHaveAttribute('aria-busy', 'true');
        // And the choice cannot be changed under a request in flight.
        expect(
            screen
                .getAllByRole('radio')
                // Disabled through the fieldset, which `.disabled` does not see.
                .every((r) => r.matches(':disabled')),
        ).toBe(true);

        act(() => requests[0].succeed());
    });

    it('sends one request when many clicks land before the page has re-rendered', () => {
        panel();

        choose('packing');
        const button = save();

        // One tick, so `processing` has not had a chance to turn true.
        act(() => {
            for (let press = 0; press < 10; press += 1) {
                button.click();
            }
        });

        expect(requests).toHaveLength(1);

        act(() => requests[0].succeed());
    });

    it('clears the choice once the server accepts it', () => {
        panel();

        choose('packing');
        fireEvent.click(save());
        act(() => requests[0].succeed());

        expect(
            (screen.getByRole('radio', { name: /packing/ }) as HTMLInputElement)
                .checked,
        ).toBe(false);
        expect(save()).toBeDisabled();
    });

    it('shows the server’s refusal beside the form and keeps what was chosen', () => {
        panel();

        choose('on_hold');
        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: 'Stock check' },
        });
        fireEvent.click(save());
        act(() =>
            requests[0].fail({
                status: 'Cannot move from picking to on hold.',
            }),
        );

        expect(screen.getByRole('alert')).toHaveTextContent(
            'Cannot move from picking to on hold.',
        );
        expect(screen.getByRole('alert')).toHaveTextContent(
            'orders.admin.status_update.refused',
        );
        expect(
            (screen.getByRole('radio', { name: /on_hold/ }) as HTMLInputElement)
                .checked,
        ).toBe(true);
        expect(screen.getByRole('textbox')).toHaveValue('Stock check');
        expect(save()).toBeEnabled();
    });

    it('still shows a refusal after the page refreshes to a track with no moves left', () => {
        const { rerender } = panel();

        choose('packing');
        fireEvent.click(save());
        act(() =>
            requests[0].fail({
                status: 'Cannot move from fulfilled to packing.',
            }),
        );

        // The server's answer carries the track's new state: it is final.
        rerender(
            <OrderStatusUpdate
                orderId="01ORDER"
                lifecycle={lifecycle({ fulfillment: axis('fulfilled', []) })}
                isStoreOrder={false}
                shipments={[]}
                canManageShipments={false}
            />,
        );

        expect(screen.getByRole('alert')).toHaveTextContent(
            'Cannot move from fulfilled to packing.',
        );
        expect(
            screen.getByText('orders.admin.status_update.final'),
        ).toBeInTheDocument();
    });

    it('drops a choice the server no longer offers, so it cannot be sent again', () => {
        const { rerender } = panel();

        choose('packing');
        rerender(
            <OrderStatusUpdate
                orderId="01ORDER"
                lifecycle={lifecycle({
                    fulfillment: axis('picking', ['on_hold']),
                })}
                isStoreOrder={false}
                shipments={[]}
                canManageShipments={false}
            />,
        );

        expect(save()).toBeDisabled();
        expect(save()).toHaveTextContent(
            'orders.admin.status_update.save_choose',
        );
    });

    it('shows a note the server rejects beside the note', () => {
        panel();

        choose('packing');
        fireEvent.click(save());
        act(() => requests[0].fail({ reason: 'The note is too long.' }));

        expect(screen.getByText('The note is too long.')).toBeInTheDocument();
    });

    it('lets the choice be cleared', () => {
        panel();

        choose('on_hold');
        fireEvent.click(
            screen.getByRole('button', {
                name: 'orders.admin.status_update.clear',
            }),
        );

        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        expect(save()).toBeDisabled();
    });

    it('is a plain button for a cancellation, not a primary one', () => {
        panel({
            lifecycle: lifecycle({
                fulfillment: axis('processing', ['cancelled']),
            }),
        });

        choose('cancelled');

        expect(save().className).toMatch(/destructive/);
    });
});

describe('when there is nothing to change', () => {
    it('says a finished track is final rather than showing an empty form', () => {
        panel({
            lifecycle: lifecycle({ fulfillment: axis('fulfilled', []) }),
        });
        track('orders.admin.lifecycle.fulfilment_label');

        expect(
            screen.getByText('orders.admin.status_update.final'),
        ).toBeInTheDocument();
        expect(screen.queryAllByRole('radio')).toHaveLength(0);
    });

    it('says so, and offers no moves, to someone who may not change it', () => {
        panel({
            lifecycle: lifecycle({
                fulfillment: axis('picking', [], { can_manage: false }),
                delivery: axis('not_shipped', [], { can_manage: false }),
                courier: axis('unassigned', [], { can_manage: false }),
            }),
        });

        expect(
            screen.getByText('orders.admin.status_update.no_permission'),
        ).toBeInTheDocument();
        expect(screen.queryAllByRole('radio')).toHaveLength(0);
    });

    it('sends a Store Order to its own page instead of offering moves the server would refuse', () => {
        panel({ isStoreOrder: true });

        expect(
            screen.getByText('orders.admin.status_update.store_order_title'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', {
                name: 'orders.admin.status_update.store_order_link',
            }),
        ).toHaveAttribute('href', expect.stringContaining('01ORDER'));
        expect(screen.queryAllByRole('radio')).toHaveLength(0);
        expect(
            screen.queryByRole('button', { name: /status_update\.save/ }),
        ).not.toBeInTheDocument();
    });
});

describe('one step at a time', () => {
    const locked =
        'Fulfilment is Packing. Delivery and the courier can start only once fulfilment is Ready for dispatch.';

    const waiting = () =>
        lifecycle({
            fulfillment: axis('packing', ['ready_for_dispatch', 'on_hold']),
            delivery: axis('not_shipped', ['on_hold', 'cancelled'], {
                locked_reason: locked,
            }),
            courier: axis('unassigned', ['cancelled'], {
                locked_reason: locked,
            }),
        });

    it('numbers the tracks and marks the ones that cannot start yet', () => {
        panel({ lifecycle: waiting() });

        const tabs = screen.getAllByRole('button', { pressed: false });

        expect(tabs[0]).toHaveTextContent('2.');
        expect(tabs[0]).toHaveTextContent(
            'orders.admin.status_update.locked_tag',
        );
        expect(tabs[1]).toHaveTextContent('3.');
        expect(tabs[1]).toHaveTextContent(
            'orders.admin.status_update.locked_tag',
        );
        expect(screen.getByRole('button', { pressed: true })).toHaveTextContent(
            '1.',
        );
    });

    it('starts on fulfilment, because that is where the work is', () => {
        panel({ lifecycle: waiting() });

        expect(
            screen.getByText(
                'orders.admin.status_update.effects.order_fulfillment.ready_for_dispatch',
            ),
        ).toBeInTheDocument();
    });

    it('says why a later track is waiting, in the server’s words, and takes you back to fulfilment', () => {
        panel({ lifecycle: waiting() });

        track('orders.admin.lifecycle.delivery_label');

        expect(screen.getByRole('note')).toHaveTextContent(locked);
        expect(screen.getByRole('note')).toHaveTextContent(
            'orders.admin.status_update.locked_title',
        );
        // Only what the server still allows: holding or cancelling.
        expect(screen.getAllByRole('radio')).toHaveLength(2);
        expect(
            screen.queryByText(
                'orders.admin.status_update.effects.order_delivery.courier_assigned',
            ),
        ).not.toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', {
                name: 'orders.admin.status_update.go_to',
            }),
        );

        expect(
            screen.getByText(
                'orders.admin.status_update.effects.order_fulfillment.ready_for_dispatch',
            ),
        ).toBeInTheDocument();
    });

    it('offers to continue to the next track once the step is saved and it has opened up', () => {
        const { rerender } = panel({ lifecycle: waiting() });

        choose('ready_for_dispatch');
        fireEvent.click(save());
        act(() => requests[0].succeed());

        // The server's answer: fulfilment is ready, so delivery has opened.
        rerender(
            <OrderStatusUpdate
                orderId="01ORDER"
                lifecycle={lifecycle({
                    fulfillment: axis('ready_for_dispatch', [
                        'fulfilled',
                        'on_hold',
                    ]),
                    delivery: axis('not_shipped', [
                        'courier_assigned',
                        'on_hold',
                        'cancelled',
                    ]),
                    courier: axis('unassigned', ['cancelled'], {
                        locked_reason: locked,
                    }),
                })}
                isStoreOrder={false}
                shipments={[]}
                canManageShipments={false}
            />,
        );

        expect(screen.getByRole('status')).toHaveTextContent(
            'orders.admin.status_update.continue_title',
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'orders.admin.status_update.continue_to',
            }),
        );

        expect(
            screen.getByText(
                'orders.admin.status_update.effects.order_delivery.courier_assigned',
            ),
        ).toBeInTheDocument();
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    it('does not offer to continue while the next track is still waiting', () => {
        panel({ lifecycle: waiting() });

        choose('on_hold');
        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: 'Stock check' },
        });
        fireEvent.click(save());
        act(() => requests[0].succeed());

        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });
});

describe('the courier behind a delivery update', () => {
    const shipment = {
        id: '01SHIPMENT',
        reference: 'SHP-1',
        provider: 'Steadfast Courier',
        status: 'in_transit',
        status_label: 'In transit',
        status_tone: 'info' as const,
        tracking_number: '1424107',
    };

    it('shows which courier and which consignment, with a way to the shipment', () => {
        panel({ shipments: [shipment] });
        track('orders.admin.lifecycle.courier_label');

        expect(screen.getByText('Steadfast Courier')).toBeInTheDocument();
        expect(screen.getByText('1424107')).toBeInTheDocument();
        expect(
            screen.getByRole('link', {
                name: 'orders.admin.status_update.open_shipment',
            }),
        ).toHaveAttribute('href', expect.stringContaining('01SHIPMENT'));
    });

    it('says what is missing when no shipment exists, and where to make one', () => {
        panel({ canManageShipments: true });
        track('orders.admin.lifecycle.courier_label');

        expect(
            screen.getByText('orders.admin.status_update.no_shipments_hint'),
        ).toBeInTheDocument();
    });

    it('does not point somebody without shipment permission at a section they cannot see', () => {
        panel({ canManageShipments: false });
        track('orders.admin.lifecycle.courier_label');

        expect(
            screen.getByText('orders.admin.status_update.no_shipments'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('orders.admin.status_update.no_shipments_hint'),
        ).not.toBeInTheDocument();
    });

    it('is not shown for the fulfilment track', () => {
        panel({ shipments: [shipment] });

        expect(screen.queryByText('Steadfast Courier')).not.toBeInTheDocument();
    });
});
