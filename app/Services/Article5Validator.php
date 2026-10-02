<?php

namespace App\Services;

use App\Models\Estimate;
use App\Models\Bidder;

/**
 * کنترل ماده 5 دستورالعمل ارزیابی مالی وزارت نفت
 * تبصره‌های 1 و 2: کنترل انحراف 3% سهم ارز و منشأ خارج
 */
class Article5Validator
{
    /**
     * محاسبه سهم ارز از price_items یا estimates
     * @param array $items - آرایه price_items یا estimates
     * @param float $totalAmount - مبلغ کل به ریال
     * @return float سهم ارز (0 تا 1)
     */
    public function calculateForexShare(array $items, float $totalAmount): float
    {
        if (empty($items) || $totalAmount <= 0) {
            return 0;
        }

        // محاسبه مجموع بخش‌های ارزی (غیر از IRR)
        $forexAmount = array_reduce($items, function ($sum, $item) {
            $currency = $item['currency'] ?? $item['currency_name'] ?? 'IRR';
            if ($currency !== 'IRR' && $currency !== 'ریال') {
                return $sum + floatval($item['amount_in_rials'] ?? 0);
            }
            return $sum;
        }, 0);

        return $forexAmount / $totalAmount;
    }

    /**
     * محاسبه سهم منشأ خارج از price_items یا estimates
     * توجه: در حال حاضر فیلد origin در schema وجود ندارد
     * این تابع آماده است برای زمانی که فیلد origin اضافه شود
     * @param array $items - آرایه price_items یا estimates
     * @param float $totalAmount - مبلغ کل به ریال
     * @return float سهم منشأ خارج (0 تا 1)
     */
    public function calculateForeignOriginShare(array $items, float $totalAmount): float
    {
        if (empty($items) || $totalAmount <= 0) {
            return 0;
        }

        // در حال حاضر، از description یا notes برای تشخیص منشأ استفاده می‌کنیم
        // یا می‌توانیم از currency استفاده کنیم (اگر currency غیر از IRR باشد و exchange_rate داشته باشد)
        // این یک راه‌حل موقت است - باید فیلد origin به schema اضافه شود

        $foreignOriginAmount = array_reduce($items, function ($sum, $item) {
            // اگر description شامل "خارج" یا "foreign" باشد
            $description = strtolower($item['description'] ?? '');
            $notes = strtolower($item['notes'] ?? '');

            if (str_contains($description, 'خارج') || str_contains($description, 'foreign') ||
                str_contains($notes, 'خارج') || str_contains($notes, 'foreign')) {
                return $sum + floatval($item['amount_in_rials'] ?? 0);
            }

            // یا اگر currency غیر از IRR باشد و exchange_rate داشته باشد (احتمالاً منشأ خارج)
            // $currency = $item['currency'] ?? $item['currency_name'] ?? 'IRR';
            // if ($currency !== 'IRR' && $currency !== 'ریال' && !empty($item['exchange_rate'])) {
            //     // این یک فرض است - باید با کاربر تأیید شود
            //     // return $sum + floatval($item['amount_in_rials'] ?? 0);
            // }

            return $sum;
        }, 0);

        return $foreignOriginAmount / $totalAmount;
    }

    /**
     * بررسی انحراف 3% ماده 5
     * @param float $bidderShare - سهم در پیشنهاد مناقصه‌گر
     * @param float $tenderShare - سهم در برآورد اولیه
     * @return array { passed: boolean, deviation: float, message: string }
     */
    public function checkArticle5Deviation(float $bidderShare, float $tenderShare): array
    {
        if ($tenderShare <= 0) {
            // اگر در برآورد اولیه سهم ارز/منشأ خارج وجود نداشته باشد، کنترل اعمال نمی‌شود
            return [
                'passed' => true,
                'deviation' => 0,
                'message' => 'در برآورد اولیه سهم ارز/منشأ خارج وجود ندارد',
            ];
        }

        $deviation = abs($bidderShare - $tenderShare) / $tenderShare;
        $maxDeviation = 0.03; // 3%

        if ($deviation > $maxDeviation) {
            return [
                'passed' => false,
                'deviation' => $deviation,
                'message' => sprintf('انحراف %.2f%% بیش از حد مجاز 3%% است', $deviation * 100),
            ];
        }

        return [
            'passed' => true,
            'deviation' => $deviation,
            'message' => sprintf('انحراف %.2f%% در محدوده مجاز است', $deviation * 100),
        ];
    }

