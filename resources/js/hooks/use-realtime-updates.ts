import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';
import { useTranslation } from '@/hooks/use-translation';
import { echo } from '@/lib/echo';

type NotificationsChanged = { reason: 'received' | 'read'; unread: number };

/**
 * Keeps the whole panel live (§33.2).
 *
 * Subscribes to this person's private channel and, whenever the server says
 * their notifications changed, refetches the current page's props. That one
 * reload is what moves the bell badge, the list, and anything on screen that
 * the event touched — a KYC queue, a stock level, a withdrawal — without each
 * screen wiring its own socket.
 *
 * The socket carries a nudge, never data. What comes back is rendered by the
 * person's own authenticated request, in their own locale, under their own
 * permissions.
 *
 * Reloads are coalesced: a burst of events (an action that notifies several
 * people, or one person several times) is one request. A hidden tab waits and
 * catches up when it becomes visible instead of fetching in the background.
 */
export function useRealtimeUpdates(): void {
    const { t } = useTranslation();
    const { realtimeChannel, notifications } = usePage().props;
    const knownIds = useRef<Set<string>>(new Set());

    useEffect(() => {
        // A page that shares no notification list (a Supplier's shell, a test)
        // simply has nothing to compare against.
        knownIds.current = new Set(
            (notifications ?? []).map((item) => item.id),
        );
    }, [notifications]);

    useEffect(() => {
        const connection = echo();

        if (!realtimeChannel || connection === null) {
            return;
        }

        let timer: ReturnType<typeof setTimeout> | null = null;
        let pending = false;
        let announce = false;

        const refresh = () => {
            pending = false;
            const alreadyShown = new Set(knownIds.current);
            const shouldAnnounce = announce;
            announce = false;

            router.reload({
                // Translations are large and do not change mid-session.
                except: ['translations'],
                onSuccess: (page) => {
                    if (!shouldAnnounce) {
                        return;
                    }

                    const fresh = (
                        (page.props.notifications ?? []) as typeof notifications
                    ).filter(
                        (item) =>
                            item.readAt === null && !alreadyShown.has(item.id),
                    );

                    // The Supplier shell carries no notification list, only a
                    // count, so there is nothing to quote — say that one came.
                    if (fresh.length === 0) {
                        toast(t('nav.notifications.new'));

                        return;
                    }

                    fresh.slice(0, 3).forEach((item) =>
                        toast(item.title, {
                            description: item.description ?? undefined,
                        }),
                    );
                },
            });
        };

        const schedule = (event: NotificationsChanged) => {
            pending = true;
            announce = announce || event.reason === 'received';

            if (document.hidden) {
                return;
            }

            if (timer !== null) {
                clearTimeout(timer);
            }

            timer = setTimeout(refresh, 250);
        };

        const catchUp = () => {
            if (!document.hidden && pending) {
                refresh();
            }
        };

        const channel = connection
            .private(realtimeChannel)
            .listen('.notifications.changed', schedule);

        document.addEventListener('visibilitychange', catchUp);

        return () => {
            if (timer !== null) {
                clearTimeout(timer);
            }

            document.removeEventListener('visibilitychange', catchUp);
            channel.stopListening('.notifications.changed', schedule);
            connection.leave(realtimeChannel);
        };
    }, [realtimeChannel]);
}
