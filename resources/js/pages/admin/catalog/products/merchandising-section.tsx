import { router, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronUp, Plus, Search, Star, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import ProductMerchandisingController from '@/actions/App/Http/Controllers/Admin/ProductMerchandisingController';
import AlertError from '@/components/alert-error';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import type {
    CatalogAbilities,
    MerchandisingState,
    ProductDetail,
    RelatedProductRow,
} from '@/types';

type Props = {
    product: ProductDetail;
    merchandising: MerchandisingState;
    relatedMatches: RelatedProductRow[] | undefined;
    can: CatalogAbilities;
};

/**
 * Featured status and related products (§11.1).
 *
 * The related list is edited here and saved whole, in the order shown. It may
 * name products in any status: what a partner actually sees is filtered by
 * eligibility on the server when their page is rendered.
 */
export default function MerchandisingSection({
    product,
    merchandising,
    relatedMatches,
    can,
}: Props) {
    const { t } = useTranslation();
    const page = usePage<{ errors: Record<string, string> }>();
    const errors = page.props.errors ?? {};

    const [related, setRelated] = useState(merchandising.related);
    const [search, setSearch] = useState('');
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        setRelated(merchandising.related);
    }, [merchandising]);

    const move = (index: number, direction: -1 | 1) =>
        setRelated((current) => {
            const target = index + direction;

            if (target < 0 || target >= current.length) {
                return current;
            }

            const next = [...current];
            [next[index], next[target]] = [next[target], next[index]];

            return next;
        });

    const find = () =>
        router.reload({
            only: ['related_matches'],
            data: { related_search: search },
        });

    const save = () =>
        router.put(
            ProductMerchandisingController.related.url(product.id),
            { related_ids: related.map((item) => item.id) },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );

    const toggleFeatured = () =>
        router.patch(
            ProductMerchandisingController.featured.url(product.id),
            { featured: !merchandising.is_featured },
            { preserveScroll: true },
        );

    const relatedErrors = Object.entries(errors)
        .filter(([key]) => key.startsWith('related_ids'))
        .map(([, message]) => message);

    return (
        <SectionCard
            title={t('catalog.related.title')}
            description={t('catalog.related.description')}
        >
            <div className="space-y-6">
                <div className="flex flex-wrap items-center gap-3">
                    <StatusPill
                        tone={merchandising.is_featured ? 'info' : 'neutral'}
                        label={t(
                            merchandising.is_featured
                                ? 'catalog.related.featured'
                                : 'catalog.related.not_featured',
                        )}
                    />
                    {merchandising.featured_at && (
                        <span className="text-muted-foreground text-xs">
                            {t('catalog.related.featured_since', {
                                date: new Date(
                                    merchandising.featured_at,
                                ).toLocaleDateString(),
                            })}
                        </span>
                    )}
                    {can.publish && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={toggleFeatured}
                        >
                            <Star className="size-4" aria-hidden="true" />
                            {t(
                                merchandising.is_featured
                                    ? 'catalog.related.unfeature'
                                    : 'catalog.related.feature',
                            )}
                        </Button>
                    )}
                </div>

                <div className="space-y-3">
                    <h3 className="text-sm font-medium">
                        {t('catalog.related.related')}
                    </h3>

                    {relatedErrors.length > 0 && (
                        <AlertError errors={relatedErrors} />
                    )}

                    {related.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            {t('catalog.related.none')}
                        </p>
                    ) : (
                        <ol className="divide-border divide-y rounded-md border">
                            {related.map((item, index) => (
                                <li
                                    key={item.id}
                                    className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm"
                                >
                                    <span className="min-w-0">
                                        <span className="font-medium">
                                            {item.name}
                                        </span>{' '}
                                        <span className="text-muted-foreground font-mono text-xs">
                                            {item.sku}
                                        </span>
                                    </span>
                                    <span className="flex items-center gap-1">
                                        <StatusPill
                                            tone={item.status_tone ?? 'neutral'}
                                            label={t(
                                                `catalog.products.status.${item.status}`,
                                            )}
                                        />
                                        {can.edit && (
                                            <>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-7"
                                                    disabled={index === 0}
                                                    onClick={() =>
                                                        move(index, -1)
                                                    }
                                                    aria-label={t(
                                                        'catalog.related.move_up',
                                                    )}
                                                >
                                                    <ChevronUp className="size-4" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-7"
                                                    disabled={
                                                        index ===
                                                        related.length - 1
                                                    }
                                                    onClick={() =>
                                                        move(index, 1)
                                                    }
                                                    aria-label={t(
                                                        'catalog.related.move_down',
                                                    )}
                                                >
                                                    <ChevronDown className="size-4" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-7"
                                                    onClick={() =>
                                                        setRelated((current) =>
                                                            current.filter(
                                                                (candidate) =>
                                                                    candidate.id !==
                                                                    item.id,
                                                            ),
                                                        )
                                                    }
                                                    aria-label={`${t('catalog.related.remove')} ${item.name}`}
                                                >
                                                    <X className="size-4" />
                                                </Button>
                                            </>
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    )}

                    {can.edit && (
                        <>
                            <div className="flex max-w-md items-end gap-2">
                                <div className="grid flex-1 gap-1.5">
                                    <Label htmlFor="related-search">
                                        {t('catalog.related.find')}
                                    </Label>
                                    <Input
                                        id="related-search"
                                        value={search}
                                        onChange={(event) =>
                                            setSearch(event.target.value)
                                        }
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter') {
                                                event.preventDefault();
                                                find();
                                            }
                                        }}
                                    />
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={find}
                                >
                                    <Search
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t('catalog.related.search')}
                                </Button>
                            </div>

                            {relatedMatches !== undefined &&
                                (relatedMatches.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">
                                        {t('catalog.related.no_matches')}
                                    </p>
                                ) : (
                                    <ul className="divide-border max-w-md divide-y rounded-md border">
                                        {relatedMatches.map((match) => (
                                            <li
                                                key={match.id}
                                                className="flex items-center justify-between gap-2 px-3 py-2 text-sm"
                                            >
                                                <span>
                                                    {match.name}{' '}
                                                    <span className="text-muted-foreground font-mono text-xs">
                                                        {match.sku}
                                                    </span>
                                                </span>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={related.some(
                                                        (item) =>
                                                            item.id ===
                                                            match.id,
                                                    )}
                                                    onClick={() =>
                                                        setRelated(
                                                            (current) => [
                                                                ...current,
                                                                match,
                                                            ],
                                                        )
                                                    }
                                                >
                                                    <Plus
                                                        className="size-4"
                                                        aria-hidden="true"
                                                    />
                                                    {t('catalog.related.add')}
                                                </Button>
                                            </li>
                                        ))}
                                    </ul>
                                ))}

                            <Button
                                type="button"
                                disabled={processing}
                                onClick={save}
                            >
                                {t('catalog.related.save')}
                            </Button>
                        </>
                    )}
                </div>
            </div>
        </SectionCard>
    );
}
