import { router } from '@inertiajs/react';
import { Monitor } from 'lucide-react';
import { useState } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import ConfirmDialog from '@/components/forms/confirm-dialog';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';

export type AuthenticatedSession = {
    id: string;
    device: string;
    ip_address: string | null;
    last_active_diff: string;
    last_active_iso: string;
    signed_in_iso: string | null;
    is_current: boolean;
    ended: boolean;
    ended_reason: string | null;
};

export type Props = {
    sessions?: AuthenticatedSession[];
};

/**
 * Where this account is signed in, and how to end any of it (§6).
 *
 * The list is the security control, not decoration: somebody who suspects their
 * password has been taken needs to see the sessions they did not start and end
 * them without waiting for support. Sessions that have already finished stay on
 * the list because "was that me last Tuesday?" is a question about one of those.
 */
export default function ManageSessions({ sessions = [] }: Props) {
    const { t } = useTranslation();
    const [pending, setPending] = useState<string | null>(null);

    const others = sessions.filter(
        (session) => !session.is_current && !session.ended,
    );

    const endedLabel = (reason: string | null) =>
        t(`security.sessions.ended.${reason ?? 'unknown'}`);

    const signOut = (id: string) => {
        setPending(id);

        router.delete(SecurityController.destroySession.url(id), {
            preserveScroll: true,
            onFinish: () => setPending(null),
        });
    };

    const signOutOthers = () => {
        setPending('all');

        router.delete(SecurityController.destroyOtherSessions.url(), {
            preserveScroll: true,
            onFinish: () => setPending(null),
        });
    };

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title={t('security.sessions.title')}
                description={t('security.sessions.description')}
            />

            <div className="border-border overflow-hidden rounded-lg border">
                {sessions.length === 0 ? (
                    <p className="text-muted-foreground p-8 text-center text-sm">
                        {t('security.sessions.empty')}
                    </p>
                ) : (
                    sessions.map((session) => (
                        <div
                            key={session.id}
                            className="flex items-center justify-between gap-4 border-b p-4 last:border-b-0"
                        >
                            <div className="flex min-w-0 items-center gap-4">
                                <div className="bg-muted flex h-10 w-10 shrink-0 items-center justify-center rounded-xl">
                                    <Monitor className="text-muted-foreground h-5 w-5" />
                                </div>

                                <div className="min-w-0 space-y-1">
                                    <div className="flex flex-wrap items-center gap-2.5">
                                        <p className="truncate font-medium tracking-tight">
                                            {session.device}
                                        </p>

                                        {session.is_current && (
                                            <span className="bg-muted text-muted-foreground ring-border inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-medium tracking-wide uppercase ring-1 ring-inset">
                                                {t('security.sessions.current')}
                                            </span>
                                        )}
                                    </div>

                                    <p className="text-muted-foreground text-sm">
                                        {session.ip_address ??
                                            t(
                                                'security.sessions.unknown_address',
                                            )}
                                        <span className="text-muted-foreground/50 mx-1">
                                            /
                                        </span>
                                        {session.ended
                                            ? endedLabel(session.ended_reason)
                                            : t(
                                                  'security.sessions.last_active',
                                                  {
                                                      when: session.last_active_diff,
                                                  },
                                              )}
                                    </p>
                                </div>
                            </div>

                            {!session.is_current && !session.ended && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="text-destructive hover:bg-destructive/10 hover:text-destructive shrink-0"
                                    disabled={pending !== null}
                                    onClick={() => signOut(session.id)}
                                >
                                    {t('security.sessions.sign_out')}
                                </Button>
                            )}
                        </div>
                    ))
                )}
            </div>

            {others.length > 0 && (
                <ConfirmDialog
                    trigger={
                        <Button variant="secondary" disabled={pending !== null}>
                            {t('security.sessions.sign_out_others')}
                        </Button>
                    }
                    title={t('security.sessions.sign_out_others_title')}
                    description={t(
                        'security.sessions.sign_out_others_description',
                    )}
                    summary={others.map((session) => session.device).join(', ')}
                    confirmLabel={t('security.sessions.sign_out_others')}
                    onConfirm={signOutOthers}
                    processing={pending === 'all'}
                    destructive
                />
            )}
        </div>
    );
}
