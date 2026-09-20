/*
   ==========================================================================
   schema.sql — ساختار پایگاه داده‌ی سایت زهره زارع
   
   روش اجرا:
     cPanel → phpMyAdmin → دیتابیس را انتخاب کنید → زبانه‌ی Import
     → همین فایل را انتخاب کنید → Go
   
   این فایل را می‌شود چند بار اجرا کرد؛ جدول‌های موجود دست‌نخورده
   می‌مانند و خدمات دوباره‌نویسی می‌شوند.
   
   تولید خودکار با tools/make-schema.js — دستی ویرایشش نکنید.
   ==========================================================================
*/

SET NAMES utf8mb4;

/*
   ---------------- کاربران ----------------
   تاریخ تولد شمسی ذخیره می‌شود (نه میلادی) چون کاربر همان را
   وارد می‌کند و تبدیل رفت‌وبرگشتی فقط جای خطا می‌سازد.
*/
CREATE TABLE IF NOT EXISTS users (
  id            VARCHAR(32)  NOT NULL,
  phone         CHAR(11)     NOT NULL,
  name          VARCHAR(80)  NOT NULL DEFAULT '',
  birth_y       SMALLINT     NULL,
  birth_m       TINYINT      NULL,
  birth_d       TINYINT      NULL,
  role          ENUM('user','admin') NOT NULL DEFAULT 'user',
  created_at    DATETIME     NOT NULL,
  last_login_at DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_users_phone (phone),
  KEY idx_users_birthday (birth_m, birth_d)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
   ---------------- نشست‌های ورود ----------------
   توکن خام هرگز ذخیره نمی‌شود؛ فقط هش SHA-256 آن. اگر کسی به
   دیتابیس دسترسی پیدا کند نمی‌تواند با آن وارد حساب کسی شود.
*/
CREATE TABLE IF NOT EXISTS sessions (
  token      CHAR(64)     NOT NULL,
  user_id    VARCHAR(32)  NOT NULL,
  created_at DATETIME     NOT NULL,
  expires_at DATETIME     NOT NULL,
  last_seen  DATETIME     NULL,
  ip         VARCHAR(45)  NULL,
  user_agent VARCHAR(200) NULL,
  PRIMARY KEY (token),
  KEY idx_sessions_user (user_id),
  KEY idx_sessions_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
   ---------------- کدهای یک‌بارمصرف ----------------
   code_hash هش کد است، نه خود کد.
*/
CREATE TABLE IF NOT EXISTS otp_codes (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  phone      CHAR(11)     NOT NULL,
  code_hash  CHAR(64)     NOT NULL,
  attempts   TINYINT      NOT NULL DEFAULT 0,
  expires_at DATETIME     NOT NULL,
  used_at    DATETIME     NULL,
  ip         VARCHAR(45)  NULL,
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_otp_phone (phone, created_at),
  KEY idx_otp_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
   ---------------- خدمات ----------------
   ستون‌های JSON فهرست‌های متنی صفحه‌ی خدمت‌اند (پاراگراف‌ها،
   مراقبت‌ها، پرسش‌ها). چون هیچ‌وقت جداگانه جست‌وجو نمی‌شوند،
   جدول جدا برایشان فقط پیچیدگی اضافه می‌کرد.
*/
CREATE TABLE IF NOT EXISTS services (
  id            VARCHAR(40)  NOT NULL,
  slug          VARCHAR(60)  NOT NULL,
  title         VARCHAR(120) NOT NULL,
  short_text    VARCHAR(300) NOT NULL DEFAULT '',
  image         VARCHAR(200) NOT NULL DEFAULT '',
  icon          VARCHAR(40)  NOT NULL DEFAULT '',
  ig_link       VARCHAR(200) NULL,
  duration_min  SMALLINT     NOT NULL DEFAULT 60,
  price_from    INT          NOT NULL DEFAULT 0,
  description   MEDIUMTEXT   NULL,
  includes_json MEDIUMTEXT   NULL,
  aftercare     MEDIUMTEXT   NULL,
  good_for      MEDIUMTEXT   NULL,
  faq           MEDIUMTEXT   NULL,
  sort_order    SMALLINT     NOT NULL DEFAULT 0,
  active        TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uk_services_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_variants (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_id   VARCHAR(40)  NOT NULL,
  variant_key  VARCHAR(40)  NOT NULL,
  name         VARCHAR(120) NOT NULL,
  note         VARCHAR(300) NOT NULL DEFAULT '',
  duration_min SMALLINT     NOT NULL DEFAULT 60,
  price        INT          NOT NULL DEFAULT 0,
  sort_order   SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uk_variant (service_id, variant_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
   ---------------- نوبت‌ها ----------------
   چرخه‌ی وضعیت (بدون درگاه پرداخت):
   
     مشتری ساعت را می‌گیرد
            ↓
     pending ──(مدیر تماس می‌گیرد و تأیید می‌کند)──→ confirmed ──→ done
        │                                               │
        │                                               └──→ no_show
        ├──(مدیر رد می‌کند)──→ rejected
        └──(مشتری منصرف می‌شود)──→ cancelled
   
   بیعانه یک رکورد ساده روی همین ردیف است، نه تراکنش بانکی. مدیر
   حین تماس مبلغ را می‌گیرد و ثبتش می‌کند. هر وقت درگاه پرداخت
   اضافه شد، فقط یک مقدار جدید به deposit_method اضافه می‌شود.
*/
CREATE TABLE IF NOT EXISTS appointments (
  id              VARCHAR(32)  NOT NULL,
  user_id         VARCHAR(32)  NOT NULL,
  service_id      VARCHAR(40)  NOT NULL,
  variant_key     VARCHAR(40)  NOT NULL,
  variant_name    VARCHAR(120) NOT NULL,
  `date`          DATE         NOT NULL,
  `time`          CHAR(5)      NOT NULL,
  duration_min    SMALLINT     NOT NULL DEFAULT 60,
  price           INT          NOT NULL DEFAULT 0,
  status          ENUM('pending','confirmed','rejected','cancelled','done','no_show')
                  NOT NULL DEFAULT 'pending',
  note            VARCHAR(400) NOT NULL DEFAULT '',
  deposit_amount  INT          NULL,
  deposit_method  VARCHAR(20)  NULL,
  deposit_ref     VARCHAR(40)  NULL,
  deposit_note    VARCHAR(200) NULL,
  deposit_paid_at DATETIME     NULL,
  reject_reason   VARCHAR(200) NULL,
  decided_at      DATETIME     NULL,
  decided_by      VARCHAR(20)  NULL,
  cancelled_at    DATETIME     NULL,
  cancelled_by    VARCHAR(20)  NULL,
  created_at      DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_appt_user (user_id),
  KEY idx_appt_day (`date`, `time`),
  KEY idx_appt_status (status, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ---------------- بستن روز و ساعت توسط مدیر ---------------- */
CREATE TABLE IF NOT EXISTS closed_days (
  `date`     DATE         NOT NULL,
  reason     VARCHAR(120) NOT NULL DEFAULT '',
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blocked_slots (
  `date`     DATE         NOT NULL,
  `time`     CHAR(5)      NOT NULL,
  reason     VARCHAR(120) NOT NULL DEFAULT '',
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (`date`, `time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
   ---------------- گزارش پیامک ----------------
   متن کد تأیید عمداً ذخیره نمی‌شود.
*/
CREATE TABLE IF NOT EXISTS sms_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  phone       CHAR(11)     NOT NULL,
  tag         VARCHAR(20)  NOT NULL DEFAULT '',
  body        VARCHAR(400) NULL,
  result_code INT          NOT NULL DEFAULT 0,
  message     VARCHAR(200) NULL,
  batch_id    BIGINT       NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sms_phone (phone, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
   ==========================================================================
   کاتالوگ خدمات
   برگرفته از assets/js/data/services.js تا نسخه‌ی سرور و نسخه‌ی
   استاتیک سایت هرگز از هم جدا نیفتند.
   ==========================================================================
*/

/* بن‌مژه (اکستنشن مژه) */
INSERT INTO services
  (id, slug, title, short_text, image, icon, ig_link, duration_min,
   price_from, description, includes_json, aftercare, good_for, faq,
   sort_order, active)
VALUES (
  'svc_lash_ext', 'lash-extensions', 'بن‌مژه (اکستنشن مژه)',
  'کاشت تار‌به‌تار مژه برای نگاهی پرحجم و طبیعی، متناسب با فرم چشم شما.',
  'assets/img/service-lash-extensions.jpg', 'lash', NULL,
  120, 1200000,
  '["در بن‌مژه، هر تار مصنوعی به‌صورت جداگانه و با چسب تخصصی روی تار طبیعی خودتان نشانده می‌شود. نتیجه، مژه‌هایی بلندتر و پرپشت‌تر است بدون آنکه نیاز به ریمل یا مژه‌ی مصنوعی داشته باشید.","قبل از شروع کار، فرم چشم، تراکم و سلامت مژه‌های طبیعی شما بررسی می‌شود و بر همان اساس طول، ضخامت و میزان فر تارها انتخاب می‌شود. هدف ما همیشه این است که نتیجه با چهره‌ی شما هماهنگ باشد، نه اینکه صرفاً پرحجم دیده شود.","تمام ابزارها قبل از هر جلسه استریل می‌شوند و از چسب‌های کم‌حساسیت با گواهی معتبر استفاده می‌کنیم."]',
  '["مشاوره‌ی رایگان و انتخاب فرم متناسب با چشم","پاک‌سازی و آماده‌سازی مژه‌ها","کاشت تار به تار با ابزار استریل","آموزش مراقبت و یک عدد برس مخصوص هدیه"]',
  '["تا ۲۴ ساعت اول از تماس مژه‌ها با آب و بخار خودداری کنید.","از آرایش‌پاک‌کن روغنی و ریمل ضدآب استفاده نکنید.","روزی یک بار مژه‌ها را با برس مخصوص شانه بزنید.","برای حفظ تراکم، هر ۲ تا ۳ هفته یک‌بار ترمیم انجام دهید."]',
  '["مژه‌های کم‌پشت یا کوتاه","کسانی که آرایش روزمره‌ی سبک می‌خواهند","مراسم و سفر"]',
  '[{"q":"به مژه‌های طبیعی آسیب می‌زند؟","a":"اگر کار به‌درستی و با وزن مناسب انجام شود، خیر. ما همیشه ضخامت تار را متناسب با توان مژه‌ی طبیعی شما انتخاب می‌کنیم."},{"q":"چند وقت دوام دارد؟","a":"به‌طور میانگین ۳ تا ۴ هفته؛ بسته به چرخه‌ی ریزش طبیعی مژه و نحوه‌ی مراقبت شما."},{"q":"می‌توانم آرایش کنم؟","a":"بله، اما از ریمل و محصولات روغنی روی خط مژه پرهیز کنید."}]',
  0, 1
)
ON DUPLICATE KEY UPDATE
  slug = VALUES(slug), title = VALUES(title), short_text = VALUES(short_text),
  image = VALUES(image), icon = VALUES(icon), duration_min = VALUES(duration_min),
  price_from = VALUES(price_from), description = VALUES(description),
  includes_json = VALUES(includes_json), aftercare = VALUES(aftercare),
  good_for = VALUES(good_for), faq = VALUES(faq), sort_order = VALUES(sort_order);

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_lash_ext', 'classic', 'کلاسیک (تار به تار)', 'طبیعی‌ترین حالت؛ مناسب استفاده‌ی روزمره', 105, 1200000, 0)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);
INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_lash_ext', 'hybrid', 'هیبرید', 'ترکیب کلاسیک و ولوم؛ حجم متعادل', 120, 1600000, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);
INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_lash_ext', 'volume', 'ولوم روسی', 'پرحجم و دراماتیک؛ مناسب مراسم', 150, 2100000, 2)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);
INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_lash_ext', 'refill', 'ترمیم (تا ۳ هفته)', 'برای حفظ تراکم بین جلسات', 75, 750000, 3)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);

/* خط چشم دائم */
INSERT INTO services
  (id, slug, title, short_text, image, icon, ig_link, duration_min,
   price_from, description, includes_json, aftercare, good_for, faq,
   sort_order, active)
VALUES (
  'svc_permanent_liner', 'permanent-liner', 'خط چشم دائم',
  'خط چشم ماندگار با طراحی دقیق و رنگ‌های طبیعی، متناسب با فرم چشم شما.',
  'assets/img/service-permanent-liner.jpg', 'liner', NULL,
  150, 1400000,
  '["در خط چشم دائم، رنگ‌دانه‌ی تخصصی در لایه‌ی سطحی پوست پلک قرار می‌گیرد تا خطی ماندگار و همیشه مرتب داشته باشید؛ بدون نیاز به خط چشم روزانه و نگرانی از پاک شدن آن.","کار با طراحی روی پوست شروع می‌شود: قبل از هر کار دائمی، فرم پیشنهادی را روی پلک شما رسم می‌کنیم و تا زمانی که کاملاً راضی نشوید، مرحله‌ی بعد را شروع نمی‌کنیم.","قبل از شروع از کرم بی‌حسی موضعی استفاده می‌شود و در بیشتر موارد حس شما چیزی بین خارش خفیف و فشار ملایم است. رنگ‌های مورد استفاده استاندارد و قابل جذب هستند."]',
  '["جلسه‌ی مشاوره و طراحی فرم روی صورت","تست حساسیت رنگ پیش از شروع","بی‌حسی موضعی","یک جلسه‌ی ترمیم رایگان بعد از ۴ تا ۶ هفته"]',
  '["تا ۷ روز محل کار را خشک نگه دارید و نشویید.","از دست زدن یا کندن پوسته‌ها جداً خودداری کنید.","تا ۲ هفته از استخر، سونا و آفتاب مستقیم دوری کنید.","پماد تجویزشده را طبق دستور استفاده کنید."]',
  '["کسانی که وقت آرایش روزانه ندارند","حساسیت به لوازم آرایش","دوست‌داران خط چشم همیشه مرتب و یکدست"]',
  '[{"q":"چقدر ماندگار است؟","a":"بسته به نوع پوست و متابولیسم، معمولاً بین یک تا سه سال. ترمیم دوره‌ای رنگ را تازه نگه می‌دارد."},{"q":"دردناک است؟","a":"با بی‌حسی موضعی، بیشتر مراجعان آن را قابل تحمل توصیف می‌کنند."},{"q":"چه زمانی رنگ نهایی مشخص می‌شود؟","a":"حدود ۴ هفته بعد؛ در روزهای اول رنگ تیره‌تر به نظر می‌رسد که کاملاً طبیعی است."}]',
  1, 1
)
ON DUPLICATE KEY UPDATE
  slug = VALUES(slug), title = VALUES(title), short_text = VALUES(short_text),
  image = VALUES(image), icon = VALUES(icon), duration_min = VALUES(duration_min),
  price_from = VALUES(price_from), description = VALUES(description),
  includes_json = VALUES(includes_json), aftercare = VALUES(aftercare),
  good_for = VALUES(good_for), faq = VALUES(faq), sort_order = VALUES(sort_order);

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_permanent_liner', 'liner_fine', 'خط چشم نازک (لش‌لاین)', 'بین تارهای مژه؛ نامحسوس و طبیعی', 120, 2500000, 0)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);
INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_permanent_liner', 'liner_classic', 'خط چشم کلاسیک', 'خط مشخص با ضخامت دلخواه', 150, 3200000, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);
INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_permanent_liner', 'liner_touchup', 'ترمیم خط چشم', 'یک تا دو سال بعد از جلسه‌ی اصلی', 90, 1400000, 2)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);

/* میکروبلیدینگ ابرو */
INSERT INTO services
  (id, slug, title, short_text, image, icon, ig_link, duration_min,
   price_from, description, includes_json, aftercare, good_for, faq,
   sort_order, active)
VALUES (
  'svc_microblading', 'microblading', 'میکروبلیدینگ ابرو',
  'ابروی پرپشت و طبیعی با تکنیک تار به تار؛ طراحی متناسب با فرم صورت شما.',
  'assets/img/service-microblading.jpg', 'brow', NULL,
  165, 1400000,
  '["در میکروبلیدینگ، با تیغه‌های بسیار ظریف، خطوطی شبیه تار موی طبیعی روی پوست ابرو ایجاد می‌شود. نتیجه، ابرویی پرپشت و متقارن است که از فاصله‌ی معمولی از موی طبیعی قابل تشخیص نیست.","طراحی اولین قدم است: فرم ابرو را بر اساس استخوان‌بندی صورت و سلیقه‌ی شما رسم می‌کنیم و فقط وقتی طرح را تأیید کردید، کار رنگ‌گذاری شروع می‌شود.","قبل از شروع از کرم بی‌حسی موضعی استفاده می‌شود و در بیشتر موارد حس شما چیزی بین خارش خفیف و فشار ملایم است. رنگ‌های مورد استفاده استاندارد و قابل جذب هستند."]',
  '["جلسه‌ی مشاوره و طراحی فرم ابرو","تست حساسیت رنگ پیش از شروع","بی‌حسی موضعی","یک جلسه‌ی ترمیم رایگان بعد از ۴ تا ۶ هفته"]',
  '["تا ۷ روز محل کار را خشک نگه دارید و نشویید.","از دست زدن یا کندن پوسته‌ها جداً خودداری کنید.","تا ۲ هفته از استخر، سونا و آفتاب مستقیم دوری کنید.","پماد تجویزشده را طبق دستور استفاده کنید."]',
  '["ابروی کم‌پشت یا نامتقارن","جای خالی یا ریزش موی ابرو","کسانی که وقت آرایش روزانه ندارند"]',
  '[{"q":"چقدر ماندگار است؟","a":"بسته به نوع پوست و متابولیسم، معمولاً بین یک تا سه سال. ترمیم دوره‌ای رنگ را تازه نگه می‌دارد."},{"q":"دردناک است؟","a":"با بی‌حسی موضعی، بیشتر مراجعان آن را قابل تحمل توصیف می‌کنند."},{"q":"چه زمانی رنگ نهایی مشخص می‌شود؟","a":"حدود ۴ هفته بعد؛ در روزهای اول رنگ تیره‌تر به نظر می‌رسد که کاملاً طبیعی است."}]',
  2, 1
)
ON DUPLICATE KEY UPDATE
  slug = VALUES(slug), title = VALUES(title), short_text = VALUES(short_text),
  image = VALUES(image), icon = VALUES(icon), duration_min = VALUES(duration_min),
  price_from = VALUES(price_from), description = VALUES(description),
  includes_json = VALUES(includes_json), aftercare = VALUES(aftercare),
  good_for = VALUES(good_for), faq = VALUES(faq), sort_order = VALUES(sort_order);

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_microblading', 'micro_blade', 'میکروبلیدینگ تار به تار', 'تراش‌های ظریف شبیه موی طبیعی ابرو', 165, 3800000, 0)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);
INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_microblading', 'micro_touchup', 'ترمیم ابرو', 'یک تا دو سال بعد از جلسه‌ی اصلی', 90, 1400000, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);

/* تینت لب و شیدینگ لب */
INSERT INTO services
  (id, slug, title, short_text, image, icon, ig_link, duration_min,
   price_from, description, includes_json, aftercare, good_for, faq,
   sort_order, active)
VALUES (
  'svc_lip_blush', 'lip-blush', 'تینت لب و شیدینگ لب',
  'رنگ‌گذاری ملایم و محو روی لب؛ رنگی طبیعی و همیشگی، بدون خط دور تیز.',
  'assets/img/service-lip-blush.jpg', 'lip', NULL,
  150, 3200000,
  '["در تینت لب یا لیپ‌بلاش، رنگ‌دانه‌ی تخصصی به‌صورت محو و لایه‌لایه در سطح لب قرار می‌گیرد. برخلاف تتوهای قدیمی، اینجا خبری از خط دور تیره و تیز نیست؛ نتیجه بیشتر شبیه یک رژ لب کم‌رنگ و ماندگار است که انگار رنگ خود لب است.","کار با انتخاب رنگ شروع می‌شود. رنگ پایه‌ی لب شما، رنگ پوست و حتی میزان گرمی یا سردی زیرلحن پوستتان را در نظر می‌گیریم تا رنگ نهایی طبیعی از آب دربیاید. اگر لبتان تیره یا کبود است، ابتدا با تکنیک نئوترالایز آن را متعادل می‌کنیم و بعد سراغ رنگ اصلی می‌رویم.","قبل از شروع از کرم بی‌حسی موضعی استفاده می‌شود. لب حساس‌تر از پلک و ابروست، اما با بی‌حسی مناسب، بیشتر مراجعان آن را قابل تحمل توصیف می‌کنند. حدود ۵ تا ۷ روز طول می‌کشد تا پوسته‌ریزی تمام شود و رنگ واقعی خودش را نشان دهد."]',
  '["مشاوره و انتخاب رنگ متناسب با پوست و لب شما","تست حساسیت رنگ پیش از شروع","طراحی و اصلاح فرم لب روی صورت","بی‌حسی موضعی در چند مرحله","یک جلسه‌ی ترمیم رایگان بعد از ۶ تا ۸ هفته"]',
  '["تا ۵ روز لب را با پماد تجویزشده مرطوب نگه دارید و نگذارید خشک شود.","پوسته‌ها را به‌هیچ‌وجه نکنید؛ خودشان باید بیفتند.","تا ۱۰ روز از غذای تند، داغ، اسیدی و نوشیدنی با نی خودداری کنید.","تا ۲ هفته از استخر، سونا و آفتاب مستقیم دوری کنید.","اگر سابقه‌ی تبخال دارید، حتماً از قبل به ما بگویید تا داروی پیشگیری تجویز شود."]',
  '["لب‌های کم‌رنگ یا بی‌حالت","لب تیره یا کبود","نامتقارنی فرم لب","کسانی که رژ لب دائمی می‌خواهند"]',
  '[{"q":"خیلی درد دارد؟","a":"لب حساس‌تر از ابرو است، اما با بی‌حسی موضعی که چند مرحله تکرار می‌شود، بیشتر مراجعان آن را قابل تحمل توصیف می‌کنند."},{"q":"رنگ اولش خیلی تند نیست؟","a":"چرا، در چند روز اول رنگ حدود ۴۰ تا ۵۰ درصد تیره‌تر و روشن‌تر از حالت نهایی دیده می‌شود. این کاملاً طبیعی است و بعد از پوسته‌ریزی، رنگ ملایم می‌شود."},{"q":"چقدر ماندگار است؟","a":"معمولاً بین یک تا سه سال. لب به دلیل بازسازی سریع پوستش، زودتر از ابرو کم‌رنگ می‌شود و ترمیم دوره‌ای رنگ را تازه نگه می‌دارد."},{"q":"اگر سابقه‌ی تبخال داشته باشم چه؟","a":"حتماً از قبل بگویید. تینت لب می‌تواند تبخال را فعال کند، به همین دلیل داروی پیشگیری از چند روز قبل تجویز می‌شود."}]',
  3, 1
)
ON DUPLICATE KEY UPDATE
  slug = VALUES(slug), title = VALUES(title), short_text = VALUES(short_text),
  image = VALUES(image), icon = VALUES(icon), duration_min = VALUES(duration_min),
  price_from = VALUES(price_from), description = VALUES(description),
  includes_json = VALUES(includes_json), aftercare = VALUES(aftercare),
  good_for = VALUES(good_for), faq = VALUES(faq), sort_order = VALUES(sort_order);

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_lip_blush', 'lip_blush', 'لیپ‌بلاش (شیدینگ محو)', 'رنگ ملایم و پخش‌شده؛ طبیعی‌ترین حالت', 150, 3200000, 0)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);
INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_lip_blush', 'lip_full', 'تینت لب کامل (فول‌لیپ)', 'پوشش یکدست کل لب با رنگ سیرتر', 180, 3900000, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);
INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_lip_blush', 'lip_contour', 'کانتور و اصلاح فرم لب', 'برای تقارن و مشخص‌کردن فرم لب', 120, 2600000, 2)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);
INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_lip_blush', 'lip_neutral', 'نئوترالایز (اصلاح لب تیره)', 'روشن‌کردن لب‌های تیره یا کبود', 165, 3600000, 3)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);
INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_lip_blush', 'lip_touchup', 'ترمیم دوره‌ای', 'یک تا دو سال بعد از جلسه‌ی اصلی', 90, 1600000, 4)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);

/* لیفت و لمینت مژه و ابرو */
INSERT INTO services
  (id, slug, title, short_text, image, icon, ig_link, duration_min,
   price_from, description, includes_json, aftercare, good_for, faq,
   sort_order, active)
VALUES (
  'svc_lift_lam', 'lift-lamination', 'لیفت و لمینت مژه و ابرو',
  'فرم‌دهی و تقویت تارهای طبیعی؛ بدون کاشت، با نتیجه‌ای شفاف و مرتب.',
  'assets/img/service-lift-lamination.jpg', 'lift', NULL,
  75, 850000,
  '["لیفت مژه با محلول‌های ملایم، تار مژه‌ی طبیعی شما را از ریشه فرم می‌دهد و بالا می‌آورد؛ بدون آنکه چیزی به مژه اضافه شود. نتیجه، چشم‌هایی بازتر و نگاهی سرحال‌تر است.","لمینت ابرو هم تارهای نامنظم ابرو را در جهت دلخواه ثابت می‌کند و باعث می‌شود ابرو پرپشت‌تر و مرتب‌تر دیده شود؛ مخصوصاً برای ابروهایی که تارهای سرکش دارند.","این خدمت سبک‌ترین گزینه‌ی ماست: نه وزنی به مژه اضافه می‌شود و نه نیاز به ترمیم منظم دارد. برای کسانی که اولین بار است سراغ خدمات مژه می‌آیند، نقطه‌ی شروع خوبی است."]',
  '["پاک‌سازی و آماده‌سازی ناحیه","فرم‌دهی با محلول ملایم و بدون آمونیاک","سرم تقویتی کراتین در پایان کار","مشاوره‌ی مراقبت خانگی"]',
  '["تا ۲۴ ساعت اول مژه‌ها را خیس نکنید.","اولین شب از خوابیدن روی صورت پرهیز کنید.","استفاده از سرم تقویتی، ماندگاری را بیشتر می‌کند.","نتیجه معمولاً ۶ تا ۸ هفته باقی می‌ماند."]',
  '["مژه‌های صاف رو به پایین","ابروهای نامنظم","کسانی که کاشت نمی‌خواهند"]',
  '[{"q":"تفاوتش با کاشت چیست؟","a":"در لیفت چیزی به مژه اضافه نمی‌شود؛ فقط تار طبیعی خودتان فرم می‌گیرد. سبک‌تر است اما حجم اضافه نمی‌کند."},{"q":"برای مژه‌ی کوتاه هم جواب می‌دهد؟","a":"بله، اما نتیجه‌ی چشمگیرتر روی مژه‌های با طول متوسط به بالا دیده می‌شود."},{"q":"هر چند وقت باید تکرار شود؟","a":"معمولاً هر ۶ تا ۸ هفته یک‌بار."}]',
  4, 1
)
ON DUPLICATE KEY UPDATE
  slug = VALUES(slug), title = VALUES(title), short_text = VALUES(short_text),
  image = VALUES(image), icon = VALUES(icon), duration_min = VALUES(duration_min),
  price_from = VALUES(price_from), description = VALUES(description),
  includes_json = VALUES(includes_json), aftercare = VALUES(aftercare),
  good_for = VALUES(good_for), faq = VALUES(faq), sort_order = VALUES(sort_order);

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_lift_lam', 'lash_lift', 'لیفت مژه', 'فر و بالا آمدن مژه‌های طبیعی', 60, 850000, 0)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);
INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_lift_lam', 'lash_tint', 'لیفت + رنگ مژه', 'برای مژه‌های روشن یا کم‌رنگ', 75, 1050000, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);
INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_lift_lam', 'brow_lam', 'لمینت ابرو', 'نظم‌دهی و پرپشت دیده شدن ابرو', 60, 900000, 2)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);
INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_lift_lam', 'combo', 'پکیج مژه + ابرو', 'صرفه‌جویی در وقت و هزینه', 105, 1650000, 3)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);

/*
   ==========================================================================
   اختیاری — قفل ضدِ رزرو هم‌زمان
   
   کد PHP قبل از ثبت بررسی می‌کند که ساعت آزاد باشد، ولی اگر دو
   نفر در همان کسری از ثانیه ثبت کنند، هر دو بررسی موفق می‌شود و
   یک ساعت دو بار رزرو می‌شود. ستون زیر همان ساعت را برای
   نوبت‌های فعال یکتا می‌کند، پس دیتابیس دومی را رد می‌کند و PHP
   پیام «این ساعت همین الان گرفته شد» نشان می‌دهد.
   
   به MySQL 5.7+ یا MariaDB 10.2+ نیاز دارد. اگر خطا داد، سایت
   بدون این هم کار می‌کند — فقط این محافظت آخر را ندارد.
   دستور زیر را جداگانه در phpMyAdmin اجرا کنید:
   
     ALTER TABLE appointments
       ADD COLUMN slot_lock VARCHAR(20)
         GENERATED ALWAYS AS (
           CASE WHEN status IN ('pending','confirmed')
                THEN CONCAT(`date`, ' ', `time`) ELSE NULL END
         ) STORED,
       ADD UNIQUE KEY uk_appt_slot (slot_lock);
   ==========================================================================
*/