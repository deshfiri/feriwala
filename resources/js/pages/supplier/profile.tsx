import { Form, Head } from '@inertiajs/react';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import TextArea from '@/components/forms/text-area';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import { update } from '@/routes/supplier/profile';

export default function SupplierProfile({
    profile,
}: {
    profile: {
        reference: string;
        business_name: string;
        contact_person_name: string;
        business_address: string;
        email: string;
        mobile: string;
        trade_licence_number: string | null;
        tax_identification_number: string | null;
        locale: string;
    };
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('supplier.profile.title')} />

            <div className="mx-auto max-w-2xl space-y-6">
                <PageHeader
                    title={t('supplier.profile.title')}
                    description={t('supplier.profile.description')}
                />

                <SectionCard
                    title={profile.business_name}
                    description={profile.reference}
                >
                    <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                        {[
                            [t('supplier.fields.email'), profile.email],
                            [t('supplier.fields.mobile'), profile.mobile],
                            [
                                t('supplier.fields.trade_licence_number'),
                                profile.trade_licence_number,
                            ],
                            [
                                t('supplier.fields.tax_identification_number'),
                                profile.tax_identification_number,
                            ],
                        ].map(([label, value]) => (
                            <div key={label}>
                                <dt className="text-muted-foreground text-xs">
                                    {label}
                                </dt>
                                <dd className="font-medium break-words">
                                    {value ?? '—'}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </SectionCard>

                <SectionCard title={t('supplier.profile.title')}>
                    <Form
                        {...update.form()}
                        options={{ preserveScroll: true }}
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <FormField
                                    label={t(
                                        'supplier.fields.contact_person_name',
                                    )}
                                    error={errors.contact_person_name}
                                    required
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            name="contact_person_name"
                                            defaultValue={
                                                profile.contact_person_name
                                            }
                                            required
                                        />
                                    )}
                                </FormField>
                                <FormField
                                    label={t(
                                        'supplier.fields.business_address',
                                    )}
                                    error={errors.business_address}
                                    required
                                >
                                    {(field) => (
                                        <TextArea
                                            {...field}
                                            name="business_address"
                                            defaultValue={
                                                profile.business_address
                                            }
                                            required
                                        />
                                    )}
                                </FormField>
                                <FormField
                                    label={t('supplier.fields.language')}
                                    error={errors.locale}
                                    required
                                >
                                    {(field) => (
                                        <select
                                            {...field}
                                            name="locale"
                                            defaultValue={profile.locale}
                                            className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                                        >
                                            <option value="en">English</option>
                                            <option value="bn">বাংলা</option>
                                        </select>
                                    )}
                                </FormField>
                                <SubmitButton processing={processing}>
                                    {t('supplier.profile.save')}
                                </SubmitButton>
                            </>
                        )}
                    </Form>
                </SectionCard>
            </div>
        </>
    );
}
