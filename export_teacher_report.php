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

$filter_grade   = trim($_GET['grade'] ?? '');
$filter_section = trim($_GET['section'] ?? '');
$filter_status  = trim($_GET['status'] ?? '');
$school_name    = trim($_GET['school_name'] ?? 'Barcelonita Fishery School');
$district_name  = trim($_GET['district'] ?? 'Cabusao, Camarines Sur');

// Fetch teacher full name
$stmtTeacher = $pdo->prepare("SELECT fname, mname, lname FROM users WHERE id = ?");
$stmtTeacher->execute([$_SESSION['user_id']]);
$teacherRow = $stmtTeacher->fetch(PDO::FETCH_ASSOC);
$assessor_name = trim(($teacherRow['fname'] ?? $_SESSION['fname'] ?? 'Teacher') . ' ' . ($teacherRow['lname'] ?? ''));

// Fetch students with reading profile, GST, and latest Pre-Test attempt
$stmt = $pdo->prepare("
    SELECT u.*, 
           p.independent_grade, p.instructional_grade, p.frustration_grade,
           g.score AS gst_score, g.total_items AS gst_total, g.percentage AS gst_pct,
           g.needs_individual_assessment, g.language AS gst_language,
           pre_att.accuracy_score AS pre_acc, pre_att.comprehension_score AS pre_comp,
           pre_att.oral_reading_profile AS pre_oral_profile, pre_att.miscues_json AS pre_miscues
    FROM users u
    LEFT JOIN reading_profiles p ON u.id = p.user_id
    LEFT JOIN (
        SELECT user_id, score, total_items, percentage, needs_individual_assessment, language
        FROM gst_results
        WHERE id IN (SELECT MAX(id) FROM gst_results GROUP BY user_id)
    ) g ON u.id = g.user_id
    LEFT JOIN (
        SELECT user_id, accuracy_score, comprehension_score, oral_reading_profile, miscues_json
        FROM reading_attempts
        WHERE id IN (
            SELECT MAX(id) FROM reading_attempts 
            WHERE phase IN ('Pre-Test', 'Course-Pre-Test') 
            GROUP BY user_id
        )
    ) pre_att ON u.id = pre_att.user_id
    WHERE u.role = 'student' 
    ORDER BY u.grade_level ASC, u.section ASC, u.lname ASC, u.fname ASC
");
$stmt->execute();
$all_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filtered_students = [];
$counts = [
    'Independent'   => 0,
    'Instructional' => 0,
    'Frustration'   => 0,
    'Non-Reader'    => 0,
    'Pending'       => 0
];

foreach ($all_students as $s) {
    $enrolled_grade = intval(preg_replace('/[^0-9]/', '', (string)($s['grade_level'] ?? '')));
    $final_status = 'Pending';
    
    if (isset($s['needs_individual_assessment']) && (int)$s['needs_individual_assessment'] === 0) {
        $final_status = 'Independent';
    } elseif ($s['independent_grade'] !== null || $s['instructional_grade'] !== null || $s['frustration_grade'] !== null) {
        if ($s['independent_grade'] !== null && $enrolled_grade <= intval($s['independent_grade'])) {
            $final_status = 'Independent';
        } elseif ($s['instructional_grade'] !== null && $enrolled_grade <= intval($s['instructional_grade'])) {
            $final_status = 'Instructional';
        } else {
            if ($s['frustration_grade'] !== null && intval($s['frustration_grade']) <= 4 && $s['instructional_grade'] === null && $s['independent_grade'] === null) {
                $final_status = 'Non-Reader';
            } else {
                $final_status = 'Frustration';
            }
        }
    }

    $courseSummary = get_student_course_progress_summary($pdo, $s['id']);
    $s['course_summary'] = $courseSummary;

    $profRank = ['non-reader' => 0, 'frustration' => 1, 'instructional' => 2, 'independent' => 3];
    if ($courseSummary['has_tests'] && !empty($courseSummary['latest_profile']) && $courseSummary['latest_profile'] !== 'Pending') {
        $courseProf = $courseSummary['latest_profile'];
        $courseRank = $profRank[strtolower(trim($courseProf))] ?? 1;
        $baselineRank = $profRank[strtolower(trim($final_status))] ?? -1;
        if ($courseSummary['post'] || $courseRank > $baselineRank || $final_status === 'Pending') {
            $final_status = ucfirst(strtolower(trim($courseProf)));
        }
    }
    
    $clean_section = preg_replace('/^Grade\s+\d+\s+/i', '', (string)($s['section'] ?? ''));
    $student_grade = trim((string)($s['grade_level'] ?? ''));

    // Apply Filters
    if (!empty($filter_grade) && strcasecmp($student_grade, $filter_grade) !== 0) {
        continue;
    }
    if (!empty($filter_section) && strcasecmp($clean_section, $filter_section) !== 0 && strcasecmp((string)($s['section'] ?? ''), $filter_section) !== 0) {
        continue;
    }
    if (!empty($filter_status) && strcasecmp($final_status, $filter_status) !== 0) {
        continue;
    }

    // Recommended Intervention Strategy / IRIP Action
    if (!empty($courseSummary['has_custom_rec']) && !empty($courseSummary['condition_tag'])) {
        $remarks = 'Teacher Note: ' . $courseSummary['condition_tag'];
    } elseif (!empty($courseSummary['has_custom_rec']) && !empty($courseSummary['recommendation_text'])) {
        $remarks = $courseSummary['recommendation_text'];
    } elseif (!empty($courseSummary['post'])) {
        $remarks = $courseSummary['outcome'] . ' (' . $final_status . ') - Post-Test Completed';
    } elseif (!empty($courseSummary['pre'])) {
        $remarks = 'Guided Reading & Comprehension Modules (Pre-Test Completed)';
    } else {
        if ($final_status === 'Independent') {
            $remarks = 'Enrichment Reading / Peer Tutoring (Grade-Ready)';
        } elseif ($final_status === 'Instructional') {
            $remarks = 'Guided Reading Practice & Comprehension Cards';
        } elseif ($final_status === 'Frustration') {
            $remarks = 'Targeted Reading Remediation & Fluency Drills';
        } elseif ($final_status === 'Non-Reader') {
            $remarks = 'Full Remediation, Intensive Phonics & Pull-out Sessions';
        } else {
            $remarks = 'Administer Phil-IRI GST & Oral Reading Pre-Assessment';
        }
    }

    // Identified Domain Deficiencies / Gaps
    $gaps = [];
    if (!empty($s['pre_miscues'])) {
        $mData = json_decode($s['pre_miscues'], true);
        if (is_array($mData)) {
            foreach ($mData as $mKey => $mCount) {
                if ((int)$mCount > 0 && $mKey !== 'selfCorrection') {
                    $gaps[] = ucfirst($mKey) . ' (' . (int)$mCount . ')';
                }
            }
        }
    }
    if (empty($gaps)) {
        if ($final_status === 'Independent') {
            $domain_gaps = 'No major reading gaps identified (Grade-ready)';
        } elseif ($final_status === 'Instructional') {
            $domain_gaps = 'Comprehension on complex sentences & pacing';
        } elseif ($final_status === 'Frustration') {
            $domain_gaps = 'Word recognition, decoding accuracy & reading fluency';
        } elseif ($final_status === 'Non-Reader') {
            $domain_gaps = 'Alphabet knowledge, phonemic awareness & basic decoding';
        } else {
            $domain_gaps = 'Awaiting diagnostic assessment';
        }
    } else {
        $domain_gaps = implode(', ', array_slice($gaps, 0, 3));
    }

    $s['computed_profile'] = $final_status;
    $s['computed_remarks'] = $remarks;
    $s['computed_gaps']    = $domain_gaps;
    $s['clean_section']    = $clean_section;

    if (isset($counts[$final_status])) {
        $counts[$final_status]++;
    }

    $filtered_students[] = $s;
}

// Load Template: ARAL_Program_Pre_Assessment_Report_Template (1).xlsx
$templatePath = __DIR__ . '/ARAL_Program_Pre_Assessment_Report_Template (1).xlsx';
$entries = XlsxHelper::readZipEntries($templatePath);

// Build Grade & Section label
$grSecLabelParts = [];
$grSecLabelParts[] = !empty($filter_grade) ? $filter_grade : 'All Grade Levels';
$grSecLabelParts[] = !empty($filter_section) ? $filter_section : 'All Sections';
$grSecHeader = implode(' - ', $grSecLabelParts);

$totalStudents = count($filtered_students);
$minRows = max(17, $totalStudents);
$lastDataRow = 7 + $minRows;
$summaryHeaderRow = $lastDataRow + 2;
$lastSheetRow = $summaryHeaderRow + 4;

$rowsXml = [];

// Row 1 & Row 2: Titles
$rowsXml[] = '<row r="1" spans="1:10" ht="21" x14ac:dyDescent="0.4">'
    . XlsxHelper::inlineStrCell('A1', 1, 'ARAL PROGRAM - LEARNER PRE-ASSESSMENT REPORT (READING)')
    . '</row>';
$rowsXml[] = '<row r="2" spans="1:10" x14ac:dyDescent="0.3">'
    . XlsxHelper::inlineStrCell('A2', 2, 'Academic Recovery and Accessible Learning (ARAL) Program (RA 12028)')
    . '</row>';

// Row 4 & Row 5: Metadata Header
$rowsXml[] = '<row r="4" spans="1:10" x14ac:dyDescent="0.3">'
    . XlsxHelper::inlineStrCell('A4', 3, 'School Name:')
    . XlsxHelper::inlineStrCell('B4', 4, $school_name)
    . XlsxHelper::inlineStrCell('D4', 3, 'Grade & Section:')
    . XlsxHelper::inlineStrCell('E4', 4, $grSecHeader)
    . '</row>';
$rowsXml[] = '<row r="5" spans="1:10" x14ac:dyDescent="0.3">'
    . XlsxHelper::inlineStrCell('A5', 3, 'District/Division:')
    . XlsxHelper::inlineStrCell('B5', 4, $district_name)
    . XlsxHelper::inlineStrCell('D5', 3, 'Assessor/Tutor:')
    . XlsxHelper::inlineStrCell('E5', 4, $assessor_name)
    . '</row>';

// Row 7: Column Headers
$headers = [
    'A7' => 'No.',
    'B7' => "Learner's Full Name",
    'C7' => 'Sex (M/F)',
    'D7' => 'Age',
    'E7' => 'CRLA / Phil-IRI Baseline Tool Used',
    'F7' => 'Pre-Assessment Raw Score',
    'G7' => 'Percentage (%)',
    'H7' => 'Initial Profile / Proficiency Level',
    'I7' => 'Identified Domain Deficiencies / Gaps',
    'J7' => 'Recommended Intervention Strategy / IRIP Action'
];
$hdrCells = '';
foreach ($headers as $cellRef => $hText) {
    $hdrCells .= XlsxHelper::inlineStrCell($cellRef, 5, $hText);
}
$rowsXml[] = '<row r="7" spans="1:10" ht="28.05" customHeight="1" x14ac:dyDescent="0.3">' . $hdrCells . '</row>';

// Student Data Rows (starting at Row 8)
for ($i = 0; $i < $minRows; $i++) {
    $r = 8 + $i;
    $isEvenRow = ($r % 2 === 0);
    // Preserve alternating row styles from template
    $styleCenter = $isEvenRow ? 6 : 10;
    $styleLeft   = $isEvenRow ? 7 : 11;
    $styleScore  = $isEvenRow ? 8 : 12;
    $stylePct    = $isEvenRow ? 9 : 13;
    $styleBlank  = $isEvenRow ? 15 : 14;

    if ($i < $totalStudents) {
        $st = $filtered_students[$i];
        $mi = !empty($st['mname']) ? strtoupper(substr($st['mname'], 0, 1)) . '.' : '';
        $fullName = trim($st['lname'] . ', ' . $st['fname'] . ($mi ? ' ' . $mi : ''));
        $genderRaw = strtoupper(trim((string)($st['gender'] ?? '')));
        $sex = ($genderRaw === 'MALE' || $genderRaw === 'M') ? 'M' : (($genderRaw === 'FEMALE' || $genderRaw === 'F') ? 'F' : '');
        $age = !empty($st['age']) ? (int)$st['age'] : '';

        // Baseline Tool & Score
        $rawScore = '';
        $pctDecimal = '';
        $denom = 40;
        if ($st['gst_score'] !== null) {
            $rawScore = (float)$st['gst_score'];
            $denom = (int)($st['gst_total'] ?: 40);
            $pctDecimal = $denom > 0 ? round($rawScore / $denom, 4) : 0;
            $toolUsed = 'Phil-IRI GST (' . ($st['gst_language'] ?? 'English') . ')';
        } elseif ($st['pre_acc'] !== null) {
            $avgPct = (floatval($st['pre_acc']) + floatval($st['pre_comp'])) / 2.0;
            $denom = 30;
            $rawScore = round(($avgPct / 100.0) * $denom);
            $pctDecimal = round($avgPct / 100.0, 4);
            $toolUsed = 'Phil-IRI Oral Pre-Test';
        } else {
            $toolUsed = 'Phil-IRI (English)';
        }

        $rowCells = XlsxHelper::numCell("A{$r}", $styleCenter, $i + 1)
            . XlsxHelper::inlineStrCell("B{$r}", $styleLeft, $fullName)
            . XlsxHelper::inlineStrCell("C{$r}", $styleCenter, $sex)
            . XlsxHelper::numCell("D{$r}", $styleCenter, $age)
            . XlsxHelper::inlineStrCell("E{$r}", $styleLeft, $toolUsed)
            . XlsxHelper::numCell("F{$r}", $styleScore, $rawScore)
            . ($rawScore !== ''
                ? XlsxHelper::formulaCell("G{$r}", $stylePct, "IF(F{$r}<>\"\", F{$r}/{$denom}, \"\")", $pctDecimal)
                : XlsxHelper::formulaCell("G{$r}", $stylePct, "IF(F{$r}<>\"\", F{$r}/30, \"\")", '', 'str'))
            . XlsxHelper::inlineStrCell("H{$r}", $styleLeft, $st['computed_profile'])
            . XlsxHelper::inlineStrCell("I{$r}", $styleLeft, $st['computed_gaps'])
            . XlsxHelper::inlineStrCell("J{$r}", $styleLeft, $st['computed_remarks']);
    } else {
        // Blank numbered row matching template
        $rowCells = XlsxHelper::numCell("A{$r}", $styleCenter, $i + 1)
            . '<c r="B' . $r . '" s="' . $styleBlank . '"/>'
            . '<c r="C' . $r . '" s="' . $styleBlank . '"/>'
            . '<c r="D' . $r . '" s="' . $styleBlank . '"/>'
            . '<c r="E' . $r . '" s="' . $styleBlank . '"/>'
            . '<c r="F' . $r . '" s="' . $styleBlank . '"/>'
            . XlsxHelper::formulaCell("G{$r}", $stylePct, "IF(F{$r}<>\"\", F{$r}/30, \"\")", '', 'str')
            . '<c r="H' . $r . '" s="' . $styleBlank . '"/>'
            . '<c r="I' . $r . '" s="' . $styleBlank . '"/>'
            . '<c r="J' . $r . '" s="' . $styleBlank . '"/>';
    }

    $rowsXml[] = '<row r="' . $r . '" spans="1:10" ht="22.05" customHeight="1" x14ac:dyDescent="0.3">' . $rowCells . '</row>';
}

// Total Profile Summary section at bottom
$rSumTitle = $summaryHeaderRow;
$rNon      = $summaryHeaderRow + 1;
$rFru      = $summaryHeaderRow + 2;
$rIns      = $summaryHeaderRow + 3;
$rInd      = $summaryHeaderRow + 4;

$rowsXml[] = '<row r="' . $rSumTitle . '" spans="1:10" x14ac:dyDescent="0.3">'
    . XlsxHelper::inlineStrCell("B{$rSumTitle}", 3, 'Total Profile Summary')
    . '</row>';
$rowsXml[] = '<row r="' . $rNon . '" spans="1:10" x14ac:dyDescent="0.3">'
    . XlsxHelper::inlineStrCell("B{$rNon}", 4, 'Total Non-Readers / Critical Intervention:')
    . XlsxHelper::formulaCell("C{$rNon}", 3, "COUNTIF(H8:H{$lastDataRow}, \"*Non-Reader*\")", $counts['Non-Reader'])
    . '</row>';
$rowsXml[] = '<row r="' . $rFru . '" spans="1:10" x14ac:dyDescent="0.3">'
    . XlsxHelper::inlineStrCell("B{$rFru}", 4, 'Total Frustration / Struggling:')
    . XlsxHelper::formulaCell("C{$rFru}", 3, "COUNTIF(H8:H{$lastDataRow}, \"*Frustration*\")", $counts['Frustration'])
    . '</row>';
$rowsXml[] = '<row r="' . $rIns . '" spans="1:10" x14ac:dyDescent="0.3">'
    . XlsxHelper::inlineStrCell("B{$rIns}", 4, 'Total Instructional:')
    . XlsxHelper::formulaCell("C{$rIns}", 3, "COUNTIF(H8:H{$lastDataRow}, \"*Instructional*\")", $counts['Instructional'])
    . '</row>';
$rowsXml[] = '<row r="' . $rInd . '" spans="1:10" x14ac:dyDescent="0.3">'
    . XlsxHelper::inlineStrCell("B{$rInd}", 4, 'Total Independent:')
    . XlsxHelper::formulaCell("C{$rInd}", 3, "COUNTIF(H8:H{$lastDataRow}, \"*Independent*\")", $counts['Independent'])
    . '</row>';

$sheet1Xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
    . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
    . 'xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" mc:Ignorable="x14ac xr xr2 xr3" '
    . 'xmlns:x14ac="http://schemas.microsoft.com/office/spreadsheetml/2009/9/ac" '
    . 'xmlns:xr="http://schemas.microsoft.com/office/spreadsheetml/2014/revision" '
    . 'xmlns:xr2="http://schemas.microsoft.com/office/spreadsheetml/2015/revision2" '
    . 'xmlns:xr3="http://schemas.microsoft.com/office/spreadsheetml/2016/revision3" '
    . 'xr:uid="{00000000-0001-0000-0000-000000000000}">'
    . '<dimension ref="A1:J' . $lastSheetRow . '"/>'
    . '<sheetViews><sheetView tabSelected="1" workbookViewId="0"><selection activeCell="A8" sqref="A8"/></sheetView></sheetViews>'
    . '<sheetFormatPr defaultRowHeight="14.4" x14ac:dyDescent="0.3"/>'
    . '<cols>'
    . '<col min="1" max="1" width="10" customWidth="1"/>'
    . '<col min="2" max="2" width="32" customWidth="1"/>'
    . '<col min="3" max="4" width="10" customWidth="1"/>'
    . '<col min="5" max="5" width="26" customWidth="1"/>'
    . '<col min="6" max="7" width="16" customWidth="1"/>'
    . '<col min="8" max="8" width="24" customWidth="1"/>'
    . '<col min="9" max="10" width="36" customWidth="1"/>'
    . '</cols>'
    . '<sheetData>' . implode('', $rowsXml) . '</sheetData>'
    . '<pageMargins left="0.75" right="0.75" top="1" bottom="1" header="0.5" footer="0.5"/>'
    . '</worksheet>';

$entries['xl/worksheets/sheet1.xml'] = $sheet1Xml;

// Remove calcChain.xml so Excel recalculates formulas cleanly on open
unset($entries['xl/calcChain.xml']);
if (isset($entries['[Content_Types].xml'])) {
    $entries['[Content_Types].xml'] = preg_replace('/<Override[^>]+calcChain\.xml[^>]*\/>/i', '', $entries['[Content_Types].xml']);
}
if (isset($entries['xl/_rels/workbook.xml.rels'])) {
    $entries['xl/_rels/workbook.xml.rels'] = preg_replace('/<Relationship[^>]+calcChain\.xml[^>]*\/>/i', '', $entries['xl/_rels/workbook.xml.rels']);
}

$xlsxBinary = XlsxHelper::buildZipBinary($entries);

// Generate Filename
$filename_parts = ['ARAL_Pre_Assessment_Report'];
if (!empty($filter_grade)) $filename_parts[] = str_replace(' ', '', $filter_grade);
if (!empty($filter_section)) $filename_parts[] = str_replace(' ', '', $filter_section);
if (!empty($filter_status)) $filename_parts[] = str_replace(' ', '', $filter_status);
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
