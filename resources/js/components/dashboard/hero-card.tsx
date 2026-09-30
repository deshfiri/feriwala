import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import GreetingBanner from '@/components/dashboard/greeting-banner';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { DashboardGreeting, DashboardStanding } from '@/types';

/**
 * The dashboard's opening statement (§33.3).
 *
 * Answers two things in the order someone asks them: who am I, and is anything
 * wrong. The standing and the action sit together at the foot of the words so
 * the one thing to do next is found in the same place every visit.
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
        <GreetingBanner
            heading={heading}
            message={
                standing.needsAttention
                    ? t('dashboard.hero.needs_attention')
                    : t('dashboard.hero.active')
            }
        >
            <StatusPill tone={standing.tone} label={standing.label} />

            {/*
             * Only rendered when the server supplied somewhere to go. A
             * status with no screen behind it yet states the problem and
             * stops there, rather than offering a button that leads
             * nowhere.
             */}
            {standing.action && (
                <Button asChild>
                    <Link href={standing.action.href} prefetch>
                        {standing.action.label}
                        <ArrowRight aria-hidden="true" className="size-4" />
                    </Link>
                </Button>
            )}
        </GreetingBanner>
    );
}
