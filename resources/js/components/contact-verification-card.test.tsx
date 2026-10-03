// @vitest-environment jsdom

import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const translations = {
    managed_accounts: {
        fields: {
            email: 'Email',
            mobile: 'Mobile',
            reason: 'Reason',
            reason_help: 'Recorded.',
        },
        send_link: 'Send setup link',
        revoke_link: 'Revoke setup link',
        contact: {
            title: 'Email & mobile',
            verified: 'Verified',
            by_staff: 'Verified by staff',
            not_verified: 'Not verified',
            confirm: 'Confirm manually',
            confirm_title: 'Confirm on their behalf',
            confirm_help: 'Recorded as staff-verified.',
            setup_title: 'Password-setup link',
            setup_help: 'No password shown.',
        },
    },
};

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    return {
        Form: ({ children }: { children: (s: unknown) => unknown }) =>
            createElement(
                'form',
                null,
                children({ processing: false, errors: {} }) as never,
            ),
        usePage: () => ({ props: { translations, locale: { current: 'en' } } }),
    };
});

const { default: ContactVerificationCard } =
    await import('./contact-verification-card');

const channel = (overrides = {}) => ({
    verified: false,
    verified_at: null,
    by_staff: false,
    verified_by: null,
    reason: null,
    ...overrides,
});

const target = { action: '/x', method: 'post' as const };

const renderCard = (props = {}) =>
    render(
        <ContactVerificationCard
            contact={{
                email: channel({
                    verified: true,
                    verified_at: '2026-10-01T10:00:00Z',
                }),
                mobile: channel(),
            }}
            verifyForm={target}
            sendLinkForm={target}
            revokeLinkForm={target}
            canVerify
            canManageSetup
            {...props}
        />,
    );

describe('the contact verification card', () => {
    it('distinguishes verified, staff-verified and unverified channels', () => {
        renderCard({
            contact: {
                email: channel({
                    verified: true,
                    verified_at: '2026-10-01T10:00:00Z',
                    by_staff: true,
                    verified_by: 'Nadia',
                    reason: 'Confirmed by phone.',
                }),
                mobile: channel(),
            },
        });

        expect(screen.getByText('Verified by staff')).toBeInTheDocument();
        expect(screen.getByText('Not verified')).toBeInTheDocument();
        expect(screen.getByText('Confirmed by phone.')).toBeInTheDocument();
    });

    it('offers manual confirmation only for an unverified channel, and asks for a reason', () => {
        renderCard();

        const confirm = screen.getAllByRole('button', {
            name: 'Confirm manually',
        });

        // Only the unverified mobile row has the button.
        expect(confirm).toHaveLength(1);

        fireEvent.click(confirm[0]);

        expect(screen.getByText('Confirm on their behalf')).toBeInTheDocument();
        expect(
            within(screen.getByRole('dialog')).getByLabelText(/Reason/),
        ).toBeRequired();
    });

    it('hides every action from someone without the permissions', () => {
        renderCard({ canVerify: false, canManageSetup: false });

        expect(
            screen.queryByRole('button', { name: 'Confirm manually' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Send setup link' }),
        ).not.toBeInTheDocument();
    });

    it('never offers a password field', () => {
        renderCard();

        expect(screen.queryByLabelText(/password/i)).not.toBeInTheDocument();
        expect(document.querySelector('input[type="password"]')).toBeNull();
    });
});
