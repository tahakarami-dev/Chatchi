# ChatChi Design System — v1.0

> سند مرجع طراحی چت‌چی (Phase 0). هر UI در Phaseهای بعدی (ویجت، پنل مدیریت، فرم‌ها) باید از همین توکن‌ها و قواعد پیروی کند.
> تغییر هر توکن فقط از طریق به‌روزرسانی همین سند انجام می‌شود.

---

## 1. هویت و اصول طراحی

**شخصیت برند:** «Friendly Professional» — گرم و در دسترس برای بازدیدکننده، دقیق و سریع برای اپراتور.

| اصل | معنی در عمل |
|---|---|
| **وضوح قبل از زیبایی** | هر صفحه یک کار اصلی دارد؛ عناصر تزئینی بدون کارکرد حذف می‌شوند. |
| **RTL-first** | طراحی از ابتدا راست‌به‌چپ است، نه برعکس‌شده‌ی یک طراحی LTR. |
| **سبک** | بدون فریم‌ورک CSS، بدون icon font، بدون کتابخانه‌ی انیمیشن. |
| **بازخورد برای هر عمل** | هیچ کلیک/ارسالی بدون واکنش بصری (loading، success، error) نمی‌ماند. |
| **دسترس‌پذیر** | کنتراست WCAG 2.1 AA، ناوبری کامل با کیبورد، `prefers-reduced-motion`. |
| **ایزوله** | استایل ویجت نباید از قالب سایت اثر بگیرد و نباید روی قالب اثر بگذارد. |

**مرجع الهام:** Crisp / Intercom برای ویجت، Linear برای پنل مدیریت (تراکم اطلاعات متعادل، کیبورد-محور، بدون شلوغی).

---

## 2. تصمیم‌های کلیدی تأییدشده

| موضوع | تصمیم |
|---|---|
| تم | **فقط Light در MVP.** همه‌ی رنگ‌ها از CSS Custom Properties می‌آیند تا Dark Mode بعداً فقط با override توکن‌ها اضافه شود. |
| پنل مدیریت | **اپلیکیشن SaaS-محور و مستقل از ظاهر پیشخوان وردپرس** (جزئیات در بخش 12). |
| فونت | **Vazirmatn** (OFL) برای فارسی و لاتین — داخل افزونه bundle می‌شود؛ هیچ درخواست به CDN خارجی. |
| آیکون | SVG اینلاین، سبک Outline، stroke 1.5 (خانواده‌ی Lucide — لایسنس ISC). |
| جایگاه ویجت | پیش‌فرض **پایین-چپ**؛ قابل تغییر به پایین-راست در تنظیمات. |
| شورت‌کد | `[chatchi]` به‌عنوان اصلی + `[hamyarchat]` به‌عنوان alias (طبق مشخصات MVP). |
| تقویم و اعداد | `Intl.DateTimeFormat('fa-IR')` → تاریخ شمسی و ارقام فارسی بدون هیچ کتابخانه‌ی اضافه. |

---

## 3. Color System

### 3.1 Brand

| Token | Value | کاربرد |
|---|---|---|
| `--chatchi-primary` | `#5B5BD6` | دکمه‌ی اصلی، حباب پیام بازدیدکننده، launcher، لینک فعال |
| `--chatchi-primary-hover` | `#4A4AC4` | hover/pressed |
| `--chatchi-primary-soft` | `#EEEEFC` | پس‌زمینه‌ی آیتم فعال، badge ملایم، focus fill |
| `--chatchi-primary-contrast` | `#FFFFFF` | متن روی primary |
| `--chatchi-accent` | `#FF7A59` | **فقط** نقطه‌ی «پیام جدید» و نشانگرهای کوچک (کنتراست متن سفید روی آن کافی نیست؛ برای متن استفاده نشود) |

کنتراست‌های بررسی‌شده: سفید روی `#5B5BD6` = **5.37:1** ✅ · `#4A4AC4` روی `#EEEEFC` = **5.96:1** ✅

