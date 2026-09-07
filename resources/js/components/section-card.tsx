import type { ReactNode } from 'react';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
} from '@/components/ui/card';
import { cn } from '@/lib/utils';

/**
 * One titled panel on a page (§33.2).
 *
 * Forms and detail screens are grouped into sections rather than run together,
 * so a long page can be scanned by heading instead of read top to bottom. The
 * heading level is a prop because a section is not always second-level — nesting
 * an `h3` under an `h2` is what lets a screen reader user move through the page
 * by structure.
 *
 * `tone="destructive"` is for panels holding an irreversible action. It marks the
 * panel, not the button: someone scrolling past should be able to tell that this
 * is the dangerous end of the page before they read a word of it.
 */
export default function SectionCard({
    title,
    description,
    actions,
    footer,
    tone = 'default',
    headingLevel: Heading = 'h2',
    contentClassName,
    className,
    children,
}: {
    title?: string;
    description?: string;
    /** Controls belonging to this section, shown beside its title. */
    actions?: ReactNode;
    footer?: ReactNode;
    tone?: 'default' | 'destructive';
    headingLevel?: 'h2' | 'h3';
    contentClassName?: string;
    className?: string;
    children: ReactNode;
}) {
    const hasHeader = Boolean(title || description || actions);

    return (
        <Card
            className={cn(
                'gap-0 overflow-hidden py-0',
                tone === 'destructive' && 'border-danger/40',
                className,
            )}
        >
            {hasHeader && (
                <CardHeader
                    className={cn(
                        'flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4',
                        tone === 'destructive' && 'border-b-danger/40',
                    )}
                >
                    <div className="min-w-0 space-y-1">
                        {title && (
                            <Heading
                                className={cn(
                                    'text-sm leading-none font-semibold tracking-tight',
                                    tone === 'destructive' && 'text-danger',
                                )}
                            >
                                {title}
                            </Heading>
                        )}
                        {description && (
                            <CardDescription>{description}</CardDescription>
                        )}
                    </div>

                    {actions && (
                        <div className="flex flex-wrap items-center gap-2">
                            {actions}
                        </div>
                    )}
                </CardHeader>
            )}

            <CardContent className={cn('px-5 py-4', contentClassName)}>
                {children}
            </CardContent>

            {footer && (
                <CardFooter className="bg-muted/40 flex flex-wrap justify-end gap-2 border-t px-5 py-3">
                    {footer}
                </CardFooter>
            )}
        </Card>
    );
}
