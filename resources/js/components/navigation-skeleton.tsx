import { router } from '@inertiajs/react';
import { useEffect, useMemo, type ReactNode } from 'react';
import PageSkeleton from '@/components/states/page-skeleton';
import { useNavigationPending } from '@/hooks/use-navigation-pending';
import { learnFrom, specFor } from '@/lib/page-shape';

/**
 * Shows a skeleton in the shape of the screen being opened, in place of the one
 * being left, for as long as the next one takes to arrive.
 *
 * Wraps a layout's page content only — the sidebar and header stay put, which is
 * what makes a navigation feel instant rather than like a reload. After every
 * page renders, its layout is measured and remembered against its URL pattern
 * (see `lib/page-shape`), so the next visit to a screen like it is drawn from
 * what it really looks like.
 */
export default function NavigationSkeleton({
    mainId,
    children,
}: {
    mainId: string;
    children: ReactNode;
}) {
    const target = useNavigationPending();

    useEffect(() => {
        const learn = () =>
            // Two frames: the page's own effects and layout have to settle
            // before there is anything true to measure.
            requestAnimationFrame(() =>
                requestAnimationFrame(() =>
                    learnFrom(
                        document.getElementById(mainId),
                        window.location.pathname,
                    ),
                ),
            );

        learn();

        return router.on('success', learn);
    }, [mainId]);

    const spec = useMemo(
        () => (target === null ? null : specFor(target)),
        [target],
    );

    return spec === null ? <>{children}</> : <PageSkeleton spec={spec} />;
}
