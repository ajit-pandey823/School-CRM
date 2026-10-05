<?php

/*
 |--------------------------------------------------------------------------
 | TEACHER - NOTICES : SHARED BOOTSTRAP + HELPERS
 |--------------------------------------------------------------------------
 | Read only module. Used by:
 |     teacher/notices/index.php    (Summary + Filters + Notice list)
 |     teacher/notices/view.php     (Notice details + Mark as read)
 |     teacher/notices/download.php (Attachment view / download)
 |
 | Flow: Dashboard > Notices > Notice list > Notice details > Download
 |
 | Tables used
 |     notices, notice_attachments, notice_reads
 |                         (created by notice_module_setup.sql)
 |     users, teachers, teacher_subjects, classes, sections  (read only)
 |
 | The module is read only apart from the teacher's OWN read state: opening
 | a notice (or pressing Mark as Read / Unread) writes one notice_reads row
 | for that teacher. A notice is never created, edited, deleted or published
 | from here, and no existing page or table is modified by this module.
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

$notice_table_check = $conn->query("SHOW TABLES LIKE 'notices'");

if (!$notice_table_check || $notice_table_check->num_rows === 0) {
    die(
        "The Notices module is not installed yet. "
        . "Please run notice_module_setup.sql on the school_management database."
    );
}


/*
 |--------------------------------------------------------------------------
 | TEACHER'S CLASSES / SECTIONS
 |--------------------------------------------------------------------------
 | A notice aimed at one class or one section is only shown to a teacher who
 | actually teaches that class / section.
 */

$sql = "SELECT DISTINCT class_uid, section_uid
        FROM teacher_subjects
        WHERE school_uid = ?
          AND teacher_uid = ?
          AND class_uid IS NOT NULL
          AND section_uid IS NOT NULL";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Assignment query failed: " . $conn->error);
}

$stmt->bind_param("ss", $school_uid, $teacher_uid);
$stmt->execute();
$notice_assignment_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$notice_class_uids   = [];
$notice_section_uids = [];

foreach ($notice_assignment_rows as $notice_assignment) {
    $notice_class_uids[]   = $notice_assignment["class_uid"];
    $notice_section_uids[] = $notice_assignment["section_uid"];
}

$has_assignments = !empty($notice_assignment_rows);


/*
 |--------------------------------------------------------------------------
 | NOTICE LOADER
 |--------------------------------------------------------------------------
 | Builds the WHERE clause that keeps a teacher inside their own notices:
 |
 |   all_teachers      - always visible
 |   specific_class    - only if the teacher teaches that class
 |   specific_section  - only if the teacher teaches that section
 |   specific_teacher  - only if it is this teacher
 |
 | Archived and draft notices are never shown.
 */
function noticesVisibilitySql($class_uids, $section_uids, $teacher_uid)
{
    $class_list   = ($class_uids   === []) ? "''" : "'" . implode("','", array_map("addslashes", $class_uids)) . "'";
    $section_list = ($section_uids === []) ? "''" : "'" . implode("','", array_map("addslashes", $section_uids)) . "'";

    return "(
                n.target_type = 'all_teachers'
             OR (n.target_type = 'specific_teacher' AND n.target_teacher_uid = '" . addslashes($teacher_uid) . "')
             OR (n.target_type = 'specific_class'   AND n.target_class_uid   IN (" . $class_list . "))
             OR (n.target_type = 'specific_section' AND n.target_section_uid IN (" . $section_list . "))
            )";
}

/*
 | All notices meant for this teacher, newest first (Urgent, then Important,
 | then Normal).
 |
 | $is_expired picks the list to load: active notices, or the ones whose
 | expiry date has already passed.
 */
