import { usePage } from '@inertiajs/react';
import type { ImgHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

type AppLogoIconProps = Omit<ImgHTMLAttributes<HTMLImageElement>, 'src'>;

/**
 * The platform logo, from the shared branding contract.
 *
 * An image rather than an inline SVG, because the logo is whatever an
 * administrator uploaded — so it takes image attributes, and it is sized by its
 * height with its width following. The shipped `/logo.png` is used if the
 * contract is absent.
 */
export default function AppLogoIcon({
    alt,
    className,
    ...props
}: AppLogoIconProps) {
    const { branding, name } = usePage().props;

    return (
        <img
            src={branding?.logo_url ?? '/logo.png'}
            alt={alt ?? name ?? 'Feriwala'}
            decoding="async"
            className={cn('object-contain', className)}
            {...props}
        />
    );
}
