<?php
$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'aral_reading';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';

try {
  $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  die("Database connection failed: " . $e->getMessage());
}

if (!function_exists('get_student_reading_level')) {
  function get_student_reading_level($pdo, $user_id, $user_data = null)
  {
    if (!$pdo || !$user_id) return 'Pending';
    if (!$user_data) {
      $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
      $stmt->execute([$user_id]);
      $user_data = $stmt->fetch();
    }
    if (!$user_data) return 'Pending';

    $curr = $user_data['current_level'] ?? '';
    if ($curr === 'Pending') return 'Pending';

    $enrolled_grade = intval(preg_replace('/[^0-9]/', '', $user_data['grade_level'] ?? ''));
    if ($enrolled_grade <= 0) $enrolled_grade = 7;

    // Check GST
    $stmtGST = $pdo->prepare("SELECT needs_individual_assessment FROM gst_results WHERE user_id = ? ORDER BY completed_at DESC LIMIT 1");
    $stmtGST->execute([$user_id]);
    $gst_result = $stmtGST->fetch();

    if (isset($gst_result['needs_individual_assessment']) && (int)$gst_result['needs_individual_assessment'] === 0) {
      return 'Independent';
    }

    // Check Consolidated Reading Profile
    $stmtP = $pdo->prepare("SELECT * FROM reading_profiles WHERE user_id = ?");
    $stmtP->execute([$user_id]);
    $profile = $stmtP->fetch();

    if ($profile && ($profile['independent_grade'] !== null || $profile['instructional_grade'] !== null || $profile['frustration_grade'] !== null)) {
      if ($profile['independent_grade'] !== null && $enrolled_grade <= intval($profile['independent_grade'])) {
        return 'Independent';
      } elseif ($profile['instructional_grade'] !== null && $enrolled_grade <= intval($profile['instructional_grade'])) {
        return 'Instructional';
      } else {
        if ($profile['frustration_grade'] !== null && intval($profile['frustration_grade']) <= 4 && $profile['instructional_grade'] === null && $profile['independent_grade'] === null) {
          return 'Non-Reader';
        } else {
          return 'Frustration';
        }
      }
    }

    if (!empty($curr) && $curr !== 'Completed Profile') {
      return $curr;
    }

    return 'Instructional';
  }
}

if (!function_exists('get_sections_by_grade')) {
  function get_sections_by_grade($pdo)
  {
    $default_sections = [
      'Grade 7' => ['Rizal', 'Luna', 'Bonifacio', 'Del Pilar'],
      'Grade 8' => ['Sapphire', 'Ruby', 'Emerald', 'Bloodstone'],
      'Grade 9' => ['Phoenix', 'Falcon', 'Lionheart', 'Raven'],
      'Grade 10' => ['Crimson', 'Amber', 'Coral', 'Ivory']
    ];

    if (!$pdo) {
      return $default_sections;
    }

    try {
      $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'available_sections'");
      $stmt->execute();
      $row = $stmt->fetch();
      if ($row && !empty(trim($row['setting_value'] ?? ''))) {
        $raw = trim($row['setting_value']);
        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
          $result = [];
          foreach (['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10'] as $g) {
            $short = preg_replace('/[^0-9]/', '', $g);
            $raw_list = $decoded[$g] ?? $decoded[$short] ?? [];
            if (is_array($raw_list)) {
              $clean_list = [];
              foreach ($raw_list as $item) {
                $t = trim((string)$item);
                if ($t !== '' && !in_array($t, $clean_list)) {
                  $clean_list[] = $t;
                }
              }
              $result[$g] = $clean_list;
            } else {
              $result[$g] = [];
            }
          }
          return $result;
        }

        // Legacy comma/newline separated fallback
        $lines = preg_split('/[\r\n,]+/', $raw);
        $result = [
          'Grade 7' => [],
          'Grade 8' => [],
          'Grade 9' => [],
          'Grade 10' => []
        ];
        foreach ($lines as $item) {
          $item = trim($item);
          if ($item === '') continue;

          if (preg_match('/^Grade\s*7\s*[:-]?\s*(.+)$/i', $item, $m)) {
            $clean = trim($m[1]);
            if ($clean !== '' && !in_array($clean, $result['Grade 7'])) $result['Grade 7'][] = $clean;
          } elseif (preg_match('/^Grade\s*8\s*[:-]?\s*(.+)$/i', $item, $m)) {
            $clean = trim($m[1]);
            if ($clean !== '' && !in_array($clean, $result['Grade 8'])) $result['Grade 8'][] = $clean;
          } elseif (preg_match('/^Grade\s*9\s*[:-]?\s*(.+)$/i', $item, $m)) {
            $clean = trim($m[1]);
            if ($clean !== '' && !in_array($clean, $result['Grade 9'])) $result['Grade 9'][] = $clean;
          } elseif (preg_match('/^Grade\s*10\s*[:-]?\s*(.+)$/i', $item, $m)) {
            $clean = trim($m[1]);
            if ($clean !== '' && !in_array($clean, $result['Grade 10'])) $result['Grade 10'][] = $clean;
          } else {
            // Unprefixed legacy section, assign to Grade 7
            if (!in_array($item, $result['Grade 7'])) {
              $result['Grade 7'][] = $item;
            }
          }
        }

        // If any grade ended up completely empty, supply default sections
        foreach (['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10'] as $g) {
          if (empty($result[$g]) && !empty($default_sections[$g])) {
            $result[$g] = $default_sections[$g];
          }
        }

        return $result;
      }
    } catch (Exception $e) {
      // Fallback on database error
    }

    return $default_sections;
  }
}