function noticesFetchList(
    $conn,
    $school_uid,
    $teacher_uid,
    $class_uids,
    $section_uids,
    $is_expired
) {
    $sql = "SELECT
                n.notice_uid,
                n.title,
                n.short_description,
                n.category,
                n.priority,
                n.target_type,
                n.target_class_uid,
                n.target_section_uid,
                n.published_by_name,
                n.publish_date,
                n.publish_time,
                n.expiry_date,
                n.is_pinned,
                COALESCE(c.class_name, '')     AS class_name,
                COALESCE(sec.section_name, '') AS section_name,
                r.read_at
            FROM notices n

            LEFT JOIN classes c
                ON c.class_uid = n.target_class_uid

            LEFT JOIN sections sec
                ON sec.section_uid = n.target_section_uid

            LEFT JOIN notice_reads r
                ON r.notice_uid = n.notice_uid
               AND r.teacher_uid = ?

            WHERE n.school_uid = ?
              AND n.status = 'published'
              AND " . noticesVisibilitySql($class_uids, $section_uids, $teacher_uid) . "
              AND " . ($is_expired
                    ? "(n.expiry_date IS NOT NULL AND n.expiry_date < CURDATE())"
                    : "(n.expiry_date IS NULL OR n.expiry_date >= CURDATE())") . "

            ORDER BY
                FIELD(n.priority, 'Urgent', 'Important', 'Normal'),
                n.is_pinned DESC,
                n.publish_date DESC,
                n.publish_time DESC";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("Notice query failed: " . $conn->error);
    }

    $stmt->bind_param("ss", $teacher_uid, $school_uid);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}



/*
 | One notice, only if it is meant for this teacher.
 */
