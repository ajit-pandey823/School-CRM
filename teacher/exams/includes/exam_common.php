<?php

/*
|--------------------------------------------------------------------------
| TEACHER - EXAMS MODULE : SHARED BOOTSTRAP + HELPERS
|--------------------------------------------------------------------------
| Read only module. Used by:
|     teacher/exams/index.php         (Exam Overview + Filters + Exam List)
|     teacher/exams/view.php          (Exam Details + Teacher Actions)
|     teacher/exams/syllabus.php      (View / Download Syllabus)
|     teacher/exams/schedule.php      (View Schedule)
|     teacher/exams/results.php       (View Marks Status)
|
| Flow: Dashboard > Exams > Exam List > Exam Details > Enter Marks
|
| Tables used
|     exam_schedule  : per exam + class + section + subject details
|                      (created by exam_module_setup.sql)
|     exams, teacher_subjects, classes, sections, subjects, academic_years
|     students, student_academic, student_marks   (read only)
|
| The module is strictly read only for the teacher: no exam is added,
| edited or deleted and no schedule is changed. No existing page or table
| is modified by this module.
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../../../function/function.php";
require_once __DIR__ . "/../../../auth.php";

authRequireType($conn, ["teacher"], "../../login.php", "../../");

if (!isset($_SESSION["user_uid"])) {
    header("Location: ../../login.php");
    exit();
}

if (($_SESSION["user_type"] ?? "") !== "teacher") {
    header("Location: ../../login.php");
    exit();
}

$school_uid = $_SESSION["school_uid"];
$user_uid   = $_SESSION["user_uid"];

/*
|--------------------------------------------------------------------------
| GET TEACHER
|--------------------------------------------------------------------------
*/

$sql = "SELECT teacher_uid, teacher_name
        FROM teachers
        WHERE school_uid = ?
          AND user_uid = ?
          AND status = 'active'
        LIMIT 1";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Teacher query failed: " . $conn->error);
}

$stmt->bind_param("ss", $school_uid, $user_uid);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$teacher) {
    die("Teacher profile not found.");
}

$teacher_uid  = $teacher["teacher_uid"];
$teacher_name = $teacher["teacher_name"];

/*
|--------------------------------------------------------------------------
| MODULE TABLE CHECK
|--------------------------------------------------------------------------
| Friendly message instead of a raw SQL error when the setup script has not
| been run yet.
*/

$exams_table_check = $conn->query("SHOW TABLES LIKE 'exam_schedule'");

if (!$exams_table_check || $exams_table_check->num_rows === 0) {
    die(
        "The Exams module is not installed yet. "
        . "Please run exam_module_setup.sql on the school_management database."
    );
}

/*
|--------------------------------------------------------------------------
| TEACHER ASSIGNMENTS + FILTER OPTION LISTS
|--------------------------------------------------------------------------
| Only classes / sections / subjects / sessions assigned to this teacher
| are ever listed or accepted as a filter value.
*/

$sql = "SELECT
            ts.class_uid,
            ts.section_uid,
            ts.subject_uid,
            ts.academic_year_uid,
            c.class_name,
            c.numeric_order,
            sec.section_name,
            sub.subject_name,
            COALESCE(ay.year_name, '') AS year_name
        FROM teacher_subjects ts

        INNER JOIN classes c
            ON c.class_uid = ts.class_uid
            AND c.status = 'active'

        INNER JOIN sections sec
            ON sec.section_uid = ts.section_uid
            AND sec.status = 'active'

        INNER JOIN subjects sub
            ON sub.subject_uid = ts.subject_uid
            AND sub.status = 'active'

        LEFT JOIN academic_years ay
            ON ay.academic_year_uid = ts.academic_year_uid

        WHERE ts.school_uid = ?
          AND ts.teacher_uid = ?

        ORDER BY
            c.numeric_order ASC,
            sec.section_name ASC,
            sub.subject_name ASC";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Assignment query failed: " . $conn->error);
}

