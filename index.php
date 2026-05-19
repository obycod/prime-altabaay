<?php
// تأمين الجلسات ضد الاختراق (HttpOnly & Strict)
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Strict');

session_start();
require 'db.php';

// تجديد المعرف (ID) لمنع اختطاف الجلسة
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

// توليد مفتاح أمان CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 1. توليد جدول المستخدمين أوتوماتيكياً وحساب المدير الافتراضي
$pdo->query("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$stmt = $pdo->query("SELECT COUNT(*) FROM users");
if($stmt->fetchColumn() == 0) {
    $hash = password_hash('Admin@2026', PASSWORD_DEFAULT);
    $pdo->query("INSERT INTO users (username, password, role) VALUES ('admin', '$hash', 'admin')");
}

// توليد جدول سجل الحركات أوتوماتيكياً
$pdo->query("CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50),
    action_details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// 2. تسجيل الخروج
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// 3. معالجة تسجيل الدخول
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login_btn'])) {
    
    // التحقق من مفتاح الأمان (CSRF)
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("خطأ أمني: طلب غير صالح.");
    }

    // التحقق من محاولات تسجيل الدخول (Brute Force Protection)
    if (isset($_SESSION['login_attempts']) && $_SESSION['login_attempts'] >= 5) {
        if (isset($_SESSION['lockout_time']) && time() - $_SESSION['lockout_time'] < 900) {
            $minutes_left = ceil((900 - (time() - $_SESSION['lockout_time'])) / 60);
            $login_error = "تم قفل الحساب مؤقتاً بسبب كثرة المحاولات الفاشلة. يرجى المحاولة بعد $minutes_left دقيقة.";
        } else {
            // انقضت فترة القفل (15 دقيقة)، إعادة تعيين العداد
            unset($_SESSION['login_attempts']);
            unset($_SESSION['lockout_time']);
        }
    }

    if (empty($login_error)) {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            // تصفير العداد عند النجاح
            unset($_SESSION['login_attempts']);
            unset($_SESSION['lockout_time']);
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            header("Location: index.php");
            exit;
        } else {
            $_SESSION['login_attempts'] = isset($_SESSION['login_attempts']) ? $_SESSION['login_attempts'] + 1 : 1;
            $_SESSION['lockout_time'] = time();
            $login_error = 'اسم المستخدم أو كلمة المرور غير صحيحة!';
        }
    }
}

