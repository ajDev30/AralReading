<?php
// We expect $target_user_id to be set before including this file.

// Fetch Course pairs with all 4 metrics and questions for raw score calculation
$stmtC = $pdo->prepare("
    SELECT a.id, a.passage_id, a.phase, a.accuracy_score, a.comprehension_score, a.reading_speed, a.oral_reading_profile, a.reading_time, c.title, c.questions_json 
    FROM reading_attempts a
    JOIN course_assessments c ON a.passage_id = c.id
    WHERE a.user_id = ? AND a.phase IN ('Course-Pre-Test', 'Course-Post-Test')
    ORDER BY a.created_at ASC
");
$stmtC->execute([$target_user_id]);
$c_attempts = $stmtC->fetchAll(PDO::FETCH_ASSOC);

$pairs = [];
foreach($c_attempts as $a) {
    // Extract Course Grade or Title group
    $courseGroup = 'Course Assessment';
    if (preg_match('/^(.*?)(?:\s*(?:—|-)\s*(?:PRE-TEST|POST-TEST))/i', $a['title'], $matches)) {
        $courseGroup = trim($matches[1]);
    } else if (preg_match('/GRADE (\d+)/i', $a['title'], $matches)) {
        $courseGroup = "Grade " . $matches[1] . " Reading Module";
    } else {
        $courseGroup = $a['title'];
    }

    if(!isset($pairs[$courseGroup])) {
        $pairs[$courseGroup] = [
            'title' => $courseGroup,
            'pre' => null,
            'post' => null
        ];
    }
    
    // Calculate total questions
    $totalQuestions = 10;
    if (!empty($a['questions_json'])) {
        $q = json_decode($a['questions_json'], true);
        if ($q) {
            $totalQuestions = 0;
            foreach($q as $item) {
                if ($item['type'] === 'multichoice' || !isset($item['type']) || isset($item['options'])) {
                    $totalQuestions += 1;
                } else if ($item['type'] === 'truefalse') {
                    $totalQuestions += 1;
                } else if ($item['type'] === 'enumeration') {
                    $expectedCount = (isset($item['answers']) && count($item['answers']) > 0) ? count($item['answers']) : (intval($item['count'] ?? 1));
                    $totalQuestions += $expectedCount;
                } else if ($item['type'] === 'essay') {
                    $pts = intval($item['points'] ?? 1);
                    $totalQuestions += $pts;
                }
            }
        }
    }
    
    $acc = floatval($a['accuracy_score']);
    $compPct = floatval($a['comprehension_score']);
    $compRaw = round(($compPct / 100) * $totalQuestions);
    $wpm = floatval($a['reading_speed']);
    $wcpm = round($wpm * ($acc / 100));

    $data = [
        'id' => $a['id'],
        'acc' => $acc,
        'comp' => $compPct,
        'comp_raw' => $compRaw,
        'total_questions' => $totalQuestions,
        'wpm' => $wpm,
        'wcpm' => $wcpm,
        'prof' => $a['oral_reading_profile']
    ];

    if($a['phase'] === 'Course-Pre-Test') {
        $pairs[$courseGroup]['pre'] = $data;
    } else {
        $pairs[$courseGroup]['post'] = $data;
    }
}

if (!function_exists('getProfLevel')) {
    function getProfLevel($prof) {
        $prof = strtolower(trim($prof));
        if ($prof === 'independent') return 3;
        if ($prof === 'instructional') return 2;
        if ($prof === 'frustration') return 1;
        return 0;
    }
}
?>

<?php if (count($pairs) > 0): ?>
<div class="mt-8">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-xl font-bold text-slate-800"><i class="fas fa-layer-group text-blue-500 mr-2"></i> Course Test Progress</h3>
    </div>
    
    <div class="space-y-6">
        <?php 
            foreach($pairs as $title => $p): 
                $hasPre = !empty($p['pre']);
                $hasPost = !empty($p['post']);
                
                $resultText = 'Pending';
                $resultColor = 'text-slate-600';
                $resultBg = 'bg-slate-50 border-slate-200';
                $icon = 'fa-clock';
                $rec = "Not enough data to provide a recommendation.";
                
                if ($hasPre && $hasPost) {
                    $pre = $p['pre'];
                    $post = $p['post'];
                    
                    $accDiff = $post['acc'] - $pre['acc'];
                    $compDiff = $post['comp'] - $pre['comp'];
                    $wpmDiff = $post['wpm'] - $pre['wpm'];
                    $wcpmDiff = $post['wcpm'] - $pre['wcpm'];
                    
                    $preLevel = getProfLevel($pre['prof']);
                    $postLevel = getProfLevel($post['prof']);
                    
                    $accImproved = $accDiff > 0;
                    $compImproved = $compDiff > 0;
                    $accDeclined = $accDiff < 0;
                    $compDeclined = $compDiff < 0;
                    
                    $fluencyStable = $wcpmDiff >= -2 && $wpmDiff >= -2;
                    
                    $levelPromoted = false;
                    if ($postLevel > $preLevel || (($accImproved || $compImproved) && $fluencyStable)) {
                        $resultText = 'Improved';
                        $resultColor = 'text-emerald-600';
                        $resultBg = 'bg-emerald-50 border-emerald-200';
                        $icon = 'fa-arrow-trend-up text-emerald-500';
                        $levelPromoted = ($postLevel > $preLevel);
                    } else if ($postLevel < $preLevel || ($accDeclined && $compDeclined)) {
                        $resultText = 'Declined';
                        $resultColor = 'text-rose-600';
                        $resultBg = 'bg-rose-50 border-rose-200';
                        $icon = 'fa-arrow-trend-down text-rose-500';
                    } else if ($accImproved || $compImproved) {
                        $resultText = 'Partial Progress';
                        $resultColor = 'text-blue-600';
                        $resultBg = 'bg-blue-50 border-blue-200';
                        $icon = 'fa-arrow-up-right-dots text-blue-500';
                    } else {
                        $resultText = 'Maintained';
                        $resultColor = 'text-amber-600';
                        $resultBg = 'bg-amber-50 border-amber-200';
                        $icon = 'fa-minus text-amber-500';
                    }
                } else if (!$hasPost) {
                    $resultText = 'In Progress';
                    $resultColor = 'text-blue-600';
                    $resultBg = 'bg-blue-50 border-blue-200';
                    $icon = 'fa-spinner text-blue-500';
                } else if (!$hasPre) {
                    $resultText = 'Post-Test Only';
                    $resultColor = 'text-slate-600';
                    $resultBg = 'bg-slate-50 border-slate-200';
                    $icon = 'fa-check text-slate-500';
                }

                // Prepare metrics for dynamic recommendation resolution
                $improvedAreas = [];
                if (!empty($accImproved)) $improvedAreas[] = "Accuracy (+" . number_format($accDiff, 1) . "%)";
                if (!empty($compImproved)) $improvedAreas[] = "Comprehension (+" . number_format($compDiff, 1) . "%)";
                $improvedAreasStr = implode(" and ", $improvedAreas);

                $metricsData = [
                    'outcome' => $resultText,
                    'student_name' => $student['fname'] ?? ($user['fname'] ?? 'The student'),
                    'pre_prof' => $hasPre ? $pre['prof'] : 'N/A',
                    'post_prof' => $hasPost ? $post['prof'] : 'N/A',
                    'acc_diff' => $hasPre && $hasPost ? $accDiff : 0,
                    'comp_diff' => $hasPre && $hasPost ? $compDiff : 0,
                    'wpm_diff' => $hasPre && $hasPost ? $wpmDiff : 0,
                    'improved_areas' => $improvedAreasStr,
                    'level_promoted' => !empty($levelPromoted)
                ];

                $recData = resolve_student_recommendation($pdo, $target_user_id, $p['title'], $metricsData);
                $rec = $recData['recommendation'];
                $isCustomRec = $recData['is_custom'];
                $conditionTag = $recData['condition_tag'] ?? null;
        ?>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="bg-slate-50 px-6 py-4 border-b border-slate-200 flex flex-col md:flex-row justify-between md:items-center gap-2">
                <h2 class="text-lg font-bold text-slate-800"><?php echo htmlspecialchars($p['title']); ?></h2>
                <div class="flex items-center gap-2 px-3 py-1 rounded-full <?php echo $resultBg; ?> border w-fit">
                    <i class="fas <?php echo $icon; ?> <?php echo $resultColor; ?>"></i>
                    <span class="text-sm font-bold <?php echo $resultColor; ?>"><?php echo $resultText; ?></span>
                </div>
            </div>
            
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <!-- Pre Test Card -->
                    <div class="border border-slate-200 rounded-lg p-5 <?php echo $hasPre ? 'bg-white' : 'bg-slate-50'; ?>">
                        <h3 class="font-bold text-slate-700 uppercase tracking-wider text-sm mb-4 border-b pb-2">Pre-Test</h3>
                        <?php if ($hasPre): $pre = $p['pre']; ?>
                            <div class="grid grid-cols-2 gap-4 mb-4">
                                <div>
                                    <div class="text-[10px] text-slate-500 uppercase tracking-wider font-bold mb-1">Comprehension</div>
                                    <div class="text-2xl font-black text-blue-600"><?php echo $pre['comp_raw']; ?>/<?php echo $pre['total_questions']; ?></div>
                                    <div class="text-sm font-medium text-slate-500"><?php echo number_format($pre['comp'], 1); ?>%</div>
                                </div>
                                <div>
                                    <div class="text-[10px] text-slate-500 uppercase tracking-wider font-bold mb-1">Reading Accuracy</div>
                                    <div class="text-2xl font-black text-emerald-600"><?php echo number_format($pre['acc'], 1); ?>%</div>
                                    <div class="text-sm font-medium text-slate-500">Profile: <?php echo $pre['prof']; ?></div>
                                </div>
                                <div>
                                    <div class="text-[10px] text-slate-500 uppercase tracking-wider font-bold mb-1">Reading Speed</div>
                                    <div class="text-xl font-bold text-slate-700"><?php echo round($pre['wpm']); ?> <span class="text-sm font-normal text-slate-500">WPM</span></div>
                                </div>
                                <div>
                                    <div class="text-[10px] text-slate-500 uppercase tracking-wider font-bold mb-1">Correct Words</div>
                                    <div class="text-xl font-bold text-slate-700"><?php echo $pre['wcpm']; ?> <span class="text-sm font-normal text-slate-500">WCPM</span></div>
                                </div>
                            </div>
                            <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'teacher'): ?>
                            <div class="mt-4 pt-4 border-t border-slate-100 text-center">
                                <a href="review_detail.php?id=<?php echo $pre['id']; ?>&sid=<?php echo $target_user_id; ?>&return=grades" class="inline-block px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-sm font-medium transition">
                                    <i class="fas fa-headphones mr-2"></i> Review Audio & Transcript
                                </a>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-center py-8 text-slate-400">
                                <i class="fas fa-times-circle text-3xl mb-2 opacity-50"></i>
                                <p class="font-medium">Not Taken</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Post Test Card -->
                    <div class="border border-slate-200 rounded-lg p-5 <?php echo $hasPost ? 'bg-white' : 'bg-slate-50'; ?>">
                        <h3 class="font-bold text-slate-700 uppercase tracking-wider text-sm mb-4 border-b pb-2">Post-Test</h3>
                        <?php if ($hasPost): $post = $p['post']; ?>
                            <div class="grid grid-cols-2 gap-4 mb-4">
                                <div>
                                    <div class="text-[10px] text-slate-500 uppercase tracking-wider font-bold mb-1">Comprehension</div>
                                    <div class="flex items-end gap-2">
                                        <div class="text-2xl font-black text-blue-600"><?php echo $post['comp_raw']; ?>/<?php echo $post['total_questions']; ?></div>
                                        <?php if($hasPre): 
                                            $cd = $post['comp'] - $p['pre']['comp']; 
                                            $col = $cd > 0 ? 'text-emerald-500' : ($cd < 0 ? 'text-rose-500' : 'text-slate-400');
                                            $sign = $cd > 0 ? '+' : '';
                                        ?>
                                        <div class="text-xs font-bold <?php echo $col; ?> mb-1"><?php echo $sign . number_format($cd, 1); ?>%</div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-sm font-medium text-slate-500"><?php echo number_format($post['comp'], 1); ?>%</div>
                                </div>
                                <div>
                                    <div class="text-[10px] text-slate-500 uppercase tracking-wider font-bold mb-1">Reading Accuracy</div>
                                    <div class="flex items-end gap-2">
                                        <div class="text-2xl font-black text-emerald-600"><?php echo number_format($post['acc'], 1); ?>%</div>
                                        <?php if($hasPre): 
                                            $ad = $post['acc'] - $p['pre']['acc']; 
                                            $col = $ad > 0 ? 'text-emerald-500' : ($ad < 0 ? 'text-rose-500' : 'text-slate-400');
                                            $sign = $ad > 0 ? '+' : '';
                                        ?>
                                        <div class="text-xs font-bold <?php echo $col; ?> mb-1"><?php echo $sign . number_format($ad, 1); ?>%</div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-sm font-medium text-slate-500">Profile: <?php echo $post['prof']; ?></div>
                                </div>
                                <div>
                                    <div class="text-[10px] text-slate-500 uppercase tracking-wider font-bold mb-1">Reading Speed</div>
                                    <div class="flex items-end gap-2">
                                        <div class="text-xl font-bold text-slate-700"><?php echo round($post['wpm']); ?> <span class="text-sm font-normal text-slate-500">WPM</span></div>
                                        <?php if($hasPre): 
                                            $wd = $post['wpm'] - $p['pre']['wpm']; 
                                            $col = $wd > 0 ? 'text-emerald-500' : ($wd < 0 ? 'text-rose-500' : 'text-slate-400');
                                            $sign = $wd > 0 ? '+' : '';
                                        ?>
                                        <div class="text-xs font-bold <?php echo $col; ?> mb-0.5"><?php echo $sign . round($wd); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div>
                                    <div class="text-[10px] text-slate-500 uppercase tracking-wider font-bold mb-1">Correct Words</div>
                                    <div class="flex items-end gap-2">
                                        <div class="text-xl font-bold text-slate-700"><?php echo $post['wcpm']; ?> <span class="text-sm font-normal text-slate-500">WCPM</span></div>
                                        <?php if($hasPre): 
                                            $wcd = $post['wcpm'] - $p['pre']['wcpm']; 
                                            $col = $wcd > 0 ? 'text-emerald-500' : ($wcd < 0 ? 'text-rose-500' : 'text-slate-400');
                                            $sign = $wcd > 0 ? '+' : '';
                                        ?>
                                        <div class="text-xs font-bold <?php echo $col; ?> mb-0.5"><?php echo $sign . round($wcd); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'teacher'): ?>
                            <div class="mt-4 pt-4 border-t border-slate-100 text-center">
                                <a href="review_detail.php?id=<?php echo $post['id']; ?>&sid=<?php echo $target_user_id; ?>&return=grades" class="inline-block px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-sm font-medium transition">
                                    <i class="fas fa-headphones mr-2"></i> Review Audio & Transcript
                                </a>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-center py-8 text-slate-400">
                                <i class="fas fa-times-circle text-3xl mb-2 opacity-50"></i>
                                <p class="font-medium">Not Taken</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Recommendation -->
                <div class="<?php echo str_replace('border-', 'border-l-4 border-y-0 border-r-0 bg-', $resultBg); ?> bg-opacity-50 rounded-r-lg p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-xs font-bold <?php echo $resultColor; ?> uppercase tracking-wider">Final Recommendation</span>
                            <?php if ($isCustomRec): ?>
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-indigo-100 text-indigo-700 border border-indigo-200" title="Customized specifically by teacher">
                                    <i class="fas fa-user-edit text-[10px]"></i> Teacher's Recommendation
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-slate-100 text-slate-600 border border-slate-200" title="Generated by automated condition rule">
                                    <i class="fas fa-robot text-[10px] text-blue-500"></i> Automated Rule
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($conditionTag)): ?>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-white text-slate-600 border border-slate-200 shadow-xs">
                                    <i class="fas fa-tag text-[10px] text-slate-400"></i> <?php echo htmlspecialchars($conditionTag); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'teacher'): ?>
                        <div class="flex items-center gap-2">
                            <button type="button" 
                                onclick='openCustomizeRecModal(<?php echo htmlspecialchars(json_encode([
                                    "user_id" => (int)$target_user_id,
                                    "student_name" => $student["fname"] ?? ($user["fname"] ?? "Student"),
                                    "course_group" => $p["title"],
                                    "recommendation" => $rec,
                                    "condition_tag" => $conditionTag ?? "",
                                    "is_custom" => $isCustomRec
                                ]), ENT_QUOTES, "UTF-8"); ?>)' 
                                class="inline-flex items-center gap-1.5 px-3 py-1 bg-white hover:bg-slate-100 text-slate-700 text-xs font-semibold rounded-md border border-slate-300 shadow-sm transition">
                                <i class="fas fa-edit text-blue-600"></i> <?php echo $isCustomRec ? 'Edit Recommendation' : 'Customize Recommendation'; ?>
                            </button>
                            <?php if ($isCustomRec): ?>
                            <button type="button" 
                                onclick="revertStudentRec(<?php echo (int)$target_user_id; ?>, <?php echo htmlspecialchars(json_encode($p['title']), ENT_QUOTES, 'UTF-8'); ?>)" 
                                class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-rose-50 hover:bg-rose-100 text-rose-700 text-xs font-semibold rounded-md border border-rose-200 transition" 
                                title="Revert back to standard automated rule">
                                <i class="fas fa-rotate-left"></i> Revert
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <p class="text-sm text-slate-700 font-medium whitespace-pre-line leading-relaxed"><?php echo htmlspecialchars($rec); ?></p>
                </div>

            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php else: ?>
    <div class="bg-white p-8 rounded-xl border border-slate-200 text-center mt-8">
        <i class="fas fa-layer-group text-slate-300 text-4xl mb-3"></i>
        <p class="text-slate-500 font-medium">No Course Tests Taken Yet</p>
        <p class="text-sm text-slate-400 mt-1">When the user takes a Course Pre-Test or Post-Test, their progress will appear here.</p>
    </div>
<?php endif; ?>
