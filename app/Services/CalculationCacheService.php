<?php

namespace App\Services;

use App\Models\Tender;
use App\Models\Estimate;
use App\Models\Index;
use App\Models\Bidder;

/**
 * سرویس مدیریت Cache برای محاسبات Po و Evaluation
 */
class CalculationCacheService
{
    /**
     * تولید hash از ورودی‌های موثر در محاسبه Po
     */
    public function generatePoInputHash(string $tenderId): string
    {
        $tender = Tender::find($tenderId);
        if (!$tender) {
            return '';
        }

        // جمع‌آوری تمام ورودی‌های موثر در محاسبه Po
        $inputs = [
            'po_method' => $tender->po_method ?? '1',
            'is_adjustable' => $tender->is_adjustable ?? false,
            'tgamma' => floatval($tender->tgamma ?? 1.0),
            'tbeta' => floatval($tender->tbeta ?? 0.5),
            'delta' => floatval($tender->delta ?? 0),
        ];

        // برآوردها (Pb)
        $estimates = Estimate::where('tender_id', $tenderId)->get();
        $estimatesData = [];
        foreach ($estimates as $estimate) {
            $estimatesData[] = [
                'amount' => floatval($estimate->amount_in_rials ?? 0),
                'is_adjustable' => $estimate->is_adjustable ?? false,
            ];
        }
        $inputs['estimates'] = $estimatesData;

        // شاخص‌ها
        $indices = Index::where('tender_id', $tenderId)->get();
        $indicesData = [];
        foreach ($indices as $index) {
            $indicesData[] = [
                'type' => $index->type ?? '',
                'value' => floatval($index->value ?? 0),
            ];
        }
        $inputs['indices'] = $indicesData;

        // تولید hash
        $json = json_encode($inputs, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
        return md5($json);
    }

    /**
     * تولید hash از ورودی‌های موثر در محاسبه Evaluation
     */
    public function generateEvaluationInputHash(string $tenderId): string
    {
        $tender = Tender::find($tenderId);
        if (!$tender) {
            return '';
        }

        // جمع‌آوری تمام ورودی‌های موثر در محاسبه Evaluation
        $inputs = [
            'po' => floatval($tender->po ?? 0),
            'is_two_stage' => $tender->is_two_stage ?? false,
            'a_max' => floatval($tender->a_max ?? 100),
            'normalization_factor' => floatval($tender->normalization_factor ?? 0.3),
        ];

        // مناقصه‌گران
        $bidders = Bidder::where('tender_id', $tenderId)->get();
        $biddersData = [];
        foreach ($bidders as $bidder) {
            $biddersData[] = [
                'id' => $bidder->id ?? '',
                'technical_score' => floatval($bidder->technical_score ?? 0),
            ];

            // قیمت‌های پیشنهادی
            $priceItems = $bidder->priceItems ?? [];
            $priceItemsData = [];
            foreach ($priceItems as $item) {
                $priceItemsData[] = [
                    'amount' => floatval($item->amount_in_rials ?? 0),
                    'currency' => $item->currency ?? 'IRR',
                    'exchange_rate' => floatval($item->exchange_rate ?? 1),
                ];
            }
            $biddersData[count($biddersData) - 1]['price_items'] = $priceItemsData;
        }
        $inputs['bidders'] = $biddersData;

        // تولید hash
        $json = json_encode($inputs, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
        return md5($json);
    }

    /**
     * بررسی اینکه آیا ورودی‌های Po تغییر کرده‌اند
     */
    public function hasPoInputsChanged(string $tenderId): bool
    {
        $tender = Tender::find($tenderId);
        if (!$tender) {
            return true;
        }

        $currentHash = $this->generatePoInputHash($tenderId);
        $storedHash = $tender->po_input_hash ?? null;

        return $currentHash !== $storedHash;
    }

    /**
     * بررسی اینکه آیا ورودی‌های Evaluation تغییر کرده‌اند
     */
    public function hasEvaluationInputsChanged(string $tenderId): bool
    {
        $tender = Tender::find($tenderId);
        if (!$tender) {
            return true;
        }

        $currentHash = $this->generateEvaluationInputHash($tenderId);
        $storedHash = $tender->evaluation_input_hash ?? null;

        return $currentHash !== $storedHash;
    }

    /**
     * ذخیره hash ورودی‌های Po
     */
    public function savePoInputHash(string $tenderId): void
    {
        $hash = $this->generatePoInputHash($tenderId);
        Tender::where('id', $tenderId)->update(['po_input_hash' => $hash]);
    }

    /**
     * ذخیره hash ورودی‌های Evaluation
     */
    public function saveEvaluationInputHash(string $tenderId): void
    {
        $hash = $this->generateEvaluationInputHash($tenderId);
        Tender::where('id', $tenderId)->update(['evaluation_input_hash' => $hash]);
    }

    /**
     * باطل کردن cache Po (وقتی ورودی‌ها تغییر می‌کنند)
     */
    public function invalidatePoCache(string $tenderId): void
    {
        Tender::where('id', $tenderId)->update([
            'po_input_hash' => null,
        ]);
        Tender::invalidatePo($tenderId);
    }

    /**
     * باطل کردن cache Evaluation (وقتی ورودی‌ها تغییر می‌کنند)
     */
    public function invalidateEvaluationCache(string $tenderId): void
    {
        Tender::where('id', $tenderId)->update([
            'evaluation_input_hash' => null,
        ]);
        Tender::invalidateEvaluation($tenderId);
    }
}

