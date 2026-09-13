import { usePage } from '@inertiajs/react';

import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    const { name } = usePage().props;

    return (
        <>
            <div className="bg-sidebar-primary text-sidebar-primary-foreground flex aspect-square size-8 shrink-0 items-center justify-center rounded-lg shadow-xs">
                <AppLogoIcon
                    aria-hidden="true"
                    className="size-5 fill-current"
                />
            </div>
            <div className="ml-0.5 grid flex-1 text-left">
                <span className="text-foreground truncate text-[0.9375rem] leading-tight font-semibold tracking-tight">
                    {name}
                </span>
            </div>
        </>
    );
}
