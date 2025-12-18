# معلومات حساب المطور (Developer Account)

## تفاصيل الحساب

### بيانات تسجيل الدخول:
- **اسم المستخدم (Username):** `developer`
- **كلمة المرور الافتراضية:** `password`

## صلاحيات حساب المطور

حساب المطور له صلاحيات خاصة:

1. **الوصول في وضع الصيانة:**
   - حساب المطور هو الحساب الوحيد الذي يمكنه تسجيل الدخول واستخدام النظام حتى عندما يكون وضع الصيانة مفعّلاً
   - جميع المستخدمين الآخرين (accountant, sales, production, manager) سيتم منعهم من الوصول عند تفعيل وضع الصيانة

2. **لوحة التحكم:**
   - حساب المطور يستخدم لوحة المدير (`dashboard/manager.php`) - لا توجد لوحة تحكم منفصلة للمطور
   - يمكن لحساب المطور الوصول إلى جميع صفحات لوحة المدير
   - يمكن الوصول إلى صفحة "إعدادات النظام" لإدارة وضع الصيانة

3. **صلاحيات كاملة:**
   - حساب المطور يعتبر أعلى مستوى من الصلاحيات في النظام
   - يمكنه الوصول إلى جميع صفحات النظام حتى في حالة وضع الصيانة

## إنشاء حساب المطور

### الطريقة الأولى: SQL Query مباشرة

قم بتنفيذ هذا SQL Query في قاعدة البيانات:

```sql
-- 1. تحديث enum role لإضافة 'developer'
ALTER TABLE `users` MODIFY COLUMN `role` enum('accountant','sales','production','manager','developer') NOT NULL;

-- 2. إنشاء حساب المطور
INSERT INTO `users` (`username`, `email`, `password_hash`, `role`, `full_name`, `status`) 
VALUES ('developer', 'developer@company.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'developer', 'حساب المطور', 'active')
ON DUPLICATE KEY UPDATE `role`='developer', `status`='active';
```

**ملاحظة:** كلمة المرور الافتراضية هي `password` - **يجب تغييرها فوراً بعد أول تسجيل دخول**.

### الطريقة الثانية: استخدام PHP

يمكن إنشاء حساب المطور من خلال PHP:

```php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

$db = db();

// التأكد من وجود role 'developer' في enum
$db->execute("ALTER TABLE `users` MODIFY COLUMN `role` enum('accountant','sales','production','manager','developer') NOT NULL");

// إنشاء حساب المطور
$passwordHash = password_hash('password', PASSWORD_DEFAULT);
$db->execute(
    "INSERT INTO `users` (`username`, `email`, `password_hash`, `role`, `full_name`, `status`) 
     VALUES (?, ?, ?, 'developer', 'حساب المطور', 'active')
     ON DUPLICATE KEY UPDATE `role`='developer', `status`='active'",
    ['developer', 'developer@company.com', $passwordHash]
);
```

## تغيير كلمة المرور

**مهم جداً:** يجب تغيير كلمة المرور الافتراضية `password` فوراً بعد أول تسجيل دخول:

1. سجل الدخول باستخدام `developer` / `password`
2. اذهب إلى صفحة البروفايل (Profile)
3. قم بتغيير كلمة المرور إلى كلمة مرور قوية وآمنة

## الأمان

- حساب المطور له صلاحيات كاملة - استخدمه بحذر
- لا تشارك بيانات حساب المطور مع مستخدمين عاديين
- استخدم كلمة مرور قوية ومعقدة
- قم بتفعيل وضع الصيانة عند إجراء صيانة على النظام

## استخدام وضع الصيانة

عندما تريد تفعيل وضع الصيانة:

1. سجل الدخول بحساب المطور
2. اذهب إلى "إعدادات النظام" من القائمة الجانبية
3. فعّل وضع الصيانة
4. الآن فقط حساب المطور سيتمكن من الوصول
5. جميع المستخدمين الآخرين سيتم منعهم وسيرون رسالة: "التطبيق تحت الصيانة في الوقت الحالي برجاء إعادة المحاولة في وقت لاحق"
