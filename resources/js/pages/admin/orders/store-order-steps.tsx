import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import SectionCard from '@/components/section-card';
import {
    StaffCourierPanel,
    type StaffCourierData,
} from '@/components/store-order-courier';
import StoreOrderTimeline from '@/components/store-order-timeline';
import type { StoreOrderTimelineData } from '@/components/store-order-timeline';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';

/** A storefront order's steps, as the admin order page receives them (D34). */
export type StoreOrderSteps = {
    timeline: StoreOrderTimelineData;
    /** The Steadfast booking panel; null when courier booking does not apply. */
    courier: StaffCourierData | null;
    urls: {
        /** `__action__` is replaced by the next action's key. */
        advance: string;
        cancel: string;
    };
    can: { advance: boolean; cancel: boolean };
};

/**
 * A storefront order's progress, its one next step and its courier booking, on
 * the order's own page — the separate Store Order page is gone, so nothing
 * about one order needs a second page.
 *
 * The next step is a named action, never a status picked from a list; the
 * server refuses anything else.
 */
export default function StoreOrderStepsPanel({
    steps,
}: {
    steps: StoreOrderSteps;
}) {
    const { t } = useTranslation();
    const form = useForm({ note: '' });
    const next = steps.timeline.next_action;

    const advance = (event: FormEvent) => {
        event.preventDefault();

        if (next) {
            form.post(steps.urls.advance.replace('__action__', next.key), {
                preserveScroll: true,
            });
        }
    };

    return (
        <>
            <SectionCard title={t('store_orders.timeline')}>
                <StoreOrderTimeline timeline={steps.timeline} />

                {steps.can.advance && next && (
                    <form
                        onSubmit={advance}
                        className="border-border mt-4 space-y-2 border-t pt-4"
                    >
                        <Button
                            type="submit"
                            disabled={form.processing}
                            data-test="next-action"
                        >
                            {next.label}
                        </Button>
                        <InputError
                            message={
                                (form.errors as Record<string, string>).action
                            }
                        />
                    </form>
                )}
            </SectionCard>

            {steps.courier && <StaffCourierPanel courier={steps.courier} />}
        </>
    );
}
