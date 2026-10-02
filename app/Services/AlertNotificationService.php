<?php

namespace App\Services;

use App\Models\Tender;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * سرویس هشدار، خطا و اعلان جامع
 * مطابق دستورالعمل ارزیابی مالی مناقصات وزارت نفت
 */
class AlertNotificationService
{
    // انواع پیام
    const TYPE_WARNING = 'WARNING';
    const TYPE_ERROR = 'ERROR';
    const TYPE_INFO = 'INFO';

    // اولویت‌های پیام (بالاتر = مهم‌تر)
    const PRIORITY_CRITICAL = 100;  // خطای بحرانی - توقف فوری
    const PRIORITY_HIGH = 75;       // خطای مهم - توقف فرآیند
    const PRIORITY_MEDIUM = 50;     // هشدار - نیاز به اقدام
    const PRIORITY_LOW = 25;        // اطلاع - فقط اطلاع‌رسانی

    // مراحل فرآیند
    const STAGE_PB_INPUT = 'ورودی Pb';
    const STAGE_INDEX_INPUT = 'ورودی شاخص‌ها';
    const STAGE_PO_CALCULATION = 'محاسبه Po';
    const STAGE_PI_INPUT = 'ورودی Pi';
    const STAGE_FORMAL_EVALUATION = 'ارزیابی شکلی';
    const STAGE_DECISION_PATH = 'مسیر تصمیم‌گیری';
    const STAGE_STATISTICAL_ANALYSIS = 'تحلیل آماری';
    const STAGE_NORMALIZATION = 'نرمال‌سازی';
    const STAGE_WINNER_SELECTION = 'تعیین برنده';
    const STAGE_TECHNICAL_COMMERCIAL = 'امتیاز فنی-بازرگانی';
    const STAGE_STATUS_UPDATE = 'اعلان وضعیت';

