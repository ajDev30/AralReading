<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

if ($action === 'get_status') {
    // Check GST
    $stmt = $pdo->prepare("SELECT * FROM gst_results WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $gst = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch tested grades (attempts)
    $stmt = $pdo->prepare("SELECT passage_grade, oral_reading_profile as classification, accuracy_score as wr, comprehension_score as comp FROM reading_attempts WHERE user_id = ? AND passage_grade IS NOT NULL AND phase = 'Pre-Test' ORDER BY id ASC");
    $stmt->execute([$user_id]);
    $attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $tested_grades = [];
    foreach($attempts as $a) {
        $tested_grades[$a['passage_grade']] = [
            'classification' => $a['classification'],
            'wr' => $a['wr'],
            'comp' => $a['comp']
        ];
    }

    echo json_encode([
        'gst_completed' => $gst ? true : false,
        'gst_result' => $gst ? [
            'score' => (int)$gst['score'],
            'totalItems' => (int)$gst['total_items'],
            'percentage' => (float)$gst['percentage'],
            'starting_grade' => $gst['starting_grade'] !== null ? (int)$gst['starting_grade'] : null,
            'needs_individual_assessment' => $gst['needs_individual_assessment'] !== null ? (int)$gst['needs_individual_assessment'] : null,
            'gst_category' => $gst['gst_category']
        ] : null,
        'tested_grades' => $tested_grades
    ]);
    exit;
}

if ($action === 'get_gst') {
    $grade = $_GET['grade'] ?? '7';
    if(is_numeric($grade)) $grade = "Grade $grade";

    $stmt = $pdo->prepare("SELECT questions_json, time_limit_minutes FROM gst_assessments WHERE grade_level = ? LIMIT 1");
    $stmt->execute([$grade]);
    $gst = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if($gst) {
        echo json_encode([
            'questions' => json_decode($gst['questions_json'], true) ?: [],
            'time_limit' => (int)$gst['time_limit_minutes']
        ]);
    } else {
        echo json_encode(['questions' => [], 'time_limit' => 0]);
    }
    exit;
}

if ($action === 'submit_gst') {
    $score = $_POST['score'] ?? 0;
    $total = $_POST['total'] ?? 0;
    $percentage = ($total > 0) ? ($score / $total) * 100 : 0;
    
    $stmt = $pdo->prepare("SELECT grade_level FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $gradeNum = (int) filter_var($stmt->fetchColumn(), FILTER_SANITIZE_NUMBER_INT);
    if(!$gradeNum) $gradeNum = 7;

    $starting_grade = $_POST['starting_grade'] ?? null;
    $needs_individual = $_POST['needs_individual'] ?? 1;
    $category = $_POST['category'] ?? '';

    $stmt = $pdo->prepare("INSERT INTO gst_results (user_id, grade_level, score, total_items, percentage, gst_category, starting_grade, needs_individual_assessment) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $gradeNum, $score, $total, $percentage, $category, $starting_grade, $needs_individual]);
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'get_passage') {
    $grade = $_GET['grade'] ?? 'Grade 7'; // e.g., 'Grade 7' or '7'
    if(is_numeric($grade)) $grade = "Grade $grade";

    $stmt = $pdo->prepare("SELECT id, title, passage_text, questions_json FROM reading_passages WHERE grade_level = ? LIMIT 1");
    $stmt->execute([$grade]);
    $passage = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$passage) {
        $stmt = $pdo->prepare("SELECT id, title, passage_text, questions_json FROM reading_passages WHERE grade_level LIKE ? AND grade_level LIKE '%PRE-TEST%' LIMIT 1");
        $stmt->execute([str_replace('Grade ', 'GRADE ', $grade) . '%']);
        $passage = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$passage) {
            echo json_encode(["passage_text" => ""]);
            exit;
        }
    }
    echo json_encode($passage);
    exit;
}

if ($action === 'override_attempt') {
    if ($_SESSION['role'] !== 'teacher') { echo json_encode(['error' => 'Unauthorized']); exit; }
    
    $attempt_id = $_POST['attempt_id'] ?? 0;
    $acc = floatval($_POST['accuracy_score'] ?? 0);
    $miscues = $_POST['miscues_json'] ?? '{}';
    $answers_json = $_POST['answers_json'] ?? '{}';
    $eval_data = $_POST['evaluation_data'] ?? null;
    $reading_speed = isset($_POST['reading_speed']) ? floatval($_POST['reading_speed']) : null;
    
    try {
        $pdo->beginTransaction();
        
        // 1. Fetch attempt to get comprehension_score and user_id
        $stmt = $pdo->prepare("SELECT user_id, comprehension_score, passage_grade, reading_speed FROM reading_attempts WHERE id = ?");
        $stmt->execute([$attempt_id]);
        $attempt = $stmt->fetch();
        if (!$attempt) throw new Exception("Attempt not found");
        
        $uid = $attempt['user_id'];
        $comp = floatval($attempt['comprehension_score']);
        if (isset($_POST['comp_score'])) { $comp = floatval($_POST['comp_score']); }
        
        $final_reading_speed = ($reading_speed !== null) ? $reading_speed : $attempt['reading_speed'];

        // 2. Calculate new classification
        $new_class = 'Instructional';
        if ($acc >= 97 && $comp >= 80) $new_class = 'Independent';
        elseif ($acc <= 89 || $comp <= 58) $new_class = 'Frustration';
        
        // 3. Update the specific attempt
        $stmt = $pdo->prepare("UPDATE reading_attempts SET accuracy_score = ?, comprehension_score = ?, miscues_json = ?, evaluation_data = ?, oral_reading_profile = ?, answers_json = ?, reading_speed = ? WHERE id = ?");
        $stmt->execute([$acc, $comp, $miscues, $eval_data, $new_class, $answers_json, $final_reading_speed, $attempt_id]);
        
        // 4. Fetch all attempts for this student to rebuild profile
        $stmt = $pdo->prepare("SELECT passage_grade, oral_reading_profile FROM reading_attempts WHERE user_id = ? AND phase = 'Pre-Test' ORDER BY created_at ASC");
        $stmt->execute([$uid]);
        $all_attempts = $stmt->fetchAll();
        
        // Deduplicate grades, taking the latest (since ordered by ASC)
        $grades = [];
        foreach($all_attempts as $a) {
            $grades[$a['passage_grade']] = $a['oral_reading_profile'];
        }
        
        ksort($grades); // Ensure lowest grades are processed first so highest grades take precedence
        
        $ind = null; $ins = null; $fru = null;
        foreach($grades as $g => $cls) {
            if ($cls === 'Independent') $ind = $g;
            if ($cls === 'Instructional') $ins = $g;
            if ($cls === 'Frustration') $fru = $g;
        }
        
        // 5. Replace reading_profiles
        $stmt = $pdo->prepare("REPLACE INTO reading_profiles (user_id, independent_grade, instructional_grade, frustration_grade) VALUES (?, ?, ?, ?)");
        $stmt->execute([$uid, $ind, $ins, $fru]);
        
        $pdo->commit();
        
        echo json_encode([
            'status' => 'success',
            'new_passage_classification' => $new_class,
            'new_profile' => [
                'independent' => $ind,
                'instructional' => $ins,
                'frustration' => $fru
            ]
        ]);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

if ($action === 'submit_attempt') {
    $grade = $_POST['passage_grade'] ?? null;
    $acc = $_POST['accuracy_score'] ?? 0;
    $comp = $_POST['comprehension_score'] ?? 0;
    $class = $_POST['classification'] ?? 'Pending';
    $time = $_POST['reading_time'] ?? 0;
    $speed = $_POST['reading_speed'] ?? 0;
    $miscues = $_POST['miscues_json'] ?? '{}';
    $answers_json = $_POST['answers_json'] ?? '{}';
    $answers_json = $_POST['answers_json'] ?? '{}';
    $eval_data = $_POST['evaluation_data'] ?? null;
    
    $audio_path = null;
    if (isset($_FILES['audio_file']) && $_FILES['audio_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/uploads/audio/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        $filename = 'attempt_' . $user_id . '_' . time() . '.webm';
        if (move_uploaded_file($_FILES['audio_file']['tmp_name'], $upload_dir . $filename)) {
            $audio_path = 'uploads/audio/' . $filename;
        }
    }
    
    // Find passage ID
    $stmt = $pdo->prepare("SELECT id FROM reading_passages WHERE grade_level = ? LIMIT 1");
    $stmt->execute(["Grade $grade"]);
    $pid = $stmt->fetchColumn();
    if (!$pid) {
        $stmt = $pdo->prepare("SELECT id FROM reading_passages WHERE grade_level LIKE ? AND grade_level LIKE '%PRE-TEST%' LIMIT 1");
        $stmt->execute(["GRADE $grade%"]);
        $pid = $stmt->fetchColumn() ?: 0;
    }

    $stmt = $pdo->prepare("INSERT INTO reading_attempts (user_id, passage_id, passage_grade, accuracy_score, comprehension_score, oral_reading_profile, reading_time, reading_speed, miscues_json, evaluation_data, audio_path, status, phase, answers_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Completed', 'Pre-Test', ?)");
    $stmt->execute([$user_id, $pid, $grade, $acc, $comp, $class, $time, $speed, $miscues, $eval_data, $audio_path, $answers_json]);
    
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'submit_course_attempt') {
    $pid = $_POST['passage_id'] ?? 0;
    $phase = $_POST['phase'] ?? 'Course-Pre-Test';
    $acc = floatval($_POST['accuracy_score'] ?? 0);
    $comp = floatval($_POST['comprehension_score'] ?? 0);
    $class = $_POST['oral_reading_profile'] ?? 'Instructional';
    $time = intval($_POST['reading_time'] ?? 0);
    $speed = floatval($_POST['reading_speed'] ?? 0);
    $miscues = $_POST['miscues_json'] ?? '{}';
    $answers_json = $_POST['answers_json'] ?? '{}';
    $answers_json = $_POST['answers_json'] ?? '{}';
    $eval_data = $_POST['evaluation_data'] ?? null;
    
    $audio_path = null;
    if (isset($_FILES['audio_file']) && $_FILES['audio_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/uploads/audio/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        $filename = 'course_' . $user_id . '_' . time() . '.webm';
        if (move_uploaded_file($_FILES['audio_file']['tmp_name'], $upload_dir . $filename)) {
            $audio_path = 'uploads/audio/' . $filename;
        }
    }
    
    $stmt = $pdo->prepare("INSERT INTO reading_attempts (user_id, passage_id, phase, accuracy_score, comprehension_score, oral_reading_profile, reading_time, reading_speed, miscues_json, evaluation_data, audio_path, status, answers_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Completed', ?)");
    $stmt->execute([$user_id, $pid, $phase, $acc, $comp, $class, $time, $speed, $miscues, $eval_data, $audio_path, $answers_json]);
    
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'finalize_profile') {
    $ind = $_POST['independent_grade'] ?? null;
    $ins = $_POST['instructional_grade'] ?? null;
    $fru = $_POST['frustration_grade'] ?? null;
    if($ind === '') $ind = null;
    if($ins === '') $ins = null;
    if($fru === '') $fru = null;

    $stmt = $pdo->prepare("REPLACE INTO reading_profiles (user_id, independent_grade, instructional_grade, frustration_grade) VALUES (?, ?, ?, ?)");
    $stmt->execute([$user_id, $ind, $ins, $fru]);
    
    // Update main user record status
    $pdo->prepare("UPDATE users SET current_level = 'Completed Profile' WHERE id = ?")->execute([$user_id]);

    echo json_encode(['status' => 'success']);
    exit;
}

echo json_encode(['error' => 'Invalid action']);
