<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: index.php");
    exit;
}

$attempt_id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("
    SELECT a.*, u.fname, u.lname, u.grade_level, u.section 
    FROM reading_attempts a
    JOIN users u ON a.user_id = u.id
    WHERE a.id = ?
");
$stmt->execute([$attempt_id]);
$attempt = $stmt->fetch();

if (!$attempt) {
    die("Assessment not found.");
}

$eval_data = $attempt['evaluation_data'] ?: '{}';

// Fetch questions and passage text based on phase
$q_json = '[]';
$passage_text = '';
if (strpos($attempt['phase'], 'Course') !== false) {
    $stmt = $pdo->prepare("SELECT questions_json, passage_text FROM course_assessments WHERE id = ?");
    $stmt->execute([$attempt['passage_id']]);
    $row = $stmt->fetch();
    if ($row) {
        $q_json = $row['questions_json'] ?: '[]';
        $passage_text = $row['passage_text'] ?: '';
    }
} else {
    $stmt = $pdo->prepare("SELECT questions_json, passage_text FROM reading_passages WHERE id = ?");
    $stmt->execute([$attempt['passage_id']]);
    $row = $stmt->fetch();
    if ($row) {
        $q_json = $row['questions_json'] ?: '[]';
        $passage_text = $row['passage_text'] ?: '';
    }
}
$answers_json = $attempt['answers_json'] ?: '{}';
$has_ops = strpos($eval_data, 'ops') !== false;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="https://tibungcodistrict.wordpress.com/wp-content/uploads/2018/06/untitled-1.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Assessment - Teacher Dashboard</title>
    <!-- Use Tailwind for main layout, but keep Bootstrap for app.js DOM compatibility -->
    <script>
        const originalWarn = console.warn;
        console.warn = function() {
            if (arguments[0] && typeof arguments[0] === 'string' && arguments[0].includes('cdn.tailwindcss.com should not be used in production')) return;
            originalWarn.apply(console, arguments);
        };
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="v536/public/style.css?v=2.0.30">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .override-btn:hover { background-color: #f1f5f9; cursor: pointer; }
        .transcript-token.match { color: inherit; text-decoration: none; border: none; }
        .transcript-token.match:hover { background: #f3f6fb; }
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
    <div class="flex-1 flex flex-col h-screen overflow-y-auto">
    <header class="min-h-[4rem] py-2 bg-white border-b border-slate-200 flex flex-wrap items-center justify-between gap-2 px-4 md:px-8">
        <div class="flex items-center min-w-0 flex-1">
            <button onclick="toggleSidebar()" class="md:hidden text-slate-600 hover:text-slate-900 mr-3 focus:outline-none shrink-0"><i class="fas fa-bars text-xl"></i></button>
            <?php 
                $sid = $_GET['sid'] ?? $attempt['user_id']; 
                $return_url = "student_submissions.php?user_id=" . $sid;
                if (isset($_GET['return']) && $_GET['return'] === 'grades') {
                    $return_url = "teacher_student_grades.php?user_id=" . $sid;
                }
            ?>
            <a href="<?php echo $return_url; ?>" class="text-slate-400 hover:text-slate-600 mr-3 shrink-0"><i class="fas fa-arrow-left"></i></a>
            <i class="fas fa-search text-blue-500 text-xl md:text-2xl mr-2.5 hidden sm:inline-block shrink-0"></i>
            <h1 class="font-bold text-sm sm:text-base md:text-lg text-slate-800 truncate">Reviewing: <?php echo htmlspecialchars(($attempt['fname'] ?? '') . ' ' . ($attempt['lname'] ?? '')); ?> (Grade <?php echo htmlspecialchars($attempt['passage_grade'] ?? 'N/A'); ?>)</h1>
        </div>
        <div class="shrink-0 w-full sm:w-auto flex justify-end">
            <button id="saveOverridesBtn" class="w-full sm:w-auto px-3 sm:px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-sm rounded font-medium shadow transition">Save Official Scores</button>
        </div>
    </header>

    <main class="p-4 md:p-8 max-w-6xl mx-auto w-full flex flex-col lg:flex-row gap-6">
        <!-- Left Column: Audio and Stats -->
        <div class="w-full lg:w-1/3 flex flex-col gap-6">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 md:p-6">
                <h3 class="font-bold text-slate-800 mb-4 border-b pb-2">Audio Recording</h3>
                <?php if ($attempt['audio_path']): ?>
                    <audio src="<?php echo htmlspecialchars($attempt['audio_path'] ?? ''); ?>" controls class="w-full"></audio>
                <?php else: ?>
                    <p class="text-slate-500 text-sm">No audio recording available for this attempt.</p>
                <?php endif; ?>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 md:p-6">
                <h3 class="font-bold text-slate-800 mb-4 border-b pb-2">Performance Scores</h3>
                
                <div class="grid grid-cols-2 gap-3 sm:gap-4 mb-4">
                    <div class="bg-slate-50 p-3 rounded text-center">
                        <div class="text-xs text-slate-500 uppercase font-bold">Accuracy</div>
                        <h4 id="readingAccuracy" class="text-lg sm:text-xl font-bold text-slate-800"><?php echo number_format($attempt['accuracy_score'], 1); ?>%</h4>
                    </div>
                    <div class="bg-slate-50 p-3 rounded text-center">
                        <div class="text-xs text-slate-500 uppercase font-bold">WCPM</div>
                        <h4 id="wpm" class="text-lg sm:text-xl font-bold text-slate-800"><?php echo number_format($attempt['reading_speed'], 1); ?></h4>
                    </div>
                    <div class="bg-slate-50 p-3 rounded text-center">
                        <div class="text-xs text-slate-500 uppercase font-bold">Comprehension</div>
                        <h4 class="text-lg sm:text-xl font-bold text-slate-800"><?php echo number_format($attempt['comprehension_score'], 1); ?>%</h4>
                    </div>
                    <div class="bg-slate-50 p-3 rounded text-center">
                        <div class="text-xs text-slate-500 uppercase font-bold">System Profile</div>
                        <h4 id="passageClassification" class="text-sm sm:text-md font-bold text-slate-800 mt-1"><?php echo htmlspecialchars($attempt['oral_reading_profile'] ?? 'Pending'); ?></h4>
                    </div>
                </div>

                <div id="miscueStrip" class="d-flex justify-content-center gap-2 flex-wrap"></div>
            </div>
            
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 md:p-6">
                <p class="text-sm text-slate-600 leading-relaxed mb-0">
                    <i class="fas fa-info-circle text-blue-500 mr-1"></i>
                    <strong>Word Assessment &amp; Teacher Override:</strong> Tap or click any word in the transcript to inspect its pronunciation details, then press <strong>Override Word Grading</strong> inside the tooltip (or double-click a word on desktop) to manually change its grading. When finished, click <strong>Save Official Scores</strong>.
                </p>
            </div>
        </div>

        <!-- Right Column: The Transcript -->
        <div class="w-full lg:w-2/3">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 md:p-6 min-h-[320px] lg:min-h-[600px]">
                <h3 class="font-bold text-slate-800 mb-4 border-b pb-2">Diagnostic Transcript</h3>
                <article id="transcript" class="story text-muted mt-4" style="font-size: 1.15rem; line-height: 2.6; overflow-wrap: break-word;"></article>
                <div id="transcriptNotice" hidden></div>
            </div>
        </div>
    </main>

    <!-- Comprehension & Essay Grading -->
    <div class="max-w-6xl mx-auto w-full px-4 md:px-8 pb-8">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 md:p-6">
            <h3 class="font-bold text-slate-800 mb-4 border-b pb-2 flex flex-wrap justify-between items-center gap-2">
                <span>Comprehension & Essay Grading</span>
                <span class="text-sm font-normal text-slate-500">Auto-calculated: <strong id="compAutoScore" class="text-blue-600"></strong></span>
            </h3>
            <div id="comprehensionList" class="space-y-6 mt-4">
                <!-- Javascript will render questions here -->
            </div>
        </div>
    </div>


    <!-- Hidden DOM Elements required by app.js rendering logic -->
    <div style="display:none;">
        <article id="story"></article>
        <button id="startBtn"></button><button id="stopBtn"></button><button id="resetBtn"></button><button id="editStoryBtn"></button><button id="restartBtn"></button><button id="diagBtn"></button><div id="diagModal"></div><button id="closeDiagBtn"></button><div id="diagContent"></div><select id="locale"></select>
        <span id="statusPill"></span><span id="hint"></span><h4 id="timer"></h4><div id="meterBar"></div><span id="vadDot"></span><span id="liveBadge"></span><h4 id="storyWordCount"></h4><span id="wordBadge"></span>
    </div>
    <div id="tooltip" class="word-tooltip" role="tooltip" hidden></div>

    <!-- Override Modal -->
    <div id="overrideModal" class="fixed inset-0 bg-slate-900 bg-opacity-50 hidden flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-sm max-h-[90vh] overflow-y-auto">
            <div class="px-6 py-4 border-b flex justify-between items-center">
                <h3 class="font-bold text-lg text-slate-800">Override Word: <span id="overrideTargetWord" class="text-blue-600"></span></h3>
                <button id="closeOverrideBtn" class="text-slate-400 hover:text-slate-600"><i class="fas fa-times"></i></button>
            </div>
            <div class="p-4 flex flex-col gap-2">
                <input type="hidden" id="overrideWordIndex">
                <button class="override-btn border p-3 text-left rounded font-medium flex justify-between items-center" data-type="match">
                    <span>Correct (No Error)</span><i class="fas fa-check text-emerald-500"></i>
                </button>
                <button class="override-btn border p-3 text-left rounded font-medium flex justify-between items-center bg-rose-50 border-rose-200" data-type="mis">
                    <span>Mispronunciation</span><i class="fas fa-exclamation-triangle text-rose-500"></i>
                </button>
                <button class="override-btn border p-3 text-left rounded font-medium flex justify-between items-center bg-slate-100 border-slate-300" data-type="omit">
                    <span>Omission</span><i class="fas fa-minus-circle text-slate-500"></i>
                </button>
                <button class="override-btn border p-3 text-left rounded font-medium flex justify-between items-center bg-amber-50 border-amber-200" data-type="sub">
                    <span>Substitution</span><i class="fas fa-exchange-alt text-amber-500"></i>
                </button>
                <button class="override-btn border p-3 text-left rounded font-medium flex justify-between items-center bg-emerald-50 border-emerald-200" data-type="insert">
                    <span>Insertion</span><i class="fas fa-plus-circle text-emerald-500"></i>
                </button>
                <button class="override-btn border p-3 text-left rounded font-medium flex justify-between items-center bg-yellow-50 border-yellow-200" data-type="repeat">
                    <span>Repetition</span><i class="fas fa-redo text-yellow-500"></i>
                </button>
                <button class="override-btn border p-3 text-left rounded font-medium flex justify-between items-center bg-purple-50 border-purple-200" data-type="trans">
                    <span>Transposition</span><i class="fas fa-random text-purple-500"></i>
                </button>
                <button class="override-btn border p-3 text-left rounded font-medium flex justify-between items-center bg-pink-50 border-pink-200" data-type="reverse">
                    <span>Reversal</span><i class="fas fa-arrows-alt-h text-pink-500"></i>
                </button>
                <button class="override-btn border p-3 text-left rounded font-medium flex justify-between items-center bg-blue-50 border-blue-200" data-type="self-corrected">
                    <span>Self-Corrected</span><i class="fas fa-undo text-blue-500"></i>
                </button>
            </div>
        </div>
    </div>

    <script src="v536/public/assessment-core.js?v=2.0.30"></script>
    <script src="v536/public/app.js?v=2.0.30"></script>
    <script>
        window.enableTeacherOverrideInTooltip = true;
        // Use JSON constants to safely encode data without causing JS SyntaxErrors (e.g. escaping apostrophes, tags, ampersands, and double quotes)
        let rawReviewData = <?php echo json_encode($eval_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        window.reviewData = typeof rawReviewData === 'string' ? (rawReviewData ? JSON.parse(rawReviewData) : {}) : rawReviewData;
        const attemptId = <?php echo $attempt_id; ?>;
        let activeAssessment = null;
        if (window.reviewData && window.reviewData.assessment) {
            activeAssessment = window.reviewData.assessment;
        } else if (window.reviewData && window.reviewData.ops) {
            activeAssessment = window.reviewData;
        }
        if (activeAssessment) {
            window.lastAssessment = activeAssessment;
        }
        
        let rawQJson = <?php echo json_encode($q_json, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const qJson = typeof rawQJson === 'string' ? (rawQJson ? JSON.parse(rawQJson) : []) : rawQJson;
        
        let rawAns = <?php echo json_encode($answers_json, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const aJson = typeof rawAns === 'string' ? (rawAns ? JSON.parse(rawAns) : {}) : rawAns;
        
        const rawPassageText = <?php echo json_encode($passage_text); ?>;
        function normStr(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/[\u201C\u201D]/g, '"')
                .replace(/[\u2018\u2019]/g, "'")
                .trim()
                .toLowerCase();
        }

        if (typeof escapeHtml !== 'function') {
            function escapeHtml(text) {
                if (text === null || text === undefined) return '';
                return String(text).replace(/&/g, "&amp;")
                                   .replace(/</g, "&lt;")
                                   .replace(/>/g, "&gt;")
                                   .replace(/"/g, "&quot;")
                                   .replace(/'/g, "&#039;");
            }
        }

        function resolveStudentChoice(q, sAns) {
            let rawAns = sAns;
            if (sAns && typeof sAns === 'object' && !Array.isArray(sAns) && sAns.answer !== undefined) {
                rawAns = sAns.answer;
            }
            if (rawAns === undefined || rawAns === null || rawAns === '') {
                return { chosenIdx: -1, chosenText: '', hasAnswer: false };
            }

            if (q.options && Array.isArray(q.options) && q.options.length > 0) {
                const normRaw = normStr(rawAns);

                // 1. Text match in options
                const textMatchIdx = q.options.findIndex(opt => normStr(opt) === normRaw);
                if (textMatchIdx !== -1) {
                    return { chosenIdx: textMatchIdx, chosenText: q.options[textMatchIdx], hasAnswer: true };
                }

                // 2. Numeric index
                const trimmedStr = String(rawAns).trim();
                if (!isNaN(Number(trimmedStr)) && Number(trimmedStr) >= 0 && Number(trimmedStr) < q.options.length) {
                    const idx = parseInt(trimmedStr, 10);
                    return { chosenIdx: idx, chosenText: q.options[idx], hasAnswer: true };
                }

                // 3. Letter match
                if (/^[a-zA-Z]$/.test(trimmedStr)) {
                    const lIdx = trimmedStr.toUpperCase().charCodeAt(0) - 65;
                    if (lIdx >= 0 && lIdx < q.options.length) {
                        return { chosenIdx: lIdx, chosenText: q.options[lIdx], hasAnswer: true };
                    }
                }

                return { chosenIdx: -1, chosenText: String(rawAns), hasAnswer: true };
            }

            return { chosenIdx: -1, chosenText: rawAns, hasAnswer: true };
        }

        function resolveCorrectChoice(q) {
            if (q.options && Array.isArray(q.options) && q.options.length > 0) {
                const correctRaw = q.correct !== undefined ? q.correct : '';
                const normCorrect = normStr(correctRaw);

                // 1. Numeric index
                const trimmedStr = String(correctRaw).trim();
                if (!isNaN(Number(trimmedStr)) && Number(trimmedStr) >= 0 && Number(trimmedStr) < q.options.length) {
                    const idx = parseInt(trimmedStr, 10);
                    return { correctIdx: idx, correctText: q.options[idx] };
                }

                // 2. Text match
                const textMatchIdx = q.options.findIndex(opt => normStr(opt) === normCorrect);
                if (textMatchIdx !== -1) {
                    return { correctIdx: textMatchIdx, correctText: q.options[textMatchIdx] };
                }

                // 3. Letter match
                if (/^[a-zA-Z]$/.test(trimmedStr)) {
                    const lIdx = trimmedStr.toUpperCase().charCodeAt(0) - 65;
                    if (lIdx >= 0 && lIdx < q.options.length) {
                        return { correctIdx: lIdx, correctText: q.options[lIdx] };
                    }
                }

                return { correctIdx: -1, correctText: String(correctRaw) };
            }

            return { correctIdx: -1, correctText: String(q.correct !== undefined ? q.correct : '') };
        }

        function renderComprehensionReview() {
            const container = document.getElementById('comprehensionList');
            if (!container) return;
            if (!qJson || qJson.length === 0) {
                container.innerHTML = '<div class="text-slate-500 text-sm italic">No comprehension questions found for this attempt.</div>';
                return;
            }
            
            let html = '';
            qJson.forEach((q, idx) => {
                let sAns = aJson[idx];
                const pts = q.type === 'essay' ? (parseInt(q.points) || 30) : 
                            (q.type === 'enumeration' ? (q.answers?.length || parseInt(q.count) || 1) : 1);
                
                let rawStudentAns = sAns;
                if (sAns && typeof sAns === 'object' && !Array.isArray(sAns) && sAns.answer !== undefined) { 
                    rawStudentAns = sAns.answer; 
                    sAns = sAns.answer; 
                }

                const isEssay = q.type === 'essay';
                const isEnum = q.type === 'enumeration';
                const isTrueFalse = q.type === 'truefalse';
                const isMulti = q.type === 'multichoice' || (!q.type && q.options) || (q.options && Array.isArray(q.options) && q.options.length > 0);

                let statusBadge = '';
                let contentHtml = '';

                if (isMulti) {
                    const student = resolveStudentChoice(q, rawStudentAns);
                    const correct = resolveCorrectChoice(q);
                    const isCorrect = student.hasAnswer && student.chosenIdx !== -1 && student.chosenIdx === correct.correctIdx;

                    if (!student.hasAnswer) {
                        statusBadge = '<span class="px-2.5 py-1 text-xs font-bold rounded-full bg-slate-100 text-slate-600 border border-slate-200"><i class="fas fa-minus-circle mr-1 text-slate-400"></i>No Answer (0 pt)</span>';
                    } else if (isCorrect) {
                        statusBadge = '<span class="px-2.5 py-1 text-xs font-bold rounded-full bg-emerald-100 text-emerald-800 border border-emerald-200"><i class="fas fa-check-circle mr-1 text-emerald-600"></i>Correct (+1 pt)</span>';
                    } else {
                        statusBadge = '<span class="px-2.5 py-1 text-xs font-bold rounded-full bg-rose-100 text-rose-800 border border-rose-200"><i class="fas fa-times-circle mr-1 text-rose-600"></i>Incorrect (0 pt)</span>';
                    }

                    // Comparison summary banner
                    const studentLabel = student.chosenIdx !== -1 ? String.fromCharCode(65 + student.chosenIdx) + '. ' + student.chosenText : 
                                         (student.hasAnswer ? student.chosenText : '<i class="text-slate-400 font-normal">No answer submitted</i>');
                    const correctLabel = correct.correctIdx !== -1 ? String.fromCharCode(65 + correct.correctIdx) + '. ' + correct.correctText : correct.correctText;

                    const studentBoxBg = !student.hasAnswer ? 'bg-slate-50 border-slate-200' : (isCorrect ? 'bg-emerald-50/60 border-emerald-200' : 'bg-rose-50/60 border-rose-200');
                    const studentIcon = !student.hasAnswer ? '<i class="fas fa-minus text-slate-400"></i>' : (isCorrect ? '<i class="fas fa-check text-emerald-600"></i>' : '<i class="fas fa-times text-rose-600"></i>');
                    const studentTextClass = !student.hasAnswer ? 'text-slate-500' : (isCorrect ? 'text-emerald-800' : 'text-rose-800');

                    contentHtml += `
                    <div class="mb-4 p-3 sm:p-4 rounded-xl border border-slate-200 bg-white grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                        <div class="p-3 rounded-lg border ${studentBoxBg} flex items-start gap-3">
                            <span class="w-7 h-7 rounded-full bg-white flex items-center justify-center font-bold shrink-0 shadow-xs mt-0.5">
                                ${studentIcon}
                            </span>
                            <div class="min-w-0 flex-1">
                                <span class="font-bold uppercase tracking-wider text-slate-500 block text-[11px] mb-1">Student's Answer</span>
                                <span class="text-sm font-semibold break-words leading-snug block ${studentTextClass}">${student.hasAnswer ? escapeHtml(studentLabel) : studentLabel}</span>
                            </div>
                        </div>
                        <div class="p-3 rounded-lg border bg-emerald-50/60 border-emerald-200 flex items-start gap-3">
                            <span class="w-7 h-7 rounded-full bg-white text-emerald-600 flex items-center justify-center font-bold shrink-0 shadow-xs mt-0.5">
                                <i class="fas fa-check"></i>
                            </span>
                            <div class="min-w-0 flex-1">
                                <span class="font-bold uppercase tracking-wider text-emerald-700 block text-[11px] mb-1">Correct Answer</span>
                                <span class="text-sm font-semibold text-emerald-900 break-words leading-snug block">${escapeHtml(correctLabel)}</span>
                            </div>
                        </div>
                    </div>
                    `;

                    // Options list
                    contentHtml += '<div class="space-y-2.5">';
                    q.options.forEach((opt, oIdx) => {
                        const isStudentChoice = (oIdx === student.chosenIdx);
                        const isTheCorrect = (oIdx === correct.correctIdx);
                        const letter = String.fromCharCode(65 + oIdx);

                        let optClasses = 'p-3 sm:p-3.5 rounded-xl border text-sm flex flex-col sm:flex-row sm:items-center justify-between gap-2 transition-colors ';
                        let leftHtml = '';
                        let tagHtml = '';

                        if (isStudentChoice && isTheCorrect) {
                            optClasses += 'bg-emerald-50 border-emerald-300 text-emerald-900 font-semibold ring-1 ring-emerald-300';
                            leftHtml = `<div class="flex items-start gap-2.5 min-w-0"><i class="fas fa-check-circle text-emerald-600 text-base mt-0.5 shrink-0"></i><strong class="text-emerald-800 shrink-0">${letter}.</strong><span class="break-words leading-relaxed">${escapeHtml(opt)}</span></div>`;
                            tagHtml = `<span class="self-start sm:self-center shrink-0 inline-flex items-center gap-1 text-[11px] font-bold px-2.5 py-1 rounded-full bg-emerald-200 text-emerald-900 shadow-2xs"><i class="fas fa-user-check mr-0.5"></i>Student Choice &bull; Correct</span>`;
                        } else if (isStudentChoice && !isTheCorrect) {
                            optClasses += 'bg-rose-50 border-rose-300 text-rose-900 font-semibold ring-1 ring-rose-200';
                            leftHtml = `<div class="flex items-start gap-2.5 min-w-0"><i class="fas fa-times-circle text-rose-600 text-base mt-0.5 shrink-0"></i><strong class="text-rose-800 shrink-0">${letter}.</strong><span class="break-words leading-relaxed">${escapeHtml(opt)}</span></div>`;
                            tagHtml = `<span class="self-start sm:self-center shrink-0 inline-flex items-center gap-1 text-[11px] font-bold px-2.5 py-1 rounded-full bg-rose-200 text-rose-900 shadow-2xs"><i class="fas fa-user-times mr-0.5"></i>Student Choice &bull; Incorrect</span>`;
                        } else if (isTheCorrect) {
                            optClasses += 'bg-emerald-50/50 border-emerald-300 text-emerald-800 font-medium border-dashed';
                            leftHtml = `<div class="flex items-start gap-2.5 min-w-0"><i class="fas fa-check text-emerald-500 text-sm mt-1 shrink-0"></i><strong class="text-emerald-700 shrink-0">${letter}.</strong><span class="break-words leading-relaxed">${escapeHtml(opt)}</span></div>`;
                            tagHtml = `<span class="self-start sm:self-center shrink-0 inline-flex items-center gap-1 text-[11px] font-bold px-2.5 py-1 rounded-full bg-emerald-100 text-emerald-700 border border-emerald-200 shadow-2xs"><i class="fas fa-check mr-0.5"></i>Correct Answer</span>`;
                        } else {
                            optClasses += 'bg-white border-slate-200 text-slate-700';
                            leftHtml = `<div class="flex items-start gap-2.5 min-w-0"><span class="inline-block w-4 text-center text-slate-400 font-bold shrink-0">${letter}.</span><span class="break-words leading-relaxed">${escapeHtml(opt)}</span></div>`;
                        }

                        contentHtml += `<div class="${optClasses}">${leftHtml}${tagHtml}</div>`;
                    });
                    contentHtml += '</div>';

                } else if (isTrueFalse) {
                    const studentRaw = normStr(rawStudentAns);
                    const correctRaw = normStr(q.correct);
                    const hasAnswer = studentRaw === 'true' || studentRaw === 'false';
                    const isCorrect = hasAnswer && (studentRaw === correctRaw);

                    if (!hasAnswer) {
                        statusBadge = '<span class="px-2.5 py-1 text-xs font-bold rounded-full bg-slate-100 text-slate-600 border border-slate-200"><i class="fas fa-minus-circle mr-1 text-slate-400"></i>No Answer (0 pt)</span>';
                    } else if (isCorrect) {
                        statusBadge = '<span class="px-2.5 py-1 text-xs font-bold rounded-full bg-emerald-100 text-emerald-800 border border-emerald-200"><i class="fas fa-check-circle mr-1 text-emerald-600"></i>Correct (+1 pt)</span>';
                    } else {
                        statusBadge = '<span class="px-2.5 py-1 text-xs font-bold rounded-full bg-rose-100 text-rose-800 border border-rose-200"><i class="fas fa-times-circle mr-1 text-rose-600"></i>Incorrect (0 pt)</span>';
                    }

                    const studentLabel = hasAnswer ? (studentRaw === 'true' ? 'True' : 'False') : '<i class="text-slate-400 font-normal">No answer submitted</i>';
                    const correctLabel = correctRaw === 'true' ? 'True' : 'False';

                    const studentBoxBg = !hasAnswer ? 'bg-slate-50 border-slate-200' : (isCorrect ? 'bg-emerald-50/60 border-emerald-200' : 'bg-rose-50/60 border-rose-200');
                    const studentIcon = !hasAnswer ? '<i class="fas fa-minus text-slate-400"></i>' : (isCorrect ? '<i class="fas fa-check text-emerald-600"></i>' : '<i class="fas fa-times text-rose-600"></i>');
                    const studentTextClass = !hasAnswer ? 'text-slate-500' : (isCorrect ? 'text-emerald-800' : 'text-rose-800');

                    contentHtml += `
                    <div class="mb-4 p-3 sm:p-4 rounded-xl border border-slate-200 bg-white grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                        <div class="p-3 rounded-lg border ${studentBoxBg} flex items-start gap-3">
                            <span class="w-7 h-7 rounded-full bg-white flex items-center justify-center font-bold shrink-0 shadow-xs mt-0.5">
                                ${studentIcon}
                            </span>
                            <div class="min-w-0 flex-1">
                                <span class="font-bold uppercase tracking-wider text-slate-500 block text-[11px] mb-1">Student's Answer</span>
                                <span class="text-sm font-semibold ${studentTextClass}">${studentLabel}</span>
                            </div>
                        </div>
                        <div class="p-3 rounded-lg border bg-emerald-50/60 border-emerald-200 flex items-start gap-3">
                            <span class="w-7 h-7 rounded-full bg-white text-emerald-600 flex items-center justify-center font-bold shrink-0 shadow-xs mt-0.5">
                                <i class="fas fa-check"></i>
                            </span>
                            <div class="min-w-0 flex-1">
                                <span class="font-bold uppercase tracking-wider text-emerald-700 block text-[11px] mb-1">Correct Answer</span>
                                <span class="text-sm font-semibold text-emerald-900">${correctLabel}</span>
                            </div>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    `;

                    ['true', 'false'].forEach(val => {
                        const label = val === 'true' ? 'True' : 'False';
                        const isStudentChoice = (val === studentRaw);
                        const isTheCorrect = (val === correctRaw);

                        let optClasses = 'p-3.5 rounded-xl border text-sm flex flex-wrap items-center justify-between gap-2 ';
                        let tag = '';
                        let icon = '';

                        if (isStudentChoice && isTheCorrect) {
                            optClasses += 'bg-emerald-50 border-emerald-300 text-emerald-900 font-semibold ring-1 ring-emerald-300';
                            icon = '<i class="fas fa-check-circle text-emerald-600 mr-2"></i>';
                            tag = '<span class="text-[11px] font-bold px-2.5 py-0.5 rounded-full bg-emerald-200 text-emerald-900">Student &bull; Correct</span>';
                        } else if (isStudentChoice && !isTheCorrect) {
                            optClasses += 'bg-rose-50 border-rose-300 text-rose-900 font-semibold ring-1 ring-rose-200';
                            icon = '<i class="fas fa-times-circle text-rose-600 mr-2"></i>';
                            tag = '<span class="text-[11px] font-bold px-2.5 py-0.5 rounded-full bg-rose-200 text-rose-900">Student Choice</span>';
                        } else if (isTheCorrect) {
                            optClasses += 'bg-emerald-50/50 border-emerald-300 text-emerald-800 font-medium border-dashed';
                            icon = '<i class="fas fa-check text-emerald-500 mr-2"></i>';
                            tag = '<span class="text-[11px] font-bold px-2.5 py-0.5 rounded-full bg-emerald-100 text-emerald-700">Correct Answer</span>';
                        } else {
                            optClasses += 'bg-white border-slate-200 text-slate-700';
                            icon = '<i class="far fa-circle text-slate-300 mr-2"></i>';
                        }

                        contentHtml += `<div class="${optClasses}"><span>${icon}${label}</span>${tag}</div>`;
                    });
                    contentHtml += '</div>';

                } else if (isEnum) {
                    const matches = calculateAutoGrade(q, rawStudentAns);
                    statusBadge = `<span class="px-2.5 py-1 text-xs font-bold rounded-full ${matches === pts ? 'bg-emerald-100 text-emerald-800 border border-emerald-200' : (matches > 0 ? 'bg-blue-100 text-blue-800 border border-blue-200' : 'bg-rose-100 text-rose-800 border border-rose-200')}">${matches} / ${pts} pts</span>`;

                    let userAnswers = Array.isArray(rawStudentAns) ? rawStudentAns : (rawStudentAns ? [rawStudentAns] : []);
                    let correctAnswers = q.answers || [];

                    contentHtml += `
                    <div class="mb-3 p-3 sm:p-4 rounded-xl border border-slate-200 bg-white grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                        <div class="space-y-2">
                            <span class="font-bold uppercase tracking-wider text-slate-500 block text-[11px]">Student's Submitted Items:</span>
                    `;

                    if (userAnswers.length === 0) {
                        contentHtml += '<div class="p-2.5 bg-slate-50 border rounded-lg text-slate-400 italic">No items submitted</div>';
                    } else {
                        userAnswers.forEach((u, uIdx) => {
                            const trimmedU = normStr(u);
                            const isMatch = correctAnswers.some(c => normStr(c) === trimmedU && trimmedU !== '');
                            contentHtml += `
                            <div class="p-2.5 rounded-lg border flex flex-wrap items-center justify-between gap-2 ${isMatch ? 'bg-emerald-50 border-emerald-200 text-emerald-900 font-medium' : 'bg-rose-50 border-rose-200 text-rose-900'}">
                                <span class="break-words">Item ${uIdx + 1}: <strong>${escapeHtml(u || '(Blank)')}</strong></span>
                                <span class="text-[11px] font-bold shrink-0 ${isMatch ? 'text-emerald-700' : 'text-rose-700'}">${isMatch ? '<i class="fas fa-check mr-1"></i>Matched' : '<i class="fas fa-times mr-1"></i>No Match'}</span>
                            </div>
                            `;
                        });
                    }

                    contentHtml += `
                        </div>
                        <div class="space-y-2">
                            <span class="font-bold uppercase tracking-wider text-emerald-700 block text-[11px]">Expected Correct Answers:</span>
                            <div class="p-2.5 bg-emerald-50/50 border border-emerald-200 rounded-lg text-emerald-800 space-y-1.5">
                                ${correctAnswers.map((c, cIdx) => `<div class="text-xs flex items-start gap-2"><i class="fas fa-check text-emerald-500 text-[10px] mt-1 shrink-0"></i><span class="break-words">${escapeHtml(c)}</span></div>`).join('')}
                            </div>
                        </div>
                    </div>
                    `;

                } else if (isEssay) {
                    const currentScore = (sAns && typeof sAns === 'object' && sAns.manual_score !== undefined) ? sAns.manual_score : 0;
                    statusBadge = `<span class="px-2.5 py-1 text-xs font-bold rounded-full bg-blue-100 text-blue-800 border border-blue-200"><i class="fas fa-feather-alt mr-1"></i>Manual Grade (${pts} pts max)</span>`;

                    const studentText = typeof rawStudentAns === 'string' ? rawStudentAns.trim() : '';

                    contentHtml += `
                    <div class="mb-3 text-sm text-slate-700">
                        <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                            <strong class="text-xs uppercase tracking-wider text-slate-600 flex items-center gap-1.5">
                                <i class="fas fa-pen-nib text-blue-600"></i> Student Written Essay:
                            </strong>
                            <span class="text-xs text-slate-400">${studentText ? studentText.split(/\s+/).filter(Boolean).length + ' words' : '0 words'}</span>
                        </div>
                        <div class="p-3.5 sm:p-4 bg-white border border-slate-300 rounded-xl min-h-[80px] whitespace-pre-wrap leading-relaxed shadow-2xs break-words">
                            ${studentText ? escapeHtml(studentText) : '<i class="text-slate-400">No essay submitted by student.</i>'}
                        </div>
                    </div>
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-t border-slate-200 pt-3 mt-3 bg-slate-100/70 p-3.5 rounded-xl">
                        <span class="text-xs font-medium text-slate-600 leading-snug"><i class="fas fa-award text-amber-500 mr-1.5"></i>Assign essay score (saved when clicking "Save Official Scores"):</span>
                        <div class="flex items-center self-end sm:self-auto">
                            <span class="text-sm font-bold text-slate-700 mr-2">Manual Score:</span>
                            <input type="number" min="0" max="${pts}" class="manual-score-input border border-slate-300 rounded-lg px-2.5 py-1.5 w-20 text-center font-bold text-slate-800 bg-white shadow-2xs outline-none focus:ring-2 focus:ring-blue-500" value="${currentScore}" data-idx="${idx}">
                            <span class="text-sm text-slate-500 ml-2 font-semibold">/ ${pts} pts</span>
                        </div>
                    </div>
                    `;
                }

                html += `
                <div class="border border-slate-200 rounded-2xl p-4 sm:p-6 bg-slate-50 relative q-review-block shadow-xs" data-idx="${idx}" data-type="${q.type || 'multichoice'}" data-max="${pts}">
                    <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-2.5 mb-4 pb-3 border-b border-slate-200/80">
                        <div class="flex items-start gap-2.5 min-w-0">
                            <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-blue-100 text-blue-700 text-xs font-bold shrink-0 mt-0.5">${idx + 1}</span>
                            <div class="font-bold text-slate-800 text-sm sm:text-base leading-relaxed break-words min-w-0">${escapeHtml(q.question || q.text || '')}</div>
                        </div>
                        <div class="self-start sm:self-auto shrink-0">
                            ${statusBadge}
                        </div>
                    </div>
                    ${contentHtml}
                </div>
                `;
            });

            container.innerHTML = html;
            updateCompScorePreview();
            
            document.querySelectorAll('.manual-score-input').forEach(inp => {
                inp.addEventListener('input', updateCompScorePreview);
            });
        }
        
        function updateCompScorePreview() {
            let totalPoints = 0;
            let earnedPoints = 0;
            
            qJson.forEach((q, idx) => {
                const sAns = aJson[idx];
                if (q.type === 'essay') {
                    const pts = parseInt(q.points) || 30;
                    totalPoints += pts;
                    const inp = document.querySelector(`.manual-score-input[data-idx="${idx}"]`);
                    if (inp) earnedPoints += (parseInt(inp.value) || 0);
                } else {
                    const pts = q.type === 'enumeration' ? (q.answers?.length || parseInt(q.count) || 1) : 1;
                    totalPoints += pts;
                    earnedPoints += calculateAutoGrade(q, sAns);
                }
            });
            
            const pct = totalPoints > 0 ? (earnedPoints / totalPoints) * 100 : 100;
            const autoScoreEl = document.getElementById('compAutoScore');
            if (autoScoreEl) {
                autoScoreEl.innerText = `${earnedPoints} / ${totalPoints} (${pct.toFixed(1)}%)`;
            }
            return pct;
        }
        
        function calculateAutoGrade(q, sAns) {
            if (sAns === undefined || sAns === null) return 0;

            let rawAns = sAns;
            if (sAns && typeof sAns === 'object' && !Array.isArray(sAns) && sAns.answer !== undefined) {
                rawAns = sAns.answer;
            }

            if (q.type === 'multichoice' || (!q.type && q.options) || (q.options && Array.isArray(q.options) && q.options.length > 0)) {
                const student = resolveStudentChoice(q, rawAns);
                const correct = resolveCorrectChoice(q);
                if (!student.hasAnswer || student.chosenIdx === -1 || correct.correctIdx === -1) {
                    return 0;
                }
                return student.chosenIdx === correct.correctIdx ? 1 : 0;
            } else if (q.type === 'truefalse') {
                const s = normStr(rawAns);
                const c = normStr(q.correct);
                if (!s) return 0;
                return (s === c) ? 1 : 0;
            } else if (q.type === 'enumeration') {
                let matches = 0;
                let userAnswers = Array.isArray(rawAns) ? rawAns : [rawAns];
                let correctAnswers = q.answers || [];
                let anyOrder = q.anyOrder !== undefined ? q.anyOrder : true;
                let caseSensitive = q.caseSensitive || false;
                
                if (anyOrder) {
                    let matchedIndexes = new Set();
                    userAnswers.forEach(uAns => {
                        if (!uAns) return;
                        let u = caseSensitive ? String(uAns).trim() : normStr(uAns);
                        for (let i = 0; i < correctAnswers.length; i++) {
                            if (matchedIndexes.has(i)) continue;
                            let c = caseSensitive ? String(correctAnswers[i]).trim() : normStr(correctAnswers[i]);
                            if (u === c && c !== "") { matches++; matchedIndexes.add(i); break; }
                        }
                    });
                } else {
                    for (let i = 0; i < correctAnswers.length; i++) {
                        if (i >= userAnswers.length) break;
                        let u = caseSensitive ? String(userAnswers[i]).trim() : normStr(userAnswers[i]);
                        let c = caseSensitive ? String(correctAnswers[i]).trim() : normStr(correctAnswers[i]);
                        if (u === c && c !== "") matches++;
                    }
                }
                return matches;
            }
            return 0;
        }
        
        renderComprehensionReview();

        // Custom review-specific renderTranscriptMarkup to support:
        // 1. Rendering all passage words, including trailing unread omissions when student did not finish reading.
        // 2. Preserving and correctly rendering overridden omissions (e.g. changed to match, mis, sub, etc.) without disappearing.
        window.renderTranscriptMarkup = function(assessment, data) {
            data = data || {};
            const azureWords = data.azure?.words || [];
            const azureMap = Core.mapAzureEvidence(assessment.referenceWords || [], azureWords);
            const spokenWords = assessment.spokenWords || [];
            const bySpoken = new Map();
            const unspokenOps = [];

            // Ensure referenceWords exists
            if (!assessment.referenceWords && rawPassageText) {
                assessment.referenceWords = rawPassageText.trim().split(/\s+/).map((w, idx) => ({ word: w, norm: w.toLowerCase().replace(/[^\w]/g, ''), index: idx }));
            }

            // Ensure all reference words are represented in ops so unread words can be seen & overridden
            if (Array.isArray(assessment.referenceWords) && assessment.referenceWords.length > 0) {
                if (!Array.isArray(assessment.ops)) assessment.ops = [];
                const coveredRefs = new Set();
                for (const op of assessment.ops) {
                    if (op.ref != null) coveredRefs.add(Number(op.ref));
                    if (Array.isArray(op.refs)) op.refs.forEach(r => coveredRefs.add(Number(r)));
                }
                for (let r = 0; r < assessment.referenceWords.length; r++) {
                    if (!coveredRefs.has(r)) {
                        const newOmit = { type: 'omit', ref: r };
                        assessment.ops.push(newOmit);
                        if (assessment.counts) {
                            assessment.counts.omission = (assessment.counts.omission || 0) + 1;
                        }
                    }
                }
            }

            for (const op of (assessment.ops || [])) {
                if ((op.type === "omit" || op.type === "azure-mis-omit") && op.ref != null) {
                    unspokenOps.push(op);
                    continue;
                }
                if (op.spoken != null) {
                    bySpoken.set(Number(op.spoken), op);
                } else if (Array.isArray(op.spokens) && op.spokens.length > 0) {
                    op.spokens.forEach((idx, k) => {
                        bySpoken.set(Number(idx), { ...op, ref: op.refs?.[k] ?? op.ref, spoken: Number(idx) });
                    });
                } else if (op.ref != null) {
                    unspokenOps.push(op);
                }
            }

            // Sort unspokenOps strictly by reference word index
            unspokenOps.sort((a, b) => Number(a.ref) - Number(b.ref));

            // Map each unspoken op (omission or overridden omission) to the appropriate spoken slot
            const unspokenAtSpoken = new Map();
            for (const op of unspokenOps) {
                let insertAt = spokenWords.length;
                for (const [spokenIndex, spokenOp] of bySpoken.entries()) {
                    const ref = spokenOp?.ref != null ? Number(spokenOp.ref) : null;
                    if (ref != null && ref >= Number(op.ref)) {
                        insertAt = Math.min(insertAt, Number(spokenIndex));
                    }
                }
                if (!unspokenAtSpoken.has(insertAt)) unspokenAtSpoken.set(insertAt, []);
                unspokenAtSpoken.get(insertAt).push(op);
            }

            transcriptTips = [];
            const html = [];

            for (let i = 0; i <= spokenWords.length; i++) {
                // Render unspoken ops scheduled before spoken token i (or trailing unread ops at i === spokenWords.length)
                for (const uOp of (unspokenAtSpoken.get(i) || [])) {
                    const refIndex = Number(uOp.ref);
                    const expectedWord = assessment.referenceWords?.[refIndex]?.word || assessment.referenceWords?.[refIndex] || "";
                    const spokenIdx = spokenWords.length > 0 ? Math.max(0, Math.min(i, spokenWords.length - 1)) : 0;
                    const info = buildTooltipData(assessment, data, uOp, spokenIdx, azureMap);
                    info.expectedWord = expectedWord;
                    info.spokenWord = (uOp.type === "omit" || uOp.type === "azure-mis-omit") ? "" : (uOp.spokenWord || expectedWord);
                    info.type = uOp.type;
                    info.op = uOp;
                    const tipIndex = transcriptTips.push(info) - 1;

                    let tokenClass = "transcript-token";
                    let tokenTitle = "Word";
                    let extraAttrs = [];

                    if (uOp.type === "omit") {
                        tokenClass += " omit-placeholder";
                        tokenTitle = "Omission";
                    } else if (uOp.type === "azure-mis-omit" || uOp.type === "mis") {
                        tokenClass += " mis";
                        tokenTitle = "Mispronunciation";
                        extraAttrs.push(`data-expected="${escapeHtml(expectedWord)}"`);
                    } else if (uOp.type === "sub") {
                        tokenClass += " sub";
                        tokenTitle = "Substitution";
                        extraAttrs.push(`data-expected="${escapeHtml(expectedWord)}"`);
                    } else if (uOp.type === "reverse") {
                        tokenClass += " rev";
                        tokenTitle = "Reversal";
                        extraAttrs.push(`data-expected="${escapeHtml(expectedWord)}"`);
                    } else if (uOp.type === "repeat") {
                        tokenClass += " rep";
                        tokenTitle = "Repetition";
                    } else if (uOp.type === "trans") {
                        tokenClass += " trans";
                        tokenTitle = "Transposition";
                    } else if (uOp.type === "self-corrected") {
                        tokenClass += " self-corrected";
                        tokenTitle = "Self-Corrected";
                    } else if (uOp.type === "insert") {
                        tokenClass += " ins";
                        tokenTitle = "Insertion";
                    } else if (uOp.type === "match") {
                        tokenClass += " match";
                        tokenTitle = "Correct (No Error)";
                    } else {
                        const cls = transcriptClassForType(uOp.type);
                        if (cls) tokenClass += " " + cls;
                    }

                    html.push(`<span class="${tokenClass}" data-tip-index="${tipIndex}" data-tip-html="${escapeHtml(tooltipHtml(info, assessment))}" tabindex="0" title="${tokenTitle}" ${extraAttrs.join(" ")}>${escapeHtml(expectedWord)}</span>`);
                }

                if (i === spokenWords.length) break;

                // Render spoken token i
                const word = spokenWords[i];
                const op = bySpoken.get(i) || { type: "match", spoken: i };
                const info = buildTooltipData(assessment, data, op, i, azureMap);
                const tipIndex = transcriptTips.push(info) - 1;
                const classes = [transcriptClassForType(op.type)];
                if (String(op.azurePronunciation || "").toLowerCase() === "mispronunciation" && op.type !== "mis") classes.push("azure-mis");
                const attrs = [];

                if (op.type === "mis" || op.type === "sub" || op.type === "reverse") {
                    const expected = info.expectedWord || "";
                    if (expected) attrs.push(`data-expected="${escapeHtml(expected)}"`);
                }
                if (op.type === "self-repair") {
                    const correctionIndex = Number(op.selfCorrection?.correction);
                    if (Number.isFinite(correctionIndex)) attrs.push(`data-correction-index="${correctionIndex}"`);
                }
                if (op.type === "self-corrected") {
                    const attemptText = (op.selfCorrection?.reparandums || [])
                        .map(idx => spokenWords[Number(idx)]?.word || "")
                        .filter(Boolean)
                        .join(" → ");
                    if (attemptText) attrs.push(`data-selfattempt="${escapeHtml(attemptText)}"`);
                }
                if (op.type === "repeat" && op.repeat) {
                    attrs.push(`data-retrace="${Number(op.repeat.retraceSpoken ?? Math.max(0, i - 1))}"`);
                    attrs.push(`data-repeat-start="${Number(op.repeat.repeatStartSpoken ?? i)}"`);
                    attrs.push(`data-repeat-length="${Number(op.repeat.length || 1)}"`);
                }
                if (op.type === "trans" && Array.isArray(op.spokens)) {
                    attrs.push(`data-trans-pair="${op.spokens.map(Number).join(",")}"`);
                }
                if (op.azurePronunciation) attrs.push(`data-azure-error="${escapeHtml(op.azurePronunciation)}"`);
                if (op.alignmentType) attrs.push(`data-alignment-type="${escapeHtml(op.alignmentType)}"`);

                const azureEvidence = assessment.evidenceBySpoken?.get?.(i) ||
                    transcriptAzureEvidence(assessment, i, op?.ref, azureMap) || null;
                const timing = azureEvidence?.timing || Core.azureTiming?.(azureEvidence);
                if (timing && Number.isFinite(Number(timing.startSeconds))) {
                    attrs.push(`data-audio-start="${Number(timing.startSeconds).toFixed(3)}"`);
                    if (Number.isFinite(Number(timing.endSeconds))) attrs.push(`data-audio-end="${Number(timing.endSeconds).toFixed(3)}"`);
                    if (Number.isFinite(Number(timing.durationSeconds))) attrs.push(`data-audio-duration="${Number(timing.durationSeconds).toFixed(3)}"`);
                }

                const tokenInner = escapeHtml(word.word);
                html.push(`<span class="transcript-token ${classes.filter(Boolean).join(" ")}" data-tip-index="${tipIndex}" data-tip-html="${escapeHtml(tooltipHtml(info, assessment))}" tabindex="0" ${attrs.join(" ")}>${tokenInner}</span>`);
            }

            return html.join(" ") || '<span class="empty-state">No transcript was captured.</span>';
        };

        if (activeAssessment && activeAssessment.ops) {
            // Restore missing reference properties expected by the UI
            if (!activeAssessment.referenceWords && rawPassageText) {
                activeAssessment.referenceWords = rawPassageText.trim().split(/\s+/).map((w, idx) => ({ word: w, norm: w.toLowerCase().replace(/[^\w]/g, ''), index: idx }));
            }
            if (activeAssessment.referenceWords && Array.isArray(activeAssessment.referenceWords)) {
                els.story.textContent = activeAssessment.referenceWords.map(w => w.word || w).join(" ");
            }
            activeAssessment.spokenWords = activeAssessment.spokenWords || [];
            activeAssessment.counts = activeAssessment.counts || {};
            
            // 1. Initial Render
            els.transcript.innerHTML = renderTranscriptMarkup(activeAssessment, window.reviewData);
            renderMiscueStrip(activeAssessment.counts);
            // We don't call renderPerformance() directly because it pulls from 'data' duration.
            // We just let the PHP values stay, UNTIL they override.
            
            // 2. Override Logic (triggered via tooltip "Override Word Grading" button or desktop double-click)
            const modal = document.getElementById('overrideModal');
            const targetWordSpan = document.getElementById('overrideTargetWord');
            const hiddenIndex = document.getElementById('overrideWordIndex');
            
            window.openWordOverrideModal = function(tipIndex) {
                if (tipIndex !== null && tipIndex !== undefined) {
                    const info = transcriptTips[tipIndex];
                    if (info) {
                        targetWordSpan.textContent = info.spokenWord || info.expectedWord || "Word";
                        hiddenIndex.value = tipIndex;
                        modal.classList.remove('hidden');
                    }
                }
            };

            document.getElementById('transcript').addEventListener('dblclick', e => {
                const token = e.target.closest('.transcript-token');
                if (token) {
                    const tipIndex = token.getAttribute('data-tip-index');
                    if (tipIndex !== null && tipIndex !== undefined) {
                        if (typeof hideTooltip === 'function') hideTooltip();
                        window.openWordOverrideModal(Number(tipIndex));
                    }
                }
            });
            
            document.getElementById('closeOverrideBtn').addEventListener('click', () => {
                modal.classList.add('hidden');
            });
            
            document.querySelectorAll('.override-btn').forEach(btn => {
                btn.addEventListener('click', e => {
                    const newType = e.currentTarget.getAttribute('data-type');
                    const tipIndex = hiddenIndex.value;
                    const info = transcriptTips[tipIndex];
                    
                    if (info && info.op) {
                        const oldType = info.op.type;
                        if (oldType === newType) {
                            modal.classList.add('hidden');
                            return;
                        }

                        activeAssessment.counts = activeAssessment.counts || {};
                        
                        // Decrement old count
                        let oldCat = getCategory(oldType);
                        if (oldCat && (activeAssessment.counts[oldCat] || 0) > 0) {
                            activeAssessment.counts[oldCat] = (activeAssessment.counts[oldCat] || 0) - 1;
                        }
                        
                        // Increment new count
                        let newCat = getCategory(newType);
                        if (newCat) {
                            activeAssessment.counts[newCat] = (activeAssessment.counts[newCat] || 0) + 1;
                        }
                        
                        // Update the OP
                        info.op.type = newType;
                        if (newType !== 'omit' && newType !== 'azure-mis-omit' && !info.op.spokenWord) {
                            info.op.spokenWord = info.expectedWord || "";
                        }
                        
                        // Re-render
                        els.transcript.innerHTML = renderTranscriptMarkup(activeAssessment, window.reviewData);
                        renderMiscueStrip(activeAssessment.counts);
                        
                        // Recalculate Accuracy
                        const miscues = miscuesForAccuracy(activeAssessment.counts);
                        const words = (activeAssessment.referenceWords || []).length;
                        const accuracy = words > 0 ? Math.max(0, ((words - miscues) / words) * 100) : 0;
                        document.getElementById('readingAccuracy').textContent = accuracy.toFixed(1) + '%';
                        
                        // Live update passage classification preview
                        const classEl = document.getElementById('passageClassification');
                        if (classEl) {
                            let comp = <?php echo floatval($attempt['comprehension_score']); ?>;
                            if (typeof updateCompScorePreview === 'function') {
                                const manualComp = updateCompScorePreview();
                                if (manualComp !== null && !isNaN(manualComp)) comp = manualComp;
                            }
                            let newClass = 'Instructional';
                            if (accuracy >= 97 && comp >= 80) newClass = 'Independent';
                            else if (accuracy <= 89 || comp <= 58) newClass = 'Frustration';
                            classEl.textContent = newClass;
                        }
                        
                        modal.classList.add('hidden');
                    }
                });
            });
            
            function getCategory(type) {
                if (type === 'mis' || type === 'azure-mis-omit') return 'mispronunciation';
                if (type === 'omit') return 'omission';
                if (type === 'sub') return 'substitution';
                if (type === 'self-corrected') return 'selfCorrection';
                if (type === 'insert') return 'insertion';
                if (type === 'repeat') return 'repetition';
                if (type === 'trans') return 'transposition';
                if (type === 'reverse') return 'reversal';
                return null;
            }
        } else {
            // No audio/transcript generated, just render the text
            els.transcript.innerHTML = rawPassageText;
        }

        document.getElementById('saveOverridesBtn').addEventListener('click', async (e) => {
            const btn = e.currentTarget;
            btn.textContent = 'Saving...';
            
            const fd = new FormData();
            fd.append('attempt_id', attemptId);
            
            if (activeAssessment && activeAssessment.ops) {
                const miscues = miscuesForAccuracy(activeAssessment.counts);
                const words = (activeAssessment.referenceWords || []).length;
                const accuracy = words > 0 ? Math.max(0, ((words - miscues) / words) * 100) : 0;
                
                fd.append('accuracy_score', accuracy);
                fd.append('miscues_json', JSON.stringify(activeAssessment.counts));
                // Update eval_data with new ops and counts so it persists
                if (window.reviewData && window.reviewData.assessment) {
                    window.reviewData.assessment = activeAssessment;
                    fd.append('evaluation_data', JSON.stringify(window.reviewData));
                } else {
                    fd.append('evaluation_data', JSON.stringify(activeAssessment));
                }
            }
            
            // --- Inject Manual Comprehension Score ---
            if (typeof updateCompScorePreview === 'function') {
                const manualComp = updateCompScorePreview();
                fd.append('comp_score', manualComp);
                
                // We must also update answers_json in the DB to save the manual scores!
                if (typeof aJson !== 'undefined' && typeof qJson !== 'undefined') {
                    document.querySelectorAll('.manual-score-input').forEach(inp => {
                        const idx = inp.getAttribute('data-idx');
                        if (!aJson[idx] || typeof aJson[idx] !== 'object') {
                            // Convert primitive answers to object if needed, or just append it
                            let oldAns = aJson[idx];
                            aJson[idx] = { answer: oldAns, manual_score: parseInt(inp.value) || 0 };
                        } else {
                            aJson[idx].manual_score = parseInt(inp.value) || 0;
                        }
                    });
                    fd.append('answers_json', JSON.stringify(aJson));
                }
            }

            try {
                const res = await fetch('api_assessment.php?action=override_attempt', {
                    method: 'POST',
                    body: fd
                });
                if (res.ok) {
                    const data = await res.json();
                    if (data.new_passage_classification) {
                        const classEl = document.getElementById('passageClassification');
                        if (classEl) classEl.textContent = data.new_passage_classification;
                    }
                    btn.textContent = 'Saved!';
                    btn.classList.replace('bg-emerald-500', 'bg-blue-500');
                    setTimeout(() => { btn.textContent = 'Save Official Scores'; btn.classList.replace('bg-blue-500', 'bg-emerald-500'); }, 2000);
                }
            } catch(err) {
                alert('Error saving overrides.');
                btn.textContent = 'Save Official Scores';
            }
        });
    </script>

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
