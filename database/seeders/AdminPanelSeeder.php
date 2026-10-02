<?php

namespace Database\Seeders;

use App\Models\CmsPage;
use App\Services\SystemSettingsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminPanelSeeder extends Seeder
{
    public function run(): void
    {
        $pages = [
            [
                'slug' => 'help',
                'title' => 'راهنمای سامانه',
                'content' => $this->defaultHelpContent(),
                'metadata' => ['type' => 'help'],
            ],
            [
                'slug' => 'about',
                'title' => 'درباره ما',
                'content' => $this->defaultAboutContent(),
                'metadata' => ['type' => 'about'],
            ],
        ];

        foreach ($pages as $pageData) {
            CmsPage::updateOrCreate(
                ['slug' => $pageData['slug']],
                [
                    'id' => CmsPage::where('slug', $pageData['slug'])->value('id') ?? (string) Str::uuid(),
                    'title' => $pageData['title'],
                    'content' => $pageData['content'],
                    'metadata' => $pageData['metadata'],
                    'version' => 1,
                    'is_published' => true,
                ]
            );
        }

        $defaults = [
            'app_version' => config('release.version', '3.0.0'),
            'system_announcement' => '',
            'feature_analytics' => true,
            'feature_export' => true,
            'default_po_method' => '1',
            'default_a_max' => 15,
            'default_normalization_factor' => 1.0,
        ];

        foreach ($defaults as $key => $value) {
            $group = str_starts_with($key, 'feature_') ? 'features'
                : (str_starts_with($key, 'default_') ? 'defaults' : 'system');
            SystemSettingsService::set($key, $value, $group);
        }

        $this->command->info('Admin panel CMS and settings seeded.');
    }

    private function defaultHelpContent(): string
    {
        return json_encode([
            'sections' => [
                [
                    'id' => 'intro',
                    'title' => 'معرفی سامانه',
                    'content' => 'سامانه ارزیابی مالی مناقصات (T.C.S) برای محاسبه برآورد به‌هنگام (Po)، ارزیابی مالی و انتخاب برنده مطابق دستورالعمل وزارت نفت طراحی شده است.',
                ],
                [
                    'id' => 'workflow',
                    'title' => 'گردش کار',
                    'content' => '۱. ثبت مناقصه  ۲. برآورد اولیه (Pb)  ۳. شاخص‌های تعدیل  ۴. محاسبه Po  ۵. ثبت پیشنهادات  ۶. ارزیابی  ۷. نتایج',
                ],
                [
                    'id' => 'formulas',
                    'title' => 'فرمول‌های محاسباتی',
                    'content' => 'فرمول‌های Po و ارزیابی قابل تنظیم توسط مدیر سیستم هستند. تغییرات فرمول باعث باطل شدن محاسبات قبلی می‌شود.',
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    private function defaultAboutContent(): string
    {
        return json_encode([
            'title' => 'سیستم محاسباتی مناقصات',
            'subtitle' => 'T.C.S',
            'description' => 'این نرم‌افزار بر اساس دستورالعمل ارزیابی مالی و تعیین کمترین قیمت متناسب در مناقصات وزارت نفت (ویرایش ۱۴۰۴) توسعه یافته است.',
            'developer' => [
                'name' => 'مجید حیدراصل',
                'organization' => 'دانشگاه خلیج فارس بوشهر',
                'phone' => '۰۹۹۸۱۳۸۲۹۰۶',
                'email' => 'majidhaidarasl@gmail.com',
            ],
            'project_manager' => [
                'name' => 'باقر پاپری مقدم فرد',
                'organization' => 'شرکت گاز استان بوشهر',
                'phone' => '۰۹۱۷۷۷۵۶۷۱۵',
                'email' => 'bagherpapari@yahoo.com',
            ],
            'company' => 'شرکت شالو تکنولوژی (Shaloo Tech)',
        ], JSON_UNESCAPED_UNICODE);
    }
}
