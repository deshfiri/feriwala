import { usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';

/**
 * The logo in the sidebar and header.
 *
 * The same uploaded image in both sidebar states: at full width when the rail
 * is expanded, contained within the icon-sized square when it is collapsed. The
 * name stays for screen readers, since the image itself is decorative here.
 */
export default function AppLogo() {
    const { name } = usePage().props;

    return (
        <>
            <AppLogoIcon
                alt=""
                aria-hidden="true"
                className="h-8 w-auto max-w-40 object-left group-data-[collapsible=icon]:size-8 group-data-[collapsible=icon]:object-center"
            />
            <span className="sr-only">{name}</span>
        </>
    );
}
