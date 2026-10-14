// @vitest-environment jsdom

import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, describe, expect, it, vi } from 'vitest';
import BatchKycForm from '@/components/kyc/batch-form';
import type { KycBatch } from '@/types';

vi.mock('@inertiajs/react', () => ({
    router: { post: vi.fn(), on: vi.fn(() => () => {}) },
}));

vi.mock('@/hooks/use-translation', () => ({
    useTranslation: () => ({
        t: (key: string, values?: Record<string, unknown>) =>
            values ? `${key} ${JSON.stringify(values)}` : key,
        locale: 'en',
    }),
}));

const endpoints = {
    file: '/f',
    value: '/v',
    remove: '/r',
    choose: '/c',
    deselect: '/d',
    submit: '/s',
};

const slot = (key: string, label: string, current = null) => ({
    key,
    label,
    instructions: null,
    required: true,
    evidence: 'file' as const,
    mimes: ['image/jpeg'],
    max_kb: 5120,
    value_label: null,
    sensitive: false,
    current,
});

const batch: KycBatch = {
    name: 'Identity',
    description: null,
    version: 1,
    editable: true,
    complete: false,
    missing: ['Identity: any one'],
    selfie: null,
    groups: [
        {
            key: 'identity',
            name: 'Identity document',
            description: null,
            rule: 'any',
            choose: true,
            required: true,
            needed: 1,
            done: 0,
            offered: 2,
            satisfied: false,
            options: [
                {
                    key: 'nid',
                    name: 'National ID',
                    instructions: null,
                    required: true,
                    chosen: true,
                    complete: false,
                    provided: 0,
                    total: 2,
                    slots: [
                        slot('front', 'Front side'),
                        slot('back', 'Back side'),
                    ],
                },
                {
                    key: 'passport',
                    name: 'Passport',
                    instructions: null,
                    required: true,
                    chosen: false,
                    complete: false,
                    provided: 0,
                    total: 1,
                    slots: [slot('page', 'Photo page')],
                },
            ],
        },
    ],
};

(
    globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
).IS_REACT_ACT_ENVIRONMENT = true;

let container: HTMLDivElement | null = null;
let root: Root | null = null;

function mount(value: KycBatch): HTMLDivElement {
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);

    act(() => {
        root?.render(<BatchKycForm batch={value} endpoints={endpoints} />);
    });

    return container;
}

afterEach(() => {
    act(() => root?.unmount());
    container?.remove();
    container = null;
    root = null;
});

describe('BatchKycForm', () => {
    it('shows the slots of the chosen option only', () => {
        const text = mount(batch).textContent ?? '';

        expect(text).toContain('Front side');
        expect(text).toContain('Back side');
        expect(text).not.toContain('Photo page');
    });

    it('keeps submit disabled until the server says the round is complete', () => {
        const view = mount(batch);
        const submit = [...view.querySelectorAll('button')].find(
            (button) => button.textContent === 'kyc.batch_form.submit_button',
        );

        expect(submit?.disabled).toBe(true);
        expect(view.textContent).toContain('Identity: any one');
    });

    it('offers no controls once the round is locked', () => {
        const text = mount({ ...batch, editable: false }).textContent ?? '';

        expect(text).not.toContain('kyc.batch_form.choose_file');
        expect(text).not.toContain('kyc.batch_form.submit_button');
        expect(text).toContain('kyc.batch_form.locked');
    });
});
