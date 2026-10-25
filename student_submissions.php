<?php
session_start();
require_once 'config.php';

// RBAC Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: index.php");
    exit;
}

$student_id = $_GET['user_id'] ?? 0;

// Fetch student details
$stmt = $pdo->prepare("SELECT fname, lname, grade_level, section FROM users WHERE id = ?");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    die("Student not found.");
}

// Fetch all pre-assessment attempts for this student
// Fetch final profile
$stmt = $pdo->prepare("SELECT * FROM reading_profiles WHERE user_id = ?");
$stmt->execute([$student_id]);
$profile = $stmt->fetch();

// Calculate Final Status
$final_status = 'Pending';
$status_bg = 'bg-slate-50 border-slate-200';
$status_text = 'text-slate-800';
$icon = 'fa-clock text-slate-400';

// Fetch GST Result
$stmtGST = $pdo->prepare("SELECT * FROM gst_results WHERE user_id = ? ORDER BY completed_at DESC LIMIT 1");
$stmtGST->execute([$student_id]);
$gst_result = $stmtGST->fetch();

if ($profile || ($gst_result && $gst_result['needs_individual_assessment'] == 0)) {
    // Calculate Phil-IRI Oral Reading Profile based on Enrolled Grade Level
    $enrolled_grade = intval(preg_replace('/[^0-9]/', '', $student['grade_level']));
    $final_status = 'Pending';
    
    if (isset($gst_result['needs_individual_assessment']) && $gst_result['needs_individual_assessment'] == 0) {
        $final_status = 'Independent';
    } elseif ($profile && ($profile['independent_grade'] !== null || $profile['instructional_grade'] !== null || $profile['frustration_grade'] !== null)) {
        if ($profile['independent_grade'] !== null && $enrolled_grade <= intval($profile['independent_grade'])) {
            $final_status = 'Independent';
        } elseif ($profile['instructional_grade'] !== null && $enrolled_grade <= intval($profile['instructional_grade'])) {
            $final_status = 'Instructional';
        } else {
            if ($profile['frustration_grade'] !== null && intval($profile['frustration_grade']) <= 4 && $profile['instructional_grade'] === null && $profile['independent_grade'] === null) {
                $final_status = 'Non-Reader';
            } else {
                $final_status = 'Frustration';
            }
        }
    }
    
    $display_verdict = $final_status;
    
    // Set colors
    if ($final_status === 'Frustration') {
        $status_bg = 'bg-rose-50 border-rose-200';
        $status_text = 'text-rose-800';
        $icon = 'fa-exclamation-circle text-rose-500';
    } elseif ($final_status === 'Instructional') {
        $status_bg = 'bg-amber-50 border-amber-200';
        $status_text = 'text-amber-800';
        $icon = 'fa-info-circle text-amber-500';
    } elseif ($final_status === 'Independent') {
        $status_bg = 'bg-emerald-50 border-emerald-200';
        $status_text = 'text-emerald-800';
        $icon = 'fa-check-circle text-emerald-500';
    } elseif ($final_status === 'Non-Reader') {
        $status_bg = 'bg-slate-700 border-slate-800';
        $status_text = 'text-white';
        $icon = 'fa-times-circle text-white';
    }
}

