<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';
require_once 'XlsxHelper.php';

// RBAC Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: index.php");
    exit;
}

$report_type    = strtolower(trim($_GET['report_type'] ?? 'comparison')); // 'pre', 'post', 'comparison'
$filter_grade   = trim($_GET['grade'] ?? '');
$filter_section = trim($_GET['section'] ?? '');
$school_name    = trim($_GET['school_name'] ?? 'Barcelonita Fishery School');
$district_name  = trim($_GET['district'] ?? 'Cabusao, Camarines Sur');
$school_year    = trim($_GET['school_year'] ?? '2025-2026');

// Fetch students with GST, Reading Profiles, and Pre/Post Reading Attempts
$stmt = $pdo->query("
    SELECT u.id, u.fname, u.mname, u.lname, u.gender, u.grade_level, u.section, u.lrn,
           g.score as gst_score, g.total_items as gst_total, g.percentage as gst_pct, g.needs_individual_assessment,
           rp.independent_grade, rp.instructional_grade, rp.frustration_grade,
           pre_oral.accuracy_score as oral_pre_acc, pre_oral.comprehension_score as oral_pre_comp, pre_oral.oral_reading_profile as oral_pre_prof
    FROM users u
    LEFT JOIN (
        SELECT user_id, score, total_items, percentage, needs_individual_assessment
        FROM gst_results
        WHERE id IN (SELECT MAX(id) FROM gst_results GROUP BY user_id)
    ) g ON u.id = g.user_id
    LEFT JOIN reading_profiles rp ON u.id = rp.user_id
    LEFT JOIN (
        SELECT user_id, accuracy_score, comprehension_score, oral_reading_profile
        FROM reading_attempts
        WHERE id IN (
            SELECT MAX(id) FROM reading_attempts
            WHERE phase = 'Pre-Test'
            GROUP BY user_id
        )
    ) pre_oral ON u.id = pre_oral.user_id
    WHERE u.role = 'student'
    ORDER BY u.lname ASC, u.fname ASC
");
$all_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

$rows_data = [];

foreach ($all_students as $s) {
    $clean_sec = preg_replace('/^Grade\s+\d+\s+/i', '', trim((string)($s['section'] ?? '')));
    $stu_grade = trim((string)($s['grade_level'] ?? ''));

    if (!empty($filter_grade) && strcasecmp($stu_grade, $filter_grade) !== 0) {
        continue;
    }
    if (!empty($filter_section) && strcasecmp($clean_sec, $filter_section) !== 0 && strcasecmp((string)($s['section'] ?? ''), $filter_section) !== 0) {
        continue;
    }

    $summary = get_student_course_progress_summary($pdo, $s['id']);
    $preCourse  = $summary['pre'];
    $postCourse = $summary['post'];

    // Determine Pre-Test metrics (Course Pre-Test -> Oral Pre-Test -> GST)
    $hasPre = false;
    $preRaw30 = '';
    $prePctDec = '';
    $preLevel = '';
    $preRemarks = '';

    if ($preCourse) {
        $hasPre = true;
        $acc = floatval($preCourse['accuracy_score']);
        $comp = floatval($preCourse['comprehension_score']);
        $pct = ($acc + $comp) / 2.0;
        $preRaw30 = round(($pct / 100.0) * 30);
        $prePctDec = round($pct / 100.0, 4);
        $preLevel = !empty($preCourse['oral_reading_profile']) ? $preCourse['oral_reading_profile'] : 'Instructional';
    } elseif ($s['oral_pre_acc'] !== null) {
        $hasPre = true;
        $acc = floatval($s['oral_pre_acc']);
        $comp = floatval($s['oral_pre_comp']);
        $pct = ($acc + $comp) / 2.0;
        $preRaw30 = round(($pct / 100.0) * 30);
        $prePctDec = round($pct / 100.0, 4);
        $preLevel = !empty($s['oral_pre_prof']) ? $s['oral_pre_prof'] : 'Instructional';
    } elseif ($s['gst_score'] !== null) {
        $hasPre = true;
        $tot = floatval($s['gst_total'] ?: 40);
        $pct = $tot > 0 ? (floatval($s['gst_score']) / $tot) * 100.0 : 0;
        $preRaw30 = round(($pct / 100.0) * 30);
        $prePctDec = round($pct / 100.0, 4);
        $preLevel = ((int)$s['needs_individual_assessment'] === 0) ? 'Independent' : 'Frustration';
    }

    if ($hasPre) {
        $preRemarks = ($preLevel === 'Non-Reader' || $preLevel === 'Frustration' || $preRaw30 <= 15)
            ? 'Targeted Remediation'
            : 'Regular Intervention';
    }

    // Determine Post-Test metrics
    $hasPost = false;
    $postRaw30 = '';
    $postPctDec = '';
    $postLevel = '';
    $postRemarks = '';

    if ($postCourse) {
        $hasPost = true;
        $acc = floatval($postCourse['accuracy_score']);
        $comp = floatval($postCourse['comprehension_score']);
        $pct = ($acc + $comp) / 2.0;
        $postRaw30 = round(($pct / 100.0) * 30);
        $postPctDec = round($pct / 100.0, 4);
        $postLevel = !empty($postCourse['oral_reading_profile']) ? $postCourse['oral_reading_profile'] : 'Instructional';
        $postRemarks = !empty($summary['condition_tag'])
            ? $summary['condition_tag']
            : (($postLevel === 'Independent' || $postLevel === 'Instructional') ? 'Regular Intervention / Enrichment' : 'Targeted Remediation');
    }

    // Filter by report type if requested, while falling back to all filtered students if none match
    $mi = !empty($s['mname']) ? strtoupper(substr($s['mname'], 0, 1)) . '.' : '';
    $fullName = trim($s['lname'] . ', ' . $s['fname'] . ($mi ? ' ' . $mi : ''));
    $genderRaw = strtoupper(trim((string)($s['gender'] ?? '')));
    $sex = ($genderRaw === 'MALE' || $genderRaw === 'M') ? 'M' : (($genderRaw === 'FEMALE' || $genderRaw === 'F') ? 'F' : '');
    $gradeSec = trim($stu_grade . ($clean_sec !== '' ? ' - ' . $clean_sec : ''));

    $rows_data[] = [
        'lrn'         => (string)($s['lrn'] ?? ''),
        'name'        => $fullName,
        'sex'         => $sex,
        'grade_sec'   => $gradeSec,
        'has_pre'     => $hasPre,
        'pre_raw'     => $preRaw30,
        'pre_pct'     => $prePctDec,
        'pre_level'   => $preLevel,
        'pre_remarks' => $preRemarks,
        'has_post'    => $hasPost,
        'post_raw'    => $postRaw30,
        'post_pct'    => $postPctDec,
        'post_level'  => $postLevel,
        'post_remarks'=> $postRemarks
    ];
}

// Filter specific subset if report_type is strictly pre or post and matching rows exist
if ($report_type === 'pre') {
    $preOnly = array_values(array_filter($rows_data, fn($r) => $r['has_pre']));
    if (!empty($preOnly)) {
        $rows_data = $preOnly;
    }
} elseif ($report_type === 'post') {
    $postOnly = array_values(array_filter($rows_data, fn($r) => $r['has_post']));
    if (!empty($postOnly)) {
        $rows_data = $postOnly;
    }
}

$templatePath = __DIR__ . '/ARAL_Program_Pre_Post_Assessment_Template.xlsx';
$entries = XlsxHelper::readZipEntries($templatePath);

$subjectKeyStage = !empty($filter_grade) ? ('Reading / ' . $filter_grade) : 'Reading / Junior High School';
$totalRows = max(5, count($rows_data));
$lastDataRow = 6 + $totalRows;

/**
 * Helper to build Sheet 1 (Pre-Test) or Sheet 2 (Post-Test)
 */
function buildTrackingSheetXml(
    string $title,
    int $titleStyle,
    int $headerStyle,
    string $district,
    string $schoolYear,
    string $schoolName,
    string $subjectStage,
    array $rowsData,
    int $totalRows,
    string $mode, // 'pre' or 'post'
    bool $isTabSelected
): string {
    $lastRow = 6 + $totalRows;
    $tabSelAttr = $isTabSelected ? ' tabSelected="1"' : '';

    $xmlRows = [];
    // Row 1: Title
    $xmlRows[] = '<row r="1" ht="40" customHeight="1">'
        . XlsxHelper::inlineStrCell('A1', $titleStyle, $title)
        . '</row>';
    $xmlRows[] = '<row r="2"></row>';
    // Row 3 & Row 4: Metadata
    $xmlRows[] = '<row r="3">'
        . XlsxHelper::inlineStrCell('A3', 2, 'Region/Division:')
        . XlsxHelper::inlineStrCell('B3', 3, $district)
        . XlsxHelper::inlineStrCell('E3', 2, 'School Year:')
        . XlsxHelper::inlineStrCell('F3', 3, $schoolYear)
        . '</row>';
    $xmlRows[] = '<row r="4">'
        . XlsxHelper::inlineStrCell('A4', 2, 'School Name:')
        . XlsxHelper::inlineStrCell('B4', 3, $schoolName)
        . XlsxHelper::inlineStrCell('E4', 2, 'Subject/Key Stage:')
        . XlsxHelper::inlineStrCell('F4', 3, $subjectStage)
        . '</row>';
    $xmlRows[] = '<row r="5"></row>';

    // Row 6: Table Headers
    $headers = [
        'A6' => 'LRN',
        'B6' => "Learner's Name",
        'C6' => 'Gender',
        'D6' => 'Grade & Section',
        'E6' => 'Raw Score',
        'F6' => 'Percentage (%)',
        'G6' => 'Proficiency Level',
        'H6' => 'Remarks / Intervention Needed'
    ];
    $hCells = '';
    foreach ($headers as $ref => $hText) {
        $hCells .= XlsxHelper::inlineStrCell($ref, $headerStyle, $hText);
    }
    $xmlRows[] = '<row r="6" ht="28" customHeight="1">' . $hCells . '</row>';

    // Data Rows (Row 7..lastRow)
    for ($i = 0; $i < $totalRows; $i++) {
        $r = 7 + $i;
        $isOddRow = ($r % 2 === 1); // Row 7, 9, 11 use styles 5,6,7; Row 8, 10 use styles 8,9,10
        $sCenter = $isOddRow ? 5 : 8;
        $sLeft   = $isOddRow ? 6 : 9;
        $sPct    = $isOddRow ? 7 : 10;

        if ($i < count($rowsData)) {
            $d = $rowsData[$i];
            $raw   = ($mode === 'pre') ? $d['pre_raw']     : $d['post_raw'];
            $pct   = ($mode === 'pre') ? $d['pre_pct']     : $d['post_pct'];
            $level = ($mode === 'pre') ? $d['pre_level']   : $d['post_level'];
            $rem   = ($mode === 'pre') ? $d['pre_remarks'] : $d['post_remarks'];

            $fFormula = "IF(E{$r}<>\"\", E{$r}/30, \"\")";
            $gFormula = "IF(E{$r}=\"\", \"\", IF(F{$r}<0.5, \"Non-Reader\", IF(F{$r}<0.75, \"Frustration\", IF(F{$r}<0.9, \"Instructional\", \"Independent\"))))";
            $hFormula = "IF(E{$r}=\"\", \"\", IF(E{$r}<=15, \"Targeted Remediation\", \"Regular Intervention\"))";

            $cells = XlsxHelper::inlineStrCell("A{$r}", $sCenter, $d['lrn'])
                . XlsxHelper::inlineStrCell("B{$r}", $sLeft, $d['name'])
                . XlsxHelper::inlineStrCell("C{$r}", $sCenter, $d['sex'])
                . XlsxHelper::inlineStrCell("D{$r}", $sLeft, $d['grade_sec'])
                . XlsxHelper::numCell("E{$r}", $sCenter, $raw)
                . XlsxHelper::formulaCell("F{$r}", $sPct, $fFormula, $pct)
                . ($level !== ''
                    ? XlsxHelper::inlineStrCell("G{$r}", $sCenter, $level)
                    : XlsxHelper::formulaCell("G{$r}", $sCenter, $gFormula, ''))
                . ($rem !== ''
                    ? XlsxHelper::inlineStrCell("H{$r}", $sLeft, $rem)
                    : XlsxHelper::formulaCell("H{$r}", $sLeft, $hFormula, ''));
        } else {
            $cells = '<c r="A' . $r . '" s="' . $sCenter . '"/>'
                . '<c r="B' . $r . '" s="' . $sLeft . '"/>'
                . '<c r="C' . $r . '" s="' . $sCenter . '"/>'
                . '<c r="D' . $r . '" s="' . $sLeft . '"/>'
                . '<c r="E' . $r . '" s="' . $sCenter . '"/>'
                . XlsxHelper::formulaCell("F{$r}", $sPct, "IF(E{$r}<>\"\", E{$r}/30, \"\")", '')
                . XlsxHelper::formulaCell("G{$r}", $sCenter, "IF(E{$r}=\"\", \"\", IF(F{$r}<0.5, \"Non-Reader\", IF(F{$r}<0.75, \"Frustration\", IF(F{$r}<0.9, \"Instructional\", \"Independent\"))))", '')
                . XlsxHelper::formulaCell("H{$r}", $sLeft, "IF(E{$r}=\"\", \"\", IF(E{$r}<=15, \"Targeted Remediation\", \"Regular Intervention\"))", '');
        }
        $xmlRows[] = '<row r="' . $r . '">' . $cells . '</row>';
    }

    return '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetPr><outlinePr summaryBelow="1" summaryRight="1"/><pageSetUpPr/></sheetPr>'
        . '<dimension ref="A1:H' . $lastRow . '"/>'
        . '<sheetViews><sheetView showGridLines="1"' . $tabSelAttr . ' workbookViewId="0"><selection activeCell="A1" sqref="A1"/></sheetView></sheetViews>'
        . '<sheetFormatPr baseColWidth="8" defaultRowHeight="15"/>'
        . '<cols>'
        . '<col width="19" customWidth="1" min="1" max="1"/>'
        . '<col width="28" customWidth="1" min="2" max="2"/>'
        . '<col width="12" customWidth="1" min="3" max="3"/>'
        . '<col width="22" customWidth="1" min="4" max="4"/>'
        . '<col width="18" customWidth="1" min="5" max="5"/>'
        . '<col width="20" customWidth="1" min="6" max="6"/>'
        . '<col width="26" customWidth="1" min="7" max="7"/>'
        . '<col width="45" customWidth="1" min="8" max="8"/>'
        . '</cols>'
        . '<sheetData>' . implode('', $xmlRows) . '</sheetData>'
        . '<mergeCells count="1"><mergeCell ref="A1:H1"/></mergeCells>'
        . '<pageMargins left="0.75" right="0.75" top="1" bottom="1" header="0.5" footer="0.5"/>'
        . '</worksheet>';
}

// Build Sheet 1 (Pre-Test) & Sheet 2 (Post-Test)
$entries['xl/worksheets/sheet1.xml'] = buildTrackingSheetXml(
    'ARAL PROGRAM - PRE-TEST TRACKING SHEET',
    1,
    4,
    $district_name,
    $school_year,
    $school_name,
    $subjectKeyStage,
    $rows_data,
    $totalRows,
    'pre',
    ($report_type === 'pre')
);

$entries['xl/worksheets/sheet2.xml'] = buildTrackingSheetXml(
    'ARAL PROGRAM - POST-TEST TRACKING SHEET',
    11,
    12,
    $district_name,
    $school_year,
    $school_name,
    $subjectKeyStage,
    $rows_data,
    $totalRows,
    'post',
    ($report_type === 'post')
);

// Build Sheet 3 (Pre-Post Comparison)
$compRowsXml = [];
$compRowsXml[] = '<row r="1" ht="40" customHeight="1">'
    . XlsxHelper::inlineStrCell('A1', 13, 'ARAL PROGRAM - PRE-TEST VS POST-TEST PERFORMANCE ANALYSIS')
    . '</row>';
$compRowsXml[] = '<row r="2"></row>';
$compRowsXml[] = '<row r="3"></row>';

$compHeaders = [
    'A4' => 'LRN',
    'B4' => "Learner's Name",
    'C4' => 'Gender',
    'D4' => 'Pre-Test Score',
    'E4' => 'Pre-Test %',
    'F4' => 'Pre-Test Level',
    'G4' => 'Post-Test Score',
    'H4' => 'Post-Test %',
    'I4' => 'Post-Test Level',
    'J4' => 'Score Gain/Loss',
    'K4' => 'Percentage Growth',
    'L4' => 'Intervention Outcome'
];
$chCells = '';
foreach ($compHeaders as $ref => $hText) {
    $chCells .= XlsxHelper::inlineStrCell($ref, 14, $hText);
}
$compRowsXml[] = '<row r="4" ht="30" customHeight="1">' . $chCells . '</row>';
$compRowsXml[] = '<row r="5"></row>';
$compRowsXml[] = '<row r="6"></row>';

for ($i = 0; $i < $totalRows; $i++) {
    $r = 7 + $i;
    $isOddRow = ($r % 2 === 1);
    $sCenter = $isOddRow ? 5 : 8;
    $sLeft   = $isOddRow ? 6 : 9;
    $sPct    = $isOddRow ? 7 : 10;

    $cCells = XlsxHelper::formulaCell("A{$r}", $sCenter, "'Pre-Test'!A{$r}")
        . XlsxHelper::formulaCell("B{$r}", $sLeft, "'Pre-Test'!B{$r}")
        . XlsxHelper::formulaCell("C{$r}", $sCenter, "'Pre-Test'!C{$r}")
        . XlsxHelper::formulaCell("D{$r}", $sCenter, "'Pre-Test'!E{$r}")
        . XlsxHelper::formulaCell("E{$r}", $sPct, "'Pre-Test'!F{$r}")
        . XlsxHelper::formulaCell("F{$r}", $sCenter, "'Pre-Test'!G{$r}")
        . XlsxHelper::formulaCell("G{$r}", $sCenter, "'Post-Test'!E{$r}")
        . XlsxHelper::formulaCell("H{$r}", $sPct, "'Post-Test'!F{$r}")
        . XlsxHelper::formulaCell("I{$r}", $sCenter, "'Post-Test'!G{$r}")
        . XlsxHelper::formulaCell("J{$r}", $sCenter, "IF(OR(D{$r}=\"\",G{$r}=\"\"), \"\", G{$r}-D{$r})")
        . XlsxHelper::formulaCell("K{$r}", $sPct, "IF(OR(E{$r}=\"\",H{$r}=\"\"), \"\", H{$r}-E{$r})")
        . XlsxHelper::formulaCell("L{$r}", $sCenter, "IF(J{$r}=\"\", \"Pending Post-Test\", IF(J{$r}>0, \"Progressed\", IF(J{$r}=0, \"No Change\", \"Regressed\")))");

    $compRowsXml[] = '<row r="' . $r . '">' . $cCells . '</row>';
}

$rBlank1 = $lastDataRow + 1;
$rBlank2 = $lastDataRow + 2;
$rSumHdr = $lastDataRow + 3;
$rTot    = $lastDataRow + 4;
$rAvgPre = $lastDataRow + 5;
$rAvgPost= $lastDataRow + 6;
$rAvgGain= $lastDataRow + 7;

$compRowsXml[] = '<row r="' . $rBlank1 . '"></row>';
$compRowsXml[] = '<row r="' . $rBlank2 . '"></row>';
$compRowsXml[] = '<row r="' . $rSumHdr . '">' . XlsxHelper::inlineStrCell("B{$rSumHdr}", 15, 'SUMMARY STATISTICS') . '</row>';
$compRowsXml[] = '<row r="' . $rTot . '">'
    . XlsxHelper::inlineStrCell("B{$rTot}", 2, 'Total Tutees Assessed')
    . XlsxHelper::formulaCell("C{$rTot}", 16, "COUNTA(B7:B{$lastDataRow})")
    . '</row>';
$compRowsXml[] = '<row r="' . $rAvgPre . '">'
    . XlsxHelper::inlineStrCell("B{$rAvgPre}", 2, 'Average Pre-Test Score')
    . XlsxHelper::formulaCell("C{$rAvgPre}", 16, "IFERROR(AVERAGE(D7:D{$lastDataRow}), 0)")
    . '</row>';
$compRowsXml[] = '<row r="' . $rAvgPost . '">'
    . XlsxHelper::inlineStrCell("B{$rAvgPost}", 2, 'Average Post-Test Score')
    . XlsxHelper::formulaCell("C{$rAvgPost}", 16, "IFERROR(AVERAGE(G7:G{$lastDataRow}), 0)")
    . '</row>';
$compRowsXml[] = '<row r="' . $rAvgGain . '">'
    . XlsxHelper::inlineStrCell("B{$rAvgGain}", 2, 'Average Class Gain')
    . XlsxHelper::formulaCell("C{$rAvgGain}", 16, "IFERROR(AVERAGE(J7:J{$lastDataRow}), 0)")
    . '</row>';

$isCompSelected = ($report_type !== 'pre' && $report_type !== 'post');
$compTabSel = $isCompSelected ? ' tabSelected="1"' : '';

$entries['xl/worksheets/sheet3.xml'] = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<sheetPr><outlinePr summaryBelow="1" summaryRight="1"/><pageSetUpPr/></sheetPr>'
    . '<dimension ref="A1:L' . $rAvgGain . '"/>'
    . '<sheetViews><sheetView showGridLines="1"' . $compTabSel . ' workbookViewId="0"><selection activeCell="A1" sqref="A1"/></sheetView></sheetViews>'
    . '<sheetFormatPr baseColWidth="8" defaultRowHeight="15"/>'
    . '<cols>'
    . '<col width="18" customWidth="1" min="1" max="1"/>'
    . '<col width="28" customWidth="1" min="2" max="2"/>'
    . '<col width="14" customWidth="1" min="3" max="3"/>'
    . '<col width="18" customWidth="1" min="4" max="4"/>'
    . '<col width="18" customWidth="1" min="5" max="5"/>'
    . '<col width="18" customWidth="1" min="6" max="6"/>'
    . '<col width="19" customWidth="1" min="7" max="7"/>'
    . '<col width="19" customWidth="1" min="8" max="8"/>'
    . '<col width="19" customWidth="1" min="9" max="9"/>'
    . '<col width="18" customWidth="1" min="10" max="10"/>'
    . '<col width="20" customWidth="1" min="11" max="11"/>'
    . '<col width="36" customWidth="1" min="12" max="12"/>'
    . '</cols>'
    . '<sheetData>' . implode('', $compRowsXml) . '</sheetData>'
    . '<mergeCells count="1"><mergeCell ref="A1:L1"/></mergeCells>'
    . '<pageMargins left="0.75" right="0.75" top="1" bottom="1" header="0.5" footer="0.5"/>'
    . '</worksheet>';

// Set activeTab in workbook.xml based on report_type (0 = Pre-Test, 1 = Post-Test, 2 = Pre-Post Comparison)
$activeTabIndex = ($report_type === 'pre') ? 0 : (($report_type === 'post') ? 1 : 2);
if (isset($entries['xl/workbook.xml'])) {
    $entries['xl/workbook.xml'] = preg_replace('/activeTab="\d+"/', 'activeTab="' . $activeTabIndex . '"', $entries['xl/workbook.xml']);
}

$xlsxBinary = XlsxHelper::buildZipBinary($entries);

$typeLabel = ($report_type === 'pre') ? 'PreTest' : (($report_type === 'post') ? 'PostTest' : 'PrePost_Comparison');
$filename_parts = ['ARAL_' . $typeLabel . '_Report'];
if (!empty($filter_grade)) $filename_parts[] = str_replace(' ', '', $filter_grade);
if (!empty($filter_section)) $filename_parts[] = str_replace(' ', '', $filter_section);
$filename_parts[] = date('Ymd_His');
$filename = implode('_', $filename_parts) . '.xlsx';

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($xlsxBinary));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo $xlsxBinary;
exit;
