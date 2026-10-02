<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Safe Formula Evaluator
 * ارزیابی امن فرمول‌ها با محدودیت‌های امنیتی
 */
class SafeFormulaEvaluator
{
    // حداکثر طول فرمول
    private const MAX_FORMULA_LENGTH = 1000;
    
    // حداکثر تعداد متغیرها
    private const MAX_VARIABLES = 50;
    
    // حداکثر زمان اجرا (ثانیه)
    private const MAX_EXECUTION_TIME = 5;
    
    // توابع ریاضی مجاز
    private const ALLOWED_FUNCTIONS = [
        'pow', 'sqrt', 'sin', 'cos', 'tan', 'asin', 'acos', 'atan',
        'abs', 'floor', 'ceil', 'round', 'exp', 'log', 'log10', 'max', 'min'
    ];
    
    // کاراکترهای مجاز در فرمول
    private const ALLOWED_CHARS = '/[0-9+\-*\/\(\)\.\sabcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_,]/';
    
    /**
     * Evaluate formula safely with rate limiting and validation
     */
    public static function evaluate(string $formula, array $variables = []): float
    {
        // 1. Validation
        if (!self::validateFormula($formula, $variables)) {
            Log::warning('Formula validation failed', [
                'formula_length' => strlen($formula),
                'variables_count' => count($variables)
            ]);
            return 0.0;
        }
        
        // 2. Rate limiting check (using cache)
        $cacheKey = 'formula_eval_' . md5($formula . serialize($variables));
        $lastEval = Cache::get($cacheKey);
        if ($lastEval && (time() - $lastEval) < 1) {
            // حداقل 1 ثانیه بین ارزیابی‌های یکسان
            Log::warning('Formula evaluation rate limit exceeded', [
                'formula' => substr($formula, 0, 50)
            ]);
            return 0.0;
        }
        Cache::put($cacheKey, time(), 60); // Cache for 60 seconds
        
        // 3. Delegate to FormulaService (which has additional safety checks)
        try {
            $result = FormulaService::evaluateFormula($formula, $variables);
            
            // 4. Validate result
            if (!is_numeric($result) || !is_finite($result)) {
                Log::warning('Formula evaluation returned invalid result', [
                    'formula' => substr($formula, 0, 50),
                    'result' => $result
                ]);
                return 0.0;
            }
            
            return (float)$result;
        } catch (\Exception $e) {
            Log::error('Formula evaluation error', [
                'formula' => substr($formula, 0, 50),
                'error' => $e->getMessage()
            ]);
            return 0.0;
        }
    }
    
    /**
     * Validate formula before evaluation
     */
    private static function validateFormula(string $formula, array $variables): bool
    {
        // 1. Check length
        if (strlen($formula) > self::MAX_FORMULA_LENGTH) {
            return false;
        }
        
        // 2. Check variables count
        if (count($variables) > self::MAX_VARIABLES) {
            return false;
        }
        
        // 3. Check for dangerous patterns
        $dangerousPatterns = [
            '/\beval\s*\(/i',
            '/\bexec\s*\(/i',
            '/\bsystem\s*\(/i',
            '/\bshell_exec\s*\(/i',
            '/\bpassthru\s*\(/i',
            '/\bproc_open\s*\(/i',
            '/\bpopen\s*\(/i',
            '/\binclude\s*\(/i',
            '/\brequire\s*\(/i',
            '/\binclude_once\s*\(/i',
            '/\brequire_once\s*\(/i',
            '/\bfile_get_contents\s*\(/i',
            '/\bfile_put_contents\s*\(/i',
            '/\bfopen\s*\(/i',
            '/\bfwrite\s*\(/i',
            '/\bunlink\s*\(/i',
            '/\brmdir\s*\(/i',
            '/\bmkdir\s*\(/i',
            '/\bchmod\s*\(/i',
            '/\bchown\s*\(/i',
            '/\bcurl_exec\s*\(/i',
            '/\bcurl_init\s*\(/i',
        ];
        
        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $formula)) {
                Log::warning('Dangerous pattern detected in formula', [
                    'pattern' => $pattern,
                    'formula' => substr($formula, 0, 50)
                ]);
                return false;
            }
        }
        
        // 4. Validate variables
        foreach ($variables as $key => $value) {
            // Variable name should be alphanumeric or underscore
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
                return false;
            }
            
            // Variable value should be numeric
            if (!is_numeric($value)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get allowed functions list
     */
    public static function getAllowedFunctions(): array
    {
        return self::ALLOWED_FUNCTIONS;
    }
}