**رنگ اصلی قابل تنظیم توسط مدیر سایت (Phase 14):** سه مشتق `hover`، `soft` و `contrast` در سمت سرور (PHP) از رنگ انتخابی محاسبه می‌شوند. اگر کنتراست متن سفید روی رنگ انتخابی کمتر از 4.5 باشد، `--chatchi-primary-contrast` خودکار به `#1A1A2E` تغییر می‌کند.

### 3.2 Neutral (Gray scale — ته‌رنگ بنفش خیلی ملایم)

| Token | Value | کاربرد |
|---|---|---|
| `--chatchi-gray-0` | `#FFFFFF` | سطح کارت‌ها، پنجره‌ی ویجت |
| `--chatchi-gray-50` | `#F7F7FA` | پس‌زمینه‌ی اپ ادمین، پس‌زمینه‌ی ناحیه‌ی پیام‌ها |
| `--chatchi-gray-100` | `#EEEEF3` | حباب پیام اپراتور، hover ردیف لیست |
| `--chatchi-gray-200` | `#E2E2EA` | border پیش‌فرض، divider |
| `--chatchi-gray-300` | `#CFCFDA` | border ورودی‌ها |
| `--chatchi-gray-400` | `#A5A5B5` | آیکون غیرفعال، placeholder |
| `--chatchi-gray-500` | `#8A8A9E` | متن tertiary (فقط ≥14px bold یا متای غیرحیاتی) |
| `--chatchi-gray-600` | `#6B6B80` | متن ثانویه (5.2:1 روی سفید ✅) |
| `--chatchi-gray-700` | `#4A4A5E` | متن label |
| `--chatchi-gray-900` | `#1A1A2E` | متن اصلی (17:1 ✅) |

### 3.3 Semantic

هر رنگ وضعیتی سه نسخه دارد: **base** (آیکون/border/نقطه)، **text** (متن روی سفید، ≥4.5:1) و **soft** (پس‌زمینه).

| وضعیت | base | text | soft |
|---|---|---|---|
| Success | `#16A34A` | `#15803D` (5.0:1) | `#ECFDF3` |
| Warning | `#D97706` | `#B45309` (5.0:1) | `#FFF7E6` |
| Error | `#DC2626` | `#B91C1C` | `#FEF2F2` |
| Info | `#5B5BD6` | `#4A4AC4` | `#EEEEFC` |

### 3.4 وضعیت‌های Conversation (پنل مدیریت)

| Status | رنگ | برچسب فارسی |
|---|---|---|
| `new` | accent `#FF7A59` (نقطه) + primary-soft (پس‌زمینه‌ی ردیف) | جدید |
| `active` | success | فعال |
| `closed` | gray-500 | بسته |

### 3.5 Semantic Surface Tokens (لایه‌ی دوم)

کامپوننت‌ها **هرگز** مستقیم از palette استفاده نمی‌کنند؛ فقط از این لایه. این همان لایه‌ای است که Dark Mode در آینده override می‌کند.

```text
--chatchi-bg            → gray-50
--chatchi-surface       → gray-0
--chatchi-surface-muted → gray-100
--chatchi-border        → gray-200
--chatchi-border-strong → gray-300
--chatchi-text          → gray-900
--chatchi-text-muted    → gray-600
--chatchi-text-subtle   → gray-500
--chatchi-focus-ring    → 0 0 0 3px rgba(91, 91, 214, .35)
```

---

## 4. Typography

**فونت:** `Vazirmatn` — وزن‌های 400 / 500 / 700 به‌صورت `woff2` (زیرمجموعه‌ی Arabic + Latin)، `font-display: swap`.

```text
--chatchi-font: "Vazirmatn", Tahoma, "Segoe UI", system-ui, sans-serif;
```

| Token | Size / Line-height | Weight | کاربرد |
|---|---|---|---|
| `--chatchi-text-xs` | 12px / 1.6 | 400 | زمان پیام، متا، badge |
| `--chatchi-text-sm` | 14px / 1.7 | 400–500 | متن پیام، ردیف لیست، label |
| `--chatchi-text-md` | 16px / 1.7 | 400–500 | ورودی‌ها (در موبایل ≥16px تا iOS زوم نکند) |
| `--chatchi-text-lg` | 20px / 1.5 | 700 | عنوان header ویجت، عنوان صفحه در ادمین |
| `--chatchi-text-xl` | 24px / 1.4 | 700 | عنوان‌های اصلی / empty state |

