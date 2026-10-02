<?php

namespace App\Domain\Settings\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Settings\AccentColor;
use App\Domain\Settings\Branding;
use App\Domain\Settings\Enums\BrandingAsset;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\Policies\BrandingPolicy;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Storage\Actions\DeleteManagedFile;
use App\Domain\Storage\Actions\StoreManagedFile;
use App\Domain\Storage\Enums\StorageVisibility;
use App\Domain\Storage\Exceptions\UnacceptableFile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Throwable;

/**
 * Replace the logo or browser icon, or put the shipped default back — and
 * choose the accent colour, or put the shipped accent back.
 *
 * Settings hold a managed storage path and nothing else — never the image's
 * bytes, never a data URI. The file and the setting are kept in step: the new
 * file is written first and removed if the setting cannot be saved; the file it
 * replaces is removed only after the setting naming the new one is saved.
 * Saving goes through {@see SettingsRepository::set()}, which clears the
 * settings cache, so the next page anybody loads shows the new image.
 *
 * The file type is read from the bytes and checked again here, so a caller that
 * skipped the form's validation still cannot store an SVG or a script.
 */
class ManageBranding
{
    public function __construct(
        protected SettingsRepository $settings,
        protected Branding $branding,
        protected RecordAuditLog $audit,
        protected StoreManagedFile $storeFile,
        protected DeleteManagedFile $deleteFile,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException when the file is not an accepted image
     */
    public function replace(User $actor, BrandingAsset $asset, UploadedFile $file): string
    {
        $this->authorize($actor);

        $previous = $this->currentPath($asset);

        try {
            $stored = $this->storeFile->handle(
                file: $file,
                purpose: Branding::FOLDER.'/'.$asset->value,
                visibility: StorageVisibility::Public,
                allowedMimeTypes: array_keys($asset->acceptedTypes()),
                maxBytes: $asset->maxKilobytes() * 1024,
                createdBy: $actor->id,
                extensionsByMime: $asset->acceptedTypes(),
            );
        } catch (UnacceptableFile $exception) {
            throw new InvalidArgumentException($exception->getMessage(), previous: $exception);
        }

        $path = $stored->path;

        try {
            $this->define($asset);
            $this->settings->set($asset->setting(), $path, $actor->id);
        } catch (Throwable $failure) {
            $this->deleteFile->handle($stored);

            throw $failure;
        }

        $this->removeFile($previous);
        $this->record($actor, $asset, $previous, $path);

        return $path;
    }

    /**
     * @throws AuthorizationException
     */
    public function restore(User $actor, BrandingAsset $asset): void
    {
        $this->authorize($actor);

        $previous = $this->currentPath($asset);

        $this->define($asset);
        $this->settings->set($asset->setting(), null, $actor->id);

        $this->removeFile($previous);
        $this->record($actor, $asset, $previous, null);
    }

    /**
     * Choose the accent colour, or pass null to go back to the shipped one.
     *
     * @throws AuthorizationException
     */
    public function setAccent(User $actor, ?AccentColor $accent): void
    {
        $this->authorize($actor);

        $previous = $this->branding->customAccent()?->hex();
        $next = $accent === null || $accent->isDefault() ? null : $accent->hex();

        $this->settings->define(
            Branding::ACCENT_SETTING,
            'branding',
            SettingType::String,
            label: 'Accent colour',
            description: 'A six-digit hex colour. Empty means the shipped accent.',
        );
        $this->settings->set(Branding::ACCENT_SETTING, $next, $actor->id);

        $this->audit->handle(new AuditEntry(
            action: $next === null ? 'system.branding_accent_restored' : 'system.branding_accent_changed',
            actorId: $actor->id,
            before: ['accent_color' => $previous],
            after: ['accent_color' => $next],
            module: 'system',
        ));
    }

    /**
     * @throws AuthorizationException
     */
    protected function authorize(User $actor): void
    {
        if (! BrandingPolicy::canManage($actor)) {
            throw new AuthorizationException('You may not change the platform branding.');
        }
    }

    protected function define(BrandingAsset $asset): void
    {
        $this->settings->define(
            $asset->setting(),
            'branding',
            SettingType::String,
            label: $asset === BrandingAsset::Logo ? 'Logo' : 'Browser icon',
            description: 'A managed path on the public disk. Empty means the shipped default.',
        );
    }

    /**
     * The stored value as it is, whether or not its file still exists — so a
     * replaced file is cleaned up even when the resolver had stopped serving it.
     */
    protected function currentPath(BrandingAsset $asset): ?string
    {
        $value = $this->settings->get($asset->setting());

        return is_string($value) && Branding::isManagedPath($value) ? $value : null;
    }

    protected function removeFile(?string $path): void
    {
        $this->deleteFile->forPath($path, StorageVisibility::Public);
    }

    protected function record(User $actor, BrandingAsset $asset, ?string $before, ?string $after): void
    {
        $this->audit->handle(new AuditEntry(
            action: $after === null ? 'system.branding_restored' : 'system.branding_replaced',
            actorId: $actor->id,
            before: ['asset' => $asset->value, 'path' => $before],
            after: ['asset' => $asset->value, 'path' => $after],
            module: 'system',
        ));
    }
}
