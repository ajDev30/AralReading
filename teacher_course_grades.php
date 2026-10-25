<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: index.php");
    exit;
}

// Fetch all students who have at least one reading attempt
$stmt = $pdo->query("
    SELECT u.id, u.fname, u.lname, u.grade_level, u.section,
           COUNT(ra.id) as total_tests,
           MAX(ra.created_at) as last_activity
    FROM users u
    JOIN reading_attempts ra ON u.id = ra.user_id AND ra.phase IN ('Course-Pre-Test', 'Course-Post-Test')
    WHERE u.role = 'student'
    GROUP BY u.id
    ORDER BY last_activity DESC
");
$students = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="https://tibungcodistrict.wordpress.com/wp-content/uploads/2018/06/untitled-1.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Grades</title>
    <script>
        const originalWarn = console.warn;
        console.warn = function() {
            if (arguments[0] && typeof arguments[0] === 'string' && arguments[0].includes('cdn.tailwindcss.com should not be used in production')) return;
            originalWarn.apply(console, arguments);
        };
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #F8FAFC; }
        .sidebar { background-color: #1a365d; }
        .card { background: white; border-radius: 0.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #E2E8F0; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 4px; }
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
        .hover:bg-slate-50:hover, tr:hover { background-color: #334155 !important; }
        .card { background-color: #1e293b !important; border-color: #334155 !important; }
        
        /* Logo Fix */
        .sidebar img { background-color: transparent !important; filter: drop-shadow(0px 0px 2px rgba(255,255,255,0.5)) !important; }
        <?php endif; ?>
        @media (max-width: 767.98px) {
            .mobile-card-table { min-width: 0 !important; width: 100% !important; display: block; }
            .mobile-card-table thead { display: none !important; }
            .mobile-card-table tbody { display: block; width: 100%; }
            .mobile-card-table tbody tr {
                display: block;
                background: #ffffff;
                border: 1px solid #e2e8f0;
                border-radius: 0.75rem;
                padding: 0.875rem;
                margin-bottom: 0.75rem;
                box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
            }
            .mobile-card-table tbody tr:last-child { margin-bottom: 0; }
            .mobile-card-table tbody td {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 0.75rem;
                padding: 0.45rem 0 !important;
                border: none !important;
                border-bottom: 1px dashed #f1f5f9 !important;
                text-align: right !important;
                background: transparent !important;
                white-space: normal !important;
            }
            .mobile-card-table tbody td:first-child {
                padding-top: 0 !important;
                padding-bottom: 0.65rem !important;
                margin-bottom: 0.25rem;
                border-bottom: 1px solid #e2e8f0 !important;
                text-align: left !important;
                justify-content: flex-start;
            }
            .mobile-card-table tbody td:first-child::before { display: none !important; }
            .mobile-card-table tbody td:last-child { border-bottom: none !important; padding-bottom: 0 !important; }
            .mobile-card-table tbody td[data-label]::before {
                content: attr(data-label);
                font-size: 0.7rem;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.04em;
                color: #64748b;
                text-align: left;
                flex-shrink: 0;
            }
            <?php if($is_dark): ?>
            .mobile-card-table tbody tr { background-color: #1e293b !important; border-color: #334155 !important; }
            .mobile-card-table tbody td { border-bottom-color: #334155 !important; }
            .mobile-card-table tbody td:first-child { border-bottom-color: #475569 !important; }
            .mobile-card-table tbody td[data-label]::before { color: #94a3b8 !important; }
            <?php endif; ?>
        }
    </style>


</head>
<body class="bg-slate-50 min-h-screen text-slate-800 flex overflow-hidden">
    
    <!-- Sidebar -->
    <?php include 'teacher_sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col h-screen overflow-hidden relative transition-all duration-300" id="mainContent">
        
        <header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-4 md:px-8 shrink-0 gap-2">
            <div class="flex items-center min-w-0">
                <button onclick="window.toggleSidebar()" class="mr-3 text-slate-500 hover:text-slate-700 focus:outline-none md:hidden shrink-0">
                    <i class="fas fa-bars text-xl"></i>
                </button>
                <h1 class="font-bold text-base md:text-lg text-slate-800 truncate">Course Grades</h1>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <button onclick="openRulesModal()" class="inline-flex items-center gap-1.5 px-2.5 sm:px-3.5 py-2 bg-blue-50 text-blue-700 hover:bg-blue-100 rounded-lg text-xs sm:text-sm font-semibold border border-blue-200 transition shadow-xs">
                    <i class="fas fa-sliders-h"></i>
                    <span class="hidden sm:inline">Manage Conditional Rules</span>
                    <span class="sm:hidden">Rules</span>
                </button>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-4 md:p-8">
            <div class="max-w-6xl mx-auto">
                <div class="mb-6 flex justify-between items-center">
                    <div>
                        <h2 class="text-lg md:text-xl font-bold text-slate-800">Pre-Test & Post-Test Results</h2>
                        <p class="text-sm text-slate-500">Track student improvement across all courses and assessments.</p>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                    <div class="p-4 border-b border-slate-200 flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
                        <h3 class="font-bold text-slate-800">Student Course Grades</h3>
                        <input type="text" id="gradesSearch" placeholder="Search students..." class="border border-slate-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:border-blue-500 w-full sm:w-64">
                    </div>
                    <?php if(count($students) > 0): ?>
                        <div class="p-3 md:p-0 overflow-x-visible md:overflow-x-auto">
                            <table id="gradesTable" class="mobile-card-table w-full text-left border-collapse">
                                <thead>
                                    <tr class="bg-slate-50 border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500 font-bold">
                                        <th class="py-3 px-4">Student Name</th>
                                        <th class="py-3 px-4">Grade & Section</th>
                                        <th class="py-3 px-4">Total Tests Taken</th>
                                        <th class="py-3 px-4">Last Activity</th>
                                        <th class="py-3 px-4 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php foreach($students as $s): 
                                        $stu_img_path = "uploads/profiles/user_" . $s['id'] . ".jpg";
                                        $stu_img = file_exists($stu_img_path) ? $stu_img_path . "?v=" . filemtime($stu_img_path) : null;
                                    ?>
                                    <tr class="hover:bg-slate-50 transition">
                                        <td class="py-3 px-4 font-medium text-slate-800" data-label="Student Name">
                                            <div class="flex items-center">
                                                <?php if ($stu_img): ?>
                                                    <img src="<?php echo htmlspecialchars($stu_img); ?>" alt="<?php echo htmlspecialchars($s['fname']); ?>" class="w-8 h-8 rounded-full object-cover border border-slate-200 mr-3 shrink-0">
                                                <?php else: ?>
                                                    <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold mr-3 shrink-0">
                                                        <?php echo strtoupper(substr($s['fname'], 0, 1)); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php echo htmlspecialchars($s['fname'] . ' ' . $s['lname']); ?>
                                            </div>
                                        </td>
                                        <td class="py-3 px-4 text-slate-600" data-label="Grade & Section"><?php echo htmlspecialchars(($s['grade_level'] ?? '') . ' - ' . preg_replace('/^Grade\s+\d+\s+/i', '', (string)($s['section'] ?? ''))); ?></td>
                                        <td class="py-3 px-4" data-label="Tests Taken">
                                            <span class="px-2 py-1 bg-slate-100 text-slate-600 rounded text-xs font-bold"><?php echo $s['total_tests']; ?> Tests</span>
                                        </td>
                                        <td class="py-3 px-4 text-slate-500 text-sm" data-label="Last Activity"><?php echo date('M d, Y h:i A', strtotime($s['last_activity'])); ?></td>
                                        <td class="py-3 px-4 text-right" data-label="Action">
                                            <a href="teacher_student_grades.php?user_id=<?php echo $s['id']; ?>" class="inline-flex items-center px-3 py-1.5 bg-blue-600 text-white hover:bg-blue-700 rounded text-xs font-medium transition shadow-sm">
                                                <i class="fas fa-chart-line mr-1.5"></i> View Progress
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-12">
                            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-slate-100 mb-4">
                                <i class="fas fa-users text-2xl text-slate-400"></i>
                            </div>
                            <h3 class="text-lg font-medium text-slate-800 mb-1">No Active Students</h3>
                            <p class="text-slate-500">When students take a Pre-Test or Post-Test, they will appear here.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    <!-- Custom Table Filter -->
    <script src="v536/public/table_filter.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            initTailwindTable('gradesTable', 'gradesSearch');
        });
    </script>
    <script>
        window.toggleSidebar = function() {
            const sidebar = document.getElementById('appSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if(sidebar) sidebar.classList.toggle('-translate-x-full');
            if(overlay) overlay.classList.toggle('hidden');
        };
    </script>
    <!-- Conditional Rules Modal -->
    <?php include 'conditional_rules_modal.php'; ?>
</body>
</html>
