import { Form, router } from '@inertiajs/react';
import { ImageOff } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import SectionCard from '@/components/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import type AdminWebsiteProductController from '@/actions/App/Http/Controllers/Admin/WebsiteProductController';

export type ProductCandidate = {
    id: string;
    name: string;
    sku: string | null;
    image: string | null;
    /** Already sold on this storefront. */
    selected: boolean;
};

/**
 * Finding a catalogue product to add to a partner's storefront, for platform
 * staff.
 *
 * A partner chooses from the catalogue's own pages; staff acting for someone
 * else's shop have none, so the products page carries this search instead. The
 * server lists only what the partner could have chosen themselves, and adding
 * goes through the same route and the same checks as the partner's own — the
 * publish limit, the price bounds and every refusal are the server's, shown
 * here and never decided here.
 *
 * The search is a partial reload of `find` and `candidates` only, keeping the
 * rest of the query string, so the table's own search and page are undisturbed.
 */
export default function WebsiteProductPicker({
    find,
    candidates,
    storeForm,
}: {
    find: string;
    candidates: ProductCandidate[];
    storeForm: ReturnType<typeof AdminWebsiteProductController.store.form>;
}) {
    const { t } = useTranslation();
    const [term, setTerm] = useState(find);
    const searched = find.length >= 2;

    const search = (event: FormEvent) => {
        event.preventDefault();

        const params = new URLSearchParams(window.location.search);
        const value = term.trim();

        if (value === '') {
            params.delete('find');
        } else {
            params.set('find', value);
        }

        router.get(
            `${window.location.pathname}?${params.toString()}`,
            {},
            {
                only: ['find', 'candidates'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    return (
        <SectionCard
            title={t('website.products.picker_title')}
            description={t('website.products.picker_hint')}
        >
            <form
                onSubmit={search}
                role="search"
                className="flex flex-wrap items-center gap-2"
            >
                <Input
                    type="search"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                    placeholder={t('website.products.picker_search')}
                    aria-label={t('website.products.picker_search')}
                    maxLength={100}
                    className="max-w-sm"
                />
                <Button type="submit" size="sm" variant="outline">
                    {t('website.products.picker_button')}
                </Button>
            </form>

            {searched && candidates.length === 0 && (
                <p
                    className="text-muted-foreground mt-4 text-sm"
                    data-test="picker-none"
                >
                    {t('website.products.picker_none')}
                </p>
            )}

            {candidates.length > 0 && (
                <ul className="divide-border mt-4 divide-y" data-test="picker">
                    {candidates.map((product) => (
                        <li
                            key={product.id}
                            className="flex flex-wrap items-center gap-3 py-3"
                        >
                            <div className="bg-muted flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-lg">
                                {product.image ? (
                                    <img
                                        src={product.image}
                                        alt=""
                                        className="size-full object-cover"
                                    />
                                ) : (
                                    <ImageOff
                                        aria-hidden="true"
                                        className="text-muted-foreground size-5"
                                    />
                                )}
                            </div>

                            <div className="min-w-0 flex-1">
                                <div className="truncate text-sm font-medium">
                                    {product.name}
                                </div>
                                {product.sku && (
                                    <div className="text-muted-foreground text-xs">
                                        {product.sku}
                                    </div>
                                )}
                            </div>

                            {product.selected ? (
                                <span className="text-muted-foreground text-xs">
                                    {t('website.products.picker_added')}
                                </span>
                            ) : (
                                <Form
                                    {...storeForm}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing, errors }) => (
                                        <div className="space-y-1">
                                            <input
                                                type="hidden"
                                                name="product"
                                                value={product.id}
                                            />
                                            <Button
                                                type="submit"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                {processing && <Spinner />}
                                                {t(
                                                    'website.products.picker_add',
                                                )}
                                            </Button>
                                            <InputError
                                                message={
                                                    errors.product ??
                                                    errors.price
                                                }
                                            />
                                        </div>
                                    )}
                                </Form>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </SectionCard>
    );
}
