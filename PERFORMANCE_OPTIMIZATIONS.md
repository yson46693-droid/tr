# تحسينات الأداء - Performance Optimizations

## ملخص التحسينات المطبقة

### ✅ 1. Font Display Optimization
- تم إضافة `font-display: swap` لجميع الخطوط (Google Fonts و Bootstrap Icons)
- تقليل FOIT (Flash of Invisible Text) من 530ms
- الخطوط الآن تظهر فوراً مع fallback fonts

### ✅ 2. Back/Forward Cache (bfcache) Fix
- تم استبدال جميع `beforeunload` و `unload` بـ `pagehide` حيث أمكن
- إزالة meta tags التي تمنع bfcache (`no-store`, `Pragma`, `Expires`)
- تحسين Cache-Control headers لاستخدام `private` بدلاً من `no-store`
- الملفات المعدلة:
  - `modules/production/tasks.php`
  - `modules/production/production.php`
  - `reader/index.php`

### ✅ 3. Layout Shift Prevention (CLS)
- إضافة CSS classes للـ aspect ratios (`aspect-ratio-16-9`, `aspect-ratio-4-3`, إلخ)
- إضافة placeholder styles للصور أثناء التحميل
- إضافة min-height للعناصر الديناميكية (cards, modals, tables)

### ✅ 4. LCP (Largest Contentful Paint) Optimization
- تحسين preload للموارد الحرجة
- تحميل CSS بشكل غير متزامن على الموبايل
- تحسين ترتيب تحميل الموارد

### ⚠️ 5. CSS غير المستخدم (41 KiB)
**الحل الموصى به:**
```bash
# استخدام PurgeCSS
npm install -g purgecss
purgecss --css assets/css/*.css --content templates/*.php modules/**/*.php --output assets/css/purged/
```

**أو استخدام أدوات أخرى:**
- UnCSS
- Critical CSS extraction tools
- Build tools مثل Vite أو Webpack مع PurgeCSS plugin

### ⚠️ 6. JavaScript Minification (19 KiB)
**الحل الموصى به:**

#### خيار 1: استخدام Terser (موصى به)
```bash
npm install -g terser
terser assets/js/*.js -o assets/js/min/ -c -m
```

#### خيار 2: استخدام UglifyJS
```bash
npm install -g uglify-js
uglifyjs assets/js/*.js -o assets/js/min/ -c -m
```

#### خيار 3: استخدام Build Tools
- Vite
- Webpack
- Rollup

**ملاحظة:** يجب تحديث مسارات ملفات JS في `header.php` و `footer.php` بعد الضغط.

### ⚠️ 7. Image Optimization (29 KiB)
**الحل الموصى به:**

#### تحويل الصور إلى WebP
```bash
# استخدام cwebp (Google)
cwebp input.jpg -q 80 -o output.webp

# أو استخدام ImageMagick
magick convert input.jpg -quality 80 output.webp
```

#### استخدام `<picture>` element
```html
<picture>
  <source srcset="image.webp" type="image/webp">
  <img src="image.jpg" alt="Description" width="800" height="600" loading="lazy">
</picture>
```

#### ضغط الصور
- استخدام TinyPNG أو Squoosh
- ضغط JPEG بجودة 80-85%
- ضغط PNG مع optipng أو pngquant

## خطوات التطبيق المتبقية

### 1. ضغط JavaScript
```bash
# إنشاء مجلد للملفات المضغوطة
mkdir -p assets/js/min

# ضغط جميع ملفات JS
for file in assets/js/*.js; do
    terser "$file" -o "assets/js/min/$(basename $file)" -c -m
done
```

### 2. تنظيف CSS
```bash
# استخدام PurgeCSS
purgecss --css assets/css/*.css \
  --content "templates/**/*.php" \
  --content "modules/**/*.php" \
  --content "*.php" \
  --output assets/css/purged/ \
  --safelist [".modal", ".card", ".btn", ".table"]
```

### 3. تحسين الصور
- تحويل جميع الصور إلى WebP
- إضافة width و height attributes
- استخدام lazy loading

## مراقبة الأداء

### أدوات المراقبة
1. **Google Lighthouse** - فحص دوري
2. **PageSpeed Insights** - فحص من Google
3. **WebPageTest** - تحليل تفصيلي
4. **Chrome DevTools Performance** - تحليل في الوقت الفعلي

### المقاييس المستهدفة
- **LCP**: < 2.5s
- **FID**: < 100ms
- **CLS**: < 0.1
- **FCP**: < 1.8s
- **TTI**: < 3.8s

## ملاحظات مهمة

1. **اختبار بعد كل تغيير**: تأكد من اختبار الموقع بعد كل تحسين
2. **Backup**: احتفظ بنسخة احتياطية من الملفات الأصلية
3. **التدرج**: طبق التحسينات تدريجياً واختبر كل واحدة
4. **Monitoring**: راقب الأداء بعد التطبيق

## المراجع
- [Web.dev Performance](https://web.dev/performance/)
- [Google Lighthouse](https://developers.google.com/web/tools/lighthouse)
- [MDN Web Performance](https://developer.mozilla.org/en-US/docs/Web/Performance)

