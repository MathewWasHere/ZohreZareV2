/*
   ==========================================================================
   migration-new-services.sql — به‌روزرسانی خدمات روی دیتابیس آماده
   ==========================================================================

   این فایل را فقط وقتی اجرا کنید که سایت از قبل نصب شده و دیتابیس
   ساخته شده است (کاربران و نوبت‌های ثبت‌شده دارید).

   چه کاری می‌کند؟
     ۱. سه ستون تازه‌ی محتوای خدمت را (اگر نباشند) اضافه می‌کند.
     ۲. خدمات قبلی را از سایت برمی‌دارد؛ خدمتی که نوبت ثبت‌شده دارد
        پاک نمی‌شود (تاریخچه‌ی نوبت‌ها سالم می‌ماند) بلکه غیرفعال می‌شود.
     ۳. خدمات تازه و گزینه‌هایشان را می‌سازد.

   روش اجرا:
     cPanel → phpMyAdmin → دیتابیس را انتخاب کنید → زبانه‌ی Import
     → همین فایل را انتخاب کنید → Go
   یا: cPanel → File Manager → آدرس این فایل را در مرورگر باز کنید
       (نصب‌کننده‌ی api/install.php فقط schema.sql را می‌خواند).

   اجرای دوباره‌ی این فایل مشکلی ندارد.

   اگر سایت را تازه نصب می‌کنید، به این فایل نیازی نیست؛ فقط
   api/schema.sql را اجرا کنید.

   برگرفته از assets/js/data/services.js — دستی ویرایشش نکنید.
   ==========================================================================
*/

/* مزایا */
SET @zz_col_benefits = IF((SELECT COUNT(*) FROM information_schema.columns
       WHERE table_schema = DATABASE() AND table_name = 'services'
         AND column_name = 'benefits') = 0,
     'ALTER TABLE services ADD COLUMN benefits MEDIUMTEXT NULL AFTER includes_json',
     'SELECT 1');
PREPARE zz_add_benefits FROM @zz_col_benefits;
EXECUTE zz_add_benefits;
DEALLOCATE PREPARE zz_add_benefits;

/* نکات مهم قبل از رزرو نوبت */
SET @zz_col_notes = IF((SELECT COUNT(*) FROM information_schema.columns
       WHERE table_schema = DATABASE() AND table_name = 'services'
         AND column_name = 'notes') = 0,
     'ALTER TABLE services ADD COLUMN notes MEDIUMTEXT NULL AFTER includes_json',
     'SELECT 1');
PREPARE zz_add_notes FROM @zz_col_notes;
EXECUTE zz_add_notes;
DEALLOCATE PREPARE zz_add_notes;

/* مراقبت‌های قبل از انجام */
SET @zz_col_pre_care = IF((SELECT COUNT(*) FROM information_schema.columns
       WHERE table_schema = DATABASE() AND table_name = 'services'
         AND column_name = 'pre_care') = 0,
     'ALTER TABLE services ADD COLUMN pre_care MEDIUMTEXT NULL AFTER includes_json',
     'SELECT 1');
PREPARE zz_add_pre_care FROM @zz_col_pre_care;
EXECUTE zz_add_pre_care;
DEALLOCATE PREPARE zz_add_pre_care;


/* برداشتن خدمات قبلی از سایت؛ خدمتی که نوبت ثبت‌شده دارد پاک نمی‌شود */
DELETE sv FROM service_variants sv
 WHERE sv.service_id IN ('svc_lash_ext', 'svc_permanent_liner', 'svc_microblading', 'svc_lip_blush', 'svc_lift_lam')
   AND NOT EXISTS (SELECT 1 FROM appointments a WHERE a.service_id = sv.service_id);
DELETE s FROM services s
 WHERE s.id IN ('svc_lash_ext', 'svc_permanent_liner', 'svc_microblading', 'svc_lip_blush', 'svc_lift_lam')
   AND NOT EXISTS (SELECT 1 FROM appointments a WHERE a.service_id = s.id);
UPDATE services SET active = 0 WHERE id IN ('svc_lash_ext', 'svc_permanent_liner', 'svc_microblading', 'svc_lip_blush', 'svc_lift_lam');

/* ---------------- خدمات تازه ---------------- */
/* بن مژه */
INSERT INTO services
  (id, slug, title, short_text, image, icon, ig_link, duration_min,
   price_from, description, includes_json, benefits, notes, pre_care,
   aftercare, good_for, faq, sort_order, active)
