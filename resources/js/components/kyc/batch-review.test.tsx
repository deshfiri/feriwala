// @vitest-environment jsdom

import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import BatchReview from '@/components/kyc/batch-review';
import type { KycBatchForReview } from '@/types';

const post = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    router: { post, on: vi.fn(() => () => {}) },
}));

vi.mock('@/hooks/use-translation', () => ({
    useTranslation: () => ({
        t: (key: string, values?: Record<string, unknown>) =>
            values ? `${key} ${JSON.stringify(values)}` : key,
        locale: 'en',
    }),
}));

(
    globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
).IS_REACT_ACT_ENVIRONMENT = true;

const evidence = (slot: string, verdict: 'accepted' | 'rejected' | null) => ({
    requirement: 'passport',
    slot,
    kind: 'file' as const,
    document: `doc-${slot}`,
    name: `${slot}.jpg`,
    size_bytes: 2048,
    live: false,
    image: false,
    value_preview: null,
    verdict,
    note: verdict === 'rejected' ? 'Blurred.' : null,
});

const slot = (key: string, current: ReturnType<typeof evidence> | null) => ({
    key,
    label: `Slot ${key}`,
    instructions: null,
    required: true,
    evidence: 'image' as const,
    mimes: ['image/jpeg'],
    max_kb: 2048,
    value_label: null,
    sensitive: false,
    current,
});

function batch(
    overrides: Partial<KycBatchForReview['review']> = {},
): KycBatchForReview {
    return {
        name: 'Identity',
        description: null,
        version: 3,
        editable: false,
        complete: false,
        missing: [],
        selfie: null,
        groups: [
            {
                key: 'identity',
                name: 'Identity document',
                description: null,
                rule: 'all',
                choose: false,
                selector_label: null,
                selected: ['passport'],
                required: true,
                needed: 1,
                done: 0,
                offered: 1,
                satisfied: false,
                options: [
                    {
                        key: 'passport',
                        name: 'Passport',
                        instructions: null,
                        required: true,
                        chosen: true,
                        complete: false,
                        provided: 0,
                        total: 2,
                        has_draft: false,
                        locked: false,
                        slots: [
                            slot('front', evidence('front', null)),
                            slot('back', evidence('back', 'rejected')),
                        ],
                    },
                ],
            },
        ],
        review: {
            problems: ['Identity — Passport: Slot front — not reviewed yet'],
            corrections: ['Identity — Passport — Slot back: Blurred.'],
            awaits_review: true,
            can_judge: true,
            can_open_documents: true,
            can_view_sensitive: false,
            ...overrides,
        },
    };
}

let container: HTMLDivElement;
let root: Root;

function mount(value: KycBatchForReview): HTMLDivElement {
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);

    act(() => {
        root.render(
            <BatchReview
                batch={value}
                verdictUrl="/verdicts"
                documentUrl={(id) => `/documents/${id}`}
                revealUrl={() => '/reveal'}
            />,
        );
    });

    return container;
}

const button = (view: HTMLElement, label: string) =>
    [...view.querySelectorAll('button')].find((one) =>
        one.textContent?.includes(label),
    );

beforeEach(() => post.mockReset());

afterEach(() => {
    act(() => root.unmount());
    container.remove();
});

describe('BatchReview', () => {
    it('says what stands in the way of approval and what the applicant will be told', () => {
        const text = mount(batch()).textContent ?? '';

        expect(text).toContain('Slot front — not reviewed yet');
        expect(text).toContain('Identity — Passport — Slot back: Blurred.');
    });

    it('links a file only to the authorised document route', () => {
        const view = mount(batch());
        const link = view.querySelector('a[href^="/documents/"]');

        expect(link?.getAttribute('href')).toBe('/documents/doc-front');
    });

    it('accepts one slot with a single verdict', () => {
        const view = mount(batch());

        act(() => {
            button(view, 'kyc.review_ui.accept')?.click();
        });

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('/verdicts');
        expect(post.mock.calls[0][1]).toEqual({
            verdicts: [
                { requirement: 'passport', slot: 'front', status: 'accepted' },
            ],
        });
    });

    it('will not send a rejection without a note', () => {
        const view = mount(batch());

        act(() => {
            button(view, 'kyc.review_ui.reject')?.click();
        });

        expect(button(view, 'kyc.review_ui.confirm_reject')?.disabled).toBe(
            true,
        );
        expect(post).not.toHaveBeenCalled();
    });

    it('offers no controls to someone who may not review', () => {
        const view = mount(batch({ can_judge: false }));

        expect(button(view, 'kyc.review_ui.accept')).toBeUndefined();
        expect(button(view, 'kyc.review_ui.reject')).toBeUndefined();
    });

    it('shows no file link to someone who may not open documents', () => {
        const view = mount(batch({ can_open_documents: false }));

        expect(view.querySelector('a[href^="/documents/"]')).toBeNull();
        expect(view.textContent).toContain('kyc.detail.documents_hidden');
    });
});
