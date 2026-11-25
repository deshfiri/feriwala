// @vitest-environment jsdom

import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { translations: {} } }),
}));

const { ConfirmProvider } = await import('./confirm-provider');
const { default: ConfirmButton } = await import('./confirm-button');

describe('a dangerous button', () => {
    it('asks "Are you sure?" and does nothing until confirmed', async () => {
        const onClick = vi.fn();

        render(
            <ConfirmProvider>
                <ConfirmButton onClick={onClick}>Delete</ConfirmButton>
            </ConfirmProvider>,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));

        expect(onClick).not.toHaveBeenCalled();
        expect(await screen.findByText('common.confirm.title')).toBeTruthy();

        // The dialog's confirm button carries the action's own label.
        const buttons = screen.getAllByRole('button', { name: 'Delete' });
        fireEvent.click(buttons[buttons.length - 1]);

        await vi.waitFor(() => expect(onClick).toHaveBeenCalledTimes(1));
    });

    it('does nothing when the person says no', async () => {
        const onClick = vi.fn();

        render(
            <ConfirmProvider>
                <ConfirmButton onClick={onClick}>Cancel order</ConfirmButton>
            </ConfirmProvider>,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Cancel order' }));
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'common.actions.cancel',
            }),
        );

        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(onClick).not.toHaveBeenCalled();
    });

    it('submits its form only after confirmation, with its own name and value', async () => {
        const submitted = vi.fn();

        render(
            <ConfirmProvider>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        const submitter = (event.nativeEvent as SubmitEvent)
                            .submitter as HTMLButtonElement | null;
                        submitted(submitter?.name, submitter?.value);
                    }}
                >
                    <ConfirmButton type="submit" name="decision" value="reject">
                        Reject
                    </ConfirmButton>
                </form>
            </ConfirmProvider>,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Reject' }));
        expect(submitted).not.toHaveBeenCalled();

        const buttons = await screen.findAllByRole('button', {
            name: 'Reject',
        });
        fireEvent.click(buttons[buttons.length - 1]);

        await vi.waitFor(() =>
            expect(submitted).toHaveBeenCalledWith('decision', 'reject'),
        );
        expect(submitted).toHaveBeenCalledTimes(1);
    });
});