VALUES (
  'svc_lash_line', 'lash-line', 'بن مژه',
  'بن مژه؛ رازِ نگاه‌های نافذ و بی‌نیاز از خط‌چشم روزانه',
  'assets/img/service-lash-line.jpg', 'lash', NULL,
  150, 1300000,
  '["خسته شدی از خط‌چشم زدن هر روزه که تا ظهر پخش می‌شه؟ بن مژه دقیقاً همون چیزیه که دنبالش بودی!","بن مژه یک تکنیک تخصصی میکروپیگمنتیشنه که دقیقاً بین ریشه‌ی مژه‌ها انجام می‌شه و یه خط‌چشم طبیعی و همیشگی به چشمات می‌ده؛ انگار همیشه چشمات آرایش‌شده و درشت به نظر می‌رسن، حتی صبح که از خواب پا می‌شی!"]',
  '[]',
  '["چشم‌هاتو درشت‌تر، نافذتر و پرجذبه‌تر می‌کنه.","مژه‌هاتو پرپشت‌تر و متراکم‌تر نشون می‌ده، انگار مژه‌ی مصنوعی زدی.","دیگه نیازی به خط‌چشم روزانه نداری؛ حتی زیر دوش و استخر هم خط چشمت سر جاشه.","برای مژه‌های کم‌پشت و کم‌رنگ یه معجزه‌ست.","نتیجه‌ای کاملاً طبیعی، نه مصنوعی و اغراق‌شده."]',
  '["کل پروسه بدون درده، فقط یه حس قلقلک و خارش سبک داری.","تا یکی دو روز ممکنه یه تورم خفیف داشته باشی که کاملاً طبیعیه و خودش برطرف می‌شه.","ماندگاریش بالای ۵ ساله، بدون افت رنگ یا کدر شدن.","در صورت نیاز، یک ترمیم بعد از یک ماه انجام می‌شه تا نتیجه پرفکت بشه."]',
  '[]',
  '["تا ۷ روز محل کار را خشک نگه دارید و نشویید.","از دست زدن یا کندن پوسته‌ها جداً خودداری کنید.","تا ۲ هفته از استخر، سونا و آفتاب مستقیم دوری کنید.","پماد تجویزشده را طبق دستور استفاده کنید."]',
  '["مژه‌های کم‌پشت و کم‌رنگ","کسانی که وقت خط‌چشم روزانه ندارند","خط‌چشمی که تا ظهر پخش می‌شود","نگاهی طبیعی، بدون آرایش"]',
  '[{"q":"بن مژه درد داره؟","a":"کل پروسه بدون درده؛ فقط یه حس قلقلک و خارش سبک داری."},{"q":"چند وقت ماندگاری داره؟","a":"ماندگاری بن مژه بالای ۵ ساله و افت رنگ یا کدر شدن نداره. در صورت نیاز، یک ترمیم بعد از یک ماه انجام می‌شه."},{"q":"بعد از کار چه چیزی طبیعیه؟","a":"تا یکی دو روز ممکنه یه تورم خفیف داشته باشی که کاملاً طبیعیه و خودش برطرف می‌شه."}]',
  0, 1
)
ON DUPLICATE KEY UPDATE
  slug = VALUES(slug), title = VALUES(title), short_text = VALUES(short_text),
  image = VALUES(image), icon = VALUES(icon), duration_min = VALUES(duration_min),
  price_from = VALUES(price_from), description = VALUES(description),
  includes_json = VALUES(includes_json), benefits = VALUES(benefits),
  notes = VALUES(notes), pre_care = VALUES(pre_care), aftercare = VALUES(aftercare),
  good_for = VALUES(good_for), faq = VALUES(faq), sort_order = VALUES(sort_order),
  active = 1;

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_lash_line', 'lashline_natural', 'بن مژه نازک (طبیعی)', 'خطی محو بین ریشه‌ی مژه‌ها؛ طبیعی‌ترین حالت', 120, 2900000, 0)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_lash_line', 'lashline_full', 'بن مژه پررنگ', 'خط مژه‌ی واضح‌تر، بدون حالت اغراق‌شده', 150, 3600000, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_lash_line', 'lashline_package', 'بن مژه + ترمیم (پکیج)', 'جلسه‌ی اصلی و ترمیم یک ماه بعد، با قیمت مناسب‌تر', 180, 4400000, 2)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_lash_line', 'lashline_touchup', 'ترمیم (یک ماه بعد)', 'برای تثبیت و کامل‌شدن نتیجه', 90, 1300000, 3)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);

/* خط چشم دائم */
INSERT INTO services
  (id, slug, title, short_text, image, icon, ig_link, duration_min,
   price_from, description, includes_json, benefits, notes, pre_care,
   aftercare, good_for, faq, sort_order, active)
