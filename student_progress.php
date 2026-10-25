<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Get user info
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
$level = get_student_reading_level($pdo, $user_id, $user);

$mname = $user['mname'] ?? '';
$mi = !empty($mname) ? strtoupper(substr($mname, 0, 1)) . '.' : '';
$fullName = htmlspecialchars($user['fname'] . ' ' . $mi . ' ' . $user['lname']);

// Fetch attempts for charting and progress evaluation
$stmt = $pdo->prepare("SELECT id, created_at, phase, accuracy_score, comprehension_score, reading_speed, oral_reading_profile FROM reading_attempts WHERE user_id = ? ORDER BY created_at ASC");
$stmt->execute([$user_id]);
$attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$labels = [];
$accuracy_data = [];
$speed_data = [];
$wcpm_data = [];

foreach($attempts as $att) {
    $labels[] = date('M d', strtotime($att['created_at']));
    $acc = floatval($att['accuracy_score']);
    $spd = floatval($att['reading_speed']);
    $accuracy_data[] = $acc;
    $speed_data[] = $spd;
    $wcpm_data[] = round($spd * ($acc / 100));
}

// -------------------------------------------------------------
// Dynamic Motivational Message Logic (4 Scenarios)
// -------------------------------------------------------------
$has_pre = false;
$has_post = false;
foreach ($attempts as $att) {
    $p = $att['phase'] ?? '';
    if (in_array($p, ['Pre-Test', 'Course-Pre-Test'])) $has_pre = true;
    if (in_array($p, ['Post-Test', 'Course-Post-Test'])) $has_post = true;
}
$completed_pre_and_post = ($has_pre && $has_post);

$comp_gain = 0;
$acc_gain = 0;
$latest_acc = 0;
$latest_comp = 0;

if (count($attempts) >= 2) {
    $first_comp = floatval($attempts[0]['comprehension_score'] ?? 0);
    $latest_comp = floatval(end($attempts)['comprehension_score'] ?? 0);
    $comp_gain = $latest_comp - $first_comp;

    $first_acc = floatval($attempts[0]['accuracy_score'] ?? 0);
    $latest_acc = floatval(end($attempts)['accuracy_score'] ?? 0);
    $acc_gain = $latest_acc - $first_acc;
} elseif (count($attempts) === 1) {
    $latest_acc = floatval($attempts[0]['accuracy_score'] ?? 0);
    $latest_comp = floatval($attempts[0]['comprehension_score'] ?? 0);
}

// Scenario 1: For Completing the Pre-Test and Post-Test
// Scenario 2: For Improving Reading Comprehension
// Scenario 3: For Students with Great and Improving Reading Skills
// Scenario 4: For Students Who Need More Practice

