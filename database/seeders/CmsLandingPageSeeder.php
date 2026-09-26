<?php

namespace Database\Seeders;

use App\Domain\Cms\Actions\PublishPage;
use App\Domain\Cms\Actions\SaveSectionDraft;
use App\Domain\Cms\Enums\MenuLocation;
use App\Domain\Cms\Enums\SectionKind;
use App\Domain\Cms\Models\Menu;
use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Models\SeoSetting;
use Illuminate\Database\Seeder;

/**
 * The real, initial content for the public landing page (§34). Idempotent:
 * running it again updates the existing `home` page's sections and
 * republishes rather than creating a duplicate — safe to include in a
 * normal seed run, never a historical migration (content is mutable; a
 * migration is not the place for it).
 *
 * Describes Feriwala as it actually is: an ERP-based wholesale and
 * dropshipping platform behind an authenticated panel, with a package
 * system, dedicated Partner Websites, a separate Supplier programme, a
 * configurable N-level referral system, and its own wallet/ledger and
 * order management — never the earlier, stale "single-level, not MLM"
 * description.
 */
class CmsLandingPageSeeder extends Seeder
{
    public function run(): void
    {
        $page = Page::query()->updateOrCreate(
            ['slug' => 'home'],
            ['page_type' => 'landing', 'default_locale' => 'en'],
        );

        $save = app(SaveSectionDraft::class);
        $sort = 0;

        foreach ($this->sections() as [$key, $kind, $content]) {
            $save->handle($page, $key, $kind, $content, sortOrder: $sort++);
        }

        $this->seedMenus();
        $this->seedSeoDefaults();

        app(PublishPage::class)->handle($page, reason: 'Initial landing page content.');
    }

