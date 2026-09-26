import { Link } from '@inertiajs/react';
import { useState } from 'react';
import AppLogo from '@/components/app-logo';
import AppearanceToggleTab from '@/components/appearance-tabs';
import LanguageSwitcher from '@/components/language-switcher';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { login, register } from '@/routes';
import type { CmsMenuItem } from '@/types';

/**
 * The public site's header (§4, §34). Deliberately its own shell, not the
 * ERP `AppSidebarHeader` — a marketing page has no sidebar to toggle and no
 * breadcrumb to show, only a logo, a handful of nav links, and the two
 * doors every visitor is here to find: register and sign in.
 */
export default function PublicHeader({ items }: { items: CmsMenuItem[] }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    return (
        <header className="border-border bg-background/95 supports-[backdrop-filter]:bg-background/80 sticky top-0 z-30 border-b backdrop-blur">
            <div className="mx-auto flex max-w-6xl items-center gap-4 px-4 py-3 sm:px-6">
                <Link href="/" className="flex shrink-0 items-center gap-2">
                    <AppLogo />
                </Link>

                <nav
                    aria-label={t('public.nav.label')}
                    className="hidden min-w-0 flex-1 items-center gap-6 md:flex"
                >
                    {items.map((item) => (
                        <a
                            key={item.key}
                            href={item.href}
                            target={
                                item.target === 'blank' ? '_blank' : undefined
                            }
                            rel={
                                item.target === 'blank'
                                    ? 'noopener noreferrer'
                                    : undefined
                            }
                            className="text-muted-foreground hover:text-foreground text-sm font-medium"
                        >
                            {item.label}
                        </a>
                    ))}
                </nav>

                <div className="ms-auto hidden shrink-0 items-center gap-2 md:flex">
                    <LanguageSwitcher />
                    <AppearanceToggleTab />
                    <Button asChild variant="ghost" size="sm">
                        <Link href={login()}>{t('public.nav.sign_in')}</Link>
                    </Button>
                    <Button asChild size="sm">
                        <Link href={register()}>
                            {t('public.nav.register')}
                        </Link>
                    </Button>
                </div>

                <button
                    type="button"
                    className="focus-visible:ring-ring ms-auto rounded-md p-2 focus-visible:ring-2 md:hidden"
                    aria-expanded={open}
                    aria-controls="public-header-mobile-menu"
                    aria-label={t('public.nav.menu')}
                    onClick={() => setOpen((value) => !value)}
                >
                    <span className="block h-0.5 w-5 bg-current" />
                    <span className="mt-1 block h-0.5 w-5 bg-current" />
                    <span className="mt-1 block h-0.5 w-5 bg-current" />
                </button>
            </div>

            {open && (
                <nav
                    id="public-header-mobile-menu"
                    aria-label={t('public.nav.menu')}
                    className="border-border space-y-3 border-t px-4 py-4 md:hidden"
                >
                    {items.map((item) => (
                        <a
                            key={item.key}
                            href={item.href}
                            className="text-foreground block text-sm font-medium"
                        >
                            {item.label}
                        </a>
                    ))}

                    <div className="flex items-center gap-2 pt-2">
                        <LanguageSwitcher />
                        <AppearanceToggleTab />
                    </div>

                    <div className="flex flex-col gap-2 pt-2">
                        <Button asChild variant="outline" size="sm">
                            <Link href={login()}>
                                {t('public.nav.sign_in')}
                            </Link>
                        </Button>
                        <Button asChild size="sm">
                            <Link href={register()}>
                                {t('public.nav.register')}
                            </Link>
                        </Button>
                    </div>
                </nav>
            )}
        </header>
    );
}
