// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ComponentProps } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/hooks/use-translation', () => ({
    useTranslation: () => ({
        t: (key: string) => key,
        locale: 'en',
        direction: 'ltr',
        available: [],
    }),
}));

const { default: LocationPicker } = await import('./location-picker');

const DIVISIONS = [{ id: 1, source_id: '10', name_en: 'Dhaka', name_bn: 'ঢাকা' }];
const DISTRICTS = [
    { id: 2, source_id: '20', name_en: 'Gazipur', name_bn: 'গাজীপুর' },
];
const UPAZILAS = [
    { id: 3, source_id: '30', name_en: 'Sreepur', name_bn: 'শ্রীপুর' },
];

/**
 * Serves the same fixed tree regardless of caller, keyed only on which
 * segment of the URL is present — enough to prove the cascading behaviour
 * without needing a real endpoint.
 */
function stubFetch() {
    return vi.fn((url: string) => {
        let data: unknown[] = [];

        if (url.includes('divisions')) {
            data = DIVISIONS;
        } else if (url.includes('/division/')) {
            data = DISTRICTS;
        } else if (url.includes('/district/')) {
            data = UPAZILAS;
        } else if (url.includes('/upazila/')) {
            data = [];
        }

        return Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ data }),
        });
    });
}

beforeEach(() => {
    global.fetch = stubFetch() as unknown as typeof fetch;
});

function renderPicker(
    props: Partial<ComponentProps<typeof LocationPicker>> = {},
) {
    return render(
        <LocationPicker
            divisionsUrl="/locations/divisions"
            childrenUrl={(parentType, parentSourceId) =>
                `/locations/${parentType}/${parentSourceId}/children`
            }
            {...props}
        />,
    );
}

describe('LocationPicker', () => {
    it('disables District, Upazila and Union until their parent is chosen', () => {
        renderPicker();

        expect(screen.getByLabelText('address.fields.district')).toBeDisabled();
        expect(screen.getByLabelText('address.fields.upazila')).toBeDisabled();
        expect(screen.getByLabelText('address.fields.union')).toBeDisabled();
    });

    it('loads districts once a division is chosen, and enables the field', async () => {
        renderPicker();

        const divisionSelect = await screen.findByLabelText(
            'address.fields.division',
        );
        expect(
            await screen.findByRole('option', { name: 'Dhaka' }),
        ).toBeInTheDocument();

        await userEvent.selectOptions(divisionSelect, '1');

        expect(
            await screen.findByRole('option', { name: 'Gazipur' }),
        ).toBeInTheDocument();
        expect(
            screen.getByLabelText('address.fields.district'),
        ).not.toBeDisabled();
    });

    it('resets District, Upazila and Union when a different Division is chosen', async () => {
        renderPicker();

        const divisionSelect = await screen.findByLabelText(
            'address.fields.division',
        );
        await userEvent.selectOptions(divisionSelect, '1');

        const districtSelect = await screen.findByLabelText(
            'address.fields.district',
        );
        await userEvent.selectOptions(districtSelect, '2');
        expect(
            await screen.findByRole('option', { name: 'Sreepur' }),
        ).toBeInTheDocument();

        // Re-choosing the division (even the same one) clears everything below it.
        await userEvent.selectOptions(divisionSelect, '1');

        expect(screen.getByLabelText('address.fields.district')).toHaveValue(
            '',
        );
        expect(screen.getByLabelText('address.fields.upazila')).toBeDisabled();
    });

    it("prefills from `initial` and fetches that chain's own children on mount", async () => {
        renderPicker({
            initial: {
                division: DIVISIONS[0],
                district: DISTRICTS[0],
                upazila: null,
                union: null,
            },
        });

        expect(
            await screen.findByRole('option', { name: 'Sreepur' }),
        ).toBeInTheDocument();
        expect(screen.getByLabelText('address.fields.district')).toHaveValue(
            '2',
        );
        expect(
            screen.getByLabelText('address.fields.upazila'),
        ).not.toBeDisabled();
    });

    it('shows a field error passed in from the server', () => {
        renderPicker({
            errors: { division_id: 'The division field is required.' },
        });

        expect(
            screen.getByText('The division field is required.'),
        ).toBeInTheDocument();
    });
});