$stmt->bind_param("ss", $school_uid, $teacher_uid);
$stmt->execute();
$exams_assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$exams_year_options    = [];
$exams_class_options   = [];
$exams_section_options = [];
$exams_subject_options = [];

foreach ($exams_assignments as $exams_assignment) {

    $exams_year_options[$exams_assignment["academic_year_uid"]] = ($exams_assignment["year_name"] !== "")
        ? $exams_assignment["year_name"]
        : $exams_assignment["academic_year_uid"];

    $exams_class_options[$exams_assignment["class_uid"]] = $exams_assignment["class_name"];

    $exams_section_options[$exams_assignment["section_uid"]] = [
        "label"     => $exams_assignment["section_name"],
        "class_uid" => $exams_assignment["class_uid"],
    ];

    $exams_subject_options[$exams_assignment["subject_uid"]] = $exams_assignment["subject_name"];
}

$has_assignments = !empty($exams_assignments);

/*
|--------------------------------------------------------------------------
| EXAMS OF THE TEACHER'S SESSIONS (filter list)
|--------------------------------------------------------------------------
*/

$exams_exam_options = [];

if ($has_assignments) {

    $sql = "SELECT
                exam_uid,
                academic_year_uid,
                exam_name,
                exam_type,
                term
            FROM exams
            WHERE school_uid = ?
              AND status = 'active'
            ORDER BY
                academic_year_uid ASC,
                exam_name ASC";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("Exam query failed: " . $conn->error);
    }

    $stmt->bind_param("s", $school_uid);
    $stmt->execute();

    $exams_master = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($exams_master as $exams_row) {

        /* only exams of a session the teacher actually teaches in */
        if (!isset($exams_year_options[$exams_row["academic_year_uid"]])) {
            continue;
        }

        $exams_exam_options[$exams_row["exam_uid"]] = [
            "label" => $exams_row["exam_name"]
                . (($exams_row["term"] !== null && $exams_row["term"] !== "")
                    ? " (" . $exams_row["term"] . ")"
                    : "")
                . " - " . $exams_year_options[$exams_row["academic_year_uid"]],
        ];
    }
}


/*
|--------------------------------------------------------------------------
| STATUS + FORMAT HELPERS
|--------------------------------------------------------------------------
*/

/*
| Exam status is derived from the exam date so the teacher always sees the
| live state: Cancelled > Scheduled (no date) > Upcoming > Ongoing > Completed.
*/
function examsScheduleStatus($row)
{
    $stored = strtolower(trim((string)($row["schedule_status"] ?? "")));

    if ($stored === "cancelled") {
        return ["key" => "cancelled", "label" => "Cancelled", "css" => "badge-cancelled"];
    }

    $exam_date = trim((string)($row["exam_date"] ?? ""));
    $today     = date("Y-m-d");

    if ($exam_date === "" || $exam_date === "0000-00-00") {
        return ["key" => "scheduled", "label" => "Scheduled", "css" => "badge-scheduled"];
    }

    if ($exam_date > $today) {
        return ["key" => "upcoming", "label" => "Upcoming", "css" => "badge-upcoming"];
    }

    if ($exam_date === $today) {
        return ["key" => "ongoing", "label" => "Ongoing", "css" => "badge-ongoing"];
    }

    return ["key" => "completed", "label" => "Completed", "css" => "badge-completed"];
}

function examsDateLabel($value)
{
    $value = trim((string)$value);

    if ($value === "" || $value === "0000-00-00") {
        return "-";
    }

    $stamp = strtotime($value);

    return ($stamp === false) ? $value : date("d M Y", $stamp);
}

function examsTimeLabel($value)
{
    $value = trim((string)$value);

    if ($value === "" || $value === "00:00:00") {
        return "-";
    }

    $stamp = strtotime($value);

    return ($stamp === false) ? $value : date("h:i A", $stamp);
}