قواعد:
- `letter-spacing` روی متن فارسی **ممنوع** (حروف متصل می‌شکنند).
- ایمیل، شماره موبایل و URL داخل `<bdi dir="ltr">` نمایش داده می‌شوند.
- ارقام نمایشی (زمان، شمارنده‌ها) با `fa-IR` فارسی می‌شوند؛ ارقام ورودی کاربر (موبایل) قبل از ذخیره به لاتین نرمال می‌شوند.

---

## 5. Spacing, Layout, Breakpoints

**Grid پایه: 4px.**

| Token | Value |
|---|---|
| `--chatchi-space-1` | 4px |
| `--chatchi-space-2` | 8px |
| `--chatchi-space-3` | 12px |
| `--chatchi-space-4` | 16px |
| `--chatchi-space-5` | 20px |
| `--chatchi-space-6` | 24px |
| `--chatchi-space-8` | 32px |
| `--chatchi-space-10` | 40px |

| Breakpoint | Value | رفتار |
|---|---|---|
| `mobile` | < 640px | ویجت تمام‌صفحه؛ ادمین تک‌ستونه |
| `tablet` | 640–1023px | ادمین دوستونه (پنل اطلاعات بازدیدکننده به drawer تبدیل می‌شود) |
| `desktop` | ≥ 1024px | ادمین سه‌ستونه |

همه‌ی فاصله‌گذاری‌ها با **logical properties** (`margin-inline-start`، `padding-inline-end`، `inset-inline-start`) نوشته می‌شوند تا RTL/LTR بدون CSS جداگانه کار کند.

---

## 6. Radius, Shadow, Z-Index

| Token | Value | کاربرد |
|---|---|---|
| `--chatchi-radius-sm` | 8px | input، button، badge |
| `--chatchi-radius-md` | 12px | کارت، dropdown، toast |
| `--chatchi-radius-lg` | 18px | حباب پیام، پنجره‌ی ویجت (20px) |
| `--chatchi-radius-full` | 9999px | launcher، آواتار، pill |

| Token | Value | کاربرد |
|---|---|---|
| `--chatchi-shadow-sm` | `0 1px 2px rgba(26,26,46,.06)` | کارت، input focus |
| `--chatchi-shadow-md` | `0 4px 12px rgba(26,26,46,.08), 0 1px 3px rgba(26,26,46,.06)` | dropdown، toast |
| `--chatchi-shadow-lg` | `0 16px 40px rgba(26,26,46,.14), 0 4px 12px rgba(26,26,46,.06)` | پنجره‌ی ویجت، launcher، modal |

| Layer | z-index |
|---|---|
| ویجت (launcher + window) | `2147483000` (بالای تقریباً همه‌ی قالب‌ها، زیر حد مجاز مرورگر) |
| Modal ادمین | 100 · Toast ادمین | 200 |

---

## 7. Motion

| Token | Value |
|---|---|
| `--chatchi-duration-fast` | 150ms (hover، focus، toggle) |
| `--chatchi-duration-base` | 200ms (ظاهر شدن پیام، dropdown) |
| `--chatchi-duration-slow` | 250ms (باز/بسته شدن پنجره‌ی ویجت) |
| `--chatchi-ease-out` | `cubic-bezier(.16, 1, .3, 1)` |
| `--chatchi-ease-in` | `cubic-bezier(.4, 0, 1, 1)` (فقط خروج) |

الگوها:
- **باز شدن ویجت:** `opacity 0→1` + `translateY(12px)→0` + `scale(.98)→1` از گوشه‌ی launcher.
- **پیام جدید:** `opacity` + `translateY(6px)` در 200ms.
- **Typing/Loading:** سه نقطه با pulse ملایم.
- **فقط `transform` و `opacity` انیمیت می‌شوند** (بدون layout thrash).
- در `prefers-reduced-motion: reduce` همه‌ی حرکت‌ها به fade کوتاه 1ms تبدیل می‌شوند.

