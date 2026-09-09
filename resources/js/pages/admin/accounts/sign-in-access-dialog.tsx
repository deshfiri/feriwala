import { Form } from '@inertiajs/react';
import IdentityAccessController from '@/actions/App/Http/Controllers/Admin/IdentityAccessController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';

export type Person = {
    id: string;
    name: string | null;
    email: string | null;
    role_label: string;
    identity_status: string;
    identity_status_label: string;
    identity_status_tone: string;
    is_locked: boolean;
    changed_at: string | null;
    can_change: boolean;
};

/**
 * Locking or restoring one person's sign-in (§6, §32.2).
 *
 * The reason is a field, not a formality: the decision gets questioned months
 * later, by which time whoever made it may have left. It is recorded in the
 * audit trail and never shown to the person — an assessment written for staff
 * is not a message to its subject (§7.3).
 *
 * The consequence is stated before the button, because "lock" reads like a
 * setting and behaves like removing someone from the building.
 */
export default function SignInAccessDialog({
    person,
    open,
    onOpenChange,
}: {
    person: Person | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    if (person === null) {
        return null;
    }

    const locking = !person.is_locked;
    const name = person.name ?? person.email ?? '';

    const action = locking
        ? IdentityAccessController.lock.form(person.id)
        : IdentityAccessController.unlock.form(person.id);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {t(
                            locking
                                ? 'security.lock.lock_title'
                                : 'security.lock.unlock_title',
                            { name },
                        )}
                    </DialogTitle>
                    <DialogDescription>
                        {t(
                            locking
                                ? 'security.lock.lock_description'
                                : 'security.lock.unlock_description',
                        )}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...action}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="reason">
                                    {t('security.lock.reason')}
                                </Label>

                                <textarea
                                    id="reason"
                                    name="reason"
                                    rows={3}
                                    required
                                    className="border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                />

                                <p className="text-muted-foreground text-xs">
                                    {t('security.lock.reason_help')}
                                </p>

                                <InputError message={errors.reason} />
                            </div>

                            <DialogFooter className="gap-2 sm:gap-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => onOpenChange(false)}
                                >
                                    {t('common.actions.cancel')}
                                </Button>

                                <Button
                                    type="submit"
                                    variant={
                                        locking ? 'destructive' : 'default'
                                    }
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    {t(
                                        locking
                                            ? 'security.lock.lock'
                                            : 'security.lock.unlock',
                                    )}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
