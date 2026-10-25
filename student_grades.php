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

// Guard against Non-Reader and Pending students accessing the grades page
if ($level === 'Non-Reader' || $level === 'Pending') {
    header("Location: dashboard_student.php");
    exit;
}

$mname = $user['mname'] ?? '';
$mi = !empty($mname) ? strtoupper(substr($mname, 0, 1)) . '.' : '';
$fullName = htmlspecialchars($user['fname'] . ' ' . $mi . ' ' . $user['lname']);

// Fetch Consolidated Profile
$stmt = $pdo->prepare("SELECT * FROM reading_profiles WHERE user_id = ?");
$stmt->execute([$user_id]);
$profile = $stmt->fetch();

// Fetch Grades/Attempts
$stmt = $pdo->prepare("
    SELECT a.*, p.title as passage_title
    FROM reading_attempts a
    LEFT JOIN reading_passages p ON a.passage_id = p.id
    WHERE a.user_id = ? AND a.phase IN ('Pre-Test', 'Post-Test', 'GST')
    ORDER BY a.created_at DESC
");
$stmt->execute([$user_id]);
$attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Course Attempts
$stmtCourse = $pdo->prepare("
    SELECT a.*, c.title as passage_title
    FROM reading_attempts a
    LEFT JOIN course_assessments c ON a.passage_id = c.id
    WHERE a.user_id = ? AND a.phase IN ('Course-Pre-Test', 'Course-Post-Test')
    ORDER BY a.created_at DESC
");
$stmtCourse->execute([$user_id]);
$course_attempts = $stmtCourse->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="https://tibungcodistrict.wordpress.com/wp-content/uploads/2018/06/untitled-1.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Grades - Phil-IRI</title>
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="flex h-screen overflow-hidden text-slate-800">
    <?php include 'student_sidebar.php'; ?>
    
    <!-- Sidebar -->
    
    

    <!-- Main Content -->
    <div class="flex-1 flex flex-col h-screen overflow-hidden">
        
        <!-- Header -->
        <header class="h-16 md:h-20 bg-white border-b border-slate-200 flex items-center justify-between px-4 md:px-8 shrink-0 gap-2">
            <div class="flex items-center min-w-0">
                <button onclick="toggleSidebar()" class="md:hidden mr-3 text-slate-500 hover:text-blue-600 focus:outline-none shrink-0"><i class="fas fa-bars text-xl"></i></button>
                <div class="min-w-0">
                    <h2 class="font-bold text-lg md:text-2xl text-slate-800 truncate">My Grades</h2>
                    <p class="text-xs md:text-sm text-slate-500 truncate hidden sm:block">View your assessment scores and reading profile.</p>
                </div>
            </div>
            <div class="flex items-center space-x-2.5 shrink-0">
                <?php 
                $avatarPath = 'uploads/profiles/user_' . $_SESSION['user_id'] . '.jpg';
                if (file_exists($avatarPath)): 
                ?>
                    <img src="<?php echo $avatarPath; ?>?t=<?php echo time(); ?>" class="w-9 h-9 md:w-10 md:h-10 rounded-full object-cover border border-slate-200">
                <?php else: ?>
                    <div class="w-9 h-9 md:w-10 md:h-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 keep-colors font-bold">
                        <i class="fas fa-user"></i>
                    </div>
                <?php endif; ?>
                <span class="font-semibold text-sm hidden sm:inline"><?php echo $fullName; ?></span>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-4 md:p-8 bg-slate-50">
            
            

            <?php $target_user_id = $user_id; include 'comparison_widget.php'; ?>
            <!-- Grades Table -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="p-4 md:p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
                    <h3 class="font-bold text-slate-800 text-base md:text-lg">Phil-IRI Assessment History (GST & Graded Passages)</h3>
                    <input type="text" id="philiriSearch" placeholder="Search assessments..." class="border border-slate-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:border-blue-500 w-full sm:w-64">
                </div>
                
                <?php if(empty($attempts)): ?>
                    <div class="p-4 md:p-8 text-center text-slate-500">You have no recorded grades yet.</div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table id="philiriTable" class="w-full text-left min-w-[680px]">
                            <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
                                <tr>
                                    <th class="p-4 border-b font-semibold">Date</th>
                                    <th class="p-4 border-b font-semibold">Type</th>
                                    <th class="p-4 border-b font-semibold">Level/Title</th>
                                    <th class="p-4 border-b font-semibold">WPM</th>
                                    <th class="p-4 border-b font-semibold">WCPM</th>
                                    <th class="p-4 border-b font-semibold">Accuracy</th>
                                    <th class="p-4 border-b font-semibold">Comprehension</th>
                                    <th class="p-4 border-b font-semibold">Classification</th>
                                </tr>
                            </thead>
                            <tbody class="text-sm divide-y divide-slate-100">
                                <?php foreach($attempts as $att): 
                                    $class = $att['oral_reading_profile'];
                                    $badge = 'bg-slate-100 text-slate-700';
                                    if ($class === 'Independent') $badge = 'bg-emerald-100 text-emerald-700';
                                    if ($class === 'Instructional') $badge = 'bg-amber-100 text-amber-700';
                                    if ($class === 'Frustration') $badge = 'bg-rose-100 text-rose-700';
                                ?>
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="p-4 whitespace-nowrap text-slate-600"><?php echo date('M d, Y h:i A', strtotime($att['created_at'])); ?></td>
                                    <td class="p-4 font-medium text-slate-700">
                                        <?php 
                                            if ($att['phase'] === 'Pre-Test') {
                                                echo 'Phil-IRI Pre-Assessment';
                                            } elseif ($att['phase'] === 'Course-Pre-Test') {
                                                echo 'Course Pre-Test';
                                            } elseif ($att['phase'] === 'Course-Post-Test') {
                                                echo 'Course Post-Test';
                                            } else {
                                                echo htmlspecialchars($att['phase']); 
                                            }
                                        ?>
                                    </td>
                                    <td class="p-4 text-slate-700 font-medium">
                                        <?php if ($att['phase'] === 'Pre-Test') echo "Grade " . $att['passage_grade'];
                                        else echo htmlspecialchars($att['passage_title']); ?>
                                    </td>
                                    <?php 
                                        $wpm = floatval($att['reading_speed']); 
                                        $wcpm = round($wpm * (floatval($att['accuracy_score']) / 100)); 
                                    ?>
                                    <td class="p-4 text-slate-700 font-medium"><?php echo round($wpm); ?></td>
                                    <td class="p-4 text-slate-700 font-medium"><?php echo $wcpm; ?></td>
                                    <td class="p-4 font-bold text-slate-700"><?php echo number_format($att['accuracy_score'], 1); ?>%</td>
                                    <td class="p-4 font-bold text-slate-700"><?php echo number_format($att['comprehension_score'], 1); ?>%</td>
                                    <td class="p-4"><span class="px-3 py-1 rounded-full text-xs font-bold <?php echo $badge; ?>"><?php echo $class; ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <!-- Course Grades Table -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden mt-8">
                <div class="p-4 md:p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
                    <h3 class="font-bold text-slate-800 text-base md:text-lg">Course Assessment History</h3>
                    <input type="text" id="courseListSearch" placeholder="Search courses..." class="border border-slate-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:border-blue-500 w-full sm:w-64">
                </div>
                
                <?php if(empty($course_attempts)): ?>
                    <div class="p-4 md:p-8 text-center text-slate-500">You have no recorded course grades yet.</div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table id="courseListTable" class="w-full text-left min-w-[640px]">
                            <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
                                <tr>
                                    <th class="p-4 border-b font-semibold">Date</th>
                                    <th class="p-4 border-b font-semibold">Type</th>
                                    <th class="p-4 border-b font-semibold">Course Title</th>
                                    <th class="p-4 border-b font-semibold">WPM</th>
                                    <th class="p-4 border-b font-semibold">WCPM</th>
                                    <th class="p-4 border-b font-semibold">Accuracy</th>
                                    <th class="p-4 border-b font-semibold">Comprehension</th>
                                </tr>
                            </thead>
                            <tbody class="text-sm divide-y divide-slate-100">
                                <?php foreach($course_attempts as $att): ?>
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="p-4 whitespace-nowrap text-slate-600"><?php echo date('M d, Y h:i A', strtotime($att['created_at'])); ?></td>
                                    <td class="p-4 font-medium text-slate-700">
                                        <?php 
                                            if ($att['phase'] === 'Course-Pre-Test') echo 'Course Pre-Test';
                                            elseif ($att['phase'] === 'Course-Post-Test') echo 'Course Post-Test';
                                            else echo htmlspecialchars($att['phase']); 
                                        ?>
                                    </td>
                                    <td class="p-4 text-slate-700 font-medium">
                                        <?php echo htmlspecialchars($att['passage_title']); ?>
                                    </td>
                                    <?php 
                                        $wpm = floatval($att['reading_speed']); 
                                        $wcpm = round($wpm * (floatval($att['accuracy_score']) / 100)); 
                                    ?>
                                    <td class="p-4 text-slate-700 font-medium"><?php echo round($wpm); ?></td>
                                    <td class="p-4 text-slate-700 font-medium"><?php echo $wcpm; ?></td>
                                    <td class="p-4 font-bold text-slate-700"><?php echo number_format($att['accuracy_score'], 1); ?>%</td>
                                    <td class="p-4 font-bold text-slate-700"><?php echo number_format($att['comprehension_score'], 1); ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>

    <!-- Custom Table Filter -->
    <script src="v536/public/table_filter.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            initTailwindTable('philiriTable', 'philiriSearch');
            initTailwindTable('courseListTable', 'courseListSearch');
        });
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