function examsDurationLabel($minutes)
{
    $minutes = (int)$minutes;

    if ($minutes <= 0) {
        return "-";
    }

    $hours = intdiv($minutes, 60);
    $mins  = $minutes % 60;

    if ($hours > 0 && $mins > 0) {
        return $hours . " hr " . $mins . " min";
    }

    if ($hours > 0) {
        return $hours . " hr";
    }

    return $mins . " min";
}

function examsNumberLabel($value)
{
    if ($value === null || $value === "") {
        return "-";
    }

    return rtrim(rtrim(number_format((float)$value, 2, ".", ""), "0"), ".");
}

/*
|--------------------------------------------------------------------------
| FIND THE TEACHER'S SCHEDULE UID FOR ONE EXAM + CLASS + SECTION + SUBJECT
|--------------------------------------------------------------------------
*/

function examsFindScheduleUid($conn, $school_uid, $teacher_uid, $exam_uid, $class_uid, $section_uid, $subject_uid, $academic_year_uid)
{
    $sql = "SELECT es.schedule_uid
            FROM exam_schedule es

            INNER JOIN teacher_subjects ts
                ON ts.school_uid = es.school_uid
                AND ts.teacher_uid = ?
                AND ts.class_uid = es.class_uid
                AND ts.section_uid = es.section_uid
                AND ts.subject_uid = es.subject_uid
                AND ts.academic_year_uid = es.academic_year_uid

            WHERE es.school_uid = ?
              AND es.exam_uid = ?
              AND es.class_uid = ?
              AND es.section_uid = ?
              AND es.subject_uid = ?
              AND es.academic_year_uid = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("Schedule lookup failed: " . $conn->error);
    }

    $stmt->bind_param(
        "sssssss",
        $teacher_uid,
        $school_uid,
        $exam_uid,
        $class_uid,
        $section_uid,
        $subject_uid,
        $academic_year_uid
    );
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (string)$row["schedule_uid"] : "";
}


/*
|--------------------------------------------------------------------------
| FETCH ONE SCHEDULE ROW (validated against the teacher's assignments)
|--------------------------------------------------------------------------
| Returns null when the schedule does not exist or does not belong to the
| logged in teacher.
*/

function examsFetchSchedule($conn, $school_uid, $teacher_uid, $schedule_uid)
{
    $schedule_uid = trim((string)$schedule_uid);

    if ($schedule_uid === "") {
        return null;
    }

    $sql = "SELECT
                es.schedule_uid,
                es.exam_uid,
                es.class_uid,
                es.section_uid,
                es.subject_uid,
                es.academic_year_uid,
                es.exam_date,
                es.start_time,
                es.duration_minutes,
                es.max_marks,
                es.passing_marks,
                es.syllabus,
                es.instructions,
                es.status AS schedule_status,
                e.exam_name,
                e.exam_type,
                e.term,
                c.class_name,
                c.numeric_order,
                sec.section_name,
                sub.subject_name,
                COALESCE(ay.year_name, '') AS year_name
            FROM exam_schedule es

            INNER JOIN exams e
                ON e.exam_uid = es.exam_uid
                AND e.status = 'active'

            INNER JOIN classes c
                ON c.class_uid = es.class_uid

            INNER JOIN sections sec
                ON sec.section_uid = es.section_uid

            INNER JOIN subjects sub
                ON sub.subject_uid = es.subject_uid

            LEFT JOIN academic_years ay
                ON ay.academic_year_uid = es.academic_year_uid

            /* must be a class / section / subject assigned to this teacher */
            INNER JOIN teacher_subjects ts
                ON ts.school_uid = es.school_uid
                AND ts.teacher_uid = ?
                AND ts.class_uid = es.class_uid
                AND ts.section_uid = es.section_uid
                AND ts.subject_uid = es.subject_uid
                AND ts.academic_year_uid = es.academic_year_uid

            WHERE es.school_uid = ?
              AND es.schedule_uid = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("Exam detail query failed: " . $conn->error);
    }

    $stmt->bind_param("sss", $teacher_uid, $school_uid, $schedule_uid);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/*
|--------------------------------------------------------------------------
| STUDENTS OF A CLASS + SECTION + SESSION
|--------------------------------------------------------------------------
*/