    /**
     * @return array<int, array{0: string, 1: SectionKind, 2: array<string, mixed>}>
     */
    protected function sections(): array
    {
        $t = fn (string $en, string $bn) => ['en' => $en, 'bn' => $bn];

        return [
            ['header', SectionKind::HeaderNav, []],

            ['hero', SectionKind::Hero, [
                'heading' => $t(
                    'Run wholesale and dropshipping from one ERP panel',
                    'একটি ইআরপি প্যানেল থেকে হোলসেল ও ড্রপশিপিং পরিচালনা করুন',
                ),
                'subheading' => $t(
                    'Feriwala is the authenticated back office behind your business, not a public shop.',
                    'ফেরিওয়ালা আপনার ব্যবসার পেছনের প্রমাণীকৃত অফিস, কোনো পাবলিক দোকান নয়।',
                ),
                'primary_cta' => ['label' => $t('Create your account', 'অ্যাকাউন্ট তৈরি করুন'), 'href' => 'register'],
                'secondary_cta' => ['label' => $t('Sign in', 'সাইন ইন করুন'), 'href' => 'login'],
            ]],

            ['platform-introduction', SectionKind::PlatformIntroduction, [
                'heading' => $t('What Feriwala is', 'ফেরিওয়ালা কী'),
                'body' => $t(
                    'Feriwala is an ERP for wholesale and dropshipping businesses. Everything happens inside one authenticated panel: choosing a package, placing orders, running a wallet and ledger, and managing a dedicated storefront.',
                    'ফেরিওয়ালা হোলসেল ও ড্রপশিপিং ব্যবসার জন্য একটি ইআরপি। সবকিছু একটি প্রমাণীকৃত প্যানেলের ভেতরে ঘটে: প্যাকেজ বেছে নেওয়া, অর্ডার দেওয়া, ওয়ালেট ও লেজার পরিচালনা, এবং একটি নিজস্ব স্টোরফ্রন্ট পরিচালনা করা।',
                ),
                'bullets' => [
                    $t('Centralized order management across every sales channel', 'প্রতিটি বিক্রয় চ্যানেল জুড়ে কেন্দ্রীভূত অর্ডার ব্যবস্থাপনা'),
                    $t('A wallet and financial ledger for every transaction', 'প্রতিটি লেনদেনের জন্য একটি ওয়ালেট ও আর্থিক লেজার'),
                    $t('A configurable, multi-level referral programme', 'একটি কনফিগারযোগ্য, বহু-স্তরের রেফারেল প্রোগ্রাম'),
                ],
            ]],

            ['benefits', SectionKind::Benefits, [
                'heading' => $t('Built for how you already work', 'আপনি যেভাবে কাজ করেন তার জন্যই তৈরি'),
                'items' => [
                    ['icon' => 'wallet', 'heading' => $t('Wallet & ledger', 'ওয়ালেট ও লেজার'), 'body' => $t('Every payment, charge and refund recorded exactly once.', 'প্রতিটি পেমেন্ট, চার্জ ও ফেরত ঠিক একবারই লিপিবদ্ধ হয়।')],
                    ['icon' => 'package', 'heading' => $t('Centralized orders', 'কেন্দ্রীভূত অর্ডার'), 'body' => $t('Wholesale and storefront orders in one place.', 'হোলসেল ও স্টোরফ্রন্ট অর্ডার এক জায়গায়।')],
                    ['icon' => 'trending-up', 'heading' => $t('Referral earnings', 'রেফারেল আয়'), 'body' => $t('A configurable, multi-level referral programme.', 'একটি কনফিগারযোগ্য, বহু-স্তরের রেফারেল প্রোগ্রাম।')],
                    ['icon' => 'shield-check', 'heading' => $t('Verified suppliers', 'যাচাইকৃত সাপ্লায়ার'), 'body' => $t('Every supplier is reviewed before they can list.', 'তালিকাভুক্ত হওয়ার আগে প্রতিটি সাপ্লায়ার পর্যালোচিত হয়।')],
                ],
            ]],

            ['how-it-works', SectionKind::HowItWorks, [
                'heading' => $t('How it works', 'যেভাবে কাজ করে'),
                'steps' => [
                    ['step_number' => 1, 'heading' => $t('Register and verify', 'নিবন্ধন ও যাচাই করুন'), 'body' => $t('Create an account and complete KYC.', 'একটি অ্যাকাউন্ট তৈরি করুন এবং কেওয়াইসি সম্পন্ন করুন।')],
                    ['step_number' => 2, 'heading' => $t('Choose a package', 'একটি প্যাকেজ বেছে নিন'), 'body' => $t('Pick the package that fits your business.', 'আপনার ব্যবসার উপযোগী প্যাকেজ বেছে নিন।')],
                    ['step_number' => 3, 'heading' => $t('Get approved', 'অনুমোদন পান'), 'body' => $t('Our team reviews and activates your account.', 'আমাদের দল পর্যালোচনা করে আপনার অ্যাকাউন্ট সক্রিয় করে।')],
                    ['step_number' => 4, 'heading' => $t('Start trading', 'ব্যবসা শুরু করুন'), 'body' => $t('Place orders, run your storefront, track your wallet.', 'অর্ডার দিন, আপনার স্টোরফ্রন্ট চালান, আপনার ওয়ালেট দেখুন।')],
                ],
            ]],

            ['dropshipping', SectionKind::Dropshipping, [
                'heading' => $t('Dropshipping, without holding stock', 'ড্রপশিপিং, মজুত না রেখেই'),
                'body' => $t(
                    'List products from the central catalogue and sell them without holding inventory yourself.',
                    'কেন্দ্রীয় ক্যাটালগ থেকে পণ্য তালিকাভুক্ত করুন এবং নিজে মজুত না রেখেই বিক্রি করুন।',
                ),
                'bullets' => [
                    $t('No inventory to manage', 'মজুত পরিচালনার প্রয়োজন নেই'),
                    $t('Orders routed to the platform for fulfilment', 'অর্ডার সম্পন্ন হওয়ার জন্য প্ল্যাটফর্মে পাঠানো হয়'),
                ],
                'cta' => ['label' => $t('Get started', 'শুরু করুন'), 'href' => 'register'],
            ]],

            ['wholesale', SectionKind::Wholesale, [
                'heading' => $t('Wholesale ordering at scale', 'বড় পরিসরে হোলসেল অর্ডার'),
                'body' => $t(
                    'Order in bulk from the central catalogue at wholesale rates, tracked through the same ERP panel.',
                    'কেন্দ্রীয় ক্যাটালগ থেকে হোলসেল রেটে বাল্ক অর্ডার করুন, একই ইআরপি প্যানেলের মাধ্যমে ট্র্যাক করুন।',
                ),
                'bullets' => [
                    $t('Wholesale pricing tiers', 'হোলসেল মূল্য স্তর'),
                    $t('Order tracking end to end', 'শুরু থেকে শেষ পর্যন্ত অর্ডার ট্র্যাকিং'),
                ],
                'cta' => ['label' => $t('Get started', 'শুরু করুন'), 'href' => 'register'],
            ]],

            ['partner-websites', SectionKind::PartnerWebsites, [
                'heading' => $t('Your own dedicated storefront', 'আপনার নিজস্ব স্টোরফ্রন্ট'),
                'body' => $t(
                    'Run a dedicated Partner Website with Feriwala as merchant of record, handling payments on your behalf.',
                    'ফেরিওয়ালা মার্চেন্ট অফ রেকর্ড হিসেবে আপনার পক্ষে পেমেন্ট পরিচালনা করে এমন একটি নিজস্ব পার্টনার ওয়েবসাইট চালান।',
                ),
                'bullets' => [
                    $t('Your own branded storefront', 'আপনার নিজস্ব ব্র্যান্ডেড স্টোরফ্রন্ট'),
                    $t('Orders flow straight into your ERP panel', 'অর্ডার সরাসরি আপনার ইআরপি প্যানেলে আসে'),
                ],
                'cta' => ['label' => $t('Learn more', 'আরও জানুন'), 'href' => 'register'],
            ]],

            ['supplier-opportunity', SectionKind::SupplierOpportunity, [
                'heading' => $t('Supply the platform, not one shop', 'একটি নয়, পুরো প্ল্যাটফর্মে সরবরাহ করুন'),
                'body' => $t(
                    'Suppliers are a separate programme from wholesale and dropshipping accounts: list products, set rates, and get paid through their own wallet.',
                    'সাপ্লায়াররা হোলসেল ও ড্রপশিপিং অ্যাকাউন্ট থেকে আলাদা একটি প্রোগ্রাম: পণ্য তালিকাভুক্ত করুন, রেট নির্ধারণ করুন, এবং নিজস্ব ওয়ালেটের মাধ্যমে পেমেন্ট পান।',
                ),
                'bullets' => [
                    $t('A dedicated Supplier application and review', 'একটি নিবেদিত সাপ্লায়ার আবেদন ও পর্যালোচনা'),
                    $t('Your own wallet and withdrawal requests', 'আপনার নিজস্ব ওয়ালেট ও উত্তোলনের অনুরোধ'),
                ],
                'cta' => ['label' => $t('Apply as a Supplier', 'সাপ্লায়ার হিসেবে আবেদন করুন'), 'href' => 'supplier.register'],
            ]],

            ['packages', SectionKind::PackagePreview, [
                'heading' => $t('Packages for every stage', 'প্রতিটি ধাপের জন্য প্যাকেজ'),
                'body' => $t('Choose the package that matches your business today.', 'আজ আপনার ব্যবসার সাথে মানানসই প্যাকেজটি বেছে নিন।'),
                'cta' => ['label' => $t('Compare packages', 'প্যাকেজ তুলনা করুন'), 'href' => 'register'],
            ]],

            ['faq', SectionKind::Faq, [
                'heading' => $t('Frequently asked questions', 'সচরাচর জিজ্ঞাসিত প্রশ্ন'),
                'items' => [
                    [
                        'question' => $t('Is Feriwala a public shop?', 'ফেরিওয়ালা কি একটি পাবলিক দোকান?'),
                        'answer' => $t('No. Feriwala is an ERP panel for registered businesses, suppliers and their Partner Websites — there is no public product catalogue here.', 'না। ফেরিওয়ালা নিবন্ধিত ব্যবসা, সাপ্লায়ার এবং তাদের পার্টনার ওয়েবসাইটের জন্য একটি ইআরপি প্যানেল — এখানে কোনো পাবলিক পণ্য ক্যাটালগ নেই।'),
                    ],
                    [
                        'question' => $t('What is the difference between wholesale and dropshipping?', 'হোলসেল ও ড্রপশিপিং-এর মধ্যে পার্থক্য কী?'),
                        'answer' => $t('Wholesale means buying stock in bulk to hold yourself; dropshipping means selling without holding inventory, with fulfilment handled centrally.', 'হোলসেল মানে নিজে রাখার জন্য বাল্কে স্টক কেনা; ড্রপশিপিং মানে মজুত না রেখে বিক্রি করা, যেখানে সরবরাহ কেন্দ্রীয়ভাবে পরিচালিত হয়।'),
                    ],
                    [
                        'question' => $t('How do I become a Supplier?', 'আমি কীভাবে সাপ্লায়ার হবো?'),
                        'answer' => $t('Suppliers apply separately from wholesale/dropshipping accounts and go through their own verification before listing products.', 'সাপ্লায়াররা হোলসেল/ড্রপশিপিং অ্যাকাউন্ট থেকে আলাদাভাবে আবেদন করেন এবং পণ্য তালিকাভুক্ত করার আগে নিজস্ব যাচাইকরণের মধ্য দিয়ে যান।'),
                    ],
                    [
                        'question' => $t('Does Feriwala have a referral programme?', 'ফেরিওয়ালার কি রেফারেল প্রোগ্রাম আছে?'),
                        'answer' => $t('Yes — a configurable, multi-level referral programme rewards accounts for the business they bring in.', 'হ্যাঁ — একটি কনফিগারযোগ্য, বহু-স্তরের রেফারেল প্রোগ্রাম অ্যাকাউন্টগুলোকে তাদের আনা ব্যবসার জন্য পুরস্কৃত করে।'),
                    ],
                ],
            ]],

            ['cta', SectionKind::Cta, [
                'heading' => $t('Ready to get started?', 'শুরু করতে প্রস্তুত?'),
                'body' => $t('Create your account and choose a package in minutes.', 'কয়েক মিনিটেই আপনার অ্যাকাউন্ট তৈরি করুন এবং প্যাকেজ বেছে নিন।'),
                'primary_cta' => ['label' => $t('Create your account', 'অ্যাকাউন্ট তৈরি করুন'), 'href' => 'register'],
                'secondary_cta' => ['label' => $t('Sign in', 'সাইন ইন করুন'), 'href' => 'login'],
            ]],

            ['footer', SectionKind::Footer, [
                'tagline' => $t('ERP for wholesale and dropshipping.', 'হোলসেল ও ড্রপশিপিং-এর জন্য ইআরপি।'),
                'copyright_text' => $t('© '.date('Y').' Feriwala. All rights reserved.', '© '.date('Y').' ফেরিওয়ালা। সর্বস্বত্ব সংরক্ষিত।'),
            ]],
        ];
    }