// 4. معالجة إضافة/حذف الموظفين (للمدير فقط)
if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
    if (isset($_POST['add_user_btn'])) {
        $u = trim($_POST['new_username']);
        $p = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        $r = $_POST['new_role'];
        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
            $stmt->execute([$u, $p, $r]);
        } catch(Exception $e) { $login_error = "اسم المستخدم موجود مسبقاً!"; }
    }
    if (isset($_POST['edit_user_btn'])) {
        $id = $_POST['edit_user_id'];
        $u = trim($_POST['edit_username']);
        $r = $_POST['edit_role'];
        try {
            if (!empty($_POST['edit_password'])) {
                $p = password_hash($_POST['edit_password'], PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET username=?, password=?, role=? WHERE id=?");
                $stmt->execute([$u, $p, $r, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET username=?, role=? WHERE id=?");
                $stmt->execute([$u, $r, $id]);
            }
            header("Location: index.php?tab=users");
            exit;
        } catch(Exception $e) { $login_error = "خطأ: اسم المستخدم موجود مسبقاً!"; }
    }
    if (isset($_GET['del_user'])) {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'");
        $stmt->execute([$_GET['del_user']]);
        header("Location: index.php?tab=users");
        exit;
    }
}

// 4.5 تسجيل الخروج التلقائي عند الخمول (Session Timeout)
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > 1800) {
        session_unset();
        session_destroy();
        header("Location: index.php");
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// 5. شاشة القفل وتسجيل الدخول
if (!isset($_SESSION['user_id'])) {
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تسجيل الدخول - Prime & Altabaay</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;800&display=swap" rel="stylesheet">
    <style> body { font-family: 'Cairo', sans-serif; background-color: #0f172a; } </style>
</head>
<body class="flex items-center justify-center h-screen">
    <div class="bg-white p-10 rounded-3xl shadow-2xl w-full max-w-md">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-extrabold text-indigo-600 mb-2">Prime & Altabaay</h1>
            <p class="text-slate-500 font-bold text-sm">بوابة الدخول للنظام اللوجستي</p>
        </div>
        <?php if($login_error): ?>
            <div class="bg-red-50 text-red-600 p-3 rounded-lg mb-6 text-sm font-bold text-center border border-red-200"><?php echo $login_error; ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <div class="mb-5">
                <label class="block text-slate-700 font-bold mb-2">اسم المستخدم</label>
                <input type="text" name="username" required class="w-full px-4 py-3 rounded-xl border border-slate-300 focus:ring-2 focus:ring-indigo-500 outline-none" autocomplete="off" dir="ltr" style="text-align:right">
            </div>
            <div class="mb-8">
                <label class="block text-slate-700 font-bold mb-2">كلمة المرور</label>
                <input type="password" name="password" required class="w-full px-4 py-3 rounded-xl border border-slate-300 focus:ring-2 focus:ring-indigo-500 outline-none" dir="ltr" style="text-align:right">
            </div>
            <button type="submit" name="login_btn" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3.5 rounded-xl shadow-lg transition text-lg">تسجيل الدخول</button>
        </form>
    </div>
</body>
</html>
<?php
    exit;
}

$role = $_SESSION['role'];
$username = $_SESSION['username'];
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prime & Altabaay - ERP System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', sans-serif; background-color: #f1f5f9; overflow: hidden; }
        .dropdown-list { max-height: 250px; overflow-y: auto; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f8fafc; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .glass-panel { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); }
    </style>
</head>
<body class="text-slate-800 flex flex-col md:flex-row h-screen" onclick="closeDropdown(event)">

    <!-- شريط علوي للموبايل -->
    <div class="md:hidden flex justify-between items-center p-4 bg-slate-900 text-white z-40 border-b border-slate-800">
        <h1 class="text-xl font-extrabold tracking-wider text-indigo-400">Prime & Altabaay</h1>
        <button onclick="toggleSidebar(event)" class="text-white focus:outline-none">
            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
        </button>
    </div>

    <aside id="sidebar" class="w-64 bg-slate-900 text-white flex-col shadow-2xl z-50 hidden md:flex absolute md:relative h-full right-0 top-0 transition-all">
        <!-- زر إغلاق القائمة في الموبايل -->
        <div class="md:hidden flex justify-end p-4 border-b border-slate-800">
            <button onclick="toggleSidebar(event)" class="text-slate-400 hover:text-white focus:outline-none">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <div class="h-16 items-center justify-center border-b border-slate-800 hidden md:flex">
            <h1 class="text-xl font-extrabold tracking-wider text-indigo-400">Prime & Altabaay</h1>
        </div>
        <div class="px-4 py-3 bg-slate-800 text-xs font-bold text-slate-400 border-b border-slate-700 flex justify-between items-center">
            <span>مرحباً، <?php echo $username; ?></span>
            <span class="bg-slate-700 text-indigo-300 px-2 py-0.5 rounded"><?php echo strtoupper($role); ?></span>
        </div>
        <nav class="flex-1 px-4 py-6 space-y-2">
            <?php if($role == 'admin' || $role == 'entry'): ?>
            <button onclick="switchTab('shipping-tab', this)" class="tab-btn w-full flex items-center gap-3 px-4 py-3 rounded-lg bg-indigo-600 text-white font-bold transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path></svg>
                طلب توصيل جديد
            </button>
            <?php endif; ?>

            <?php if($role == 'admin' || $role == 'editor'): ?>
            <button onclick="switchTab('clients-tab', this)" class="tab-btn w-full flex items-center gap-3 px-4 py-3 rounded-lg <?php echo ($role=='editor' && $role!='entry')?'bg-indigo-600 text-white':'text-slate-300 hover:bg-slate-800 hover:text-white'; ?> font-bold transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                قاعدة بيانات المكاتب
            </button>
            <?php endif; ?>

            <?php if($role == 'admin' || $role == 'viewer'): ?>
            <button onclick="switchTab('orders-tab', this)" class="tab-btn w-full flex items-center gap-3 px-4 py-3 rounded-lg <?php echo ($role=='viewer')?'bg-indigo-600 text-white':'text-slate-300 hover:bg-slate-800 hover:text-white'; ?> font-bold transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path></svg>
                سجل الطلبات
            </button>
            <?php endif; ?>

            <?php if($role == 'admin'): ?>
            <button onclick="switchTab('users-tab', this)" class="tab-btn w-full flex items-center gap-3 px-4 py-3 rounded-lg text-slate-300 hover:bg-slate-800 hover:text-white font-bold transition border-t border-slate-700 mt-4 pt-6">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                إدارة الموظفين
            </button>
            <button onclick="switchTab('logs-tab', this); fetchLogsFromServer();" class="tab-btn w-full flex items-center gap-3 px-4 py-3 rounded-lg text-slate-300 hover:bg-slate-800 hover:text-white font-bold transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                سجل المراقبة 👁️
            </button>
            <?php endif; ?>
        </nav>
        <div class="p-4 border-t border-slate-800">
            <a href="?logout=true" class="w-full flex justify-center items-center gap-2 bg-red-500 hover:bg-red-600 text-white font-bold py-2 rounded-lg transition text-sm">تسجيل الخروج</a>
        </div>
    </aside>

    <main class="flex-1 flex flex-col h-screen overflow-hidden bg-slate-50">
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">

            <?php if($role == 'admin' || $role == 'entry'): ?>
            <div id="shipping-tab" class="tab-content block animate-fade-in max-w-5xl mx-auto">
                <div class="glass-panel rounded-2xl shadow-sm border border-slate-200 p-6 sm:p-8">
                    <h2 class="text-lg font-bold text-slate-800 mb-6 pb-4 border-b border-slate-100 flex items-center gap-2">
                        <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                        إصدار طلب توصيل جديد
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="relative col-span-1 md:col-span-2 search-container">
                            <label class="block text-sm font-bold text-slate-700 mb-2">أسم المكتب <span class="text-red-500">*</span></label>
                            <input type="text" id="clientName" placeholder="بحث ذكي بالاسم أو الهاتف..." onkeyup="filterSmartSearch()" onfocus="filterSmartSearch()" autocomplete="off" class="w-full px-4 py-3 rounded-xl border border-slate-300 focus:ring-2 focus:ring-indigo-500 transition outline-none shadow-sm">
                            <div id="clientDropdown" class="absolute left-0 right-0 mt-1 bg-white border border-slate-200 rounded-xl shadow-2xl z-50 hidden dropdown-list divide-y divide-slate-100"></div>
                        </div>

                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">عدد الكراتين <span class="text-red-500">*</span></label>
                            <input type="number" id="cartonCount" min="1" placeholder="مثال: 4" class="w-full px-4 py-3 rounded-xl border border-slate-300 focus:ring-2 focus:ring-indigo-500 transition outline-none text-center font-bold shadow-sm">
                        </div>

                        <input type="hidden" id="phone2" value="">
                        <input type="hidden" id="clientType" value="">

                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">رقم الهاتف <span class="text-red-500">*</span></label>
                            <input type="text" id="phoneNumber" class="w-full px-4 py-3 rounded-xl border border-slate-300 focus:ring-2 focus:ring-indigo-500 transition outline-none shadow-sm" dir="ltr" style="text-align: right;">
                        </div>

                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">المحافظة <span class="text-red-500">*</span></label>
                            <input type="text" id="provinceName" list="provincesList" placeholder="اختر المحافظة..." class="w-full px-4 py-3 rounded-xl border border-slate-300 focus:ring-2 focus:ring-indigo-500 transition outline-none shadow-sm">
                            <datalist id="provincesList">
                                <option value="بغداد"><option value="الناصرية ذي قار"><option value="البصرة"><option value="اربيل"><option value="ديالى"><option value="الكوت واسط"><option value="كربلاء"><option value="دهوك"><option value="بابل الحلة"><option value="النجف"><option value="كركوك"><option value="السليمانيه"><option value="صلاح الدين"><option value="الانبار"><option value="السماوة المثنى"><option value="موصل"><option value="الديوانية">
                            </datalist>
                        </div>

                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">مبلغ التوصيل <span class="text-red-500">*</span></label>
                            <input type="text" id="amount" placeholder="0" oninput="formatNumberInput(this)" class="w-full px-4 py-3 rounded-xl border border-slate-300 focus:ring-2 focus:ring-indigo-500 transition outline-none font-bold text-indigo-600 shadow-sm" dir="ltr" style="text-align:right">
                        </div>

                        <div class="col-span-1 md:col-span-3">
                            <label class="block text-sm font-bold text-slate-700 mb-2">رقم الوصل (اختياري)</label>
                            <input type="text" id="receiptNo" placeholder="اتركه فارغاً للتوليد التلقائي..." class="w-full px-4 py-3 rounded-xl border border-slate-300 focus:ring-2 focus:ring-indigo-500 transition outline-none shadow-sm">
                        </div>

                        <div class="col-span-1 md:col-span-3">
                            <label class="block text-sm font-bold text-slate-700 mb-2">العنوان التفصيلي <span class="text-red-500">*</span></label>
                            <input type="text" id="address" placeholder="اكتب العنوان بدقة..." class="w-full px-4 py-3 rounded-xl border border-slate-300 focus:ring-2 focus:ring-indigo-500 transition outline-none shadow-sm">
                        </div>
                    </div>

                    <div class="mt-8 pt-6 border-t border-slate-100">
                        <button id="submitBtn" onclick="processOrder()" class="w-full flex justify-center items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-4 px-8 rounded-xl shadow-md transition text-lg">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                            إرسال الطلب
                        </button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if($role == 'admin' || $role == 'editor'): ?>
            <div id="clients-tab" class="tab-content <?php echo ($role=='editor')?'block':'hidden'; ?> animate-fade-in max-w-7xl mx-auto">
                <div class="glass-panel rounded-2xl shadow-sm border border-slate-200 p-6 sm:p-8">
                    
                    <div class="flex flex-col md:flex-row justify-between items-center mb-6 pb-4 border-b border-slate-100 gap-4">
                        <h2 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                            <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                            إدارة بيانات المكاتب 
                            <span id="clientCountBadge" class="bg-indigo-100 text-indigo-700 py-1 px-3 rounded-md text-xs font-bold">تحميل...</span>
                        </h2>
                        
                        <?php if($role == 'admin'): ?>
                        <div class="flex gap-2">
                            <button onclick="exportClientsToExcel()" class="flex items-center gap-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 font-bold py-2 px-4 rounded-lg transition text-sm shadow-sm">
                                📥 تصدير
                            </button>
                            <button id="btnImport" onclick="document.getElementById('excelFileInput').click()" class="flex items-center gap-2 bg-slate-800 hover:bg-slate-900 text-white font-bold py-2 px-4 rounded-lg shadow-sm transition text-sm">
                                📤 استيراد وتحديث
                            </button>
                            <input type="file" id="excelFileInput" accept=".xlsx, .xls, .csv" class="hidden" onchange="handleExcelImport(event)">
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                        <div class="md:col-span-2">
                            <input type="text" id="tableSearch" placeholder="بحث عام في الجدول..." oninput="renderClientsTable()" class="w-full px-4 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 outline-none text-sm">
                        </div>
                        <div>
                            <select id="filterType" onchange="renderClientsTable()" class="w-full px-4 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 outline-none text-sm bg-white">
                                <option value="">كل الأنواع</option>
                            </select>
                        </div>
                        <div>
                            <select id="filterProvince" onchange="renderClientsTable()" class="w-full px-4 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 outline-none text-sm bg-white">
                                <option value="">كل المحافظات</option>
                            </select>
                        </div>
                    </div>

                    <div class="overflow-x-auto rounded-xl border border-slate-200">
                        <table class="min-w-full divide-y divide-slate-200 text-sm text-right">
                            <thead class="bg-slate-100">
                                <tr>
                                    <th class="px-4 py-3 font-bold text-slate-600 w-12">ت</th>
                                    <th class="px-4 py-3 font-bold text-slate-600">أسم المكتب</th>
                                    <th class="px-4 py-3 font-bold text-slate-600">نوع العميل</th>
                                    <th class="px-4 py-3 font-bold text-slate-600">المحافظة</th>
                                    <th class="px-4 py-3 font-bold text-slate-600">رقم الهاتف</th>
                                    <th class="px-4 py-3 font-bold text-slate-600 max-w-xs">العنوان التفصيلي</th>
                                    <th class="px-4 py-3 font-bold text-slate-600 text-center">تعديل</th>
                                </tr>
                            </thead>
                            <tbody id="clientsPreviewContainer" class="divide-y divide-slate-100 bg-white"></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if($role == 'admin' || $role == 'viewer'): ?>
            <div id="orders-tab" class="tab-content <?php echo ($role=='viewer')?'block':'hidden'; ?> animate-fade-in max-w-7xl mx-auto">
                <div class="glass-panel rounded-2xl shadow-sm border border-slate-200 p-6 sm:p-8">
                    <div class="flex justify-between items-center mb-6 pb-4 border-b border-slate-100">
                        <h2 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                            <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path></svg>
                            سجل الطلبات المصدرة
                        </h2>
                        <div class="flex gap-2 flex-wrap justify-end">
                            <input type="date" id="order-date-filter" onchange="renderOrdersTable()" class="px-3 py-2 rounded-lg border border-slate-300 outline-none text-sm text-slate-700">
                            <button onclick="exportOrdersToExcel()" class="flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2 px-4 rounded-lg shadow-sm transition text-sm">
                                📥 تصدير السجل (Excel)
                            </button>
                            <button onclick="fetchOrdersFromServer()" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-bold py-2 px-4 rounded-lg transition text-sm">
                                🔄 تحديث
                            </button>
                        </div>
                    </div>
                    
                    <div class="overflow-x-auto rounded-xl border border-slate-200">
                        <table class="min-w-full divide-y divide-slate-200 text-sm text-right">
                            <thead class="bg-slate-100">
                                <tr>
                                    <th class="px-4 py-3 font-bold text-slate-600">رقم الطلب</th>
                                    <th class="px-4 py-3 font-bold text-slate-600">المكتب</th>
                                    <th class="px-4 py-3 font-bold text-slate-600">الكراتين</th>
                                    <th class="px-4 py-3 font-bold text-slate-600">المبلغ</th>
                                    <th class="px-4 py-3 font-bold text-slate-600">التاريخ</th>
                                    <th class="px-4 py-3 font-bold text-slate-600 text-center">إجراءات</th>
                                </tr>
                            </thead>
                            <tbody id="ordersPreviewContainer" class="divide-y divide-slate-100 bg-white"></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if($role == 'admin'): ?>
            <div id="users-tab" class="tab-content hidden animate-fade-in max-w-5xl mx-auto">
                <div class="glass-panel rounded-2xl shadow-sm border border-slate-200 p-6 sm:p-8">
                    <h2 class="text-lg font-bold text-slate-800 mb-6 pb-4 border-b border-slate-100">إدارة حسابات الموظفين</h2>
                    
                    <form method="POST" class="bg-slate-50 p-6 rounded-xl border border-slate-200 mb-8 grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">اسم المستخدم</label>
                            <input type="text" name="new_username" required class="w-full px-4 py-2 rounded-lg border border-slate-300 outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">كلمة المرور</label>
                            <input type="password" name="new_password" required class="w-full px-4 py-2 rounded-lg border border-slate-300 outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">الصلاحية</label>
                            <select name="new_role" class="w-full px-4 py-2 rounded-lg border border-slate-300 outline-none bg-white">
                                <option value="entry">موظف إدخال طلبات</option>
                                <option value="editor">معدل بيانات العملاء</option>
                                <option value="viewer">مراقب (سجل الطلبات)</option>
                                <option value="admin">مدير نظام (كامل الصلاحيات)</option>
                            </select>
                        </div>
                        <div>
                            <button type="submit" name="add_user_btn" class="w-full bg-indigo-600 text-white font-bold py-2 rounded-lg hover:bg-indigo-700 transition">إضافة موظف</button>
                        </div>
                    </form>

                    <div class="overflow-x-auto rounded-xl border border-slate-200">
                        <table class="min-w-full divide-y divide-slate-200 text-sm text-right">
                            <thead class="bg-slate-100">
                                <tr>
                                    <th class="px-4 py-3 font-bold text-slate-600">المستخدم</th>
                                    <th class="px-4 py-3 font-bold text-slate-600">الصلاحية</th>
                                    <th class="px-4 py-3 font-bold text-slate-600 text-center">إجراء</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                <?php
                                $users = $pdo->query("SELECT * FROM users")->fetchAll();
                                foreach($users as $u):
                                ?>
                                <tr>
                                    <td class="px-4 py-3 font-bold text-slate-700"><?php echo $u['username']; ?></td>
                                    <td class="px-4 py-3 text-indigo-600 font-bold"><?php echo strtoupper($u['role']); ?></td>
                                    <td class="px-4 py-3 text-center">
                                        <?php if($u['role'] != 'admin'): ?>
                                        <button onclick="openEditUserModal(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['username'], ENT_QUOTES); ?>', '<?php echo $u['role']; ?>')" class="text-indigo-600 hover:text-indigo-800 font-bold bg-indigo-50 hover:bg-indigo-100 px-3 py-1 rounded ml-1 transition">تعديل ✏️</button>
                                        <a href="?del_user=<?php echo $u['id']; ?>" onclick="confirmUserDeletion(event, this.href)" class="text-red-500 hover:text-red-700 font-bold bg-red-50 hover:bg-red-100 px-3 py-1 rounded transition">حذف 🗑️</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if($role == 'admin'): ?>
            <div id="logs-tab" class="tab-content hidden animate-fade-in max-w-7xl mx-auto">
                <div class="glass-panel rounded-2xl shadow-sm border border-slate-200 p-6 sm:p-8">
                    <div class="flex justify-between items-center mb-6 pb-4 border-b border-slate-100">
                        <h2 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                            <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                            سجل حركات النظام
                        </h2>
                        <button onclick="fetchLogsFromServer()" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-bold py-2 px-4 rounded-lg transition text-sm">🔄 تحديث</button>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                        <div>
                            <select id="log-user-filter" onchange="renderLogsTable()" class="w-full px-4 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 outline-none text-sm bg-white">
                                <option value="">كل الموظفين</option>
                            </select>
                        </div>
                        <div>
                            <input type="text" id="log-action-filter" oninput="renderLogsTable()" placeholder="بحث في تفاصيل الحركة..." class="w-full px-4 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 outline-none text-sm">
                        </div>
                        <div>
                            <input type="date" id="log-date-filter" onchange="renderLogsTable()" class="w-full px-4 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-indigo-500 outline-none text-sm text-slate-700">
                        </div>
                    </div>

                    <div class="overflow-x-auto rounded-xl border border-slate-200">
                        <table class="min-w-full divide-y divide-slate-200 text-sm text-right">
                            <thead class="bg-slate-100">
                                <tr>
                                    <th class="px-4 py-3 font-bold text-slate-600 w-48">التاريخ والوقت</th>
                                    <th class="px-4 py-3 font-bold text-slate-600 w-32">الموظف</th>
                                    <th class="px-4 py-3 font-bold text-slate-600">تفاصيل الحركة</th>
                                </tr>
                            </thead>
                            <tbody id="logsPreviewContainer" class="divide-y divide-slate-100 bg-white"></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </main>

    <div id="editModal" class="fixed inset-0 bg-slate-900 bg-opacity-50 hidden items-center justify-center z-50 p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-slate-50">
                <h3 class="text-lg font-bold text-slate-800">تعديل بيانات المكتب</h3>
                <button onclick="closeModal('editModal')" class="text-slate-400 hover:text-red-500 transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="p-6 space-y-4">
                <input type="hidden" id="edit_id">
                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <label class="block text-xs font-bold text-slate-500 mb-1">أسم المكتب</label>
                        <input type="text" id="edit_name" <?php if($role == 'editor') echo 'readonly class="w-full px-3 py-2 border border-slate-200 bg-slate-100 cursor-not-allowed rounded-lg"'; else echo 'class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"'; ?>>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 mb-1">نوع العميل</label>
                        <select id="edit_type" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none bg-white"></select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 mb-1">المحافظة</label>
                        <select id="edit_province" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none bg-white">
                            <option value="بغداد">بغداد</option>
                            <option value="الناصرية ذي قار">الناصرية ذي قار</option>
                            <option value="البصرة">البصرة</option>
                            <option value="اربيل">اربيل</option>
                            <option value="ديالى">ديالى</option>
                            <option value="الكوت واسط">الكوت واسط</option>
                            <option value="كربلاء">كربلاء</option>
                            <option value="دهوك">دهوك</option>
                            <option value="بابل الحلة">بابل الحلة</option>
                            <option value="النجف">النجف</option>
                            <option value="كركوك">كركوك</option>
                            <option value="السليمانيه">السليمانيه</option>
                            <option value="صلاح الدين">صلاح الدين</option>
                            <option value="الانبار">الانبار</option>
                            <option value="السماوة المثنى">السماوة المثنى</option>
                            <option value="موصل">موصل</option>
                            <option value="الديوانية">الديوانية</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 mb-1">رقم الهاتف</label>
                        <input type="text" id="edit_phone" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none" dir="ltr" style="text-align: right;">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 mb-1">رقم الهاتف 2</label>
                        <input type="text" id="edit_phone2" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none" dir="ltr" style="text-align: right;">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-bold text-slate-500 mb-1">العنوان التفصيلي</label>
                        <input type="text" id="edit_address" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none">
                    </div>
                </div>
            </div>
            <div class="px-6 py-4 border-t border-slate-100 bg-slate-50 flex justify-end gap-3">
                <button onclick="closeModal('editModal')" class="px-4 py-2 text-slate-600 bg-white border border-slate-300 rounded-lg font-bold hover:bg-slate-50 transition text-sm">إلغاء</button>
                <button id="btnSaveEdit" onclick="saveClientEdit()" class="px-6 py-2 bg-indigo-600 text-white rounded-lg font-bold hover:bg-indigo-700 transition text-sm">حفظ التعديلات</button>
            </div>
        </div>
    </div>

    <!-- Modal View Order -->
    <div id="modal-view-order" class="fixed inset-0 bg-slate-900 bg-opacity-50 hidden items-center justify-center z-[60] p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-slate-50">
                <h3 class="text-lg font-bold text-slate-800">تفاصيل الطلب <span id="view_order_id" class="text-indigo-600 font-mono"></span></h3>
                <button onclick="closeModal('modal-view-order')" class="text-slate-400 hover:text-red-500 transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-5 text-sm">
                <div><span class="block text-xs font-bold text-slate-500">اسم المكتب</span><div id="view_client_name" class="font-bold text-slate-800 mt-1"></div></div>
                <div><span class="block text-xs font-bold text-slate-500">عدد الكراتين</span><div id="view_carton_count" class="font-bold text-slate-800 mt-1"></div></div>
                <div><span class="block text-xs font-bold text-slate-500">مبلغ التوصيل</span><div id="view_amount" class="font-bold text-indigo-600 mt-1"></div></div>
                <div><span class="block text-xs font-bold text-slate-500">تاريخ الطلب</span><div id="view_created_at" class="font-bold text-slate-800 mt-1" dir="ltr" style="text-align: right;"></div></div>
                <div><span class="block text-xs font-bold text-slate-500">المحافظة</span><div id="view_province" class="font-bold text-slate-800 mt-1"></div></div>
                <div><span class="block text-xs font-bold text-slate-500">رقم الهاتف</span><div id="view_phone" class="font-bold text-slate-800 mt-1" dir="ltr" style="text-align: right;"></div></div>
                <div class="col-span-1 md:col-span-2"><span class="block text-xs font-bold text-slate-500">العنوان التفصيلي</span><div id="view_address" class="font-bold text-slate-800 mt-1"></div></div>
                <div class="col-span-1 md:col-span-2"><span class="block text-xs font-bold text-slate-500">رقم الوصل</span><div id="view_receipt_no" class="font-bold text-slate-800 mt-1"></div></div>
            </div>
        </div>
    </div>

    <!-- Modal Edit User -->
    <div id="editUserModal" class="fixed inset-0 bg-slate-900 bg-opacity-50 hidden items-center justify-center z-[60] p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-slate-50">
                <h3 class="text-lg font-bold text-slate-800">تعديل بيانات الموظف</h3>
                <button onclick="closeModal('editUserModal')" class="text-slate-400 hover:text-red-500 transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <input type="hidden" name="edit_user_id" id="edit_user_id">
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2">اسم المستخدم</label>
                    <input type="text" name="edit_username" id="edit_username" required class="w-full px-4 py-2 rounded-lg border border-slate-300 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2">كلمة المرور <span class="text-xs text-slate-400">(اتركه فارغاً لعدم التغيير)</span></label>
                    <input type="password" name="edit_password" id="edit_password" class="w-full px-4 py-2 rounded-lg border border-slate-300 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2">الصلاحية</label>
                    <select name="edit_role" id="edit_role" class="w-full px-4 py-2 rounded-lg border border-slate-300 outline-none bg-white">
                        <option value="entry">موظف إدخال طلبات</option>
                        <option value="editor">معدل بيانات العملاء</option>
                        <option value="viewer">مراقب (سجل الطلبات)</option>
                        <option value="admin">مدير نظام (كامل الصلاحيات)</option>
                    </select>
                </div>
                <div class="pt-4 border-t border-slate-100 flex justify-end gap-3">
                    <button type="button" onclick="closeModal('editUserModal')" class="px-4 py-2 text-slate-600 bg-white border border-slate-300 rounded-lg font-bold hover:bg-slate-50 transition text-sm">إلغاء</button>
                    <button type="submit" name="edit_user_btn" class="px-6 py-2 bg-indigo-600 text-white rounded-lg font-bold hover:bg-indigo-700 transition text-sm">حفظ التعديلات</button>
                </div>
            </form>
        </div>
    </div>

<script>
const USER_ROLE = '<?php echo $role; ?>';
let currentClientsList = [];
let currentOrdersList = [];
let currentLogsList = [];

// === التنسيق المحاسبي ===
function formatNumStr(num) {
    if(!num) return '0';
    return Number(num).toLocaleString('en-US');
}

function formatNumberInput(element) {
    let val = element.value.replace(/,/g, '');
    if(!isNaN(val) && val !== '') {
        element.value = Number(val).toLocaleString('en-US');
    } else if(val === '') {
        element.value = '';
    }
}

// === التهيئة ومسح الفورمة ===
window.onload = function() {
    resetForm();
    if(USER_ROLE == 'admin' || USER_ROLE == 'entry' || USER_ROLE == 'editor') fetchClientsFromServer();
    if(USER_ROLE == 'admin' || USER_ROLE == 'viewer') fetchOrdersFromServer();
    if(USER_ROLE == 'admin') fetchLogsFromServer();
    
    // إبقاء تاب اليوزرز مفتوح اذا تمت اضافة مستخدم
    const urlParams = new URLSearchParams(window.location.search);
    if(urlParams.get('tab') === 'users') {
        const userBtn = document.querySelector(`button[onclick="switchTab('users-tab', this)"]`);
        if(userBtn) switchTab('users-tab', userBtn);
    }
};

function resetForm() {
    if(document.getElementById('clientName')) {
        document.getElementById('clientName').value = '';
        document.getElementById('cartonCount').value = '';
        document.getElementById('phoneNumber').value = '';
        document.getElementById('phone2').value = '';
        document.getElementById('provinceName').value = '';
        document.getElementById('amount').value = '';
        document.getElementById('receiptNo').value = '';
        document.getElementById('address').value = '';
        document.getElementById('clientType').value = '';
    }
}

function switchTab(tabId, btn) {
    document.querySelectorAll(".tab-content").forEach(el => el.classList.add("hidden"));
    document.querySelectorAll(".tab-btn").forEach(el => {
        el.classList.remove("bg-indigo-600", "text-white");
        el.classList.add("text-slate-300", "hover:bg-slate-800");
    });
    document.getElementById(tabId).classList.remove("hidden");
    btn.classList.add("bg-indigo-600", "text-white");
    btn.classList.remove("text-slate-300", "hover:bg-slate-800");
}

function toggleSidebar(e) {
    if(e) e.stopPropagation();
    const sidebar = document.getElementById('sidebar');
    if (sidebar.classList.contains('hidden')) {
        sidebar.classList.remove('hidden');
        sidebar.classList.add('flex');
    } else {
        sidebar.classList.add('hidden');
        sidebar.classList.remove('flex');
    }
}

// === تأكيد حذف موظف ===
function confirmUserDeletion(e, url) {
    e.preventDefault();
    Swal.fire({
        title: 'هل أنت متأكد؟',
        text: 'سيتم حذف هذا الموظف نهائياً!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#94a3b8',
        confirmButtonText: 'نعم، احذف',
        cancelButtonText: 'إلغاء'
    }).then((result) => {
        if (result.isConfirmed) { window.location.href = url; }
    });
}

// === البحث الذكي ===
function ultraSmartMatch(query, text) {
    if (!text) return false;
    const queryWords = query.toLowerCase().trim().split(/\s+/);
    const targetText = text.toLowerCase();
    return queryWords.every(word => targetText.includes(word));
}

function filterSmartSearch() {
    const query = document.getElementById('clientName').value;
    const dropdown = document.getElementById('clientDropdown');
    dropdown.innerHTML = '';
    if (!query.trim()) { dropdown.style.display = 'none'; return; }

    const filtered = currentClientsList.filter(c => ultraSmartMatch(query, `${c.name||''} ${c.phone||''} ${c.province||''}`)).slice(0, 20);

    if (filtered.length > 0) {
        dropdown.style.display = 'block';
        filtered.forEach(client => {
            const div = document.createElement('div');
            div.className = 'p-3 cursor-pointer border-b border-slate-50 hover:bg-slate-50 text-right';
            div.innerHTML = `<div class="font-bold text-indigo-700 flex items-center gap-2">${client.name} <span class="bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded text-[10px]">${client.client_type || '-'}</span></div>
                             <div class="text-xs text-slate-500 mt-1 flex gap-3"><span>📱 ${client.phone}</span><span>📍 ${client.province}</span></div>`;
            div.onclick = function() {
                document.getElementById('clientName').value = client.name;
                document.getElementById('clientType').value = client.client_type || ''; 
                document.getElementById('phoneNumber').value = client.phone || '';
                document.getElementById('phone2').value = client.phone2 || ''; 
                document.getElementById('provinceName').value = client.province || '';
                document.getElementById('address').value = client.address || '';
                dropdown.style.display = 'none';
            };
            dropdown.appendChild(div);
        });
    } else { dropdown.style.display = 'none'; }
}

function closeDropdown(e) {
    if (!e.target.closest('.search-container') && document.getElementById('clientDropdown')) {
        document.getElementById('clientDropdown').style.display = 'none';
    }
}

// === المكاتب ===
function fetchClientsFromServer() {
    fetch('get_clients.php').then(res => res.json()).then(data => {
        if(Array.isArray(data)) { currentClientsList = data; populateFilters(); renderClientsTable(); }
    });
}

function populateFilters() {
    let types = new Set(), provs = new Set();
    currentClientsList.forEach(c => { if(c.client_type) types.add(c.client_type); if(c.province) provs.add(c.province); });
    
    if(document.getElementById('filterType')) {
        document.getElementById('filterType').innerHTML = '<option value="">كل الأنواع</option>';
        document.getElementById('filterProvince').innerHTML = '<option value="">كل المحافظات</option>';
        types.forEach(t => document.getElementById('filterType').innerHTML += `<option value="${t}">${t}</option>`);
        provs.forEach(p => document.getElementById('filterProvince').innerHTML += `<option value="${p}">${p}</option>`);
    }
    
    const editTypeSelect = document.getElementById('edit_type');
    if(editTypeSelect) {
        editTypeSelect.innerHTML = '<option value="">غير محدد</option>';
        types.forEach(t => editTypeSelect.innerHTML += `<option value="${t}">${t}</option>`);
    }
}

function renderClientsTable() {
    const tbody = document.getElementById('clientsPreviewContainer');
    if(!tbody) return;
    const sq = document.getElementById('tableSearch').value.toLowerCase();
    const tq = document.getElementById('filterType').value;
    const pq = document.getElementById('filterProvince').value;

    let filtered = currentClientsList.filter(c => ultraSmartMatch(sq, `${c.name||''} ${c.phone||''} ${c.province||''}`) && (tq ? c.client_type === tq : true) && (pq ? c.province === pq : true));
    document.getElementById('clientCountBadge').innerText = `${filtered.length} مكتب`;

    if (filtered.length === 0) { tbody.innerHTML = `<tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">لا توجد نتائج مطابقة.</td></tr>`; return; }

    let html = '';
    filtered.forEach((client, idx) => {
        const safeData = JSON.stringify(client).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        html += `<tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 text-slate-400 font-medium">${idx + 1}</td>
                    <td class="px-4 py-3 font-bold text-indigo-700">${client.name}</td>
                    <td class="px-4 py-3"><span class="bg-indigo-50 border border-indigo-100 text-indigo-700 py-1 px-2 rounded font-bold text-[11px]">${client.client_type || '-'}</span></td>
                    <td class="px-4 py-3 text-slate-600">${client.province}</td>
                    <td class="px-4 py-3 text-slate-600 font-mono text-sm" dir="ltr"><div class="text-right">${client.phone}</div><div class="text-xs text-slate-400">${client.phone2 || ''}</div></td>
                    <td class="px-4 py-3 text-slate-500 text-xs max-w-[200px] truncate" title="${client.address || ''}">${client.address || '-'}</td>
                    <td class="px-4 py-3 text-center"><button data-client="${safeData}" onclick="openEditModal(this)" class="text-indigo-600 bg-indigo-50 px-3 py-1.5 rounded-lg text-xs font-bold">تعديل</button></td>
                 </tr>`;
    });
    tbody.innerHTML = html;
}

// === التعديل ===
function openEditModal(btn) {
    const client = JSON.parse(btn.getAttribute('data-client'));
    document.getElementById('edit_id').value = client.id;
    document.getElementById('edit_name').value = client.name;
    document.getElementById('edit_type').value = client.client_type || '';
    document.getElementById('edit_province').value = client.province || 'بغداد';
    document.getElementById('edit_phone').value = client.phone;
    document.getElementById('edit_phone2').value = client.phone2 || '';
    document.getElementById('edit_address').value = client.address || '';
    document.getElementById('editModal').classList.remove('hidden'); document.getElementById('editModal').classList.add('flex');
}
function closeModal(id) { document.getElementById(id).classList.add('hidden'); document.getElementById(id).classList.remove('flex'); }

function saveClientEdit() {
    const btn = document.getElementById('btnSaveEdit');
    btn.innerHTML = 'جاري الحفظ...'; btn.disabled = true;
    const data = {
        id: document.getElementById('edit_id').value,
        name: document.getElementById('edit_name').value,
        client_type: document.getElementById('edit_type').value,
        province: document.getElementById('edit_province').value,
        phone: document.getElementById('edit_phone').value,
        phone2: document.getElementById('edit_phone2').value,
        address: document.getElementById('edit_address').value
    };
    fetch('update_client.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) })
    .then(res => res.json()).then(res => {
        if(res.success) { 
            closeModal('editModal'); 
            fetchClientsFromServer(); 
            Swal.fire({icon: 'success', title: 'نجاح', text: 'تم تعديل بيانات المكتب بنجاح!', timer: 2000, showConfirmButton: false});
        } else { 
            Swal.fire({icon: 'error', title: 'خطأ', text: res.error}); 
        }
    }).finally(() => { btn.innerHTML = 'حفظ التعديلات'; btn.disabled = false; });
}

// === الطلبات والرفع ===
function processOrder() {
    const orderData = {
        clientName: document.getElementById('clientName').value.trim(),
        cartonCount: document.getElementById('cartonCount').value,
        phoneNumber: document.getElementById('phoneNumber').value.trim(),
        phone2: document.getElementById('phone2').value.trim(),
        provinceName: document.getElementById('provinceName').value.trim(),
        amount: document.getElementById('amount').value.replace(/,/g, ''), // تنظيف الفواصل قبل الإرسال للسيرفر
        receiptNo: document.getElementById('receiptNo').value.trim(),
        address: document.getElementById('address').value.trim()
    };

    if (!orderData.clientName || !orderData.cartonCount || !orderData.phoneNumber || !orderData.provinceName || orderData.amount === "" || !orderData.address) {
        Swal.fire({icon: 'warning', title: 'تنبيه', text: 'يرجى ملء كافة الحقول الإجبارية المميزة بنجمة حمراء.'}); return;
    }

    const btn = document.getElementById('submitBtn');
    btn.innerHTML = '⏳ جاري الحفظ والمعالجة...'; btn.disabled = true;

    fetch('submit_order.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(orderData) })
    .then(res => res.json()).then(data => {
        if(data.success) { 
            resetForm(); 
            Swal.fire({icon: 'success', title: 'نجاح', text: 'تمت العملية بنجاح!', timer: 2000, showConfirmButton: false});
        } 
        else { Swal.fire({icon: 'error', title: 'خطأ', text: data.error}); }
    }).finally(() => { btn.innerHTML = 'إرسال الطلب'; btn.disabled = false; });
}

function generateExcelFile(o) {
    const notes = `عدد الكراتين الكلي للمكتبة ( ${o.cartonCount} )`;
    const headers = [ "ملاحظات", "عدد القطع\nأجباري", "يحتوي على ارجاع بضاعة؟", "هاتف المستلم\nأجباري 11 خانة", "تفاصيل العنوان\nأجباري", "شفرة المحافظة\nأجباري", "أسم المستلم", "المبلغ عراقي\nكامل بالالاف .\nفي حال عدم توفره سيعتبر 0", "رقم الوصل \nفي حال عدم وجود رقم وصل سيتم توليده من النظام", "كود الشحنة", "هاتف المستلم 2\n", "نوع البضاعة", "وصف البضاعة المسترجعة اوالمستبدلة" ];
    const finalRows = [headers];
    for (let i = 0; i < o.cartonCount; i++) {
        finalRows.push([ notes, 1, "Y", o.phoneNumber, o.address, o.provinceName, o.clientName, Number(o.amount), o.receiptNo, "", o.phone2, "ملازم", "" ]);
    }
    const wb = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(finalRows), "Sheet1");
    XLSX.writeFile(wb, `شحنة_${o.clientName}_${o.cartonCount}_كارتونة.xlsx`);
}

function fetchOrdersFromServer() {
    fetch('get_orders.php').then(res => res.json()).then(data => {
        currentOrdersList = data;
        // تعيين فلتر التاريخ الافتراضي ليومنا هذا إن لم يكن معيناً
        if(!document.getElementById('order-date-filter').value) {
            document.getElementById('order-date-filter').value = new Date().toISOString().split('T')[0];
        }
        renderOrdersTable();
    });
}

function renderOrdersTable() {
    const tbody = document.getElementById('ordersPreviewContainer');
    if(!tbody) return;
    const filterDate = document.getElementById('order-date-filter').value;
    
    let filtered = currentOrdersList;
    if(filterDate) {
        filtered = currentOrdersList.filter(o => o.created_at.startsWith(filterDate));
    }
    
    if(filtered.length === 0) { tbody.innerHTML = `<tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">لا توجد طلبات مطابقة في هذا التاريخ.</td></tr>`; return; }
    
    let html = '';
    filtered.forEach(order => {
        const safeOrderData = JSON.stringify(order).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        
        html += `<tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 font-mono text-slate-500">#${order.id}</td>
                    <td class="px-4 py-3 font-bold text-slate-700">${order.client_name}</td>
                    <td class="px-4 py-3 text-slate-600">${order.carton_count} كارتونة</td>
                    <td class="px-4 py-3 text-indigo-600 font-bold">${formatNumStr(order.amount)} د.ع</td>
                    <td class="px-4 py-3 text-slate-500 text-xs" dir="ltr">${order.created_at}</td>
                    <td class="px-4 py-3 text-center flex justify-center gap-1.5 flex-wrap">
                        <button onclick="openViewOrderModal('${safeOrderData}')" class="text-blue-600 hover:text-blue-800 bg-blue-50 hover:bg-blue-100 px-3 py-1 rounded text-xs font-bold transition">استعراض 👁️</button>
                        <button onclick="exportSingleOrder('${safeOrderData}')" class="text-emerald-600 hover:text-emerald-800 bg-emerald-50 hover:bg-emerald-100 px-3 py-1 rounded text-xs font-bold transition">تصدير</button>
                        <button onclick="deleteOrder(${order.id})" class="text-red-500 hover:text-red-700 bg-red-50 hover:bg-red-100 px-3 py-1 rounded text-xs font-bold transition">حذف 🗑️</button>
                    </td>
                 </tr>`;
    });
    tbody.innerHTML = html;
}

function deleteOrder(id) {
    Swal.fire({
        title: 'هل أنت متأكد؟',
        text: 'لن تتمكن من استرجاع هذا الطلب!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#94a3b8',
        confirmButtonText: 'نعم، احذف',
        cancelButtonText: 'إلغاء'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('delete_order.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: id}) })
            .then(res => res.json()).then(data => {
                if(data.success) { fetchOrdersFromServer(); Swal.fire({icon: 'success', title: 'تم الحذف', text: 'تم حذف الطلب بنجاح.', timer: 2000, showConfirmButton: false}); }
                else Swal.fire({icon: 'error', title: 'خطأ', text: data.error});
            });
        }
    });
}

function exportOrdersToExcel() {
    if(!currentOrdersList || currentOrdersList.length === 0) { Swal.fire({icon: 'warning', title: 'تنبيه', text: 'لا يوجد سجل طلبات لتصديره'}); return; }
    const rows = [["رقم الطلب", "المكتب", "الكراتين", "المبلغ", "تاريخ الطلب"]];
    currentOrdersList.forEach(o => rows.push([o.id, o.client_name, o.carton_count, formatNumStr(o.amount), o.created_at]));
    const wb = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(rows), "الطلبات");
    XLSX.writeFile(wb, `سجل_الطلبات.xlsx`);
}

function handleExcelImport(event) {
    const file = event.target.files[0];
    if (!file) return;
    const btn = document.getElementById('btnImport'); const original = btn.innerHTML; btn.innerHTML = '⏳ جاري المعالجة...';
    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const jsonRows = XLSX.utils.sheet_to_json(XLSX.read(new Uint8Array(e.target.result), { type: 'array' }).Sheets[workbook.SheetNames[0]], { header: 1 });
            let typeIdx = 1, provIdx = 2, nameIdx = 3, phoneIdx = 4, addrIdx = 5, phone2Idx = 6;
            const importedList = [];
            const startIdx = (jsonRows[0] && jsonRows[0][nameIdx] && isNaN(jsonRows[0][nameIdx])) ? 1 : 0;
            for (let r = startIdx; r < jsonRows.length; r++) {
                if (!jsonRows[r] || !jsonRows[r][nameIdx]) continue;
                importedList.push({ client_type: jsonRows[r][typeIdx]||'', name: String(jsonRows[r][nameIdx]).trim(), phone: jsonRows[r][phoneIdx]||'', phone2: jsonRows[r][phone2Idx]||'', province: jsonRows[r][provIdx]||'', address: jsonRows[r][addrIdx]||'' });
            }
            if (importedList.length > 0) {
                btn.innerHTML = '⏳ جاري الرفع...';
                fetch('save_clients.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(importedList) })
                .then(res => res.json()).then(res => { if(res.success) { Swal.fire({icon: 'success', title: 'نجاح', text: 'تم استيراد المكاتب بنجاح!', timer: 2000, showConfirmButton: false}); fetchClientsFromServer(); } else Swal.fire({icon: 'error', title: 'خطأ', text: 'حدث خطأ أثناء الاستيراد'}); }).finally(() => btn.innerHTML = original);
            }
        } catch (error) { Swal.fire({icon: 'error', title: 'خطأ', text: 'خطأ في الملف.'}); btn.innerHTML = original; }
        event.target.value = '';
    };
    reader.readAsArrayBuffer(file);
}

function exportClientsToExcel() {
    if (currentClientsList.length === 0) return;
    const rows = [["التسلسل", "نوع العميل", "المحافظة", "أسم المكتب", "رقم الهاتف", "العنوان التفصيلي", "رقم الهاتف 2"]];
    currentClientsList.forEach((c, idx) => rows.push([idx + 1, c.client_type||'', c.province, c.name, c.phone, c.address||'', c.phone2||'']));
    const wb = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(rows), "المكاتب");
    XLSX.writeFile(wb, `بيانات_المكاتب.xlsx`);
}

function exportSingleOrder(orderJson) {
    const order = JSON.parse(orderJson);
    
    // استرجاع الثوابت والملاحظات
    const notes = `عدد الكراتين الكلي للمكتبة ( ${order.carton_count} )`;
    const goodsType = "ملازم";
    const piecesCount = 1;
    const hasReturn = "Y";
    const phone2 = ""; // يمكن تركه فارغاً في الاسترجاع
    const shipmentCode = "";
    const returnDescription = "";

    // الترويسة المعتمدة
    const headers = [ "ملاحظات", "عدد القطع\nأجباري", "يحتوي على ارجاع بضاعة؟", "هاتف المستلم\nأجباري 11 خانة", "تفاصيل العنوان\nأجباري", "شفرة المحافظة\nأجباري", "أسم المستلم", "المبلغ عراقي\nكامل بالالاف .\nفي حال عدم توفره سيعتبر 0", "رقم الوصل \nفي حال عدم وجود رقم وصل سيتم توليده من النظام", "كود الشحنة", "هاتف المستلم 2\n", "نوع البضاعة", "وصف البضاعة المسترجعة اوالمستبدلة" ];
    
    const finalRows = [headers];
    
    // تكرار الأسطر حسب عدد الكراتين المحفوظ بالطلب
    for (let i = 0; i < order.carton_count; i++) {
        finalRows.push([
            notes, 
            piecesCount, 
            hasReturn, 
            order.phone, 
            order.address, 
            order.province, 
            order.client_name, 
            Number(order.amount), 
            order.receipt_no || "", 
            shipmentCode, 
            phone2, 
            goodsType, 
            returnDescription
        ]);
    }
    
    // توليد وتحميل الملف
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(finalRows), "Sheet1");
    XLSX.writeFile(wb, `إعادة_تصدير_${order.client_name}_${order.carton_count}_كارتونة.xlsx`);
}