VALUES (
  'svc_eyeliner', 'eyeliner', 'خط چشم دائم',
  'خط چشم دائم؛ نگاهی همیشه آماده، بدون نیاز به آرایش روزانه',
  'assets/img/service-eyeliner.jpg', 'liner', NULL,
  150, 1300000,
  '["دیگه لازم نیست هر روز صبح وقت بذاری تا خط‌چشمت رو کامل و قرینه بکشی! خط چشم دائم یه تکنیک تخصصی میکروپیگمنتیشنه که با دقت روی خط مژه‌ها اجرا می‌شه و یه خط‌چشم زیبا، قرینه و ماندگار بهت می‌ده؛ چه صبح از خواب پا شی، چه شب بری بیرون، نگاهت همیشه آماده و جذابه."]',
  '[]',
  '["خط‌چشمی کاملاً قرینه و متناسب با فرم چشم‌هات.","چشم‌ها درشت‌تر، براق‌تر و پرجذبه‌تر دیده می‌شن.","صرفه‌جویی در وقت؛ دیگه نیازی به خط‌چشم زدن روزانه نیست.","ضدآب و مقاوم در برابر عرق، اشک و شنا.","قابل اجرا در استایل‌های مختلف؛ کلاسیک، اسموکی یا با دم‌چشمی ظریف."]',
  '["انجام کار تقریباً بدون درده، فقط یه حس گزگز یا خارش خفیف داره.","ممکنه تا یکی دو روز کمی قرمزی یا تورم سبک داشته باشی که طبیعیه.","ماندگاریش بین ۲ تا ۵ سال، بسته به نوع پوست و مراقبت.","برای حفظ شفافیت رنگ، بعد از حدود یک ماه یک جلسه ترمیم لازمه."]',
  '[]',
  '["تا ۷ روز محل کار را خشک نگه دارید و نشویید.","از دست زدن یا کندن پوسته‌ها جداً خودداری کنید.","تا ۲ هفته از استخر، سونا و آفتاب مستقیم دوری کنید.","پماد تجویزشده را طبق دستور استفاده کنید."]',
  '["نگاهی همیشه آماده، بدون آرایش","خط‌چشمی که پخش یا پاک می‌شود","پوست چرب و حساس به لوازم آرایش","استایل‌های کلاسیک، اسموکی و دم‌چشمی"]',
  '[{"q":"خط چشم دائم درد داره؟","a":"انجام کار تقریباً بدون درده؛ فقط یه حس گزگز یا خارش خفیف داره."},{"q":"چند وقت ماندگاری داره؟","a":"بین ۲ تا ۵ سال، بسته به نوع پوست و مراقبت. برای حفظ شفافیت رنگ، بعد از حدود یک ماه یک جلسه ترمیم لازمه."},{"q":"چه استایل‌هایی اجرا می‌شه؟","a":"کلاسیک، اسموکی، یا با دم‌چشمی ظریف؛ فرم نهایی قبل از کار روی پلک طراحی می‌شه و تا تأیید تو شروع نمی‌شه."}]',
  1, 1
)
ON DUPLICATE KEY UPDATE
  slug = VALUES(slug), title = VALUES(title), short_text = VALUES(short_text),
  image = VALUES(image), icon = VALUES(icon), duration_min = VALUES(duration_min),
  price_from = VALUES(price_from), description = VALUES(description),
  includes_json = VALUES(includes_json), benefits = VALUES(benefits),
  notes = VALUES(notes), pre_care = VALUES(pre_care), aftercare = VALUES(aftercare),
  good_for = VALUES(good_for), faq = VALUES(faq), sort_order = VALUES(sort_order),
  active = 1;

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_eyeliner', 'liner_thin', 'خط چشم نازک (لش‌لاین)', 'خطی باریک و نامحسوس، نزدیک خط مژه', 120, 2900000, 0)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_eyeliner', 'liner_classic', 'خط چشم کلاسیک', 'خط مشخص با ضخامت دلخواه', 150, 3500000, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_eyeliner', 'liner_wing', 'خط چشم با دم ظریف', 'برای نگاهی کشیده و خاص', 165, 3900000, 2)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_eyeliner', 'liner_smoky', 'خط چشم اسموکی', 'محو و پنبه‌ای، بدون مرز تیز', 180, 4200000, 3)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_eyeliner', 'liner_touchup', 'ترمیم (حدود یک ماه بعد)', 'برای شفافیت و یکدستی رنگ', 90, 1300000, 4)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);

/* دارک لیپس (روشن‌سازی لب‌های تیره) */
INSERT INTO services
  (id, slug, title, short_text, image, icon, ig_link, duration_min,
   price_from, description, includes_json, benefits, notes, pre_care,
   aftercare, good_for, faq, sort_order, active)
