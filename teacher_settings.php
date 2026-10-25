<?php
session_start();
require_once 'config.php';

// RBAC Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: index.php");
    exit;
}

$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $keys = [
        'asr_enabled', 
        'azure_openai_key', 
        'openai_model',
        'azure_speech_key',
        'azure_region', 
        'azure_language',
        'smtp_host',
        'smtp_port',
        'smtp_user',
        'smtp_pass',
        'smtp_from_email',
        'smtp_from_name'
    ];
    
    // Save to DB
    foreach ($keys as $key) {
        $val = $_POST[$key] ?? ($key === 'asr_enabled' ? '1' : '');
        if ($key === 'asr_enabled' && $val === '') {
            $val = '1';
        }
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = :v2");
        $stmt->execute(['k' => $key, 'v' => $val, 'v2' => $val]);
    }

    // Save sections per grade (Grades 7, 8, 9, 10)
    $posted_sections = [];
    if (isset($_POST['sections']) && is_array($_POST['sections'])) {
        $posted_sections = $_POST['sections'];
    } elseif (!empty($_POST['sections_json'])) {
        $decoded = json_decode($_POST['sections_json'], true);
        if (is_array($decoded)) {
            $posted_sections = $decoded;
        }
    }
    save_sections_by_grade($pdo, $posted_sections);
    
    // Generate .env file for local fallback compatibility
    $env_content = "AZURE_OPENAI_API_KEY=" . ($_POST['azure_openai_key'] ?? '') . "\n";
    $env_content .= "AZURE_SPEECH_KEY=" . ($_POST['azure_speech_key'] ?? '') . "\n";
    $env_content .= "AZURE_SPEECH_REGION=" . ($_POST['azure_region'] ?? '') . "\n";
    $env_content .= "AZURE_LANGUAGE=" . ($_POST['azure_language'] ?? '') . "\n";
    $env_content .= "OPENAI_MODEL=" . ($_POST['openai_model'] ?? '') . "\n";
    
    @file_put_contents(__DIR__ . '/v536/.env', $env_content);
    
    $has_openai_key = !empty($_POST['azure_openai_key']);
    $has_speech_key = !empty($_POST['azure_speech_key']);
    $has_region = !empty($_POST['azure_region']);
    $req_met = $has_openai_key && $has_speech_key && $has_region;

    if ($req_met) {
        $success = "Settings saved successfully.";
    } else {
        $success = "Settings saved. Please provide the missing API keys.";
    }

}

// Fetch current settings
$stmt = $pdo->query("SELECT * FROM settings");
$settings_raw = $stmt->fetchAll();
$settings = [];
foreach($settings_raw as $s) {
    $settings[$s['setting_key']] = $s['setting_value'];
}
$sections_by_grade = get_sections_by_grade($pdo);

$has_openai_key = !empty($settings['azure_openai_key']);
$has_speech_key = !empty($settings['azure_speech_key']);
$has_region = !empty($settings['azure_region']);

