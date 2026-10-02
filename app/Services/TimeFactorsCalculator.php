<?php

namespace App\Services;

use Morilog\Jalali\Jalalian;

/**
 * محاسبه فاکتورهای زمانی T_β و T_γ
 * طبق دستورالعمل ارزیابی مالی وزارت نفت
 */
class TimeFactorsCalculator
{
    /**
     * محاسبه T_β: مدت از زمان آخرین شاخص منتشرشده تا پایان دوره مبنای پیمان (بر حسب سال)
     * @param string|null $lastIndexDate - تاریخ آخرین شاخص منتشرشده (شمسی)
     * @param string|null $contractBasePeriodEnd - تاریخ پایان دوره مبنای پیمان (شمسی)
     * @return float|null T_β بر حسب سال (اعشاری)
     */
    public function calculateT_beta(?string $lastIndexDate, ?string $contractBasePeriodEnd): ?float
    {
        if (empty($lastIndexDate) || empty($contractBasePeriodEnd)) {
            return null;
        }

        try {
            // تبدیل تاریخ‌های شمسی به timestamp
            $lastIndex = $this->parseJalaliDate($lastIndexDate);
            $contractEnd = $this->parseJalaliDate($contractBasePeriodEnd);

            if (!$lastIndex || !$contractEnd) {
                return null;
            }

            // محاسبه تفاضل به روز
            $diffDays = ($contractEnd->getTimestamp() - $lastIndex->getTimestamp()) / (24 * 60 * 60);

            // تبدیل به سال (365.25 روز برای در نظر گیری سال کبیسه)
            $T_beta = $diffDays / 365.25;

            return round($T_beta, 4); // گرد کردن به 4 رقم اعشار
        } catch (\Exception $e) {
            \Log::error('Error calculating T_beta: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * محاسبه T_γ: مدت از زمان آخرین شاخص منتشرشده تا زمان نصف مدت اولیه پیمان (بر حسب سال)
     * @param string|null $lastIndexDate - تاریخ آخرین شاخص منتشرشده (شمسی)
     * @param string|null $proposalValidityEnd - تاریخ پایان مدت اعتبار پیشنهادها (شمسی)
     * @param int|null $contractDurationMonths - مدت قرارداد (ماه)
     * @return float|null T_γ بر حسب سال (اعشاری)
     */
    public function calculateT_gamma(?string $lastIndexDate, ?string $proposalValidityEnd, ?int $contractDurationMonths): ?float
    {
        if (empty($lastIndexDate) || empty($proposalValidityEnd) || empty($contractDurationMonths)) {
            return null;
        }

        try {
            // تبدیل تاریخ‌های شمسی به timestamp
            $lastIndex = $this->parseJalaliDate($lastIndexDate);
            $validityEnd = $this->parseJalaliDate($proposalValidityEnd);

            if (!$lastIndex || !$validityEnd) {
                return null;
            }

            // تاریخ شروع پیمان = تاریخ پایان مدت اعتبار پیشنهادها
            // تاریخ نصف مدت اولیه پیمان = شروع + (مدت / 2)
            $halfDurationDays = ($contractDurationMonths / 2) * 30; // تقریب ماه به روز
            $midContractTimestamp = $validityEnd->getTimestamp() + ($halfDurationDays * 24 * 60 * 60);

            // محاسبه تفاضل به روز
            $diffDays = ($midContractTimestamp - $lastIndex->getTimestamp()) / (24 * 60 * 60);

            // تبدیل به سال (365.25 روز برای در نظر گیری سال کبیسه)
            $T_gamma = $diffDays / 365.25;

            return round($T_gamma, 4); // گرد کردن به 4 رقم اعشار
        } catch (\Exception $e) {
            \Log::error('Error calculating T_gamma: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * محاسبه خودکار T_β و T_γ از اطلاعات tender
     * @param array $tender - اطلاعات مناقصه
     * @param string|null $lastIndexDate - تاریخ آخرین شاخص منتشرشده (شمسی)
     * @return array { Tβ: float|null, T_γ: float|null }
     */
    public function calculateTimeFactors(array $tender, ?string $lastIndexDate): array
    {
        if (empty($tender) || empty($lastIndexDate)) {
            return [
                'Tβ' => null,
                'T_γ' => null,
            ];
        }

        // T_β: نیاز به تاریخ پایان دوره مبنای پیمان
        // طبق بند 3-7: سه‌ماه قبل از سه‌ماه حاوی آخرین مهلت پیشنهاد
        // در حال حاضر، از فیلد base_period استفاده می‌کنیم
        $contractBasePeriodEnd = $tender['base_period'] ?? $tender['contract_base_period_end'] ?? null;
        $T_beta = $this->calculateT_beta($lastIndexDate, $contractBasePeriodEnd);

        // T_γ: نیاز به تاریخ پایان مدت اعتبار پیشنهادها و مدت قرارداد
        $proposalValidityEnd = $tender['proposal_validity_end'] ?? $tender['date'] ?? null; // تاریخ بازگشایی
        $contractDurationMonths = $tender['contract_duration_months'] ?? 12; // پیش‌فرض 12 ماه
        $T_gamma = $this->calculateT_gamma($lastIndexDate, $proposalValidityEnd, $contractDurationMonths);

        return [
            'Tβ' => $T_beta,
            'T_γ' => $T_gamma,
        ];
    }

    /**
     * Parse Jalali date string to Jalalian object
     * @param string $dateString - Date in format YYYY/MM/DD or YYYY-MM-DD
     * @return Jalalian|null
     */
    private function parseJalaliDate(string $dateString): ?Jalalian
    {
        try {
            // Try to parse the date
            $dateString = str_replace('-', '/', $dateString);
            $parts = explode('/', $dateString);

            if (count($parts) !== 3) {
                return null;
            }

            $year = intval($parts[0]);
            $month = intval($parts[1]);
            $day = intval($parts[2]);

            // Validate date parts
            if ($year < 1300 || $year > 1500 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
                return null;
            }

            return new Jalalian($year, $month, $day);
        } catch (\Exception $e) {
            return null;
        }
    }
}