function examsFetchStudents($conn, $school_uid, $class_uid, $section_uid, $academic_year_uid)
{
    $sql = "SELECT
                s.student_uid,
                s.student_name,
                s.admission_no,
                sa.roll_no
            FROM student_academic sa
            INNER JOIN students s
                ON s.student_uid = sa.student_uid
            WHERE sa.school_uid = ?
              AND sa.class_uid = ?
              AND sa.section_uid = ?
              AND sa.academic_year_uid = ?
              AND sa.status = 'active'
              AND s.status = 'active'
            ORDER BY
                sa.roll_no,
                s.student_name";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("Student query failed: " . $conn->error);
    }

    $stmt->bind_param("ssss", $school_uid, $class_uid, $section_uid, $academic_year_uid);
    $stmt->execute();

    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $students;
}


/*
|--------------------------------------------------------------------------
| SAVED MARKS OF ONE EXAM + SUBJECT + CLASS + SECTION + SESSION
|--------------------------------------------------------------------------
*/

function examsFetchMarks($conn, $school_uid, $exam_uid, $subject_uid, $class_uid, $section_uid, $academic_year_uid)
{
    $sql = "SELECT
                student_uid,
                max_marks,
                marks_obtained,
                is_absent,
                is_exempted,
                grade,
                entry_status
            FROM student_marks
            WHERE school_uid = ?
              AND exam_uid = ?
              AND subject_uid = ?
              AND class_uid = ?
              AND section_uid = ?
              AND academic_year_uid = ?";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("Marks query failed: " . $conn->error);
    }

    $stmt->bind_param(
        "ssssss",
        $school_uid,
        $exam_uid,
        $subject_uid,
        $class_uid,
        $section_uid,
        $academic_year_uid
    );
    $stmt->execute();

    $marks  = [];
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $marks[$row["student_uid"]] = $row;
    }

    $stmt->close();

    return $marks;
}

/*
|--------------------------------------------------------------------------
| MARKS STATUS BUNDLE (students + saved marks + summary counts)
|--------------------------------------------------------------------------
*/

function examsMarksStatusBundle($conn, $school_uid, $exam_uid, $subject_uid, $class_uid, $section_uid, $academic_year_uid)
{
    $students = examsFetchStudents($conn, $school_uid, $class_uid, $section_uid, $academic_year_uid);
    $saved    = examsFetchMarks($conn, $school_uid, $exam_uid, $subject_uid, $class_uid, $section_uid, $academic_year_uid);

    $bundle = [
        "rows"         => [],
        "total"        => 0,
        "entered"      => 0,
        "pending"      => 0,
        "absent"       => 0,
        "exempted"     => 0,
        "draft"        => 0,
        "submitted"    => 0,
        "max_marks"    => 0,
        "status_label" => "Not Submitted",
    ];

    foreach ($students as $student) {

        $student_uid = $student["student_uid"];
        $row         = $saved[$student_uid] ?? null;

        $marks_value = null;
        $grade       = "";
        $is_absent   = false;
        $is_exempt   = false;
        $entry       = "pending";

        if ($row) {

            if ($row["marks_obtained"] !== null) {
                $marks_value = (float)$row["marks_obtained"];
            }

            $grade     = (string)$row["grade"];
            $is_absent = (int)$row["is_absent"] === 1;
            $is_exempt = (int)$row["is_exempted"] === 1;
            $entry     = (string)$row["entry_status"];

            if ((float)$row["max_marks"] > 0) {
                $bundle["max_marks"] = (float)$row["max_marks"];
            }
        }

        if ($entry === "pending") {
            $bundle["pending"]++;
        } else {
            $bundle["entered"]++;
        }

        if ($entry === "draft") {
            $bundle["draft"]++;
        } elseif ($entry === "submitted") {
            $bundle["submitted"]++;
        }

        if ($is_absent) {
            $bundle["absent"]++;
        }

        if ($is_exempt) {
            $bundle["exempted"]++;
        }

        $bundle["rows"][] = [
            "student_uid"  => $student_uid,
            "roll_no"      => (string)$student["roll_no"],
            "student_name" => (string)$student["student_name"],
            "admission_no" => (string)$student["admission_no"],
            "marks"        => $marks_value,
            "grade"        => $grade,
            "is_absent"    => $is_absent,
            "is_exempt"    => $is_exempt,
            "entry_status" => $entry,
        ];
    }

    $bundle["total"] = count($bundle["rows"]);

    if ($bundle["total"] > 0
        && $bundle["entered"] === $bundle["total"]
        && $bundle["submitted"] === $bundle["total"]
    ) {
        $bundle["status_label"] = "Submitted";
    } elseif ($bundle["entered"] > 0) {
        $bundle["status_label"] = "In Progress";
    }

    return $bundle;
}