function noticesFetchOne($conn, $school_uid, $teacher_uid, $class_uids, $section_uids, $notice_uid)
{
    $sql = "SELECT
                n.notice_uid,
                n.title,
                n.short_description,
                n.content,
                n.category,
                n.priority,
                n.target_type,
                n.target_class_uid,
                n.target_section_uid,
                n.target_teacher_uid,
                n.published_by_user_uid,
                n.published_by_name,
                n.publish_date,
                n.publish_time,
                n.expiry_date,
                COALESCE(c.class_name, '')     AS class_name,
                COALESCE(sec.section_name, '') AS section_name,
                COALESCE(pu.name, '')          AS author_name,
                r.read_at
            FROM notices n

            LEFT JOIN classes c
                ON c.class_uid = n.target_class_uid

            LEFT JOIN sections sec
                ON sec.section_uid = n.target_section_uid

            LEFT JOIN users pu
                ON pu.user_uid = n.published_by_user_uid

            LEFT JOIN notice_reads r
                ON r.notice_uid = n.notice_uid
               AND r.teacher_uid = ?

            WHERE n.notice_uid = ?
              AND n.school_uid = ?
              AND n.status = 'published'
              AND " . noticesVisibilitySql($class_uids, $section_uids, $teacher_uid) . "
            LIMIT 1";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("Notice query failed: " . $conn->error);
    }

    $stmt->bind_param("sss", $teacher_uid, $notice_uid, $school_uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/* Attachments of one notice. */
function noticesFetchAttachments($conn, $school_uid, $notice_uid)
{
    $sql = "SELECT attachment_uid, file_name, file_path, file_type, file_size, mime_type
            FROM notice_attachments
            WHERE school_uid = ?
              AND notice_uid = ?
            ORDER BY id ASC";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("Attachment query failed: " . $conn->error);
    }

    $stmt->bind_param("ss", $school_uid, $notice_uid);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/*
 | Marks a notice read or unread for THIS teacher only.
 | Returns true when the read state was actually changed.
 */
function noticesSetRead($conn, $school_uid, $teacher_uid, $notice_uid, $as_read)
{
    if ($as_read) {

        /* INSERT IGNORE keeps the very first read timestamp */
        $sql = "INSERT IGNORE INTO notice_reads (school_uid, notice_uid, teacher_uid)
                VALUES (?, ?, ?)";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("sss", $school_uid, $notice_uid, $teacher_uid);
        $ok = $stmt->execute();
        $stmt->close();

        return (bool)$ok;
    }

    $sql = "DELETE FROM notice_reads
            WHERE school_uid = ?
              AND notice_uid = ?
              AND teacher_uid = ?";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("sss", $school_uid, $notice_uid, $teacher_uid);
    $ok = $stmt->execute();
    $stmt->close();

    return (bool)$ok;
}



/*
 |--------------------------------------------------------------------------
 | FORMAT HELPERS
 |--------------------------------------------------------------------------
 */

function noticesDateLabel($value)
{
    $value = trim((string)$value);

    if ($value === "" || $value === "0000-00-00") {
        return "-";
    }

    $stamp = strtotime($value);

    return ($stamp === false) ? $value : date("d M Y", $stamp);
}

function noticesTimeLabel($value)
{
    $value = trim((string)$value);

    if ($value === "" || $value === "00:00:00") {
        return "";
    }

    $stamp = strtotime($value);

    return ($stamp === false) ? $value : date("h:i A", $stamp);
}

/* CSS class for a priority badge */
function noticesPriorityCss($priority)
{
    switch (strtolower(trim((string)$priority))) {
        case "urgent":
            return "priority-urgent";

        case "important":
            return "priority-important";
    }

    return "priority-normal";
}

/* CSS class for a category chip */
function noticesCategoryCss($category)
{
    $slug = strtolower(preg_replace("/[^a-z0-9]+/i", "-", trim((string)$category)));

    return "cat-" . trim($slug, "-");
}

/* "All Teachers" / "Class 1" / "Section A" / a single teacher */
function noticesTargetLabel($row)
{
    switch ((string)$row["target_type"]) {
        case "specific_class":
            return (($row["class_name"] ?? "") !== "")
                ? $row["class_name"]
                : "Specific Class";

        case "specific_section":
            return (($row["section_name"] ?? "") !== "")
                ? $row["section_name"]
                : "Specific Section";

        case "specific_teacher":
            return "Only Me";
    }

    return "All Teachers";
}

/* is this row already read by this teacher? */
function noticesIsRead($row)
{
    return trim((string)($row["read_at"] ?? "")) !== "";
}

/* has the notice passed its expiry date? */
function noticesIsExpired($row)
{
    $expiry = trim((string)($row["expiry_date"] ?? ""));

    if ($expiry === "" || $expiry === "0000-00-00") {
        return false;
    }

    return ($expiry < date("Y-m-d"));
}

/* readable file size, e.g. "1.2 KB" */
function noticesFileSize($bytes)
{
    $bytes = (int)$bytes;

    if ($bytes <= 0) {
        return "-";
    }

    if ($bytes < 1024) {
        return $bytes . " B";
    }

    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . " KB";
    }

    return round($bytes / 1048576, 1) . " MB";
}

/* icon + label for an attachment type */
function noticesFileIcon($file_type)
{
    switch (strtolower(trim((string)$file_type))) {
        case "pdf":
            return "📕";

        case "image":
            return "🖼️";

        case "document":
            return "📄";
    }

    return "📎";
}

function noticesFileTypeLabel($file_type)
{
    switch (strtolower(trim((string)$file_type))) {
        case "pdf":
            return "PDF";

        case "image":
            return "Image";

        case "document":
            return "Document";
    }

    return "File";
}



/*
 |--------------------------------------------------------------------------
 | SHARED STYLES
 |--------------------------------------------------------------------------
 */