    /**
     * تعریف تمام پیام‌های سیستم
     */
    private const MESSAGES = [
        'W001' => [
            'type' => self::TYPE_WARNING,
            'stage' => self::STAGE_PB_INPUT,
            'condition' => 'Pb ناقص یا مستندات ناقص',
            'description' => 'برآورد اولیه بدون جزئیات کافی یا فاقد مستندات پایه',
            'message' => 'برآورد اولیه ناقص است. لطفاً اطلاعات شرح کار، فهرست بها و مبانی محاسبه را تکمیل کنید.',
            'priority' => self::PRIORITY_HIGH,
            'blocks_process' => true,
        ],
        'W002' => [
            'type' => self::TYPE_WARNING,
            'stage' => self::STAGE_INDEX_INPUT,
            'condition' => 'F1…F9 ناقص',
            'description' => 'شاخص‌های تعدیل تاریخی ناقص هستند',
            'message' => 'شاخص‌های تعدیل تاریخی ناقص هستند. لطفاً تمام مقادیر f1 تا f9 را وارد کنید.',
        ],
        'E001' => [
            'type' => self::TYPE_ERROR,
            'stage' => self::STAGE_PO_CALCULATION,
            'condition' => 'Pb یا شاخص‌ها معتبر نیست',
            'description' => 'امکان محاسبه برآورد به‌هنگام وجود ندارد',
            'message' => 'محاسبه Po ممکن نیست. ورودی Pb یا شاخص‌های تعدیل معتبر نیستند.',
        ],
        'W003' => [
            'type' => self::TYPE_WARNING,
            'stage' => self::STAGE_PO_CALCULATION,
            'condition' => 'مسیر دو مرحله‌ای، تراز نیاز است',
            'description' => 'برای مناقصه دو مرحله‌ای، باید قیمت تراز شود',
            'message' => 'این مناقصه دو مرحله‌ای است. لطفاً قیمت‌ها را تراز کنید قبل از ادامه.',
        ],
        'W004' => [
            'type' => self::TYPE_WARNING,
            'stage' => self::STAGE_PI_INPUT,
            'condition' => 'Pi ناقص یا تفکیک ارزی/ریالی ناقص',
            'description' => 'قیمت پیشنهادی مناقصه‌گر ناقص است',
            'message' => 'قیمت پیشنهادی ناقص است یا تفکیک ریالی/ارزی مشخص نشده است.',
        ],
        'E002' => [
            'type' => self::TYPE_ERROR,
            'stage' => self::STAGE_FORMAL_EVALUATION,
            'condition' => 'Pi خارج از محدوده اولیه ±3% بخش ارزی',
            'description' => 'بر اساس ماده ۵، پیشنهاد نامتعارف است',
            'message' => 'قیمت پیشنهادی مناقصه‌گر نامتعارف است و حذف شد (بیش از ۳٪ اختلاف در بخش ارزی).',
        ],
        'W005' => [
            'type' => self::TYPE_WARNING,
            'stage' => self::STAGE_DECISION_PATH,
            'condition' => 'n ≤ 2',
            'description' => 'تعداد پیشنهادها کم است',
            'message' => 'تعداد پیشنهادهای باقیمانده ≤ 2 است. مسیر ساده انتخاب برنده اعمال می‌شود.',
        ],
        'W006' => [
            'type' => self::TYPE_WARNING,
            'stage' => self::STAGE_DECISION_PATH,
            'condition' => 'n ≥ 4 و میانگین خارج از [0.8Po, 1.35Po]',
            'description' => 'میانگین نامتعارف',
            'message' => 'میانگین پیشنهادها خارج از بازه قابل قبول است. فرآیند متوقف شد، بازنگری Pb و Po و ارجاع به کمیته فنی لازم است.',
        ],
        'I001' => [
            'type' => self::TYPE_INFO,
            'stage' => self::STAGE_DECISION_PATH,
            'condition' => 'n ≥ 4 و میانگین در بازه',
            'description' => 'مسیر تحلیل آماری',
            'message' => 'میانگین پیشنهادها در محدوده است. تحلیل آماری ادامه می‌یابد.',
        ],
        'W007' => [
            'type' => self::TYPE_WARNING,
            'stage' => self::STAGE_STATISTICAL_ANALYSIS,
            'condition' => 'mo یا so غیر معتبر',
            'description' => 'محاسبه میانگین یا انحراف معیار مشکل دارد',
            'message' => 'محاسبه میانگین (mo) یا انحراف معیار (so) با خطا مواجه شد. بررسی ورودی‌ها لازم است.',
        ],
        'W008' => [
            'type' => self::TYPE_WARNING,
            'stage' => self::STAGE_NORMALIZATION,
            'condition' => 'P\'i خارج از [-1,1]',
            'description' => 'پیشنهاد نیاز به بررسی دامنه ±10% یا ±20% دارد',
            'message' => 'قیمت نرمال‌شده خارج از بازه است. بررسی استثنای دامنه ±10% یا ±20% لازم است.',
        ],
        'E003' => [
            'type' => self::TYPE_ERROR,
            'stage' => self::STAGE_STATISTICAL_ANALYSIS,
            'condition' => 'داده ناقص برای نرمال‌سازی',
            'description' => 'تعداد پیشنهادها یا Po ناقص است',
            'message' => 'نرمال‌سازی ممکن نیست. لطفاً داده‌های Pi و Po را کامل کنید.',
        ],
        'I002' => [
            'type' => self::TYPE_INFO,
            'stage' => self::STAGE_WINNER_SELECTION,
            'condition' => 'برنده انتخاب شد',
            'description' => 'بر اساس دامنه قیمت‌های متناسب',
            'message' => 'برنده مناقصه مشخص شد: کمترین قیمت از دامنه متناسب.',
        ],
        'W009' => [
            'type' => self::TYPE_WARNING,
            'stage' => self::STAGE_TECHNICAL_COMMERCIAL,
            'condition' => 'مناقصه دو مرحله‌ای و امتیاز لحاظ نشده',
            'description' => 'امتیاز فنی باید اعمال شود',
            'message' => 'امتیاز فنی-بازرگانی برای مناقصه دو مرحله‌ای ثبت نشده است. لطفاً اعمال کنید.',
        ],
        'E004' => [
            'type' => self::TYPE_ERROR,
            'stage' => self::STAGE_PI_INPUT,
            'condition' => 'Pi و f ناقص همزمان',
            'description' => 'داده‌ها برای ادامه فرآیند کافی نیست',
            'message' => 'ورودی‌ها ناقص هستند: قیمت پیشنهادی و شاخص‌های تعدیل تاریخی موجود نیست. اصلاح الزامی است.',
        ],
        'W010' => [
            'type' => self::TYPE_WARNING,
            'stage' => self::STAGE_DECISION_PATH,
            'condition' => 'مسیر وقفه و مسیر آماری همزمان',
            'description' => 'تداخل تصمیم‌گیری',
            'message' => 'تداخل در مسیرهای تصمیم‌گیری شناسایی شد. بررسی بازنگری Pb و Po و مسیر آماری لازم است.',
        ],
        'I003' => [
            'type' => self::TYPE_INFO,
            'stage' => self::STAGE_STATUS_UPDATE,
            'condition' => 'هر مرحله بدون خطا',
            'description' => 'اطلاع‌رسانی وضعیت جاری',
            'message' => 'فرآیند بدون خطا ادامه می‌یابد. مرحله فعلی: {stage_name}.',
        ],
        'W011' => [
            'type' => self::TYPE_WARNING,
            'stage' => self::STAGE_INDEX_INPUT,
            'condition' => 'شاخص‌های تعدیل تاریخی ناسازگار',
            'description' => 'f1 تا f9 از منابع مختلف یا دوره‌های ناهماهنگ وارد شده‌اند',
            'message' => 'شاخص‌های تعدیل تاریخی ناسازگار هستند. لطفاً دنباله f1 تا f9 را با ترتیب زمانی درست وارد کنید.',
        ],
        'W012' => [
            'type' => self::TYPE_WARNING,
            'stage' => self::STAGE_PO_CALCULATION,
            'condition' => 'محدوده ناهماهنگ شاخص‌ها نسبت به Pb',
            'description' => 'شاخص‌های اقتصادی باعث شده Po بسیار خارج از بازه منطقی نسبت به Pb شود',
            'message' => 'مقادیر شاخص‌ها باعث برآورد غیرمنطقی شده است. بازنگری Pb و شاخص‌ها الزامی است.',
        ],
        'E005' => [
            'type' => self::TYPE_ERROR,
            'stage' => self::STAGE_PO_CALCULATION,
            'condition' => 'خطا در اعمال روش پیش‌بینی (دوم یا سوم)',
            'description' => 'میانگین تغییرات یا تفاضلات عددی قابل محاسبه نیست',
            'message' => 'محاسبه F\'2 یا F\'3 با روش پیش‌بینی امکان‌پذیر نیست. داده‌های تاریخی بررسی شود.',
        ],
        'E006' => [
            'type' => self::TYPE_ERROR,
            'stage' => self::STAGE_TECHNICAL_COMMERCIAL,
            'condition' => 'عدم تطابق مسیر دو مرحله‌ای با امتیاز فنی-بازرگانی',
            'description' => 'در مناقصه دو مرحله‌ای، مرحله فنی ناقص است',
            'message' => 'مرحله امتیاز فنی-بازرگانی تکمیل نشده است. ادامه فرآیند امکان‌پذیر نیست.',
        ],
        'E007' => [
            'type' => self::TYPE_ERROR,
            'stage' => self::STAGE_STATISTICAL_ANALYSIS,
            'condition' => 'ورودی‌های ناقص ترکیبی در مسیر تحلیل آماری',
            'description' => 'Pi ناقص، Po یا mo/so غیرمعتبر، یا n کمتر از 4 و میانگین نامشخص',
            'message' => 'داده‌ها برای تحلیل آماری کافی نیست. لطفاً همه قیمت‌ها و برآوردها را کامل کنید.',
        ],
        'E009' => [
            'type' => self::TYPE_ERROR,
            'stage' => self::STAGE_PO_CALCULATION,
            'condition' => 'T_β ناقص برای کارهای دارای تعدیل',
            'description' => 'پارامتر T_β (بازه زمانی برای کارهای دارای تعدیل) تنظیم نشده یا نامعتبر است',
            'message' => 'برای کارهای دارای تعدیل با روش اول، پارامتر T_β الزامی است. لطفاً این پارامتر را در بخش "پارامترهای زمانی" تنظیم کنید.',
            'priority' => self::PRIORITY_HIGH,
            'blocks_process' => true,
        ],
        'E010' => [
            'type' => self::TYPE_ERROR,
            'stage' => self::STAGE_PO_CALCULATION,
            'condition' => 'T_γ ناقص برای کارهای فاقد تعدیل',
            'description' => 'پارامتر T_γ (بازه زمانی برای کارهای فاقد تعدیل) تنظیم نشده یا نامعتبر است',
            'message' => 'برای کارهای فاقد تعدیل با روش اول، پارامتر T_γ الزامی است. لطفاً این پارامتر را در بخش "پارامترهای زمانی" تنظیم کنید.',
            'priority' => self::PRIORITY_HIGH,
            'blocks_process' => true,
        ],
        'E011' => [
            'type' => self::TYPE_ERROR,
            'stage' => self::STAGE_PO_CALCULATION,
            'condition' => 'ضریب β یا γ نامعتبر',
            'description' => 'ضریب محاسبه شده (β برای کارهای دارای تعدیل یا γ برای کارهای فاقد تعدیل) نامعتبر است',
            'message' => 'ضریب محاسبه شده نامعتبر است. لطفاً شاخص‌ها و پارامترهای زمانی را بررسی کنید.',
            'priority' => self::PRIORITY_HIGH,
            'blocks_process' => true,
        ],
        'W011' => [
            'type' => self::TYPE_WARNING,
            'stage' => self::STAGE_PO_CALCULATION,
            'condition' => 'ضریب β یا γ خارج از محدوده منطقی',
            'description' => 'ضریب محاسبه شده خارج از محدوده منطقی (0.5 تا 2.0) است',
            'message' => 'ضریب محاسبه شده خارج از محدوده منطقی است. لطفاً شاخص‌ها و پارامترهای زمانی را بررسی کنید.',
            'priority' => self::PRIORITY_MEDIUM,
            'blocks_process' => false,
        ],
        'W013' => [
            'type' => self::TYPE_WARNING,
            'stage' => self::STAGE_NORMALIZATION,
            'condition' => 'مقادیر نرمال‌شده خارج از حد منطقی شدید',
            'description' => 'P\'i بسیار بزرگ یا کوچک (مثلاً ±5 یا ±10) که نشان‌دهنده مشکل شدید ورودی است',
            'message' => 'مقادیر نرمال‌شده خارج از بازه قابل قبول هستند. بررسی کامل ورودی‌ها و شاخص‌ها لازم است.',
        ],
    ];

