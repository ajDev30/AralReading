<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: index.php");
    exit;
}

$user_level = get_student_reading_level($pdo, $_SESSION['user_id']);
if ($user_level === 'Non-Reader' || $user_level === 'Independent' || $user_level === 'Pending') {
    header("Location: dashboard_student.php");
    exit;
}

$id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare("SELECT * FROM course_assessments WHERE id = ?");
$stmt->execute([$id]);
$test = $stmt->fetch();

if (!$test) {
    die("Test not found.");
}

$phase = $test['test_type'] === 'Pre-Test' ? 'Course-Pre-Test' : 'Course-Post-Test';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="https://tibungcodistrict.wordpress.com/wp-content/uploads/2018/06/untitled-1.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="UTF-8">
    <title>Take Test: <?php echo htmlspecialchars($test['title']); ?></title>
    <link rel="stylesheet" href="v536/public/style.css?v=2.0.30">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #F1F5F9; }
        .course-shell { max-width: 1200px; margin: 0 auto; background: white; border-radius: 12px; padding: 1.25rem; width: 100%; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); }
    
        @media (max-width: 767.98px) {
            body { padding: 0.5rem !important; }
            .course-shell, .shell { padding: 0.875rem !important; margin: 0 !important; width: 100% !important; }
            .course-top-bar { flex-direction: column; align-items: flex-start !important; gap: 0.75rem; }
            .course-top-bar h3 { font-size: 1.25rem !important; margin-bottom: 0; }
            .course-top-bar .btn { width: 100%; }
            #submitTestBtn { width: 100%; }
        }
        
    .sidebar { background-color: #1a365d; }
    </style>

    <script src="js/htmx.min.js"></script>
    <meta name="htmx-config" content='{"globalViewTransitions":true}'>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="p-2 p-md-4">

<div class="course-shell">
    <div class="course-top-bar d-flex justify-content-between align-items-center mb-4 border-bottom pb-3">
        <h3 class="mb-0"><?php echo htmlspecialchars($test['title']); ?> <span class="badge badge-info"><?php echo $test['test_type']; ?></span></h3>
        <a href="student_course.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left mr-1"></i> Back to Materials</a>
    </div>

        <main class="shell" style="all: unset; display: block;">
        <header class="app-header mb-3 bg-white p-3 rounded shadow-sm border d-flex justify-content-between align-items-center">
            <div>
                <h1 style="font-weight:bold; font-size:1.25rem;" class="mb-1">Reading Assessment</h1>
                <p style="font-size:0.875rem; color:#64748b;" class="mb-0" id="level-indicator">Read aloud</p>
            </div>
            <div class="controls d-flex align-items-center" style="gap: 8px;">
                <button id="diagBtn" class="btn btn-sm btn-outline-secondary" title="Diagnostics"><i class="fas fa-cog"></i></button>
                <span class="badge badge-primary p-2" id="statusPill">Ready</span>
            </div>
        </header>

        <div class="reader-card bg-white p-3 p-md-4 rounded shadow-sm border mb-4">
            <div class="reader-toolbar mb-3 d-flex justify-content-between align-items-center border-bottom pb-2">
                <div class="d-flex align-items-center">
                    <span class="dot" id="vadDot"></span>
                    <span id="hint" class="ml-2 font-weight-bold text-primary">Press Start and read the passage clearly.</span>
                </div>
            </div>

            <textarea id="storyEditor" hidden><?php echo htmlspecialchars($test['passage_text']); ?></textarea>
            
            <div class="split-view row">
                <section id="reading-pane-container" class="reading-pane col-md-12 border-right" style="position: relative;">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="font-weight-bold text-secondary small">PASSAGE</span>
                    </div>
                    <article id="story" class="story" style="font-size: 1.15rem; line-height: 1.9;"><?php echo $test['passage_text']; ?></article>
                </section>
                <section id="realtime-pane-container" class="realtime-pane col-md-6 d-none">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="font-weight-bold text-secondary small">LIVE TRANSCRIPT</span>
                    </div>
                    <article id="transcript" class="story text-muted" style="font-size: 1.15rem; line-height: 2.6;"><span class="empty-state">Your spoken words will appear here...</span></article><div id="transcriptNotice" hidden></div>
                </section>
            </div>
        </div>

        <div class="reader-control-btns text-center mt-3 mb-3 d-flex justify-content-center flex-wrap" style="gap: 12px;">
            <button id="startBtn" class="btn btn-primary btn-lg px-4 shadow"><i class="fas fa-microphone"></i> Start</button>
            <button id="stopBtn" class="btn btn-danger btn-lg px-4 shadow" disabled><i class="fas fa-stop"></i> Stop</button>
            <button id="resetBtn" class="btn btn-outline-secondary btn-lg px-4 shadow"><i class="fas fa-redo"></i> Retry</button>
        </div>

        <div class="row mt-4 border-top pt-3">
            <div class="col-6 col-md-3 text-center mb-3 mb-md-0">
                <span class="text-secondary small font-weight-bold">TIME</span><br>
                <h4 id="timer" class="text-primary font-weight-bold mb-0">00:00</h4>
            </div>
            <div class="col-6 col-md-3 text-center mb-3 mb-md-0">
                <span class="text-secondary small font-weight-bold">STORY WORDS</span><br>
                <h4 id="storyWordCount" class="text-primary font-weight-bold mb-0">0</h4>
                <span id="wordBadge" hidden></span>
            </div>
            <div class="col-6 col-md-3 text-center">
                <span class="text-secondary small font-weight-bold">WPM</span><br>
                <h4 id="wpm" class="text-success font-weight-bold mb-0">—</h4>
            </div>
            <div class="col-6 col-md-3 text-center">
                <span class="text-secondary small font-weight-bold">ACCURACY</span><br>
                <h4 id="readingAccuracy" class="text-success font-weight-bold mb-0">—</h4>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-12 text-center">
                <div id="miscueStrip" class="d-flex justify-content-center flex-wrap" style="gap: 6px;"></div>
            </div>
        </div>
        
        <!-- Hidden UI Elements Required by app.js -->
        <div id="tooltip" class="word-tooltip" role="tooltip" hidden></div>
        <button id="editStoryBtn" hidden><strong></strong></button>
        <button id="restartBtn" hidden></button>
        <dialog id="diagModal" class="diag-modal">
            <div class="diag-modal-header">
                <h3>Assessment Diagnostics</h3>
                <button id="closeDiagBtn" class="btn btn-sm btn-outline-secondary">Close</button>
            </div>
            <div id="diagContent" class="diag-modal-content">No assessment yet.</div>
        </dialog>
        <select id="locale" hidden><option value="en-US">en-US</option></select>
        <div id="meterBar" hidden></div>
        <span id="liveBadge" hidden></span>

        <!-- COMPREHENSION TEST -->
        <div id="comprehension-section" class="card shadow-sm mt-4 d-none" style="border: 2px solid #0d6efd; border-radius: 8px;">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0">📝 Comprehension Test</h4>
            </div>
            <div class="card-body" id="comprehension-questions">
                <!-- JS will inject questions here -->
            </div>
            <div class="card-footer text-right">
                <button id="submitTestBtn" class="btn btn-success btn-lg font-weight-bold shadow">Submit Test</button>
            </div>
        </div>

    </main>
</div>


<script>
const courseQuestions = <?php echo $test['questions_json']; ?>;
let oralScore = 0;
let readingTime = 0;
let readingSpeed = 0;
let miscues = {};

// Handle Assessment Complete (From app.js)
window.addEventListener('assessmentComplete', (e) => {
    const detailData = e.detail.data || e.detail;
    
    oralScore = detailData.readingAccuracy || 0;
    readingTime = detailData.durationSeconds || 0;
    readingSpeed = detailData.wpm || 0;
    miscues = detailData.miscuesJson || '{}';
    window.currentAssessment = e.detail.assessment || detailData.evalData || null;
    
    // Show Comprehension Test
    document.getElementById('comprehension-section').classList.remove('d-none');
    renderComprehensionTest(courseQuestions);
});

function renderComprehensionTest(questions) {
    const container = document.getElementById('comprehension-questions');
    container.innerHTML = '';
    
    let qNum = 1;
    questions.forEach((q, idx) => {
        let isDesc = (q.type === 'description');
        let title = isDesc ? (q.question || q.text || '') : `${qNum}. ${q.question || q.text || ''}`;
        
        let html = `<div class="mb-4 p-3 bg-light rounded question-block" data-idx="${idx}">
            <h5>${title}</h5>`;
            
        if (!isDesc) qNum++;
        
        if (q.type === 'multichoice' || (!q.type && q.options)) {
            (q.options || []).forEach((opt, oIdx) => {
                html += `<div class="form-check">
                    <input class="form-check-input" type="radio" name="q_${idx}" value="${oIdx}" id="q_${idx}_${oIdx}">
                    <label class="form-check-label" for="q_${idx}_${oIdx}">${opt}</label>
                </div>`;
            });
        } else if (q.type === 'truefalse') {
            html += `<div class="form-check"><input class="form-check-input" type="radio" name="q_${idx}" value="true" id="q_${idx}_t"><label class="form-check-label" for="q_${idx}_t">True</label></div>
                     <div class="form-check"><input class="form-check-input" type="radio" name="q_${idx}" value="false" id="q_${idx}_f"><label class="form-check-label" for="q_${idx}_f">False</label></div>`;
        } else if (q.type === 'enumeration') {
            const count = (q.answers && q.answers.length > 0) ? q.answers.length : (parseInt(q.count) || 3);
            for(let c=0; c<count; c++) html += `<input type="text" class="form-control mb-2 enum-input" placeholder="Item ${c+1}">`;
        } else if (q.type === 'essay') {
            html += `<textarea class="form-control essay-input" rows="3" placeholder="Type your answer..."></textarea>`;
        }
        
        html += `</div>`;
        container.innerHTML += html;
    });
}

document.getElementById('submitTestBtn').addEventListener('click', async () => {
    let compCorrect = 0;
    let compTotal = 0;
    let studentAnswers = {};
    const blocks = document.querySelectorAll('.question-block');
    
    blocks.forEach(block => {
        const idx = block.getAttribute('data-idx');
        const q = courseQuestions[idx];
        
        if (q.type === 'multichoice' || !q.type) {
            compTotal++;
            const selected = document.querySelector(`input[name="q_${idx}"]:checked`);
            if (selected) { studentAnswers[idx] = selected.value; if (parseInt(selected.value) === parseInt(q.correct)) compCorrect++; }
        } else if (q.type === 'truefalse') {
            compTotal++;
            const selected = document.querySelector(`input[name="q_${idx}"]:checked`);
            if (selected) { studentAnswers[idx] = selected.value; if (selected.value === String(q.correct)) compCorrect++; }
        } else if (q.type === 'enumeration') {
            const inputs = document.querySelectorAll(`.question-block[data-idx="${idx}"] .enum-input`);
            let userAnswers = Array.from(inputs).map(inp => inp.value.trim());
            studentAnswers[idx] = userAnswers;
            let correctAnswers = q.answers || [];
            let expectedCount = correctAnswers.length > 0 ? correctAnswers.length : (parseInt(q.count) || 1);
            compTotal += expectedCount;
            
            let matches = 0;
            let anyOrder = q.anyOrder !== undefined ? q.anyOrder : true;
            let caseSensitive = q.caseSensitive || false;
            
            if (anyOrder) {
                let matchedIndexes = new Set();
                userAnswers.forEach(uAns => {
                    if (!uAns) return;
                    let u = caseSensitive ? uAns : uAns.toLowerCase();
                    for (let i = 0; i < correctAnswers.length; i++) {
                        if (matchedIndexes.has(i)) continue;
                        let c = caseSensitive ? correctAnswers[i].trim() : correctAnswers[i].trim().toLowerCase();
                        if (u === c && c !== "") {
                            matches++;
                            matchedIndexes.add(i);
                            break;
                        }
                    }
                });
            } else {
                for (let i = 0; i < correctAnswers.length; i++) {
                    if (i >= userAnswers.length) break;
                    let u = caseSensitive ? userAnswers[i] : userAnswers[i].toLowerCase();
                    let c = caseSensitive ? correctAnswers[i].trim() : correctAnswers[i].trim().toLowerCase();
                    if (u === c && c !== "") {
                        matches++;
                    }
                }
            }
            compCorrect += matches;
        }
        else if (q.type === 'essay') {
            const textarea = document.querySelector(`.question-block[data-idx="${idx}"] .essay-input`);
            if (textarea) studentAnswers[idx] = textarea.value.trim();
            let pts = parseInt(q.points) || 30;
            compTotal += pts;
        }
    });
    
    const compScore = compTotal > 0 ? (compCorrect / compTotal) * 100 : 100;

    
    // Determine class
    let classification = "Instructional";
    if (oralScore >= 97 && compScore >= 80) classification = "Independent";
    else if (oralScore <= 89 || compScore <= 58) classification = "Frustration";

    const fd = new FormData();
    fd.append('passage_id', '<?php echo $test['id']; ?>');
    fd.append('phase', '<?php echo $phase; ?>');
    fd.append('accuracy_score', oralScore);
    fd.append('comprehension_score', compScore);
    fd.append('oral_reading_profile', classification);
    fd.append('reading_time', readingTime);
    fd.append('reading_speed', readingSpeed);
    fd.append('miscues_json', typeof miscues === 'string' ? miscues : JSON.stringify(miscues));
    fd.append('answers_json', JSON.stringify(studentAnswers));
    fd.append('evaluation_data', JSON.stringify(window.currentAssessment || {}));
    
    if (window.lastRecordingBlob) {
        fd.append('audio_file', window.lastRecordingBlob, 'course.webm');
    }

    // Submit to API
    const res = await fetch('api_assessment.php?action=submit_course_attempt', { method: 'POST', body: fd });
    if (res.ok) {
        window.location.href = 'student_course.php?success=1';
    } else {
        alert("Failed to submit.");
    }
});
</script>

<script src="v536/public/js/speech-sdk.bundle.min.js"></script>
<script src="v536/public/assessment-core.js?v=<?php echo time(); ?>"></script>
<script src="v536/public/app.js?v=<?php echo time(); ?>"></script>


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
