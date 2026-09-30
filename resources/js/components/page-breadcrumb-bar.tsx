import { Breadcrumbs } from '@/components/breadcrumbs';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

/**
 * Where you are, set as a quiet strip above the page rather than inside the
 * header, after Able Pro — the header is left to search and the account.
 *
 * It sits on the same gutters and width cap as `PageContainer`, so the trail
 * lines up with the page title beneath it. Nothing renders when a page has
 * no breadcrumbs.
 */
export function PageBreadcrumbBar({
    breadcrumbs,
}: {
    breadcrumbs: BreadcrumbItemType[];
}) {
    if (breadcrumbs.length === 0) {
        return null;
    }

    return (
        <div className="mx-auto -mb-1 w-full max-w-[96rem] px-4 pt-4 sm:-mb-2 sm:px-6 sm:pt-5">
            <Breadcrumbs breadcrumbs={breadcrumbs} />
        </div>
    );
}
