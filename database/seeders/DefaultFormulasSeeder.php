<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Formula;
use Illuminate\Support\Str;

class DefaultFormulasSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultFormulas = [
            // فرمول‌های مسیر ساده (ماده 6-1)
            [
                'name' => 'حد_پایین_مسیر_ساده',
                'category' => 'evaluation',
                'description' => 'حد پایین قیمت برای مسیر ساده (ماده 6-1)',
                'formula' => '0.9 * Po',
                'parameters' => ['Po' => 'برآورد به‌هنگام'],
                'conditions' => ['route' => 'simple', 'n < 4'],
                'is_default' => true,
            ],
            [
                'name' => 'حد_بالای_مسیر_ساده',
                'category' => 'evaluation',
                'description' => 'حد بالا قیمت برای مسیر ساده (ماده 6-1)',
                'formula' => '1.1 * Po',
                'parameters' => ['Po' => 'برآورد به‌هنگام'],
                'conditions' => ['route' => 'simple', 'n < 4'],
                'is_default' => true,
            ],
            [
                'name' => 'شرط_مسیر_ساده',
                'category' => 'evaluation',
                'description' => 'شرط استفاده از مسیر ساده',
                'formula' => 'n < 4',
                'parameters' => ['n' => 'تعداد مناقصه‌گران'],
                'conditions' => null,
                'is_default' => true,
            ],
            // فرمول‌های ماده 6-2
            [
                'name' => 'حد_پایین_ماده_6_2',
                'category' => 'evaluation',
                'description' => 'حد پایین بازه مجاز برای ماده 6-2',
                'formula' => '0.8 * Po',
                'parameters' => ['Po' => 'برآورد به‌هنگام'],
                'conditions' => ['route' => 'statistical'],
                'is_default' => true,
            ],
            [
                'name' => 'حد_بالای_ماده_6_2',
                'category' => 'evaluation',
                'description' => 'حد بالا بازه مجاز برای ماده 6-2',
                'formula' => '1.35 * Po',
                'parameters' => ['Po' => 'برآورد به‌هنگام'],
                'conditions' => ['route' => 'statistical'],
                'is_default' => true,
            ],
            // فرمول‌های دامنه الحاقی (تبصره بند 6-3-3)
            [
                'name' => 'درصد_دامنه_الحاقی_اصلی',
                'category' => 'evaluation',
                'description' => 'درصد دامنه الحاقی زمانی که P\'\'_o در دامنه اصلی است (تبصره بند 6-3-3)',
                'formula' => '0.20',
                'parameters' => [],
                'conditions' => ['Po_in_primary_range' => true],
                'is_default' => true,
            ],
            [
                'name' => 'درصد_دامنه_الحاقی_فرعی',
                'category' => 'evaluation',
                'description' => 'درصد دامنه الحاقی زمانی که P\'\'_o در دامنه اصلی نیست (تبصره بند 6-3-3)',
                'formula' => '0.10',
                'parameters' => [],
                'conditions' => ['Po_in_primary_range' => false],
                'is_default' => true,
            ],
            [
                'name' => 'حد_پایین_دامنه_الحاقی',
                'category' => 'evaluation',
                'description' => 'حد پایین دامنه الحاقی',
                'formula' => 'Po * (1 - annex_percent)',
                'parameters' => ['Po' => 'برآورد به‌هنگام', 'annex_percent' => 'درصد دامنه الحاقی'],
                'conditions' => ['route' => 'statistical'],
                'is_default' => true,
            ],
            [
                'name' => 'حد_بالای_دامنه_الحاقی',
                'category' => 'evaluation',
                'description' => 'حد بالا دامنه الحاقی',
                'formula' => 'Po * (1 + annex_percent)',
                'parameters' => ['Po' => 'برآورد به‌هنگام', 'annex_percent' => 'درصد دامنه الحاقی'],
                'conditions' => ['route' => 'statistical'],
                'is_default' => true,
            ],
            // فرمول‌های نرمال‌سازی آماری (ماده 6-3)
            [
                'name' => 'نرمال_سازی_آماری',
                'category' => 'evaluation',
                'description' => 'فرمول نرمال‌سازی آماری: P\'\'_i = (P\'_i - m_o) / s_o',
                'formula' => '(P_prime_i - m_o) / s_o',
                'parameters' => ['P_prime_i' => 'قیمت تراز شده', 'm_o' => 'میانگین', 's_o' => 'انحراف معیار'],
                'conditions' => ['route' => 'statistical'],
                'is_default' => true,
            ],
            [
                'name' => 'نرمال_سازی_آماری_Po',
                'category' => 'evaluation',
                'description' => 'فرمول نرمال‌سازی آماری برای P_o: P\'\'_o = (P_o - m_o) / s_o',
                'formula' => '(Po - m_o) / s_o',
                'parameters' => ['Po' => 'برآورد به‌هنگام', 'm_o' => 'میانگین', 's_o' => 'انحراف معیار'],
                'conditions' => ['route' => 'statistical'],
                'is_default' => true,
            ],
            // فرمول‌های محاسبات آماری
            [
                'name' => 'محاسبه_میانگین',
                'category' => 'evaluation',
                'description' => 'محاسبه میانگین: m_o = (Σ P\'_i + P_o) / n',
                'formula' => '(sum + Po) / n',
                'parameters' => ['sum' => 'مجموع قیمت‌های تراز شده', 'Po' => 'برآورد به‌هنگام', 'n' => 'تعداد'],
                'conditions' => ['route' => 'statistical'],
                'is_default' => true,
            ],
            [
                'name' => 'محاسبه_انحراف_معیار',
                'category' => 'evaluation',
                'description' => 'محاسبه انحراف معیار: s_o = √[Σ(P\'_i - m_o)² + (P_o - m_o)²] / n',
                'formula' => 'sqrt(variance_sum / n)',
                'parameters' => ['variance_sum' => 'مجموع مربعات انحرافات', 'n' => 'تعداد'],
                'conditions' => ['route' => 'statistical'],
                'is_default' => true,
            ],
            // فرمول‌های دامنه اصلی
            [
                'name' => 'حد_پایین_دامنه_اصلی',
                'category' => 'evaluation',
                'description' => 'حد پایین دامنه اصلی نرمال‌سازی آماری',
                'formula' => '-1',
                'parameters' => [],
                'conditions' => ['route' => 'statistical'],
                'is_default' => true,
            ],
            [
                'name' => 'حد_بالای_دامنه_اصلی',
                'category' => 'evaluation',
                'description' => 'حد بالا دامنه اصلی نرمال‌سازی آماری',
                'formula' => '1',
                'parameters' => [],
                'conditions' => ['route' => 'statistical'],
                'is_default' => true,
            ],
            // فرمول‌های محاسبه برآورد به‌هنگام (Po) - روش 1
            [
                'name' => 'Po_روش1_تعدیل_I2_برابر_I3',
                'category' => 'po_calculation',
                'description' => 'محاسبه Po برای کار دارای تعدیل زمانی که I₂ برابر I₃ است',
                'formula' => 'Pb * (I2 / I1)',
                'parameters' => ['Pb' => 'برآورد اولیه', 'I1' => 'شاخص I₁', 'I2' => 'شاخص I₂'],
                'conditions' => ['method' => '1', 'isAdjustable' => true, 'I2_equals_I3' => true],
                'is_default' => true,
            ],
            [
                'name' => 'Po_روش1_تعدیل_I2_مخالف_I3',
                'category' => 'po_calculation',
                'description' => 'محاسبه Po برای کار دارای تعدیل زمانی که I₂ برابر I₃ نیست',
                'formula' => 'Pb * ((I3 / I1) * pow(1 + r2, Tβ))',
                'parameters' => ['Pb' => 'برآورد اولیه', 'I1' => 'شاخص I₁', 'I3' => 'شاخص I₃', 'r2' => 'نرخ تغییر r₂', 'Tβ' => 'دوره T_β'],
                'conditions' => ['method' => '1', 'isAdjustable' => true, 'I2_equals_I3' => false],
                'is_default' => true,
            ],
            [
                'name' => 'Po_روش1_فاقد_تعدیل_I2_برابر_I3',
                'category' => 'po_calculation',
                'description' => 'محاسبه Po برای کار فاقد تعدیل زمانی که I₂ برابر I₃ است',
                'formula' => 'Pb * ((I2 / I1) * pow(1 + r1, T_γ))',
                'parameters' => ['Pb' => 'برآورد اولیه', 'I1' => 'شاخص I₁', 'I2' => 'شاخص I₂', 'r1' => 'نرخ تغییر r₁', 'T_γ' => 'دوره T_γ'],
                'conditions' => ['method' => '1', 'isAdjustable' => false, 'I2_equals_I3' => true],
                'is_default' => true,
            ],
            [
                'name' => 'Po_روش1_فاقد_تعدیل_I2_مخالف_I3',
                'category' => 'po_calculation',
                'description' => 'محاسبه Po برای کار فاقد تعدیل زمانی که I₂ برابر I₃ نیست',
                'formula' => 'Pb * ((I3 / I1) * pow(1 + r2, T_γ))',
                'parameters' => ['Pb' => 'برآورد اولیه', 'I1' => 'شاخص I₁', 'I3' => 'شاخص I₃', 'r2' => 'نرخ تغییر r₂', 'T_γ' => 'دوره T_γ'],
                'conditions' => ['method' => '1', 'isAdjustable' => false, 'I2_equals_I3' => false],
                'is_default' => true,
            ],
            // فرمول‌های محاسبه برآورد به‌هنگام (Po) - روش 2
            [
                'name' => 'Po_روش2_تعدیل_شاخص_اعلام_شده',
                'category' => 'po_calculation',
                'description' => 'روش 2 - کار دارای تعدیل با شاخص اعلام شده',
                'formula' => 'Pb * (F2 / F1)',
                'parameters' => ['Pb' => 'برآورد اولیه', 'F1' => 'شاخص F₁', 'F2' => 'شاخص F₂'],
                'conditions' => ['method' => '2', 'isAdjustable' => true, 'F2_declared' => true],
                'is_default' => true,
            ],
            [
                'name' => 'Po_روش2_تعدیل_شاخص_اعلام_نشده',
                'category' => 'po_calculation',
                'description' => 'روش 2 - کار دارای تعدیل با شاخص اعلام نشده (میانگین نسبت‌ها)',
                'formula' => 'Pb * (F2_prime / F1)',
                'parameters' => ['Pb' => 'برآورد اولیه', 'F1' => 'شاخص F₁', 'F2_prime' => 'F\'₂ محاسبه شده'],
                'conditions' => ['method' => '2', 'isAdjustable' => true, 'F2_declared' => false],
                'is_default' => true,
            ],
            [
                'name' => 'Po_روش2_فاقد_تعدیل_شاخص_اعلام_شده',
                'category' => 'po_calculation',
                'description' => 'روش 2 - کار فاقد تعدیل با شاخص اعلام شده',
                'formula' => 'Pb * (F2 / F1)',
                'parameters' => ['Pb' => 'برآورد اولیه', 'F1' => 'شاخص F₁', 'F2' => 'شاخص F₂'],
                'conditions' => ['method' => '2', 'isAdjustable' => false, 'F2_declared' => true],
                'is_default' => true,
            ],
            [
                'name' => 'Po_روش2_فاقد_تعدیل_شاخص_اعلام_نشده',
                'category' => 'po_calculation',
                'description' => 'روش 2 - کار فاقد تعدیل با شاخص اعلام نشده (میانگین نسبت‌ها)',
                'formula' => 'Pb * (F2_prime / F1)',
                'parameters' => ['Pb' => 'برآورد اولیه', 'F1' => 'شاخص F₁', 'F2_prime' => 'F\'₂ محاسبه شده'],
                'conditions' => ['method' => '2', 'isAdjustable' => false, 'F2_declared' => false],
                'is_default' => true,
            ],
            // فرمول‌های محاسبه برآورد به‌هنگام (Po) - روش 3
            [
                'name' => 'Po_روش3_تعدیل_شاخص_اعلام_شده',
                'category' => 'po_calculation',
                'description' => 'روش 3 - کار دارای تعدیل با شاخص اعلام شده',
                'formula' => 'Pb * (F2 / F1)',
                'parameters' => ['Pb' => 'برآورد اولیه', 'F1' => 'شاخص F₁', 'F2' => 'شاخص F₂'],
                'conditions' => ['method' => '3', 'isAdjustable' => true, 'F2_declared' => true],
                'is_default' => true,
            ],
            [
                'name' => 'Po_روش3_تعدیل_شاخص_اعلام_نشده',
                'category' => 'po_calculation',
                'description' => 'روش 3 - کار دارای تعدیل با شاخص اعلام نشده (میانگین تفاضل)',
                'formula' => 'Pb * (F2_prime / F1)',
                'parameters' => ['Pb' => 'برآورد اولیه', 'F1' => 'شاخص F₁', 'F2_prime' => 'F\'₂ محاسبه شده'],
                'conditions' => ['method' => '3', 'isAdjustable' => true, 'F2_declared' => false],
                'is_default' => true,
            ],
            [
                'name' => 'Po_روش3_فاقد_تعدیل_شاخص_اعلام_شده',
                'category' => 'po_calculation',
                'description' => 'روش 3 - کار فاقد تعدیل با شاخص اعلام شده',
                'formula' => 'Pb * (F2 / F1)',
                'parameters' => ['Pb' => 'برآورد اولیه', 'F1' => 'شاخص F₁', 'F2' => 'شاخص F₂'],
                'conditions' => ['method' => '3', 'isAdjustable' => false, 'F2_declared' => true],
                'is_default' => true,
            ],
            [
                'name' => 'Po_روش3_فاقد_تعدیل_شاخص_اعلام_نشده',
                'category' => 'po_calculation',
                'description' => 'روش 3 - کار فاقد تعدیل با شاخص اعلام نشده (میانگین تفاضل)',
                'formula' => 'Pb * (F2_prime / F1)',
                'parameters' => ['Pb' => 'برآورد اولیه', 'F1' => 'شاخص F₁', 'F2_prime' => 'F\'₂ محاسبه شده'],
                'conditions' => ['method' => '3', 'isAdjustable' => false, 'F2_declared' => false],
                'is_default' => true,
            ],
            // فرمول‌های محاسبه Beta و Gamma
            [
                'name' => 'Beta_روش1_تعدیل_I2_برابر_I3',
                'category' => 'po_calculation',
                'description' => 'محاسبه β برای کار دارای تعدیل زمانی که I₂ برابر I₃ است',
                'formula' => 'I2 / I1',
                'parameters' => ['I1' => 'شاخص I₁', 'I2' => 'شاخص I₂'],
                'conditions' => ['method' => '1', 'isAdjustable' => true, 'I2_equals_I3' => true, 'type' => 'beta'],
                'is_default' => true,
            ],
            [
                'name' => 'Beta_روش1_تعدیل_I2_مخالف_I3',
                'category' => 'po_calculation',
                'description' => 'محاسبه β برای کار دارای تعدیل زمانی که I₂ برابر I₃ نیست',
                'formula' => '(I3 / I1) * pow(1 + r2, Tβ)',
                'parameters' => ['I1' => 'شاخص I₁', 'I3' => 'شاخص I₃', 'r2' => 'نرخ تغییر r₂', 'Tβ' => 'دوره T_β'],
                'conditions' => ['method' => '1', 'isAdjustable' => true, 'I2_equals_I3' => false, 'type' => 'beta'],
                'is_default' => true,
            ],
            [
                'name' => 'Gamma_روش1_فاقد_تعدیل_I2_برابر_I3',
                'category' => 'po_calculation',
                'description' => 'محاسبه γ برای کار فاقد تعدیل زمانی که I₂ برابر I₃ است',
                'formula' => '(I2 / I1) * pow(1 + r1, T_γ)',
                'parameters' => ['I1' => 'شاخص I₁', 'I2' => 'شاخص I₂', 'r1' => 'نرخ تغییر r₁', 'T_γ' => 'دوره T_γ'],
                'conditions' => ['method' => '1', 'isAdjustable' => false, 'I2_equals_I3' => true, 'type' => 'gamma'],
                'is_default' => true,
            ],
            [
                'name' => 'Gamma_روش1_فاقد_تعدیل_I2_مخالف_I3',
                'category' => 'po_calculation',
                'description' => 'محاسبه γ برای کار فاقد تعدیل زمانی که I₂ برابر I₃ نیست',
                'formula' => '(I3 / I1) * pow(1 + r2, T_γ)',
                'parameters' => ['I1' => 'شاخص I₁', 'I3' => 'شاخص I₃', 'r2' => 'نرخ تغییر r₂', 'T_γ' => 'دوره T_γ'],
                'conditions' => ['method' => '1', 'isAdjustable' => false, 'I2_equals_I3' => false, 'type' => 'gamma'],
                'is_default' => true,
            ],
            [
                'name' => 'Beta_روش2_شاخص_اعلام_شده',
                'category' => 'po_calculation',
                'description' => 'محاسبه β برای روش 2 با شاخص اعلام شده',
                'formula' => 'F2 / F1',
                'parameters' => ['F1' => 'شاخص F₁', 'F2' => 'شاخص F₂'],
                'conditions' => ['method' => '2', 'isAdjustable' => true, 'F2_declared' => true, 'type' => 'beta'],
                'is_default' => true,
            ],
            [
                'name' => 'Gamma_روش2_شاخص_اعلام_شده',
                'category' => 'po_calculation',
                'description' => 'محاسبه γ برای روش 2 با شاخص اعلام شده',
                'formula' => 'F2 / F1',
                'parameters' => ['F1' => 'شاخص F₁', 'F2' => 'شاخص F₂'],
                'conditions' => ['method' => '2', 'isAdjustable' => false, 'F2_declared' => true, 'type' => 'gamma'],
                'is_default' => true,
            ],
            [
                'name' => 'Beta_روش2_شاخص_اعلام_نشده',
                'category' => 'po_calculation',
                'description' => 'محاسبه β برای روش 2 با شاخص اعلام نشده',
                'formula' => 'F2_prime / F1',
                'parameters' => ['F1' => 'شاخص F₁', 'F2_prime' => 'F\'₂ محاسبه شده'],
                'conditions' => ['method' => '2', 'isAdjustable' => true, 'F2_declared' => false, 'type' => 'beta'],
                'is_default' => true,
            ],
            [
                'name' => 'Gamma_روش2_شاخص_اعلام_نشده',
                'category' => 'po_calculation',
                'description' => 'محاسبه γ برای روش 2 با شاخص اعلام نشده',
                'formula' => 'F2_prime / F1',
                'parameters' => ['F1' => 'شاخص F₁', 'F2_prime' => 'F\'₂ محاسبه شده'],
                'conditions' => ['method' => '2', 'isAdjustable' => false, 'F2_declared' => false, 'type' => 'gamma'],
                'is_default' => true,
            ],
            [
                'name' => 'Beta_روش3_شاخص_اعلام_شده',
                'category' => 'po_calculation',
                'description' => 'محاسبه β برای روش 3 با شاخص اعلام شده',
                'formula' => 'F2 / F1',
                'parameters' => ['F1' => 'شاخص F₁', 'F2' => 'شاخص F₂'],
                'conditions' => ['method' => '3', 'isAdjustable' => true, 'F2_declared' => true, 'type' => 'beta'],
                'is_default' => true,
            ],
            [
                'name' => 'Gamma_روش3_شاخص_اعلام_شده',
                'category' => 'po_calculation',
                'description' => 'محاسبه γ برای روش 3 با شاخص اعلام شده',
                'formula' => 'F2 / F1',
                'parameters' => ['F1' => 'شاخص F₁', 'F2' => 'شاخص F₂'],
                'conditions' => ['method' => '3', 'isAdjustable' => false, 'F2_declared' => true, 'type' => 'gamma'],
                'is_default' => true,
            ],
            [
                'name' => 'Beta_روش3_شاخص_اعلام_نشده',
                'category' => 'po_calculation',
                'description' => 'محاسبه β برای روش 3 با شاخص اعلام نشده',
                'formula' => 'F2_prime / F1',
                'parameters' => ['F1' => 'شاخص F₁', 'F2_prime' => 'F\'₂ محاسبه شده'],
                'conditions' => ['method' => '3', 'isAdjustable' => true, 'F2_declared' => false, 'type' => 'beta'],
                'is_default' => true,
            ],
            [
                'name' => 'Gamma_روش3_شاخص_اعلام_نشده',
                'category' => 'po_calculation',
                'description' => 'محاسبه γ برای روش 3 با شاخص اعلام نشده',
                'formula' => 'F2_prime / F1',
                'parameters' => ['F1' => 'شاخص F₁', 'F2_prime' => 'F\'₂ محاسبه شده'],
                'conditions' => ['method' => '3', 'isAdjustable' => false, 'F2_declared' => false, 'type' => 'gamma'],
                'is_default' => true,
            ],
            // فرمول‌های محاسبه F2_prime
            [
                'name' => 'F2_prime_روش2_میانگین_نسبت',
                'category' => 'po_calculation',
                'description' => 'محاسبه F\'₂ برای روش 2 با استفاده از میانگین نسبت‌ها',
                'formula' => 'f9 * pow(avgRatio, z)',
                'parameters' => ['f9' => 'شاخص f₉', 'avgRatio' => 'میانگین نسبت‌ها', 'z' => 'ضریب z'],
                'conditions' => ['method' => '2', 'F2_declared' => false, 'type' => 'F2_prime'],
                'is_default' => true,
            ],
            [
                'name' => 'F2_prime_روش3_میانگین_تفاضل',
                'category' => 'po_calculation',
                'description' => 'محاسبه F\'₂ برای روش 3 با استفاده از میانگین تفاضل',
                'formula' => 'f9 + (avgDiff * z)',
                'parameters' => ['f9' => 'شاخص f₉', 'avgDiff' => 'میانگین تفاضل', 'z' => 'ضریب z'],
                'conditions' => ['method' => '3', 'F2_declared' => false, 'type' => 'F2_prime'],
                'is_default' => true,
            ],
            // فرمول‌های محاسبه میانگین
            [
                'name' => 'میانگین_نسبت_روش2',
                'category' => 'po_calculation',
                'description' => 'محاسبه میانگین نسبت‌ها برای روش 2',
                'formula' => 'sum_ratios / count_ratios',
                'parameters' => ['sum_ratios' => 'مجموع نسبت‌ها', 'count_ratios' => 'تعداد نسبت‌ها'],
                'conditions' => ['method' => '2', 'type' => 'avgRatio'],
                'is_default' => true,
            ],
            [
                'name' => 'میانگین_تفاضل_روش3',
                'category' => 'po_calculation',
                'description' => 'محاسبه میانگین تفاضل برای روش 3',
                'formula' => 'sum_diffs / count_diffs',
                'parameters' => ['sum_diffs' => 'مجموع تفاضل‌ها', 'count_diffs' => 'تعداد تفاضل‌ها'],
                'conditions' => ['method' => '3', 'type' => 'avgDiff'],
                'is_default' => true,
            ],
            // فرمول‌های محاسبه قیمت تراز شده
            [
                'name' => 'قیمت_تراز_شده_تک_مرحله',
                'category' => 'evaluation',
                'description' => 'محاسبه قیمت تراز شده برای مناقصه تک مرحله‌ای: P\'_i = P_i',
                'formula' => 'P_i',
                'parameters' => ['P_i' => 'قیمت پیشنهادی مناقصه‌گر i'],
                'conditions' => ['is_two_stage' => false],
                'is_default' => true,
            ],
            [
                'name' => 'قیمت_تراز_شده_دو_مرحله',
                'category' => 'evaluation',
                'description' => 'محاسبه قیمت تراز شده برای مناقصه دو مرحله‌ای: P\'_i = P_i × (A_max / A_i)',
                'formula' => 'P_i * (A_max / A_i)',
                'parameters' => ['P_i' => 'قیمت پیشنهادی', 'A_max' => 'حداکثر امتیاز فنی-بازرگانی', 'A_i' => 'امتیاز فنی-بازرگانی مناقصه‌گر i'],
                'conditions' => ['is_two_stage' => true],
                'is_default' => true,
            ],
            // فرمول‌های محاسبه محدوده نهایی
            [
                'name' => 'محدوده_نهایی_مسیر_ساده',
                'category' => 'evaluation',
                'description' => 'محاسبه محدوده نهایی برای مسیر ساده',
                'formula' => 'P_Lower = 0.9 * Po, P_Upper = 1.1 * Po',
                'parameters' => ['Po' => 'برآورد به‌هنگام'],
                'conditions' => ['route' => 'simple'],
                'is_default' => true,
            ],
            [
                'name' => 'محدوده_نهایی_مسیر_آماری',
                'category' => 'evaluation',
                'description' => 'محاسبه محدوده نهایی برای مسیر آماری',
                'formula' => 'P_Lower = Po * (1 - annex_percent), P_Upper = Po * (1 + annex_percent)',
                'parameters' => ['Po' => 'برآورد به‌هنگام', 'annex_percent' => 'درصد دامنه الحاقی'],
                'conditions' => ['route' => 'statistical'],
                'is_default' => true,
            ],
        ];

        $createdCount = 0;
        $updatedCount = 0;
        
        foreach ($defaultFormulas as $formulaData) {
            $existing = Formula::where('name', $formulaData['name'])->first();
            
            if ($existing) {
                // به‌روزرسانی فرمول موجود
                $existing->update(array_merge($formulaData, [
                    'is_active' => true,
                    'version' => ($existing->version ?? 1) + 1,
                ]));
                $updatedCount++;
            } else {
                // ایجاد فرمول جدید
                Formula::create(array_merge($formulaData, [
                    'id' => Str::uuid(),
                    'is_active' => true,
                    'version' => 1,
                ]));
                $createdCount++;
            }
        }
        
        // Log نتیجه
        \Log::info('DefaultFormulasSeeder completed', [
            'total_in_seeder' => count($defaultFormulas),
            'created' => $createdCount,
            'updated' => $updatedCount,
            'total_in_db' => Formula::count()
        ]);
    }
}

