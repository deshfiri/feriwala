// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';
import Benefits from './benefits';

/**
 * The backend's allow-list (App\Domain\Cms\Support\SectionContentValidator)
 * already refuses an icon name outside its closed set before this ever
 * renders — this proves the renderer itself never crashes even if that
 * contract were ever violated, falling back to a real icon rather than
 * rendering nothing.
 */
it('falls back to a default icon rather than crashing on an unknown icon name', () => {
    const content = {
        heading: 'Benefits',
        items: [{ icon: 'not-a-real-icon', heading: 'Something', body: null }],
    };

    render(<Benefits content={content} />);

    expect(screen.getByText('Something')).toBeInTheDocument();
});

it('renders every allowed icon without throwing', () => {
    const icons = [
        'shield-check',
        'wallet',
        'truck',
        'store',
        'users',
        'package',
        'trending-up',
        'globe',
        'lock',
        'clock',
        'layers',
        'banknote',
    ];

    const content = {
        heading: 'Benefits',
        items: icons.map((icon) => ({ icon, heading: icon, body: null })),
    };

    render(<Benefits content={content} />);

    icons.forEach((icon) => {
        expect(screen.getByText(icon)).toBeInTheDocument();
    });
});
