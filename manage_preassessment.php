<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: index.php");
    exit;
}

$grade = $_GET['grade'] ?? null;
$action = $_POST['action'] ?? null;

if (!$grade) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <link rel="icon" type="image/png" href="https://tibungcodistrict.wordpress.com/wp-content/uploads/2018/06/untitled-1.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta charset="UTF-8">
        <title>Manage English Passages</title>
        <script>
        const originalWarn = console.warn;
        console.warn = function() {
            if (arguments[0] && typeof arguments[0] === 'string' && arguments[0].includes('cdn.tailwindcss.com should not be used in production')) return;
            originalWarn.apply(console, arguments);
        };
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>body { background-color: #F8FAFC; } .sidebar { background-color: #1a365d; }        <?php $is_dark = isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark'; ?>
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

    
    <!-- TinyMCE Editor -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js" referrerpolicy="origin"></script>
    <script>
      function initTinyMCE() {
        if (typeof tinymce !== 'undefined') {
            tinymce.remove('textarea[name="passage_text"]');
            tinymce.init({
                selector: 'textarea[name="passage_text"]',
                plugins: 'lists link code',
                toolbar: 'undo redo | formatselect | bold italic | alignleft aligncenter alignright | bullist numlist | code',
                menubar: false,
                height: 300,
                branding: false,
                setup: function (editor) {
                    editor.on('change', function () {
                        editor.save();
                    });
                }
            });
        }
      }
      document.addEventListener('DOMContentLoaded', initTinyMCE);
      document.addEventListener('htmx:afterSwap', initTinyMCE);
    </script>


</head>
    <body class="flex h-screen overflow-hidden text-slate-800">
    <?php include 'teacher_sidebar.php'; ?>
    <div class="flex-1 flex flex-col h-screen overflow-hidden">
        <header class="h-16 bg-white border-b border-slate-200 flex items-center px-4 md:px-8 shrink-0" hx-boost="true">
            <div class="flex items-center">
                <button onclick="toggleSidebar()" class="md:hidden mr-4 text-slate-500 hover:text-blue-600 focus:outline-none"><i class="fas fa-bars text-xl"></i></button>
            <i class="fas fa-book-reader text-blue-600 text-2xl mr-3"></i>
            <h1 class="font-bold text-lg text-slate-800">Manage Reading Passages</h1>
            </div>
        </header>
        <main class="flex-1 p-4 md:p-8 max-w-5xl mx-auto w-full overflow-y-auto">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-slate-800">Select Grade Level to Edit</h2>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach(['Grade 4','Grade 5','Grade 6','Grade 7','Grade 8','Grade 9','Grade 10'] as $g): ?>
                    <a href="manage_preassessment.php?grade=<?php echo urlencode($g); ?>" class="bg-white border border-slate-200 rounded-xl p-4 md:p-8 text-center hover:border-blue-500 hover:shadow-md transition">
                        <div class="text-5xl text-blue-500 mb-4"><i class="fas fa-book-open"></i></div>
                        <h3 class="text-xl font-bold text-slate-700"><?php echo $g; ?></h3>
                        <p class="text-slate-500 text-sm mt-2">Manage the English Reading Passage and Comprehension Questions.</p>
                    </a>
                <?php endforeach; ?>
            </div>
        </main>
    
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
    <?php
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM reading_passages WHERE grade_level = ?");
$stmt->execute([$grade]);
$record = $stmt->fetch(PDO::FETCH_OBJ);

if (!$record) {
    $stmt = $pdo->prepare("SELECT * FROM reading_passages WHERE grade_level LIKE ? AND grade_level LIKE '%PRE-TEST%' LIMIT 1");
    $stmt->execute([str_replace('Grade ', 'GRADE ', $grade) . '%']);
    $record = $stmt->fetch(PDO::FETCH_OBJ);
}

if (!$record) {
    $pdo->prepare("INSERT INTO reading_passages (title, grade_level, passage_text, questions_json) VALUES (?, ?, '', '[]')")
        ->execute(["$grade Assessment", $grade]);
    $stmt = $pdo->prepare("SELECT * FROM reading_passages WHERE grade_level = ?");
    $stmt->execute([$grade]);
    $record = $stmt->fetch(PDO::FETCH_OBJ);
}

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $passage = $_POST['passage_text'] ?? '';
    $questions_json = $_POST['questions_json'] ?? '[]';
    
    $update = $pdo->prepare("UPDATE reading_passages SET passage_text=?, questions_json=? WHERE id=?");
    $update->execute([$passage, $questions_json, $record->id]);
    
    header("Location: manage_preassessment.php?grade=" . urlencode($grade) . "&success=1");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="https://tibungcodistrict.wordpress.com/wp-content/uploads/2018/06/untitled-1.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="UTF-8">
    <title>Edit <?php echo htmlspecialchars($grade); ?> Passage</title>
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
        body { background-color: #F8FAFC; } .sidebar { background-color: #1a365d; }
    
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

    <!-- TinyMCE Editor -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js" referrerpolicy="origin"></script>
    <script>
      tinymce.init({
        selector: 'textarea[name="passage_text"]',
        plugins: 'lists link code',
        toolbar: 'undo redo | formatselect | bold italic | alignleft aligncenter alignright | bullist numlist | code',
        menubar: false,
        height: 300,
        branding: false
      });
    </script>

</head>
<body class="flex h-screen overflow-hidden text-slate-800">
    <?php include 'teacher_sidebar.php'; ?>
    <div class="flex-1 flex flex-col h-screen overflow-hidden">

    <header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-4 md:px-8 shrink-0 gap-2">
        <div class="flex items-center min-w-0">
            <button onclick="toggleSidebar()" class="md:hidden mr-3 text-slate-500 hover:text-blue-600 focus:outline-none shrink-0"><i class="fas fa-bars text-xl"></i></button>
            <a href="manage_preassessment.php" class="text-slate-400 hover:text-slate-600 mr-3 shrink-0"><i class="fas fa-arrow-left"></i></a>
            <i class="fas fa-book-reader text-blue-600 text-xl md:text-2xl mr-2.5 shrink-0"></i>
            <h1 class="font-bold text-base md:text-lg text-slate-800 truncate">Edit <?php echo htmlspecialchars($grade); ?> Passage</h1>
        </div>
        <div class="shrink-0">
            <button type="submit" form="editForm" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2 px-3.5 sm:px-6 rounded shadow transition flex items-center text-xs sm:text-sm">
                <i class="fas fa-save mr-1.5 sm:mr-2"></i> Save Content
            </button>
        </div>
    </header>

    <main class="flex-1 p-4 md:p-8 max-w-4xl mx-auto w-full overflow-y-auto">
        <?php if(isset($_GET['success'])): ?>
            <div class="bg-emerald-50 text-emerald-700 p-4 rounded border border-emerald-200 mb-6 font-bold shadow-sm flex items-center">
                <i class="fas fa-check-circle mr-2"></i> Passage and questions saved successfully!
            </div>
        <?php endif; ?>

        <form method="POST" id="editForm" onsubmit="return validateAndSyncJSON()">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="questions_json" id="questions_json" value="<?php echo htmlspecialchars($record->questions_json); ?>">

            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 mb-6">
                <div class="flex items-center border-b border-slate-100 pb-3 mb-4">
                    <i class="fas fa-book text-blue-500 mr-2"></i>
                    <h3 class="font-bold text-lg text-slate-800">Grade-Level Reading Passage (English)</h3>
                </div>
                <div class="mb-2">
                    <textarea class="w-full border-slate-300 rounded text-base p-4 bg-slate-50 focus:bg-white border focus:ring-2 focus:ring-blue-100 focus:border-blue-500 transition outline-none" name="passage_text" rows="8" required placeholder="Paste the English reading passage text here..."><?php echo htmlspecialchars($record->passage_text); ?></textarea>
                </div>
                <p class="text-slate-500 text-sm"><i class="fas fa-info-circle mr-1"></i> This single passage will be tested dynamically. The system will classify the student's performance as Independent, Instructional, or Frustration automatically.</p>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 mb-10">
                <div class="flex flex-col md:flex-row md:items-center justify-between border-b border-slate-100 pb-3 mb-4">
                    <div class="flex items-center">
                        <i class="fas fa-clipboard-list text-blue-500 mr-2"></i>
                        <h3 class="font-bold text-lg text-slate-800">Comprehension Questions</h3>
                    </div>

                </div>
                
                
                <div id="ra-q-list" class="space-y-4 pb-16"></div>
                
                <!-- Bottom Add Question Controls (Floating) -->
                <div class="sticky bottom-4 z-50 bg-white/95 backdrop-blur border-2 border-blue-100 rounded-xl shadow-[0_0_20px_rgba(0,0,0,0.1)] p-3 sm:p-4 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-2.5 transform transition-all -translate-y-2">
                    <div class="text-slate-700 text-xs sm:text-sm font-bold flex items-center">
                        <i class="fas fa-plus-circle text-blue-500 mr-2 text-base sm:text-lg"></i>
                        Add New Item to Questionnaire:
                    </div>
                    <div class="flex flex-col sm:flex-row gap-2 bg-slate-50 p-1.5 rounded-lg border border-slate-200 shadow-inner w-full sm:w-auto">
                        <select id="ra-q-type-add-bottom" class="border border-slate-300 rounded px-3 py-2 text-xs sm:text-sm bg-white font-bold outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition cursor-pointer w-full sm:w-auto">
                            <option value="description">ℹ️ Instruction / Text</option>
                            <option value="multichoice">🔘 Multiple Choice</option>
                            <option value="truefalse">✅ True or False</option>
                            <option value="enumeration">🔢 Enumeration</option>
                            <option value="essay">📝 Essay</option>
                        </select>
                        <button type="button" onclick="addQ(document.getElementById('ra-q-type-add-bottom').value)" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 sm:px-6 rounded text-xs sm:text-sm transition shadow flex items-center justify-center w-full sm:w-auto">
                            <i class="fas fa-plus mr-2"></i> Add Question
                        </button>
                    </div>
                </div>

            </div>
        </form>
    </main>

<script>
let questions = [];
try { questions = JSON.parse(document.getElementById('questions_json').value) || []; } catch(e) { questions = []; }

function escapeHtml(str) {
    if (!str) return "";
    return String(str).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}


function updateEnumCount(qIdx, newCount) {
    let q = questions[qIdx];
    let count = parseInt(newCount) || 1;
    q.count = count;
    if (!q.answers) q.answers = [];
    while (q.answers.length < count) q.answers.push("");
    if (q.answers.length > count) q.answers = q.answers.slice(0, count);
    syncJSON();
    renderQ();
}
function updateEnumAnswer(qIdx, aIdx, val) {
    questions[qIdx].answers[aIdx] = val;
    syncJSON();

    if (typeof tinymce !== 'undefined') {
        tinymce.remove('.tinymce-q');
        tinymce.init({
            selector: '.tinymce-q',
            height: 200,
            menubar: false,
            plugins: 'lists link image',
            toolbar: 'bold italic underline | bullist numlist | link image',
                        paste_data_images: false,
            images_upload_url: 'api_upload_image.php',
            automatic_uploads: true,
            file_picker_types: 'image',
            setup: function(editor) {
                editor.on('change keyup', function() {
                    editor.save();
                    let idx = editor.getElement().getAttribute('data-idx');
                    updateQProp(idx, 'question', editor.getContent());
                });
            }
        });
    }

}


let formIsDirty = false;

document.addEventListener('input', () => { formIsDirty = true; });
if (typeof tinymce !== 'undefined') {
    tinymce.on('AddEditor', function (e) {
        e.editor.on('change keyup', function () { formIsDirty = true; });
    });
}

window.addEventListener('beforeunload', function (e) {
    if (formIsDirty) {
        e.preventDefault();
        e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
    }
});

function validateAndSyncJSON() {
    let isValid = true;
    
    if (typeof tinymce !== 'undefined') {
        tinymce.triggerSave();
    }
    
    document.querySelectorAll('.border-red-500').forEach(el => {
        el.classList.remove('border-red-500', 'border-2');
    });
    document.querySelectorAll('.val-err').forEach(el => el.remove());

    questions.forEach((q, idx) => {
        let qText = q.question;
        // If TinyMCE hasn't synced back to q.question yet, grab from textarea
        const ta = document.querySelector(`.tinymce-q[data-idx="${idx}"]`);
        if (ta && ta.value) {
            qText = ta.value;
        }

        if (!qText || qText.trim() === "" || qText.replace(/<[^>]*>?/gm, '').trim() === "") {
            isValid = false;
            highlightError('question', idx);
        }
        
        if (q.type === 'multichoice' && q.options) {
            q.options.forEach((opt, oIdx) => {
                if (!opt || opt.trim() === "") {
                    isValid = false;
                    highlightError(`option-${oIdx}`, idx);
                }
            });
        }
        
        if (q.type === 'enumeration' && q.answers) {
            q.answers.forEach((ans, aIdx) => {
                if (!ans || ans.trim() === "") {
                    isValid = false;
                    highlightError(`answer-${aIdx}`, idx);
                }
            });
        }
    });

    if (!isValid) {
        alert("Cannot be saved. Please fill in all the descriptions and choices before saving.");
        return false;
    }
    
    syncJSON();
    formIsDirty = false;
    return true;
}

function highlightError(fieldStr, qIdx) {
    let el = null;
    if (fieldStr === 'question') {
        const ta = document.querySelector(`.tinymce-q[data-idx="${qIdx}"]`);
        if (ta) {
            const editor = tinymce.get(ta.id);
            if (editor && editor.getContainer()) {
                el = editor.getContainer();
            } else {
                el = ta;
            }
        }
    } else if (fieldStr.startsWith('option-')) {
        const oIdx = fieldStr.split('-')[1];
        el = document.querySelector(`input.mc-option[data-qidx="${qIdx}"][data-oidx="${oIdx}"]`);
    } else if (fieldStr.startsWith('answer-')) {
        const aIdx = fieldStr.split('-')[1];
        el = document.querySelector(`input.enum-answer[data-qidx="${qIdx}"][data-aidx="${aIdx}"]`);
    }
    
    if (el) {
        el.classList.add('border-red-500', 'border-2');
        if (!el.nextElementSibling || !el.nextElementSibling.classList.contains('val-err')) {
            const err = document.createElement('div');
            err.className = 'val-err text-red-500 text-xs font-bold mt-1';
            err.innerText = 'This field is required';
            el.parentNode.insertBefore(err, el.nextSibling);
        }
    }
}

function syncJSON() {
    document.getElementById('questions_json').value = JSON.stringify(questions);
}

function renderQ() {
    const list = document.getElementById('ra-q-list');
    list.innerHTML = '';
    
    if (questions.length === 0) {
        list.innerHTML = '<div class="text-slate-400 py-8 text-center border-2 border-dashed border-slate-200 rounded-lg bg-slate-50">No comprehension questions added yet.</div>';
    }

    let qNum = 1;
    questions.forEach((q, idx) => {
        const card = document.createElement('div');
        card.className = "bg-white border border-slate-200 rounded-lg p-5 relative shadow-sm hover:shadow-md transition";
        let typeName = "", typeColor = "", contentHtml = "";

        if (q.type === 'multichoice') {
            typeName = "Multiple Choice"; typeColor = "bg-blue-500";
            const opts = q.options || ["", ""];
            contentHtml = `
                <div class="mb-3">
                    <label class="block text-sm font-bold text-slate-700 mb-1">Question:</label>
                    <textarea class="tinymce-q w-full border-slate-300 rounded text-sm p-2 bg-slate-50 focus:bg-white border focus:ring-1 focus:ring-blue-500 outline-none" rows="2" data-idx="${idx}" oninput="updateQProp(${idx}, 'question', this.value)">${escapeHtml(q.question || "")}</textarea>
                </div>
                <div class="mb-2 text-sm font-bold text-slate-700">Choices: <span class="font-normal text-slate-500">(Select the radio button to mark correct answer)</span></div>
                <div class="space-y-2 mb-3">
                    ${opts.map((opt, oidx) => `
                        <div class="flex items-center space-x-2">
                            <input type="radio" class="w-4 h-4 text-blue-600 cursor-pointer" name="correct_${idx}" ${parseInt(q.correct) === oidx ? "checked" : ""} onchange="updateQProp(${idx}, 'correct', ${oidx})">
                            <input type="text" class="mc-option flex-1 border border-slate-300 rounded text-sm p-2 outline-none focus:border-blue-500" data-qidx="${idx}" data-oidx="${oidx}" value="${escapeHtml(opt)}" oninput="updateQOption(${idx}, ${oidx}, this.value)">
                            <button type="button" class="text-red-400 hover:text-red-600 px-2" onclick="removeQOption(${idx}, ${oidx})"><i class="fas fa-times"></i></button>
                        </div>
                    `).join('')}
                </div>
                <button type="button" class="text-blue-600 text-sm font-bold hover:underline" onclick="addQOption(${idx})"><i class="fas fa-plus text-xs mr-1"></i> Add Choice</button>
            `;
        } else if (q.type === 'truefalse') {
            typeName = "True/False"; typeColor = "bg-emerald-500";
            contentHtml = `
                <div class="mb-3">
                    <label class="block text-sm font-bold text-slate-700 mb-1">Statement:</label>
                    <textarea class="tinymce-q w-full border-slate-300 rounded text-sm p-2 bg-slate-50 focus:bg-white border focus:ring-1 focus:ring-blue-500 outline-none" rows="2" data-idx="${idx}" oninput="updateQProp(${idx}, 'question', this.value)">${escapeHtml(q.question || "")}</textarea>
                </div>
                <div class="flex items-center text-sm font-bold text-slate-700">
                    Correct Answer: 
                    <select class="ml-3 border border-slate-300 rounded px-2 py-1 outline-none focus:border-blue-500" onchange="updateQProp(${idx}, 'correct', this.value === 'true')">
                        <option value="true" ${q.correct === true ? "selected" : ""}>True</option>
                        <option value="false" ${q.correct === false ? "selected" : ""}>False</option>
                    </select>
                </div>
            `;
        } else if (q.type === 'enumeration') {
            typeName = "Enumeration"; typeColor = "bg-purple-500";
            q.answers = q.answers || Array.from({length: parseInt(q.count) || 3}, () => "");
            q.caseSensitive = q.caseSensitive || false;
            q.anyOrder = q.anyOrder !== undefined ? q.anyOrder : true;
            
            contentHtml = `
                <div class="mb-3">
                    <label class="block text-sm font-bold text-slate-700 mb-1">Instruction / Prompt:</label>
                    <textarea class="tinymce-q w-full border-slate-300 rounded text-sm p-2 bg-slate-50 focus:bg-white border focus:ring-1 focus:ring-blue-500 outline-none" rows="2" data-idx="${idx}" oninput="updateQProp(${idx}, 'question', this.value)">${escapeHtml(q.question || "")}</textarea>
                </div>
                
                <div class="mb-3 flex flex-wrap items-center justify-between bg-slate-50 p-3 rounded border border-slate-200 gap-2">
                    <div class="flex items-center gap-4 text-sm font-bold text-slate-700">
                        <label class="flex items-center cursor-pointer">
                            <input type="checkbox" class="mr-2" onchange="updateQProp(${idx}, 'caseSensitive', this.checked)" ${q.caseSensitive ? 'checked' : ''}> Case-Sensitive
                        </label>
                        <label class="flex items-center cursor-pointer">
                            <input type="checkbox" class="mr-2" onchange="updateQProp(${idx}, 'anyOrder', this.checked)" ${q.anyOrder ? 'checked' : ''}> In Any Order
                        </label>
                    </div>
                    <div class="text-sm font-bold text-slate-700">
                        Items:
                        <input type="number" min="1" max="10" class="ml-2 w-16 border border-slate-300 rounded px-2 py-1 outline-none text-center" onchange="updateEnumCount(${idx}, this.value)" value="${q.answers.length}">
                    </div>
                </div>

                <div class="space-y-2 mb-2">
                    <div class="text-sm font-bold text-slate-700 mb-1">Correct Answers:</div>
                    ${q.answers.map((ans, aIdx) => `
                        <div class="flex items-center space-x-2">
                            <span class="text-slate-400 font-bold w-6 text-right">${aIdx+1}.</span>
                            <input type="text" class="enum-answer flex-1 border border-slate-300 rounded px-3 py-1 outline-none focus:border-blue-500" data-qidx="${idx}" data-aidx="${aIdx}" placeholder="Correct answer ${aIdx+1}" value="${escapeHtml(ans)}" oninput="updateEnumAnswer(${idx}, ${aIdx}, this.value)">
                        </div>
                    `).join('')}
                </div>
            `;
        } else if (q.type === 'essay') {
            typeName = "Essay"; typeColor = "bg-pink-500";
            q.points = q.points || 30;
            contentHtml = `
                <div class="mb-3">
                    <label class="block text-sm font-bold text-slate-700 mb-1">Essay Prompt:</label>
                    <textarea class="tinymce-q w-full border-slate-300 rounded text-sm p-2 bg-slate-50 focus:bg-white border focus:ring-1 focus:ring-blue-500 outline-none" rows="3" data-idx="${idx}" oninput="updateQProp(${idx}, 'question', this.value)">${escapeHtml(q.question || "")}</textarea>
                </div>
                <div class="flex items-center text-sm font-bold text-slate-700">
                    Max Points for this Essay:
                    <input type="number" min="1" max="100" class="ml-3 w-20 border border-slate-300 rounded px-2 py-1 outline-none text-center focus:border-blue-500" onchange="updateQProp(${idx}, 'points', this.value)" value="${q.points}">
                </div>
            `;
        } else if (q.type === 'description') {
            typeName = "Instruction"; typeColor = "bg-slate-500";
            contentHtml = `
                <div class="mb-1">
                    <label class="block text-sm font-bold text-slate-700 mb-1">Instruction Text:</label>
                    <textarea class="tinymce-q w-full border-slate-300 rounded text-sm p-2 bg-slate-50 focus:bg-white border focus:ring-1 focus:ring-blue-500 outline-none" rows="2" data-idx="${idx}" oninput="updateQProp(${idx}, 'question', this.value)">${escapeHtml(q.question || "")}</textarea>
                </div>
            `;
        }

        let displayNumHtml = '';
        if (q.type !== 'description') {
            displayNumHtml = `<span class="font-bold text-slate-400 mr-3 text-lg">#${qNum}</span>`;
            qNum++;
        } else {
            displayNumHtml = `<span class="text-slate-400 mr-3 text-lg" title="Instruction/Description (No Number)"><i class="fas fa-info-circle"></i></span>`;
        }

        card.innerHTML = `
            <div class="flex justify-between items-center mb-3 pb-3 border-b border-slate-100">
                <div class="flex items-center">
                    ${displayNumHtml}
                    <span class="${typeColor} text-white text-xs font-bold px-2 py-1 rounded-full uppercase tracking-wide">${typeName}</span>
                </div>
                <div class="flex space-x-1">
                    <button type="button" class="text-slate-400 hover:text-slate-600 p-1" onclick="moveQ(${idx}, -1)" title="Move Up"><i class="fas fa-arrow-up"></i></button>
                    <button type="button" class="text-slate-400 hover:text-slate-600 p-1" onclick="moveQ(${idx}, 1)" title="Move Down"><i class="fas fa-arrow-down"></i></button>
                    <button type="button" class="text-red-400 hover:text-red-600 p-1 ml-2" onclick="deleteQ(${idx})" title="Delete"><i class="fas fa-trash-alt"></i></button>
                </div>
            </div>
            ${contentHtml}
        `;
        list.appendChild(card);
    });
    syncJSON();

    if (typeof tinymce !== 'undefined') {
        tinymce.remove('.tinymce-q');
        tinymce.init({
            selector: '.tinymce-q',
            height: 200,
            menubar: false,
            plugins: 'lists link image',
            toolbar: 'bold italic underline | bullist numlist | link image',
                        paste_data_images: false,
            images_upload_url: 'api_upload_image.php',
            automatic_uploads: true,
            file_picker_types: 'image',
            setup: function(editor) {
                editor.on('change keyup', function() {
                    editor.save();
                    let idx = editor.getElement().getAttribute('data-idx');
                    updateQProp(idx, 'question', editor.getContent());
                });
            }
        });
    }

}

window.addQ = function(overrideType = null) {
    const type = overrideType || (document.getElementById('ra-q-type-add') ? document.getElementById('ra-q-type-add').value : 'multichoice');
    let newQ = { type: type, question: "" };
    if (type === 'multichoice') { newQ.options = ["Option 1", "Option 2"]; newQ.correct = 0; } 
    else if (type === 'truefalse') { newQ.correct = true; }
    questions.push(newQ);
    renderQ();
};

window.deleteQ = function(idx) { if (confirm("Delete this question?")) { questions.splice(idx, 1); renderQ(); } };
window.moveQ = function(idx, dir) { const target = idx + dir; if (target >= 0 && target < questions.length) { const temp = questions[idx]; questions[idx] = questions[target]; questions[target] = temp; renderQ(); } };
window.updateQProp = function(idx, prop, val) { questions[idx][prop] = val; syncJSON(); };
window.addQOption = function(idx) { if (!questions[idx].options) questions[idx].options = []; questions[idx].options.push(""); renderQ(); };
window.removeQOption = function(idx, oidx) { questions[idx].options.splice(oidx, 1); if (questions[idx].correct >= questions[idx].options.length) questions[idx].correct = Math.max(0, questions[idx].options.length - 1); renderQ(); };
window.updateQOption = function(idx, oidx, val) { questions[idx].options[oidx] = val; syncJSON(); };

// Initial render
renderQ();
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
    </div>
</body>
</html>
