import AppLogo from '@/components/app-logo';
import type { CmsMenuItem } from '@/types';

/**
 * A minimal single-row footer (§34) — logo, footer links, legal links,
 * copyright. Matches the `banij_landing` reference's own preference for a
 * light footer over a heavy multi-column one; content (tagline, copyright
 * text) comes from the `footer` section, links from the `footer`/`legal`
 * menus.
 */
export default function PublicFooter({
    tagline,
    copyrightText,
    footerLinks,
    legalLinks,
}: {
    tagline?: string;
    copyrightText: string;
    footerLinks: CmsMenuItem[];
    legalLinks: CmsMenuItem[];
}) {
    return (
        <footer className="border-border border-t">
            <div className="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-10 sm:px-6 md:flex-row md:items-center md:justify-between">
                <div className="flex items-center gap-2">
                    <AppLogo />
                    {tagline && (
                        <span className="text-muted-foreground hidden text-sm sm:inline">
                            {tagline}
                        </span>
                    )}
                </div>

                <nav
                    aria-label="Footer"
                    className="flex flex-wrap items-center gap-x-6 gap-y-2"
                >
                    {[...footerLinks, ...legalLinks].map((item) => (
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
                            className="text-muted-foreground hover:text-foreground text-sm"
                        >
                            {item.label}
                        </a>
                    ))}
                </nav>

                <p className="text-muted-foreground text-sm">{copyrightText}</p>
            </div>
        </footer>
    );
}
