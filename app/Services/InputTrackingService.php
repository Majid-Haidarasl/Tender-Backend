<?php

namespace App\Services;

use App\Models\Estimate;
use App\Models\Index;
use App\Models\Bidder;
use App\Models\Tender;
use App\Services\AuditTrailService;
use App\Services\DataValidationService;

/**
 * سرویس ردیابی خودکار ورودی‌ها
 * 
 * این سرویس به صورت خودکار ورودی‌ها را در Audit Trail ثبت می‌کند
 */
class InputTrackingService
{
    private $auditTrailService;
    private $validationService;

    public function __construct()
    {
        $this->auditTrailService = new AuditTrailService();
        $this->validationService = new DataValidationService();
    }

    /**
     * ثبت ورودی Pb
     */
    public function trackPb(string $tenderId, string $estimateId, float $pb): void
    {
        $validation = $this->validationService->validatePb($tenderId);
        
        $this->auditTrailService->logInput(
            $tenderId,
            \App\Models\InputHistory::INPUT_PB,
            'برآورد اولیه (Pb)',
            \App\Models\InputHistory::ENTITY_ESTIMATE,
            $estimateId,
            $pb,
            null,
            null,
            [
                'valid' => $validation['valid'] ?? true,
                'errors' => $validation['errors'] ?? [],
                'warnings' => $validation['warnings'] ?? [],
            ],
            \App\Services\AlertNotificationService::STAGE_PB_INPUT
        );
    }

    /**
     * ثبت ورودی Po
     */
    public function trackPo(string $tenderId, float $po, ?array $calculationData = null): void
    {
        // اعتبارسنجی Po (اگر متد public باشد)
        $validation = ['valid' => true, 'errors' => []];
        try {
            if (method_exists($this->validationService, 'validatePo')) {
                $reflection = new \ReflectionMethod($this->validationService, 'validatePo');
                if ($reflection->isPublic()) {
                    $tender = Tender::find($tenderId);
                    $validation = $this->validationService->validatePo($tenderId, $tender);
                }
            }
        } catch (\Exception $e) {
            // اگر اعتبارسنجی در دسترس نباشد، ادامه می‌دهیم
        }
        
        $this->auditTrailService->logInput(
            $tenderId,
            \App\Models\InputHistory::INPUT_PO,
            'برآورد به‌هنگام (Po)',
            \App\Models\InputHistory::ENTITY_TENDER,
            $tenderId,
            $po,
            null,
            null,
            [
                'valid' => $validation['valid'] ?? true,
                'errors' => $validation['errors'] ?? [],
                'warnings' => $validation['warnings'] ?? [],
                'calculation_data' => $calculationData,
            ],
            \App\Services\AlertNotificationService::STAGE_PO_CALCULATION
        );
    }

    /**
     * ثبت ورودی Pi
     */
    public function trackPi(string $tenderId, string $bidderId, float $pi, ?float $adjustedPrice = null, ?float $normalizedPrice = null): void
    {
        $bidder = Bidder::find($bidderId);
        $bidderName = $bidder ? $bidder->name : 'نامشخص';
        
        $this->auditTrailService->logInput(
            $tenderId,
            \App\Models\InputHistory::INPUT_PI,
            "قیمت پیشنهادی ({$bidderName})",
            \App\Models\InputHistory::ENTITY_BIDDER,
            $bidderId,
            $pi,
            $adjustedPrice,
            $normalizedPrice,
            [
                'valid' => $pi > 0,
                'adjusted' => $adjustedPrice !== null,
                'normalized' => $normalizedPrice !== null,
            ],
            \App\Services\AlertNotificationService::STAGE_PI_INPUT
        );
    }

    /**
     * ثبت ورودی شاخص
     */
    public function trackIndex(string $tenderId, string $indexId, string $indexType, float $value): void
    {
        $this->auditTrailService->logInput(
            $tenderId,
            \App\Models\InputHistory::INPUT_INDEX,
            "شاخص {$indexType}",
            \App\Models\InputHistory::ENTITY_INDEX,
            $indexId,
            $value,
            null,
            null,
            [
                'valid' => $value > 0,
                'index_type' => $indexType,
            ],
            \App\Services\AlertNotificationService::STAGE_INDEX_INPUT
        );
    }

    /**
     * ثبت ضریب β
     */
    public function trackBeta(string $tenderId, float $beta, ?array $calculationData = null): void
    {
        $validation = $this->validationService->validateBetaGamma($beta, null, true);
        
        $this->auditTrailService->logInput(
            $tenderId,
            \App\Models\InputHistory::INPUT_BETA,
            'ضریب تعدیل β',
            \App\Models\InputHistory::ENTITY_TENDER,
            $tenderId,
            $beta,
            null,
            null,
            [
                'valid' => $validation['valid'],
                'errors' => $validation['errors'],
                'warnings' => $validation['warnings'],
                'calculation_data' => $calculationData,
            ],
            \App\Services\AlertNotificationService::STAGE_PO_CALCULATION
        );
    }

    /**
     * ثبت ضریب γ
     */
    public function trackGamma(string $tenderId, float $gamma, ?array $calculationData = null): void
    {
        $validation = $this->validationService->validateBetaGamma(null, $gamma, false);
        
        $this->auditTrailService->logInput(
            $tenderId,
            \App\Models\InputHistory::INPUT_GAMMA,
            'ضریب تعدیل γ',
            \App\Models\InputHistory::ENTITY_TENDER,
            $tenderId,
            $gamma,
            null,
            null,
            [
                'valid' => $validation['valid'] ?? true,
                'errors' => $validation['errors'] ?? [],
                'warnings' => $validation['warnings'] ?? [],
                'calculation_data' => $calculationData,
            ],
            \App\Services\AlertNotificationService::STAGE_PO_CALCULATION
        );
    }
}

