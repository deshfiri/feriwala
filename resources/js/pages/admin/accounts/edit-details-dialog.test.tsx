// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    return {
        Form: ({ children }: { children: (s: unknown) => unknown }) =>
            createElement(
                'form',
                null,
                children({ processing: false, errors: {} }) as never,
            ),
        usePage: () => ({ props: { translations: {} } }),
    };
});

const { default: EditDetailsDialog } = await import('./edit-details-dialog');

const details = {
    business_name: 'Rahim Traders',
    name: 'Rahim Uddin',
    email: 'rahim@example.com',
    mobile: '+8801712345678',
    date_of_birth: '1990-05-17',
    gender: 'male',
    country: 'BD',
    nationality: 'Bangladeshi',
    countries: [
        { value: 'BD', label: 'Bangladesh' },
        { value: 'IN', label: 'India' },
    ],
    genders: [
        { value: 'male', label: 'Male' },
        { value: 'female', label: 'Female' },
    ],
};

describe('the edit partner details dialog', () => {
    it('starts from the stored values, with the login fields required', () => {
        render(
            <EditDetailsDialog
                accountId="01ACCOUNT"
                details={details}
                open
                onOpenChange={() => {}}
            />,
        );

        const field = (name: string) =>
            screen.getByLabelText(`account.edit_details.fields.${name}`);

        expect(field('business_name')).toHaveValue('Rahim Traders');
        expect(field('email')).toHaveValue('rahim@example.com');
        expect(field('email')).toBeRequired();
        expect(field('mobile')).toHaveValue('+8801712345678');
        expect(field('date_of_birth')).toHaveValue('1990-05-17');
        expect(field('gender')).toHaveValue('male');
        expect(field('country')).toHaveValue('BD');
        expect(field('nationality')).toHaveValue('Bangladeshi');
    });
});