/*
|--------------------------------------------------------------------------
| SHARED STYLES (same look and feel as Attendance / Marks Entry pages)
|--------------------------------------------------------------------------
*/

function examsModuleStyles()
{
    return <<<'CSS'

        body {
            font-family: Arial, sans-serif;
            background: #f5f6fa;
            margin: 0;
            padding: 30px;
        }

        .container {
            max-width: 1250px;
            margin: auto;
        }

        .card {
            background: #fff;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,.08);
        }

        h1, h2, h3 {
            margin-top: 0;
        }

        .teacher-info {
            color: #555;
            margin-bottom: 10px;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 15px;
            color: #007bff;
            text-decoration: none;
        }

        .muted {
            color: #6b7280;
            font-size: 13px;
        }

        .success {
            background: #d4edda;
            color: #155724;
            padding: 12px;
            margin-bottom: 15px;
            border-radius: 5px;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            margin-bottom: 15px;
            border-radius: 5px;
        }

        .empty {
            padding: 20px;
            background: #fff3cd;
            border-radius: 6px;
        }

        .notice {
            background: #e7f1ff;
            border-left: 4px solid #0d6efd;
            color: #084298;
            padding: 12px 15px;
            border-radius: 6px;
            font-size: 14px;
        }

        /* ---------- OVERVIEW CARDS ---------- */

        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 14px;
        }

        .summary-card {
            background: #f8f9fc;
            border: 1px solid #e6e9f0;
            border-radius: 8px;
            padding: 16px;
        }

        .summary-card span {
            display: block;
            font-size: 12px;
            color: #666;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: .3px;
        }

        .summary-card strong {
            font-size: 24px;
        }

        .summary-card.accent-total     { border-left: 4px solid #0d6efd; }
        .summary-card.accent-upcoming  { border-left: 4px solid #0d6efd; }
        .summary-card.accent-ongoing   { border-left: 4px solid #fd7e14; }
        .summary-card.accent-completed { border-left: 4px solid #198754; }

        /* ---------- FILTERS ---------- */

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 15px;
        }

        .filter-grid label {
            display: block;
            font-size: 13px;
            font-weight: bold;
            color: #444;
            margin-bottom: 5px;
        }

        .filter-grid select,
        .filter-grid input {
            width: 100%;
            padding: 9px;
            border: 1px solid #ccc;
            border-radius: 6px;
            box-sizing: border-box;
            background: #fff;
        }

        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            margin-top: 18px;
        }

        /* ---------- BUTTONS ---------- */

        .btn {
            display: inline-block;
            padding: 10px 16px;
            border: none;
            border-radius: 6px;
            background: #0d6efd;
            color: #fff;
            text-decoration: none;
            font-size: 14px;
            cursor: pointer;
        }

        .btn:hover { background: #0b5ed7; }

        .btn-secondary { background: #6c757d; }
        .btn-secondary:hover { background: #5c636a; }

        .btn-green { background: #198754; }
        .btn-green:hover { background: #157347; }

        .btn-outline {
            background: #fff;
            color: #0d6efd;
            border: 1px solid #0d6efd;
        }

        .btn-outline:hover { background: #eaf2ff; }

        /* ---------- TEACHER ACTIONS (read only) ---------- */

        .actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(215px, 1fr));
            gap: 12px;
        }

        .action-btn {
            display: block;
            padding: 14px 16px;
            border: 1px solid #d9e2ec;
            border-radius: 8px;
            background: #f8f9fc;
            color: #1b3a57;
            text-decoration: none;
            font-size: 14px;
            font-weight: bold;
        }

        .action-btn:hover {
            background: #eaf2ff;
            border-color: #0d6efd;
        }

        .action-btn .hint {
            display: block;
            font-weight: normal;
            font-size: 12px;
            color: #6b7280;
            margin-top: 4px;
        }

        .action-btn.is-current {
            border-color: #0d6efd;
            background: #eaf2ff;
        }

        /* ---------- TABLE ---------- */

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 10px;
            border-bottom: 1px solid #eee;
            text-align: left;
            font-size: 14px;
            vertical-align: middle;
        }

        th {
            background: #f1f1f1;
            font-size: 13px;
        }

        tbody tr:hover {
            background: #fafbfd;
        }

        /* Action buttons inside one exam-list row.
           They are stacked vertically so the 10-column table stays readable
           instead of stretching very wide. */
        td.row-actions {
            white-space: nowrap;
        }

        td.row-actions .btn {
            display: block;
            margin-bottom: 6px;
            text-align: center;
        }

        td.row-actions .btn:last-child {
            margin-bottom: 0;
        }

        /* ---------- BADGES ---------- */

        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            color: #fff;
            background: #6c757d;
        }

        .badge-upcoming  { background: #0d6efd; }
        .badge-ongoing   { background: #fd7e14; }
        .badge-completed { background: #198754; }
        .badge-scheduled { background: #6c757d; }
        .badge-cancelled { background: #dc3545; }
        .badge-pending   { background: #adb5bd; }
        .badge-draft     { background: #ffc107; color: #4d3b00; }
        .badge-submitted { background: #198754; }

        /* ---------- EXAM DETAILS ---------- */

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 14px;
        }

        .detail-item {
            background: #f8f9fc;
            border: 1px solid #e6e9f0;
            border-radius: 8px;
            padding: 12px 14px;
        }

        .detail-item span {
            display: block;
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: .3px;
        }

        .detail-item strong {
            font-size: 15px;
        }

        .detail-block {
            background: #f8f9fc;
            border: 1px solid #e6e9f0;
            border-radius: 8px;
            padding: 16px;
            margin-top: 14px;
        }

        .detail-block h3 {
            margin-bottom: 10px;
        }

        .prewrap {
            white-space: pre-wrap;
            font-family: Arial, sans-serif;
            font-size: 14px;
            line-height: 1.6;
            margin: 0;
            color: #333;
        }

        .grade-box {
            display: inline-block;
            min-width: 42px;
            padding: 5px 10px;
            background: #eef2f7;
            border-radius: 5px;
            font-weight: bold;
            text-align: center;
        }

        /* ---------- PRINT ---------- */

        @media print {

            body {
                background: #fff;
                padding: 0;
            }

            .card {
                box-shadow: none;
                border: 1px solid #ddd;
            }

            .no-print {
                display: none !important;
            }
        }

CSS;
}