function noticeModuleStyles()
{
    return <<<'CSS'

        body {
            font-family: Arial, sans-serif;
            background: #f5f6fa;
            margin: 0;
            padding: 30px;
        }

        .container {
            max-width: 1200px;
            margin: auto;
        }

        .card {
            background: #fff;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,.08);
        }

        h1, h2, h3 { margin-top: 0; }

        .teacher-info { color: #555; margin-bottom: 10px; }

        .back-link {
            display: inline-block;
            margin-bottom: 15px;
            color: #007bff;
            text-decoration: none;
        }

        .muted { color: #6b7280; font-size: 13px; }

        .empty {
            padding: 20px;
            background: #fff3cd;
            border-radius: 6px;
        }

        .notice-msg {
            background: #e7f1ff;
            border-left: 4px solid #0d6efd;
            color: #084298;
            padding: 12px 15px;
            border-radius: 6px;
            font-size: 14px;
        }

        .notice-msg.ok {
            background: #d4edda;
            border-left-color: #198754;
            color: #155724;
        }

        /* ---------- SUMMARY CARDS ---------- */

        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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
            text-transform: uppercase;
            letter-spacing: .3px;
            margin-bottom: 6px;
        }

        .summary-card strong { font-size: 24px; }

        .summary-card.accent-total   { border-left: 4px solid #0d6efd; }
        .summary-card.accent-unread  { border-left: 4px solid #dc3545; }
        .summary-card.accent-important { border-left: 4px solid #fd7e14; }
        .summary-card.accent-expired { border-left: 4px solid #6c757d; }

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

        .btn-outline { background: #fff; color: #0d6efd; }
        .btn-secondary { background: #6c757d; border-color: #6c757d; }
        .btn-green { background: #198754; border-color: #198754; }
        .btn-sm { padding: 6px 12px; font-size: 13px; }

        .toolbar {
            margin-top: 16px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        /* ---------- NOTICE LIST ---------- */

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

        th { background: #f1f1f1; font-size: 13px; }

        tbody tr:hover { background: #fafbfd; }

        tr.is-unread { background: #f0f7ff; }
        tr.is-unread:hover { background: #e3f0ff; }

        tr.priority-urgent-row { border-left: 4px solid #dc3545; }
        tr.priority-important-row { border-left: 4px solid #fd7e14; }

        .notice-title {
            font-weight: bold;
            color: #1a1a1a;
            text-decoration: none;
        }

        .notice-title:hover { text-decoration: underline; }

        .notice-desc {
            color: #555;
            font-size: 13px;
            margin-top: 3px;
        }

        .who {
            color: #6b7280;
            font-size: 12px;
            margin-top: 3px;
        }

        /* ---------- BADGES ---------- */

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            color: #fff;
            background: #6c757d;
            white-space: nowrap;
        }

        .priority-normal    { background: #6c757d; }
        .priority-important { background: #fd7e14; }
        .priority-urgent    { background: #dc3545; }

        .badge-read    { background: #adb5bd; }
        .badge-unread  { background: #dc3545; }
        .badge-expired { background: #495057; }
        .badge-pin     { background: #0d6efd; }
        .badge-att     { background: #198754; }

        .chip {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            background: #eef1f6;
            color: #333;
            border: 1px solid #e0e4ec;
            white-space: nowrap;
        }

        /* ---------- NOTICE DETAILS ---------- */

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 14px;
        }

        .detail-item {
            background: #f8f9fc;
            border: 1px solid #e6e9f0;
            border-radius: 8px;
            padding: 14px;
        }

        .detail-item span {
            display: block;
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: .3px;
            margin-bottom: 5px;
        }

        .detail-item strong { font-size: 14px; }

        .notice-content {
            background: #fbfcfe;
            border: 1px solid #e6e9f0;
            border-radius: 8px;
            padding: 18px;
            line-height: 1.7;
            font-size: 15px;
            color: #222;
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        /* ---------- ATTACHMENTS ---------- */

        .attach-row {
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid #e6e9f0;
            border-radius: 8px;
            padding: 12px 14px;
            margin-bottom: 10px;
            background: #fff;
        }

        .attach-icon { font-size: 22px; }

        .attach-info { flex: 1; }

        .attach-name { font-weight: bold; font-size: 14px; }

        .attach-meta { color: #6b7280; font-size: 12px; margin-top: 2px; }

        .section-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: .3px;
            margin: 22px 0 10px;
        }

        @media print {
            body { background: #fff; padding: 0; }
            .no-print { display: none !important; }
            .card { box-shadow: none; border: 1px solid #ddd; }
        }

CSS;
}

