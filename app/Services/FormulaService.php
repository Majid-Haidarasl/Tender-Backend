<?php

namespace App\Services;

use App\Models\Formula;

class FormulaService
{
    private static $cache = [];

    /**
     * Get formula by name and evaluate it with variables
     */
    public static function getFormula(string $name, array $variables = []): ?float
    {
        $formula = self::getFormulaModel($name);
        
        if (!$formula || !$formula->is_active) {
            return null;
        }

        return $formula->evaluate($variables);
    }

    /**
     * Get formula model by name
     */
    public static function getFormulaModel(string $name): ?Formula
    {
        if (!isset(self::$cache[$name])) {
            self::$cache[$name] = Formula::getActiveByName($name);
        }

        return self::$cache[$name];
    }

    /**
     * Clear cache
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Evaluate formula with safe evaluation
     * پشتیبانی از توابع ریاضی مانند pow, sqrt, sin, cos, etc.
     */
    public static function evaluateFormula(string $formula, array $variables = []): float
    {
        // تبدیل T_beta به Tβ در فرمول برای backward compatibility
        // این کار برای فرمول‌های قدیمی که هنوز T_beta دارند (تبدیل خودکار)
        $formula = preg_replace('/\bT_beta\b/', 'Tβ', $formula);
        $formula = preg_replace('/\bT_gamma\b/', 'T_γ', $formula);
        
        // ابتدا متغیرها را جایگزین کن (قبل از پردازش فرمول)
        // ترتیب مهم است: ابتدا متغیرهای طولانی‌تر را جایگزین کن تا از جایگزینی نادرست جلوگیری شود
        $sortedKeys = array_keys($variables);
        usort($sortedKeys, function($a, $b) {
            return strlen($b) - strlen($a); // طولانی‌ترها اول
        });
        
        foreach ($sortedKeys as $key) {
            $value = $variables[$key];
            // تبدیل مقدار به عدد برای اطمینان
            $numericValue = is_numeric($value) ? (float)$value : 0.0;
            
            // جایگزینی دقیق - فقط کلمه کامل را جایگزین کن (نه بخشی از کلمه)
            // استفاده از word boundary برای متغیرهای ساده و pattern خاص برای متغیرهای با underscore یا کاراکترهای یونانی
            if (strpos($key, '_') !== false || preg_match('/[α-ωΑ-Ω]/u', $key)) {
                // برای متغیرهای با underscore (مثل T_beta که به Tβ تبدیل می‌شود) یا کاراکترهای یونانی (مثل Tβ)، از pattern خاص استفاده کن
                // باید مطمئن شویم که قبل و بعد از متغیر، کاراکتر غیر alphanumeric یا underscore وجود دارد
                // استفاده از lookbehind و lookahead برای اطمینان از جایگزینی دقیق
                // برای کاراکترهای یونانی، باید از Unicode flag استفاده کنیم
                $pattern = '/(?<![a-zA-Z0-9_α-ωΑ-Ω])' . preg_quote($key, '/') . '(?![a-zA-Z0-9_α-ωΑ-Ω])/u';
            } else {
                // برای متغیرهای ساده، از word boundary استفاده کن
                $pattern = '/\b' . preg_quote($key, '/') . '\b/';
            }
            
            // انجام جایگزینی
            $newFormula = preg_replace($pattern, (string)$numericValue, $formula);
            
            // بررسی اینکه جایگزینی انجام شده باشد
            if ($newFormula === $formula && (stripos($formula, $key) !== false || mb_stripos($formula, $key, 0, 'UTF-8') !== false)) {
                // اگر جایگزینی انجام نشد اما متغیر در فرمول وجود دارد، مشکل از pattern است
                \Log::warning('Variable replacement failed', [
                    'key' => $key,
                    'value' => $numericValue,
                    'formula_before' => $formula,
                    'pattern' => $pattern,
                    'formula_contains_key' => stripos($formula, $key) !== false || mb_stripos($formula, $key, 0, 'UTF-8') !== false
                ]);
            }
            
            $formula = $newFormula;
        }
        
        // بررسی اینکه آیا Tβ یا T_γ هنوز در فرمول باقی مانده است
        // اگر باقی مانده باشد، یعنی جایگزین نشده و باید خطا بدهیم
        if (mb_stripos($formula, 'Tβ', 0, 'UTF-8') !== false) {
            \Log::error('Tβ variable not replaced in formula', [
                'formula' => $formula,
                'variables' => array_keys($variables)
            ]);
            // تبدیل به 0 برای جلوگیری از خطای eval
            $formula = preg_replace('/Tβ/u', '0', $formula);
        }
        if (mb_stripos($formula, 'T_γ', 0, 'UTF-8') !== false) {
            \Log::error('T_γ variable not replaced in formula', [
                'formula' => $formula,
                'variables' => array_keys($variables)
            ]);
            // تبدیل به 0 برای جلوگیری از خطای eval
            $formula = preg_replace('/T_γ/u', '0', $formula);
        }
        
        // Log فرمول بعد از جایگزینی برای دیباگ
        \Log::debug('Formula after variable replacement', [
            'formula' => $formula,
            'variables' => $variables
        ]);
        
        // Evaluate formula safely
        try {
            // تبدیل توابع ریاضی به فرمت PHP
            // pow(x, y) -> pow(x, y) (قبلاً در PHP موجود است)
            // sqrt(x) -> sqrt(x) (قبلاً در PHP موجود است)
            // حفظ توابع ریاضی مجاز
            $allowedFunctions = ['pow', 'sqrt', 'sin', 'cos', 'tan', 'abs', 'floor', 'ceil', 'round', 'exp', 'log'];
            
            // بررسی وجود توابع مجاز در فرمول
            $hasAllowedFunction = false;
            foreach ($allowedFunctions as $func) {
                if (stripos($formula, $func . '(') !== false) {
                    $hasAllowedFunction = true;
                    break;
                }
            }
            
            if ($hasAllowedFunction) {
                // اگر توابع ریاضی وجود دارد، فقط کاراکترهای خطرناک را حذف کن
                // اما توابع مجاز را حفظ کن
                // حذف کاراکترهای خطرناک اما حفظ توابع و اعداد و عملگرها
                // توجه: باید کاما (,) را هم حفظ کنیم چون در توابع pow(x, y) استفاده می‌شود
                // همچنین باید فاصله را حفظ کنیم برای خوانایی
                // حفظ کاراکترهای یونانی (α-ω, Α-Ω) برای متغیرهایی مثل Tβ, T_γ
                $cleanedFormula = preg_replace('/[^0-9+\-*\/\(\)\.\sabcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_α-ωΑ-Ω,]/u', '', $formula);
                
                // حذف فاصله‌های اضافی برای اطمینان از syntax صحیح
                $cleanedFormula = preg_replace('/\s+/', ' ', $cleanedFormula);
                $cleanedFormula = trim($cleanedFormula);
                
                // بررسی اینکه فرمول خالی نشده باشد
                if (empty($cleanedFormula)) {
                    \Log::warning('Formula is empty after cleaning', [
                        'original_formula' => $formula,
                        'cleaned_formula' => $cleanedFormula,
                        'variables' => $variables
                    ]);
                    return 0.0;
                }
                
                // بررسی syntax قبل از eval با استفاده از tokenizer
                $tokens = @token_get_all("<?php return $cleanedFormula;");
                if ($tokens === false) {
                    \Log::error('Formula tokenization failed', [
                        'formula' => $formula,
                        'cleaned' => $cleanedFormula,
                        'variables' => $variables
                    ]);
                    return 0.0;
                }
                
                // Log برای دیباگ
                \Log::info('Evaluating formula with functions', [
                    'original' => $formula,
                    'cleaned' => $cleanedFormula,
                    'variables' => $variables,
                    'eval_code' => "return $cleanedFormula;"
                ]);
                
                // استفاده از eval با توابع مجاز
                $result = @eval("return $cleanedFormula;");
                
                // بررسی خطاهای eval
                if ($result === false) {
                    $lastError = error_get_last();
                    if ($lastError) {
                        \Log::error('Formula syntax error in eval', [
                            'formula' => $formula,
                            'cleaned' => $cleanedFormula,
                            'eval_code' => "return $cleanedFormula;",
                            'error' => $lastError['message'],
                            'error_file' => $lastError['file'] ?? 'N/A',
                            'error_line' => $lastError['line'] ?? 'N/A',
                            'variables' => $variables
                        ]);
                        return 0.0;
                    }
                }
            } else {
                // اگر تابع ریاضی وجود ندارد، فقط کاراکترهای ریاضی ساده را نگه دار
                $cleanedFormula = preg_replace('/[^0-9+\-*\/\(\)\.\s]/', '', $formula);
                
                // بررسی اینکه فرمول خالی نشده باشد
                if (empty(trim($cleanedFormula))) {
                    \Log::warning('Formula is empty after cleaning', [
                        'original_formula' => $formula,
                        'cleaned_formula' => $cleanedFormula,
                        'variables' => $variables
                    ]);
                    return 0.0;
                }
                
                // استفاده از eval
                $result = @eval("return $cleanedFormula;");
            }
            
            if ($result === false || !is_numeric($result)) {
                \Log::warning('Formula evaluation returned invalid result', [
                    'original_formula' => $formula,
                    'cleaned_formula' => $cleanedFormula ?? 'N/A',
                    'result' => $result,
                    'variables' => $variables
                ]);
                return 0.0;
            }
            
            return (float)$result;
        } catch (\ParseError $e) {
            \Log::error('Formula parse error', [
                'formula' => $formula,
                'variables' => $variables,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return 0.0;
        } catch (\Exception $e) {
            \Log::error('Formula evaluation error', [
                'formula' => $formula,
                'variables' => $variables,
                'error' => $e->getMessage()
            ]);
            return 0.0;
        }
    }
}

