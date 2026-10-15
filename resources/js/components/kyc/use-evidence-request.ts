import { router } from '@inertiajs/react';
import { useState } from 'react';

/** The URLs of one audience's batch evidence endpoints. */
export type KycBatchEndpoints = {
    file: string;
    value: string;
    remove: string;
    choose: string;
    deselect: string;
    submit: string;
    /**
     * Where the applicant's own uploaded file can be looked at, if anywhere. The
     * route authorises on every request; there is no address of the file itself.
     */
    preview?: (documentId: string) => string;
};

export const formatSize = (bytes: number): string =>
    bytes < 1024 ? `${bytes} B` : `${Math.round(bytes / 1024)} KB`;

/**
 * One request against a slot or an option, with its own progress and its own
 * error.
 *
 * Each item saves alone, so a refused file never costs the others, and the
 * message appears against the item that was refused.
 */
export function useEvidenceRequest() {
    const [busy, setBusy] = useState(false);
    const [progress, setProgress] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);

    const send = (
        url: string,
        data: Record<string, unknown>,
        forceFormData = false,
    ) =>
        router.post(url, data as never, {
            forceFormData,
            preserveScroll: true,
            onStart: () => {
                setBusy(true);
                setError(null);
            },
            onProgress: (event) =>
                setProgress(
                    event?.percentage === undefined
                        ? null
                        : Math.round(event.percentage),
                ),
            onError: (errors) => setError(Object.values(errors)[0] ?? null),
            onFinish: () => {
                setBusy(false);
                setProgress(null);
            },
        });

    return { busy, progress, error, send };
}
