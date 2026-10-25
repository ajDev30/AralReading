<?php
session_start();
require_once 'config.php';

// RBAC Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: index.php");
    exit;
}

// Fetch real data
$stmt = $pdo->prepare("
    SELECT u.*, 
           p.independent_grade, p.instructional_grade, p.frustration_grade,
           g.score AS gst_score, g.total_items AS gst_total, g.percentage AS gst_pct,
           g.needs_individual_assessment, g.completed_at AS gst_completed_at,
           pre_att.accuracy_score AS oral_pre_acc, pre_att.comprehension_score AS oral_pre_comp,
           pre_att.oral_reading_profile AS oral_pre_prof, pre_att.created_at AS oral_pre_date, pre_att.phase AS oral_pre_phase,
           post_att.accuracy_score AS oral_post_acc, post_att.comprehension_score AS oral_post_comp,
           post_att.oral_reading_profile AS oral_post_prof, post_att.created_at AS oral_post_date
    FROM users u
    LEFT JOIN reading_profiles p ON u.id = p.user_id
    LEFT JOIN (
        SELECT user_id, score, total_items, percentage, needs_individual_assessment, completed_at
        FROM gst_results
        WHERE id IN (SELECT MAX(id) FROM gst_results GROUP BY user_id)
    ) g ON u.id = g.user_id
    LEFT JOIN (
        SELECT user_id, accuracy_score, comprehension_score, oral_reading_profile, created_at, phase
        FROM reading_attempts
        WHERE id IN (
            SELECT MAX(id) FROM reading_attempts
            WHERE phase IN ('Pre-Test', 'Course-Pre-Test')
            GROUP BY user_id
        )
    ) pre_att ON u.id = pre_att.user_id
    LEFT JOIN (
        SELECT user_id, accuracy_score, comprehension_score, oral_reading_profile, created_at
        FROM reading_attempts
        WHERE id IN (
            SELECT MAX(id) FROM reading_attempts
            WHERE phase IN ('Post-Test', 'Course-Post-Test')
            GROUP BY user_id
        )
    ) post_att ON u.id = post_att.user_id
    WHERE u.role = 'student' 
    ORDER BY u.fname ASC
");
$stmt->execute();
$students = $stmt->fetchAll();

$total_students = count($students);
$independent = 0; $instructional = 0; $frustration = 0; $non_reader = 0; $pending = 0;
$pretest_students = [];
$posttest_students = [];

foreach($students as &$s) {
    $enrolled_grade = intval(preg_replace('/[^0-9]/', '', $s['grade_level']));
    $final_status = 'Pending';
    
    if (isset($s['needs_individual_assessment']) && $s['needs_individual_assessment'] == 0) {
        $final_status = 'Independent';
    } elseif ($s['independent_grade'] !== null || $s['instructional_grade'] !== null || $s['frustration_grade'] !== null) {
        if ($s['independent_grade'] !== null && $enrolled_grade <= intval($s['independent_grade'])) {
            $final_status = 'Independent';
        } elseif ($s['instructional_grade'] !== null && $enrolled_grade <= intval($s['instructional_grade'])) {
            $final_status = 'Instructional';
        } else {
            if ($s['frustration_grade'] !== null && intval($s['frustration_grade']) <= 4 && $s['instructional_grade'] === null && $s['independent_grade'] === null) {
                $final_status = 'Non-Reader';
            } else {
                $final_status = 'Frustration';
            }
        }
    }
    
    // Enrich with course assessment progress & custom teacher recommendations
    $courseSummary = get_student_course_progress_summary($pdo, $s['id']);
    $s['course_summary'] = $courseSummary;

    $profRank = ['non-reader' => 0, 'frustration' => 1, 'instructional' => 2, 'independent' => 3];
    if ($courseSummary['has_tests'] && !empty($courseSummary['latest_profile']) && $courseSummary['latest_profile'] !== 'Pending') {
        $courseProf = $courseSummary['latest_profile'];
        $courseRank = $profRank[strtolower(trim($courseProf))] ?? 1;
        $baselineRank = $profRank[strtolower(trim($final_status))] ?? -1;
        if ($courseSummary['post'] || $courseRank > $baselineRank || $final_status === 'Pending') {
            $final_status = ucfirst(strtolower(trim($courseProf)));
        }
    }
    
    $s['oral_reading_profile'] = $final_status;
    
    // Status Remarks calculation
    if (!empty($courseSummary['has_custom_rec']) && !empty($courseSummary['condition_tag'])) {
        $s['status_remarks'] = 'Teacher Note: ' . $courseSummary['condition_tag'];
    } elseif (!empty($courseSummary['has_custom_rec']) && !empty($courseSummary['recommendation_text'])) {
        $s['status_remarks'] = 'Teacher Recommendation Applied';
    } elseif (!empty($courseSummary['post'])) {
        $s['status_remarks'] = $courseSummary['outcome'] . ' (' . $final_status . ') - Post-Test Completed';
    } elseif (!empty($courseSummary['pre'])) {
        $s['status_remarks'] = 'Course Pre-Test Completed (In Progress)';
    } else {
        if ($final_status === 'Independent') {
            $s['status_remarks'] = 'Mastery / Grade-Ready';
        } elseif ($final_status === 'Instructional') {
            $s['status_remarks'] = 'Recommended for Guided Reading Practice';
        } elseif ($final_status === 'Frustration') {
            $s['status_remarks'] = 'Requires Targeted Reading Support';
        } elseif ($final_status === 'Non-Reader') {
            $s['status_remarks'] = 'Urgent: Individual Reading Intervention';
        } else {
            $s['status_remarks'] = 'Awaiting Assessment / GST';
        }
    }
    
    if($final_status === 'Independent') $independent++;
    elseif($final_status === 'Instructional') $instructional++;
    elseif($final_status === 'Frustration') $frustration++;
    elseif($final_status === 'Non-Reader') $non_reader++;
    else $pending++;

    // Collect students who took Pre-Test (Course Pre-Test, Oral Pre-Test, or GST)
    if (!empty($courseSummary['pre']) || $s['oral_pre_acc'] !== null || $s['gst_score'] !== null) {
        $pretest_students[] = $s;
    }

    // Collect students who took Post-Test (Course Post-Test or Oral Post-Test)
    if (!empty($courseSummary['post']) || $s['oral_post_acc'] !== null) {
        $posttest_students[] = $s;
    }
}
unset($s);

$pct_ind = $total_students > 0 ? round(($independent/$total_students)*100, 1) : 0;
$pct_ins = $total_students > 0 ? round(($instructional/$total_students)*100, 1) : 0;
$pct_fru = $total_students > 0 ? round(($frustration/$total_students)*100, 1) : 0;
$pct_non = $total_students > 0 ? round(($non_reader/$total_students)*100, 1) : 0;

$sections = [];
$grade_levels = [];
foreach($students as $st) {
    $sec = preg_replace('/^Grade\s+\d+\s+/i', '', trim($st['section'] ?? ''));
    if (!empty($sec) && !in_array($sec, $sections)) {
        $sections[] = $sec;
    }
    $gl = trim($st['grade_level'] ?? '');
    if (!empty($gl) && !in_array($gl, $grade_levels)) {
        $grade_levels[] = $gl;
    }
}
sort($sections);
sort($grade_levels);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="https://tibungcodistrict.wordpress.com/wp-content/uploads/2018/06/untitled-1.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard - Phil-IRI</title>
    <script>
        const originalWarn = console.warn;
        console.warn = function() {
            if (arguments[0] && typeof arguments[0] === 'string' && arguments[0].includes('cdn.tailwindcss.com should not be used in production')) return;
            originalWarn.apply(console, arguments);
        };
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        .hover\:bg-slate-50:hover, tr:hover { background-color: #334155 !important; }
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
            .mobile-card-table tbody td[colspan] {
                display: block !important;
                text-align: center !important;
                border-bottom: none !important;
                padding: 0.75rem 0 !important;
            }
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


    <script src="js/htmx.min.js"></script>
    <meta name="htmx-config" content='{"globalViewTransitions":true}'>
</head>
<body class="flex h-screen overflow-hidden text-slate-800">

    <!-- Sidebar -->
    <?php include 'teacher_sidebar.php'; ?>

    <div class="flex-1 flex flex-col h-screen overflow-hidden">
        <header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-4 md:px-8 shrink-0">
            <div class="flex items-center">
                <button onclick="toggleSidebar()" class="md:hidden mr-4 text-slate-500 hover:text-blue-600 focus:outline-none"><i class="fas fa-bars text-xl"></i></button>
                <div>
                <h2 class="font-bold text-lg text-slate-800">Welcome, <?php echo htmlspecialchars($_SESSION['fname']); ?>!</h2>
            </div></div>
            <div class="flex items-center space-x-3 border-l pl-4 border-slate-200">
                <?php 
                $avatarPath = 'uploads/profiles/user_' . $_SESSION['user_id'] . '.jpg';
                if (file_exists($avatarPath)): 
                ?>
                    <img src="<?php echo $avatarPath; ?>?t=<?php echo time(); ?>" class="w-10 h-10 rounded-full object-cover border border-slate-200">
                <?php else: ?>
                    <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 font-bold">
                        <?php echo substr($_SESSION['fname'], 0, 1); ?>
                    </div>
                <?php endif; ?>
                <div class="leading-tight">
                    <p class="font-semibold text-sm"><?php echo htmlspecialchars($_SESSION['fname']); ?></p>
                    <p class="text-xs text-slate-500 capitalize">Teacher</p>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-x-hidden overflow-y-auto p-4 md:p-6 bg-slate-50">
            <h2 class="text-xl md:text-2xl font-bold text-slate-800 mb-4 md:mb-6">Dashboard Overview</h2>

            <!-- Metrics -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3 md:gap-4 mb-6">
                <div class="card p-4 flex flex-col md:flex-row md:items-center justify-between border-l-4 border-l-blue-500">
                    <div>
                        <p class="text-sm font-semibold text-blue-600 mb-1">Total</p>
                        <h3 class="text-2xl md:text-3xl font-bold"><?php echo $total_students; ?></h3>
                    </div>
                </div>
                <div class="card p-4 flex flex-col md:flex-row md:items-center justify-between border-l-4 border-l-emerald-500 bg-emerald-50/30">
                    <div>
                        <p class="text-sm font-semibold text-emerald-600 mb-1">Independent</p>
                        <h3 class="text-2xl md:text-3xl font-bold"><?php echo $independent; ?></h3>
                        <p class="text-xs text-emerald-600 font-medium"><?php echo $pct_ind; ?>%</p>
                    </div>
                </div>
                <div class="card p-4 flex flex-col md:flex-row md:items-center justify-between border-l-4 border-l-amber-500 bg-amber-50/30">
                    <div>
                        <p class="text-sm font-semibold text-amber-600 mb-1">Instructional</p>
                        <h3 class="text-2xl md:text-3xl font-bold"><?php echo $instructional; ?></h3>
                        <p class="text-xs text-amber-600 font-medium"><?php echo $pct_ins; ?>%</p>
                    </div>
                </div>
                <div class="card p-4 flex flex-col md:flex-row md:items-center justify-between border-l-4 border-l-rose-500 bg-rose-50/30">
                    <div>
                        <p class="text-sm font-semibold text-rose-600 mb-1">Frustration</p>
                        <h3 class="text-2xl md:text-3xl font-bold"><?php echo $frustration; ?></h3>
                        <p class="text-xs text-rose-600 font-medium"><?php echo $pct_fru; ?>%</p>
                    </div>
                </div>
                <div class="card p-4 col-span-2 md:col-span-1 flex flex-col md:flex-row md:items-center justify-between border-l-4 border-l-slate-500 bg-slate-50/30">
                    <div>
                        <p class="text-sm font-semibold text-slate-600 mb-1">Non-Reader</p>
                        <h3 class="text-2xl md:text-3xl font-bold"><?php echo $non_reader; ?></h3>
                        <p class="text-xs text-slate-600 font-medium"><?php echo $pct_non; ?>%</p>
                    </div>
                </div>
            </div>

            <!-- Mobile Table View Selector (Only visible on mobile < 768px) -->
            <div class="md:hidden card p-3.5 mb-4 flex items-center justify-between gap-3 border-l-4 border-l-indigo-500">
                <label for="mobileDashboardTableSelect" class="text-xs font-bold uppercase tracking-wider text-slate-600 shrink-0 flex items-center gap-1.5 mb-0">
                    <i class="fas fa-table text-indigo-500"></i> View Table:
                </label>
                <select id="mobileDashboardTableSelect" onchange="switchMobileDashboardTable(this.value)" class="border border-slate-300 rounded-lg px-3 py-2 text-xs font-bold text-slate-800 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 flex-1 min-w-0">
                    <option value="masterlist">Pre-Assessment / Master List (<?php echo count($students); ?>)</option>
                    <option value="pretest">Pre-Test Student List (<?php echo count($pretest_students); ?>)</option>
                    <option value="posttest">Post-Test Student List (<?php echo count($posttest_students); ?>)</option>
                </select>
            </div>

            <!-- Masterlist -->
            <div id="masterlistCard" class="card p-4 md:p-6 h-auto flex md:flex flex-col mb-8 md:mb-10">
                <div class="flex flex-col lg:flex-row lg:justify-between lg:items-center border-b pb-3 mb-4 gap-3">
                    <div class="flex flex-wrap items-center justify-between sm:justify-start gap-2.5">
                        <h3 class="font-bold text-slate-800 text-base">Master Student List</h3>
                        <button onclick="openReportModal()" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-semibold shadow-sm transition">
                            <i class="fas fa-file-excel"></i> Generate Report (.xlsx)
                        </button>
                    </div>
                    <div class="grid grid-cols-2 sm:flex sm:flex-wrap items-center gap-2 w-full lg:w-auto">
                        <!-- Grade Level Filter -->
                        <select id="masterlistGradeFilter" onchange="applyMasterlistFilters()" class="border border-slate-300 rounded px-2.5 py-1.5 text-xs focus:outline-none focus:border-blue-500 bg-white font-medium w-full sm:w-auto">
                            <option value="">All Grades</option>
                            <?php foreach($grade_levels as $gl): ?>
                                <option value="<?php echo htmlspecialchars($gl); ?>"><?php echo htmlspecialchars($gl); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <!-- Section Filter -->
                        <select id="masterlistSectionFilter" onchange="applyMasterlistFilters()" class="border border-slate-300 rounded px-2.5 py-1.5 text-xs focus:outline-none focus:border-blue-500 bg-white font-medium w-full sm:w-auto">
                            <option value="">All Sections</option>
                            <?php foreach($sections as $sec): ?>
                                <option value="<?php echo htmlspecialchars($sec); ?>"><?php echo htmlspecialchars($sec); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <!-- Gender Filter -->
                        <select id="masterlistGenderFilter" onchange="applyMasterlistFilters()" class="border border-slate-300 rounded px-2.5 py-1.5 text-xs focus:outline-none focus:border-blue-500 bg-white font-medium w-full sm:w-auto">
                            <option value="">All Genders</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>

                        <!-- Profile Filter -->
                        <select id="masterlistProfileFilter" onchange="applyMasterlistFilters()" class="border border-slate-300 rounded px-2.5 py-1.5 text-xs focus:outline-none focus:border-blue-500 bg-white font-medium w-full sm:w-auto">
                            <option value="">All Profiles</option>
                            <option value="Independent">Independent</option>
                            <option value="Instructional">Instructional</option>
                            <option value="Frustration">Frustration</option>
                            <option value="Non-Reader">Non-Reader</option>
                            <option value="Pending">Pending</option>
                        </select>

                        <!-- Search -->
                        <input type="text" id="masterlistSearch" onkeyup="applyMasterlistFilters()" placeholder="Search students..." class="col-span-2 sm:col-span-1 border border-slate-300 rounded px-3 py-1.5 text-xs focus:outline-none focus:border-blue-500 w-full sm:w-44">
                    </div>
                </div>

                <div class="overflow-x-visible md:overflow-x-auto">
                    <table id="masterlistTable" class="mobile-card-table w-full text-sm text-left border-collapse">
                        <thead class="text-xs text-slate-500 bg-slate-50">
                            <tr>
                                <th class="py-2.5 px-3">Student Name</th>
                                <th class="py-2.5 px-3">LRN</th>
                                <th class="py-2.5 px-3">Gender</th>
                                <th class="py-2.5 px-3">Grade Level</th>
                                <th class="py-2.5 px-3">Section</th>
                                <th class="py-2.5 px-3">Oral Reading Profile</th>
                                <th class="py-2.5 px-3">Status Remarks</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php 
                            if(count($students) > 0) {
                                foreach($students as $s):
                                    $lvl = $s['oral_reading_profile'] ?? 'Pending';
                                    $mi = !empty($s['mname']) ? strtoupper(substr($s['mname'], 0, 1)) . '.' : '';
                                    $name = htmlspecialchars($s['fname'] . ' ' . $mi . ' ' . $s['lname']);
                                    $lc = 'bg-slate-100 text-slate-600';
                                    if($lvl=='Independent') $lc='bg-emerald-100 text-emerald-800';
                                    if($lvl=='Instructional') $lc='bg-amber-100 text-amber-800';
                                    if($lvl=='Frustration') $lc='bg-rose-100 text-rose-800';
                                    if($lvl=='Non-Reader') $lc='bg-slate-700 text-white';

                                    $clean_sec = preg_replace('/^Grade\s+\d+\s+/i', '', (string)($s['section'] ?? ''));
                                    $gender = !empty($s['gender']) ? $s['gender'] : 'N/A';
                                    $remarks = $s['status_remarks'] ?? 'Awaiting Assessment / GST';
                                    $stu_img_path = "uploads/profiles/user_" . $s['id'] . ".jpg";
                                    $stu_img = file_exists($stu_img_path) ? $stu_img_path . "?v=" . filemtime($stu_img_path) : null;
                            ?>
                            <tr class="hover:bg-slate-50 transition"
                                data-grade="<?php echo htmlspecialchars($s['grade_level'] ?? ''); ?>" 
                                data-section="<?php echo htmlspecialchars($clean_sec); ?>" 
                                data-gender="<?php echo htmlspecialchars($gender); ?>"
                                data-profile="<?php echo htmlspecialchars($lvl); ?>">
                                <td class="py-2.5 px-3 font-medium text-slate-800" data-label="Student Name">
                                    <div class="flex items-center gap-2.5">
                                        <?php if ($stu_img): ?>
                                            <img src="<?php echo htmlspecialchars($stu_img); ?>" alt="<?php echo $name; ?>" class="w-8 h-8 rounded-full object-cover border border-slate-200 shrink-0">
                                        <?php else: ?>
                                            <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold text-xs shrink-0">
                                                <?php echo strtoupper(substr($s['fname'] ?? 'S', 0, 1)); ?>
                                            </div>
                                        <?php endif; ?>
                                        <span><?php echo $name; ?></span>
                                    </div>
                                </td>
                                <td class="py-2.5 px-3 text-slate-500 font-mono text-xs" data-label="LRN"><?php echo htmlspecialchars($s['lrn'] ?? 'N/A'); ?></td>
                                <td class="py-2.5 px-3 text-slate-600 text-xs font-medium" data-label="Gender">
                                    <?php if ($gender === 'Male'): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 font-semibold"><i class="fas fa-mars text-[10px]"></i> Male</span>
                                    <?php elseif ($gender === 'Female'): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-pink-50 text-pink-700 font-semibold"><i class="fas fa-venus text-[10px]"></i> Female</span>
                                    <?php else: ?>
                                        <span class="text-slate-400">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2.5 px-3 text-slate-600 font-medium" data-label="Grade Level"><?php echo htmlspecialchars($s['grade_level'] ?? 'N/A'); ?></td>
                                <td class="py-2.5 px-3 text-slate-600" data-label="Section"><?php echo htmlspecialchars($clean_sec ?: 'N/A'); ?></td>
                                <td class="py-2.5 px-3" data-label="Oral Reading Profile"><span class="px-2.5 py-0.5 rounded-full text-xs font-semibold <?php echo $lc; ?>"><?php echo $lvl; ?></span></td>
                                <td class="py-2.5 px-3 text-slate-600 text-xs font-medium" data-label="Status Remarks">
                                    <span>
                                        <?php echo htmlspecialchars($remarks); ?>
                                        <?php if (!empty($s['course_summary']['has_custom_rec'])): ?>
                                            <span class="inline-block ml-1 text-indigo-600" title="Custom Teacher Recommendation Applied"><i class="fas fa-tag text-[10px]"></i></span>
                                        <?php endif; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; } else { echo "<tr><td colspan='7' class='text-center py-4 text-slate-500'>No students found.</td></tr>"; } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pre-Test Student List -->
            <div id="pretestCard" class="card p-4 md:p-6 h-auto hidden md:flex flex-col mb-8 md:mb-10">
                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center border-b pb-3 mb-4 gap-3">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-blue-100 text-blue-600 flex items-center justify-center font-bold text-xs shrink-0">
                            <i class="fas fa-clipboard-check"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-slate-800 text-base">Pre-Test Student List</h3>
                            <p class="text-xs text-slate-500">Students who have completed a Pre-Test assessment (<?php echo count($pretest_students); ?> students)</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 w-full sm:w-auto">
                        <input type="text" id="pretestSearch" onkeyup="filterSimpleTable('pretestTable', this.value)" placeholder="Search pre-test students..." class="border border-slate-300 rounded px-3 py-1.5 text-xs focus:outline-none focus:border-blue-500 w-full sm:w-52">
                    </div>
                </div>

                <div class="overflow-x-visible md:overflow-x-auto">
                    <table id="pretestTable" class="mobile-card-table w-full text-sm text-left border-collapse">
                        <thead class="text-xs text-slate-500 bg-slate-50">
                            <tr>
                                <th class="py-2.5 px-3">Student Name</th>
                                <th class="py-2.5 px-3">LRN</th>
                                <th class="py-2.5 px-3">Gender</th>
                                <th class="py-2.5 px-3">Grade Level</th>
                                <th class="py-2.5 px-3">Section</th>
                                <th class="py-2.5 px-3">Pre-Test Score</th>
                                <th class="py-2.5 px-3">Pre-Test Profile</th>
                                <th class="py-2.5 px-3">Date Taken</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (count($pretest_students) > 0): ?>
                                <?php foreach ($pretest_students as $ps): 
                                    $mi = !empty($ps['mname']) ? strtoupper(substr($ps['mname'], 0, 1)) . '.' : '';
                                    $pName = htmlspecialchars($ps['fname'] . ' ' . $mi . ' ' . $ps['lname']);
                                    $clean_sec = preg_replace('/^Grade\s+\d+\s+/i', '', (string)($ps['section'] ?? ''));
                                    $gender = !empty($ps['gender']) ? $ps['gender'] : 'N/A';
                                    $stu_img_path = "uploads/profiles/user_" . $ps['id'] . ".jpg";
                                    $stu_img = file_exists($stu_img_path) ? $stu_img_path . "?v=" . filemtime($stu_img_path) : null;

                                    $cPre = $ps['course_summary']['pre'] ?? null;
                                    if ($cPre) {
                                        $scoreHtml = '<span class="font-bold text-slate-800">' . number_format($cPre['accuracy_score'], 1) . '%</span> <span class="text-[10px] text-slate-500">Acc</span> | <span class="font-bold text-blue-600">' . number_format($cPre['comprehension_score'], 1) . '%</span> <span class="text-[10px] text-slate-500">Comp</span>';
                                        $preProf = $cPre['oral_reading_profile'] ?: ($ps['oral_reading_profile'] ?? 'Instructional');
                                        $dateStr = !empty($cPre['created_at']) ? date('M d, Y h:i A', strtotime($cPre['created_at'])) : 'N/A';
                                    } elseif ($ps['oral_pre_acc'] !== null) {
                                        $scoreHtml = '<span class="font-bold text-slate-800">' . number_format($ps['oral_pre_acc'], 1) . '%</span> <span class="text-[10px] text-slate-500">Acc</span> | <span class="font-bold text-blue-600">' . number_format($ps['oral_pre_comp'], 1) . '%</span> <span class="text-[10px] text-slate-500">Comp</span>';
                                        $preProf = $ps['oral_pre_prof'] ?: ($ps['oral_reading_profile'] ?? 'Instructional');
                                        $dateStr = !empty($ps['oral_pre_date']) ? date('M d, Y h:i A', strtotime($ps['oral_pre_date'])) : 'N/A';
                                    } else {
                                        $scoreHtml = '<span class="font-bold text-slate-800">GST: ' . htmlspecialchars($ps['gst_score'] . '/' . $ps['gst_total']) . '</span> <span class="text-[10px] text-slate-500">(' . number_format($ps['gst_pct'], 1) . '%)</span>';
                                        $preProf = $ps['oral_reading_profile'] ?? 'Pending';
                                        $dateStr = !empty($ps['gst_completed_at']) ? date('M d, Y h:i A', strtotime($ps['gst_completed_at'])) : 'N/A';
                                    }

                                    $plc = 'bg-slate-100 text-slate-600';
                                    if ($preProf === 'Independent') $plc = 'bg-emerald-100 text-emerald-800';
                                    elseif ($preProf === 'Instructional') $plc = 'bg-amber-100 text-amber-800';
                                    elseif ($preProf === 'Frustration') $plc = 'bg-rose-100 text-rose-800';
                                    elseif ($preProf === 'Non-Reader') $plc = 'bg-slate-700 text-white';
                                ?>
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="py-2.5 px-3 font-medium text-slate-800" data-label="Student Name">
                                        <div class="flex items-center gap-2.5">
                                            <?php if ($stu_img): ?>
                                                <img src="<?php echo htmlspecialchars($stu_img); ?>" alt="<?php echo $pName; ?>" class="w-8 h-8 rounded-full object-cover border border-slate-200 shrink-0">
                                            <?php else: ?>
                                                <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold text-xs shrink-0">
                                                    <?php echo strtoupper(substr($ps['fname'] ?? 'S', 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <span><?php echo $pName; ?></span>
                                        </div>
                                    </td>
                                    <td class="py-2.5 px-3 text-slate-500 font-mono text-xs" data-label="LRN"><?php echo htmlspecialchars($ps['lrn'] ?? 'N/A'); ?></td>
                                    <td class="py-2.5 px-3 text-slate-600 text-xs font-medium" data-label="Gender">
                                        <?php if ($gender === 'Male'): ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 font-semibold"><i class="fas fa-mars text-[10px]"></i> Male</span>
                                        <?php elseif ($gender === 'Female'): ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-pink-50 text-pink-700 font-semibold"><i class="fas fa-venus text-[10px]"></i> Female</span>
                                        <?php else: ?>
                                            <span class="text-slate-400">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2.5 px-3 text-slate-600 font-medium" data-label="Grade Level"><?php echo htmlspecialchars($ps['grade_level'] ?? 'N/A'); ?></td>
                                    <td class="py-2.5 px-3 text-slate-600" data-label="Section"><?php echo htmlspecialchars($clean_sec ?: 'N/A'); ?></td>
                                    <td class="py-2.5 px-3 text-xs" data-label="Pre-Test Score"><div><?php echo $scoreHtml; ?></div></td>
                                    <td class="py-2.5 px-3" data-label="Pre-Test Profile"><span class="px-2.5 py-0.5 rounded-full text-xs font-semibold <?php echo $plc; ?>"><?php echo htmlspecialchars($preProf); ?></span></td>
                                    <td class="py-2.5 px-3 text-slate-500 text-xs" data-label="Date Taken"><?php echo htmlspecialchars($dateStr); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="8" class="text-center py-4 text-slate-500">No students have taken the Pre-Test yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Post-Test Student List -->
            <div id="posttestCard" class="card p-4 md:p-6 h-auto hidden md:flex flex-col mb-8 md:mb-10">
                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center border-b pb-3 mb-4 gap-3">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-emerald-100 text-emerald-600 flex items-center justify-center font-bold text-xs shrink-0">
                            <i class="fas fa-award"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-slate-800 text-base">Post-Test Student List</h3>
                            <p class="text-xs text-slate-500">Students who have completed a Post-Test assessment (<?php echo count($posttest_students); ?> students)</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 w-full sm:w-auto">
                        <input type="text" id="posttestSearch" onkeyup="filterSimpleTable('posttestTable', this.value)" placeholder="Search post-test students..." class="border border-slate-300 rounded px-3 py-1.5 text-xs focus:outline-none focus:border-blue-500 w-full sm:w-52">
                    </div>
                </div>

                <div class="overflow-x-visible md:overflow-x-auto">
                    <table id="posttestTable" class="mobile-card-table w-full text-sm text-left border-collapse">
                        <thead class="text-xs text-slate-500 bg-slate-50">
                            <tr>
                                <th class="py-2.5 px-3">Student Name</th>
                                <th class="py-2.5 px-3">LRN</th>
                                <th class="py-2.5 px-3">Gender</th>
                                <th class="py-2.5 px-3">Grade Level</th>
                                <th class="py-2.5 px-3">Section</th>
                                <th class="py-2.5 px-3">Post-Test Score</th>
                                <th class="py-2.5 px-3">Post-Test Profile</th>
                                <th class="py-2.5 px-3">Outcome</th>
                                <th class="py-2.5 px-3">Date Taken</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (count($posttest_students) > 0): ?>
                                <?php foreach ($posttest_students as $pts): 
                                    $mi = !empty($pts['mname']) ? strtoupper(substr($pts['mname'], 0, 1)) . '.' : '';
                                    $ptName = htmlspecialchars($pts['fname'] . ' ' . $mi . ' ' . $pts['lname']);
                                    $clean_sec = preg_replace('/^Grade\s+\d+\s+/i', '', (string)($pts['section'] ?? ''));
                                    $gender = !empty($pts['gender']) ? $pts['gender'] : 'N/A';
                                    $stu_img_path = "uploads/profiles/user_" . $pts['id'] . ".jpg";
                                    $stu_img = file_exists($stu_img_path) ? $stu_img_path . "?v=" . filemtime($stu_img_path) : null;

                                    $cPost = $pts['course_summary']['post'] ?? null;
                                    if ($cPost) {
                                        $postAcc = floatval($cPost['accuracy_score']);
                                        $postComp = floatval($cPost['comprehension_score']);
                                        $postProf = $cPost['oral_reading_profile'] ?: ($pts['oral_reading_profile'] ?? 'Instructional');
                                        $dateStr = !empty($cPost['created_at']) ? date('M d, Y h:i A', strtotime($cPost['created_at'])) : 'N/A';
                                    } else {
                                        $postAcc = floatval($pts['oral_post_acc']);
                                        $postComp = floatval($pts['oral_post_comp']);
                                        $postProf = $pts['oral_post_prof'] ?: ($pts['oral_reading_profile'] ?? 'Instructional');
                                        $dateStr = !empty($pts['oral_post_date']) ? date('M d, Y h:i A', strtotime($pts['oral_post_date'])) : 'N/A';
                                    }

                                    $scoreHtml = '<span class="font-bold text-slate-800">' . number_format($postAcc, 1) . '%</span> <span class="text-[10px] text-slate-500">Acc</span> | <span class="font-bold text-blue-600">' . number_format($postComp, 1) . '%</span> <span class="text-[10px] text-slate-500">Comp</span>';

                                    $ptlc = 'bg-slate-100 text-slate-600';
                                    if ($postProf === 'Independent') $ptlc = 'bg-emerald-100 text-emerald-800';
                                    elseif ($postProf === 'Instructional') $ptlc = 'bg-amber-100 text-amber-800';
                                    elseif ($postProf === 'Frustration') $ptlc = 'bg-rose-100 text-rose-800';
                                    elseif ($postProf === 'Non-Reader') $ptlc = 'bg-slate-700 text-white';

                                    $outcomeText = $pts['course_summary']['outcome'] ?? 'Completed';
                                    $outcomeBadge = $pts['course_summary']['badge_class'] ?? 'bg-emerald-100 text-emerald-800 font-semibold';
                                ?>
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="py-2.5 px-3 font-medium text-slate-800" data-label="Student Name">
                                        <div class="flex items-center gap-2.5">
                                            <?php if ($stu_img): ?>
                                                <img src="<?php echo htmlspecialchars($stu_img); ?>" alt="<?php echo $ptName; ?>" class="w-8 h-8 rounded-full object-cover border border-slate-200 shrink-0">
                                            <?php else: ?>
                                                <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold text-xs shrink-0">
                                                    <?php echo strtoupper(substr($pts['fname'] ?? 'S', 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <span><?php echo $ptName; ?></span>
                                        </div>
                                    </td>
                                    <td class="py-2.5 px-3 text-slate-500 font-mono text-xs" data-label="LRN"><?php echo htmlspecialchars($pts['lrn'] ?? 'N/A'); ?></td>
                                    <td class="py-2.5 px-3 text-slate-600 text-xs font-medium" data-label="Gender">
                                        <?php if ($gender === 'Male'): ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 font-semibold"><i class="fas fa-mars text-[10px]"></i> Male</span>
                                        <?php elseif ($gender === 'Female'): ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-pink-50 text-pink-700 font-semibold"><i class="fas fa-venus text-[10px]"></i> Female</span>
                                        <?php else: ?>
                                            <span class="text-slate-400">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2.5 px-3 text-slate-600 font-medium" data-label="Grade Level"><?php echo htmlspecialchars($pts['grade_level'] ?? 'N/A'); ?></td>
                                    <td class="py-2.5 px-3 text-slate-600" data-label="Section"><?php echo htmlspecialchars($clean_sec ?: 'N/A'); ?></td>
                                    <td class="py-2.5 px-3 text-xs" data-label="Post-Test Score"><div><?php echo $scoreHtml; ?></div></td>
                                    <td class="py-2.5 px-3" data-label="Post-Test Profile"><span class="px-2.5 py-0.5 rounded-full text-xs font-semibold <?php echo $ptlc; ?>"><?php echo htmlspecialchars($postProf); ?></span></td>
                                    <td class="py-2.5 px-3" data-label="Outcome"><span class="px-2.5 py-0.5 rounded-full text-xs <?php echo $outcomeBadge; ?>"><?php echo htmlspecialchars($outcomeText); ?></span></td>
                                    <td class="py-2.5 px-3 text-slate-500 text-xs" data-label="Date Taken"><?php echo htmlspecialchars($dateStr); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="9" class="text-center py-4 text-slate-500">No students have taken the Post-Test yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Generate Report Modal -->
    <div id="reportModal" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-xl max-w-md w-full p-6 border border-slate-200">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
                <h3 class="font-bold text-lg text-slate-800 flex items-center">
                    <i class="fas fa-file-excel text-emerald-600 mr-2"></i> Generate Pre-Assessment Report (.xlsx)
                </h3>
                <button type="button" onclick="closeReportModal()" class="text-slate-400 hover:text-slate-600 text-xl font-bold">&times;</button>
            </div>
            <p class="text-xs text-slate-500 mb-4">Export the official ARAL Program Learner Pre-Assessment Excel Report based on Phil-IRI assessment data.</p>
            
            <form method="GET" action="export_teacher_report.php" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">School Name</label>
                    <input type="text" name="school_name" value="Barcelonita Fishery School" class="w-full border border-slate-300 rounded px-3 py-2 text-sm bg-white focus:outline-none focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">District / Division</label>
                    <input type="text" name="district" value="Cabusao, Camarines Sur" class="w-full border border-slate-300 rounded px-3 py-2 text-sm bg-white focus:outline-none focus:border-blue-500">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Year / Grade Level</label>
                        <select name="grade" id="reportGrade" class="w-full border border-slate-300 rounded px-3 py-2 text-sm bg-white focus:outline-none focus:border-blue-500">
                            <option value="">All Year Levels</option>
                            <?php foreach($grade_levels as $gl): ?>
                                <option value="<?php echo htmlspecialchars($gl); ?>"><?php echo htmlspecialchars($gl); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Section</label>
                        <select name="section" id="reportSection" class="w-full border border-slate-300 rounded px-3 py-2 text-sm bg-white focus:outline-none focus:border-blue-500">
                            <option value="">All Sections</option>
                            <?php foreach($sections as $sec): ?>
                                <option value="<?php echo htmlspecialchars($sec); ?>"><?php echo htmlspecialchars($sec); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Status / Reading Profile</label>
                    <select name="status" id="reportStatus" class="w-full border border-slate-300 rounded px-3 py-2 text-sm bg-white focus:outline-none focus:border-blue-500">
                        <option value="">All Statuses / Profiles</option>
                        <option value="Independent">Independent</option>
                        <option value="Instructional">Instructional</option>
                        <option value="Frustration">Frustration</option>
                        <option value="Non-Reader">Non-Reader</option>
                        <option value="Pending">Pending</option>
                    </select>
                </div>
                
                <div class="flex justify-end gap-2 pt-4 border-t border-slate-100">
                    <button type="button" onclick="closeReportModal()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-sm font-medium transition">Cancel</button>
                    <button type="submit" onclick="setTimeout(closeReportModal, 500)" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-bold transition shadow-sm flex items-center">
                        <i class="fas fa-file-excel mr-1.5"></i> Download .xlsx Report
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Custom Table Filter & Modal Logic -->
    <script src="v536/public/table_filter.js"></script>
    <script>
        function openReportModal() {
            document.getElementById('reportModal').classList.remove('hidden');
        }
        function closeReportModal() {
            document.getElementById('reportModal').classList.add('hidden');
        }

        function switchMobileDashboardTable(selected) {
            const map = {
                masterlist: document.getElementById('masterlistCard'),
                pretest: document.getElementById('pretestCard'),
                posttest: document.getElementById('posttestCard')
            };
            Object.keys(map).forEach(key => {
                const el = map[key];
                if (!el) return;
                if (key === selected) {
                    el.classList.remove('hidden');
                    el.classList.add('flex');
                } else {
                    el.classList.remove('flex');
                    el.classList.add('hidden');
                }
            });
        }

        function filterSimpleTable(tableId, query) {
            const q = (query || '').toLowerCase().trim();
            const rows = document.querySelectorAll('#' + tableId + ' tbody tr');
            rows.forEach(row => {
                if (row.cells.length <= 1) return;
                const text = row.innerText.toLowerCase();
                row.style.display = (!q || text.includes(q)) ? '' : 'none';
            });
        }

        function applyMasterlistFilters() {
            const q = (document.getElementById('masterlistSearch')?.value || '').toLowerCase();
            const grade = document.getElementById('masterlistGradeFilter')?.value || '';
            const sec = document.getElementById('masterlistSectionFilter')?.value || '';
            const gender = document.getElementById('masterlistGenderFilter')?.value || '';
            const prof = document.getElementById('masterlistProfileFilter')?.value || '';
            
            const rows = document.querySelectorAll('#masterlistTable tbody tr');
            rows.forEach(row => {
                if (row.cells.length <= 1) return;
                const rowGrade = row.getAttribute('data-grade') || '';
                const rowSec = row.getAttribute('data-section') || '';
                const rowGender = row.getAttribute('data-gender') || '';
                const rowProf = row.getAttribute('data-profile') || '';
                const rowText = row.innerText.toLowerCase();
                
                const matchQ = !q || rowText.includes(q);
                const matchGrade = !grade || rowGrade.toLowerCase() === grade.toLowerCase();
                const matchSec = !sec || rowSec.toLowerCase() === sec.toLowerCase();
                const matchGender = !gender || rowGender.toLowerCase() === gender.toLowerCase();
                const matchProf = !prof || rowProf.toLowerCase() === prof.toLowerCase();
                
                row.style.display = (matchQ && matchGrade && matchSec && matchGender && matchProf) ? '' : 'none';
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            // Initialize header sorting for columns
            const headers = document.querySelectorAll('#masterlistTable thead th');
            headers.forEach((header, index) => {
                header.style.cursor = 'pointer';
                header.innerHTML += ' <i class="fas fa-sort text-slate-300 ml-1 text-[10px]"></i>';
                let asc = true;
                header.addEventListener('click', () => {
                    const tbody = document.querySelector('#masterlistTable tbody');
                    const rows = Array.from(tbody.querySelectorAll('tr'));
                    
                    headers.forEach(h => {
                        const icon = h.querySelector('i.fa-sort, i.fa-sort-up, i.fa-sort-down');
                        if(icon) { icon.className = 'fas fa-sort text-slate-300 ml-1 text-[10px]'; }
                    });
                    
                    const currentIcon = header.querySelector('i');
                    if (currentIcon) {
                        currentIcon.className = asc ? 'fas fa-sort-up text-blue-500 ml-1 text-[10px]' : 'fas fa-sort-down text-blue-500 ml-1 text-[10px]';
                    }

                    rows.sort((a, b) => {
                        if (a.cells.length <= 1 || b.cells.length <= 1) return 0;
                        const aCol = a.cells[index].textContent.trim();
                        const bCol = b.cells[index].textContent.trim();
                        const aNum = parseFloat(aCol.replace(/[^0-9.-]/g, ''));
                        const bNum = parseFloat(bCol.replace(/[^0-9.-]/g, ''));
                        if (!isNaN(aNum) && !isNaN(bNum)) {
                            return asc ? aNum - bNum : bNum - aNum;
                        }
                        return asc ? aCol.localeCompare(bCol) : bCol.localeCompare(aCol);
                    });

                    rows.forEach(row => tbody.appendChild(row));
                    asc = !asc;
                });
            });
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
