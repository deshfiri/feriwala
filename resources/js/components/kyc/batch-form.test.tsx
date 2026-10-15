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
        status: 'not_started',
        options,
        ...overrides,
    };
}

const batch = (
    groups: KycBatchGroup[],
    editable = true,
    selfie: KycBatch['selfie'] = null,
): KycBatch => ({
    name: 'Standard KYC',
    description: null,
    version: 1,
    editable,
    complete: false,
    missing: [],
    selfie,
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

describe('group status and a lone required option', () => {
    it.each([
        ['not_started', 'kyc.batch_form.status_not_started'],
        ['in_progress', 'kyc.batch_form.status_in_progress'],
        ['complete', 'kyc.batch_form.status_complete'],
    ] as const)('shows a %s group as such', (status, label) => {
        const view = mount(
            batch([
                group('any', [nid({ chosen: true }), passport(), licence()], {
                    status,
                }),
            ]),
        );

        expect(view.textContent).toContain(
            status === 'complete' ? 'kyc.batch_form.status_complete' : label,
        );
    });

    it('opens a single required option directly, with no selector and no frame', () => {
        const view = mount(
            batch([
                group('all', [nid({ chosen: true })], {
                    selected: ['nid'],
                    needed: 1,
                    offered: 1,
                }),
            ]),
        );

        expect(select(view)).toBeNull();
        expect(view.querySelectorAll('input[type="checkbox"]').length).toBe(1);
        expect(fileInputs(view)).toBe(2);
        // No per-option heading and no "every option" instruction for one option.
        expect(view.querySelector('h3')).toBeNull();
        expect(view.textContent).not.toContain('kyc.batch_form.all_required');
    });
});

describe('the live selfie', () => {
    const slotOf = (current: unknown = null) => ({
        key: 'capture',
        label: 'Live selfie',
        instructions: null,
        required: true,
        evidence: 'image' as const,
        mimes: ['image/jpeg'],
        max_kb: 3072,
        value_label: null,
        sensitive: false,
        current,
    });
    const selfie = (
        required: boolean,
        current: unknown = null,
        allows_upload = false,
    ): KycBatch['selfie'] =>
        ({
            mode: required ? 'required' : 'optional',
            required,
            satisfied: current !== null,
            allows_upload,
            requirement: '__selfie',
            slot: slotOf(current),
        }) as KycBatch['selfie'];

    const stop = vi.fn();
    const getUserMedia = vi.fn();

    beforeEach(() => {
        stop.mockReset();
        getUserMedia.mockReset();
        getUserMedia.mockImplementation(async () => ({
            getTracks: () => [{ stop }],
        }));
        Object.defineProperty(navigator, 'mediaDevices', {
            configurable: true,
            value: {
                getUserMedia,
            },
        });
        HTMLMediaElement.prototype.play = vi.fn(async () => undefined);
        Object.defineProperty(HTMLVideoElement.prototype, 'videoWidth', {
            configurable: true,
            get: () => 640,
        });
        Object.defineProperty(HTMLVideoElement.prototype, 'videoHeight', {
            configurable: true,
            get: () => 480,
        });
        HTMLCanvasElement.prototype.getContext = vi.fn(() => ({
            drawImage: vi.fn(),
        })) as never;
        HTMLCanvasElement.prototype.toBlob = function (callback: BlobCallback) {
            callback(new Blob(['frame'], { type: 'image/jpeg' }));
        };
        URL.createObjectURL = vi.fn(() => 'blob:selfie');
        URL.revokeObjectURL = vi.fn();
    });

    const flush = () => act(async () => {});

    it('is not shown at all when the batch does not ask for one', () => {
        const view = mount(batch([group('any', [nid(), passport()])]));

        expect(view.textContent).not.toContain('kyc.batch_form.selfie_title');
        expect(view.textContent).not.toContain('kyc.batch_form.selfie_start');
    });

    it('is labelled required, or optional, as the batch says', () => {
        const required = mount(batch([], true, selfie(true))).textContent ?? '';

        expect(required).toContain('kyc.batch_form.selfie_required');

        act(() => root.unmount());
        container.remove();

        const optional =
            mount(batch([], true, selfie(false))).textContent ?? '';

        expect(optional).toContain('kyc.batch_form.optional');
        expect(optional).not.toContain('kyc.batch_form.selfie_required');
    });

    it('offers the camera and no file picker unless the business allows one', () => {
        const view = mount(batch([], true, selfie(true)));

        expect(view.textContent).toContain('kyc.batch_form.selfie_start');
        expect(fileInputs(view)).toBe(0);

        act(() => root.unmount());
        container.remove();

        const fallback = mount(batch([], true, selfie(true, null, true)));

        expect(fileInputs(fallback)).toBe(1);
    });

    it('opens the camera facing the user, previews the frame, retakes and saves it', async () => {
        const view = mount(batch([], true, selfie(true)));

        await act(async () => {
            button('kyc.batch_form.selfie_start')?.click();
        });
        await flush();

        expect(getUserMedia).toHaveBeenCalledTimes(1);
        expect(getUserMedia.mock.calls[0][0].video.facingMode).toBe('user');
        expect(getUserMedia.mock.calls[0][0].audio).toBe(false);

        await act(async () => {
            button('kyc.batch_form.selfie_take')?.click();
        });
        await flush();

        // A preview, before anything is saved, and the camera is released.
        expect(view.querySelector('img')).not.toBeNull();
        expect(stop).toHaveBeenCalled();
        expect(post).not.toHaveBeenCalled();
        expect(view.textContent).toContain('kyc.batch_form.selfie_retake');

        // Retake goes back to a live camera.
        await act(async () => {
            button('kyc.batch_form.selfie_retake')?.click();
        });
        await flush();
        expect(getUserMedia).toHaveBeenCalledTimes(2);

        await act(async () => {
            button('kyc.batch_form.selfie_take')?.click();
        });
        await flush();
        await act(async () => {
            button('kyc.batch_form.selfie_use')?.click();
        });

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('/f');
        expect(post.mock.calls[0][1]).toMatchObject({
            requirement: '__selfie',
            slot: 'capture',
            captured_live: 1,
            capture_meta: { width: 640, height: 480, facing: 'user' },
        });
    });

    it('says why, and offers a retry, when the camera is refused', async () => {
        getUserMedia.mockRejectedValueOnce(
            new DOMException('no', 'NotAllowedError'),
        );

        const view = mount(batch([], true, selfie(true)));

        await act(async () => {
            button('kyc.batch_form.selfie_start')?.click();
        });
        await flush();

        expect(view.querySelector('[role="alert"]')?.textContent).toContain(
            'kyc.batch_form.camera_denied',
        );
        expect(view.textContent).toContain('kyc.batch_form.camera_retry');
        // Refusal is not a reason to hand over a file picker.
        expect(fileInputs(view)).toBe(0);
    });

    it('shows a saved selfie as saved, and keeps an accepted one locked', () => {
        const saved = {
            kind: 'file',
            document: 'doc-selfie',
            name: 'selfie.jpg',
            size_bytes: 1000,
            live: true,
            image: true,
            value_preview: null,
            verdict: null,
            note: null,
        };
        const open = mount(batch([], true, selfie(true, saved)));

        expect(open.textContent).toContain('kyc.batch_form.selfie_saved');
        expect(open.textContent).toContain('kyc.batch_form.selfie_retake');
        expect(open.textContent).not.toContain('kyc.batch_form.selfie_start');

        act(() => root.unmount());
        container.remove();

        const accepted = mount(
            batch([], true, selfie(true, { ...saved, verdict: 'accepted' })),
        );

        expect(accepted.textContent).not.toContain(
            'kyc.batch_form.selfie_retake',
        );
        expect(accepted.textContent).not.toContain(
            'kyc.batch_form.selfie_start',
        );
    });

    it('offers the camera again when the reviewer sent the selfie back', () => {
        const rejected = {
            kind: 'file',
            document: 'doc-selfie',
            name: 'selfie.jpg',
            size_bytes: 1000,
            live: true,
            image: true,
            value_preview: null,
            verdict: 'rejected',
            note: 'Too dark.',
        };
        const view = mount(batch([], true, selfie(true, rejected)));

        expect(view.textContent).toContain('Too dark.');
        expect(view.textContent).toContain('kyc.batch_form.selfie_start');
    });
});
