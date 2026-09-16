<?php

namespace App\Domain\Website\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Domain\Website\WebsiteImageStore;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * What a partner may change about their own storefront (§16.3, P5-12).
 *
 * Website information, contact details, colours, basic branding and the theme —
 * exactly the list §16.3 gives, and nothing beyond it. In particular **no
 * product is created here**: §16.3 is explicit that a partner cannot create
 * products through website management, and the way to guarantee that is for
 * this class to have no route to the catalogue at all (P5-14). Products are
 * selected from the central catalogue, never authored.
 *
 * A closed website is not edited. It is finished with, and letting somebody
 * repaint a shop that no longer exists would only make its records disagree
 * with what was served.
 *
 * Replacing an image deletes the one it replaces, in that order: a storefront
 * that renders a path pointing at nothing is worse than one carrying a file
 * nobody references.
 */
class ManageWebsiteBranding
{
    public function __construct(
        protected WebsiteImageStore $images,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  already validated by the caller
     *
     * @throws WebsiteRefused
     */
    public function update(Website $website, User $actor, array $attributes): Website
    {
        $this->assertEditable($website);

        $website->fill($attributes)->save();

        $this->record($website, $actor, 'website.branding_updated', $website->getChanges());

        return $website;
    }

    /**
     * Put a logo or a banner in place, replacing whatever was there.
     *
     * @param  string  $asset  `logo` or `banner`
     *
     * @throws WebsiteRefused
     */
    public function putImage(Website $website, User $actor, string $asset, UploadedFile $file): Website
    {
        $this->assertEditable($website);

        $column = $this->columnFor($asset);
        $previous = $website->{$column};

        $path = $this->images->store($file, $website->public_id, $asset);

        $website->forceFill([$column => $path])->save();

        // Only once the new one is recorded: an interrupted replacement should
        // leave a storefront with a stale image, never with none.
        $this->images->delete($previous);

        $this->record($website, $actor, 'website.image_updated', ['asset' => $asset]);

        return $website;
    }

    /**
     * @throws WebsiteRefused
     */
    public function removeImage(Website $website, User $actor, string $asset): Website
    {
        $this->assertEditable($website);

        $column = $this->columnFor($asset);
        $previous = $website->{$column};

        $website->forceFill([$column => null])->save();

        $this->images->delete($previous);

        $this->record($website, $actor, 'website.image_removed', ['asset' => $asset]);

        return $website;
    }

    /**
     * @param  array<string, mixed>  $after
     */
    protected function record(Website $website, User $actor, string $action, array $after): void
    {
        $this->audit->handle(new AuditEntry(
            action: $action,
            actorId: $actor->id,
            auditableType: Website::class,
            auditableId: $website->id,
            after: $after,
            accountId: $website->business_account_id,
            module: 'website',
        ));
    }

    /**
     * @throws WebsiteRefused
     */
    protected function assertEditable(Website $website): void
    {
        if ($website->status->isTerminal()) {
            throw WebsiteRefused::notEditable();
        }
    }

    /**
     * @throws WebsiteRefused
     */
    protected function columnFor(string $asset): string
    {
        return match ($asset) {
            'logo' => 'logo_path',
            'banner' => 'banner_path',
            default => throw WebsiteRefused::imageTypeNotAccepted(),
        };
    }
}
