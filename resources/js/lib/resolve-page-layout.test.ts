import { describe, expect, it } from 'vitest';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import SupplierAuthLayout from '@/layouts/supplier-auth-layout';
import SupplierLayout from '@/layouts/supplier-layout';
import { resolvePageLayout } from './resolve-page-layout';

/**
 * A guest hitting the public landing page once crashed with
 * "Cannot read properties of null (reading 'name')" because this resolver
 * had no `public/*` case and fell through to the authenticated ERP shell,
 * which assumes `auth.user` is never null. Every page name this resolver
 * sees is covered here so that gap can't reopen unnoticed.
 */
describe('resolvePageLayout', () => {
    it('gives the welcome and every public page no shell -- they build their own', () => {
        expect(resolvePageLayout('welcome')).toBeNull();
        expect(resolvePageLayout('public/landing')).toBeNull();
        expect(resolvePageLayout('public/landing-unavailable')).toBeNull();
    });

    it('routes auth and supplier-auth pages to their own auth shells', () => {
        expect(resolvePageLayout('auth/login')).toBe(AuthLayout);
        expect(resolvePageLayout('supplier/auth/login')).toBe(
            SupplierAuthLayout,
        );
    });

    it('routes supplier pages to the Supplier shell', () => {
        expect(resolvePageLayout('supplier/dashboard')).toBe(SupplierLayout);
    });

    it('nests settings pages inside the ERP shell', () => {
        expect(resolvePageLayout('settings/profile')).toEqual([
            AppLayout,
            SettingsLayout,
        ]);
    });

    it('falls back to the ERP shell for everything else', () => {
        expect(resolvePageLayout('dashboard')).toBe(AppLayout);
        expect(resolvePageLayout('admin/dashboard')).toBe(AppLayout);
    });
});
