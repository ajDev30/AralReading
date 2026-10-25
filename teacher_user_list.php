<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: index.php");
    exit;
}

$current_teacher_id = (int)$_SESSION['user_id'];
$sections_by_grade = get_sections_by_grade($pdo);
$flash_success = '';
$flash_error = '';

/**
 * Helper to recalculate reading_profiles from a student's remaining Pre-Test attempts
 */
function rebuild_student_reading_profile(PDO $pdo, int $uid): void {
    $stmt = $pdo->prepare("SELECT passage_grade, oral_reading_profile FROM reading_attempts WHERE user_id = ? AND phase = 'Pre-Test' AND passage_grade IS NOT NULL ORDER BY created_at ASC, id ASC");
    $stmt->execute([$uid]);
    $all_attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($all_attempts)) {
        $pdo->prepare("DELETE FROM reading_profiles WHERE user_id = ?")->execute([$uid]);
        return;
    }

    $grades = [];
    foreach ($all_attempts as $a) {
        $grades[(int)$a['passage_grade']] = $a['oral_reading_profile'];
    }
    ksort($grades);

    $ind = null;
    $ins = null;
    $fru = null;
    foreach ($grades as $g => $cls) {
        if ($cls === 'Independent') $ind = $g;
        elseif ($cls === 'Instructional') $ins = $g;
        elseif ($cls === 'Frustration' || $cls === 'Non-Reader') $fru = $g;
    }

    $stmt = $pdo->prepare("REPLACE INTO reading_profiles (user_id, independent_grade, instructional_grade, frustration_grade) VALUES (?, ?, ?, ?)");
    $stmt->execute([$uid, $ind, $ins, $fru]);
}

/**
 * Helper to apply an explicit Oral Reading Level to a student
 */
function apply_manual_oral_reading_level(PDO $pdo, int $uid, string $oral_level, int $enrolled_grade): void {
    if ($enrolled_grade <= 0) $enrolled_grade = 7;

    if ($oral_level === 'Pending') {
        $pdo->prepare("UPDATE users SET current_level = 'Pending' WHERE id = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM reading_profiles WHERE user_id = ?")->execute([$uid]);
        return;
    }

    if (!in_array($oral_level, ['Independent', 'Instructional', 'Frustration', 'Non-Reader'], true)) {
        return;
    }

    $pdo->prepare("UPDATE users SET current_level = ? WHERE id = ?")->execute([$oral_level, $uid]);

    $ind = null;
    $ins = null;
    $fru = null;
    if ($oral_level === 'Independent') {
        $ind = $enrolled_grade;
    } elseif ($oral_level === 'Instructional') {
        $ins = $enrolled_grade;
        $pdo->prepare("UPDATE gst_results SET needs_individual_assessment = 1 WHERE user_id = ? AND needs_individual_assessment = 0")->execute([$uid]);
    } elseif ($oral_level === 'Frustration') {
        $fru = max(5, $enrolled_grade);
        $pdo->prepare("UPDATE gst_results SET needs_individual_assessment = 1 WHERE user_id = ? AND needs_individual_assessment = 0")->execute([$uid]);
    } elseif ($oral_level === 'Non-Reader') {
        $fru = 4;
        $pdo->prepare("UPDATE gst_results SET needs_individual_assessment = 1 WHERE user_id = ? AND needs_individual_assessment = 0")->execute([$uid]);
    }

    $stmt = $pdo->prepare("REPLACE INTO reading_profiles (user_id, independent_grade, instructional_grade, frustration_grade) VALUES (?, ?, ?, ?)");
    $stmt->execute([$uid, $ind, $ins, $fru]);
}

/**
 * Helper to insert or update a student's GST result
 */
