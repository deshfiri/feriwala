import { Link } from '@inertiajs/react';
import type { LinkRef } from '@/types/cases';

/**
 * A reference to another record: a link where the reader may open it, plain text
 * where they may not, so nothing on a screen leads to a refusal (D35).
 */
export default function LinkedRef({ item }: { item: LinkRef }) {
    return (
        <>
            {item.url ? (
                <Link
                    href={item.url}
                    className="font-mono underline-offset-4 hover:underline"
                >
                    {item.reference}
                </Link>
            ) : (
                <span className="font-mono">{item.reference}</span>
            )}
            {item.status_label ? (
                <span className="text-muted-foreground text-xs">
                    {' '}
                    ({item.status_label})
                </span>
            ) : null}
        </>
    );
}
