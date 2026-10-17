import { Form, usePage } from '@inertiajs/react';
import { UserCheck } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { stop } from '@/routes/impersonation';

/**
 * The strip shown on every screen while an administrator is looking at a
 * partner's or a Supplier's dashboard.
 *
 * It is the only way out of a partner visit — the session's identity is the
 * partner for the duration, who has no route back to the admin panel — so it
 * must be on every layout, and it must say plainly whose dashboard this is.
 */
export default function ImpersonationBanner() {
    const { t } = useTranslation();
    const { impersonation } = usePage<{
        impersonation?: { kind: 'partner' | 'supplier'; name: string } | null;
    }>().props;

    if (!impersonation) {
        return null;
    }

    return (
        <div
            role="status"
            className="border-warning/40 bg-warning-subtle text-warning flex flex-wrap items-center gap-3 border-b px-4 py-2 text-sm"
        >
            <UserCheck aria-hidden="true" className="size-4 shrink-0" />
            <p className="min-w-0 flex-1 font-medium">
                {t('account.dashboard_access.banner', {
                    name: impersonation.name,
                })}
            </p>
            <Form {...stop.form()}>
                {({ processing }) => (
                    <Button
                        type="submit"
                        size="sm"
                        variant="outline"
                        disabled={processing}
                    >
                        {t('account.dashboard_access.return')}
                    </Button>
                )}
            </Form>
        </div>
    );
}