    /**
     * ارسال پیام
     * 
     * @param string $messageId شناسه پیام
     * @param string $tenderId شناسه مناقصه
     * @param array $context اطلاعات اضافی
     * @return array
     */
    public function send(string $messageId, string $tenderId, array $context = []): array
    {
        if (!isset(self::MESSAGES[$messageId])) {
            throw new \Exception("پیام با شناسه {$messageId} یافت نشد");
        }

        $messageConfig = self::MESSAGES[$messageId];
        $message = $this->formatMessage($messageConfig['message'], $context);

        // ثبت در لاگ
        $this->logMessage($messageId, $messageConfig, $tenderId, $context);

        // ثبت در Audit Trail
        $auditTrailService = new \App\Services\AuditTrailService();
        $auditTrailService->logMessage(
            $tenderId,
            $messageId,
            $messageConfig['type'],
            $messageConfig['stage'],
            null,
            $messageConfig['priority'] ?? 0,
            $messageConfig['blocks_process'] ?? false,
            $context
        );

        // ثبت در دیتابیس
        $notification = $this->saveNotification($messageId, $messageConfig, $tenderId, $message, $context);

        // ارسال به داشبورد (در صورت نیاز)
        $this->sendToDashboard($messageId, $messageConfig, $tenderId, $message);

        return [
            'message_id' => $messageId,
            'type' => $messageConfig['type'],
            'stage' => $messageConfig['stage'],
            'message' => $message,
            'notification_id' => $notification->id ?? null,
            'timestamp' => now()->toDateTimeString(),
        ];
    }

