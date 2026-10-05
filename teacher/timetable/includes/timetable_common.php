<?php

/*
 |--------------------------------------------------------------------------
 | TEACHER - CLASS TIMETABLE : SHARED BOOTSTRAP + HELPERS
 |--------------------------------------------------------------------------
 | Read only module. Used by:
 |     teacher/timetable/index.php  (Weekly grid + Day view + filters)
 |     teacher/timetable/view.php   (Period details)
 |     teacher/timetable/print.php  (Print / download friendly view)
 |
 | Flow: Dashboard > Class Timetable > Weekly View > Select Day > Period
 |
 | Tables used
 |     timetable_periods : period / break structure of a session
 |     timetable_slots   : subject + teacher + room per
 |                        session + class + section + day + period
 |                        (both created by timetable_module_setup.sql)
 |     teachers, teacher_subjects, classes, sections, subjects, academic_years
 |
 | The module is strictly read only for the teacher: a period is never added,
 | edited or deleted, and neither the teacher nor the period times can be
 | changed from here. No existing page or table is modified by this module.
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

$timetable_table_check = $conn->query("SHOW TABLES LIKE 'timetable_periods'");

if (!$timetable_table_check || $timetable_table_check->num_rows === 0) {
    die(
        "The Class Timetable module is not installed yet. "
        . "Please run timetable_module_setup.sql on the school_management database."
    );
}


/*
 |--------------------------------------------------------------------------
 | TEACHER ASSIGNMENTS + FILTER OPTION LISTS
 |--------------------------------------------------------------------------
 | Only classes / sections / subjects / sessions assigned to this teacher are
 | ever listed or accepted as a filter value.
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
$tt_assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$tt_year_options    = [];
$tt_class_options   = [];
$tt_section_options = [];
$tt_subject_options = [];

foreach ($tt_assignments as $tt_assignment) {

    $tt_year_options[$tt_assignment["academic_year_uid"]] = ($tt_assignment["year_name"] !== "")
        ? $tt_assignment["year_name"]
        : $tt_assignment["academic_year_uid"];

    $tt_class_options[$tt_assignment["class_uid"]] = $tt_assignment["class_name"];

    $tt_section_options[$tt_assignment["section_uid"]] = [
        "label"     => $tt_assignment["section_name"],
        "class_uid" => $tt_assignment["class_uid"],
    ];

    $tt_subject_options[$tt_assignment["subject_uid"]] = $tt_assignment["subject_name"];
}

$has_assignments = !empty($tt_assignments);


/*
 |--------------------------------------------------------------------------
 | DAY LABELS
 |--------------------------------------------------------------------------
 | The school week runs Monday (1) to Saturday (6). Sunday is not part of
 | the timetable, so date('N') = 7 is folded back to Monday.
 */

$tt_days = [
    1 => "Monday",
    2 => "Tuesday",
    3 => "Wednesday",
    4 => "Thursday",
    5 => "Friday",
    6 => "Saturday",
];

$tt_current_day = (int)date("N");

if ($tt_current_day === 7) {
    $tt_current_day = 1;
}

$tt_current_date = date("d M Y");



/*
 |--------------------------------------------------------------------------
 | PERIOD / SLOT HELPERS
 |--------------------------------------------------------------------------
 */

/* "09:00 AM" style label, "-" when the time is missing */
function ttTimeLabel($value)
{
    $value = trim((string)$value);

    if ($value === "" || $value === "00:00:00") {
        return "-";
    }

    $stamp = strtotime($value);

    return ($stamp === false) ? $value : date("h:i A", $stamp);
}

/* "09:00 AM - 09:40 AM" */
function ttPeriodTimeLabel($start, $end)
{
    return ttTimeLabel($start) . " - " . ttTimeLabel($end);
}

function ttDayLabel($day_no)
{
    global $tt_days;

    $day_no = (int)$day_no;

    return isset($tt_days[$day_no]) ? $tt_days[$day_no] : "-";
}

/*
 | A period that is not a "subject" period is a break. The label comes from
 | timetable_periods (Short Break / Lunch Break / Activity Period) and, if a
 | break somehow has no period row, from this fallback.
 */
function ttPeriodTypeLabel($period_type, $period_label)
{
    $period_type = strtolower(trim((string)$period_type));
    $period_label = trim((string)$period_label);

    switch ($period_type) {
        case "short_break":
            return ($period_label !== "") ? $period_label : "Short Break";

        case "lunch_break":
            return ($period_label !== "") ? $period_label : "Lunch Break";

        case "activity":
            return ($period_label !== "") ? $period_label : "Activity Period";

        case "free":
            return ($period_label !== "") ? $period_label : "Free Period";
    }

    return "Period";
}