---

## 8. Iconography

- سبک: Outline، `stroke-width: 1.5`، `stroke-linecap: round`، grid 24px.
- اندازه‌ها: 16px (داخل متن/badge)، 20px (دکمه‌ها)، 24px (launcher و nav).
- رنگ همیشه `currentColor` است.
- آیکون‌های جهت‌دار (ارسال، بازگشت، فلش) در RTL با `transform: scaleX(-1)` قرینه می‌شوند.
- مجموعه‌ی MVP: `message-circle`، `x`، `send`، `check`، `check-check`، `user`، `mail`، `phone`، `search`، `filter`، `inbox`، `users`، `settings`، `log-out`، `arrow-right`، `alert-circle`، `loader`، `image`، `circle-slash`، `more-horizontal`.

---

## 9. Chat Widget (Frontend)

### 9.1 ساختار

```text
┌──────────────────────────────┐
│ Header: آواتار اپراتور + نام │  ← primary، متن سفید، دکمه‌ی بستن
│         + زیرعنوان            │
├──────────────────────────────┤
│                              │
│   Message list (gray-50)     │  ← scroll، گروه‌بندی پیام‌ها
│                              │
├──────────────────────────────┤
│ Composer: [textarea] [ارسال] │
└──────────────────────────────┘
        ( launcher 56px ●)
```

| ویژگی | Desktop | Mobile (<640px) |
|---|---|---|
| ابعاد پنجره | 380 × min(640px, 100vh − 120px) | تمام‌صفحه (`100dvh`) |
| فاصله از لبه | 24px | 0 (launcher: 16px) |
| Launcher | دایره 56px، primary، shadow-lg | همان |

### 9.2 حباب پیام

| نوع | سمت (RTL) | پس‌زمینه | متن | گوشه‌ی دُم |
|---|---|---|---|---|
| بازدیدکننده | راست (start) | primary | primary-contrast | پایین-راست 6px |
| اپراتور | چپ (end) | gray-100 | text | پایین-چپ 6px + آواتار 28px |
| سیستمی (بسته شدن، شروع) | وسط | بدون حباب | text-subtle، xs | — |

- حداکثر عرض حباب: 80%.
- **گروه‌بندی:** پیام‌های متوالی یک فرستنده با فاصله‌ی زمانی کمتر از 2 دقیقه → فاصله‌ی 4px، فقط آخرین پیام گروه دُم و زمان دارد.
- **وضعیت خوانده‌شدن** (فقط زیر پیام‌های بازدیدکننده): `check` = ارسال‌شده، `check-check` = خوانده‌شده.
- **در حال ارسال:** opacity .7 + آیکون loader؛ **خطا:** border error + متن «ارسال نشد — تلاش مجدد».
- لینک‌ها داخل پیام: قابل کلیک، `rel="nofollow noopener"`، underline.

### 9.3 فرم شروع گفتگو

- داخل همان پنجره، جایگزین Message list (نه modal جدا).
- فیلدها: نام، ایمیل، موبایل — هر کدام قابل فعال/غیرفعال (Phase 14).
- Label همیشه قابل مشاهده (placeholder جای label نمی‌نشیند).
- خطای validation زیر فیلد، رنگ error-text، همراه `aria-describedby`.
- دکمه‌ی «شروع گفتگو» تمام‌عرض.

### 9.4 ایزوله‌سازی استایل

ویجت داخل **Shadow DOM** رندر می‌شود تا CSS قالب روی آن اثر نگذارد و بالعکس. `@font-face` در document اصلی (با نام یکتای `ChatChi Vazirmatn`) تعریف می‌شود چون داخل Shadow DOM لود نمی‌شود. (پیاده‌سازی نهایی در Phase 7.)

---

## 10. Component Library (مشترک ویجت و ادمین)