    /**
     * ارسال هشدار
     */
    public function sendWarning(string $messageId, string $tenderId, array $context = []): array
    {
        return $this->send($messageId, $tenderId, $context);
    }

    /**
     * ارسال خطا
     */
    public function sendError(string $messageId, string $tenderId, array $context = []): array
    {
        $result = $this->send($messageId, $tenderId, $context);
        
        // برای خطاها، فرآیند متوقف می‌شود
        $this->stopProcess($tenderId, $messageId);
        
        return $result;
    }

    /**
     * ارسال اطلاع
     */
    public function sendInfo(string $messageId, string $tenderId, array $context = []): array
    {
        return $this->send($messageId, $tenderId, $context);
    }

    /**
     * بررسی و ارسال خودکار پیام‌ها بر اساس شرایط
     */
    public function checkAndSend(string $tenderId, string $stage, array $data = []): array
    {
        $messages = [];

        switch ($stage) {
            case self::STAGE_PB_INPUT:
                if (empty($data['pb']) || $data['pb'] <= 0) {
                    $messages[] = $this->sendWarning('W001', $tenderId, $data);
                }
                break;

            case self::STAGE_INDEX_INPUT:
                $missingIndices = $this->checkMissingIndices($tenderId, $data['po_method'] ?? '1');
                if (!empty($missingIndices)) {
                    $messages[] = $this->sendWarning('W002', $tenderId, ['missing_indices' => $missingIndices]);
                }
                break;

            case self::STAGE_PO_CALCULATION:
                if (empty($data['pb']) || empty($data['indices_valid'])) {
                    $messages[] = $this->sendError('E001', $tenderId, $data);
                }
                if (!empty($data['is_two_stage']) && empty($data['prices_adjusted'])) {
                    $messages[] = $this->sendWarning('W003', $tenderId, $data);
                }
                break;

            case self::STAGE_PI_INPUT:
                if (empty($data['pi_complete']) || empty($data['currency_split'])) {
                    $messages[] = $this->sendWarning('W004', $tenderId, $data);
                }
                break;

            case self::STAGE_FORMAL_EVALUATION:
                if (!empty($data['article5_violation'])) {
                    $messages[] = $this->sendError('E002', $tenderId, $data);
                }
                break;

            case self::STAGE_DECISION_PATH:
                $n = $data['bid_count'] ?? 0;
                $mean = $data['mean'] ?? 0;
                $po = $data['po'] ?? 0;

                if ($n <= 2) {
                    $messages[] = $this->sendWarning('W005', $tenderId, ['n' => $n]);
                } elseif ($n >= 4) {
                    $lowerBound = 0.8 * $po;
                    $upperBound = 1.35 * $po;
                    if ($mean < $lowerBound || $mean > $upperBound) {
                        $messages[] = $this->sendWarning('W006', $tenderId, [
                            'mean' => $mean,
                            'lower_bound' => $lowerBound,
                            'upper_bound' => $upperBound,
                        ]);
                    } else {
                        $messages[] = $this->sendInfo('I001', $tenderId, ['mean' => $mean]);
                    }
                }
                break;

            case self::STAGE_STATISTICAL_ANALYSIS:
                if (empty($data['mo']) || empty($data['so']) || $data['mo'] <= 0 || $data['so'] <= 0) {
                    $messages[] = $this->sendWarning('W007', $tenderId, $data);
                }
                if (empty($data['pi_complete']) || empty($data['po'])) {
                    $messages[] = $this->sendError('E003', $tenderId, $data);
                }
                break;

            case self::STAGE_NORMALIZATION:
                if (!empty($data['pi_out_of_range'])) {
                    $messages[] = $this->sendWarning('W008', $tenderId, $data);
                }
                break;

            case self::STAGE_WINNER_SELECTION:
                if (!empty($data['winner_selected'])) {
                    $messages[] = $this->sendInfo('I002', $tenderId, [
                        'winner_name' => $data['winner_name'] ?? '',
                    ]);
                }
                break;

            case self::STAGE_TECHNICAL_COMMERCIAL:
                if (!empty($data['is_two_stage']) && empty($data['technical_score'])) {
                    $messages[] = $this->sendWarning('W009', $tenderId, $data);
                }
                // E006: عدم تطابق مسیر دو مرحله‌ای با امتیاز فنی
                if (!empty($data['is_two_stage']) && empty($data['technical_stage_complete'])) {
                    $messages[] = $this->sendError('E006', $tenderId, $data);
                }
                break;
        }

        return $messages;
    }

