import PageContainer from '@/components/page-container';
import TableSkeleton from '@/components/states/table-skeleton';
import { StatCardGrid } from '@/components/stat-card';
import { useTranslation } from '@/hooks/use-translation';
import type { PageSpec } from '@/lib/page-shape';
import { cn } from '@/lib/utils';

/** One pulsing block. The same ground the table skeleton uses. */
function Block({
    className,
    delay = 0,
}: {
    className?: string;
    delay?: number;
}) {
    return (
        <div
            className={cn('bg-muted animate-pulse rounded-md', className)}
            style={delay ? { animationDelay: `${delay}ms` } : undefined}
        />
    );
}

/** A panel's own surface, so a card-shaped gap reads as a card. */
function Panel({
    children,
    className,
}: {
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'bg-card overflow-hidden rounded-xl border shadow-sm',
                className,
            )}
        >
            {children}
        </div>
    );
}

/**
 * The header every screen opens with: a title, a line of description, and —
 * where the real one has them — a row of actions. The same heights as
 * `PageHeader`, so nothing moves when the page arrives.
 */
function HeaderSkeleton({ actions }: { actions: boolean }) {
    return (
        <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div className="space-y-1.5">
                <Block className="h-7 w-56 sm:h-8 sm:w-72" />
                <Block className="h-4 w-full max-w-xl" delay={80} />
            </div>
            {actions && <Block className="h-9 w-32 rounded-lg" delay={120} />}
        </div>
    );
}

function StatRowSkeleton({ count }: { count: number }) {
    return (
        <StatCardGrid>
            {Array.from({ length: count }).map((_, index) => (
                <Panel key={index} className="space-y-3 p-5">
                    <Block className="h-3 w-24" delay={index * 60} />
                    <Block className="h-8 w-20" delay={index * 60 + 40} />
                </Panel>
            ))}
        </StatCardGrid>
    );
}

function SectionSkeleton({ index }: { index: number }) {
    return (
        <Panel>
            <div className="space-y-1.5 border-b px-5 py-4">
                <Block className="h-5 w-40" delay={index * 80} />
                <Block
                    className="h-4 w-72 max-w-full"
                    delay={index * 80 + 40}
                />
            </div>
            <div className="grid gap-4 px-5 py-5 sm:grid-cols-2">
                {Array.from({ length: 4 }).map((_, field) => (
                    <div key={field} className="space-y-2">
                        <Block className="h-3 w-24" delay={field * 50} />
                        <Block
                            className="h-9 w-full rounded-lg"
                            delay={field * 50 + 30}
                        />
                    </div>
                ))}
            </div>
        </Panel>
    );
}

/**
 * A screen's skeleton, in the shape of that screen.
 *
 * Inside the same `PageContainer` as the real thing — same gutters, same
 * rhythm, same width — and built from the measured spec of the screen it stands
 * for: the table has its real column and row count, the stat row its real number
 * of figures, a form its narrow width. A skeleton that is the wrong shape moves
 * the whole page when the real one lands, which is worse than none.
 */
export default function PageSkeleton({ spec }: { spec: PageSpec }) {
    const { t } = useTranslation();

    return (
        <PageContainer width={spec.narrow ? 'narrow' : 'full'}>
            <div role="status" aria-busy="true" className="contents">
                <span className="sr-only">{t('common.table.loading')}</span>

                <HeaderSkeleton actions={spec.actions} />

                {spec.stats >= 2 && <StatRowSkeleton count={spec.stats} />}

                {spec.shape === 'list' && (
                    <Panel>
                        <div className="flex min-h-14 flex-wrap items-center gap-2 border-b px-4 py-3">
                            <Block className="h-8 w-full rounded-lg sm:w-72" />
                            <Block className="h-8 w-28 rounded-lg" delay={60} />
                            <Block
                                className="h-8 w-28 rounded-lg"
                                delay={120}
                            />
                            <Block
                                className="ml-auto h-8 w-24 rounded-lg"
                                delay={180}
                            />
                        </div>
                        <TableSkeleton
                            rows={spec.rows}
                            columns={spec.columns}
                        />
                        <div className="flex items-center justify-between border-t px-4 py-3">
                            <Block className="h-4 w-40" />
                            <Block className="h-8 w-48 rounded-lg" delay={80} />
                        </div>
                    </Panel>
                )}

                {spec.shape === 'dashboard' && (
                    <div className="grid gap-4 lg:grid-cols-2">
                        {Array.from({ length: Math.max(spec.sections, 2) }).map(
                            (_, index) => (
                                <Panel key={index} className="space-y-4 p-5">
                                    <Block
                                        className="h-5 w-40"
                                        delay={index * 80}
                                    />
                                    <Block
                                        className="h-56 w-full rounded-lg"
                                        delay={index * 80 + 40}
                                    />
                                </Panel>
                            ),
                        )}
                    </div>
                )}

                {(spec.shape === 'form' || spec.shape === 'detail') &&
                    Array.from({ length: spec.sections }).map((_, index) => (
                        <SectionSkeleton key={index} index={index} />
                    ))}
            </div>
        </PageContainer>
    );
}
