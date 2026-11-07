import { Check, Copy } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { useClipboard } from '@/hooks/use-clipboard';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

/**
 * An identifier that copies itself when clicked — a partner number a staff
 * member reads out, pastes into a ticket or searches for elsewhere.
 *
 * The value is shown as it is and is the click target. Copying says so twice:
 * the icon turns into a tick, and a toast names what was copied ("Partner ID
 * BJ… copied"). The toast is also how a screen reader is told, since sonner
 * announces its own messages and the icon changing is not something it can see
 * (§33.9). A screen reader hears the value followed by what pressing it does
 * ("BJ… Copy Partner ID").
 */
export default function CopyableText({
    value,
    label,
    className,
}: {
    value: string;
    /** What the value is, in words — "Partner ID" — for the spoken hint. */
    label: string;
    className?: string;
}) {
    const { t } = useTranslation();
    const [, copy] = useClipboard();
    const [justCopied, setJustCopied] = useState(false);
    const timer = useRef<number | undefined>(undefined);

    useEffect(() => () => window.clearTimeout(timer.current), []);

    const onClick = async () => {
        if (!(await copy(value))) {
            return;
        }

        toast.success(t('common.clipboard.copied', { label, value }));
        setJustCopied(true);
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => setJustCopied(false), 2000);
    };

    const Icon = justCopied ? Check : Copy;

    return (
        <button
            type="button"
            onClick={() => void onClick()}
            title={t('common.clipboard.copy', { label })}
            className={cn(
                'text-foreground hover:bg-accent focus-visible:ring-ring -mx-1.5 inline-flex max-w-full cursor-pointer items-center gap-1.5 rounded-md px-1.5 py-0.5 font-mono text-xs tabular-nums transition focus-visible:ring-2 focus-visible:outline-none',
                className,
            )}
        >
            <span className="truncate">{value}</span>
            <Icon
                aria-hidden="true"
                className="text-muted-foreground size-3.5 shrink-0"
            />
            <span className="sr-only">
                {t('common.clipboard.copy', { label })}
            </span>
        </button>
    );
}