function upsert_student_gst(PDO $pdo, int $uid, int $enrolled_grade, int $score, int $total_items = 40, ?int $custom_starting_grade = null): void {
    if ($enrolled_grade <= 0) $enrolled_grade = 7;
    if ($total_items <= 0) $total_items = 40;
    $score = max(0, min($score, $total_items));
    $percentage = ($total_items > 0) ? round(($score / $total_items) * 100, 2) : 0;

    if ($score >= 28) {
        $offset = 0;
        $category = "Exempt from Individual Assessment (Score 28+)";
        $needs_indiv = 0;
    } elseif ($score >= 16) {
        $offset = 2;
        $category = "Requires Individual Assessment (Score 16-27)";
        $needs_indiv = 1;
    } else {
        $offset = 3;
        $category = "Requires Individual Assessment (Score 0-15)";
        $needs_indiv = 1;
    }

    $starting_grade = $custom_starting_grade !== null ? $custom_starting_grade : max(4, $enrolled_grade - $offset);
    if ($custom_starting_grade !== null) {
        $needs_indiv = 1;
        $category = "Teacher Assigned Starting Grade (Grade $starting_grade)";
    }

    $pdo->prepare("DELETE FROM gst_results WHERE user_id = ?")->execute([$uid]);
    $stmt = $pdo->prepare("INSERT INTO gst_results (user_id, grade_level, score, total_items, percentage, gst_category, starting_grade, needs_individual_assessment) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$uid, $enrolled_grade, $score, $total_items, $percentage, $category, $starting_grade, $needs_indiv]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_action = $_POST['form_action'] ?? '';

    try {
        if ($form_action === 'add_user') {
            $role = ($_POST['role'] ?? 'student') === 'teacher' ? 'teacher' : 'student';
            $fname = trim($_POST['fname'] ?? '');
            $mname = trim($_POST['mname'] ?? '');
            $lname = trim($_POST['lname'] ?? '');
            $gender = trim($_POST['gender'] ?? '');
            $grade_num = (int)preg_replace('/[^0-9]/', '', $_POST['grade_level'] ?? '7');
            if ($grade_num < 7 || $grade_num > 10) $grade_num = 7;
            $grade_level = $role === 'student' ? "Grade $grade_num" : '';
            $section = $role === 'student' ? trim($_POST['section'] ?? '') : '';

            if ($fname === '' || $lname === '') {
                throw new Exception("First Name and Surname are required.");
            }

            // LRN handling for students
            $lrn_suffix = preg_replace('/[^0-9]/', '', $_POST['lrn_suffix'] ?? '');
            if ($role === 'student') {
                if ($lrn_suffix !== '') {
                    if (strlen($lrn_suffix) !== 6) {
                        throw new Exception("LRN last 6 digits must be exactly 6 numbers (or leave blank to auto-generate).");
                    }
                    $lrn = '301942' . $lrn_suffix;
                } else {
                    do {
                        $lrn = '301942' . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                        $chk = $pdo->prepare("SELECT id FROM users WHERE lrn = ?");
                        $chk->execute([$lrn]);
                    } while ($chk->fetch());
                }
            } else {
                $lrn = 'N/A';
            }

            // Username & Email (auto-generate if blank)
            $username = trim($_POST['username'] ?? '');
            if ($username === '') {
                $base_user = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $fname) . '.' . preg_replace('/[^a-zA-Z0-9]/', '', $lname));
                if ($base_user === '.') $base_user = 'user';
                $username = $base_user;
                $suffix_idx = 1;
                while (true) {
                    $chk = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                    $chk->execute([$username]);
                    if (!$chk->fetch()) break;
                    $username = $base_user . $suffix_idx;
                    $suffix_idx++;
                }
            } else {
                $chk = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $chk->execute([$username]);
                if ($chk->fetch()) {
                    throw new Exception("Username '$username' is already taken.");
                }
            }

            $email = trim($_POST['email'] ?? '');
            if ($email === '') {
                $email = $username . '@aral.local';
            } else {
                $chk = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $chk->execute([$email]);
                if ($chk->fetch()) {
                    throw new Exception("Email '$email' is already registered.");
                }
            }

            if ($role === 'student' && $lrn !== 'N/A') {
                $chk = $pdo->prepare("SELECT id FROM users WHERE lrn = ?");
                $chk->execute([$lrn]);
                if ($chk->fetch()) {
                    throw new Exception("LRN '$lrn' already exists.");
                }
            }

            $raw_password = $_POST['password'] ?? '';
            if (trim($raw_password) === '') {
                $raw_password = '123456';
            }
            $hashed_password = password_hash($raw_password, PASSWORD_BCRYPT);

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO users (fname, mname, lname, lrn, age, gender, section, grade_level, email, username, password, role, current_level) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
            $stmt->execute([
                $fname,
                $mname,
                $lname,
                $lrn,
                13,
                $gender ?: 'Male',
                $section,
                $grade_level,
                $email,
                $username,
                $hashed_password,
                $role
            ]);
            $new_uid = (int)$pdo->lastInsertId();

            if ($role === 'student') {
                $gst_score_raw = trim($_POST['gst_score'] ?? '');
                $gst_total_raw = (int)($_POST['gst_total'] ?? 40);
                $starting_grade_raw = trim($_POST['starting_grade'] ?? '');
                $custom_start = ($starting_grade_raw !== '' && is_numeric($starting_grade_raw)) ? (int)$starting_grade_raw : null;

                if ($gst_score_raw !== '' && is_numeric($gst_score_raw)) {
                    upsert_student_gst($pdo, $new_uid, $grade_num, (int)$gst_score_raw, $gst_total_raw > 0 ? $gst_total_raw : 40, $custom_start);
                    if ((int)$gst_score_raw >= 28 && $custom_start === null && empty($_POST['oral_reading_level'])) {
                        apply_manual_oral_reading_level($pdo, $new_uid, 'Independent', $grade_num);
                    }
                } elseif ($custom_start !== null) {
                    upsert_student_gst($pdo, $new_uid, $grade_num, 0, 40, $custom_start);
                }

                $oral_level = trim($_POST['oral_reading_level'] ?? '');
                if ($oral_level !== '') {
                    apply_manual_oral_reading_level($pdo, $new_uid, $oral_level, $grade_num);
                }
            }

            $pdo->commit();
            $flash_success = "User " . htmlspecialchars("$fname $lname") . " (@$username) added successfully!";

        } elseif ($form_action === 'toggle_role') {
            $target_id = (int)($_POST['user_id'] ?? 0);
            $new_role = ($_POST['new_role'] ?? '') === 'teacher' ? 'teacher' : 'student';

            if ($target_id <= 0) {
                throw new Exception("Invalid user selected.");
            }
            if ($target_id === $current_teacher_id && $new_role !== 'teacher') {
                throw new Exception("You cannot demote your own active Teacher/Admin account.");
            }

            $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmt->execute([$new_role, $target_id]);
            $flash_success = $new_role === 'teacher'
                ? "User has been promoted to Teacher / Admin."
                : "User role has been updated to Student.";

        } elseif ($form_action === 'delete_user') {
            $target_id = (int)($_POST['user_id'] ?? 0);
            if ($target_id <= 0) {
                throw new Exception("Invalid user selected.");
            }
            if ($target_id === $current_teacher_id) {
                throw new Exception("You cannot delete your own active Teacher/Admin account.");
            }

            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM gst_results WHERE user_id = ?")->execute([$target_id]);
            $pdo->prepare("DELETE FROM reading_profiles WHERE user_id = ?")->execute([$target_id]);
            $pdo->prepare("DELETE FROM reading_attempts WHERE user_id = ?")->execute([$target_id]);
            $pdo->prepare("DELETE FROM student_recommendations WHERE user_id = ?")->execute([$target_id]);
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$target_id]);
            $pdo->commit();

            $flash_success = "User and all associated assessment records have been removed.";

        } elseif ($form_action === 'edit_user') {
            $target_id = (int)($_POST['user_id'] ?? 0);
            if ($target_id <= 0) {
                throw new Exception("Invalid user selected.");
            }

            $fname = trim($_POST['fname'] ?? '');
            $mname = trim($_POST['mname'] ?? '');
            $lname = trim($_POST['lname'] ?? '');
            $gender = trim($_POST['gender'] ?? 'Male');
            $role = ($_POST['role'] ?? 'student') === 'teacher' ? 'teacher' : 'student';
            if ($target_id === $current_teacher_id && $role !== 'teacher') {
                throw new Exception("You cannot demote your own active Teacher/Admin account.");
            }

            $grade_num = (int)preg_replace('/[^0-9]/', '', $_POST['grade_level'] ?? '7');
            if ($grade_num < 7 || $grade_num > 10) $grade_num = 7;
            $grade_level = $role === 'student' ? "Grade $grade_num" : trim($_POST['grade_level'] ?? '');
            $section = trim($_POST['section'] ?? '');
            $lrn = trim($_POST['lrn'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $new_pass = trim($_POST['new_password'] ?? '');

            if ($fname === '' || $lname === '') {
                throw new Exception("First Name and Surname are required.");
            }

            if ($username !== '') {
                $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                $chk->execute([$username, $target_id]);
                if ($chk->fetch()) {
                    throw new Exception("Username '$username' is already used by another account.");
                }
            }

            $pdo->beginTransaction();

            $sql = "UPDATE users SET fname = ?, mname = ?, lname = ?, gender = ?, role = ?, grade_level = ?, section = ?, lrn = ?, username = ?, email = ? WHERE id = ?";
            $pdo->prepare($sql)->execute([
                $fname, $mname, $lname, $gender, $role, $grade_level, $section, $lrn, $username, $email, $target_id
            ]);

            if ($new_pass !== '') {
                $hashed = password_hash($new_pass, PASSWORD_BCRYPT);
                $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $target_id]);
            }

            // Student-specific Assessment & Cascading operations
            $actions_taken = ["Profile updated"];

            // 1. GST Score Reset or Update
            $gst_action = $_POST['gst_action'] ?? 'keep';
            if ($gst_action === 'reset') {
                $pdo->prepare("DELETE FROM gst_results WHERE user_id = ?")->execute([$target_id]);
                $pdo->prepare("UPDATE users SET current_level = 'Pending' WHERE id = ?")->execute([$target_id]);
                $actions_taken[] = "GST score reset";
            } elseif ($gst_action === 'set') {
                $new_gst_score = (int)($_POST['edit_gst_score'] ?? 0);
                $new_gst_total = (int)($_POST['edit_gst_total'] ?? 40);
                upsert_student_gst($pdo, $target_id, $grade_num, $new_gst_score, $new_gst_total > 0 ? $new_gst_total : 40);
                $actions_taken[] = "GST score updated to $new_gst_score/$new_gst_total";
            }

            // 2. Remove specific cascading passage grade levels checked by teacher
            $remove_grades = $_POST['remove_passage_grades'] ?? [];
            if (is_array($remove_grades) && count($remove_grades) > 0) {
                $delStmt = $pdo->prepare("DELETE FROM reading_attempts WHERE user_id = ? AND phase = 'Pre-Test' AND passage_grade = ?");
                $removed_list = [];
                foreach ($remove_grades as $rg) {
                    $rg_int = (int)$rg;
                    if ($rg_int > 0) {
                        $delStmt->execute([$target_id, $rg_int]);
                        $removed_list[] = "Grade $rg_int";
                    }
                }
                if (!empty($removed_list)) {
                    rebuild_student_reading_profile($pdo, $target_id);
                    $pdo->prepare("UPDATE users SET current_level = 'Pending' WHERE id = ?")->execute([$target_id]);
                    $actions_taken[] = "Removed cascading passage(s): " . implode(', ', $removed_list);
                }
            }

            // 3. Cascading System Reset or Start at Specific Grade Reading Level
            $cascade_action = $_POST['cascade_action'] ?? 'keep';
            if ($cascade_action === 'reset_all') {
                $pdo->prepare("DELETE FROM reading_attempts WHERE user_id = ? AND phase = 'Pre-Test'")->execute([$target_id]);
                $pdo->prepare("DELETE FROM reading_profiles WHERE user_id = ?")->execute([$target_id]);
                $pdo->prepare("UPDATE users SET current_level = 'Pending' WHERE id = ?")->execute([$target_id]);
                $actions_taken[] = "Cascading system reset";
            } elseif ($cascade_action === 'start_grade') {
                $target_start_grade = (int)($_POST['cascade_start_grade'] ?? 0);
                $clear_existing_cascade = !empty($_POST['cascade_clear_existing']);
                if ($target_start_grade >= 4 && $target_start_grade <= 10) {
                    if ($clear_existing_cascade) {
                        $pdo->prepare("DELETE FROM reading_attempts WHERE user_id = ? AND phase = 'Pre-Test'")->execute([$target_id]);
                        $pdo->prepare("DELETE FROM reading_profiles WHERE user_id = ?")->execute([$target_id]);
                    } else {
                        // Remove any existing attempt at the target starting grade so cascading-flow.js starts at this grade next
                        $pdo->prepare("DELETE FROM reading_attempts WHERE user_id = ? AND phase = 'Pre-Test' AND passage_grade = ?")->execute([$target_id, $target_start_grade]);
                        rebuild_student_reading_profile($pdo, $target_id);
                    }

                    // Ensure gst_results exists and points starting_grade to $target_start_grade with needs_individual_assessment = 1
                    $stmtGst = $pdo->prepare("SELECT id FROM gst_results WHERE user_id = ? ORDER BY id DESC LIMIT 1");
                    $stmtGst->execute([$target_id]);
                    $existingGst = $stmtGst->fetch();
                    if ($existingGst) {
                        $pdo->prepare("UPDATE gst_results SET starting_grade = ?, needs_individual_assessment = 1, gst_category = ? WHERE id = ?")
                            ->execute([$target_start_grade, "Teacher Assigned Starting Grade (Grade $target_start_grade)", $existingGst['id']]);
                    } else {
                        upsert_student_gst($pdo, $target_id, $grade_num, 0, 40, $target_start_grade);
                    }

                    $pdo->prepare("UPDATE users SET current_level = 'Pending' WHERE id = ?")->execute([$target_id]);
                    $actions_taken[] = "Cascading set to start at Grade $target_start_grade";
                }
            }

            // 4. Reset Course Pre-Test / Post-Test
            if (!empty($_POST['reset_course_pretest'])) {
                $pdo->prepare("DELETE FROM reading_attempts WHERE user_id = ? AND phase = 'Course-Pre-Test'")->execute([$target_id]);
                $actions_taken[] = "Course Pre-Test reset";
            }
            if (!empty($_POST['reset_course_posttest'])) {
                $pdo->prepare("DELETE FROM reading_attempts WHERE user_id = ? AND phase = 'Course-Post-Test'")->execute([$target_id]);
                $actions_taken[] = "Course Post-Test reset";
            }

            // 5. Manual Oral Reading Level Override (applied last so explicit override wins)
            $oral_override = trim($_POST['oral_reading_level_override'] ?? 'keep');
            if ($oral_override !== 'keep' && $oral_override !== '') {
                apply_manual_oral_reading_level($pdo, $target_id, $oral_override, $grade_num);
                $actions_taken[] = "Oral Reading Level set to $oral_override";
            }

            $pdo->commit();
            $flash_success = implode(" • ", $actions_taken) . " for " . htmlspecialchars("$fname $lname") . ".";
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $flash_error = $e->getMessage();
    }
}

