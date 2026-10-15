// @vitest-environment jsdom

import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import BatchKycForm from '@/components/kyc/batch-form';
import type { KycBatch, KycBatchGroup, KycBatchOption } from '@/types';

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

const endpoints = {
    file: '/f',
    value: '/v',
    remove: '/r',
    choose: '/c',
    deselect: '/d',
    submit: '/s',
};

const slot = (key: string, label: string) => ({
    key,
    label,
    instructions: null,
    required: true,
    evidence: 'image' as const,
    mimes: ['image/jpeg'],
    max_kb: 2048,
    value_label: null,
    sensitive: false,
    current: null,
});

const option = (
    key: string,
    name: string,
    slots: [string, string][],
    overrides: Partial<KycBatchOption> = {},
): KycBatchOption => ({
    key,
    name,
    instructions: null,
    required: true,
    chosen: false,
    complete: false,
    provided: 0,
    total: slots.length,
    has_draft: false,
    locked: false,
    slots: slots.map(([k, label]) => slot(k, label)),
    ...overrides,
});

const nid = (overrides: Partial<KycBatchOption> = {}) =>
    option(
        'nid',
        'National ID',
        [
            ['front', 'Front side'],
            ['back', 'Back side'],
        ],
        overrides,
    );
const passport = (overrides: Partial<KycBatchOption> = {}) =>
    option('passport', 'Passport', [['bio', 'Bio page']], overrides);
const licence = (overrides: Partial<KycBatchOption> = {}) =>
    option(
        'dl',
        'Driving Licence',
        [
            ['front', 'Front side'],
            ['back', 'Back side'],
        ],
        overrides,
    );

function group(
    rule: KycBatchGroup['rule'],
    options: KycBatchOption[],
    overrides: Partial<KycBatchGroup> = {},
): KycBatchGroup {
    return {
        key: 'identity',
        name: 'Identity Verification',
        description: null,
        rule,
        choose: rule !== 'all',
        selector_label: 'Select identification document',
        selected: options.filter((o) => o.chosen).map((o) => o.key),
        required: true,
        needed: rule === 'min' ? 2 : 1,
        done: 0,
        offered: options.length,
        satisfied: false,
        options,
        ...overrides,
    };
}

const batch = (groups: KycBatchGroup[], editable = true): KycBatch => ({
    name: 'Standard KYC',
    description: null,
    version: 1,
    editable,
    complete: false,
    missing: [],
    selfie: null,
    groups,
});

let container: HTMLDivElement;
let root: Root;

function mount(value: KycBatch): HTMLDivElement {
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);

    act(() => {
        root.render(<BatchKycForm batch={value} endpoints={endpoints} />);
    });

    return container;
}

const fileInputs = (view: HTMLElement) =>
    view.querySelectorAll('input[type="file"]').length;
const select = (view: HTMLElement) =>
    view.querySelector('select') as HTMLSelectElement;

function pick(element: HTMLSelectElement, value: string) {
    act(() => {
        element.value = value;
        element.dispatchEvent(new Event('change', { bubbles: true }));
    });
}

const button = (label: string) =>
    [...document.body.querySelectorAll('button')].find((one) =>
        one.textContent?.includes(label),
    );

beforeEach(() => post.mockReset());

afterEach(() => {
    act(() => root.unmount());
    container.remove();
    document.body.innerHTML = '';
});

