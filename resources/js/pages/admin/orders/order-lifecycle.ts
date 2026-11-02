import OrderController from '@/actions/App/Http/Controllers/Admin/OrderController';
import type { StatusTone } from '@/lib/status';

export type LifecycleAxis = 'fulfillment' | 'delivery' | 'courier';

export type LifecycleAxisSummary = {
    status: string;
    status_label: string;
    status_tone: StatusTone;
    is_terminal: boolean;
    can_manage: boolean;
    available_actions: string[];
    history: {
        previous_status: string | null;
        new_status: string;
        changed_by: string | null;
        changed_at: string;
        reason: string | null;
    }[];
};

export type OrderLifecycle = Record<LifecycleAxis, LifecycleAxisSummary>;

export const AXES: LifecycleAxis[] = ['fulfillment', 'delivery', 'courier'];

export const STATUS_GROUP = {
    fulfillment: 'order_fulfillment',
    delivery: 'order_delivery',
    courier: 'order_courier',
} as const;

export const AXIS_LABEL_KEY: Record<LifecycleAxis, string> = {
    fulfillment: 'orders.admin.lifecycle.fulfilment_label',
    delivery: 'orders.admin.lifecycle.delivery_label',
    courier: 'orders.admin.lifecycle.courier_label',
};

/**
 * Target statuses that always need a note — mirrors each Advance action's own
 * `REASON_REQUIRED`, which is what the server enforces; this only lets the form
 * say so before the request is made.
 */
export const REASON_REQUIRED: Record<LifecycleAxis, string[]> = {
    fulfillment: ['on_hold', 'cancelled'],
    delivery: ['on_hold', 'cancelled', 'failed_delivery', 'return_requested'],
    courier: ['cancelled', 'failed_delivery'],
};

/** Each axis's own update route; the server decides what is allowed. */
export const AXIS_ROUTE = {
    fulfillment: (order: string) =>
        OrderController.advanceFulfilmentStatus({ order }),
    delivery: (order: string) =>
        OrderController.advanceDeliveryStatus({ order }),
    courier: (order: string) => OrderController.advanceCourierStatus({ order }),
} as const;

/**
 * The usual way through each axis. Anything off this path — a hold, a failed
 * attempt, a return — is an exception, shown as one rather than as a position.
 */
export const HAPPY_PATH: Record<LifecycleAxis, string[]> = {
    fulfillment: [
        'pending_review',
        'source_allocation_pending',
        'processing',
        'picking',
        'packing',
        'ready_for_dispatch',
        'fulfilled',
    ],
    delivery: [
        'not_shipped',
        'courier_assigned',
        'shipped',
        'in_transit',
        'out_for_delivery',
        'delivered',
    ],
    courier: [
        'unassigned',
        'assigned',
        'pickup_requested',
        'picked_up',
        'in_transit',
        'delivered',
    ],
};

/** A status that sits at the same step of the path as another. */
const SAME_STEP: Partial<Record<LifecycleAxis, Record<string, string>>> = {
    fulfillment: {
        supplier_confirmation_pending: 'source_allocation_pending',
    },
};

/** Statuses a person should look at, beyond the ones that are simply final. */
const NEEDS_ATTENTION = [
    'on_hold',
    'failed_delivery',
    'return_requested',
    'partially_fulfilled',
];

export type AxisProgress =
    | { kind: 'path'; step: number; total: number; complete: boolean }
    | { kind: 'exception' };

/**
 * Where a status sits on its axis's usual path.
 */
export function progressOf(axis: LifecycleAxis, status: string): AxisProgress {
    const path = HAPPY_PATH[axis];
    const index = path.indexOf(SAME_STEP[axis]?.[status] ?? status);

    if (index === -1) {
        return { kind: 'exception' };
    }

    return {
        kind: 'path',
        step: index + 1,
        total: path.length,
        complete: index === path.length - 1,
    };
}

/**
 * The usual next step, when the server allows it from here: the first status
 * after this one on the path that is among the moves on offer.
 */
export function usualNextStep(
    axis: LifecycleAxis,
    summary: Pick<LifecycleAxisSummary, 'status' | 'available_actions'>,
): string | null {
    const path = HAPPY_PATH[axis];
    const index = path.indexOf(
        SAME_STEP[axis]?.[summary.status] ?? summary.status,
    );

    if (index === -1) {
        return null;
    }

    return (
        path
            .slice(index + 1)
            .find((status) => summary.available_actions.includes(status)) ??
        null
    );
}

/** The axes whose current status needs a person to look at it. */
export function axesNeedingAttention(
    lifecycle: OrderLifecycle,
): LifecycleAxis[] {
    return AXES.filter((axis) =>
        NEEDS_ATTENTION.includes(lifecycle[axis].status),
    );
}