$requirements_met = $has_openai_key && $has_speech_key && $has_region;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="https://tibungcodistrict.wordpress.com/wp-content/uploads/2018/06/untitled-1.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ASR Settings - Teacher Dashboard</title>
    <script>
        const originalWarn = console.warn;
        console.warn = function() {
            if (arguments[0] && typeof arguments[0] === 'string' && arguments[0].includes('cdn.tailwindcss.com should not be used in production')) return;
            originalWarn.apply(console, arguments);
        };
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #F8FAFC; }
            .sidebar { background-color: #1a365d; }
        <?php $is_dark = isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark'; ?>
        <?php if($is_dark): ?>
        /* Refined Slate Dark Mode */
        body { background-color: #0f172a !important; color: #f8fafc !important; }
        .bg-white, .bg-slate-50 { background-color: #1e293b !important; border-color: #334155 !important; color: #f8fafc !important; }
        
        .text-slate-800, .text-slate-700 { color: #f1f5f9 !important; }
        .text-slate-600, .text-slate-500, .text-slate-400 { color: #cbd5e1 !important; }
        .border-slate-200, .border-slate-100, .border-b, .border-l { border-color: #334155 !important; }
        .border-slate-300 { border-color: #475569 !important; }
        .sidebar { background-color: #0b1120 !important; border-right: 1px solid #1e293b !important; }
        input, select, textarea { background-color: #0f172a !important; color: white !important; border-color: #475569 !important; }
        .shadow-sm { box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.5) !important; }

        /* Colored Badges / Cards Fixes */
        .bg-blue-50, .bg-blue-100 { background-color: rgba(59, 130, 246, 0.2) !important; color: #93c5fd !important; }
        .text-blue-600, .text-blue-700, .text-blue-800 { color: #60a5fa !important; }
        .border-blue-100, .border-blue-200, .border-l-blue-500 { border-color: rgba(59, 130, 246, 0.3) !important; }

        .bg-emerald-50, .bg-emerald-100 { background-color: rgba(16, 185, 129, 0.2) !important; color: #6ee7b7 !important; }
        .text-emerald-600, .text-emerald-700, .text-emerald-800 { color: #34d399 !important; }
        .border-emerald-100, .border-emerald-200 { border-color: rgba(16, 185, 129, 0.3) !important; }

        .bg-amber-50, .bg-amber-100 { background-color: rgba(245, 158, 11, 0.2) !important; color: #fcd34d !important; }
        .text-amber-600, .text-amber-700, .text-amber-800 { color: #fbbf24 !important; }
        .border-amber-100, .border-amber-200 { border-color: rgba(245, 158, 11, 0.3) !important; }

        .bg-rose-50, .bg-rose-100 { background-color: rgba(244, 63, 94, 0.2) !important; color: #fda4af !important; }
        .text-rose-600, .text-rose-700, .text-rose-800 { color: #fb7185 !important; }
        .border-rose-100, .border-rose-200 { border-color: rgba(244, 63, 94, 0.3) !important; }
        
        .bg-purple-50, .bg-purple-100 { background-color: rgba(168, 85, 247, 0.2) !important; color: #d8b4fe !important; }
        .text-purple-600, .text-purple-700, .text-purple-800 { color: #c084fc !important; }

        /* Bug Fixes for hover states and cards */
        .bg-slate-100, .bg-slate-200 { background-color: #334155 !important; color: #e2e8f0 !important; }
        .hover\:bg-slate-50:hover, tr:hover { background-color: #334155 !important; }
        .card { background-color: #1e293b !important; border-color: #334155 !important; }
        
        /* Logo Fix */
        .sidebar img { background-color: transparent !important; filter: drop-shadow(0px 0px 2px rgba(255,255,255,0.5)) !important; }
        <?php endif; ?>
    </style>


    <script src="js/htmx.min.js"></script>
    <meta name="htmx-config" content='{"globalViewTransitions":true}'>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="flex h-screen overflow-hidden text-slate-800">
    <?php include 'teacher_sidebar.php'; ?>
    <div class="flex-1 flex flex-col h-screen overflow-hidden">
    
    <!-- Top Navbar -->
    <header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-4 md:px-8 shrink-0">
        <div class="flex items-center">
            <a href="dashboard_teacher.php" class="text-slate-400 hover:text-slate-600 mr-4"><i class="fas fa-arrow-left"></i></a>
            <i class="fas fa-cog text-slate-500 text-2xl mr-3"></i>
            <h1 class="font-bold text-lg text-slate-800">ASR & API Settings</h1>
        </div>
    </header>

    <main class="flex-1 p-4 md:p-8 max-w-4xl mx-auto w-full overflow-y-auto">
        <?php if($success): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-lg mb-6 flex items-start">
                <i class="fas fa-check-circle mt-0.5 mr-2"></i>
                <span><?php echo $success; ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="" autocomplete="off" class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 md:p-8 space-y-6">
            <?php if (!$requirements_met): ?>
                <div class="bg-amber-50 border border-amber-200 text-amber-700 px-4 py-3 rounded-lg flex items-start text-sm">
                    <i class="fas fa-exclamation-triangle mt-0.5 mr-2"></i>
                    <div>
                        <span class="font-bold">Missing API Configuration:</span> Reading assessments require the following credentials to be configured:
                        <ul class="list-disc ml-5 mt-1 text-xs">
                            <?php if (!$has_openai_key): ?><li>OpenAI API Key</li><?php endif; ?>
                            <?php if (!$has_speech_key): ?><li>Azure Speech API Key</li><?php endif; ?>
                            <?php if (!$has_region): ?><li>Azure Region</li><?php endif; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <h3 class="font-bold text-slate-800 text-lg mb-2"><i class="fas fa-robot text-blue-500 mr-2"></i> OpenAI Configuration</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-slate-700 mb-1">OpenAI API Key</label>
                    <input type="text" name="azure_openai_key" autocomplete="off" style="-webkit-text-security: disc;" value="<?php echo htmlspecialchars($settings['azure_openai_key'] ?? ''); ?>" placeholder="sk-proj-..." class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">OpenAI Realtime Model</label>
                    <input type="text" name="openai_model" value="<?php echo htmlspecialchars($settings['openai_model'] ?? 'gpt-4o-realtime-preview-2024-10-01'); ?>" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>

            <h3 class="font-bold text-slate-800 text-lg mb-2 mt-8"><i class="fas fa-cloud text-blue-500 mr-2"></i> Azure Speech SDK Configuration</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Azure Speech API Key</label>
                    <input type="text" name="azure_speech_key" autocomplete="off" style="-webkit-text-security: disc;" value="<?php echo htmlspecialchars($settings['azure_speech_key'] ?? ''); ?>" placeholder="Enter Azure Speech Resource Key..." class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Azure Region</label>
                    <input type="text" name="azure_region" value="<?php echo htmlspecialchars($settings['azure_region'] ?? 'eastus'); ?>" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Azure Language</label>
                    <input type="text" name="azure_language" value="en-US" readonly class="w-full px-4 py-2 border border-slate-200 bg-slate-50 text-slate-500 rounded-lg cursor-not-allowed">
                    <p class="text-[10px] text-slate-400 mt-1">Must be en-US for this deployment.</p>
                </div>
            </div>

            <!-- Email & SMTP Configuration for Password Reset -->
            <div class="border-t border-slate-200 pt-6 mt-8">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="font-bold text-slate-800 text-lg flex items-center">
                            <i class="fas fa-envelope-open-text text-blue-500 mr-2"></i> SMTP Email Configuration (Forgot Password)
                        </h3>
                        <p class="text-xs text-slate-500 mt-0.5">Configure transactional email (Brevo / SendGrid / Mailgun) or your custom domain email (e.g., admin@aral-reading.tech) for password reset links.</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 bg-slate-50/70 p-5 rounded-xl border border-slate-200">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-slate-700 mb-1">SMTP Host</label>
                        <input type="text" name="smtp_host" value="<?php echo htmlspecialchars($settings['smtp_host'] ?? ''); ?>" 
                            placeholder="e.g. smtp-relay.brevo.com or mail.aral-reading.tech" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">SMTP Port</label>
                        <input type="number" name="smtp_port" value="<?php echo htmlspecialchars($settings['smtp_port'] ?? '587'); ?>" 
                            placeholder="587 or 465" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 text-sm">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-slate-700 mb-1">SMTP Username / API Key</label>
                        <input type="text" name="smtp_user" autocomplete="username" value="<?php echo htmlspecialchars($settings['smtp_user'] ?? ''); ?>" 
                            placeholder="e.g. admin@aral-reading.tech or Brevo SMTP Login" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">SMTP Password / Secret</label>
                        <input type="password" name="smtp_pass" autocomplete="new-password" value="<?php echo htmlspecialchars($settings['smtp_pass'] ?? ''); ?>" 
                            placeholder="SMTP Password or Master API Key" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Sender Email ("From")</label>
                        <input type="email" name="smtp_from_email" value="<?php echo htmlspecialchars($settings['smtp_from_email'] ?? ''); ?>" 
                            placeholder="e.g. noreply@aral-reading.tech" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 text-sm">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-slate-700 mb-1">Sender Name</label>
                        <input type="text" name="smtp_from_name" value="<?php echo htmlspecialchars($settings['smtp_from_name'] ?? 'ARAL Reading Assessment'); ?>" 
                            placeholder="e.g. ARAL Reading Assessment" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 text-sm">
                    </div>
                </div>
            </div>

            
            <!-- Student Sections Manager -->
            <div class="border-t border-slate-200 pt-6 mt-8">
                <div class="flex flex-col md:flex-row md:items-center justify-between mb-4">
                    <div>
                        <h3 class="font-bold text-slate-800 text-lg flex items-center">
                            <i class="fas fa-users-cog text-blue-500 mr-2"></i> Student Sections by Grade Level
                        </h3>
                        <p class="text-xs text-slate-500 mt-0.5">Manage sections for Grades 7 to 10. Students will only see sections for their selected grade level during registration.</p>
                    </div>
                    <div class="mt-2 md:mt-0 text-xs font-semibold text-slate-500">
                        <span class="inline-flex items-center px-2.5 py-1 bg-blue-50 text-blue-700 rounded-full border border-blue-200">
                            <i class="fas fa-layer-group mr-1.5"></i> Grades 7, 8, 9, 10
                        </span>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <?php 
                    $grades = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10'];
                    $gradeColors = [
                        'Grade 7' => ['badge' => 'bg-blue-100 text-blue-700 border-blue-200', 'btn' => 'bg-blue-600 hover:bg-blue-700'],
                        'Grade 8' => ['badge' => 'bg-indigo-100 text-indigo-700 border-indigo-200', 'btn' => 'bg-indigo-600 hover:bg-indigo-700'],
                        'Grade 9' => ['badge' => 'bg-emerald-100 text-emerald-700 border-emerald-200', 'btn' => 'bg-emerald-600 hover:bg-emerald-700'],
                        'Grade 10' => ['badge' => 'bg-purple-100 text-purple-700 border-purple-200', 'btn' => 'bg-purple-600 hover:bg-purple-700'],
                    ];

                    foreach ($grades as $grade):
                        $gradeNum = preg_replace('/[^0-9]/', '', $grade);
                        $gradeKey = 'Grade_' . $gradeNum;
                        $secs = $sections_by_grade[$grade] ?? [];
                        $c = $gradeColors[$grade];
                    ?>
                    <div class="bg-slate-50/70 rounded-xl border border-slate-200 p-4 flex flex-col justify-between shadow-sm">
                        <div>
                            <!-- Grade Card Header -->
                            <div class="flex items-center justify-between pb-3 mb-3 border-b border-slate-200">
                                <div class="flex items-center space-x-2">
                                    <span class="w-7 h-7 rounded-lg <?php echo $c['badge']; ?> border font-bold flex items-center justify-center text-xs">
                                        <?php echo $gradeNum; ?>
                                    </span>
                                    <h4 class="font-bold text-sm text-slate-800"><?php echo $grade; ?></h4>
                                </div>
                                <span id="count_<?php echo $gradeKey; ?>" class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-slate-200 text-slate-600">
                                    <?php echo count($secs); ?> <?php echo count($secs) === 1 ? 'section' : 'sections'; ?>
                                </span>
                            </div>

                            <!-- Add Section Input -->
                            <div class="flex gap-1.5 mb-3">
                                <input type="text" id="input_<?php echo $gradeKey; ?>" 
                                    placeholder="Enter section name (e.g. <?php echo $gradeNum == 7 ? 'Rizal' : ($gradeNum == 8 ? 'Ruby' : ($gradeNum == 9 ? 'Phoenix' : 'Crimson')); ?>)..." 
                                    class="flex-1 px-3 py-1.5 text-xs border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none bg-white"
                                    onkeydown="if(event.key === 'Enter'){ event.preventDefault(); addSection('<?php echo $grade; ?>', '<?php echo $gradeKey; ?>'); }">
                                <button type="button" onclick="addSection('<?php echo $grade; ?>', '<?php echo $gradeKey; ?>')" 
                                    class="px-3 py-1.5 <?php echo $c['btn']; ?> text-white rounded-lg text-xs font-bold transition flex items-center gap-1 shrink-0 shadow-sm">
                                    <i class="fas fa-plus text-[10px]"></i> Add
                                </button>
                            </div>

                            <!-- Sections List -->
                            <div id="list_<?php echo $gradeKey; ?>" class="space-y-1.5 max-h-52 overflow-y-auto pr-0.5">
                                <?php if (empty($secs)): ?>
                                    <p class="empty-msg text-xs text-slate-400 italic py-4 text-center">No sections configured for <?php echo $grade; ?>.</p>
                                <?php else: ?>
                                    <?php foreach ($secs as $sec): ?>
                                    <div class="section-row flex items-center justify-between p-2 bg-white rounded-lg border border-slate-200 text-xs shadow-2xs group hover:border-slate-300 transition" data-section="<?php echo htmlspecialchars($sec); ?>">
                                        <div class="section-display flex items-center gap-2 flex-1 mr-2 overflow-hidden">
                                            <i class="fas fa-chalkboard text-slate-400 text-[11px]"></i>
                                            <span class="section-text font-semibold text-slate-700 truncate"><?php echo htmlspecialchars($sec); ?></span>
                                            <input type="hidden" name="sections[<?php echo $grade; ?>][]" value="<?php echo htmlspecialchars($sec); ?>">
                                        </div>
                                        <div class="section-actions flex items-center gap-1 shrink-0">
                                            <button type="button" onclick="startEditSection(this, '<?php echo $grade; ?>', '<?php echo $gradeKey; ?>')" title="Rename Section" class="w-6 h-6 rounded flex items-center justify-center text-slate-400 hover:text-blue-600 hover:bg-blue-50 transition">
                                                <i class="fas fa-pencil-alt text-[10px]"></i>
                                            </button>
                                            <button type="button" onclick="deleteSection(this, '<?php echo $grade; ?>', '<?php echo $gradeKey; ?>')" title="Remove Section" class="w-6 h-6 rounded flex items-center justify-center text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition">
                                                <i class="fas fa-trash-alt text-[10px]"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="pt-6 border-t mt-8 text-right">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 px-4 md:px-8 rounded-lg transition shadow-sm">Save Settings</button>
            </div>
        </form>
    </main>

    </div>
    <script>
        window.toggleSidebar = function() {
            const sidebar = document.getElementById('appSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if(sidebar) sidebar.classList.toggle('-translate-x-full');
            if(overlay) overlay.classList.toggle('hidden');
        };

        function updateCount(gradeKey) {
            const list = document.getElementById('list_' + gradeKey);
            const countBadge = document.getElementById('count_' + gradeKey);
            const rows = list.querySelectorAll('.section-row');
            const count = rows.length;
            countBadge.textContent = count + (count === 1 ? ' section' : ' sections');

            const emptyMsg = list.querySelector('.empty-msg');
            if (count === 0) {
                if (!emptyMsg) {
                    const p = document.createElement('p');
                    p.className = 'empty-msg text-xs text-slate-400 italic py-4 text-center';
                    p.textContent = 'No sections configured.';
                    list.appendChild(p);
                }
            } else if (emptyMsg) {
                emptyMsg.remove();
            }
        }

        function addSection(gradeName, gradeKey) {
            const input = document.getElementById('input_' + gradeKey);
            const val = input.value.trim();
            if (!val) {
                input.focus();
                return;
            }

            const list = document.getElementById('list_' + gradeKey);
            const existing = Array.from(list.querySelectorAll('input[type="hidden"]')).map(el => el.value.toLowerCase());
            if (existing.includes(val.toLowerCase())) {
                alert('Section "' + val + '" already exists in ' + gradeName + '.');
                input.focus();
                return;
            }

            const emptyMsg = list.querySelector('.empty-msg');
            if (emptyMsg) emptyMsg.remove();

            const row = document.createElement('div');
            row.className = 'section-row flex items-center justify-between p-2 bg-white rounded-lg border border-slate-200 text-xs shadow-2xs group hover:border-slate-300 transition';
            row.setAttribute('data-section', val);
            row.innerHTML = `
                <div class="section-display flex items-center gap-2 flex-1 mr-2 overflow-hidden">
                    <i class="fas fa-chalkboard text-slate-400 text-[11px]"></i>
                    <span class="section-text font-semibold text-slate-700 truncate">` + escapeHtml(val) + `</span>
                    <input type="hidden" name="sections[` + gradeName + `][]" value="` + escapeHtml(val) + `">
                </div>
                <div class="section-actions flex items-center gap-1 shrink-0">
                    <button type="button" onclick="startEditSection(this, '` + gradeName + `', '` + gradeKey + `')" title="Rename Section" class="w-6 h-6 rounded flex items-center justify-center text-slate-400 hover:text-blue-600 hover:bg-blue-50 transition">
                        <i class="fas fa-pencil-alt text-[10px]"></i>
                    </button>
                    <button type="button" onclick="deleteSection(this, '` + gradeName + `', '` + gradeKey + `')" title="Remove Section" class="w-6 h-6 rounded flex items-center justify-center text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition">
                        <i class="fas fa-trash-alt text-[10px]"></i>
                    </button>
                </div>
            `;

            list.appendChild(row);
            input.value = '';
            input.focus();
            updateCount(gradeKey);
        }

        function deleteSection(btn, gradeName, gradeKey) {
            const row = btn.closest('.section-row');
            const name = row.getAttribute('data-section');
            if (confirm('Are you sure you want to remove section "' + name + '" from ' + gradeName + '?')) {
                row.remove();
                updateCount(gradeKey);
            }
        }

        function startEditSection(btn, gradeName, gradeKey) {
            const row = btn.closest('.section-row');
            const displayDiv = row.querySelector('.section-display');
            const actionsDiv = row.querySelector('.section-actions');
            const currentName = row.getAttribute('data-section');

            displayDiv.classList.add('hidden');
            actionsDiv.classList.add('hidden');

            const editContainer = document.createElement('div');
            editContainer.className = 'edit-box flex items-center gap-1.5 w-full';
            editContainer.innerHTML = `
                <input type="text" class="edit-input flex-1 px-2 py-1 text-xs border border-blue-400 rounded focus:ring-1 focus:ring-blue-500 focus:outline-none font-semibold bg-white" value="` + escapeHtml(currentName) + `">
                <button type="button" class="btn-save-edit w-6 h-6 rounded bg-emerald-600 text-white flex items-center justify-center text-[10px] hover:bg-emerald-700 transition" title="Save">
                    <i class="fas fa-check"></i>
                </button>
                <button type="button" class="btn-cancel-edit w-6 h-6 rounded bg-slate-200 text-slate-600 flex items-center justify-center text-[10px] hover:bg-slate-300 transition" title="Cancel">
                    <i class="fas fa-times"></i>
                </button>
            `;

            row.appendChild(editContainer);
            const input = editContainer.querySelector('.edit-input');
            input.focus();
            input.select();

            const saveEdit = function() {
                const newVal = input.value.trim();
                if (!newVal) {
                    alert('Section name cannot be empty.');
                    input.focus();
                    return;
                }

                const list = document.getElementById('list_' + gradeKey);
                const existing = Array.from(list.querySelectorAll('.section-row'))
                    .filter(r => r !== row)
                    .map(r => r.getAttribute('data-section').toLowerCase());

                if (existing.includes(newVal.toLowerCase())) {
                    alert('Section "' + newVal + '" already exists in ' + gradeName + '.');
                    input.focus();
                    return;
                }

                row.setAttribute('data-section', newVal);
                displayDiv.querySelector('.section-text').textContent = newVal;
                displayDiv.querySelector('input[type="hidden"]').value = newVal;

                editContainer.remove();
                displayDiv.classList.remove('hidden');
                actionsDiv.classList.remove('hidden');
            };

            const cancelEdit = function() {
                editContainer.remove();
                displayDiv.classList.remove('hidden');
                actionsDiv.classList.remove('hidden');
            };

            editContainer.querySelector('.btn-save-edit').addEventListener('click', saveEdit);
            editContainer.querySelector('.btn-cancel-edit').addEventListener('click', cancelEdit);
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    saveEdit();
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    cancelEdit();
                }
            });
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
