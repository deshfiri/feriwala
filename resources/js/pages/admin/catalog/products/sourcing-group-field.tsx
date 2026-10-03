import { useState } from 'react';
import SectionCard from '@/components/section-card';
import SourcingGroupPicker, {
    type SourcingGroupOption,
} from '@/components/sourcing-group-picker';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTranslation } from '@/hooks/use-translation';

export type ProductSourcing = {
    groups: SourcingGroupOption[];
    can_select: boolean;
    can_create: boolean;
};

/**
 * Optional, while adding a product: put it into a sourcing group from a
 * pop-up -- choosing an existing group or creating one on the spot -- so the
 * product does not need a second visit to be matched with Supplier and
 * warehouse sources. Posts `sourcing_group_id` with the product form.
 */
export default function SourcingGroupField({
    sourcing,
}: {
    sourcing: ProductSourcing;
}) {
    const { t, locale } = useTranslation();
    const [open, setOpen] = useState(false);
    const [groups, setGroups] = useState(sourcing.groups);
    const [selected, setSelected] = useState('');

    const chosen = groups.find((group) => group.id === selected);

    return (
        <SectionCard
            title={t('catalog.products.sourcing_section')}
            description={t('catalog.products.sourcing_help')}
        >
            <input type="hidden" name="sourcing_group_id" value={selected} />

            <div className="flex flex-wrap items-center gap-2">
                <span className="text-sm">
                    {chosen
                        ? locale === 'bn'
                            ? chosen.name_bn
                            : chosen.name_en
                        : t('catalog.products.sourcing_none')}
                </span>

                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setOpen(true)}
                >
                    {chosen
                        ? t('catalog.products.sourcing_change')
                        : t('catalog.products.sourcing_choose')}
                </Button>

                {chosen && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => setSelected('')}
                    >
                        {t('catalog.products.sourcing_clear')}
                    </Button>
                )}
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {t('catalog.products.sourcing_choose')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('catalog.products.sourcing_help')}
                        </DialogDescription>
                    </DialogHeader>

                    <SourcingGroupPicker
                        groups={groups}
                        value={selected}
                        canCreate={sourcing.can_create}
                        onChange={setSelected}
                        onCreated={(group) =>
                            setGroups((current) => [group, ...current])
                        }
                    />

                    <DialogFooter>
                        <Button type="button" onClick={() => setOpen(false)}>
                            {t('sourcing.form.save')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </SectionCard>
    );
}
