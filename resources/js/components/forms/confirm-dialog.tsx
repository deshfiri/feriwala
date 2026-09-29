import { AlertTriangle } from 'lucide-react';
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
 * A destructive confirmation also carries a warning mark beside its title, so the
 * dialog looks different from a routine one before a word of it is read.
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
                <DialogHeader className="gap-3 sm:flex-row sm:items-start sm:gap-4">
                    {destructive && (
                        <span
                            aria-hidden="true"
                            className="bg-danger-subtle text-danger mx-auto flex size-10 shrink-0 items-center justify-center rounded-full sm:mx-0"
                        >
                            <AlertTriangle className="size-5" />
                        </span>
                    )}

                    <div className="space-y-1.5">
                        <DialogTitle>{title}</DialogTitle>
                        {description && (
                            <DialogDescription>{description}</DialogDescription>
                        )}
                    </div>
                </DialogHeader>

                {summary && (
                    <div className="bg-surface-subtle rounded-lg border px-4 py-3 text-sm">
                        {summary}
                    </div>
                )}

                <DialogFooter className="gap-2 sm:gap-2">
                    <Button
                        variant="outline"
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
