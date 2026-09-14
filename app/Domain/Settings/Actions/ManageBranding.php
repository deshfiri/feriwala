<?php

namespace App\Domain\Settings\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Settings\Branding;
use App\Domain\Settings\Enums\BrandingAsset;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\Policies\BrandingPolicy;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

/**
 * Replace the logo or browser icon, or put the shipped default back.
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
    ) {}

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException when the file is not an accepted image
     */
    public function replace(User $actor, BrandingAsset $asset, UploadedFile $file): string
    {
        $this->authorize($actor);

        // Read from the file's own bytes, never from the name or the browser's claim.
        $mime = (string) MimeTypes::getDefault()->guessMimeType((string) $file->getRealPath());
        $extension = $asset->acceptedTypes()[$mime] ?? null;

        if ($extension === null) {
            throw new InvalidArgumentException("A file of type [{$mime}] is not accepted.");
        }

        if ((int) $file->getSize() > $asset->maxKilobytes() * 1024) {
            throw new InvalidArgumentException('The file is larger than '.$asset->maxKilobytes().' KB.');
        }

        $previous = $this->currentPath($asset);
        $path = sprintf('%s/%s-%s.%s', Branding::FOLDER, $asset->value, bin2hex(random_bytes(16)), $extension);

        $this->branding->disk()->put($path, (string) file_get_contents($file->getRealPath()));

        try {
            $this->define($asset);
            $this->settings->set($asset->setting(), $path, $actor->id);
        } catch (Throwable $failure) {
            $this->branding->disk()->delete($path);

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
        if ($path !== null) {
            $this->branding->disk()->delete($path);
        }
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