// Fetch all users with GST, Reading Profile, Cascading Attempts, and Course Pre/Post counts
$stmtUsers = $pdo->query("
    SELECT 
        u.*,
        g.score AS gst_score,
        g.total_items AS gst_total,
        g.percentage AS gst_pct,
        g.starting_grade AS gst_starting_grade,
        g.needs_individual_assessment,
        g.gst_category,
        rp.independent_grade,
        rp.instructional_grade,
        rp.frustration_grade
    FROM users u
    LEFT JOIN gst_results g ON g.id = (SELECT id FROM gst_results WHERE user_id = u.id ORDER BY completed_at DESC, id DESC LIMIT 1)
    LEFT JOIN reading_profiles rp ON rp.user_id = u.id
    ORDER BY FIELD(u.role, 'teacher', 'student'), u.lname ASC, u.fname ASC
");
$all_users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

// Fetch all reading_attempts grouped by user_id
$stmtAtt = $pdo->query("
    SELECT id, user_id, passage_grade, phase, accuracy_score, comprehension_score, oral_reading_profile, created_at
    FROM reading_attempts
    ORDER BY passage_grade ASC, created_at DESC
");
$attempts_by_user = [];
while ($row = $stmtAtt->fetch(PDO::FETCH_ASSOC)) {
    $uid = (int)$row['user_id'];
    if (!isset($attempts_by_user[$uid])) {
        $attempts_by_user[$uid] = [
            'cascading' => [],
            'course_pre' => [],
            'course_post' => []
        ];
    }
    if ($row['phase'] === 'Pre-Test' && $row['passage_grade'] !== null) {
        $g = (int)$row['passage_grade'];
        // Keep latest attempt per grade for display summary
        if (!isset($attempts_by_user[$uid]['cascading'][$g])) {
            $attempts_by_user[$uid]['cascading'][$g] = $row;
        }
    } elseif ($row['phase'] === 'Course-Pre-Test') {
        $attempts_by_user[$uid]['course_pre'][] = $row;
    } elseif ($row['phase'] === 'Course-Post-Test') {
        $attempts_by_user[$uid]['course_post'][] = $row;
    }
}

$total_users_count = count($all_users);
$total_students_count = 0;
$total_teachers_count = 0;
$all_sections = [];

foreach ($all_users as &$u) {
    $uid = (int)$u['id'];
    $u['CascadingAttempts'] = isset($attempts_by_user[$uid]['cascading']) ? array_values($attempts_by_user[$uid]['cascading']) : [];
    $u['CoursePreCount'] = isset($attempts_by_user[$uid]['course_pre']) ? count($attempts_by_user[$uid]['course_pre']) : 0;
    $u['CoursePostCount'] = isset($attempts_by_user[$uid]['course_post']) ? count($attempts_by_user[$uid]['course_post']) : 0;
    $u['LatestCoursePre'] = $u['CoursePreCount'] > 0 ? $attempts_by_user[$uid]['course_pre'][0] : null;
    $u['LatestCoursePost'] = $u['CoursePostCount'] > 0 ? $attempts_by_user[$uid]['course_post'][0] : null;

    if ($u['role'] === 'teacher') {
        $total_teachers_count++;
        $u['computed_profile'] = 'N/A';
    } else {
        $total_students_count++;
        $enrolled_grade = (int)preg_replace('/[^0-9]/', '', $u['grade_level'] ?? '');
        if ($enrolled_grade <= 0) $enrolled_grade = 7;

        $final_status = 'Pending';
        if (isset($u['needs_individual_assessment']) && (int)$u['needs_individual_assessment'] === 0) {
            $final_status = 'Independent';
        } elseif ($u['independent_grade'] !== null || $u['instructional_grade'] !== null || $u['frustration_grade'] !== null) {
            if ($u['independent_grade'] !== null && $enrolled_grade <= (int)$u['independent_grade']) {
                $final_status = 'Independent';
            } elseif ($u['instructional_grade'] !== null && $enrolled_grade <= (int)$u['instructional_grade']) {
                $final_status = 'Instructional';
            } else {
                if ($u['frustration_grade'] !== null && (int)$u['frustration_grade'] <= 4 && $u['instructional_grade'] === null && $u['independent_grade'] === null) {
                    $final_status = 'Non-Reader';
                } else {
                    $final_status = 'Frustration';
                }
            }
        } elseif (!empty($u['current_level']) && !in_array($u['current_level'], ['Pending', 'Completed Profile'], true)) {
            $final_status = $u['current_level'];
        }
        $u['computed_profile'] = $final_status;

        $clean_sec = preg_replace('/^Grade\s+\d+\s+/i', '', trim((string)($u['section'] ?? '')));
        if ($clean_sec !== '' && !in_array($clean_sec, $all_sections, true)) {
            $all_sections[] = $clean_sec;
        }
    }
}
unset($u);
sort($all_sections);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="https://tibungcodistrict.wordpress.com/wp-content/uploads/2018/06/untitled-1.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User List & Management - Phil-IRI</title>
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
        .card { background: white; border-radius: 0.75rem; box-shadow: 0 1px 3px rgba(0,0,0,0.08); border: 1px solid #E2E8F0; }
        <?php $is_dark = isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark'; ?>
        <?php if($is_dark): ?>
        body { background-color: #0f172a !important; color: #f8fafc !important; }
        .bg-white, .bg-slate-50 { background-color: #1e293b !important; border-color: #334155 !important; color: #f8fafc !important; }
        .text-slate-800, .text-slate-700 { color: #f1f5f9 !important; }
        .text-slate-600, .text-slate-500, .text-slate-400 { color: #cbd5e1 !important; }
        .border-slate-200, .border-slate-100, .border-b, .border-l, .border-t { border-color: #334155 !important; }
        .border-slate-300 { border-color: #475569 !important; }
        .sidebar { background-color: #0b1120 !important; border-right: 1px solid #1e293b !important; }
        input, select, textarea { background-color: #0f172a !important; color: white !important; border-color: #475569 !important; }
        .bg-slate-100, .bg-slate-200 { background-color: #334155 !important; color: #e2e8f0 !important; }
        .hover\:bg-slate-50:hover, tr:hover { background-color: #334155 !important; }
        .card { background-color: #1e293b !important; border-color: #334155 !important; }
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
</head>
<body class="flex h-screen overflow-hidden text-slate-800">
    <?php include 'teacher_sidebar.php'; ?>

    <div class="flex-1 flex flex-col h-screen overflow-hidden">
        <!-- Header -->
        <header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-4 md:px-8 shrink-0 gap-2">
            <div class="flex items-center min-w-0">
                <button onclick="toggleSidebar()" class="md:hidden mr-3 text-slate-500 hover:text-blue-600 focus:outline-none shrink-0">
                    <i class="fas fa-bars text-xl"></i>
                </button>
                <div class="flex items-center gap-2.5 min-w-0">
                    <div class="w-9 h-9 rounded-lg bg-blue-100 text-blue-600 flex items-center justify-center font-bold text-sm shrink-0">
                        <i class="fas fa-users-cog"></i>
                    </div>
                    <div class="min-w-0">
                        <h1 class="font-bold text-base md:text-lg text-slate-800 leading-tight truncate">User List &amp; Account Management</h1>
                        <p class="text-[11px] text-slate-500 truncate hidden sm:block">Add, edit, remove, promote users, and manage student GST, Cascading, &amp; Course tests</p>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <button type="button" onclick="openAddUserModal()" class="inline-flex items-center gap-1.5 px-3 sm:px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs sm:text-sm font-bold shadow-sm transition">
                    <i class="fas fa-user-plus"></i>
                    <span>Add User / Student</span>
                </button>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-4 md:p-6 bg-slate-50">
            <div class="max-w-7xl mx-auto space-y-5">

                <?php if ($flash_success): ?>
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-xl text-sm flex items-center justify-between shadow-xs">
                    <div class="flex items-center gap-2.5">
                        <i class="fas fa-check-circle text-emerald-600 text-base"></i>
                        <span class="font-semibold"><?php echo $flash_success; ?></span>
                    </div>
                    <button type="button" onclick="this.parentElement.remove()" class="text-emerald-600 hover:text-emerald-800 font-bold text-lg leading-none">&times;</button>
                </div>
                <?php endif; ?>

                <?php if ($flash_error): ?>
                <div class="bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 rounded-xl text-sm flex items-center justify-between shadow-xs">
                    <div class="flex items-center gap-2.5">
                        <i class="fas fa-exclamation-circle text-rose-600 text-base"></i>
                        <span class="font-semibold"><?php echo htmlspecialchars($flash_error); ?></span>
                    </div>
                    <button type="button" onclick="this.parentElement.remove()" class="text-rose-600 hover:text-rose-800 font-bold text-lg leading-none">&times;</button>
                </div>
                <?php endif; ?>

                <!-- Summary Cards -->
                <div class="grid grid-cols-3 gap-3 md:gap-4">
                    <div class="card p-3.5 md:p-4 border-l-4 border-l-blue-500">
                        <p class="text-xs font-semibold text-blue-600 mb-0.5">Total Users</p>
                        <h3 class="text-xl md:text-2xl font-bold text-slate-800"><?php echo $total_users_count; ?></h3>
                    </div>
                    <div class="card p-3.5 md:p-4 border-l-4 border-l-emerald-500">
                        <p class="text-xs font-semibold text-emerald-600 mb-0.5">Students</p>
                        <h3 class="text-xl md:text-2xl font-bold text-slate-800"><?php echo $total_students_count; ?></h3>
                    </div>
                    <div class="card p-3.5 md:p-4 border-l-4 border-l-purple-500">
                        <p class="text-xs font-semibold text-purple-600 mb-0.5">Teachers / Admins</p>
                        <h3 class="text-xl md:text-2xl font-bold text-slate-800"><?php echo $total_teachers_count; ?></h3>
                    </div>
                </div>

                <!-- User List Table Card -->
                <div class="card p-4 md:p-6">
                    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3 border-b border-slate-200 pb-4 mb-4">
                        <div>
                            <h2 class="text-base font-bold text-slate-800">All Registered Users</h2>
                            <p class="text-xs text-slate-500">Manage student profiles, assessment resets, cascading reading levels, and teacher/admin privileges.</p>
                        </div>

                        <!-- Filters -->
                        <div class="grid grid-cols-2 sm:flex sm:flex-wrap items-center gap-2 w-full lg:w-auto">
                            <select id="userRoleFilter" onchange="applyUserListFilters()" class="border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs font-medium bg-white focus:outline-none focus:border-blue-500">
                                <option value="">All Roles</option>
                                <option value="student">Students</option>
                                <option value="teacher">Teachers / Admins</option>
                            </select>

                            <select id="userGradeFilter" onchange="applyUserListFilters()" class="border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs font-medium bg-white focus:outline-none focus:border-blue-500">
                                <option value="">All Grades</option>
                                <option value="Grade 7">Grade 7</option>
                                <option value="Grade 8">Grade 8</option>
                                <option value="Grade 9">Grade 9</option>
                                <option value="Grade 10">Grade 10</option>
                            </select>

                            <select id="userSectionFilter" onchange="applyUserListFilters()" class="border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs font-medium bg-white focus:outline-none focus:border-blue-500">
                                <option value="">All Sections</option>
                                <?php foreach ($all_sections as $sec): ?>
                                    <option value="<?php echo htmlspecialchars($sec); ?>"><?php echo htmlspecialchars($sec); ?></option>
                                <?php endforeach; ?>
                            </select>

                            <select id="userProfileFilter" onchange="applyUserListFilters()" class="border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs font-medium bg-white focus:outline-none focus:border-blue-500">
                                <option value="">All Reading Levels</option>
                                <option value="Independent">Independent</option>
                                <option value="Instructional">Instructional</option>
                                <option value="Frustration">Frustration</option>
                                <option value="Non-Reader">Non-Reader</option>
                                <option value="Pending">Pending</option>
                            </select>

                            <input type="text" id="userSearchInput" onkeyup="applyUserListFilters()" placeholder="Search name, LRN, username..." class="col-span-2 sm:col-span-1 border border-slate-300 rounded-lg px-3 py-1.5 text-xs focus:outline-none focus:border-blue-500 w-full sm:w-52 bg-white">
                        </div>
                    </div>

                    <div class="overflow-x-visible md:overflow-x-auto">
                        <table id="userListTable" class="mobile-card-table w-full text-sm text-left border-collapse">
                            <thead class="text-xs text-slate-500 bg-slate-50 border-y border-slate-200 uppercase tracking-wider">
                                <tr>
                                    <th class="py-3 px-3 font-semibold">User</th>
                                    <th class="py-3 px-3 font-semibold">Role</th>
                                    <th class="py-3 px-3 font-semibold">Gender &amp; Class</th>
                                    <th class="py-3 px-3 font-semibold">GST &amp; Oral Level</th>
                                    <th class="py-3 px-3 font-semibold">Cascading &amp; Courses</th>
                                    <th class="py-3 px-3 font-semibold text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($all_users as $u):
                                    $uid = (int)$u['id'];
                                    $is_self = ($uid === $current_teacher_id);
                                    $mi = !empty($u['mname']) ? strtoupper(substr($u['mname'], 0, 1)) . '.' : '';
                                    $full_name = trim($u['fname'] . ' ' . $mi . ' ' . $u['lname']);
                                    $clean_sec = preg_replace('/^Grade\s+\d+\s+/i', '', trim((string)($u['section'] ?? '')));
                                    $gender = !empty($u['gender']) ? $u['gender'] : 'N/A';
                                    $prof = $u['computed_profile'];

                                    $prof_badge = 'bg-slate-100 text-slate-600';
                                    if ($prof === 'Independent') $prof_badge = 'bg-emerald-100 text-emerald-800';
                                    elseif ($prof === 'Instructional') $prof_badge = 'bg-amber-100 text-amber-800';
                                    elseif ($prof === 'Frustration') $prof_badge = 'bg-rose-100 text-rose-800';
                                    elseif ($prof === 'Non-Reader') $prof_badge = 'bg-slate-700 text-white';

                                    $avatar_path = "uploads/profiles/user_" . $uid . ".jpg";
                                    $avatar_url = file_exists($avatar_path) ? $avatar_path . "?v=" . filemtime($avatar_path) : null;

                                    $user_json = htmlspecialchars(json_encode([
                                        'id' => $uid,
                                        'fname' => $u['fname'] ?? '',
                                        'mname' => $u['mname'] ?? '',
                                        'lname' => $u['lname'] ?? '',
                                        'gender' => $u['gender'] ?? 'Male',
                                        'role' => $u['role'] ?? 'student',
                                        'grade_level' => $u['grade_level'] ?? 'Grade 7',
                                        'section' => $clean_sec,
                                        'lrn' => $u['lrn'] ?? '',
                                        'username' => $u['username'] ?? '',
                                        'email' => $u['email'] ?? '',
                                        'computed_profile' => $prof,
                                        'gst_score' => $u['gst_score'],
                                        'gst_total' => $u['gst_total'] ?? 40,
                                        'gst_starting_grade' => $u['gst_starting_grade'],
                                        'cascading_attempts' => $u['CascadingAttempts'],
                                        'course_pre_count' => $u['CoursePreCount'],
                                        'course_post_count' => $u['CoursePostCount'],
                                        'latest_course_pre' => $u['LatestCoursePre'],
                                        'latest_course_post' => $u['LatestCoursePost'],
                                        'is_self' => $is_self
                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
                                ?>
                                <tr class="hover:bg-slate-50 transition"
                                    data-role="<?php echo htmlspecialchars($u['role'] ?? ''); ?>"
                                    data-grade="<?php echo htmlspecialchars($u['grade_level'] ?? ''); ?>"
                                    data-section="<?php echo htmlspecialchars($clean_sec); ?>"
                                    data-profile="<?php echo htmlspecialchars($prof); ?>">

                                    <!-- User -->
                                    <td class="py-3 px-3 font-medium text-slate-800" data-label="User">
                                        <div class="flex items-center gap-2.5">
                                            <?php if ($avatar_url): ?>
                                                <img src="<?php echo htmlspecialchars($avatar_url); ?>" alt="<?php echo htmlspecialchars($full_name); ?>" class="w-9 h-9 rounded-full object-cover border border-slate-200 shrink-0">
                                            <?php else: ?>
                                                <div class="w-9 h-9 rounded-full <?php echo $u['role'] === 'teacher' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-600'; ?> flex items-center justify-center font-bold text-xs shrink-0">
                                                    <?php echo strtoupper(substr($u['fname'] ?? 'U', 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="min-w-0">
                                                <div class="font-bold text-slate-800 text-xs sm:text-sm flex items-center gap-1.5 flex-wrap">
                                                    <span><?php echo htmlspecialchars($full_name); ?></span>
                                                    <?php if ($is_self): ?>
                                                        <span class="px-1.5 py-0.5 text-[10px] rounded bg-blue-50 text-blue-600 font-semibold border border-blue-200">You</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-[11px] text-slate-400">
                                                    @<?php echo htmlspecialchars($u['username'] ?: 'no-username'); ?>
                                                    <?php if ($u['role'] === 'student' && !empty($u['lrn']) && $u['lrn'] !== 'Not Assigned'): ?>
                                                        &bull; <span class="font-mono">LRN: <?php echo htmlspecialchars($u['lrn']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Role -->
                                    <td class="py-3 px-3" data-label="Role">
                                        <?php if ($u['role'] === 'teacher'): ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-purple-100 text-purple-800">
                                                <i class="fas fa-user-shield text-[10px]"></i> Teacher / Admin
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-50 text-blue-700">
                                                <i class="fas fa-user-graduate text-[10px]"></i> Student
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Gender & Class -->
                                    <td class="py-3 px-3 text-xs" data-label="Gender & Class">
                                        <div>
                                            <?php if ($gender === 'Male'): ?>
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 font-semibold"><i class="fas fa-mars text-[10px]"></i> Male</span>
                                            <?php elseif ($gender === 'Female'): ?>
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-pink-50 text-pink-700 font-semibold"><i class="fas fa-venus text-[10px]"></i> Female</span>
                                            <?php else: ?>
                                                <span class="text-slate-400">N/A</span>
                                            <?php endif; ?>

                                            <?php if ($u['role'] === 'student'): ?>
                                                <div class="text-slate-600 font-medium mt-1">
                                                    <?php echo htmlspecialchars(($u['grade_level'] ?: 'N/A') . ($clean_sec ? ' - ' . $clean_sec : '')); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <!-- GST & Oral Level -->
                                    <td class="py-3 px-3 text-xs" data-label="GST & Oral Level">
                                        <?php if ($u['role'] === 'student'): ?>
                                            <div>
                                                <div class="mb-1">
                                                    <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold <?php echo $prof_badge; ?>">
                                                        <?php echo htmlspecialchars($prof); ?>
                                                    </span>
                                                </div>
                                                <div class="text-[11px] text-slate-500">
                                                    <?php if ($u['gst_score'] !== null): ?>
                                                        GST: <strong class="text-slate-700"><?php echo (int)$u['gst_score'] . '/' . (int)$u['gst_total']; ?></strong>
                                                        <?php if ($u['gst_starting_grade']): ?>
                                                            <span class="text-blue-600 font-semibold">(Start Gr <?php echo (int)$u['gst_starting_grade']; ?>)</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        GST: <span class="text-slate-400">Not Taken</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-slate-400 text-xs">—</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Cascading & Courses -->
                                    <td class="py-3 px-3 text-xs" data-label="Cascading & Courses">
                                        <?php if ($u['role'] === 'student'): ?>
                                            <div class="space-y-1">
                                                <div class="flex flex-wrap gap-1 justify-end md:justify-start">
                                                    <?php if (!empty($u['CascadingAttempts'])): ?>
                                                        <?php foreach ($u['CascadingAttempts'] as $ca): ?>
                                                            <span class="px-1.5 py-0.5 rounded bg-slate-100 text-slate-700 font-semibold text-[10px] border border-slate-200" title="<?php echo htmlspecialchars($ca['oral_reading_profile'] . ' (' . number_format($ca['accuracy_score'], 1) . '% Acc)'); ?>">
                                                                Gr <?php echo (int)$ca['passage_grade']; ?>: <?php echo htmlspecialchars(substr($ca['oral_reading_profile'], 0, 3)); ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <span class="text-[11px] text-slate-400">No cascading passages</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-[11px] text-slate-500">
                                                    Pre-Test: <strong class="<?php echo $u['CoursePreCount'] > 0 ? 'text-emerald-600' : 'text-slate-400'; ?>"><?php echo $u['CoursePreCount'] > 0 ? 'Taken' : 'None'; ?></strong>
                                                    &bull;
                                                    Post-Test: <strong class="<?php echo $u['CoursePostCount'] > 0 ? 'text-emerald-600' : 'text-slate-400'; ?>"><?php echo $u['CoursePostCount'] > 0 ? 'Taken' : 'None'; ?></strong>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-slate-400 text-xs">Admin / Teacher Account</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Actions -->
                                    <td class="py-3 px-3 text-right whitespace-nowrap" data-label="Actions">
                                        <div class="inline-flex flex-wrap items-center justify-end gap-1.5">
                                            <button type="button" onclick='openEditUserModal(<?php echo $user_json; ?>)' class="px-2.5 py-1.5 bg-blue-50 hover:bg-blue-100 text-blue-600 rounded-lg text-xs font-semibold transition inline-flex items-center gap-1" title="Edit Profile & Assessment Controls">
                                                <i class="fas fa-sliders-h"></i> Edit
                                            </button>

                                            <?php if (!$is_self): ?>
                                                <form method="POST" class="inline" onsubmit="return confirm('<?php echo $u['role'] === 'student' ? 'Promote this user to Teacher / Admin?' : 'Change this Teacher back to a Student account?'; ?>');">
                                                    <input type="hidden" name="form_action" value="toggle_role">
                                                    <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
                                                    <input type="hidden" name="new_role" value="<?php echo $u['role'] === 'student' ? 'teacher' : 'student'; ?>">
                                                    <?php if ($u['role'] === 'student'): ?>
                                                        <button type="submit" class="px-2.5 py-1.5 bg-purple-50 hover:bg-purple-100 text-purple-700 rounded-lg text-xs font-semibold transition inline-flex items-center gap-1" title="Promote to Teacher / Admin">
                                                            <i class="fas fa-user-shield"></i> Promote
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="submit" class="px-2.5 py-1.5 bg-amber-50 hover:bg-amber-100 text-amber-700 rounded-lg text-xs font-semibold transition inline-flex items-center gap-1" title="Set role to Student">
                                                            <i class="fas fa-user-graduate"></i> Make Student
                                                        </button>
                                                    <?php endif; ?>
                                                </form>

                                                <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to permanently remove <?php echo htmlspecialchars(addslashes($full_name)); ?> and all their assessment records?');">
                                                    <input type="hidden" name="form_action" value="delete_user">
                                                    <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
                                                    <button type="submit" class="px-2.5 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-600 rounded-lg text-xs font-semibold transition inline-flex items-center gap-1" title="Delete User">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- ========================================== -->
    <!-- ADD USER / STUDENT MODAL                   -->
    <!-- ========================================== -->
    <div id="addUserModal" class="fixed inset-0 bg-slate-900/50 backdrop-blur-xs z-50 hidden flex items-center justify-center p-3 sm:p-4">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl max-h-[92vh] overflow-y-auto border border-slate-200">
            <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between sticky top-0 bg-white z-10">
                <h3 class="font-bold text-base sm:text-lg text-slate-800 flex items-center gap-2">
                    <i class="fas fa-user-plus text-blue-600"></i> Add New Student / User
                </h3>
                <button type="button" onclick="closeAddUserModal()" class="text-slate-400 hover:text-slate-600 text-xl font-bold leading-none">&times;</button>
            </div>

            <form method="POST" class="p-5 space-y-5">
                <input type="hidden" name="form_action" value="add_user">

                <!-- Account Role -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 bg-slate-50 p-3.5 rounded-xl border border-slate-200">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Account Role</label>
                        <select name="role" id="addRoleSelect" onchange="toggleAddStudentFields()" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm font-semibold bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="student">Student</option>
                            <option value="teacher">Teacher / Admin</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Gender <span class="text-rose-500">*</span></label>
                        <select name="gender" required class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm font-semibold bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                </div>

                <!-- Name Fields -->
                <div>
                    <h4 class="text-xs font-bold uppercase tracking-wider text-blue-600 mb-2.5 flex items-center gap-1.5">
                        <i class="fas fa-id-card"></i> Full Name
                    </h4>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">First Name <span class="text-rose-500">*</span></label>
                            <input type="text" name="fname" required placeholder="e.g. Juan" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Middle Name</label>
                            <input type="text" name="mname" placeholder="e.g. Santos" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Surname <span class="text-rose-500">*</span></label>
                            <input type="text" name="lname" required placeholder="e.g. Dela Cruz" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                </div>

                <!-- Student Class & Optional Assessment Setup -->
                <div id="addStudentSection" class="space-y-5">
                    <div>
                        <h4 class="text-xs font-bold uppercase tracking-wider text-blue-600 mb-2.5 flex items-center gap-1.5">
                            <i class="fas fa-graduation-cap"></i> Grade Level, Section &amp; LRN
                        </h4>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1">Grade Level <span class="text-rose-500">*</span></label>
                                <select name="grade_level" id="addGradeSelect" onchange="populateSectionDropdown('addGradeSelect', 'addSectionSelect')" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 font-medium">
                                    <option value="Grade 7">Grade 7</option>
                                    <option value="Grade 8">Grade 8</option>
                                    <option value="Grade 9">Grade 9</option>
                                    <option value="Grade 10">Grade 10</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1">Section <span class="text-rose-500">*</span></label>
                                <select name="section" id="addSectionSelect" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 font-medium"></select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1">LRN (Optional)</label>
                                <div class="flex items-center border border-slate-300 rounded-lg overflow-hidden bg-white focus-within:ring-2 focus-within:ring-blue-500">
                                    <span class="px-2.5 py-2 bg-slate-100 text-slate-700 font-mono font-bold text-xs border-r border-slate-300 select-none">301942</span>
                                    <input type="text" name="lrn_suffix" maxlength="6" inputmode="numeric" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,6)" placeholder="6 digits (or auto)" class="w-full px-2.5 py-2 text-xs font-mono focus:outline-none">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="p-4 rounded-xl bg-blue-50/50 border border-blue-200 space-y-3">
                        <div class="flex items-center justify-between">
                            <h4 class="text-xs font-bold uppercase tracking-wider text-blue-800 flex items-center gap-1.5">
                                <i class="fas fa-clipboard-check text-blue-600"></i> Optional Initial Assessment Data
                            </h4>
                            <span class="text-[11px] text-blue-600 font-medium">Optional</span>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1">GST Score (Optional)</label>
                                <div class="flex items-center gap-1.5">
                                    <input type="number" name="gst_score" min="0" max="100" placeholder="Score" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <span class="text-xs text-slate-400 font-bold">/</span>
                                    <input type="number" name="gst_total" min="1" max="100" value="40" class="w-16 border border-slate-300 rounded-lg px-2 py-2 text-sm text-center bg-white focus:outline-none">
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1">Oral Reading Level (Optional)</label>
                                <select name="oral_reading_level" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="">None (Pending Assessment)</option>
                                    <option value="Independent">Independent</option>
                                    <option value="Instructional">Instructional</option>
                                    <option value="Frustration">Frustration</option>
                                    <option value="Non-Reader">Non-Reader</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1">Cascading Start Grade</label>
                                <select name="starting_grade" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="">Auto from GST</option>
                                    <option value="4">Start at Grade 4</option>
                                    <option value="5">Start at Grade 5</option>
                                    <option value="6">Start at Grade 6</option>
                                    <option value="7">Start at Grade 7</option>
                                    <option value="8">Start at Grade 8</option>
                                    <option value="9">Start at Grade 9</option>
                                    <option value="10">Start at Grade 10</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Login Credentials -->
                <div>
                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2.5 flex items-center gap-1.5">
                        <i class="fas fa-key"></i> Login Credentials (Optional — Auto-Generated if Blank)
                    </h4>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Username</label>
                            <input type="text" name="username" placeholder="Auto from name if blank" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Email</label>
                            <input type="email" name="email" placeholder="Auto if blank" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Password (Default: 123456)</label>
                            <input type="text" name="password" value="123456" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-2.5 pt-3 border-t border-slate-200">
                    <button type="button" onclick="closeAddUserModal()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-sm font-semibold transition">Cancel</button>
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-bold shadow-sm transition">
                        <i class="fas fa-check mr-1.5"></i> Create User
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- EDIT USER & ASSESSMENT CONTROLS MODAL      -->
    <!-- ========================================== -->
    <div id="editUserModal" class="fixed inset-0 bg-slate-900/50 backdrop-blur-xs z-50 hidden flex items-center justify-center p-3 sm:p-4">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-3xl max-h-[92vh] overflow-y-auto border border-slate-200">
            <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between sticky top-0 bg-white z-10">
                <h3 class="font-bold text-base sm:text-lg text-slate-800 flex items-center gap-2">
                    <i class="fas fa-user-edit text-blue-600"></i>
                    <span>Edit User &amp; Assessment Controls: <span id="editModalUserName" class="text-blue-600"></span></span>
                </h3>
                <button type="button" onclick="closeEditUserModal()" class="text-slate-400 hover:text-slate-600 text-xl font-bold leading-none">&times;</button>
            </div>

            <form method="POST" class="p-5 space-y-5">
                <input type="hidden" name="form_action" value="edit_user">
                <input type="hidden" name="user_id" id="editUserId">

                <!-- 1. Profile Details -->
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-3">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-blue-600 flex items-center gap-1.5">
                        <i class="fas fa-id-card"></i> 1. User Profile &amp; Role
                    </h4>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">First Name <span class="text-rose-500">*</span></label>
                            <input type="text" name="fname" id="editFname" required class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Middle Name</label>
                            <input type="text" name="mname" id="editMname" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Surname <span class="text-rose-500">*</span></label>
                            <input type="text" name="lname" id="editLname" required class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Role</label>
                            <select name="role" id="editRole" onchange="toggleEditStudentSections()" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm font-semibold bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="student">Student</option>
                                <option value="teacher">Teacher / Admin</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Gender</label>
                            <select name="gender" id="editGender" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                        <div id="editGradeWrap">
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Grade Level</label>
                            <select name="grade_level" id="editGradeLevel" onchange="populateSectionDropdown('editGradeLevel', 'editSection')" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="Grade 7">Grade 7</option>
                                <option value="Grade 8">Grade 8</option>
                                <option value="Grade 9">Grade 9</option>
                                <option value="Grade 10">Grade 10</option>
                            </select>
                        </div>
                        <div id="editSectionWrap">
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Section</label>
                            <select name="section" id="editSection" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500"></select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                        <div id="editLrnWrap">
                            <label class="block text-xs font-semibold text-slate-700 mb-1">LRN</label>
                            <input type="text" name="lrn" id="editLrn" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-xs font-mono bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Username</label>
                            <input type="text" name="username" id="editUsername" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Email</label>
                            <input type="email" name="email" id="editEmail" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">New Password (Optional)</label>
                            <input type="text" name="new_password" placeholder="Blank = keep current" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                </div>

                <!-- Student-Only Assessment Controls -->
                <div id="editStudentAssessmentControls" class="space-y-4">

                    <!-- 2. Oral Reading Profile & GST Score Controls -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Oral Reading Profile Override -->
                        <div class="p-4 rounded-xl border border-slate-200 bg-white space-y-2.5">
                            <h4 class="text-xs font-bold uppercase tracking-wider text-emerald-700 flex items-center gap-1.5">
                                <i class="fas fa-book-reader"></i> 2. Oral Reading Level Override
                            </h4>
                            <p class="text-xs text-slate-500">Current Level: <strong id="editCurrentOralLevel" class="text-slate-800"></strong></p>
                            <select name="oral_reading_level_override" id="editOralLevelOverride" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white font-medium focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="keep">Keep Current Oral Reading Level</option>
                                <option value="Independent">Set to Independent</option>
                                <option value="Instructional">Set to Instructional</option>
                                <option value="Frustration">Set to Frustration</option>
                                <option value="Non-Reader">Set to Non-Reader</option>
                                <option value="Pending">Set to Pending (Unassessed)</option>
                            </select>
                        </div>

                        <!-- GST Score Controls -->
                        <div class="p-4 rounded-xl border border-slate-200 bg-white space-y-2.5">
                            <h4 class="text-xs font-bold uppercase tracking-wider text-blue-700 flex items-center gap-1.5">
                                <i class="fas fa-file-alt"></i> 3. GST Score Management
                            </h4>
                            <p class="text-xs text-slate-500">Current GST: <strong id="editCurrentGstStatus" class="text-slate-800"></strong></p>
                            <select name="gst_action" id="editGstAction" onchange="toggleEditGstInputs()" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white font-medium focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="keep">Keep Current GST Score</option>
                                <option value="set">Set / Update GST Score Manually</option>
                                <option value="reset">Reset GST Score (Student Retakes GST)</option>
                            </select>
                            <div id="editGstScoreWrap" class="hidden pt-2 flex items-center gap-2">
                                <input type="number" name="edit_gst_score" id="editGstScoreInput" min="0" max="100" placeholder="Score" class="w-24 border border-slate-300 rounded-lg px-3 py-1.5 text-sm bg-white">
                                <span class="text-xs font-bold text-slate-400">/</span>
                                <input type="number" name="edit_gst_total" id="editGstTotalInput" min="1" max="100" value="40" class="w-20 border border-slate-300 rounded-lg px-2 py-1.5 text-sm text-center bg-white">
                                <span class="text-xs text-slate-500">items</span>
                            </div>
                        </div>
                    </div>

                    <!-- 4. Cascading System & Specific Grade Level Controls -->
                    <div class="p-4 rounded-xl border border-amber-200 bg-amber-50/40 space-y-3">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-amber-800 flex items-center gap-1.5">
                            <i class="fas fa-sitemap text-amber-600"></i> 4. Cascading Reading System Controls
                        </h4>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1">Cascading Action</label>
                                <select name="cascade_action" id="editCascadeAction" onchange="toggleEditCascadeInputs()" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white font-medium focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="keep">Keep Current Cascading Progress</option>
                                    <option value="start_grade">Start / Jump to Specific Grade Reading Level</option>
                                    <option value="reset_all">Reset Entire Cascading System (Clear All Passages)</option>
                                </select>
                            </div>

                            <div id="editCascadeGradeWrap" class="hidden space-y-2">
                                <label class="block text-xs font-semibold text-slate-700 mb-1">Select Grade Reading Level to Start</label>
                                <select name="cascade_start_grade" id="editCascadeStartGrade" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white font-bold text-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="4">Grade 4 Passage</option>
                                    <option value="5">Grade 5 Passage</option>
                                    <option value="6">Grade 6 Passage</option>
                                    <option value="7">Grade 7 Passage</option>
                                    <option value="8">Grade 8 Passage</option>
                                    <option value="9">Grade 9 Passage</option>
                                    <option value="10">Grade 10 Passage</option>
                                </select>
                                <label class="inline-flex items-center gap-2 text-xs text-slate-700 cursor-pointer">
                                    <input type="checkbox" name="cascade_clear_existing" value="1" checked class="rounded border-slate-300 text-blue-600">
                                    <span>Also clear previous cascading passage attempts</span>
                                </label>
                            </div>
                        </div>

                        <!-- Specific Grade Reading Levels Taken by Student -->
                        <div class="pt-2 border-t border-amber-200/80">
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">
                                Remove Specific Grade Reading Level(s) Taken by Student:
                            </label>
                            <div id="editCascadingGradesList" class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                <!-- Populated via JS -->
                            </div>
                        </div>
                    </div>

                    <!-- 5. Reset Course Pre-Test & Post-Test -->
                    <div class="p-4 rounded-xl border border-rose-200 bg-rose-50/40 space-y-2.5">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-rose-800 flex items-center gap-1.5">
                            <i class="fas fa-redo-alt text-rose-600"></i> 5. Reset Course Pre-Test &amp; Post-Test
                        </h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <label class="flex items-start gap-2.5 p-2.5 rounded-lg bg-white border border-slate-200 cursor-pointer hover:bg-rose-50/50 transition">
                                <input type="checkbox" name="reset_course_pretest" id="resetCoursePreCheck" value="1" class="mt-0.5 rounded border-slate-300 text-rose-600">
                                <div class="text-xs">
                                    <span class="font-bold text-slate-800 block">Reset Course Pre-Test</span>
                                    <span id="editCoursePreInfo" class="text-slate-500"></span>
                                </div>
                            </label>
                            <label class="flex items-start gap-2.5 p-2.5 rounded-lg bg-white border border-slate-200 cursor-pointer hover:bg-rose-50/50 transition">
                                <input type="checkbox" name="reset_course_posttest" id="resetCoursePostCheck" value="1" class="mt-0.5 rounded border-slate-300 text-rose-600">
                                <div class="text-xs">
                                    <span class="font-bold text-slate-800 block">Reset Course Post-Test</span>
                                    <span id="editCoursePostInfo" class="text-slate-500"></span>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-2.5 pt-3 border-t border-slate-200">
                    <button type="button" onclick="closeEditUserModal()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-sm font-semibold transition">Cancel</button>
                    <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-bold shadow-sm transition">
                        <i class="fas fa-save mr-1.5"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const SECTIONS_BY_GRADE = <?php echo json_encode($sections_by_grade, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        function populateSectionDropdown(gradeSelectId, sectionSelectId, selectedSection = '') {
            const gradeEl = document.getElementById(gradeSelectId);
            const secEl = document.getElementById(sectionSelectId);
            if (!gradeEl || !secEl) return;

            const gradeVal = gradeEl.value || 'Grade 7';
            const list = Array.isArray(SECTIONS_BY_GRADE[gradeVal]) ? [...SECTIONS_BY_GRADE[gradeVal]] : [];
            if (selectedSection && !list.includes(selectedSection)) {
                list.push(selectedSection);
            }

            secEl.innerHTML = '';
            if (list.length === 0) {
                const opt = document.createElement('option');
                opt.value = 'Section A';
                opt.textContent = 'Section A';
                secEl.appendChild(opt);
                return;
            }

            list.forEach(sec => {
                const opt = document.createElement('option');
                opt.value = sec;
                opt.textContent = sec;
                if (selectedSection && sec.toLowerCase() === selectedSection.toLowerCase()) {
                    opt.selected = true;
                }
                secEl.appendChild(opt);
            });
        }

        function openAddUserModal() {
            populateSectionDropdown('addGradeSelect', 'addSectionSelect');
            toggleAddStudentFields();
            document.getElementById('addUserModal').classList.remove('hidden');
        }

        function closeAddUserModal() {
            document.getElementById('addUserModal').classList.add('hidden');
        }

        function toggleAddStudentFields() {
            const role = document.getElementById('addRoleSelect').value;
            const studentSec = document.getElementById('addStudentSection');
            if (role === 'teacher') {
                studentSec.classList.add('hidden');
            } else {
                studentSec.classList.remove('hidden');
            }
        }

        function toggleEditStudentSections() {
            const role = document.getElementById('editRole').value;
            const isStudent = role === 'student';
            document.getElementById('editGradeWrap').classList.toggle('hidden', !isStudent);
            document.getElementById('editSectionWrap').classList.toggle('hidden', !isStudent);
            document.getElementById('editLrnWrap').classList.toggle('hidden', !isStudent);
            document.getElementById('editStudentAssessmentControls').classList.toggle('hidden', !isStudent);
        }

        function toggleEditGstInputs() {
            const action = document.getElementById('editGstAction').value;
            document.getElementById('editGstScoreWrap').classList.toggle('hidden', action !== 'set');
        }

        function toggleEditCascadeInputs() {
            const action = document.getElementById('editCascadeAction').value;
            document.getElementById('editCascadeGradeWrap').classList.toggle('hidden', action !== 'start_grade');
        }

        function openEditUserModal(user) {
            document.getElementById('editUserId').value = user.id;
            document.getElementById('editModalUserName').textContent = (user.fname + ' ' + user.lname).trim();
            document.getElementById('editFname').value = user.fname || '';
            document.getElementById('editMname').value = user.mname || '';
            document.getElementById('editLname').value = user.lname || '';
            document.getElementById('editRole').value = user.role || 'student';
            document.getElementById('editGender').value = (user.gender === 'Female') ? 'Female' : 'Male';

            const gradeVal = (user.grade_level && /^Grade\s+(7|8|9|10)$/i.test(user.grade_level)) ? user.grade_level : 'Grade 7';
            document.getElementById('editGradeLevel').value = gradeVal;
            populateSectionDropdown('editGradeLevel', 'editSection', user.section || '');

            document.getElementById('editLrn').value = user.lrn || '';
            document.getElementById('editUsername').value = user.username || '';
            document.getElementById('editEmail').value = user.email || '';

            // Oral Reading Level
            document.getElementById('editCurrentOralLevel').textContent = user.computed_profile || 'Pending';
            document.getElementById('editOralLevelOverride').value = 'keep';

            // GST Status
            const gstStatusEl = document.getElementById('editCurrentGstStatus');
            if (user.gst_score !== null && user.gst_score !== undefined) {
                gstStatusEl.textContent = `${user.gst_score} / ${user.gst_total || 40}` + (user.gst_starting_grade ? ` (Start Grade ${user.gst_starting_grade})` : ' (Exempt)');
                document.getElementById('editGstScoreInput').value = user.gst_score;
                document.getElementById('editGstTotalInput').value = user.gst_total || 40;
            } else {
                gstStatusEl.textContent = 'Not Taken';
                document.getElementById('editGstScoreInput').value = '';
                document.getElementById('editGstTotalInput').value = 40;
            }
            document.getElementById('editGstAction').value = 'keep';
            toggleEditGstInputs();

            // Cascading Controls
            document.getElementById('editCascadeAction').value = 'keep';
            if (user.gst_starting_grade) {
                document.getElementById('editCascadeStartGrade').value = String(user.gst_starting_grade);
            } else {
                document.getElementById('editCascadeStartGrade').value = '7';
            }
            toggleEditCascadeInputs();

            const gradesContainer = document.getElementById('editCascadingGradesList');
            gradesContainer.innerHTML = '';
            const attempts = Array.isArray(user.cascading_attempts) ? user.cascading_attempts : [];
            if (attempts.length === 0) {
                gradesContainer.innerHTML = '<div class="col-span-2 text-xs text-slate-400 italic py-1">No cascading reading passages taken yet.</div>';
            } else {
                attempts.forEach(att => {
                    const g = att.passage_grade;
                    const acc = parseFloat(att.accuracy_score || 0).toFixed(1);
                    const comp = parseFloat(att.comprehension_score || 0).toFixed(1);
                    const prof = att.oral_reading_profile || 'Pending';
                    const label = document.createElement('label');
                    label.className = 'flex items-center justify-between gap-2 p-2.5 rounded-lg bg-white border border-slate-200 cursor-pointer hover:bg-rose-50/60 transition text-xs';
                    label.innerHTML = `
                        <div class="flex items-center gap-2">
                            <input type="checkbox" name="remove_passage_grades[]" value="${g}" class="rounded border-slate-300 text-rose-600">
                            <div>
                                <span class="font-bold text-slate-800">Remove Grade ${g}</span>
                                <span class="block text-[11px] text-slate-500">${prof} &bull; ${acc}% Acc | ${comp}% Comp</span>
                            </div>
                        </div>
                        <span class="px-2 py-0.5 rounded bg-rose-100 text-rose-700 font-semibold text-[10px]">Delete</span>
                    `;
                    gradesContainer.appendChild(label);
                });
            }

            // Course Pre-Test & Post-Test
            document.getElementById('resetCoursePreCheck').checked = false;
            document.getElementById('resetCoursePostCheck').checked = false;
            document.getElementById('editCoursePreInfo').textContent = user.course_pre_count > 0
                ? `Completed (${user.course_pre_count} attempt${user.course_pre_count > 1 ? 's' : ''}) — Check to clear`
                : 'Not taken yet';
            document.getElementById('editCoursePostInfo').textContent = user.course_post_count > 0
                ? `Completed (${user.course_post_count} attempt${user.course_post_count > 1 ? 's' : ''}) — Check to clear`
                : 'Not taken yet';

            toggleEditStudentSections();
            document.getElementById('editUserModal').classList.remove('hidden');
        }

        function closeEditUserModal() {
            document.getElementById('editUserModal').classList.add('hidden');
        }

        function applyUserListFilters() {
            const role = (document.getElementById('userRoleFilter')?.value || '').toLowerCase();
            const grade = (document.getElementById('userGradeFilter')?.value || '').toLowerCase();
            const section = (document.getElementById('userSectionFilter')?.value || '').toLowerCase();
            const profile = (document.getElementById('userProfileFilter')?.value || '').toLowerCase();
            const query = (document.getElementById('userSearchInput')?.value || '').toLowerCase().trim();

            const rows = document.querySelectorAll('#userListTable tbody tr');
            rows.forEach(row => {
                if (row.cells.length <= 1) return;
                const rRole = (row.getAttribute('data-role') || '').toLowerCase();
                const rGrade = (row.getAttribute('data-grade') || '').toLowerCase();
                const rSec = (row.getAttribute('data-section') || '').toLowerCase();
                const rProf = (row.getAttribute('data-profile') || '').toLowerCase();
                const text = row.innerText.toLowerCase();

                const matchRole = !role || rRole === role;
                const matchGrade = !grade || rGrade === grade;
                const matchSec = !section || rSec === section;
                const matchProf = !profile || rProf === profile;
                const matchQ = !query || text.includes(query);

                row.style.display = (matchRole && matchGrade && matchSec && matchProf && matchQ) ? '' : 'none';
            });
        }

        window.toggleSidebar = function() {
            const sidebar = document.getElementById('appSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (sidebar) sidebar.classList.toggle('-translate-x-full');
            if (overlay) overlay.classList.toggle('hidden');
        };
    </script>
</body>
</html>