function openViewOrderModal(orderJson) {
    const o = JSON.parse(orderJson);
    document.getElementById('view_order_id').innerText = '#' + o.id;
    document.getElementById('view_client_name').innerText = o.client_name;
    document.getElementById('view_carton_count').innerText = o.carton_count + ' كارتونة';
    document.getElementById('view_amount').innerText = formatNumStr(o.amount) + ' د.ع';
    document.getElementById('view_created_at').innerText = o.created_at;
    document.getElementById('view_province').innerText = o.province;
    document.getElementById('view_phone').innerText = o.phone;
    document.getElementById('view_address').innerText = o.address;
    document.getElementById('view_receipt_no').innerText = o.receipt_no || 'لم يتم إدخال رقم وصل';
    
    document.getElementById('modal-view-order').classList.remove('hidden');
    document.getElementById('modal-view-order').classList.add('flex');
}

function openEditUserModal(id, username, role) {
    document.getElementById('edit_user_id').value = id;
    document.getElementById('edit_username').value = username;
    document.getElementById('edit_role').value = role;
    document.getElementById('edit_password').value = '';
    document.getElementById('editUserModal').classList.remove('hidden');
    document.getElementById('editUserModal').classList.add('flex');
}

function fetchLogsFromServer() {
    fetch('get_logs.php').then(res => res.json()).then(data => {
        currentLogsList = data;
        populateLogFilters();
        renderLogsTable();
    }).catch(err => console.error(err));
}

