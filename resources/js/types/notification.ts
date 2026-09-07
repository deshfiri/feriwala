/**
 * One row in the header notification centre, mirroring
 * App\Domain\Notification\Data\HeaderNotification.
 *
 * `createdAt` arrives already worded and already translated — the client never
 * formats a date, for the same reason it never formats money. `createdAtIso` is
 * the machine value that goes in the `<time datetime>` attribute beside it.
 */
export type HeaderNotification = {
    id: string;
    title: string;
    description: string | null;
    /** null when the notification is news rather than a task. */
    href: string | null;
    /**
     * Null when the stored row carries no timestamp — the table declares them
     * nullable. Shown as nothing rather than guessed at.
     */
    createdAt: string | null;
    createdAtIso: string | null;
    /** null while unread. */
    readAt: string | null;
};