if (!function_exists('save_sections_by_grade')) {
  function save_sections_by_grade($pdo, $sections_by_grade)
  {
    $clean = [];
    foreach (['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10'] as $g) {
      $short = preg_replace('/[^0-9]/', '', $g);
      $raw_list = $sections_by_grade[$g] ?? $sections_by_grade[$short] ?? [];
      if (!is_array($raw_list)) {
        $raw_list = [];
      }
      $clean_list = [];
      foreach ($raw_list as $item) {
        $t = trim((string)$item);
        if ($t !== '' && !in_array($t, $clean_list)) {
          $clean_list[] = $t;
        }
      }
      $clean[$g] = $clean_list;
    }

    $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('available_sections', :v) ON DUPLICATE KEY UPDATE setting_value = :v2");
    $stmt->execute(['v' => $json, 'v2' => $json]);
    return $clean;
  }
}

if (!function_exists('get_recommendation_rules')) {
  function get_recommendation_rules($pdo)
  {
    if (!$pdo) return [];
    try {
      $stmt = $pdo->query("SELECT * FROM recommendation_rules WHERE is_active = 1 ORDER BY id ASC");
      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
      return [];
    }
  }
}

if (!function_exists('save_student_custom_recommendation')) {
  function save_student_custom_recommendation($pdo, $user_id, $course_group, $text, $tag = null, $teacher_id = null)
  {
    if (!$pdo || empty($user_id) || empty($course_group)) return false;
    $stmt = $pdo->prepare("
            INSERT INTO student_recommendations (user_id, course_group, condition_tag, recommendation_text, teacher_id)
            VALUES (:uid, :grp, :tag, :rec, :tid)
            ON DUPLICATE KEY UPDATE 
                condition_tag = VALUES(condition_tag),
                recommendation_text = VALUES(recommendation_text),
                teacher_id = VALUES(teacher_id),
                updated_at = CURRENT_TIMESTAMP
        ");
    return $stmt->execute([
      'uid' => $user_id,
      'grp' => $course_group,
      'tag' => $tag,
      'rec' => $text,
      'tid' => $teacher_id
    ]);
  }
}

if (!function_exists('delete_student_custom_recommendation')) {
  function delete_student_custom_recommendation($pdo, $user_id, $course_group)
  {
    if (!$pdo || empty($user_id) || empty($course_group)) return false;
    $stmt = $pdo->prepare("DELETE FROM student_recommendations WHERE user_id = ? AND course_group = ?");
    return $stmt->execute([$user_id, $course_group]);
  }
}

if (!function_exists('resolve_student_recommendation')) {
  function resolve_student_recommendation($pdo, $user_id, $course_group, $metrics)
  {
    if (!$pdo) {
      return [
        'is_custom' => false,
        'recommendation' => 'Assessment comparison recorded.',
        'condition_tag' => null
      ];
    }

    $outcome = $metrics['outcome'] ?? 'Pending';
    $postProf = $metrics['post_prof'] ?? '';
    $preProf = $metrics['pre_prof'] ?? '';
    $improvedAreas = $metrics['improved_areas'] ?? '';
    $studentName = $metrics['student_name'] ?? 'The student';

    $accDiffFormatted = isset($metrics['acc_diff']) ? (($metrics['acc_diff'] > 0 ? '+' : '') . number_format($metrics['acc_diff'], 1) . '%') : '';
    $compDiffFormatted = isset($metrics['comp_diff']) ? (($metrics['comp_diff'] > 0 ? '+' : '') . number_format($metrics['comp_diff'], 1) . '%') : '';
    $wpmDiffFormatted = isset($metrics['wpm_diff']) ? (round($metrics['wpm_diff']) . ' WPM') : '';

    // Template replacements mapping (supports both new user-friendly slash tokens and legacy curly tags)
    $replacements = [
      // User-friendly slash shortcuts
      '/student' => htmlspecialchars($studentName),
      '/pre-test' => htmlspecialchars($preProf),
      '/post-test' => htmlspecialchars($postProf),
      '/diff_accuracy' => $accDiffFormatted,
      '/diff_comprehension' => $compDiffFormatted,
      '/improve' => htmlspecialchars($improvedAreas),
      // Legacy / backward-compatible placeholders
      '{student_name}' => htmlspecialchars($studentName),
      '{pre_prof}' => htmlspecialchars($preProf),
      '{post_prof}' => htmlspecialchars($postProf),
      '{improved_areas}' => htmlspecialchars($improvedAreas),
      '{acc_diff}' => $accDiffFormatted,
      '{comp_diff}' => $compDiffFormatted,
      '{wpm_diff}' => $wpmDiffFormatted
    ];

    // 1. Check if a personalized custom recommendation exists for this user and course group
    try {
      $stmt = $pdo->prepare("SELECT * FROM student_recommendations WHERE user_id = ? AND course_group = ?");
      $stmt->execute([$user_id, $course_group]);
      $custom = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($custom && !empty(trim($custom['recommendation_text']))) {
        $customText = str_replace(array_keys($replacements), array_values($replacements), trim($custom['recommendation_text']));
        return [
          'is_custom' => true,
          'recommendation' => $customText,
          'condition_tag' => $custom['condition_tag'] ?? 'Teacher Note',
          'teacher_id' => $custom['teacher_id'],
          'updated_at' => $custom['updated_at']
        ];
      }
    } catch (Exception $e) {
      // Table might not exist yet, continue to rule evaluation
    }

    // 2. Evaluate dynamic conditional rules from recommendation_rules
    $rules = get_recommendation_rules($pdo);
    $matchedTemplate = null;
    $matchedTag = null;

    // First attempt: exact match on outcome AND target_profile
    foreach ($rules as $r) {
      if (strcasecmp($r['outcome'], $outcome) === 0 && !empty($postProf) && strcasecmp($r['target_profile'], $postProf) === 0) {
        $matchedTemplate = $r['recommendation_template'];
        $matchedTag = $r['condition_name'];
        break;
      }
    }

    // Second attempt: match outcome with target_profile = 'Any'
    if (!$matchedTemplate) {
      foreach ($rules as $r) {
        if (strcasecmp($r['outcome'], $outcome) === 0 && strcasecmp($r['target_profile'], 'Any') === 0) {
          // Check if rule requires profile promotion vs metric improvement
          if ($outcome === 'Improved' && isset($metrics['level_promoted']) && !$metrics['level_promoted'] && stripos($r['condition_name'], 'Promotion') !== false) {
            continue;
          }
          $matchedTemplate = $r['recommendation_template'];
          $matchedTag = $r['condition_name'];
          break;
        }
      }
    }

    // 3. Fallback standard message if no rules matched
    if (!$matchedTemplate) {
      if ($outcome === 'Improved') {
        $matchedTemplate = "Congratulations /student! Reading profile showed clear improvement. Keep up the great reading habits!";
      } elseif ($outcome === 'Declined') {
        $matchedTemplate = "/student is currently at /post-test level. Recommended action: 15 minutes of guided oral reading practice daily.";
      } elseif ($outcome === 'Partial Progress') {
        $matchedTemplate = "/student showed positive progress in /improve! Continue regular practice to reach the next reading profile.";
      } elseif ($outcome === 'In Progress') {
        $matchedTemplate = "/student has completed the Pre-Test. Review the module lessons and proceed to the Post-Test when ready!";
      } elseif ($outcome === 'Post-Test Only') {
        $matchedTemplate = "/student completed the Post-Test. Encourage taking the Pre-Test next time to track before-and-after reading growth.";
      } else {
        $matchedTemplate = "/student maintained a stable /pre-test reading profile. Regular guided reading sessions will help build even higher fluency.";
      }
    }

    $finalText = str_replace(array_keys($replacements), array_values($replacements), $matchedTemplate);

    return [
      'is_custom' => false,
      'recommendation' => $finalText,
      'condition_tag' => $matchedTag
    ];
  }
}

if (!function_exists('get_student_course_progress_summary')) {
  function get_student_course_progress_summary($pdo, $user_id)
  {
    if (!$pdo || empty($user_id)) {
      return [
        'has_tests' => false,
        'outcome' => 'No Data',
        'badge_class' => 'bg-slate-100 text-slate-500',
        'latest_profile' => 'Pending',
        'has_custom_rec' => false,
        'condition_tag' => null,
        'recommendation_text' => null,
        'course_title' => null,
        'pre' => null,
        'post' => null,
        'acc_diff' => 0,
        'comp_diff' => 0
      ];
    }

    // Fetch course attempts
    $stmt = $pdo->prepare("
            SELECT a.*, c.title as course_title, c.questions_json
            FROM reading_attempts a
            JOIN course_assessments c ON a.passage_id = c.id
            WHERE a.user_id = ? AND a.phase IN ('Course-Pre-Test', 'Course-Post-Test')
            ORDER BY a.created_at ASC
        ");
    $stmt->execute([$user_id]);
    $attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($attempts)) {
      return [
        'has_tests' => false,
        'outcome' => 'No Data',
        'badge_class' => 'bg-slate-100 text-slate-500',
        'latest_profile' => 'Pending',
        'has_custom_rec' => false,
        'condition_tag' => null,
        'recommendation_text' => null,
        'course_title' => null,
        'pre' => null,
        'post' => null,
        'acc_diff' => 0,
        'comp_diff' => 0
      ];
    }

    // Group into course modules
    $pairs = [];
    foreach ($attempts as $a) {
      $courseGroup = 'Course Assessment';
      if (preg_match('/^(.*?)(?:\s*(?:—|-)\s*(?:PRE-TEST|POST-TEST))/i', $a['course_title'], $matches)) {
        $courseGroup = trim($matches[1]);
      } else if (preg_match('/GRADE (\d+)/i', $a['course_title'], $matches)) {
        $courseGroup = "Grade " . $matches[1] . " Reading Module";
      } else {
        $courseGroup = $a['course_title'];
      }

      if (!isset($pairs[$courseGroup])) {
        $pairs[$courseGroup] = ['title' => $courseGroup, 'pre' => null, 'post' => null];
      }
      if ($a['phase'] === 'Course-Pre-Test') {
        $pairs[$courseGroup]['pre'] = $a;
      } else {
        $pairs[$courseGroup]['post'] = $a;
      }
    }

    // Evaluate the active (latest) course group
    $activeGroup = end($pairs);
    $title = $activeGroup['title'] ?? 'Course Assessment';
    $pre = $activeGroup['pre'];
    $post = $activeGroup['post'];

    $profRank = ['frustration' => 1, 'instructional' => 2, 'independent' => 3];
    $outcome = 'In Progress';
    $badgeClass = 'bg-blue-50 text-blue-600 border border-blue-200 font-semibold';
    $latestProfile = 'Pending';
    $accDiff = 0;
    $compDiff = 0;

    if ($pre && $post) {
      $latestProfile = $post['oral_reading_profile'] ?? 'Instructional';
      $accDiff = floatval($post['accuracy_score']) - floatval($pre['accuracy_score']);
      $compDiff = floatval($post['comprehension_score']) - floatval($pre['comprehension_score']);
      $wpmDiff = floatval($post['reading_speed']) - floatval($pre['reading_speed']);
      $wcpmPre = round(floatval($pre['reading_speed']) * (floatval($pre['accuracy_score']) / 100));
      $wcpmPost = round(floatval($post['reading_speed']) * (floatval($post['accuracy_score']) / 100));
      $wcpmDiff = $wcpmPost - $wcpmPre;

      $preLevel = $profRank[strtolower(trim($pre['oral_reading_profile'] ?? ''))] ?? 0;
      $postLevel = $profRank[strtolower(trim($post['oral_reading_profile'] ?? ''))] ?? 0;

      $accImproved = $accDiff > 0;
      $compImproved = $compDiff > 0;
      $accDeclined = $accDiff < 0;
      $compDeclined = $compDiff < 0;
      $fluencyStable = $wcpmDiff >= -2 && $wpmDiff >= -2;

      if ($postLevel > $preLevel || (($accImproved || $compImproved) && $fluencyStable)) {
        $outcome = 'Improved';
        $badgeClass = 'bg-emerald-100 text-emerald-800 font-bold';
      } else if ($postLevel < $preLevel || ($accDeclined && $compDeclined)) {
        $outcome = 'Declined';
        $badgeClass = 'bg-rose-100 text-rose-800 font-bold';
      } else if ($accImproved || $compImproved) {
        $outcome = 'Partial Progress';
        $badgeClass = 'bg-blue-100 text-blue-800 font-semibold';
      } else {
        $outcome = 'Maintained';
        $badgeClass = 'bg-amber-100 text-amber-800 font-semibold';
      }
    } elseif ($pre && !$post) {
      $outcome = 'In Progress';
      $badgeClass = 'bg-blue-50 text-blue-600 border border-blue-200 font-semibold';
      $latestProfile = $pre['oral_reading_profile'] ?? 'Pending';
    } elseif (!$pre && $post) {
      $outcome = 'Post-Test Only';
      $badgeClass = 'bg-slate-100 text-slate-600 font-semibold';
      $latestProfile = $post['oral_reading_profile'] ?? 'Instructional';
    }

    // Check if teacher saved a custom recommendation / condition tag
    $stmtRec = $pdo->prepare("SELECT * FROM student_recommendations WHERE user_id = ? ORDER BY updated_at DESC LIMIT 1");
    $stmtRec->execute([$user_id]);
    $customRec = $stmtRec->fetch(PDO::FETCH_ASSOC);

    $hasCustomRec = !empty($customRec);
    $conditionTag = $customRec['condition_tag'] ?? null;
    $recommendationText = $customRec['recommendation_text'] ?? null;

    return [
      'has_tests' => true,
      'course_title' => $title,
      'outcome' => $outcome,
      'badge_class' => $badgeClass,
      'latest_profile' => $latestProfile,
      'has_custom_rec' => $hasCustomRec,
      'condition_tag' => $conditionTag,
      'recommendation_text' => $recommendationText,
      'pre' => $pre,
      'post' => $post,
      'acc_diff' => $accDiff,
      'comp_diff' => $compDiff
    ];
  }
}
