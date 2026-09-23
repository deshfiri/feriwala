import { Link } from '@inertiajs/react';
import { Truck } from 'lucide-react';
import type { ReactNode } from 'react';
import AppearanceToggleTab from '@/components/appearance-tabs';
import BrandingHead from '@/components/branding-head';
import LanguageSwitcher from '@/components/language-switcher';
import { useTranslation } from '@/hooks/use-translation';
import { login } from '@/routes';

/**
 * The shell for Supplier registration, sign in and password reset (D25).
 *
 * Deliberately not the Client/Partner `AuthLayout`: the Supplier account is a
 * separate domain, and the person arriving here should be able to tell at a
 * glance that they are on the Supplier side — with a way across to the
 * Client/Partner sign in if they came to the wrong door.
 */
export default function SupplierAuthLayout({
    titleKey,
    descriptionKey,
    children,
}: {
    titleKey?: string;
    descriptionKey?: string;
    children: ReactNode;
}) {
    const { t } = useTranslation();

    return (
        <>
            <BrandingHead />
            <div className="bg-background flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
                <div className="absolute end-4 top-4 flex items-center gap-2">
                    <LanguageSwitcher />
                    <AppearanceToggleTab />
                </div>

                <div className="w-full max-w-sm">
                    <div className="flex flex-col gap-8">
                        <div className="flex flex-col items-center gap-3">
                            <span className="bg-brand-subtle text-brand inline-flex items-center gap-2 rounded-md px-3 py-1 text-sm font-medium">
                                <Truck className="size-4" aria-hidden="true" />
                                {t('supplier.portal_name')}
                            </span>

                            <div className="space-y-2 text-center">
                                <h1 className="text-xl font-medium">
                                    {titleKey ? t(titleKey) : ''}
                                </h1>
                                {descriptionKey && (
                                    <p className="text-muted-foreground text-center text-sm">
                                        {t(descriptionKey)}
                                    </p>
                                )}
                            </div>
                        </div>

                        {children}

                        <p className="text-muted-foreground text-center text-xs">
                            {t('supplier.auth.client_notice')}{' '}
                            <Link href={login()} className="underline">
                                {t('supplier.auth.login_submit')}
                            </Link>
                        </p>
                    </div>
                </div>
            </div>
        </>
    );
}
