import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import SupplierAuthLayout from '@/layouts/supplier-auth-layout';
import SupplierLayout from '@/layouts/supplier-layout';

/**
 * Maps an Inertia page name to its shell. `welcome` and every `public/*` page
 * build their own full-page chrome (header/nav/footer) and must resolve to
 * `null` -- falling through to the default ERP `AppLayout` crashes for a
 * guest, since `AppLayout` assumes an authenticated `auth.user`.
 */
export function resolvePageLayout(name: string) {
    switch (true) {
        case name === 'welcome':
            return null;
        case name.startsWith('public/'):
            return null;
        case name.startsWith('auth/'):
            return AuthLayout;
        // The Supplier account domain (D25) has its own shells, visibly
        // separate from the Client/Partner ERP.
        case name.startsWith('supplier/auth/'):
            return SupplierAuthLayout;
        case name.startsWith('supplier/'):
            return SupplierLayout;
        case name.startsWith('settings/'):
            return [AppLayout, SettingsLayout];
        default:
            return AppLayout;
    }
}