    /**
     * بررسی و ارسال پیام‌های ترکیبی
     */
    public function checkCombinedErrors(string $tenderId, array $data): array
    {
        $messages = [];

        // E004: Pi و f ناقص همزمان
        $piIncomplete = empty($data['pi_complete']);
        $indicesIncomplete = !empty($data['indices_incomplete']);
        if ($piIncomplete && $indicesIncomplete) {
            $messages[] = $this->sendError('E004', $tenderId, $data);
        }

        // W010: مسیر وقفه و مسیر آماری همزمان
        $suspendedPath = !empty($data['suspended_path']);
        $statisticalPath = !empty($data['statistical_path']);
        if ($suspendedPath && $statisticalPath) {
            $messages[] = $this->sendWarning('W010', $tenderId, $data);
        }

        // E007: ورودی‌های ناقص ترکیبی در مسیر تحلیل آماری
        $piIncomplete = empty($data['pi_complete']);
        $poInvalid = empty($data['po']) || $data['po'] <= 0;
        $moSoInvalid = empty($data['mo']) || empty($data['so']) || $data['mo'] <= 0 || $data['so'] <= 0;
        $nLessThan4 = ($data['n'] ?? 0) < 4;
        $meanUnclear = empty($data['mean']);
        
        if (($piIncomplete || $poInvalid || $moSoInvalid || ($nLessThan4 && $meanUnclear))) {
            $messages[] = $this->sendError('E007', $tenderId, [
                'pi_complete' => !$piIncomplete,
                'po_valid' => !$poInvalid,
                'mo_so_valid' => !$moSoInvalid,
                'n' => $data['n'] ?? 0,
                'mean_clear' => !$meanUnclear,
            ]);
        }

        return $messages;
    }