VALUES (
  'svc_dark_lips', 'dark-lips', 'دارک لیپس (روشن‌سازی لب‌های تیره)',
  'دارک لیپس؛ وداع با تیرگی لب‌ها، سلام به لب‌های صورتی و شفاف',
  'assets/img/service-dark-lips.jpg', 'lip', NULL,
  165, 1800000,
  '["تیرگی لب یکی از دغدغه‌های خیلی از خانم‌هاست که با آرایش هم به‌طور کامل پوشش داده نمی‌شه. دارک لیپس یه تکنیک تخصصی میکروپیگمنتیشنه که با پوشوندن رنگدانه‌های تیره‌ی لب و جایگزینی با رنگ‌های طبیعی و روشن، به لب‌هات یه رنگ صورتی، شفاف و سالم می‌ده؛ بدون نیاز به رژلب!"]',
  '[]',
  '["از بین بردن تیرگی و کبودی طبیعی یا اکتسابی لب.","رنگ لب طبیعی، یکدست و شفاف حتی بدون آرایش.","افزایش اعتمادبه‌نفس در لبخند و عکس‌گرفتن.","ماندگاری بالا و نتیجه‌ای که هر روز تازه به نظر می‌رسه."]',
  '["کار تقریباً بدون درده، بی‌حسی موضعی هم استفاده می‌شه.","طبیعیه که تا چند روز لب کمی متورم یا خشک باشه.","رنگ نهایی معمولاً بعد از التیام کامل (حدود ۲ تا ۴ هفته) مشخص می‌شه.","برای رسیدن به نتیجه‌ی ایده‌آل معمولاً ۲ جلسه (اصلی + ترمیم) لازمه."]',
  '["از ۴۸ ساعت قبل، از هرگونه لایه‌بردار یا اسکراب لب خودداری کن.","اگر سابقه‌ی تبخال (هرپس) داری، حتماً قبل از نوبت به متخصص اطلاع بده تا داروی پیشگیری تجویز بشه: قرص آسیکلوویر ۴۰۰ میلی‌گرم هر ۸ ساعت، از ۳ روز قبل از نوبت (طبق تجویز پزشک) و تا ۳ روز بعد از انجام کار ادامه پیدا کنه.","از یک هفته قبل، مصرف داروهای رقیق‌کننده‌ی خون (مثل آسپرین) و مکمل‌هایی مثل امگا۳ رو در صورت امکان و با تأیید پزشک محدود کن.","حداقل ۲۴ ساعت قبل از نوبت، الکل مصرف نکن.","در روز کار، از رژلب یا محصولات آرایشی روی لب استفاده نکن.","لب‌ها رو با مرطوب‌کننده یا بالم بدون رنگ، آماده و نرم نگه‌دار.","ترجیحاً با معده‌ی پر سر جلسه بیا، نه ناشتا.","اگر تحت درمان دارویی خاص یا بیماری زمینه‌ای هستی، حتماً قبل از نوبت با متخصص هماهنگ کن."]',
  '["تماس آب با لب اشکالی نداره.","تا ۲ الی ۳ روز، هیچ نوع شوینده (صابون، ژل شست‌وشو و...) نباید به لب‌ها برسه.","در چند روز اول، با یک پد پنبه‌ای مرطوب‌شده با آب جوشیده‌ی ولرم به‌صورت ضربه‌ای (نه مالشی) روی لب بزن؛ این کار باعث خروج آب‌های ناباف (لنف) از لب و رطوبت‌رسانی قابل‌توجه به لب می‌شه.","در صورت تورم لب، می‌تونی کمپرس یخ (پیچیده‌شده در پارچه‌ی تمیز، نه تماس مستقیم یخ با پوست) رو به‌صورت کوتاه و متناوب روی لب بذاری تا تورم کاهش پیدا کنه.","به‌هیچ‌وجه از هیچ نوع پمادی بعد از کار استفاده نشه.","لب‌ها رو خشک نگه‌دار؛ از خیس‌کاری زیاد یا لیسیدن مکرر لب پرهیز کن.","تا بهبودی کامل، از رژلب، مداد لب و سایر محصولات آرایشی روی لب استفاده نکن.","از خوردن غذاهای خیلی داغ، تند یا شور در چند روز اول خودداری کن تا تحریک لب کمتر بشه.","پوسته‌ریزی طبیعیه؛ پوسته‌ها رو نکن و اجازه بده خودشون بریزن، وگرنه رنگ‌دهی یکنواخت نمی‌شه.","تا التیام کامل، از قرارگیری مستقیم در معرض آفتاب، سونا و استخر خودداری کن.","از خمیردندان‌های سفیدکننده (مثل تری‌دی وایت) در این دوره استفاده نکن.","در صورت وجود تبخال، مصرف قرص آسیکلوویر طبق برنامه‌ی تجویزشده تا ۳ روز بعد از کار ادامه پیدا کنه.","در صورت تمایل و برای بهبودی سریع‌تر، می‌تونی از قرص آناهیل استفاده کنی.","رنگ نهایی و واقعی لب معمولاً بعد از ۳ تا ۴ هفته و پس از افتادن کامل پوسته‌ها مشخص می‌شه."]',
  '["لب‌های تیره یا کبود","تیرگی ناشی از آفتاب یا سیگار","کسانی که با رژلب هم پوشش کامل نمی‌گیرند","لبخند و عکس‌های بی‌دغدغه"]',
  '[{"q":"دارک لیپس درد داره؟","a":"کار تقریباً بدون درده و بی‌حسی موضعی هم استفاده می‌شه."},{"q":"رنگ نهایی کِی مشخص می‌شه؟","a":"معمولاً بعد از التیام کامل، حدود ۲ تا ۴ هفته بعد از جلسه."},{"q":"چند جلسه لازمه؟","a":"برای رسیدن به نتیجه‌ی ایده‌آل معمولاً ۲ جلسه (اصلی + ترمیم) لازمه."},{"q":"اگر سابقه‌ی تبخال داشته باشم چه؟","a":"حتماً قبل از نوبت اطلاع بده تا داروی پیشگیری تجویز بشه؛ مصرفش از ۳ روز قبل شروع و تا ۳ روز بعد ادامه پیدا می‌کنه."}]',
  2, 1
)
ON DUPLICATE KEY UPDATE
  slug = VALUES(slug), title = VALUES(title), short_text = VALUES(short_text),
  image = VALUES(image), icon = VALUES(icon), duration_min = VALUES(duration_min),
  price_from = VALUES(price_from), description = VALUES(description),
  includes_json = VALUES(includes_json), benefits = VALUES(benefits),
  notes = VALUES(notes), pre_care = VALUES(pre_care), aftercare = VALUES(aftercare),
  good_for = VALUES(good_for), faq = VALUES(faq), sort_order = VALUES(sort_order),
  active = 1;

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_dark_lips', 'dark_lips_main', 'دارک لیپس (روشن‌سازی)', 'پوشاندن رنگدانه‌های تیره و جایگزینی با رنگ‌های روشن', 165, 4200000, 0)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_dark_lips', 'dark_lips_package', 'دارک لیپس + ترمیم (پکیج)', 'جلسه‌ی اصلی و ترمیم، برای نتیجه‌ی یکدست', 195, 5000000, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_dark_lips', 'dark_lips_touchup', 'ترمیم روشن‌سازی', 'یک جلسه بعد از جلسه‌ی اصلی', 90, 1800000, 2)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);

