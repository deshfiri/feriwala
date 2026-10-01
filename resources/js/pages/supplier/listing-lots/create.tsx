import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import { store } from '@/routes/supplier/listing-lots';

export default function SupplierListingLotCreate() {
    const { t } = useTranslation();

    const form = useForm({
        title: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.post(store().url);
    };

    return (
        <>
            <Head title={t('supplier.listing_lots.create_title')} />

            <PageContainer width="narrow">
                <form onSubmit={submit} className="space-y-6">
                    <PageHeader
                        title={t('supplier.listing_lots.create_title')}
                        description={t(
                            'supplier.listing_lots.create_description',
                        )}
                    />

                    <SectionCard title={t('supplier.listing_lots.batch_title')}>
                        <FormField
                            label={t('supplier.listing_lots.batch_title')}
                            error={form.errors.title}
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    value={form.data.title}
                                    onChange={(e) =>
                                        form.setData('title', e.target.value)
                                    }
                                />
                            )}
                        </FormField>
                    </SectionCard>

                    <SubmitButton processing={form.processing}>
                        {t('supplier.listing_lots.start')}
                    </SubmitButton>
                </form>
            </PageContainer>
        </>
    );
}
