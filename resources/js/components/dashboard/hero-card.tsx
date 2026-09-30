import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { DashboardGreeting, DashboardStanding } from '@/types';

/**
 * The dashboard's opening statement (§33.3).
 *
 * Answers two things in the order someone asks them: who am I, and is anything
 * wrong. §33.1 rules out decoration without purpose, so there is no
 * illustration — the space goes to the standing and the action instead, which
 * sit on their own footer so the one thing to do next is found in the same
 * place every visit.
 *
 * `needsAttention` comes from the server rather than being inferred from the
 * tone here. Whether a lapsed package is worth leading the page with is a
 * business decision, and reading a colour name to make it would put that
 * decision in the wrong place.
 */
export default function HeroCard({
    greeting,
    standing,
}: {
    greeting: DashboardGreeting;
    standing: DashboardStanding;
}) {
    const { t } = useTranslation();

    const heading =
        greeting.name === ''
            ? t('dashboard.greeting.fallback')
            : t(`dashboard.greeting.${greeting.period}`, {
                  name: greeting.name,
              });

    return (
        <section className="bg-card text-card-foreground flex h-full flex-col overflow-hidden rounded-xl border shadow-sm">
            <div className="flex-1 space-y-2 p-6">
                <h1 className="text-2xl font-semibold tracking-tight text-balance">
                    {heading}
                </h1>
                <p className="text-muted-foreground max-w-prose text-sm text-balance">
                    {standing.needsAttention
                        ? t('dashboard.hero.needs_attention')
                        : t('dashboard.hero.active')}
                </p>
            </div>

            <div className="bg-surface-subtle flex flex-wrap items-center justify-between gap-x-4 gap-y-3 border-t px-6 py-4">
                <StatusPill tone={standing.tone} label={standing.label} />

                {/*
                 * Only rendered when the server supplied somewhere to go. A
                 * status with no screen behind it yet states the problem and
                 * stops there, rather than offering a button that leads
                 * nowhere.
                 */}
                {standing.action && (
                    <Button asChild size="sm">
                        <Link href={standing.action.href} prefetch>
                            {standing.action.label}
                            <ArrowRight aria-hidden="true" className="size-4" />
                        </Link>
                    </Button>
                )}
            </div>
        </section>
    );
}