    protected function seedMenus(): void
    {
        $header = Menu::query()->firstOrCreate(['location' => MenuLocation::Header->value]);
        $footer = Menu::query()->firstOrCreate(['location' => MenuLocation::Footer->value]);
        $legal = Menu::query()->firstOrCreate(['location' => MenuLocation::Legal->value]);

        if ($header->items()->count() === 0) {
            $header->allItems()->createMany([
                ['label_en' => 'How it works', 'label_bn' => 'যেভাবে কাজ করে', 'external_url' => '/#how-it-works', 'sort_order' => 1],
                ['label_en' => 'Suppliers', 'label_bn' => 'সাপ্লায়ার', 'route_name' => 'supplier.register', 'sort_order' => 2],
                ['label_en' => 'Sign in', 'label_bn' => 'সাইন ইন', 'route_name' => 'login', 'sort_order' => 3],
            ]);
        }

        if ($footer->items()->count() === 0) {
            $footer->allItems()->createMany([
                ['label_en' => 'Register', 'label_bn' => 'নিবন্ধন', 'route_name' => 'register', 'sort_order' => 1],
                ['label_en' => 'Sign in', 'label_bn' => 'সাইন ইন', 'route_name' => 'login', 'sort_order' => 2],
                ['label_en' => 'Become a Supplier', 'label_bn' => 'সাপ্লায়ার হন', 'route_name' => 'supplier.register', 'sort_order' => 3],
            ]);
        }

        if ($legal->items()->count() === 0) {
            $legal->allItems()->createMany([
                ['label_en' => 'Terms', 'label_bn' => 'শর্তাবলী', 'external_url' => '/#', 'sort_order' => 1],
                ['label_en' => 'Privacy', 'label_bn' => 'গোপনীয়তা', 'external_url' => '/#', 'sort_order' => 2],
            ]);
        }
    }

    protected function seedSeoDefaults(): void
    {
        SeoSetting::query()->updateOrCreate(['locale' => 'en'], [
            'default_title' => 'Feriwala — ERP for Wholesale and Dropshipping',
            'default_description' => 'Feriwala is an authenticated ERP panel for wholesale and dropshipping businesses, dedicated Partner Websites, and Suppliers.',
            'organization_name' => 'Feriwala',
            'robots_default' => 'index, follow',
        ]);

        SeoSetting::query()->updateOrCreate(['locale' => 'bn'], [
            'default_title' => 'ফেরিওয়ালা — হোলসেল ও ড্রপশিপিং-এর জন্য ইআরপি',
            'default_description' => 'ফেরিওয়ালা হোলসেল ও ড্রপশিপিং ব্যবসা, নিবেদিত পার্টনার ওয়েবসাইট এবং সাপ্লায়ারদের জন্য একটি প্রমাণীকৃত ইআরপি প্যানেল।',
            'organization_name' => 'ফেরিওয়ালা',
            'robots_default' => 'index, follow',
        ]);
    }
}
