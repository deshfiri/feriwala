import { Link, usePage } from '@inertiajs/react';
import { Bell, BellOff } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { HeaderNotification } from '@/types';

/**
 * The notification centre (§33.2, D20).
 *
 * The list comes from the server or it is empty — nothing here invents a count.
 * An unread badge is the one thing in a header people trust implicitly, so a
 * decorative "3" would be a lie with consequences: someone opens the menu, finds
 * nothing, and stops believing the badge for good.
 *
 * The dot never carries the meaning by itself. It is mirrored in the trigger's
 * accessible label and in a written count inside the menu, so the state reads
 * for a screen reader and in greyscale too (§33.9).
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
                    className="relative size-9"
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
                            className="bg-brand ring-background absolute top-1.5 right-1.5 size-2 rounded-full ring-2"
                        />
                    )}
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" className="w-80 p-0">
                <div className="border-border flex items-center justify-between gap-2 border-b px-4 py-3">
                    <p className="text-sm font-semibold">
                        {t('nav.notifications.title')}
                    </p>
                    {hasUnread && (
                        <span className="bg-brand-subtle text-brand shrink-0 rounded px-1.5 py-0.5 text-xs font-medium">
                            {t('nav.notifications.unread', {
                                count: unreadCount,
                            })}
                        </span>
                    )}
                </div>

                {notifications.length === 0 ? (
                    <div className="flex flex-col items-center gap-1.5 px-6 py-8 text-center">
                        <BellOff
                            aria-hidden="true"
                            className="text-muted-foreground mb-1 size-5"
                        />
                        <p className="text-sm font-medium">
                            {t('nav.notifications.empty_title')}
                        </p>
                        <p className="text-muted-foreground text-xs text-balance">
                            {t('nav.notifications.empty_description')}
                        </p>
                    </div>
                ) : (
                    <ul className="max-h-96 overflow-y-auto">
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
 * One notification. A row with a destination is a link; one without is plain
 * text rather than a control that looks clickable and goes nowhere.
 */
function NotificationRow({
    notification,
}: {
    notification: HeaderNotification;
}) {
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

    if (notification.href === null) {
        return <div className="px-4 py-3">{body}</div>;
    }

    return (
        <Link
            href={notification.href}
            className="hover:bg-accent block px-4 py-3 transition-colors"
        >
            {body}
        </Link>
    );
}