    /**
     * بررسی ناسازگاری شاخص‌های تعدیل تاریخی
     */
    public function checkIndexInconsistency(string $tenderId, string $poMethod = '2'): ?array
    {
        if ($poMethod !== '2' && $poMethod !== '3') {
            return null;
        }

        $indices = \App\Models\Index::getByTenderId($tenderId);
        $indicesMap = [];
        foreach ($indices as $index) {
            $indicesMap[$index['type']] = $index;
        }

        // بررسی f1 تا f9
        $fIndices = [];
        for ($i = 1; $i <= 9; $i++) {
            $fKey = "f{$i}";
            if (isset($indicesMap[$fKey])) {
                $fIndices[$i] = $indicesMap[$fKey];
            }
        }

        if (count($fIndices) < 2) {
            return null; // تعداد کافی نیست برای بررسی ناسازگاری
        }

        // بررسی ترتیب زمانی و ناسازگاری
        $dates = [];
        $values = [];
        foreach ($fIndices as $i => $index) {
            if (!empty($index['date'])) {
                $dates[$i] = $index['date'];
            }
            $values[$i] = $index['value'] ?? 0;
        }

        // بررسی ناسازگاری در ترتیب زمانی
        $inconsistent = false;
        if (count($dates) >= 2) {
            $sortedDates = $dates;
            sort($sortedDates);
            if ($dates !== $sortedDates) {
                $inconsistent = true;
            }
        }

        // بررسی ناسازگاری در مقادیر (تغییرات غیرمنطقی)
        if (count($values) >= 2) {
            $changes = [];
            $prevValue = null;
            foreach ($values as $i => $value) {
                if ($prevValue !== null) {
                    $change = abs($value - $prevValue) / $prevValue;
                    if ($change > 0.5) { // تغییر بیش از 50%
                        $inconsistent = true;
                        break;
                    }
                }
                $prevValue = $value;
            }
        }

        if ($inconsistent) {
            return $this->sendWarning('W011', $tenderId, [
                'indices_count' => count($fIndices),
                'dates' => $dates,
            ]);
        }

        return null;
    }

