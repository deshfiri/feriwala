<?php

namespace Database\Seeders;

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Kyc\Actions\PublishKycBatchVersion;
use App\Domain\Kyc\Actions\SaveKycBatchStructure;
use App\Domain\Kyc\Models\KycBatch;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * One complete example KYC batch, so the hierarchy can be seen and tried at once:
 *
 *   Standard KYC (batch)
 *   ├─ Identity Verification (group, any one option)
 *   │   ├─ National ID (option)        → Front image, Back image (slots)
 *   │   ├─ Passport (option)           → Bio page image/PDF
 *   │   └─ Driving Licence (option)    → Front image, Back image
 *   └─ Business Verification (group, all options required)
 *       ├─ Trade Licence (option)      → Licence PDF
 *       └─ Business Address (option)   → Address document
 *
 * Development data only — it is called from {@see DemoSeeder}, never from
 * {@see SafeSeeder}, and refuses to run in production.
 *
 * Idempotent, and it never overwrites a batch staff made: if a batch with this
 * key or this name exists, nothing is touched. It is built through the same
 * actions the builder uses and published as version 1, but left **switched off**
 * and not the default, so seeding it changes nobody's KYC flow. Switch it on from
 * Admin → KYC batches when you want applicants to meet it.
 *
 * `php artisan db:seed --class=StandardKycBatchSeeder`
 */
class StandardKycBatchSeeder extends Seeder
{
    public const KEY = 'standard_kyc';

    public const NAME = 'Standard KYC';

    public function run(SaveKycBatchStructure $structure, PublishKycBatchVersion $publisher): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('StandardKycBatchSeeder is development data and must never run in production.');
        }

        if (KycBatch::query()->where('key', self::KEY)->orWhere('name', self::NAME)->exists()) {
            // The example batch this seeder made earlier gains the dropdown label it
            // did not have then — and only that, and only where nobody has set one.
            // A batch that merely shares the name, or has been changed, is left as it is.
            $this->nameTheIdentityDropdown($publisher);

            $this->command->info('Standard KYC batch already exists — left as it is.');

            return;
        }

        $actor = User::role(PlatformRole::SuperAdmin->value)->orderBy('id')->first();

        if ($actor === null) {
            throw new RuntimeException('Seed the staff first (DemoSeeder): the batch is built on behalf of a Super Admin.');
        }

        $batch = KycBatch::create([
            'key' => self::KEY,
            'audience' => 'both',
            'name' => self::NAME,
            'name_bn' => 'স্ট্যান্ডার্ড কেওয়াইসি',
            'description' => 'Who you are, and that the business is real.',
            'description_bn' => 'আপনি কে, এবং ব্যবসাটি বাস্তব কি না।',
            'selfie_mode' => 'optional',
            'is_active' => false,
            'is_default' => false,
            'sort_order' => (int) KycBatch::query()->max('sort_order') + 1,
        ]);

        $structure->handle($batch, $this->groups(), $actor);
        $publisher->handle($batch->refresh(), $actor);

        $this->command->info('Standard KYC batch created and published (switched off): 2 groups, 5 options, 7 slots.');
    }

    /**
     * Fills the Identity group's empty dropdown label on the seeded batch and
     * publishes the result as a new version (rounds already open keep theirs).
     */
    private function nameTheIdentityDropdown(PublishKycBatchVersion $publisher): void
    {
        $batch = KycBatch::query()->where('key', self::KEY)->first();
        $group = $batch?->groups()->where('key', 'identity_verification')->whereNull('selector_label')->first();

        if ($batch === null || $group === null || $group->completion_rule->value !== 'any') {
            return;
        }

        $group->forceFill([
            'selector_label' => 'Select identification document',
            'selector_label_bn' => 'পরিচয়ের নথি বাছাই করুন',
        ])->save();

        $actor = User::role(PlatformRole::SuperAdmin->value)->orderBy('id')->first();

        if ($actor !== null && $batch->published_version > 0) {
            $publisher->handle($batch->refresh(), $actor);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function groups(): array
    {
        return [
            [
                'name' => 'Identity Verification',
                'name_bn' => 'পরিচয় যাচাই',
                'description' => 'Choose one way to prove who you are.',
                'description_bn' => 'আপনি কে তা প্রমাণ করার একটি উপায় বেছে নিন।',
                'selector_label' => 'Select identification document',
                'selector_label_bn' => 'পরিচয়ের নথি বাছাই করুন',
                'rule' => 'any',
                'required' => true,
                'requirements' => [
                    [
                        'name' => 'National ID',
                        'name_bn' => 'জাতীয় পরিচয়পত্র',
                        'required' => true,
                        'slots' => [
                            $this->image('Front image', 'সামনের ছবি'),
                            $this->image('Back image', 'পেছনের ছবি'),
                        ],
                    ],
                    [
                        'name' => 'Passport',
                        'name_bn' => 'পাসপোর্ট',
                        'required' => true,
                        'slots' => [$this->file('Bio page image/PDF', 'বায়ো পাতার ছবি/পিডিএফ')],
                    ],
                    [
                        'name' => 'Driving Licence',
                        'name_bn' => 'ড্রাইভিং লাইসেন্স',
                        'required' => true,
                        'slots' => [
                            $this->image('Front image', 'সামনের ছবি'),
                            $this->image('Back image', 'পেছনের ছবি'),
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Business Verification',
                'name_bn' => 'ব্যবসা যাচাই',
                'description' => 'Both are needed.',
                'description_bn' => 'দুটিই দরকার।',
                'rule' => 'all',
                'required' => true,
                'requirements' => [
                    [
                        'name' => 'Trade Licence',
                        'name_bn' => 'ট্রেড লাইসেন্স',
                        'required' => true,
                        'slots' => [[
                            'label' => 'Licence PDF', 'label_bn' => 'লাইসেন্স পিডিএফ', 'evidence' => 'pdf',
                            'required' => true, 'mimes' => ['application/pdf'], 'max_kb' => 5120,
                        ]],
                    ],
                    [
                        'name' => 'Business Address',
                        'name_bn' => 'ব্যবসার ঠিকানা',
                        'required' => true,
                        'slots' => [$this->file('Address document', 'ঠিকানার নথি')],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function image(string $label, string $labelBn): array
    {
        return [
            'label' => $label, 'label_bn' => $labelBn, 'evidence' => 'image',
            'required' => true, 'mimes' => ['image/jpeg', 'image/png'], 'max_kb' => 5120,
        ];
    }

    /**
     * An image or a PDF.
     *
     * @return array<string, mixed>
     */
    private function file(string $label, string $labelBn): array
    {
        return [
            'label' => $label, 'label_bn' => $labelBn, 'evidence' => 'file',
            'required' => true, 'mimes' => ['image/jpeg', 'image/png', 'application/pdf'], 'max_kb' => 5120,
        ];
    }
}