describe('an "any one" group', () => {
    it('shows only the dropdown before anything is chosen', () => {
        const view = mount(
            batch([group('any', [nid(), passport(), licence()])]),
        );

        expect(select(view)).not.toBeNull();
        expect(view.querySelector('label')?.textContent).toBe(
            'Select identification document',
        );
        expect([...select(view).options].map((o) => o.textContent)).toEqual([
            'kyc.batch_form.choose_one',
            'National ID',
            'Passport',
            'Driving Licence',
        ]);

        // No upload for any of the three, and no slot named.
        expect(fileInputs(view)).toBe(0);
        expect(view.textContent).not.toContain('Front side');
        expect(view.textContent).not.toContain('Bio page');
    });

    it('says nothing is selected, and never counts the unchosen options as missing', () => {
        const text =
            mount(batch([group('any', [nid(), passport(), licence()])]))
                .textContent ?? '';

        expect(text).toContain('kyc.batch_form.no_selection');
        // Not "0 of 3 provided", not a count of anything for the unchosen.
        expect(text).not.toContain('kyc.batch_form.progress');
        expect(text).not.toContain('kyc.batch_form.items');
    });

    it.each([
        ['National ID', 'nid', 2, ['Front side', 'Back side']],
        ['Passport', 'passport', 1, ['Bio page']],
        ['Driving Licence', 'dl', 2, ['Front side', 'Back side']],
    ])(
        'shows exactly the slots of the chosen %s',
        (_name, chosenKey, count, labels) => {
            const options = [nid(), passport(), licence()].map((one) => ({
                ...one,
                chosen: one.key === chosenKey,
            }));
            const view = mount(batch([group('any', options)]));

            expect(fileInputs(view)).toBe(count);
            labels.forEach((label) =>
                expect(view.textContent).toContain(label),
            );
        },
    );

    it("shows the chosen option's own progress, and completion for the group", () => {
        const partial =
            mount(
                batch([
                    group('any', [
                        nid({ chosen: true, provided: 1 }),
                        passport(),
                        licence(),
                    ]),
                ]),
            ).textContent ?? '';

        expect(partial).toContain('kyc.batch_form.option_progress');
        expect(partial).toContain('"option":"National ID"');
        expect(partial).toContain('"provided":1,"total":2');

        act(() => root.unmount());
        container.remove();

        const done =
            mount(
                batch([
                    group(
                        'any',
                        [
                            nid({ chosen: true, provided: 2, complete: true }),
                            passport(),
                            licence(),
                        ],
                        { satisfied: true, done: 1 },
                    ),
                ]),
            ).textContent ?? '';

        expect(done).toContain('kyc.batch_form.group_complete');
        expect(done).toContain('Identity Verification');
    });

    it('posts the choice straight away when nothing would be lost', () => {
        const view = mount(
            batch([
                group('any', [nid({ chosen: true }), passport(), licence()]),
            ]),
        );

        pick(select(view), 'passport');

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('/c');
        expect(post.mock.calls[0][1]).toEqual({
            group: 'identity',
            requirement: 'passport',
        });
    });

    it('asks first when files already added for the option would be discarded', () => {
        const view = mount(
            batch([
                group('any', [
                    nid({ chosen: true, has_draft: true, provided: 1 }),
                    passport(),
                    licence(),
                ]),
            ]),
        );

        pick(select(view), 'passport');

        // Nothing is sent until the applicant agrees.
        expect(post).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain(
            'kyc.batch_form.change_title',
        );
        expect(document.body.textContent).toContain('"option":"National ID"');

        act(() => {
            button('kyc.batch_form.change_keep')?.click();
        });

        expect(post).not.toHaveBeenCalled();
    });

    it('sends the change once the applicant confirms it', () => {
        const view = mount(
            batch([
                group('any', [
                    nid({ chosen: true, has_draft: true, provided: 1 }),
                    passport(),
                    licence(),
                ]),
            ]),
        );

        pick(select(view), 'dl');

        act(() => {
            button('kyc.batch_form.change_confirm')?.click();
        });

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][1]).toEqual({
            group: 'identity',
            requirement: 'dl',
        });
    });

    it('cannot be changed once the option is accepted or sent, and says why', () => {
        const view = mount(
            batch([
                group('any', [
                    nid({ chosen: true, locked: true }),
                    passport(),
                    licence(),
                ]),
            ]),
        );

        expect(select(view).disabled).toBe(true);
        expect(view.textContent).toContain('kyc.batch_form.locked_option');
    });
});

describe('the other completion rules', () => {
    it('shows every option of an "all required" group, with its slots', () => {
        const view = mount(
            batch([
                group(
                    'all',
                    [nid({ chosen: true }), passport({ chosen: true })],
                    { selected: ['nid', 'passport'], needed: 2 },
                ),
            ]),
        );

        expect(select(view)).toBeNull();
        expect(fileInputs(view)).toBe(3);
        expect(view.textContent).toContain('kyc.batch_form.all_required');
    });

    it('lets an "at least N" group be ticked, and shows only the ticked options\' slots', () => {
        const view = mount(
            batch([
                group('min', [nid({ chosen: true }), passport(), licence()]),
            ]),
        );

        // The last checkbox on the page is the submit declaration, not an option.
        const boxes = (
            [
                ...view.querySelectorAll('input[type="checkbox"]'),
            ] as HTMLInputElement[]
        ).slice(0, 3);

        expect(boxes).toHaveLength(3);
        expect(boxes.map((b) => b.checked)).toEqual([true, false, false]);
        // Only National ID is ticked, so only its two slots appear.
        expect(fileInputs(view)).toBe(2);

        act(() => {
            boxes[1].click();
        });

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('/c');
        expect(post.mock.calls[0][1]).toEqual({
            group: 'identity',
            requirement: 'passport',
        });
    });

    it('asks before un-ticking an option that holds files', () => {
        const view = mount(
            batch([
                group('min', [
                    nid({ chosen: true, has_draft: true }),
                    passport(),
                    licence(),
                ]),
            ]),
        );

        act(() => {
            (
                view.querySelector('input[type="checkbox"]') as HTMLInputElement
            ).click();
        });

        expect(post).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain(
            'kyc.batch_form.change_title',
        );

        act(() => {
            button('kyc.batch_form.change_confirm')?.click();
        });

        expect(post.mock.calls[0][0]).toBe('/d');
    });
});

describe('a locked round', () => {
    it('offers no controls once the round is with the team', () => {
        const view = mount(
            batch(
                [group('any', [nid({ chosen: true }), passport(), licence()])],
                false,
            ),
        );

        expect(select(view).disabled).toBe(true);
        expect(view.textContent).not.toContain('kyc.batch_form.submit_button');
        expect(view.textContent).toContain('kyc.batch_form.locked');
    });
});