| Component | Variants | ارتفاع |
|---|---|---|
| **Button** | `primary` · `secondary` (border) · `ghost` · `danger` | sm 32px · md 40px |
| **Input / Textarea** | default · focus (border primary + focus-ring) · error · disabled | 40px (موبایل 44px) |
| **Badge** | status (`new`/`active`/`closed`) · count (عدد روی primary) · dot (accent) | 20px |
| **Avatar** | تصویر · حروف اول (پس‌زمینه‌ی primary-soft) | 28 / 36 / 48px |
| **Toggle** | on / off / disabled | 20px |
| **Toast** | success · error · info — پایین-وسط، خودکار بعد از 4s | — |
| **Skeleton** | خط · دایره · کارت | — |
| **Empty State** | آیکون 40px + عنوان + توضیح + (اختیاری) CTA | — |
| **Error State** | آیکون alert + پیام انسانی + دکمه‌ی «تلاش مجدد» | — |

**Touch target** حداقل 40×40px (موبایل 44×44px). **Focus** همیشه قابل مشاهده با `--chatchi-focus-ring` (فقط `:focus-visible`).

---

## 11. حالت‌های سیستمی (State Matrix)

| صفحه/بخش | Loading | Empty | Error |
|---|---|---|---|
| لیست پیام‌های ویجت | 3 skeleton حباب | پیام خوش‌آمد / «سؤالت را بپرس 👋» | «اتصال برقرار نشد» + تلاش مجدد |
| ارسال پیام | حباب با loader | — | حباب با border قرمز + تلاش مجدد |
| لیست گفتگوها (ادمین) | 6 skeleton ردیف | «هنوز گفتگویی نداری» | کارت خطا + تلاش مجدد |
| جستجو (ادمین) | skeleton | «نتیجه‌ای برای «…» پیدا نشد» | toast خطا |
| صفحه‌ی گفتگو (ادمین) | skeleton سه‌ستونه | «یک گفتگو را انتخاب کن» | «این گفتگو پیدا نشد یا دسترسی نداری» |
| اپراتورها | skeleton کارت | «اولین اپراتور را اضافه کن» + CTA | toast خطا |
| Rate limit | — | — | «کمی آهسته‌تر 🙂 چند ثانیه دیگر دوباره امتحان کن» |

---

## 12. پنل مدیریت (SaaS App)

### 12.1 تصمیم معماری

پنل مدیریت یک **اپلیکیشن تمام‌صفحه و مستقل** است که ظاهر پیشخوان وردپرس (منوی کناری، admin bar، notice افزونه‌های دیگر) را **ندارد**.

| گزینه | مزایا | معایب | وضعیت |
|---|---|---|---|
| **A. صفحه‌ی `wp-admin/admin.php?page=chatchi` که یک HTML document کامل و مستقل رندر می‌کند** (الگوی Elementor Editor) | احراز هویت، capability و nonce بومی وردپرس؛ بدون rewrite rule؛ افزونه‌های امنیتی که از wp-admin محافظت می‌کنند همچنان کار می‌کنند؛ CSS/JS سایر افزونه‌ها لود نمی‌شود | URL زیبا نیست | ✅ **انتخاب‌شده** |
| B. مسیر فرانت‌اند اختصاصی (مثلاً `/chatchi-app/`) | URL تمیز | rewrite/flush، تداخل با افزونه‌های کش و امنیت، سطح حمله‌ی بیشتر | ❌ |

یک آیتم «چت‌چی» در منوی وردپرس فقط ورودی به همین اپ است. داخل اپ، دکمه‌ی «بازگشت به وردپرس» وجود دارد.
اپ با **Vanilla JavaScript (ES Modules)** و بدون build step ساخته می‌شود؛ ارتباط فقط از طریق REST API (Phase 6) با cookie + nonce `wp_rest`. مسیریابی داخلی hash-based است (`#/conversations/42`).

### 12.2 Layout (RTL)

```text
┌────┬──────────────────────┬──────────────────────────┬────────────────┐
│ ●  │  گفتگوها       [🔍]  │  هدر گفتگو: نام · وضعیت   │  اطلاعات        │
│    │  [همه|جدید|فعال|بسته]│  [بستن گفتگو]             │  بازدیدکننده    │
│ 💬 │ ┌──────────────────┐ │                          │                │
│ 👥 │ │● علی · ۲ دقیقه   │ │     پیام‌ها                │  نام            │
│ ⚙️ │ │  سلام، سفارشم…   │ │                          │  ایمیل          │
│    │ ├──────────────────┤ │                          │  موبایل         │
│    │ │  مریم · دیروز    │ │                          │  اولین بازدید   │
│ ↩  │ └──────────────────┘ │  [composer…]    [ارسال]  │  صفحه‌ی شروع    │
└────┴──────────────────────┴──────────────────────────┴────────────────┘
 Rail      List (320px)          Conversation (flex)       Info (300px)
 64px
```

