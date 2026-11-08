/**
 * An instant as a `datetime-local` value, in the browser's own time, or '' when
 * there is none.
 */
export function toLocalInput(instant: string | null | undefined): string {
    if (!instant) {
        return '';
    }

    const date = new Date(instant);
    const pad = (value: number) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/**
 * A `datetime-local` value as an instant with its offset, or null when empty, so
 * the server stores exactly the moment meant whatever time zone typed it (C4).
 */
export function toInstant(value: unknown): string | null {
    return typeof value === 'string' && value !== ''
        ? new Date(value).toISOString()
        : null;
}
