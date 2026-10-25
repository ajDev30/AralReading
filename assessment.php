<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: index.php");
    exit;
}
$stmt = $pdo->prepare("SELECT grade_level FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$u = $stmt->fetch();
$gradeNum = (int) filter_var($u['grade_level'], FILTER_SANITIZE_NUMBER_INT);
if(!$gradeNum) $gradeNum = 7;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="https://tibungcodistrict.wordpress.com/wp-content/uploads/2018/06/untitled-1.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Phil-IRI English Reading Assessment</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="v536/public/style.css?v=2.0.30">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <script src="js/htmx.min.js"></script>
    <meta name="htmx-config" content='{"globalViewTransitions":true}'>

    

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-light is-uninitialized">
    <main class="shell container mt-4">

        <!-- GST SECTION -->
        <section id="gst-section" class="card shadow-sm mb-4 d-none">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">English Group Screening Test (GST)</h5>
            </div>
            <div class="card-body">
                <p class="text-muted mb-4">Please answer the following screening questions. Your score will determine your individualized assessment level.</p>
                <div id="gst-timer-container" class="alert alert-warning font-weight-bold text-center d-none">
                    <i class="fas fa-clock"></i> Time Remaining: <span id="gst-timer-display">00:00</span>
                </div>
                <div id="gst-questions"></div>
                <button id="submitGstBtn" class="btn btn-success btn-lg mt-3">Submit GST</button>
            </div>
        
                

        </section>

        <!-- READER SECTION -->
        <section id="transition-section" class="card shadow-sm mb-4 d-none">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0" id="transition-title">Assessment Result</h5>
            </div>
            <div class="card-body text-center">
                <div id="transition-content" class="mb-4 lead"></div>
                <button id="continueAssessmentBtn" class="btn btn-primary btn-lg px-5">Continue to Next Stage</button>
            </div>
        </section>
        <section id="reader-section" class="d-none">
            <header class="app-header mb-3 bg-white p-3 rounded shadow-sm border d-flex justify-content-between align-items-center">
                <div>
                    <h1 style="font-weight:bold; font-size:1.25rem;" class="mb-1">English Reading Assessment</h1>
                    <p style="font-size:0.875rem; color:#64748b;" class="mb-0" id="level-indicator">Loading passage...</p>
                </div>
                <div class="controls d-flex align-items-center" style="gap: 8px;">
                    <a href="dashboard_student.php" class="btn btn-sm btn-outline-secondary" title="Back to Dashboard"><i class="fas fa-arrow-left mr-1"></i> Dashboard</a>
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

                <textarea id="storyEditor" hidden></textarea>
                
                <div class="split-view row">
                    <section id="reading-pane-container" class="reading-pane col-md-12 border-right" style="position: relative;">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="font-weight-bold text-secondary small">PASSAGE</span>
                        </div>
                        <article id="story" class="story" style="font-size: 1.15rem; line-height: 1.9;"></article>
                    </section>
                    <section id="realtime-pane-container" class="realtime-pane col-md-6 d-none">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="font-weight-bold text-secondary small">LIVE TRANSCRIPT</span>
                        </div>
                        <article id="transcript" class="story text-muted" style="font-size: 1.15rem; line-height: 2.6;"></article><div id="transcriptNotice" hidden></div>
                    </section>
                </div>
            </div>

            <div class="reader-control-btns text-center mt-3 mb-3 d-flex justify-content-center flex-wrap" style="gap: 12px;">
                <button id="startBtn" class="btn btn-primary btn-lg px-4 shadow"><i class="fas fa-microphone"></i> Start</button>
                <button id="stopBtn" class="btn btn-danger btn-lg px-4 shadow" disabled><i class="fas fa-stop"></i> Stop</button>
                <button id="resetBtn" class="btn btn-outline-secondary btn-lg px-4 shadow"><i class="fas fa-redo"></i> Retry</button>
            </div>
<!-- INJECTED METRICS AND HIDDEN ELEMENTS FOR APP.JS -->
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
        </section>

    </main>

    <!-- COMPREHENSION SECTION -->
    <section id="assessmentModal" class="container card shadow-sm mt-4 mb-4" style="display:none; border-top: 5px solid #28a745;">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0">Comprehension Check</h5>
        </div>
        <div class="card-body bg-light">
            <div id="questionsContainer"></div>
            <div class="text-center mt-4">
                <button id="submitAssessmentBtn" class="btn btn-success btn-lg px-5 shadow">Submit Assessment</button>
            </div>
        </div>
    </section>

    <script>
        const API_TOKEN = "session-based"; 
        window.STUDENT_GRADE = <?php echo $gradeNum; ?>;
    </script>
    <script src="v536/public/js/speech-sdk.bundle.min.js"></script>
    <script src="v536/public/assessment-core.js?v=<?php echo time(); ?>"></script>
    <script src="v536/public/app.js?v=<?php echo time(); ?>"></script>
    <script src="v536/public/cascading-flow.js?v=<?php echo time(); ?>"></script>

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
