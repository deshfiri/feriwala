// @vitest-environment jsdom

import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { SmsEventRow } from './sms';

const { router } = vi.hoisted(() => ({ router: { put: vi.fn() } }));

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    return {
        Head: () => null,
        Form: ({ children }: { children: (props: unknown) => unknown }) =>
            createElement(
                'form',
                null,
                children({ errors: {}, processing: false }) as never,
            ),
        router,
        usePage: () => ({ props: { translations: {}, permissions: {} } }),
    };
});

const { default: AdminSms } = await import('./sms');

const smsEvents: SmsEventRow[] = [
    {
        event: 'account.activated',
        title: 'Account activated',
        description: 'Sent when approved.',
        switchable: true,
        enabled: true,
        sent: 3,
    },
    {
        event: 'payment.received',
        title: 'Payment received',
        description: 'Sent when paid.',
        switchable: true,
        enabled: false,
        sent: 0,
    },
    {
        event: 'mobile_verification',
        title: 'Mobile verification code',
        description: 'One-time code.',
        switchable: false,
        enabled: true,
        sent: 12,
    },
];

function renderSmsPage(manage = true) {
    return render(
        <AdminSms
            settings={{ enabled: true, provider: 'log', can_send: true }}
            providers={[{ name: 'log', is_implemented: true, is_active: true }]}
            messages={[]}
            events={smsEvents}
            can={{ manage }}
        />,
    );
}

function smsEventSwitch(event: string): HTMLElement {
    const row = document.querySelector<HTMLElement>(
        `[data-test="sms-event-${event}"]`,
    );

    if (!row) {
        throw new Error(`No row for ${event}`);
    }

    return within(row).getByRole('switch');
}

/**
 * One switch per SMS event, posting to the server's toggle; one-time codes
 * are shown locked on.
 */
describe('the per-event SMS switches', () => {
    beforeEach(() => router.put.mockReset());

    it('shows each event with its current state', () => {
        renderSmsPage();

        expect(smsEventSwitch('account.activated')).toHaveAttribute(
            'aria-checked',
            'true',
        );
        expect(smsEventSwitch('payment.received')).toHaveAttribute(
            'aria-checked',
            'false',
        );
    });

    it('locks one-time codes on, and says why', () => {
        renderSmsPage();

        expect(smsEventSwitch('mobile_verification')).toBeDisabled();
        expect(smsEventSwitch('mobile_verification')).toHaveAttribute(
            'aria-checked',
            'true',
        );
        expect(
            screen.getByText('sms.event_switch.always_on'),
        ).toBeInTheDocument();
    });

    it('sends the flipped state for that event', () => {
        renderSmsPage();

        fireEvent.click(smsEventSwitch('account.activated'));

        expect(router.put).toHaveBeenCalledTimes(1);
        expect(router.put.mock.calls[0][0]).toBe('/admin/sms/events');
        expect(router.put.mock.calls[0][1]).toEqual({
            event: 'account.activated',
            enabled: false,
        });
    });

    it('is read-only without permission to manage SMS', () => {
        renderSmsPage(false);

        screen
            .getAllByRole('switch')
            .forEach((toggle) => expect(toggle).toBeDisabled());
        expect(
            screen.getByText('sms.event_switch.read_only'),
        ).toBeInTheDocument();
    });
});
