// @vitest-environment jsdom

import { act, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            translations: {
                common: {
                    clipboard: {
                        copy: 'Copy :label',
                        copied: ':label :value copied',
                    },
                },
            },
        },
    }),
}));

const success = vi.fn();

vi.mock('sonner', () => ({ toast: { success: (m: string) => success(m) } }));

const { default: CopyableText } = await import('./copyable-text');

beforeEach(() => {
    success.mockClear();
});

describe('a copyable identifier', () => {
    it('shows the value and copies exactly that value when clicked', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.assign(navigator, { clipboard: { writeText } });

        render(<CopyableText value="BJ56987412548" label="Partner ID" />);

        expect(screen.getByText('BJ56987412548')).toBeInTheDocument();

        await act(async () => {
            screen.getByRole('button').click();
        });

        expect(writeText).toHaveBeenCalledTimes(1);
        expect(writeText).toHaveBeenCalledWith('BJ56987412548');
    });

    it('shows a toast naming the copied id', async () => {
        Object.assign(navigator, {
            clipboard: { writeText: vi.fn().mockResolvedValue(undefined) },
        });

        render(<CopyableText value="BJ56987412548" label="Partner ID" />);

        expect(success).not.toHaveBeenCalled();

        await act(async () => {
            screen.getByRole('button').click();
        });

        expect(success).toHaveBeenCalledTimes(1);
        expect(success).toHaveBeenCalledWith('Partner ID BJ56987412548 copied');
    });

    it('says nothing when the browser refuses to copy', async () => {
        Object.assign(navigator, {
            clipboard: {
                writeText: vi.fn().mockRejectedValue(new Error('denied')),
            },
        });
        vi.spyOn(console, 'warn').mockImplementation(() => undefined);

        render(<CopyableText value="BJ56987412548" label="Partner ID" />);

        await act(async () => {
            screen.getByRole('button').click();
        });

        expect(success).not.toHaveBeenCalled();
    });
});