if ($completed_pre_and_post && $level !== 'Independent') {
    $promo_title = "Great job completing your pre-test and post-test!";
    $promo_desc = "Keep showing up for your intervention classes and reading different passages. Every page you read brings you one step closer to becoming a more confident and skilled reader. Keep it up!";
    $promo_gradient = "from-emerald-600 to-teal-700";
    $promo_icon = "fa-graduation-cap";
    $promo_badge = "Milestone Achieved";
} elseif ($comp_gain > 0) {
    $promo_title = "You're making great progress in reading comprehension!";
    $promo_desc = "Keep practicing, challenging yourself with new passages, and discovering new ideas. You're getting better every day, so keep the momentum going!";
    $promo_gradient = "from-purple-600 to-indigo-700";
    $promo_icon = "fa-lightbulb";
    $promo_badge = "Comprehension Boost";
} elseif ($latest_acc >= 90 || $level === 'Independent' || ($acc_gain > 0 && $latest_acc >= 85)) {
    $promo_title = "Your reading skills are getting stronger!";
    $promo_desc = "Your reading skills are getting stronger, and that's something to be proud of! Keep reading different passages and sharpening your comprehension skills. Stay curious, stay focused, and keep reaching for new reading goals!";
    $promo_gradient = "from-blue-600 to-cyan-700";
    $promo_icon = "fa-chart-line";
    $promo_badge = "Skill Mastery";
} else {
    $promo_title = "Every great reader starts with practice!";
    $promo_desc = "Every great reader starts with practice, and you're on your way! Keep working on your reading skills, one passage at a time. Don't give up—every word you read and every lesson you learn brings you closer to success. You've got this!";
    $promo_gradient = "from-amber-600 to-orange-700";
    $promo_icon = "fa-book-reader";
    $promo_badge = "Keep Practicing";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="https://tibungcodistrict.wordpress.com/wp-content/uploads/2018/06/untitled-1.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Progress - Phil-IRI</title>
    <script>
        const originalWarn = console.warn;
        console.warn = function() {
            if (arguments[0] && typeof arguments[0] === 'string' && arguments[0].includes('cdn.tailwindcss.com should not be used in production')) return;
            originalWarn.apply(console, arguments);
        };
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        .border-slate-200, .border-slate-100, .border-b { border-color: #334155 !important; }
        .border-slate-300 { border-color: #475569 !important; }
        .sidebar { background-color: #0b1120 !important; border-right: 1px solid #1e293b !important; }
        input, select, textarea { background-color: #0f172a !important; color: white !important; border-color: #475569 !important; }
        .shadow-sm { box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.5) !important; }

        /* Colored Badges / Cards Fixes */
        .bg-blue-50, .bg-blue-100 { background-color: rgba(59, 130, 246, 0.2) !important; color: #93c5fd !important; }
        .text-blue-600, .text-blue-700, .text-blue-800 { color: #60a5fa !important; }
        .border-blue-100, .border-blue-200 { border-color: rgba(59, 130, 246, 0.3) !important; }

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
        
        
        /* Bug Fixes for "Not Taken", "Pre-Test", and Table Hovers */
        .bg-slate-100, .bg-slate-200 { background-color: #334155 !important; color: #e2e8f0 !important; }
        .hover\:bg-slate-50:hover { background-color: #334155 !important; }
        tr:hover { background-color: #334155 !important; }
/* Logo Fix */
        .sidebar img { background-color: transparent !important; filter: drop-shadow(0px 0px 2px rgba(255,255,255,0.5)) !important; }
        <?php endif; ?>
    </style>




    <script src="js/htmx.min.js"></script>
    <meta name="htmx-config" content='{"globalViewTransitions":true}'>
</head>
<body class="flex h-screen overflow-hidden text-slate-800">
    <?php include 'student_sidebar.php'; ?>
    
    <!-- Sidebar -->
    
    

    <!-- Main Content -->
    <div class="flex-1 flex flex-col h-screen overflow-hidden">
        
        <!-- Header -->
        <header class="h-20 bg-white border-b border-slate-200 flex items-center justify-between px-4 md:px-8 shrink-0">
            <div class="flex items-center">
                <button onclick="toggleSidebar()" class="md:hidden mr-4 text-slate-500 hover:text-blue-600 focus:outline-none"><i class="fas fa-bars text-xl"></i></button>
                <div>
                <h2 class="font-bold text-2xl text-slate-800">My Progress</h2>
                <p class="text-sm text-slate-500">Track your reading improvement over time.</p>
            </div>
            </div>
            <div class="flex items-center space-x-3">
                <?php 
                $avatarPath = 'uploads/profiles/user_' . $_SESSION['user_id'] . '.jpg';
                if (file_exists($avatarPath)): 
                ?>
                    <img src="<?php echo $avatarPath; ?>?t=<?php echo time(); ?>" class="w-10 h-10 rounded-full object-cover border border-slate-200">
                <?php else: ?>
                    <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 keep-colors font-bold">
                        <i class="fas fa-user"></i>
                    </div>
                <?php endif; ?>
                <span class="font-semibold text-sm"><?php echo $fullName; ?></span>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-4 md:p-8 bg-slate-50">
            
            <?php $target_user_id = $user_id; include 'comparison_widget.php'; ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 md:p-8">
                <!-- Accuracy Chart -->
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                    <h3 class="font-bold text-slate-700 mb-4 text-sm flex items-center"><i class="fas fa-crosshairs mr-2 text-blue-500"></i> Reading Accuracy Trend</h3>
                    <div style="height: 300px;">
                        <canvas id="accuracyChart"></canvas>
                    </div>
                </div>

                <!-- Speed Chart -->
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                    <h3 class="font-bold text-slate-700 mb-4 text-sm flex items-center"><i class="fas fa-stopwatch mr-2 text-purple-500"></i> Reading Speed (WPM)</h3>
                    <div style="height: 300px;">
                        <canvas id="speedChart"></canvas>
                    </div>
                </div>

                <!-- WCPM Chart -->
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 md:col-span-2 lg:col-span-1">
                    <h3 class="font-bold text-slate-700 mb-4 text-sm flex items-center"><i class="fas fa-tachometer-alt mr-2 text-emerald-500"></i> WCPM Trend</h3>
                    <div style="height: 300px;">
                        <canvas id="wcpmChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Keep Reading Promo (Dynamic Motivation) -->
            <div class="mt-8 bg-gradient-to-r <?php echo $promo_gradient; ?> rounded-xl shadow-md p-6 md:p-8 text-white flex flex-col md:flex-row md:items-center justify-between gap-6 transition-all duration-300">
                <div class="max-w-2xl">
                    <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white/20 backdrop-blur-sm text-xs font-bold uppercase tracking-wider mb-3">
                        <i class="fas <?php echo $promo_icon; ?>"></i>
                        <span><?php echo $promo_badge; ?></span>
                    </div>
                    <h3 class="text-2xl font-bold mb-2 leading-snug"><?php echo $promo_title; ?></h3>
                    <p class="text-white/95 text-sm md:text-base leading-relaxed"><?php echo $promo_desc; ?></p>
                </div>
                <div class="text-6xl md:text-7xl opacity-25 shrink-0 self-end md:self-center">
                    <i class="fas <?php echo $promo_icon; ?>"></i>
                </div>
            </div>

        </main>
    </div>

    <script>
    {
const labels = <?php echo json_encode($labels); ?>;
    const accData = <?php echo json_encode($accuracy_data); ?>;
    const speedData = <?php echo json_encode($speed_data); ?>;
    const wcpmData = <?php echo json_encode($wcpm_data); ?>;

    if (labels.length > 0) {
        new Chart(document.getElementById('accuracyChart'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Accuracy %',
                    data: accData,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { min: 0, max: 100 } }
            }
        });

        new Chart(document.getElementById('speedChart'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Words Per Minute',
                    data: speedData,
                    borderColor: '#8b5cf6',
                    backgroundColor: 'rgba(139, 92, 246, 0.1)',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { min: 0 } }
            }
        });

        new Chart(document.getElementById('wcpmChart'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Words Correct Per Minute',
                    data: wcpmData,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { min: 0 } }
            }
        });
    }
}
    </script>

    <script>
        window.toggleSidebar = function() {
            const sidebar = document.getElementById('appSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if(sidebar) sidebar.classList.toggle('-translate-x-full');
            if(overlay) overlay.classList.toggle('hidden');
        }
    </script>
</body>
</html>
