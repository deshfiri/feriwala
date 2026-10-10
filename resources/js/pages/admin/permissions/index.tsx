import { Form, Head } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import PermissionsController from '@/actions/App/Http/Controllers/Admin/PermissionsController';
import { index as permissionsIndex } from '@/routes/admin/permissions';
import ReasonDialog from '@/components/forms/reason-dialog';
import DataTable from '@/components/data-table/data-table';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import { index as rolesIndex } from '@/routes/admin/roles';
import type { Column, Paginator } from '@/types';
import ReasonTextarea from '@/components/forms/reason-textarea';

type PermissionRow = {
    name: string;
    module: string;
    action: string;
    description: string | null;
    is_system: boolean;
    is_archived: boolean;
    roles_count: number;
};

type ModuleOption = { value: string; label: string };

type Props = {
    permissions: Paginator<PermissionRow>;
    filters: { search: string; module: string; type: string };
    modules: ModuleOption[];
    can: { manage: boolean };
};

const ALL = 'all';
const RELOAD_PROPS = ['permissions', 'filters'];

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * The permission catalogue (Role and Permission management): every
 * permission that exists, System-bound (already checked by a policy, Gate,
 * route or action) or Custom/unbound (named for future use, granting
 * nothing by itself yet).
 */
export default function PermissionsIndex({ permissions, modules, can }: Props) {
    const { t } = useTranslation();
    const { getFilter, setFilter } = useTableQuery({ only: RELOAD_PROPS });

    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<PermissionRow | null>(null);
    const [archiving, setArchiving] = useState<PermissionRow | null>(null);

    const typeLabel = (row: PermissionRow) =>
        row.is_system
            ? t('access.permissions.system_bound')
            : t('access.permissions.custom_unbound');

    const columns: Column<PermissionRow>[] = [
        {
            key: 'name',
            header: t('access.permissions.columns.name'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate font-mono text-xs font-medium">
                        {row.name}
                    </div>
                    <div className="text-muted-foreground truncate text-xs">
                        {row.description ??
                            t('access.permissions.no_description')}
                    </div>
                </div>
            ),
        },
        {
            key: 'module',
            header: t('access.permissions.columns.module'),
            priority: 'secondary',
            cell: (row) => row.module,
        },
        {
            key: 'action',
            header: t('access.permissions.columns.action'),
            priority: 'secondary',
            cell: (row) => row.action,
        },
        {
            key: 'type',
            header: t('access.permissions.columns.type'),
            cell: (row) => (
                <StatusPill
                    tone={row.is_system ? 'info' : 'neutral'}
                    label={typeLabel(row)}
                />
            ),
        },
        {
            key: 'roles',
            header: t('access.permissions.columns.roles'),
            align: 'end',
            cell: (row) =>
                t('access.permissions.roles_count', { count: row.roles_count }),
        },
        {
            key: 'actions',
            header: '',
            alwaysVisible: true,
            align: 'end',
            cell: (row) =>
                can.manage && !row.is_system && !row.is_archived ? (
                    <div className="flex justify-end gap-1">
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setEditing(row)}
                        >
                            {t('access.permissions.edit')}
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setArchiving(row)}
                        >
                            {t('access.permissions.archive')}
                        </Button>
                    </div>
                ) : row.is_archived ? (
                    <StatusPill
                        tone="neutral"
                        label={t('access.roles.archived')}
                        className="ml-auto"
                    />
                ) : null,
        },
    ];

    return (
        <>
            <Head title={t('access.permissions.title')} />

            <PageContainer>
                <PageHeader
                    title={t('access.permissions.title')}
                    description={t('access.permissions.description')}
                    back={{
                        href: rolesIndex(),
                        label: t('access.roles.title'),
                    }}
                    actions={
                        can.manage && (
                            <Button onClick={() => setCreating(true)}>
                                <Plus className="size-4" aria-hidden="true" />
                                {t('access.permissions.create')}
                            </Button>
                        )
                    }
                />

                <DataTable
                    columns={columns}
                    paginator={permissions}
                    rowKey={(row) => row.name}
                    caption={t('access.permissions.title')}
                    searchPlaceholder={t(
                        'access.permissions.search_placeholder',
                    )}
                    onlyReload={RELOAD_PROPS}
                    filters={
                        <div className="flex flex-wrap items-center gap-2">
                            <Select
                                value={getFilter('module') ?? ALL}
                                onValueChange={(value) =>
                                    setFilter(
                                        'module',
                                        value === ALL ? undefined : value,
                                    )
                                }
                            >
                                <SelectTrigger
                                    size="sm"
                                    className="w-44"
                                    aria-label={t(
                                        'access.permissions.filters.module',
                                    )}
                                >
                                    <SelectValue
                                        placeholder={t(
                                            'access.permissions.filters.module',
                                        )}
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        {t(
                                            'access.permissions.filters.all_modules',
                                        )}
                                    </SelectItem>
                                    {modules.map((module) => (
                                        <SelectItem
                                            key={module.value}
                                            value={module.value}
                                        >
                                            {module.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            <Select
                                value={getFilter('type') ?? ALL}
                                onValueChange={(value) =>
                                    setFilter(
                                        'type',
                                        value === ALL ? undefined : value,
                                    )
                                }
                            >
                                <SelectTrigger
                                    size="sm"
                                    className="w-44"
                                    aria-label={t(
                                        'access.permissions.filters.type',
                                    )}
                                >
                                    <SelectValue
                                        placeholder={t(
                                            'access.permissions.filters.type',
                                        )}
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        {t(
                                            'access.permissions.filters.all_types',
                                        )}
                                    </SelectItem>
                                    <SelectItem value="system">
                                        {t('access.permissions.filters.system')}
                                    </SelectItem>
                                    <SelectItem value="custom">
                                        {t('access.permissions.filters.custom')}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    }
                    emptyState={
                        <EmptyState
                            title={t('access.permissions.empty_title')}
                            description={t(
                                'access.permissions.empty_description',
                            )}
                        />
                    }
                />
            </PageContainer>

            <Dialog
                open={creating}
                onOpenChange={(next) => !next && setCreating(false)}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {t('access.permissions.create')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('access.permissions.create_description')}
                        </DialogDescription>
                    </DialogHeader>

                    <Form
                        {...PermissionsController.store.form()}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setCreating(false)}
                        className="grid gap-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="permission-name">
                                        {t('access.permissions.name')}
                                    </Label>
                                    <Input
                                        id="permission-name"
                                        name="name"
                                        required
                                        autoComplete="off"
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        {t('access.permissions.name_help')}
                                    </p>
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="permission-description">
                                        {t('access.permissions.description')}
                                    </Label>
                                    <textarea
                                        id="permission-description"
                                        name="description"
                                        rows={2}
                                        className={controlClass}
                                    />
                                    <InputError message={errors.description} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="permission-reason">
                                        {t('access.permissions.reason')}
                                    </Label>
                                    <ReasonTextarea
                                        context="access"
                                        id="permission-reason"
                                        name="reason"
                                        rows={2}
                                        required
                                        minLength={5}
                                        className={controlClass}
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        {t('access.permissions.reason_help')}
                                    </p>
                                    <InputError message={errors.reason} />
                                </div>

                                <DialogFooter className="gap-2 sm:gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setCreating(false)}
                                        disabled={processing}
                                    >
                                        {t('common.actions.cancel')}
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        {t('access.permissions.submit')}
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={editing !== null}
                onOpenChange={(next) => !next && setEditing(null)}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {t('access.permissions.edit_title')}
                        </DialogTitle>
                    </DialogHeader>

                    {editing && (
                        <Form
                            {...PermissionsController.update.form(editing.name)}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setEditing(null)}
                            className="grid gap-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="permission-edit-description">
                                            {t(
                                                'access.permissions.description',
                                            )}
                                        </Label>
                                        <textarea
                                            id="permission-edit-description"
                                            name="description"
                                            rows={2}
                                            defaultValue={
                                                editing.description ?? ''
                                            }
                                            className={controlClass}
                                        />
                                        <InputError
                                            message={errors.description}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="permission-edit-reason">
                                            {t('access.permissions.reason')}
                                        </Label>
                                        <ReasonTextarea
                                            context="access"
                                            id="permission-edit-reason"
                                            name="reason"
                                            rows={2}
                                            required
                                            minLength={5}
                                            className={controlClass}
                                        />
                                        <InputError message={errors.reason} />
                                    </div>

                                    <DialogFooter className="gap-2 sm:gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => setEditing(null)}
                                            disabled={processing}
                                        >
                                            {t('common.actions.cancel')}
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            {t('access.permissions.submit')}
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    )}
                </DialogContent>
            </Dialog>

            {archiving && (
                <ReasonDialog
                    open={archiving !== null}
                    onClose={() => setArchiving(null)}
                    title={t('access.permissions.archive_title')}
                    description={t('access.permissions.archive_description')}
                    form={PermissionsController.archive.form(archiving.name)}
                    confirmLabel={t('access.permissions.archive')}
                    destructive
                />
            )}
        </>
    );
}

PermissionsIndex.layout = {
    breadcrumbs: [
        { title: 'access.permissions.title', href: permissionsIndex() },
    ],
};
