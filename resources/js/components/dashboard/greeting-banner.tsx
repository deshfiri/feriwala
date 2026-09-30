import type { ReactNode } from 'react';
import GreetingIllustration from '@/components/dashboard/greeting-illustration';
import { cn } from '@/lib/utils';

/**
 * The opening card of every dashboard, after Isomorphic: a large greeting and
 * one line of standing on the left, the storefront illustration on the right,
 * and whatever the page offers next beneath the words.
 *
 * Purely the frame. What the heading says, whether anything needs attention
 * and which action to offer are all decided by the page and the server; this
 * only lays them out, so the ERP, Admin and Supplier dashboards open the same
 * way.
 */
export default function GreetingBanner({
    heading,
    message,
    wave = true,
    children,
    className,
}: {
    heading: string;
    message: string;
    /** The hand after the heading. Off when the heading is not a greeting. */
    wave?: boolean;
    /** Status and actions, shown beneath the message. */
    children?: ReactNode;
    className?: string;
}) {
    return (
        <section
            className={cn(
                'bg-card text-card-foreground relative flex h-full items-stretch overflow-hidden rounded-xl border',
                className,
            )}
        >
            <div className="flex min-w-0 flex-1 flex-col justify-between gap-6 p-6 sm:p-8">
                <div className="space-y-3">
                    <h1 className="text-2xl leading-tight font-bold tracking-tight text-balance sm:text-3xl">
                        {heading}
                        {wave && (
                            <span
                                aria-hidden="true"
                                className="ml-2 inline-block"
                            >
                                👋
                            </span>
                        )}
                    </h1>
                    <p className="text-muted-foreground max-w-prose text-sm text-balance sm:text-base">
                        {message}
                    </p>
                </div>

                {children && (
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-3">
                        {children}
                    </div>
                )}
            </div>

            <GreetingIllustration className="hidden w-52 shrink-0 self-end pt-4 pr-6 pb-2 sm:block lg:w-60" />
        </section>
    );
}