    /**
     * بررسی محدوده ناهماهنگ شاخص‌ها نسبت به Pb
     */
    public function checkUnreasonablePoRange(string $tenderId, float $pb, float $po): ?array
    {
        if ($pb <= 0 || $po <= 0) {
            return null;
        }

        // بررسی اینکه آیا Po خارج از بازه منطقی است (مثلاً کمتر از 50% یا بیشتر از 200% Pb)
        $ratio = $po / $pb;
        $unreasonable = $ratio < 0.5 || $ratio > 2.0;

        if ($unreasonable) {
            return $this->sendWarning('W012', $tenderId, [
                'pb' => $pb,
                'po' => $po,
                'ratio' => $ratio,
            ]);
        }

        return null;
    }

    /**
     * بررسی خطا در اعمال روش پیش‌بینی
     */
    public function checkForecastMethodError(string $tenderId, string $method, array $calculationData = []): ?array
    {
        if ($method !== '2' && $method !== '3') {
            return null;
        }

        $error = false;
        $errorDetails = [];

        if ($method === '2') {
            // بررسی F'2
            if (empty($calculationData['f_prime_2']) || is_nan($calculationData['f_prime_2'])) {
                $error = true;
                $errorDetails[] = 'F\'2';
            }
        } elseif ($method === '3') {
            // بررسی F'3
            if (empty($calculationData['f_prime_3']) || is_nan($calculationData['f_prime_3'])) {
                $error = true;
                $errorDetails[] = 'F\'3';
            }
        }

        // بررسی میانگین تغییرات
        if (isset($calculationData['mean_changes']) && (empty($calculationData['mean_changes']) || is_nan($calculationData['mean_changes']))) {
            $error = true;
            $errorDetails[] = 'میانگین تغییرات';
        }

        if ($error) {
            return $this->sendError('E005', $tenderId, [
                'method' => $method,
                'error_details' => $errorDetails,
            ]);
        }

        return null;
    }

    /**
     * بررسی مقادیر نرمال‌شده خارج از حد منطقی
     */
    public function checkExtremeNormalizedValues(string $tenderId, array $normalizedValues): ?array
    {
        if (empty($normalizedValues)) {
            return null;
        }

        $extremeThreshold = 5.0; // ±5 یا بیشتر
        $extremeValues = [];

        foreach ($normalizedValues as $value) {
            if (abs($value) > $extremeThreshold) {
                $extremeValues[] = $value;
            }
        }

        if (!empty($extremeValues)) {
            return $this->sendWarning('W013', $tenderId, [
                'extreme_values' => $extremeValues,
                'threshold' => $extremeThreshold,
                'count' => count($extremeValues),
            ]);
        }

        return null;
    }

    /**
     * دریافت تمام پیام‌های یک مناقصه
     */
    public function getTenderMessages(string $tenderId, ?string $type = null): array
    {
        $query = Notification::where('tender_id', $tenderId);
        
        if ($type) {
            $query->where('type', $type);
        }

        return $query->orderBy('created_at', 'desc')->get()->toArray();
    }

    /**
     * دریافت پیام بر اساس شناسه
     */
    public function getMessage(string $messageId): ?array
    {
        return self::MESSAGES[$messageId] ?? null;
    }