- **Rail (راست):** لوگو، گفتگوها، اپراتورها، تنظیمات، و در پایین آواتار کاربر + بازگشت به وردپرس.
- **List:** جستجو + فیلتر status به‌شکل segmented control؛ ردیف‌ها: آواتار، نام، پیش‌نمایش آخرین پیام (یک خط)، زمان نسبی، dot برای پیام خوانده‌نشده.
- **Conversation:** همان سیستم حباب ویجت؛ در ادمین پیام‌های **اپراتور** در سمت start و primary هستند (دید از نگاه کاربر فعلی).
- **Info:** اطلاعات بازدیدکننده، قابل جمع شدن.
- صفحات **اپراتورها** و **تنظیمات** از layout «Rail + محتوای تمام‌عرض با حداکثر 960px» استفاده می‌کنند.

### 12.3 Topbar ادمین

ارتفاع 56px، سطح سفید با border پایین: عنوان صفحه (lg) در start و اکشن‌های صفحه در end.

---

## 13. لحن و Microcopy

- محاوره‌ای-مؤدب با «تو»؛ کوتاه و انسانی. مثال‌ها: «پیامت ارسال شد»، «اتصال برقرار نشد».
- خطا باید بگوید **چه شد + چه کنیم**: «ارسال نشد. اینترنتت را چک کن و دوباره بفرست.»
- کلمات کلیدی ثابت: گفتگو (نه چت/مکالمه)، اپراتور (نه پشتیبان/Agent)، بازدیدکننده (نه کاربر مهمان).
- همه‌ی رشته‌ها از طریق توابع ترجمه‌ی وردپرس (`__()`، `wp.i18n`) با text domain `chatchi` عبور می‌کنند.

---

## 14. Accessibility Checklist

- [ ] کنتراست متن ≥ 4.5:1، متن بزرگ/آیکون ≥ 3:1
- [ ] launcher: `aria-label="باز کردن گفتگو"` + `aria-expanded`
- [ ] پنجره‌ی ویجت: `role="dialog"` + `aria-labelledby`؛ `Esc` پنجره را می‌بندد؛ focus به launcher برمی‌گردد
- [ ] لیست پیام‌ها: `role="log"` + `aria-live="polite"`
- [ ] همه‌ی کنترل‌ها با Tab قابل دسترس و دارای focus-visible
- [ ] `lang="fa"` و `dir="rtl"` روی root ویجت و اپ ادمین
- [ ] هیچ اطلاعاتی فقط با رنگ منتقل نمی‌شود (status = رنگ + متن)

---

## 15. قرارداد نام‌گذاری در کد

| مورد | الگو | مثال |
|---|---|---|
| CSS Custom Property | `--chatchi-{category}-{name}` | `--chatchi-primary-soft` |
| CSS Class (BEM سبک) | `.chatchi-{block}__{element}--{modifier}` | `.chatchi-bubble--visitor` |
| PHP Namespace | `ChatChi\{Module}` | `ChatChi\Core\Installer` |
| Hook / Option / Table | `chatchi_` | `chatchi_loaded`، `chatchi_version` |
| Text Domain | `chatchi` | — |
| JS global (تنها یک مورد) | `window.ChatChi` | — |

---

## 16. Future Improvements (خارج از MVP — پیاده‌سازی نمی‌شود)

- Dark Mode (زیرساخت توکن آماده است)
- انتخاب فونت قالب سایت به‌جای Vazirmatn
- حالت آنلاین/آفلاین اپراتور و «در حال نوشتن…»
- اعلان صوتی و Browser Notification برای اپراتور
- Command palette (Ctrl+K) در پنل مدیریت