    /**
     * کنترل کامل ماده 5 برای یک پیشنهاد
     * @param string $tenderId - شناسه مناقصه
     * @param array $bidderPriceItems - آرایه price_items مناقصه‌گر
     * @param float $bidderTotalPrice - مبلغ کل پیشنهاد مناقصه‌گر
     * @return array {
     *   passed: boolean,
     *   forexCheck: { passed, deviation, message },
     *   originCheck: { passed, deviation, message },
     *   errors: Array
     * }
     */
    public function validateArticle5(string $tenderId, array $bidderPriceItems, float $bidderTotalPrice): array
    {
        try {
            // دریافت estimates (برآورد اولیه)
            $estimates = Estimate::getByTenderId($tenderId);

            if (empty($estimates)) {
                // اگر برآورد اولیه وجود نداشته باشد، کنترل اعمال نمی‌شود
                return [
                    'passed' => true,
                    'forexCheck' => ['passed' => true, 'deviation' => 0, 'message' => 'برآورد اولیه ثبت نشده است'],
                    'originCheck' => ['passed' => true, 'deviation' => 0, 'message' => 'برآورد اولیه ثبت نشده است'],
                    'errors' => [],
                ];
            }

            // محاسبه مبلغ کل برآورد اولیه
            $tenderTotalPrice = array_reduce($estimates, function ($sum, $estimate) {
                return $sum + floatval($estimate['amount_in_rials'] ?? 0);
            }, 0);

            // محاسبه سهم ارز
            $bidderForexShare = $this->calculateForexShare($bidderPriceItems, $bidderTotalPrice);
            $tenderForexShare = $this->calculateForexShare($estimates, $tenderTotalPrice);
            $forexCheck = $this->checkArticle5Deviation($bidderForexShare, $tenderForexShare);

            // محاسبه سهم منشأ خارج
            $bidderForeignOriginShare = $this->calculateForeignOriginShare($bidderPriceItems, $bidderTotalPrice);
            $tenderForeignOriginShare = $this->calculateForeignOriginShare($estimates, $tenderTotalPrice);
            $originCheck = $this->checkArticle5Deviation($bidderForeignOriginShare, $tenderForeignOriginShare);

            $errors = [];
            if (!$forexCheck['passed']) {
                $errors[] = 'سهم ارز: ' . $forexCheck['message'];
            }
            if (!$originCheck['passed']) {
                $errors[] = 'سهم منشأ خارج: ' . $originCheck['message'];
            }

            return [
                'passed' => $forexCheck['passed'] && $originCheck['passed'],
                'forexCheck' => $forexCheck,
                'originCheck' => $originCheck,
                'errors' => $errors,
                'details' => [
                    'bidderForexShare' => $bidderForexShare,
                    'tenderForexShare' => $tenderForexShare,
                    'bidderForeignOriginShare' => $bidderForeignOriginShare,
                    'tenderForeignOriginShare' => $tenderForeignOriginShare,
                ],
            ];
        } catch (\Exception $e) {
            return [
                'passed' => false,
                'forexCheck' => ['passed' => false, 'deviation' => 0, 'message' => 'خطا در بررسی: ' . $e->getMessage()],
                'originCheck' => ['passed' => false, 'deviation' => 0, 'message' => 'خطا در بررسی: ' . $e->getMessage()],
                'errors' => ['خطا در بررسی ماده 5: ' . $e->getMessage()],
            ];
        }
    }
}