/* شیدینگ و تینت دائمی لب */
INSERT INTO services
  (id, slug, title, short_text, image, icon, ig_link, duration_min,
   price_from, description, includes_json, benefits, notes, pre_care,
   aftercare, good_for, faq, sort_order, active)
VALUES (
  'svc_lip_tint', 'lip-tint', 'شیدینگ و تینت دائمی لب',
  'شیدینگ و تینت دائمی لب؛ لب‌هایی پرحجم‌تر، رنگی‌تر و همیشه آماده',
  'assets/img/service-lip-tint.jpg', 'lip', NULL,
  150, 1600000,
  '["دیگه نیازی نیست هر بار قبل از بیرون رفتن رژلب بزنی! شیدینگ و تینت دائمی لب با پاشیدن ملایم رنگدانه روی لب، هم کانتور لب رو زیباتر می‌کنه و هم یه رنگ دلخواه و ماندگار بهش می‌ده؛ نتیجه‌ای شبیه رژلب مات و طبیعی، ولی همیشگی."]',
  '[]',
  '["لب‌ها پرحجم‌تر و فرم‌دارتر به نظر می‌رسن.","امکان انتخاب رنگ دلخواه، از صورتی طبیعی تا رنگ‌های پررنگ‌تر.","پوشش نامنظمی‌ها و کم‌رنگی طبیعی لب.","ماندگاری بالا و صرفه‌جویی در استفاده‌ی روزانه از رژلب."]',
  '["کار تقریباً بدون درده، بی‌حسی موضعی استفاده می‌شه.","تا چند روز اول ممکنه لب کمی متورم، خشک یا پوسته‌پوسته بشه که طبیعیه.","رنگ نهایی واقعی معمولاً بعد از حدود ۳ تا ۴ هفته دیده می‌شه.","برای ماندگاری بهتر، یک جلسه ترمیم بعد از یک ماه توصیه می‌شه."]',
  '["از ۴۸ ساعت قبل، از هرگونه لایه‌بردار یا اسکراب لب خودداری کن.","اگر سابقه‌ی تبخال (هرپس) داری، حتماً قبل از نوبت به متخصص اطلاع بده تا داروی پیشگیری تجویز بشه: قرص آسیکلوویر ۴۰۰ میلی‌گرم هر ۸ ساعت، از ۳ روز قبل از نوبت (طبق تجویز پزشک) و تا ۳ روز بعد از انجام کار ادامه پیدا کنه.","از یک هفته قبل، مصرف داروهای رقیق‌کننده‌ی خون (مثل آسپرین) و مکمل‌هایی مثل امگا۳ رو در صورت امکان و با تأیید پزشک محدود کن.","حداقل ۲۴ ساعت قبل از نوبت، الکل مصرف نکن.","در روز کار، از رژلب یا محصولات آرایشی روی لب استفاده نکن.","لب‌ها رو با مرطوب‌کننده یا بالم بدون رنگ، آماده و نرم نگه‌دار.","ترجیحاً با معده‌ی پر سر جلسه بیا، نه ناشتا.","اگر تحت درمان دارویی خاص یا بیماری زمینه‌ای هستی، حتماً قبل از نوبت با متخصص هماهنگ کن."]',
  '["تماس آب با لب اشکالی نداره.","تا ۲ الی ۳ روز، هیچ نوع شوینده (صابون، ژل شست‌وشو و...) نباید به لب‌ها برسه.","در چند روز اول، با یک پد پنبه‌ای مرطوب‌شده با آب جوشیده‌ی ولرم به‌صورت ضربه‌ای (نه مالشی) روی لب بزن؛ این کار باعث خروج آب‌های ناباف (لنف) از لب و رطوبت‌رسانی قابل‌توجه به لب می‌شه.","در صورت تورم لب، می‌تونی کمپرس یخ (پیچیده‌شده در پارچه‌ی تمیز، نه تماس مستقیم یخ با پوست) رو به‌صورت کوتاه و متناوب روی لب بذاری تا تورم کاهش پیدا کنه.","به‌هیچ‌وجه از هیچ نوع پمادی بعد از کار استفاده نشه.","لب‌ها رو خشک نگه‌دار؛ از خیس‌کاری زیاد یا لیسیدن مکرر لب پرهیز کن.","تا بهبودی کامل، از رژلب، مداد لب و سایر محصولات آرایشی روی لب استفاده نکن.","از خوردن غذاهای خیلی داغ، تند یا شور در چند روز اول خودداری کن تا تحریک لب کمتر بشه.","پوسته‌ریزی طبیعیه؛ پوسته‌ها رو نکن و اجازه بده خودشون بریزن، وگرنه رنگ‌دهی یکنواخت نمی‌شه.","تا التیام کامل، از قرارگیری مستقیم در معرض آفتاب، سونا و استخر خودداری کن.","از خمیردندان‌های سفیدکننده (مثل تری‌دی وایت) در این دوره استفاده نکن.","در صورت وجود تبخال، مصرف قرص آسیکلوویر طبق برنامه‌ی تجویزشده تا ۳ روز بعد از کار ادامه پیدا کنه.","در صورت تمایل و برای بهبودی سریع‌تر، می‌تونی از قرص آناهیل استفاده کنی.","رنگ نهایی و واقعی لب معمولاً بعد از ۳ تا ۴ هفته و پس از افتادن کامل پوسته‌ها مشخص می‌شه."]',
  '["لب‌های کم‌رنگ یا بی‌حالت","نامتقارنی فرم لب","کسانی که رژلب دائمی می‌خواهند","رنگی همیشگی، شبیه رژلب مات"]',
  '[{"q":"شیدینگ و تینت چه تفاوتی دارن؟","a":"در شیدینگ رنگ ملایم و پخش‌شده روی لب می‌شینه؛ در تینت (فول‌لیپ) کل لب با رنگ سیرتر و یکدست پوشش داده می‌شه. رنگ و استایل دلخواهت رو قبل از کار با هم انتخاب می‌کنیم."},{"q":"درد داره؟","a":"کار تقریباً بدون درده و بی‌حسی موضعی استفاده می‌شه."},{"q":"رنگ نهایی کِی مشخص می‌شه؟","a":"معمولاً بعد از حدود ۳ تا ۴ هفته، پس از افتادن کامل پوست‌ها."},{"q":"ماندگاریش چقدره؟","a":"ماندگاری بالا دارد و برای حفظ آن، یک جلسه ترمیم بعد از یک ماه توصیه می‌شه."}]',
  3, 1
)
ON DUPLICATE KEY UPDATE
  slug = VALUES(slug), title = VALUES(title), short_text = VALUES(short_text),
  image = VALUES(image), icon = VALUES(icon), duration_min = VALUES(duration_min),
  price_from = VALUES(price_from), description = VALUES(description),
  includes_json = VALUES(includes_json), benefits = VALUES(benefits),
  notes = VALUES(notes), pre_care = VALUES(pre_care), aftercare = VALUES(aftercare),
  good_for = VALUES(good_for), faq = VALUES(faq), sort_order = VALUES(sort_order),
  active = 1;

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_lip_tint', 'tint_shading', 'شیدینگ لب (رنگ ملایم)', 'رنگ پخش‌شده و طبیعی، بدون خط دور تیز', 150, 3500000, 0)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_lip_tint', 'tint_full', 'تینت دائمی لب (فول‌لیپ)', 'پوشش یکدست کل لب با رنگ دلخواه', 180, 4200000, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_lip_tint', 'tint_contour', 'کانتور و اصلاح فرم لب', 'برای تقارن و فرم‌دار شدن لب', 120, 2900000, 2)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_lip_tint', 'tint_touchup', 'ترمیم دوره‌ای', 'یک ماه بعد از جلسه‌ی اصلی', 90, 1600000, 3)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);

