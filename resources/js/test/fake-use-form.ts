import { useRef, useState } from 'react';

type VisitOptions = {
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
    onFinish?: () => void;
};

/**
 * One request the component under test made through the fake form, held open
 * until the test decides how it ends — as a slow server would.
 */
export type PendingRequest = {
    url: string;
    method: string;
    payload: Record<string, unknown>;
    succeed: () => void;
    fail: (errors: Record<string, string>) => void;
};

/**
 * Every request made through {@link fakeUseForm}, oldest first. A test clears
 * it in `beforeEach`.
 */
export const requests: PendingRequest[] = [];

/**
 * A stand-in for Inertia's `useForm` that keeps the parts a component can see —
 * data, dirtiness, errors, `processing`, `transform` — in React state, and sends
 * nothing. A request stays in flight until the test calls `succeed()` or
 * `fail()`.
 *
 * It has `submit` only, taking a route (`{ url, method }`) as Wayfinder
 * returns one. There is deliberately no `put` or `patch`: a component that
 * hard-codes a verb must fail here, as it would against a route that declares
 * another (the real update route is PATCH, and a PUT to it is a 405).
 */
export function fakeUseForm<T extends Record<string, unknown>>(initial: T) {
    const [data, setDataState] = useState<T>(initial);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const saved = useRef(initial);
    const transformer = useRef<((data: T) => Record<string, unknown>) | null>(
        null,
    );

    return {
        data,
        errors,
        processing,
        isDirty: JSON.stringify(data) !== JSON.stringify(saved.current),
        setData: (key: keyof T, value: unknown) =>
            setDataState((current) => ({ ...current, [key]: value })),
        transform: (callback: (data: T) => Record<string, unknown>) => {
            transformer.current = callback;
        },
        submit: (
            route: { url: string; method: string },
            options: VisitOptions = {},
        ) => {
            setProcessing(true);
            setErrors({});

            requests.push({
                url: route.url,
                method: route.method,
                payload: transformer.current?.(data) ?? data,
                succeed: () => {
                    options.onSuccess?.();
                    options.onFinish?.();
                    setProcessing(false);
                },
                fail: (failures) => {
                    setErrors(failures);
                    options.onError?.(failures);
                    options.onFinish?.();
                    setProcessing(false);
                },
            });
        },
    };
}