function populateLogFilters() {
    let users = new Set();
    currentLogsList.forEach(log => { if(log.username) users.add(log.username); });
    const userFilter = document.getElementById('log-user-filter');
    if(userFilter) {
        userFilter.innerHTML = '<option value="">كل الموظفين</option>';
        users.forEach(u => userFilter.innerHTML += `<option value="${u}">${u}</option>`);
    }
}

function renderLogsTable() {
    const tbody = document.getElementById('logsPreviewContainer');
    if(!tbody) return;
    if(!currentLogsList || currentLogsList.length === 0) { tbody.innerHTML = `<tr><td colspan="3" class="px-4 py-8 text-center text-slate-500">لا توجد حركات مسجلة.</td></tr>`; return; }
    
    const userQ = document.getElementById('log-user-filter').value;
    const actionQ = document.getElementById('log-action-filter').value.toLowerCase();
    const dateQ = document.getElementById('log-date-filter').value;

    let filtered = currentLogsList.filter(log => {
        const matchUser = userQ ? log.username === userQ : true;
        const matchAction = actionQ ? (log.action_details && log.action_details.toLowerCase().includes(actionQ)) : true;
        const matchDate = dateQ ? log.created_at.startsWith(dateQ) : true;
        return matchUser && matchAction && matchDate;
    });

    if (filtered.length === 0) { tbody.innerHTML = `<tr><td colspan="3" class="px-4 py-8 text-center text-slate-500">لا توجد نتائج مطابقة للفلاتر.</td></tr>`; return; }
        
    let html = '';
    filtered.forEach(log => {
        html += `<tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 text-slate-500 text-xs font-mono" dir="ltr">${log.created_at}</td>
                    <td class="px-4 py-3 font-bold text-indigo-700">${log.username}</td>
                    <td class="px-4 py-3 text-slate-700">${log.action_details}</td>
                 </tr>`;
    });
    tbody.innerHTML = html;
}
</script>
</body>
</html>