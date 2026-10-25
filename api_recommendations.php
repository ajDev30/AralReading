<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

// RBAC: Only teachers can access this API
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized: Teacher access required.']);
    exit;
}

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

if ($action === 'save_student_rec') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $courseGroup = trim($_POST['course_group'] ?? '');
    $text = trim($_POST['recommendation_text'] ?? '');
    $tag = trim($_POST['condition_tag'] ?? 'Teacher Note');

    if ($userId <= 0 || empty($courseGroup) || empty($text)) {
        echo json_encode(['success' => false, 'error' => 'Student ID, course group, and recommendation text are required.']);
        exit;
    }

    $teacherId = (int)$_SESSION['user_id'];
    $ok = save_student_custom_recommendation($pdo, $userId, $courseGroup, $text, $tag, $teacherId);

    if ($ok) {
        echo json_encode([
            'success' => true, 
            'message' => 'Personalized recommendation saved for this student.',
            'recommendation' => $text,
            'condition_tag' => $tag
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to save recommendation to database.']);
    }
    exit;
}

if ($action === 'delete_student_rec') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $courseGroup = trim($_POST['course_group'] ?? '');

    if ($userId <= 0 || empty($courseGroup)) {
        echo json_encode(['success' => false, 'error' => 'Student ID and course group are required.']);
        exit;
    }

    $ok = delete_student_custom_recommendation($pdo, $userId, $courseGroup);
    if ($ok) {
        echo json_encode(['success' => true, 'message' => 'Reverted to automated rule-based recommendation.']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to revert recommendation.']);
    }
    exit;
}

if ($action === 'get_rules') {
    $rules = get_recommendation_rules($pdo);
    echo json_encode(['success' => true, 'rules' => $rules]);
    exit;
}

if ($action === 'save_rule') {
    $ruleId = (int)($_POST['rule_id'] ?? 0);
    $name = trim($_POST['condition_name'] ?? '');
    $outcome = trim($_POST['outcome'] ?? 'Improved');
    $profile = trim($_POST['target_profile'] ?? 'Any');
    $template = trim($_POST['recommendation_template'] ?? '');

    if (empty($name) || empty($template)) {
        echo json_encode(['success' => false, 'error' => 'Condition name and recommendation template are required.']);
        exit;
    }

    if ($ruleId > 0) {
        $stmt = $pdo->prepare("UPDATE recommendation_rules SET condition_name = ?, outcome = ?, target_profile = ?, recommendation_template = ? WHERE id = ?");
        $stmt->execute([$name, $outcome, $profile, $template, $ruleId]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO recommendation_rules (condition_name, outcome, target_profile, recommendation_template) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $outcome, $profile, $template]);
    }

    echo json_encode(['success' => true, 'message' => 'Conditional rule saved successfully.']);
    exit;
}

if ($action === 'delete_rule') {
    $ruleId = (int)($_POST['rule_id'] ?? 0);
    if ($ruleId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid rule ID.']);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM recommendation_rules WHERE id = ?");
    $stmt->execute([$ruleId]);

    echo json_encode(['success' => true, 'message' => 'Conditional rule deleted.']);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action.']);
