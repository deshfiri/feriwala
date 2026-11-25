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

const { ConfirmProvider } =
    await import('@/components/confirm/confirm-provider');
const { default: AdminSms } = await import('./sms');

const smsEvents: SmsEventRow[] = [
    {
        event: 'account.activated',
        title: 'Account activated',
        description: 'Sent when approved.',
        one_time_code: false,
        enabled: true,
        sent: 3,
    },
    {
        event: 'payment.received',
        title: 'Payment received',
        description: 'Sent when paid.',
        one_time_code: false,
        enabled: false,
        sent: 0,
    },
    {
        event: 'mobile_verification',
        title: 'Mobile verification code',
        description: 'One-time code.',
        one_time_code: true,
        enabled: true,
        sent: 12,
    },
];

/** Answers the app's "Are you sure?" with Confirm. */
async function smsConfirmDialog(): Promise<void> {
    fireEvent.click(
        await screen.findByRole('button', { name: 'common.actions.confirm' }),
    );
}

function renderSmsPage(manage = true) {
    return render(
        <ConfirmProvider>
            <AdminSms
                settings={{
                    enabled: true,
                    provider: 'log',
                    can_send: true,
                    storefront_verification_required: false,
                }}
                providers={[
                    {
                        name: 'log',
                        is_implemented: true,
                        is_active: true,
                        is_configured: true,
                        required_configuration: [],
                        missing_configuration: [],
                    },
                ]}
                messages={[]}
                events={smsEvents}
                can={{ manage }}
            />
        </ConfirmProvider>,
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

    it('warns on a one-time code and asks, in words, before switching it off', async () => {
        renderSmsPage();

        expect(smsEventSwitch('mobile_verification')).toBeEnabled();
        expect(
            screen.getByText('sms.event_switch.code_warning'),
        ).toBeInTheDocument();

        fireEvent.click(smsEventSwitch('mobile_verification'));

        // Declined: nothing is sent.
        expect(
            await screen.findByText('sms.event_switch.confirm_code_off'),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'common.actions.cancel' }),
        );
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(router.put).not.toHaveBeenCalled();

        fireEvent.click(smsEventSwitch('mobile_verification'));
        await smsConfirmDialog();

        await vi.waitFor(() => expect(router.put).toHaveBeenCalledTimes(1));
        expect(router.put.mock.calls[0][1]).toEqual({
            event: 'mobile_verification',
            enabled: false,
        });
    });

    it('asks before switching an ordinary event off too', async () => {
        renderSmsPage();

        fireEvent.click(smsEventSwitch('account.activated'));

        expect(
            await screen.findByText('common.confirm.description'),
        ).toBeInTheDocument();
        expect(router.put).not.toHaveBeenCalled();
    });

    it('does not ask before switching an event on', async () => {
        renderSmsPage();

        fireEvent.click(smsEventSwitch('payment.received'));

        await vi.waitFor(() => expect(router.put).toHaveBeenCalledTimes(1));
        expect(screen.queryByText('common.confirm.title')).toBeNull();
        expect(router.put.mock.calls[0][1]).toEqual({
            event: 'payment.received',
            enabled: true,
        });
    });

    it('sends the flipped state for that event', async () => {
        renderSmsPage();

        fireEvent.click(smsEventSwitch('account.activated'));
        await smsConfirmDialog();

        await vi.waitFor(() => expect(router.put).toHaveBeenCalledTimes(1));
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
