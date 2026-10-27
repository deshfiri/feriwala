// @vitest-environment jsdom

import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { TimelineEntry } from '@/types/cases';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { translations: {}, locale: { current: 'en' } } }),
}));

const { default: CaseTimeline } = await import('./case-timeline');

const entries: TimelineEntry[] = [
    {
        key: 0,
        kind: 'status',
        event: 'Requested',
        actor_type: 'partner',
        actor: null,
        at: '2026-10-20T09:00:00+06:00',
        previous: null,
        next: 'Requested',
        note: 'The size is wrong.',
    },
    {
        key: 1,
        kind: 'responsibility',
        event: 'Responsibility changed',
        actor_type: 'staff',
        actor: 'Rafiq',
        at: '2026-10-21T09:00:00+06:00',
        previous: 'Partner',
        next: 'Banij',
        note: 'Packed badly at the warehouse.',
    },
];

describe('the case timeline', () => {
    it('says what happened, from what to what, who by kind of party, and why', () => {
        render(<CaseTimeline entries={entries} />);

        const list = screen.getByRole('list', { name: 'cases.timeline.title' });
        const moved = within(list).getAllByRole('listitem')[1];

        expect(moved).toHaveTextContent('Responsibility changed');
        expect(moved).toHaveTextContent('Partner → Banij');
        expect(moved).toHaveTextContent('cases.actors.staff (Rafiq)');
        expect(moved).toHaveTextContent('Packed badly at the warehouse.');
    });

    it('never needs colour to tell the parties apart: each is named in words', () => {
        render(<CaseTimeline entries={entries} />);

        expect(screen.getByText(/cases\.actors\.partner/)).toBeVisible();
        expect(screen.getByText(/cases\.actors\.staff/)).toBeVisible();
    });

    it('shows a Partner no staff name when the server sent none', () => {
        render(
            <CaseTimeline
                entries={[{ ...entries[1], actor: null, note: null }]}
            />,
        );

        expect(screen.queryByText(/Rafiq/)).toBeNull();
        expect(screen.getByText(/cases\.actors\.staff/)).toBeVisible();
    });

    it('says so when nothing has happened yet', () => {
        render(<CaseTimeline entries={[]} />);

        expect(screen.getByText('cases.timeline.empty')).toBeVisible();
    });
});
