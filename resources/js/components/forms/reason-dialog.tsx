import { Form } from '@inertiajs/react';
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

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * A sensitive or irreversible action that requires a recorded reason
 * (§32.2) -- archiving a custom role or permission, for example.
 *
 * Distinct from `ConfirmDialog`, which never collects input: this submits a
 * real form, because the server records the reason in the audit trail
 * rather than merely asking "are you sure".
 */
export default function ReasonDialog({
    open,
    onClose,
    title,
    description,
    form,
    confirmLabel,
    destructive = false,
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    description?: string;
    /** A Wayfinder `.form()` result, e.g. `RolesController.archive.form(name)`. */
    form: {
        action: string;
        method: 'get' | 'post' | 'put' | 'patch' | 'delete';
    };
    confirmLabel?: string;
    destructive?: boolean;
}) {
    const { t } = useTranslation();

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    {description && (
                        <DialogDescription>{description}</DialogDescription>
                    )}
                </DialogHeader>

                <Form
                    {...form}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="grid gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="reason-dialog-reason">
                                    {t('access.roles.reason')}
                                </Label>
                                <textarea
                                    id="reason-dialog-reason"
                                    name="reason"
                                    rows={2}
                                    required
                                    minLength={5}
                                    className={controlClass}
                                />
                                <InputError message={errors.reason} />
                            </div>

                            <DialogFooter className="gap-2 sm:gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={onClose}
                                    disabled={processing}
                                >
                                    {t('common.actions.cancel')}
                                </Button>
                                <Button
                                    type="submit"
                                    variant={
                                        destructive ? 'destructive' : 'default'
                                    }
                                    disabled={processing}
                                >
                                    {processing && (
                                        <Spinner className="size-3.5" />
                                    )}
                                    {confirmLabel ??
                                        t('common.actions.confirm')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