$stmt = $pdo->prepare("
    SELECT a.*, c.title as course_title 
    FROM reading_attempts a
    LEFT JOIN course_assessments c ON a.passage_id = c.id
    WHERE a.user_id = ? AND a.phase = 'Pre-Test'
    ORDER BY a.created_at DESC
");
$stmt->execute([$student_id]);
$attempts = $stmt->fetchAll();




?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="https://tibungcodistrict.wordpress.com/wp-content/uploads/2018/06/untitled-1.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Submissions - Teacher Dashboard</title>
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


    <script src="js/htmx.min.js"></script>
    <meta name="htmx-config" content='{"globalViewTransitions":true}'>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="flex h-screen overflow-hidden text-slate-800">
    <?php include 'teacher_sidebar.php'; ?>
    <div class="flex-1 flex flex-col h-screen overflow-hidden">
    
    <header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-4 md:px-8 shrink-0 gap-2">
        <div class="flex items-center min-w-0 flex-1">
            <button onclick="toggleSidebar()" class="md:hidden mr-3 text-slate-500 hover:text-blue-600 focus:outline-none shrink-0"><i class="fas fa-bars text-xl"></i></button>
            <a href="assessment_result.php" class="text-slate-400 hover:text-slate-600 mr-3 shrink-0"><i class="fas fa-arrow-left"></i></a>
            <?php 
                $stu_img_path = "uploads/profiles/user_" . $student_id . ".jpg";
                $stu_img = file_exists($stu_img_path) ? $stu_img_path . "?v=" . filemtime($stu_img_path) : null;
            ?>
            <?php if ($stu_img): ?>
                <img src="<?php echo htmlspecialchars($stu_img); ?>" alt="<?php echo htmlspecialchars($student['fname']); ?>" class="w-8 h-8 md:w-9 md:h-9 rounded-full object-cover border border-slate-200 mr-2.5 shrink-0">
            <?php else: ?>
                <div class="w-8 h-8 md:w-9 md:h-9 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold mr-2.5 text-sm shrink-0">
                    <?php echo strtoupper(substr($student['fname'] ?? 'S', 0, 1)); ?>
                </div>
            <?php endif; ?>
            <h1 class="font-bold text-sm sm:text-base md:text-lg text-slate-800 truncate">Passage Attempts: <?php echo htmlspecialchars($student['fname'] . ' ' . $student['lname']); ?></h1>
        </div>
        <div class="flex items-center space-x-3 shrink-0">
            <?php 
                $teacher_img_path = "uploads/profiles/user_" . $_SESSION['user_id'] . ".jpg";
                $teacher_img = file_exists($teacher_img_path) ? $teacher_img_path . "?v=" . filemtime($teacher_img_path) : null;
            ?>
            <?php if ($teacher_img): ?>
                <img src="<?php echo htmlspecialchars($teacher_img); ?>" alt="Profile" class="w-9 h-9 md:w-10 md:h-10 rounded-full object-cover border border-slate-200">
            <?php else: ?>
                <div class="w-9 h-9 md:w-10 md:h-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 font-bold">
                    <?php echo substr($_SESSION['fname'], 0, 1); ?>
                </div>
            <?php endif; ?>
            <div class="leading-tight hidden sm:block">
                <p class="font-semibold text-sm"><?php echo htmlspecialchars($_SESSION['fname']); ?></p>
                <p class="text-xs text-slate-500 capitalize"><?php echo htmlspecialchars($_SESSION['role']); ?></p>
            </div>
        </div>
    </header>

    <main class="flex-1 p-4 md:p-8 max-w-6xl mx-auto w-full overflow-y-auto">
        
        <?php if (isset($display_verdict)): ?>
        <div class="mb-6 rounded-xl border p-6 flex flex-col md:flex-row md:items-center justify-between <?php echo $status_bg; ?>">
            <div class="flex items-center gap-4">
                <i class="fas <?php echo $icon; ?> text-4xl"></i>
                <div>
                    <p class="text-sm font-semibold uppercase tracking-wider <?php echo $status_text; ?> opacity-80">Oral Reading</p>
                    <h2 class="text-3xl font-black <?php echo $status_text; ?>"><?php echo $display_verdict; ?></h2>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($gst_result): ?>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 mb-6">
            <h2 class="text-xl font-bold text-slate-800 mb-4 border-b pb-2">Group Screening Test (GST) Result</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <p class="text-sm text-slate-500 mb-1">Enrolled Grade</p>
                    <p class="font-bold text-slate-800">Grade <?php echo htmlspecialchars($gst_result['grade_level']); ?></p>
                </div>
                <div>
                    <p class="text-sm text-slate-500 mb-1">GST Score</p>
                    <p class="font-bold text-slate-800"><?php echo htmlspecialchars($gst_result['score'] . ' / ' . $gst_result['total_items']); ?></p>
                </div>
                <div>
                    <p class="text-sm text-slate-500 mb-1">Phil-IRI Category</p>
                    <p class="font-bold text-slate-800">
                        <?php 
                        if (!empty($gst_result['gst_category'])) {
                            echo htmlspecialchars($gst_result['gst_category']);
                        } else {
                            if ($gst_result['score'] >= 14) {
                                echo "Independent";
                            } else {
                                echo "Needs Individual Assessment";
                            }
                        }
                        ?>
                    </p>
                </div>
                <div>
                    <p class="text-sm text-slate-500 mb-1">Assigned Starting Grade</p>
                    <p class="font-bold <?php echo $gst_result['needs_individual_assessment'] ? 'text-blue-600' : 'text-slate-800'; ?>">
                        <?php echo $gst_result['needs_individual_assessment'] ? 'Grade ' . htmlspecialchars($gst_result['starting_grade']) : 'N/A'; ?>
                    </p>
                </div>
            </div>
            <?php if ($gst_result['needs_individual_assessment']): ?>
                <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-800">
                    <p class="mb-1"><strong><i class="fas fa-info-circle mr-1"></i> Individualized Reading Assessment: REQUIRED.</strong></p>
                    <ul class="list-disc pl-5 mt-1 space-y-1">
                        <li>The student scored below 14 on the GST.</li>
                        <li>They must take individualized passages starting at <strong>Grade <?php echo htmlspecialchars($gst_result['starting_grade']); ?></strong> based on the Phil-IRI cascading rules.</li>
                        <li>The system will continue presenting passages until they score <strong>Instructional or Independent at their Enrolled Grade</strong>, OR until they hit <strong>Frustration</strong>, identifying their final oral reading profile.</li>
                    </ul>
                </div>
            <?php else: ?>
                <div class="mt-4 p-3 bg-emerald-50 border border-emerald-200 rounded-lg">
                    <p class="text-sm text-emerald-800"><i class="fas fa-check-circle mr-2"></i><strong>Individualized Reading Assessment: NOT REQUIRED.</strong> The student scored 14 or higher (Independent) on their enrolled grade's GST. No further cascading assessment is needed.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 mb-6">
            <h2 class="text-xl font-bold text-slate-800 mb-2 border-b pb-2">Group Screening Test (GST) Result</h2>
            <p class="text-slate-500">No GST result found for this student.</p>
        </div>
        <?php endif; ?>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <h2 class="text-xl font-bold text-slate-800 mb-2">Individual Passages</h2>
            <p class="text-slate-500 mb-6">Review the specific audio recordings and transcripts that generated the student's profile.</p>
            
            <?php if (count($attempts) > 0): ?>
                <div class="overflow-x-visible md:overflow-x-auto">
                    <table class="mobile-card-table w-full text-sm text-left border-collapse">
                        <thead class="text-xs text-slate-500 bg-slate-50 border-y border-slate-200">
                            <tr>
                                <th class="py-3 px-4 font-medium">Passage</th>
                                <th class="py-3 px-4 font-medium">Date Submitted</th>
                                <th class="py-3 px-4 font-medium">Accuracy</th>
                                <th class="py-3 px-4 font-medium">Comprehension</th>
                                <th class="py-3 px-4 font-medium">System Classification</th>
                                <th class="py-3 px-4 font-medium text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach($attempts as $att): 
                                $date = date('M d, Y g:i A', strtotime($att['created_at']));
                                $profile = $att['oral_reading_profile'];
                                $profileClass = $profile == 'Independent' ? 'bg-emerald-100 text-emerald-800' : ($profile == 'Instructional' ? 'bg-amber-100 text-amber-800' : 'bg-rose-100 text-rose-800');
                            ?>
                            <tr class="hover:bg-slate-50">
                                <td class="py-3 px-4 font-bold text-slate-800" data-label="Passage">
    <?php 
        if ($att['phase'] === 'Pre-Test') {
            echo "Phil-IRI: Grade " . htmlspecialchars($att['passage_grade']);
        } else {
            echo htmlspecialchars($att['course_title'] . " (" . $att['phase'] . ")");
        }
    ?>
</td>
                                <td class="py-3 px-4 text-slate-500" data-label="Date Submitted"><?php echo $date; ?></td>
                                <td class="py-3 px-4 font-medium" data-label="Accuracy"><?php echo number_format($att['accuracy_score'], 1); ?>%</td>
                                <td class="py-3 px-4 font-medium" data-label="Comprehension"><?php echo number_format($att['comprehension_score'], 1); ?>%</td>
                                <td class="py-3 px-4" data-label="System Classification"><span class="inline-block whitespace-nowrap px-2 py-0.5 rounded-full text-xs font-medium <?php echo $profileClass; ?>"><?php echo $profile; ?></span></td>
                                <td class="py-3 px-4 text-right" data-label="Action">
                                    <a href="review_detail.php?id=<?php echo $att['id']; ?>&sid=<?php echo $student_id; ?>" class="inline-flex items-center px-3 py-1.5 bg-rose-50 text-rose-600 hover:bg-rose-100 rounded text-xs font-medium transition">
                                        <i class="fas fa-headphones mr-1.5"></i> Review Audio
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-8">
                    <p class="text-slate-500">No individual passages found for this student.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

        </div>
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
