<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: index.php");
    exit;
}

$student_id = (int)($_GET['user_id'] ?? $_GET['student_id'] ?? $_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT fname, lname, grade_level, section FROM users WHERE id = ?");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    die("Student not found.");
}

// Fetch GST Result
$stmtGST = $pdo->prepare("SELECT * FROM gst_results WHERE user_id = ? ORDER BY completed_at DESC LIMIT 1");
$stmtGST->execute([$student_id]);
$gst_info = $stmtGST->fetch(PDO::FETCH_ASSOC);

// Fetch Reading Profile (Cascading)
$stmtRP = $pdo->prepare("SELECT * FROM reading_profiles WHERE user_id = ?");
$stmtRP->execute([$student_id]);
$rp_info = $stmtRP->fetch(PDO::FETCH_ASSOC);

$enrolled_grade = intval(preg_replace('/[^0-9]/', '', $student['grade_level'] ?? ''));
$baseline_profile = 'Pending';
if (isset($gst_info['needs_individual_assessment']) && (int)$gst_info['needs_individual_assessment'] === 0) {
    $baseline_profile = 'Independent';
} elseif ($rp_info && ($rp_info['independent_grade'] !== null || $rp_info['instructional_grade'] !== null || $rp_info['frustration_grade'] !== null)) {
    if ($rp_info['independent_grade'] !== null && $enrolled_grade <= intval($rp_info['independent_grade'])) {
        $baseline_profile = 'Independent';
    } elseif ($rp_info['instructional_grade'] !== null && $enrolled_grade <= intval($rp_info['instructional_grade'])) {
        $baseline_profile = 'Instructional';
    } else {
        if ($rp_info['frustration_grade'] !== null && intval($rp_info['frustration_grade']) <= 4 && $rp_info['instructional_grade'] === null && $rp_info['independent_grade'] === null) {
            $baseline_profile = 'Non-Reader';
        } else {
            $baseline_profile = 'Frustration';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Grades - <?php echo htmlspecialchars($student['fname'] . ' ' . $student['lname']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #F8FAFC; }
        .sidebar { background-color: #1a365d; }
    </style>
</head>
<body class="flex h-screen overflow-hidden text-slate-800">
    <?php include 'teacher_sidebar.php'; ?>
    <div class="flex-1 flex flex-col h-screen overflow-hidden">
        <header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-4 md:px-8 shrink-0 gap-2">
            <div class="flex items-center min-w-0 flex-1">
                <button onclick="toggleSidebar()" class="md:hidden mr-3 text-slate-500 hover:text-blue-600 focus:outline-none shrink-0"><i class="fas fa-bars text-xl"></i></button>
                <a href="teacher_course_grades.php" class="text-slate-400 hover:text-slate-600 mr-3 shrink-0"><i class="fas fa-arrow-left"></i></a>
                <?php 
                    $stu_img_path = "uploads/profiles/user_" . $student_id . ".jpg";
                    $stu_img = file_exists($stu_img_path) ? $stu_img_path . "?v=" . filemtime($stu_img_path) : null;
                ?>
                <?php if ($stu_img): ?>
                    <img src="<?php echo htmlspecialchars($stu_img); ?>" alt="<?php echo htmlspecialchars($student['fname']); ?>" class="w-8 h-8 md:w-10 md:h-10 rounded-full object-cover border border-slate-200 mr-2.5 shrink-0">
                <?php else: ?>
                    <div class="w-8 h-8 md:w-10 md:h-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold mr-2.5 text-sm shrink-0">
                        <?php echo strtoupper(substr($student['fname'] ?? 'S', 0, 1)); ?>
                    </div>
                <?php endif; ?>
                <div class="min-w-0">
                    <h1 class="font-bold text-sm sm:text-base md:text-lg text-slate-800 truncate">Course Grades: <?php echo htmlspecialchars($student['fname'] . ' ' . $student['lname']); ?></h1>
                    <p class="text-xs text-slate-500 truncate">Grade <?php echo htmlspecialchars($student['grade_level'] ?? ''); ?> - Section <?php echo htmlspecialchars($student['section'] ?? ''); ?></p>
                </div>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <button onclick="openRulesModal()" class="inline-flex items-center gap-1.5 px-2.5 sm:px-3.5 py-2 bg-blue-50 text-blue-700 hover:bg-blue-100 rounded-lg text-xs sm:text-sm font-semibold border border-blue-200 transition shadow-xs">
                    <i class="fas fa-sliders-h"></i>
                    <span class="hidden sm:inline">Manage Conditional Rules</span>
                    <span class="sm:hidden">Rules</span>
                </button>
            </div>
        </header>

        <main class="flex-1 p-4 md:p-8 overflow-y-auto max-w-6xl mx-auto w-full">
            <!-- Phil-IRI Pre-Assessment Baseline Card -->
            <div class="bg-white rounded-xl shadow-xs border border-slate-200 p-5 mb-6">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-3 border-b border-slate-100 gap-2 mb-4">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-blue-100 text-blue-600 flex items-center justify-center font-bold text-sm">
                            <i class="fas fa-clipboard-check"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-slate-800 text-sm">Phil-IRI Pre-Assessment Baseline</h3>
                            <p class="text-xs text-slate-500">Initial diagnostic intake: GST screening and cascading oral assessment</p>
                        </div>
                    </div>
                    <?php if ($gst_info || $rp_info): ?>
                        <a href="student_submissions.php?user_id=<?php echo $student_id; ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-semibold transition">
                            <i class="fas fa-microphone-alt text-slate-500"></i> View Audio & Passages
                        </a>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- GST Screening -->
                    <div class="p-3.5 bg-blue-50/40 rounded-lg border border-blue-100">
                        <span class="text-[11px] font-bold text-blue-800 uppercase tracking-wider block mb-1">GST Screening</span>
                        <?php if ($gst_info): ?>
                            <div class="flex items-baseline gap-2">
                                <span class="text-xl font-bold text-slate-800"><?php echo htmlspecialchars($gst_info['score'] . '/' . $gst_info['total_items']); ?></span>
                                <span class="text-xs text-slate-500 font-semibold">(<?php echo number_format($gst_info['percentage'], 1); ?>%)</span>
                            </div>
                            <div class="mt-1">
                                <?php if ((int)$gst_info['needs_individual_assessment'] === 0): ?>
                                    <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold bg-emerald-100 text-emerald-800">
                                        <i class="fas fa-check-circle mr-1"></i> Exempt (Score 28+)
                                    </span>
                                <?php else: ?>
                                    <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold bg-amber-100 text-amber-800">
                                        Needs Individual Assessment
                                    </span>
                                    <div class="text-xs text-blue-700 font-medium mt-1">Starting Passage: Grade <?php echo htmlspecialchars($gst_info['starting_grade']); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <span class="text-xs text-slate-400 font-medium">Not taken yet</span>
                        <?php endif; ?>
                    </div>

                    <!-- Cascading Diagnostic Profile -->
                    <div class="p-3.5 bg-indigo-50/40 rounded-lg border border-indigo-100">
                        <span class="text-[11px] font-bold text-indigo-800 uppercase tracking-wider block mb-1">Cascading Oral Profile</span>
                        <?php 
                        $bp_badge = 'bg-slate-100 text-slate-600';
                        if ($baseline_profile === 'Independent') $bp_badge = 'bg-emerald-100 text-emerald-800';
                        elseif ($baseline_profile === 'Instructional') $bp_badge = 'bg-amber-100 text-amber-800';
                        elseif ($baseline_profile === 'Frustration') $bp_badge = 'bg-rose-100 text-rose-800';
                        elseif ($baseline_profile === 'Non-Reader') $bp_badge = 'bg-slate-700 text-white';
                        ?>
                        <div class="flex items-center gap-2">
                            <span class="inline-block px-2.5 py-1 rounded-full text-xs font-bold <?php echo $bp_badge; ?>">
                                <?php echo htmlspecialchars($baseline_profile); ?>
                            </span>
                        </div>
                        <div class="text-xs text-slate-600 mt-2 space-y-0.5">
                            <?php if ($rp_info): ?>
                                <?php if ($rp_info['instructional_grade']): ?><div>Instructional Level: <b>Grade <?php echo htmlspecialchars($rp_info['instructional_grade']); ?></b></div><?php endif; ?>
                                <?php if ($rp_info['frustration_grade']): ?><div>Frustration Level: <b>Grade <?php echo htmlspecialchars($rp_info['frustration_grade']); ?></b></div><?php endif; ?>
                                <?php if ($rp_info['independent_grade']): ?><div>Independent Level: <b>Grade <?php echo htmlspecialchars($rp_info['independent_grade']); ?></b></div><?php endif; ?>
                            <?php elseif ($gst_info && (int)$gst_info['needs_individual_assessment'] === 0): ?>
                                <div>Exempt: Demonstrated grade-level mastery on GST</div>
                            <?php else: ?>
                                <div class="text-slate-400">Cascading passages pending</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php 
                $target_user_id = $student_id;
                include 'comparison_widget.php';
            ?>
        </main>
    </div>

    <!-- Customize Recommendation Modal -->
    <div id="customizeRecModal" class="fixed inset-0 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4 z-50 hidden transition-opacity duration-200">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl overflow-hidden flex flex-col max-h-[90vh]">
            <!-- Modal Header -->
            <div class="p-5 border-b border-slate-100 flex items-center justify-between bg-slate-50">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center font-bold">
                        <i class="fas fa-user-edit text-lg"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-slate-800 text-base" id="recModalTitle">Customize Recommendation</h3>
                        <p class="text-xs text-slate-500" id="recModalSubtitle">Tailor specific guidance for this student</p>
                    </div>
                </div>
                <button type="button" onclick="closeCustomizeRecModal()" class="text-slate-400 hover:text-slate-600 w-8 h-8 rounded-lg flex items-center justify-center hover:bg-slate-200/50 transition">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>

            <!-- Modal Form -->
            <form id="customizeRecForm" onsubmit="saveStudentRec(event)" class="p-6 overflow-y-auto space-y-4">
                <input type="hidden" name="user_id" id="recUserId" value="">
                <input type="hidden" name="course_group" id="recCourseGroup" value="">

                <div>
                    <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">Condition Tag / Label</label>
                    <input type="text" name="condition_tag" id="recConditionTag" placeholder="e.g. Personalized Guidance, Targeted Intervention, Fast-Track" class="w-full px-3.5 py-2 border border-slate-300 rounded-lg text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <div class="flex flex-wrap gap-1.5 mt-2">
                        <span class="text-[11px] text-slate-400 self-center mr-1">Quick Tags:</span>
                        <button type="button" onclick="setRecTag('Teacher Note')" class="px-2 py-0.5 rounded text-xs bg-slate-100 text-slate-600 hover:bg-slate-200 transition">Teacher Note</button>
                        <button type="button" onclick="setRecTag('Targeted Practice')" class="px-2 py-0.5 rounded text-xs bg-slate-100 text-slate-600 hover:bg-slate-200 transition">Targeted Practice</button>
                        <button type="button" onclick="setRecTag('Intervention Plan')" class="px-2 py-0.5 rounded text-xs bg-slate-100 text-slate-600 hover:bg-slate-200 transition">Intervention Plan</button>
                        <button type="button" onclick="setRecTag('Advanced Enrichment')" class="px-2 py-0.5 rounded text-xs bg-slate-100 text-slate-600 hover:bg-slate-200 transition">Advanced Enrichment</button>
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider">Recommendation Text</label>
                        <span class="text-[11px] text-slate-400">Click any shortcut below to insert</span>
                    </div>

                    <!-- Quick Insert Shortcut Pills -->
                    <div class="flex flex-wrap items-center gap-1.5 mb-2 p-2 bg-slate-50 border border-slate-200 rounded-lg">
                        <span class="text-[11px] font-bold text-slate-500 mr-1"><i class="fas fa-terminal text-blue-500 mr-1"></i>Shortcuts:</span>
                        <button type="button" onclick="insertToken('recText', '/student')" class="px-2 py-0.5 rounded text-xs bg-white text-blue-700 font-semibold border border-blue-200 hover:bg-blue-50 transition shadow-2xs" title="Student First Name">/student</button>
                        <button type="button" onclick="insertToken('recText', '/pre-test')" class="px-2 py-0.5 rounded text-xs bg-white text-indigo-700 font-semibold border border-indigo-200 hover:bg-indigo-50 transition shadow-2xs" title="Pre-Test Reading Profile">/pre-test</button>
                        <button type="button" onclick="insertToken('recText', '/post-test')" class="px-2 py-0.5 rounded text-xs bg-white text-emerald-700 font-semibold border border-emerald-200 hover:bg-emerald-50 transition shadow-2xs" title="Post-Test Reading Profile">/post-test</button>
                        <button type="button" onclick="insertToken('recText', '/diff_accuracy')" class="px-2 py-0.5 rounded text-xs bg-white text-cyan-700 font-semibold border border-cyan-200 hover:bg-cyan-50 transition shadow-2xs" title="Accuracy Difference %">/diff_accuracy</button>
                        <button type="button" onclick="insertToken('recText', '/diff_comprehension')" class="px-2 py-0.5 rounded text-xs bg-white text-violet-700 font-semibold border border-violet-200 hover:bg-violet-50 transition shadow-2xs" title="Comprehension Difference %">/diff_comprehension</button>
                        <button type="button" onclick="insertToken('recText', '/improve')" class="px-2 py-0.5 rounded text-xs bg-white text-amber-700 font-semibold border border-amber-200 hover:bg-amber-50 transition shadow-2xs" title="Improved Areas Summary">/improve</button>
                    </div>

                    <textarea name="recommendation_text" id="recText" rows="5" required placeholder="Write personalized teacher recommendation here e.g. Great work /student! Keep practicing..." class="w-full p-3.5 border border-slate-300 rounded-lg text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500 leading-relaxed"></textarea>
                </div>

                <!-- Template Selector -->
                <div class="bg-blue-50/60 border border-blue-100 rounded-xl p-3.5">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-1.5">
                        <span class="text-xs font-bold text-blue-800"><i class="fas fa-lightbulb text-amber-500 mr-1.5"></i> Insert From Existing Rule</span>
                        <select id="quickTemplateSelect" onchange="insertTemplateFromSelect()" class="text-xs border border-blue-200 bg-white rounded px-2.5 py-1 text-slate-700 focus:outline-none focus:ring-1 focus:ring-blue-500 max-w-xs">
                            <option value="">-- Choose a rule template --</option>
                        </select>
                    </div>
                    <p class="text-[11px] text-blue-600 leading-normal">
                        Select a template above to use it as a starting draft, or write your own custom guidance from scratch.
                    </p>
                </div>

                <div id="recModalAlert" class="hidden p-3 rounded-lg text-xs font-medium"></div>

                <!-- Actions -->
                <div class="pt-2 flex items-center justify-end gap-3 border-t border-slate-100">
                    <button type="button" onclick="closeCustomizeRecModal()" class="px-4 py-2 border border-slate-300 text-slate-600 hover:bg-slate-50 rounded-lg text-sm font-medium transition">
                        Cancel
                    </button>
                    <button type="submit" id="saveRecBtn" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-semibold transition shadow-sm flex items-center gap-2">
                        <i class="fas fa-save"></i>
                        <span>Save Recommendation</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Manage Conditional Rules Modal -->
    <?php include 'conditional_rules_modal.php'; ?>


    <script>
        window.cachedRules = [];

        window.toggleSidebar = function() {
            const sidebar = document.getElementById('appSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if(sidebar) sidebar.classList.toggle('-translate-x-full');
            if(overlay) overlay.classList.toggle('hidden');
        };

        // --- Customize Recommendation Modal ---
        window.openCustomizeRecModal = function(data) {
            document.getElementById('recUserId').value = data.user_id;
            document.getElementById('recCourseGroup').value = data.course_group;
            document.getElementById('recConditionTag').value = data.condition_tag || 'Teacher Note';
            document.getElementById('recText').value = data.recommendation || '';
            document.getElementById('recModalTitle').textContent = 'Customize Recommendation - ' + data.course_group;
            document.getElementById('recModalSubtitle').textContent = 'Personalize recommendation for ' + data.student_name;
            
            const alert = document.getElementById('recModalAlert');
            alert.className = 'hidden p-3 rounded-lg text-xs font-medium';
            alert.textContent = '';

            loadRulesDropdown();
            document.getElementById('customizeRecModal').classList.remove('hidden');
        };

        window.closeCustomizeRecModal = function() {
            document.getElementById('customizeRecModal').classList.add('hidden');
        };

        window.setRecTag = function(tag) {
            document.getElementById('recConditionTag').value = tag;
        };

        window.insertToken = function(targetTextareaId, token) {
            const textarea = document.getElementById(targetTextareaId);
            if (!textarea) return;

            const start = textarea.selectionStart !== undefined ? textarea.selectionStart : textarea.value.length;
            const end = textarea.selectionEnd !== undefined ? textarea.selectionEnd : textarea.value.length;
            const text = textarea.value;

            let insertStr = token;
            if (start > 0 && text[start - 1] !== ' ' && text[start - 1] !== '\n') {
                insertStr = ' ' + insertStr;
            }
            if (end < text.length && text[end] !== ' ' && text[end] !== '\n' && text[end] !== '.' && text[end] !== '!' && text[end] !== '?') {
                insertStr += ' ';
            }

            textarea.value = text.substring(0, start) + insertStr + text.substring(end);
            textarea.focus();
            const newPos = start + insertStr.length;
            textarea.setSelectionRange(newPos, newPos);
        };

        window.insertTemplateFromSelect = function() {
            const select = document.getElementById('quickTemplateSelect');
            const val = select.value;
            if (val) {
                const studentName = <?php echo json_encode($student['fname']); ?>;
                const substituted = val.replace(/\{student_name\}|\/student/g, studentName);
                document.getElementById('recText').value = substituted;
            }
        };

        window.saveStudentRec = function(e) {
            e.preventDefault();
            const form = document.getElementById('customizeRecForm');
            const submitBtn = document.getElementById('saveRecBtn');
            const alertBox = document.getElementById('recModalAlert');

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Saving...';

            const formData = new FormData(form);
            formData.append('action', 'save_student_rec');

            fetch('api_recommendations.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-save mr-1"></i> Save Recommendation';

                if (data.success) {
                    alertBox.className = 'p-3 rounded-lg text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200';
                    alertBox.textContent = data.message || 'Saved successfully!';
                    setTimeout(() => {
                        window.location.reload();
                    }, 600);
                } else {
                    alertBox.className = 'p-3 rounded-lg text-xs font-medium bg-rose-50 text-rose-700 border border-rose-200';
                    alertBox.textContent = data.error || 'Failed to save recommendation.';
                }
            })
            .catch(err => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-save mr-1"></i> Save Recommendation';
                alertBox.className = 'p-3 rounded-lg text-xs font-medium bg-rose-50 text-rose-700 border border-rose-200';
                alertBox.textContent = 'Server error: ' + err.message;
            });
        };

        window.revertStudentRec = function(userId, courseGroup) {
            if (!confirm('Revert to the standard automated rule-based recommendation for "' + courseGroup + '"?')) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'delete_student_rec');
            formData.append('user_id', userId);
            formData.append('course_group', courseGroup);

            fetch('api_recommendations.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.error || 'Failed to revert recommendation.');
                }
            })
            .catch(err => alert('Network error: ' + err.message));
        };



        function loadRulesDropdown() {
            if (!window.cachedRules || window.cachedRules.length === 0) {
                fetch('api_recommendations.php?action=get_rules')
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.rules) {
                        window.cachedRules = data.rules;
                        populateDropdown(data.rules);
                    }
                });
            } else {
                populateDropdown(window.cachedRules);
            }
        }

        function populateDropdown(rules) {
            const select = document.getElementById('quickTemplateSelect');
            if (!select) return;
            select.innerHTML = '<option value="">-- Choose a rule template --</option>' +
                rules.map(r => `<option value="${escapeHtml(r.recommendation_template)}">[${escapeHtml(r.outcome)}] ${escapeHtml(r.condition_name)}</option>`).join('');
        }


        // Preload rules for template dropdown on initial page load
        document.addEventListener('DOMContentLoaded', () => {
            loadRulesDropdown();
        });
    </script>
</body>
</html>