/* CSS class for a period / slot cell, used to colour the weekly grid */
function ttPeriodCss($period_type)
{
    switch (strtolower(trim((string)$period_type))) {
        case "short_break":
            return "cell-break";

        case "lunch_break":
            return "cell-lunch";

        case "activity":
            return "cell-activity";

        case "free":
            return "cell-free";
    }

    return "cell-subject";
}

/*
 | Is this the period that is running right now?
 |
 | Only the current day of the current week can be "ongoing". A break counts
 | as ongoing too, so the highlight always matches whatever is on the clock.
 */
function ttIsOngoing($day_no, $start_time, $end_time)
{
    global $tt_current_day;

    if ((int)$day_no !== (int)$tt_current_day) {
        return false;
    }

    $start = trim((string)$start_time);
    $end   = trim((string)$end_time);

    if ($start === "" || $end === "") {
        return false;
    }

    $now        = time();
    $start_at   = strtotime($start);
    $end_at     = strtotime($end);

    if ($start_at === false || $end_at === false) {
        return false;
    }

    return ($now >= $start_at && $now < $end_at);
}



/*
 |--------------------------------------------------------------------------
 | DATA LOADERS
 |--------------------------------------------------------------------------
 */

/*
 | All period rows of one session, in timetable order. These rows become the
 | grid rows; a break row is rendered as a break even when it has no slot.
 */
function ttFetchPeriods($conn, $school_uid, $academic_year_uid)
{
    $sql = "SELECT
                period_no,
                period_label,
                period_type,
                start_time,
                end_time
            FROM timetable_periods
            WHERE school_uid = ?
              AND academic_year_uid = ?
              AND status = 'active'
            ORDER BY
                start_time ASC,
                period_no ASC";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("Period query failed: " . $conn->error);
    }

    $stmt->bind_param("ss", $school_uid, $academic_year_uid);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/*
 | One section's slots for one session, indexed by "day|period" so the grid can
 | look up a cell in constant time.
 |
 | $days limits the columns when the Day view is used; pass [] for the full
 | Monday..Saturday week.
 |
 | $subject_uid is an optional filter used by the "Subject" filter - when it is
 | set, only slots of that subject are returned.
 */
function ttFetchSlots($conn, $school_uid, $academic_year_uid, $class_uid, $section_uid, $days = [], $subject_uid = "")
{
    if ($days !== [] && !in_array(1, $days, true) && !in_array(2, $days, true)
        && !in_array(3, $days, true) && !in_array(4, $days, true)
        && !in_array(5, $days, true) && !in_array(6, $days, true)) {
        return [];
    }

    $sql = "SELECT
                s.slot_uid,
                s.day_of_week,
                s.period_no,
                s.subject_uid,
                s.teacher_uid,
                s.room_no,
                s.note,
                COALESCE(sub.subject_name, '') AS subject_name,
                COALESCE(t.teacher_name, '')    AS teacher_name
            FROM timetable_slots s

            LEFT JOIN subjects sub
                ON sub.subject_uid = s.subject_uid

            LEFT JOIN teachers t
                ON t.teacher_uid = s.teacher_uid

            WHERE s.school_uid = ?
              AND s.academic_year_uid = ?
              AND s.class_uid = ?
              AND s.section_uid = ?
              AND s.status = 'active'";

    $bind_types  = "ssss";
    $bind_values = [$school_uid, $academic_year_uid, $class_uid, $section_uid];

    if ($days !== []) {

        $day_placeholders = implode(",", array_fill(0, count($days), "?"));
        $sql             .= " AND s.day_of_week IN (" . $day_placeholders . ")";

        $bind_types .= str_repeat("i", count($days));

        foreach ($days as $day) {
            $bind_values[] = (int)$day;
        }
    }

    if ($subject_uid !== "") {
        $sql            .= " AND s.subject_uid = ?";
        $bind_types     .= "s";
        $bind_values[]   = $subject_uid;
    }

    $sql .= " ORDER BY
                s.day_of_week ASC,
                s.period_no ASC";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("Timetable slot query failed: " . $conn->error);
    }

    $stmt->bind_param($bind_types, ...$bind_values);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    /* index by "day|period" for O(1) grid lookups */
    $indexed = [];

    foreach ($rows as $row) {
        $indexed[$row["day_of_week"] . "|" . $row["period_no"]] = $row;
    }

    return $indexed;
}



/*
 |--------------------------------------------------------------------------
 | SHARED STYLES
 |--------------------------------------------------------------------------
 | Kept in a function so every page of the module looks the same, in the same
 | style as the read only Exams module.
 */