    /**
     * دریافت تمام پیام‌های تعریف شده
     */
    public function getAllMessages(): array
    {
        return self::MESSAGES;
    }

    /**
     * قالب‌بندی پیام با جایگزینی متغیرها
     */
    private function formatMessage(string $message, array $context): string
    {
        $formatted = $message;
        
        // جایگزینی {stage_name}
        if (isset($context['stage_name'])) {
            $formatted = str_replace('{stage_name}', $context['stage_name'], $formatted);
        }

        // جایگزینی سایر متغیرها
        foreach ($context as $key => $value) {
            if (is_scalar($value)) {
                $formatted = str_replace("{{$key}}", (string)$value, $formatted);
            }
        }

        return $formatted;
    }

    /**
     * ثبت در لاگ
     */
    private function logMessage(string $messageId, array $messageConfig, string $tenderId, array $context): void
    {
        $logData = [
            'message_id' => $messageId,
            'type' => $messageConfig['type'],
            'stage' => $messageConfig['stage'],
            'tender_id' => $tenderId,
            'condition' => $messageConfig['condition'],
            'context' => $context,
        ];

        $logLevel = match ($messageConfig['type']) {
            self::TYPE_ERROR => 'error',
            self::TYPE_WARNING => 'warning',
            self::TYPE_INFO => 'info',
            default => 'info',
        };

        Log::{$logLevel}("Alert/Notification: {$messageId}", $logData);
    }

    /**
     * ذخیره در دیتابیس
     */
    private function saveNotification(string $messageId, array $messageConfig, string $tenderId, string $message, array $context): ?Notification
    {
        try {
            return Notification::create([
                'tender_id' => $tenderId,
                'type' => strtolower($messageConfig['type']),
                'source' => Notification::SOURCE_SYSTEM,
                'message_id' => $messageId,
                'title' => $messageConfig['condition'],
                'message' => $message,
                'stage' => $messageConfig['stage'],
                'context' => $context,
                'details' => [
                    'description' => $messageConfig['description'],
                    'condition' => $messageConfig['condition'],
                ],
                'read' => false,
            ]);
        } catch (\Exception $e) {
            // اگر جدول notifications وجود نداشته باشد، فقط در لاگ ثبت می‌شود
            Log::warning("Could not save notification to database: " . $e->getMessage());
            return null;
        }
    }

    /**
     * ارسال به داشبورد
     */
    private function sendToDashboard(string $messageId, array $messageConfig, string $tenderId, string $message): void
    {
        // در اینجا می‌توانید WebSocket یا Event برای ارسال به داشبورد استفاده کنید
        // فعلاً فقط در لاگ ثبت می‌شود
        Log::info("Dashboard notification", [
            'message_id' => $messageId,
            'tender_id' => $tenderId,
            'message' => $message,
        ]);
    }

    /**
     * توقف فرآیند
     */
    private function stopProcess(string $tenderId, string $messageId): void
    {
        $tender = Tender::find($tenderId);
        if ($tender) {
            // تغییر وضعیت به SUSPENDED یا WAITING
            $tender->update(['status' => 'SUSPENDED']);
            Log::warning("Process stopped for tender {$tenderId} due to error: {$messageId}");
        }
    }

    /**
     * بررسی شاخص‌های ناقص
     */
    private function checkMissingIndices(string $tenderId, string $poMethod): array
    {
        $indices = \App\Models\Index::getByTenderId($tenderId);
        $indicesMap = [];
        foreach ($indices as $index) {
            $indicesMap[$index['type']] = $index['value'];
        }

        $required = match ($poMethod) {
            '1' => ['I1', 'I2', 'I3', 'r1', 'r2'],
            '2' => ['F1', 'z'],
            '3' => ['F1', 'z'],
            default => [],
        };

        $missing = [];
        foreach ($required as $indexType) {
            if (!isset($indicesMap[$indexType]) || $indicesMap[$indexType] <= 0) {
                $missing[] = $indexType;
            }
        }

        return $missing;
    }
}

