# سامانه ارزیابی مالی مناقصات وزارت نفت

## معرفی

این سامانه برای ارزیابی مالی مناقصات مطابق دستورالعمل وزارت نفت طراحی شده است.

## ویژگی‌های اصلی

### 1. مدیریت مناقصات
- ثبت و مدیریت مناقصات
- ثبت برآورد اولیه (Pb)
- محاسبه برآورد به‌هنگام (Po)
- مدیریت شاخص‌های تعدیل

### 2. ارزیابی مالی
- دریافت پیشنهادات مناقصه‌گران
- فیلتر ماده 5
- تحلیل آماری
- نرمال‌سازی قیمت‌ها
- انتخاب برنده

### 3. گزارش‌دهی
- گزارش مدیریتی
- گزارش هوشمند متنی
- گزارش PDF
- گزارش Word (DOCX)
- گزارش HTML و Markdown

### 4. سیستم هشدار و اعلان
- 23 پیام هشدار/خطا/اطلاع
- اولویت‌بندی پیام‌ها
- مدیریت خطاهای ترکیبی

### 5. Audit Trail
- ثبت کامل تاریخچه
- بازپخش سناریوها
- کنترل دسترسی کاربران

### 6. اعتبارسنجی جامع
- 11 مرحله اعتبارسنجی
- بررسی تطابق با دستورالعمل
- مدیریت داده‌های ناقص

## ساختار پروژه

```
backend/
├── app/
│   ├── Http/Controllers/     # کنترلرهای API
│   ├── Models/               # مدل‌های Eloquent
│   ├── Services/             # سرویس‌های اصلی
│   └── ...
├── database/
│   ├── migrations/           # Migration ها
│   └── seeders/              # Seeder ها
├── tests/                    # تست‌های جامع
└── routes/
    └── api.php               # مسیرهای API

Frontend/
├── src/
│   ├── pages/                # صفحات اصلی
│   ├── components/           # کامپوننت‌ها
│   └── ...
└── ...
```

## API Endpoints

### مناقصات
- `GET /api/tenders` - لیست مناقصات
- `POST /api/tenders` - ایجاد مناقصه
- `GET /api/tenders/{id}` - جزئیات مناقصه
- `PUT /api/tenders/{id}` - به‌روزرسانی مناقصه

### برآوردها
- `GET /api/estimates?tender_id={id}` - لیست برآوردها
- `POST /api/estimates` - ایجاد برآورد

### شاخص‌ها
- `GET /api/indices?tender_id={id}` - لیست شاخص‌ها
- `POST /api/indices` - ایجاد شاخص

### مناقصه‌گران
- `GET /api/bidders?tender_id={id}` - لیست مناقصه‌گران
- `POST /api/bidders` - ثبت پیشنهاد

### ارزیابی
- `POST /api/tenders/{id}/calculate-po` - محاسبه Po
- `POST /api/tenders/{id}/evaluate` - انجام ارزیابی
- `GET /api/tenders/{id}/results` - نتایج ارزیابی

### گزارش‌ها
- `GET /api/reports/management/tender/{id}` - گزارش مدیریتی (JSON)
- `GET /api/reports/management/tender/{id}/html` - گزارش HTML
- `GET /api/reports/management/tender/{id}/pdf` - گزارش PDF
- `GET /api/reports/management/tender/{id}/word` - گزارش Word
- `GET /api/reports/tender/{id}/text` - گزارش متنی

### Audit Trail
- `GET /api/audit-trail/tender/{id}` - تاریخچه کامل
- `GET /api/audit-trail/tender/{id}/replay` - بازپخش سناریو

## مستندات

- [راهنمای نصب](INSTALLATION_GUIDE.md)
- [مستندات گزارش مدیریتی](tests/MANAGEMENT_REPORT_DOCUMENTATION.md)
- [مستندات Audit Trail](tests/AUDIT_TRAIL_DOCUMENTATION.md)
- [مستندات سیستم هشدار](tests/ALERT_SYSTEM_DOCUMENTATION.md)
- [راهنمای تست‌ها](tests/COMPREHENSIVE_CHECKLIST_TEST_DOCUMENTATION.md)

## تست

```bash
# تست جامع چک‌لیست
php tests/ComprehensiveSystemChecklistTest.php

# تست مسیرهای تصمیم
php tests/DecisionPathComprehensiveTest.php

# تست تراز و امتیاز فنی
php tests/TechnicalCommercialScoreTest.php
```

## مجوز

این پروژه برای استفاده در وزارت نفت ایران طراحی شده است.