function ttModuleStyles()
{
    return <<<'CSS'

        body {
            font-family: Arial, sans-serif;
            background: #f5f6fa;
            margin: 0;
            padding: 30px;
        }

        .container {
            max-width: 1300px;
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

        /* ---------- TOP INFO STRIP ---------- */

        .top-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .info-box {
            background: #f8f9fc;
            border: 1px solid #e6e9f0;
            border-radius: 8px;
            padding: 14px;
        }

        .info-box span {
            display: block;
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: .3px;
            margin-bottom: 5px;
        }

        .info-box strong {
            font-size: 15px;
        }

        .info-box.is-today {
            border-left: 4px solid #198754;
            background: #eefaf1;
        }

        /* ---------- FILTERS ---------- */

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            font-size: 14px;
            background: #fff;
        }

        /* ---------- BUTTONS ---------- */

        .btn {
            display: inline-block;
            padding: 9px 16px;
            border: 1px solid #0d6efd;
            background: #0d6efd;
            color: #fff;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            cursor: pointer;
        }

        .btn-outline {
            background: #fff;
            color: #0d6efd;
        }

        .btn-secondary {
            background: #6c757d;
            border-color: #6c757d;
            color: #fff;
        }

        .btn-green {
            background: #198754;
            border-color: #198754;
        }

        .toolbar {
            margin-top: 16px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .action-btn {
            display: block;
            border: 1px solid #e6e9f0;
            border-radius: 8px;
            padding: 13px 15px;
            margin-bottom: 10px;
            text-decoration: none;
            color: #222;
            background: #fff;
        }

        .action-btn:hover {
            border-color: #0d6efd;
            background: #f5f9ff;
        }

        .action-btn.is-current {
            border-color: #0d6efd;
            background: #eaf2ff;
        }

        .action-btn .hint {
            display: block;
            font-size: 12px;
            color: #6b7280;
            margin-top: 3px;
        }

        /* ---------- WEEKLY GRID ---------- */

        .grid-scroll {
            overflow-x: auto;
        }

        table.grid {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        table.grid th,
        table.grid td {
            border: 1px solid #e6e9f0;
            padding: 8px;
            vertical-align: top;
            font-size: 13px;
        }

        table.grid thead th {
            background: #f1f3f7;
            text-align: center;
            font-size: 13px;
        }

        table.grid thead th.is-today {
            background: #d9f2e1;
        }

        table.grid tbody th {
            background: #f8f9fc;
            text-align: left;
            white-space: nowrap;
        }

        table.grid tbody th .time {
            display: block;
            font-weight: normal;
            color: #6b7280;
            font-size: 11px;
        }

        .cell {
            min-height: 58px;
        }

        .cell-subject  { background: #fff; }
        .cell-break    { background: #fff7e6; }
        .cell-lunch    { background: #ffe9d6; }
        .cell-activity { background: #eef4ff; }
        .cell-free     { background: #f4f4f6; }

        .cell.is-ongoing {
            outline: 2px solid #198754;
            outline-offset: -2px;
        }

        .cell.is-mine {
            background: #eaf2ff;
        }

        .cell.is-mine.is-ongoing {
            background: #d4f0de;
        }

        .cell .subject {
            font-weight: bold;
            color: #1a1a1a;
        }

        .cell .who {
            color: #555;
            font-size: 12px;
        }

        .cell .break-label {
            font-weight: bold;
            color: #8a6d3b;
        }

        .cell-free .break-label {
            color: #6b7280;
        }

        .now-flag {
            display: inline-block;
            margin-top: 4px;
            padding: 2px 7px;
            border-radius: 10px;
            background: #198754;
            color: #fff;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: .3px;
        }

        /* ---------- DAY VIEW ---------- */

        .day-heading {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 15px;
        }

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

        tr.is-ongoing {
            background: #eefaf1;
        }

        .time-col {
            white-space: nowrap;
            font-weight: bold;
        }

        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            color: #fff;
            background: #6c757d;
        }

        .badge-break    { background: #fd7e14; }
        .badge-lunch    { background: #b45309; }
        .badge-activity { background: #0d6efd; }
        .badge-free     { background: #6c757d; }
        .badge-subject  { background: #198754; }

        .day-links {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
        }

        .day-links a {
            display: inline-block;
            padding: 8px 14px;
            border: 1px solid #ddd;
            border-radius: 20px;
            text-decoration: none;
            color: #333;
            background: #fff;
            font-size: 13px;
        }

        .day-links a.is-today {
            border-color: #198754;
            background: #eefaf1;
        }

        .day-links a.is-active {
            border-color: #0d6efd;
            background: #eaf2ff;
            color: #0d6efd;
            font-weight: bold;
        }

        /* ---------- LEGEND ---------- */

        .legend {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 14px;
            font-size: 12px;
            color: #555;
        }

        .legend span.swatch {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 1px solid #ddd;
            border-radius: 3px;
            vertical-align: -2px;
            margin-right: 5px;
        }

        /* ---------- PRINT ---------- */

        @media print {
            body {
                background: #fff;
                padding: 0;
            }

            .no-print {
                display: none !important;
            }

            .card {
                box-shadow: none;
                border: 1px solid #ddd;
            }

            table.grid {
                min-width: 0;
            }
        }

CSS;
}