/* میکروبلیدینگ ابرو */
INSERT INTO services
  (id, slug, title, short_text, image, icon, ig_link, duration_min,
   price_from, description, includes_json, benefits, notes, pre_care,
   aftercare, good_for, faq, sort_order, active)
VALUES (
  'svc_brow_micro', 'brow-microblading', 'میکروبلیدینگ ابرو',
  'میکروبلیدینگ ابرو؛ ابروهایی طبیعی، پرپشت و همیشه مرتب',
  'assets/img/service-brow-microblading.jpg', 'brow', NULL,
  165, 1500000,
  '["دیگه لازم نیست هر روز وقت بذاری تا ابروهاتو با مداد یا سایه پر کنی و فرم بدی! میکروبلیدینگ یه تکنیک تخصصی و دقیقه که با کشیدن تار به تار و شبیه‌سازی موهای طبیعی ابرو، یه فرم زیبا، متقارن و پرپشت به ابروهات می‌ده؛ نتیجه‌ای اونقدر طبیعیه که کسی متوجه نمی‌شه کار انجام دادی، فقط فکر می‌کنن ابروهای خدادادیته!"]',
  '[]',
  '["فرم و قرینگی کامل متناسب با حالت صورت.","پوشش کامل جاهای خالی، کم‌پشتی یا نامنظمی ابرو.","نتیجه‌ای کاملاً طبیعی و تار به تار، نه یکدست و مصنوعی.","صرفه‌جویی در وقت؛ دیگه نیازی به آرایش روزانه‌ی ابرو نیست.","مناسب برای افرادی با ابروی کم‌پشت، نامتقارن یا آسیب‌دیده از اصلاح زیاد."]',
  '["کار تقریباً بدون درده، بی‌حسی موضعی هم استفاده می‌شه.","طبیعیه که ۲ تا ۳ روز اول رنگ کمی پررنگ‌تر از حالت نهایی به نظر برسه.","ممکنه کمی قرمزی یا تورم خفیف در همون روز اول داشته باشی.","رنگ نهایی و واقعی بعد از حدود ۲ تا ۴ هفته و پس از افتادن پوسته‌ها مشخص می‌شه.","برای نتیجه‌ی پرفکت، یک جلسه ترمیم بعد از ۴ تا ۶ هفته لازمه."]',
  '["از ۴۸ ساعت قبل، اپیلاسیون، اصلاح یا رنگ ابرو انجام نده.","از یک هفته قبل، مصرف داروهای رقیق‌کننده‌ی خون (مثل آسپرین) و مکمل‌هایی مثل امگا۳ رو در صورت امکان و با تأیید پزشک محدود کن.","حداقل ۲۴ ساعت قبل از نوبت، الکل مصرف نکن.","از یک هفته قبل، از لایه‌بردار، رتینول یا کرم‌های ضدپیری روی ناحیه‌ی ابرو استفاده نکن.","در روز کار، از آرایش ابرو (مداد، سایه، ژل) استفاده نکن.","اگر بوتاکس یا فیلر در ناحیه‌ی پیشانی/ابرو انجام داده‌ای، حداقل ۲ هفته فاصله بنداز.","اگر تحت درمان دارویی خاص یا بیماری زمینه‌ای هستی، حتماً قبل از نوبت با متخصص هماهنگ کن.","ترجیحاً با معده‌ی پر سر جلسه بیا، نه ناشتا."]',
  '["تا ۲۴ ساعت اول از تماس آب با ناحیه‌ی ابرو خودداری کن.","تا ۷ تا ۱۰ روز، پماد ترمیمی توصیه‌شده توسط متخصص رو طبق دستور و به مقدار کم استفاده کن.","ناحیه رو خشک نگه‌دار؛ از تعریق شدید، ورزش سنگین، سونا و استخر تا التیام کامل خودداری کن.","پوسته‌ریزی طبیعیه؛ پوسته‌ها رو نکن و اجازه بده خودشون بریزن، وگرنه رنگ‌دهی یکنواخت نمی‌شه.","تا التیام کامل، از آرایش مستقیم روی ابرو (مداد، سایه، ژل) استفاده نکن.","از قرارگیری مستقیم در معرض آفتاب تا التیام کامل خودداری کن؛ بعد از بهبودی هم از ضدآفتاب روی ناحیه استفاده کن.","عینک آفتابی یا کلاه هنگام بیرون رفتن در روزهای اول توصیه می‌شه.","رنگ نهایی و واقعی بعد از ۲ تا ۴ هفته و پس از افتادن کامل پوسته‌ها مشخص می‌شه."]',
  '["ابروی کم‌پشت یا نامتقارن","جای خالی و ریزش موی ابرو","آسیب‌دیده از اصلاح زیاد","کسانی که وقت آرایش روزانه‌ی ابرو ندارند"]',
  '[{"q":"میکروبلیدینگ درد داره؟","a":"کار تقریباً بدون درده و بی‌حسی موضعی هم استفاده می‌شه."},{"q":"رنگ نهایی کِی مشخص می‌شه؟","a":"بعد از حدود ۲ تا ۴ هفته و پس از افتادن پوسته‌ها. دو تا سه روز اول رنگ کمی پررنگ‌تر از حالت نهایی به نظر می‌رسه که طبیعیه."},{"q":"ترمیم لازمه؟","a":"برای نتیجه‌ی پرفکت، یک جلسه ترمیم بعد از ۴ تا ۶ هفته لازمه."},{"q":"چند وقت ماندگاری داره؟","a":"بسته به نوع پوست و مراقبت؛ با رعایت مراقبت‌ها و ترمیم دوره‌ای، رنگ تازه می‌مونه."}]',
  4, 1
)
ON DUPLICATE KEY UPDATE
  slug = VALUES(slug), title = VALUES(title), short_text = VALUES(short_text),
  image = VALUES(image), icon = VALUES(icon), duration_min = VALUES(duration_min),
  price_from = VALUES(price_from), description = VALUES(description),
  includes_json = VALUES(includes_json), benefits = VALUES(benefits),
  notes = VALUES(notes), pre_care = VALUES(pre_care), aftercare = VALUES(aftercare),
  good_for = VALUES(good_for), faq = VALUES(faq), sort_order = VALUES(sort_order),
  active = 1;

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_brow_micro', 'micro_hair', 'میکروبلیدینگ تار به تار', 'تراش‌های ظریف شبیه موی طبیعی ابرو', 165, 3900000, 0)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_brow_micro', 'micro_package', 'میکروبلیدینگ + ترمیم (پکیج)', 'جلسه‌ی اصلی و ترمیم ۴ تا ۶ هفته بعد', 195, 4600000, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_brow_micro', 'micro_touchup', 'ترمیم ابرو', 'برای کامل‌شدن فرم و رنگ', 90, 1500000, 2)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);

