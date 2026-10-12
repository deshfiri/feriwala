import { Link, router, usePage } from '@inertiajs/react';
import { Bell, BellOff, Check, CheckCheck } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { xsrfToken } from '@/lib/xsrf';
import { read, readAll } from '@/routes/notifications';
import type { HeaderNotification } from '@/types';

/** The badge stops counting at this, so a long backlog cannot widen the bell. */
const BADGE_CAP = 99;

/**
 * The notification centre (§33.2, D20).
 *
 * The list and the count come from the server or they are empty — nothing here
 * invents a figure. An unread badge is the one thing in a header people trust
 * implicitly, so a decorative "3" would be a lie with consequences: someone
 * opens the menu, finds nothing, and stops believing the badge for good. New
 * arrivals reach it live: `useRealtimeUpdates` reloads these props when the
 * socket says something changed.
 *
 * The number never carries the meaning by itself. It is mirrored in the
 * trigger's accessible label and in a written count inside the menu, so the
 * state reads for a screen reader and in greyscale too (§33.9).
 */
export default function NotificationMenu() {
    const { t } = useTranslation();
    const { notifications, unreadNotificationCount } = usePage().props;

    const unreadCount = unreadNotificationCount ?? 0;
    const hasUnread = unreadCount > 0;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="text-muted-foreground hover:text-foreground relative size-9"
                    aria-label={
                        hasUnread
                            ? t('nav.notifications.unread', {
                                  count: unreadCount,
                              })
                            : t('nav.notifications.none')
                    }
                >
                    <Bell aria-hidden="true" className="size-4" />
                    {hasUnread && (
                        <span
                            aria-hidden="true"
                            className="bg-brand text-brand-foreground ring-background absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] leading-none font-semibold tabular-nums ring-2"
                        >
                            {unreadCount > BADGE_CAP
                                ? `${BADGE_CAP}+`
                                : unreadCount}
                        </span>
                    )}
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent
                align="end"
                sideOffset={8}
                className="w-80 overflow-hidden rounded-xl p-0"
            >
                <div className="border-border flex items-center justify-between gap-2 border-b px-4 py-3">
                    <div className="flex min-w-0 items-center gap-2">
                        <p className="text-sm font-semibold">
                            {t('nav.notifications.title')}
                        </p>
                        {hasUnread && (
                            <span className="bg-brand-subtle text-brand shrink-0 rounded-full px-2 py-0.5 text-xs font-medium">
                                {t('nav.notifications.unread', {
                                    count: unreadCount,
                                })}
                            </span>
                        )}
                    </div>
                    {hasUnread && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-muted-foreground h-7 shrink-0 gap-1 px-2 text-xs"
                            onClick={() =>
                                router.post(
                                    readAll.url(),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <CheckCheck
                                aria-hidden="true"
                                className="size-3.5"
                            />
                            {t('nav.notifications.mark_all_read')}
                        </Button>
                    )}
                </div>

                {notifications.length === 0 ? (
                    <div className="flex flex-col items-center gap-1.5 px-6 py-8 text-center">
                        <span
                            aria-hidden="true"
                            className="bg-surface-subtle text-muted-foreground mb-2 flex size-10 items-center justify-center rounded-xl border"
                        >
                            <BellOff className="size-4" />
                        </span>
                        <p className="text-sm font-medium">
                            {t('nav.notifications.empty_title')}
                        </p>
                        <p className="text-muted-foreground text-xs text-balance">
                            {t('nav.notifications.empty_description')}
                        </p>
                    </div>
                ) : (
                    <ul className="max-h-96 scrollbar-thin overflow-y-auto">
                        {notifications.map((notification) => (
                            <li
                                key={notification.id}
                                className="border-border border-b last:border-b-0"
                            >
                                <NotificationRow notification={notification} />
                            </li>
                        ))}
                    </ul>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * Marks one notification read without a page visit, so it can ride alongside
 * following the row's link. An Inertia visit here would cancel the navigation
 * the person just asked for. The socket brings the badge down afterwards.
 */
function markReadInBackground(id: string): void {
    void fetch(read.url(id), {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
    });
}

/**
 * One notification. A row with a destination is a link; one without is plain
 * text rather than a control that looks clickable and goes nowhere. An unread
 * row also offers a button to mark it read in place.
 */
function NotificationRow({
    notification,
}: {
    notification: HeaderNotification;
}) {
    const { t } = useTranslation();
    const isUnread = notification.readAt === null;

    const body = (
        <>
            <div className="flex items-start gap-2">
                <span
                    aria-hidden="true"
                    className={cn(
                        'mt-1.5 size-1.5 shrink-0 rounded-full',
                        isUnread ? 'bg-brand' : 'bg-transparent',
                    )}
                />
                <p
                    className={cn(
                        'flex-1 text-sm',
                        isUnread ? 'font-medium' : 'text-muted-foreground',
                    )}
                >
                    {notification.title}
                </p>
            </div>

            {notification.description && (
                <p className="text-muted-foreground mt-0.5 pl-3.5 text-xs">
                    {notification.description}
                </p>
            )}

            {/* An unknown time shows as nothing, never as a guess. */}
            {notification.createdAt && (
                <time
                    dateTime={notification.createdAtIso ?? undefined}
                    className="text-muted-foreground mt-1 block pl-3.5 text-xs"
                >
                    {notification.createdAt}
                </time>
            )}
        </>
    );

    return (
        <div className="hover:bg-surface-hover flex items-start transition-colors">
            {notification.href === null ? (
                <div className="min-w-0 flex-1 px-4 py-3">{body}</div>
            ) : (
                <Link
                    href={notification.href}
                    className="min-w-0 flex-1 px-4 py-3"
                    onClick={() => {
                        if (isUnread) {
                            markReadInBackground(notification.id);
                        }
                    }}
                >
                    {body}
                </Link>
            )}

            {isUnread && (
                <Button
                    variant="ghost"
                    size="icon"
                    className="text-muted-foreground hover:text-foreground mt-2 mr-2 size-7 shrink-0"
                    aria-label={t('nav.notifications.mark_read')}
                    title={t('nav.notifications.mark_read')}
                    onClick={() =>
                        router.post(
                            read.url(notification.id),
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    <Check aria-hidden="true" className="size-3.5" />
                </Button>
            )}
        </div>
    );
}
