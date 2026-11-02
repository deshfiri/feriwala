import { describe, expect, it } from 'vitest';
import {
    HAPPY_PATH,
    axesNeedingAttention,
    progressOf,
    usualNextStep,
    type OrderLifecycle,
} from './order-lifecycle';

const axis = (status: string) => ({
    status,
    status_label: status,
    status_tone: 'info' as const,
    is_terminal: false,
    can_manage: true,
    available_actions: [],
    history: [],
});

describe('where a status sits on its track', () => {
    it('numbers the usual path and says when it is complete', () => {
        expect(progressOf('fulfillment', 'pending_review')).toEqual({
            kind: 'path',
            step: 1,
            total: 7,
            complete: false,
        });
        expect(progressOf('fulfillment', 'packing')).toMatchObject({
            step: 5,
            complete: false,
        });
        expect(progressOf('fulfillment', 'fulfilled')).toMatchObject({
            step: 7,
            complete: true,
        });
        expect(progressOf('delivery', 'delivered')).toMatchObject({
            complete: true,
        });
        expect(progressOf('courier', 'picked_up')).toMatchObject({ step: 4 });
    });

    it('puts a supplier confirmation at the same step as the allocation it follows', () => {
        expect(
            progressOf('fulfillment', 'supplier_confirmation_pending'),
        ).toEqual(progressOf('fulfillment', 'source_allocation_pending'));
    });

    it.each([
        ['fulfillment', 'on_hold'],
        ['fulfillment', 'cancelled'],
        ['fulfillment', 'partially_fulfilled'],
        ['delivery', 'failed_delivery'],
        ['delivery', 'return_requested'],
        ['delivery', 'refunded'],
        ['courier', 'returned_to_origin'],
    ] as const)('calls %s %s an exception, not a position', (track, status) => {
        expect(progressOf(track, status)).toEqual({ kind: 'exception' });
    });

    it('has every path status as a real status of its track', () => {
        // The paths are the only hand-written lists here; a typo would show as
        // an exception for a perfectly normal status.
        for (const status of HAPPY_PATH.delivery) {
            expect(progressOf('delivery', status).kind).toBe('path');
        }
    });
});

describe('the usual next step', () => {
    it('is the next step on the path, when the server allows it', () => {
        expect(
            usualNextStep('fulfillment', {
                status: 'picking',
                available_actions: ['packing', 'on_hold'],
            }),
        ).toBe('packing');
    });

    it('skips the optional supplier step rather than steering towards it', () => {
        expect(
            usualNextStep('fulfillment', {
                status: 'source_allocation_pending',
                available_actions: [
                    'supplier_confirmation_pending',
                    'processing',
                    'on_hold',
                    'cancelled',
                ],
            }),
        ).toBe('processing');
    });

    it('offers nothing the server did not offer', () => {
        expect(
            usualNextStep('delivery', {
                status: 'shipped',
                available_actions: ['failed_delivery'],
            }),
        ).toBeNull();
    });

    it('has no usual next step from an exception', () => {
        expect(
            usualNextStep('fulfillment', {
                status: 'on_hold',
                available_actions: ['processing', 'picking'],
            }),
        ).toBeNull();
    });
});

describe('what needs a person', () => {
    it('lists the tracks on hold, failed, returning or partly done', () => {
        const lifecycle: OrderLifecycle = {
            fulfillment: axis('on_hold'),
            delivery: axis('failed_delivery'),
            courier: axis('in_transit'),
        };

        expect(axesNeedingAttention(lifecycle)).toEqual([
            'fulfillment',
            'delivery',
        ]);
    });

    it('lists nothing when everything is on its normal path', () => {
        expect(
            axesNeedingAttention({
                fulfillment: axis('packing'),
                delivery: axis('not_shipped'),
                courier: axis('unassigned'),
            }),
        ).toEqual([]);
    });
});
