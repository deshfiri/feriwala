import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * The one place the public page's max-width and vertical rhythm are decided
 * (mirrors `PageContainer`'s role in the ERP shell, adapted for a marketing
 * page rather than a dashboard: wider reading measure, more breathing room
 * between sections).
 */
export default function SectionContainer({
    children,
    className,
    as: Tag = 'section',
}: {
    children: ReactNode;
    className?: string;
    as?: 'section' | 'div';
}) {
    return (
        <Tag className={cn('py-14 sm:py-20', className)}>
            <div className="mx-auto max-w-6xl px-4 sm:px-6">{children}</div>
        </Tag>
    );
}