/* لیفت و لمینت مژه و ابرو */
INSERT INTO services
  (id, slug, title, short_text, image, icon, ig_link, duration_min,
   price_from, description, includes_json, benefits, notes, pre_care,
   aftercare, good_for, faq, sort_order, active)
VALUES (
  'svc_lash_brow_lift', 'lash-brow-lift', 'لیفت و لمینت مژه و ابرو',
  'لیفت و لمینت مژه و ابرو؛ فرم‌دهی طبیعی بدون نیاز به اکستنشن یا رنگ روزانه',
  'assets/img/service-lash-brow-lift.jpg', 'lift', NULL,
  75, 900000,
  '["دلت می‌خواد مژه‌هات فرفری و بلندتر به نظر برسن یا ابروهات مرتب و پرپشت‌تر بشن، بدون اکستنشن و بدون آرایش روزانه؟ لیفت و لمینت دقیقاً همین کار رو می‌کنه! این روش با فرم‌دهی و تقویت موهای طبیعی مژه و ابرو، یه ظاهر مرتب، پرپشت و جذاب بهت می‌ده که چند هفته ماندگاره؛ انگار هر روز صبح از آرایشگاه اومدی بیرون!"]',
  '[]',
  '["مژه‌ها فرفری‌تر، بلندتر و پرپشت‌تر به نظر می‌رسن، بدون نیاز به مژه مصنوعی.","ابروها مرتب، پرپشت و فرم‌گرفته می‌شن، حتی اگه موهای سرکش داشته باشن.","روشی سبک و کاملاً طبیعی، برخلاف اکستنشن یا تاتو.","نتیجه‌ای که چند هفته (معمولاً ۴ تا ۶ هفته) ماندگاره.","بدون نیاز به ریمل یا ژل ابرو روزانه؛ صبح‌ها فقط چند دقیقه صرفه‌جویی وقت."]',
  '["کاملاً بدون درده، فقط یه حس آرامش و کمی سنگینی پلک داری.","ممکنه بلافاصله بعد از کار چشم‌ها کمی حساس یا قرمز به نظر برسن که خیلی زود برطرف می‌شه.","ماندگاری معمولاً بین ۴ تا ۶ هفته‌ست، بسته به سیکل رشد طبیعی مو.","برای حفظ نتیجه، تکرار هر ۴ تا ۶ هفته یک‌بار توصیه می‌شه."]',
  '["از ۲۴ ساعت قبل، از ریمل، خط‌چشم یا هرگونه آرایش چشم استفاده نکن.","از استفاده‌ی لنز تماسی در روز انجام کار خودداری کن یا لنزهاتو همراه داشته باش.","از یک هفته قبل، اکستنشن مژه یا هرگونه کار حرفه‌ای دیگه روی مژه/ابرو انجام نده.","اگر التهاب، عفونت چشم یا حساسیت پوستی فعال داری، نوبت رو به تعویق بنداز.","اگر لنز دائم (میکروبلیدینگ) یا تزریق اخیر در ناحیه‌ی ابرو داشتی، حتماً به متخصص اطلاع بده.","ترجیحاً بدون آرایش صورت سر جلسه بیا تا کار تمیزتر انجام بشه."]',
  '["تا ۲۴ تا ۴۸ ساعت اول، از تماس آب، بخار یا رطوبت (حمام داغ، سونا، استخر) با ناحیه خودداری کن.","در همین بازه، از خواب روی صورت یا فشار به مژه/ابرو پرهیز کن.","از ریمل، خط‌چشم و آرایش روی ابرو حداقل تا ۲۴ ساعت اول استفاده نکن.","از مالیدن یا کشیدن مژه‌ها و ابروها خودداری کن تا فرم به‌هم نریزه.","در صورت تمایل، از سرم یا روغن تقویتی مخصوص مژه/ابرو (طبق توصیه‌ی متخصص) برای ماندگاری بهتر استفاده کن.","از حلال آرایش چشم یا محصولات روغنی قوی روی ناحیه در روزهای اول پرهیز کن.","برای حفظ نتیجه، هر ۴ تا ۶ هفته یک‌بار تکرار توصیه می‌شه."]',
  '["مژه‌های صاف رو به پایین","ابروهای نامرتب با موهای سرکش","کسانی که اکستنشن یا تتو نمی‌خواهند","نتیجه‌ای طبیعی برای چند هفته"]',
  '[{"q":"لیفت و لمینت درد داره؟","a":"کاملاً بدون درده؛ فقط یه حس آرامش و کمی سنگینی پلک داری."},{"q":"چند وقت ماندگاری داره؟","a":"معمولاً بین ۴ تا ۶ هفته، بسته به سیکل رشد طبیعی مو."},{"q":"هر چند وقت تکرار کنم؟","a":"برای حفظ نتیجه، تکرار هر ۴ تا ۶ هفته یک‌بار توصیه می‌شه."},{"q":"تفاوتش با اکستنشن چیه؟","a":"در لیفت و لمینت چیزی به موی طبیعی اضافه نمی‌شه؛ فقط موهای خودت فرم می‌گیرن و تقویت می‌شن."}]',
  5, 1
)
ON DUPLICATE KEY UPDATE
  slug = VALUES(slug), title = VALUES(title), short_text = VALUES(short_text),
  image = VALUES(image), icon = VALUES(icon), duration_min = VALUES(duration_min),
  price_from = VALUES(price_from), description = VALUES(description),
  includes_json = VALUES(includes_json), benefits = VALUES(benefits),
  notes = VALUES(notes), pre_care = VALUES(pre_care), aftercare = VALUES(aftercare),
  good_for = VALUES(good_for), faq = VALUES(faq), sort_order = VALUES(sort_order),
  active = 1;

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_lash_brow_lift', 'lift_lash', 'لیفت مژه', 'فر و بالا آمدن مژه‌های طبیعی', 60, 900000, 0)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_lash_brow_lift', 'lift_lash_tint', 'لیفت + رنگ مژه', 'برای مژه‌های روشن یا کم‌رنگ', 75, 1100000, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_lash_brow_lift', 'lift_brow_lam', 'لمینت ابرو', 'نظم‌دهی و پرپشت دیده شدن ابرو', 60, 950000, 2)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);

INSERT INTO service_variants
  (service_id, variant_key, name, note, duration_min, price, sort_order)
VALUES ('svc_lash_brow_lift', 'lift_combo', 'پکیج مژه + ابرو', 'صرفه‌جویی در وقت و هزینه', 105, 1750000, 3)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), note = VALUES(note),
  duration_min = VALUES(duration_min), price = VALUES(price),
  sort_order = VALUES(sort_order);


/*
   برای بررسی نتیجه:
     SELECT id, title, active FROM services ORDER BY sort_order;
     SELECT service_id, variant_key, price FROM service_variants
      ORDER BY service_id, sort_order;
*/
