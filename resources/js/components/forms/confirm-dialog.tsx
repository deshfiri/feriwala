import { useState, type ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Confirmation before a sensitive or irreversible action (§33.5, §33.7).
 *
 * `summary` is the important part. A dialog that only asks "Are you sure?" gets
 * clicked through without being read; one that restates what is about to happen
 * — the amount, the destination, the number of rows affected — gives the reader
 * a chance to notice that it is wrong.
 *
 * Sensitive financial actions may additionally require password confirmation,
 * two-factor, or a second approver (§32.2). Those are enforced server-side; this
 * dialog is the last chance to reconsider, not the security control.
 */
export default function ConfirmDialog({
    trigger,
    title,
    description,
    summary,
    confirmLabel,
    onConfirm,
    processing = false,
    destructive = false,
}: {
    trigger: ReactNode;
    title: string;
    description?: string;
    /** What is about to happen, restated concretely. */
    summary?: ReactNode;
    confirmLabel?: string;
    onConfirm: () => void;
    processing?: boolean;
    destructive?: boolean;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>

            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    {description && (
                        <DialogDescription>{description}</DialogDescription>
                    )}
                </DialogHeader>

                {summary && (
                    <div className="bg-muted border-border rounded-md border px-3 py-2.5 text-sm">
                        {summary}
                    </div>
                )}

                <DialogFooter className="gap-2 sm:gap-2">
                    <Button
                        variant="ghost"
                        onClick={() => setOpen(false)}
                        disabled={processing}
                    >
                        {t('common.actions.cancel')}
                    </Button>

                    <Button
                        variant={destructive ? 'destructive' : 'default'}
                        onClick={onConfirm}
                        disabled={processing}
                        aria-busy={processing || undefined}
                        className="gap-2"
                    >
                        {processing && <Spinner className="size-3.5" />}
                        {confirmLabel ?? t('common.actions.confirm')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
