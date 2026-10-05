<?php

/*
 |--------------------------------------------------------------------------
 | TEACHER - PROFILE : SHARED BOOTSTRAP + HELPERS
 |--------------------------------------------------------------------------
 | Used by:
 |     teacher/profile/index.php           (Profile view)
 |     teacher/profile/edit.php            (Edit allowed contact details)
 |     teacher/profile/change-password.php (Change own password)
 |     teacher/profile/logout.php          (Logout from account)
 |
 | Flow: Dashboard > Profile > (view > edit contact / change password)
 |
 | Tables used
 |     teacher_profiles  : designation, department, city, state, pincode and
 |                         the "can the teacher edit contact" policy switch
 |                         (created by profile_module_setup.sql)
 |     teachers, users, roles, schools, academic_years, teacher_subjects,
 |     classes, sections, subjects   (read only)
 |
 | WHAT THE TEACHER MAY CHANGE
 |     phone, email, address, city, state, pincode - only when
 |     teacher_profiles.can_edit_contact = 1
 |
 | WHAT THE TEACHER MAY NEVER CHANGE
 |     employee code, role, school, account status, joining date and the
 |     assigned classes / subjects. Those stay under the school admin, so no
 |     code path in this module writes to them.
 |
 | THE PASSWORD
 |     The password is never read into a variable, displayed or logged. The
 |     only values used are the hash in `users`, through password_verify()
 |     and password_hash().
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
 | MODULE TABLE CHECK
 |--------------------------------------------------------------------------
 | Friendly message instead of a raw SQL error when the setup script has not
 | been run yet.
 */

$profile_table_check = $conn->query("SHOW TABLES LIKE 'teacher_profiles'");

if (!$profile_table_check || $profile_table_check->num_rows === 0) {
    die(
        "The Profile module is not installed yet. "
        . "Please run profile_module_setup.sql on the school_management database."
    );
}


/*
 |--------------------------------------------------------------------------
 | FULL PROFILE OF THE LOGGED IN TEACHER
 |--------------------------------------------------------------------------
 | One query pulls together:
 |     - the teacher record (employee code, dob, gender, joining date, ...)
 |     - the account record (username, role, last login, status)
 |     - the role name
 |     - the extra profile fields (designation, department, city, ...)
 |     - the school (name, code, contact, address)
 |
 | NOTE: users.password is deliberately NOT selected, so the hash never
 | leaves the database for this page.
 */

$sql = "SELECT
            t.teacher_uid,
            t.employee_code,
            t.teacher_name,
            t.gender,
            t.dob,
            t.email         AS teacher_email,
            t.mobile        AS teacher_mobile,
            t.qualification,
            t.experience,
            t.joining_date,
            t.address       AS teacher_address,
            t.profile_photo,
            t.status        AS teacher_status,

            u.user_uid,
            u.username,
            u.email         AS account_email,
            u.mobile        AS account_mobile,
            u.profile_photo AS account_photo,
            u.user_type,
            u.status        AS account_status,
            u.last_login,

            r.role_name,

            COALESCE(p.designation, '')     AS designation,
            COALESCE(p.department, '')      AS department,
            COALESCE(p.city, '')            AS city,
            COALESCE(p.state, '')           AS state,
            COALESCE(p.pincode, '')         AS pincode,
            COALESCE(p.can_edit_contact, 0) AS can_edit_contact,

            COALESCE(sc.school_name, '')     AS school_name,
            COALESCE(sc.school_code, '')     AS school_code,
            COALESCE(sc.email, '')           AS school_email,
            COALESCE(sc.phone, '')           AS school_phone,
            COALESCE(sc.alternate_phone, '') AS school_alternate_phone,
            COALESCE(sc.address, '')         AS school_address,
            COALESCE(sc.city, '')            AS school_city,
            COALESCE(sc.state, '')           AS school_state,
            COALESCE(sc.pincode, '')         AS school_pincode,
            COALESCE(sc.affiliation_no, '')  AS affiliation_no,
            COALESCE(sc.udise_code, '')      AS udise_code

        FROM teachers t

        INNER JOIN users u
            ON u.user_uid = t.user_uid

        LEFT JOIN roles r
            ON r.role_uid = u.role_uid

        LEFT JOIN teacher_profiles p
            ON p.teacher_uid = t.teacher_uid
           AND p.school_uid = t.school_uid

        LEFT JOIN schools sc
            ON sc.school_uid = t.school_uid

        WHERE t.school_uid = ?
          AND t.user_uid = ?
          AND t.status <> 'resigned'
        LIMIT 1";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Profile query failed: " . $conn->error);
}

$stmt->bind_param("ss", $school_uid, $user_uid);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$profile) {
    die("Teacher profile not found.");
}

$teacher_uid  = $profile["teacher_uid"];
$teacher_name = $profile["teacher_name"];


/*
 |--------------------------------------------------------------------------
 | CURRENT ACADEMIC SESSION
 |--------------------------------------------------------------------------
 */

$sql = "SELECT academic_year_uid, year_name
        FROM academic_years
        WHERE status = 'active'
        ORDER BY id ASC
        LIMIT 1";

$stmt = $conn->prepare($sql);
$session_row = null;

if ($stmt) {
    $stmt->execute();
    $session_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$session_uid  = ($session_row["academic_year_uid"] ?? "");
$session_name = ($session_row["year_name"] ?? "Not Set");


/*
 |--------------------------------------------------------------------------
 | ASSIGNED CLASSES / SECTIONS / SUBJECTS
 |--------------------------------------------------------------------------
 | Read only. The teacher can see what they teach but can never change it.
 */

$sql = "SELECT
            ts.class_uid,
            ts.section_uid,
            ts.subject_uid,
            c.class_name,
            c.numeric_order,
            sec.section_name,
            sub.subject_name
        FROM teacher_subjects ts

        LEFT JOIN classes c
            ON c.class_uid = ts.class_uid

        LEFT JOIN sections sec
            ON sec.section_uid = ts.section_uid

        LEFT JOIN subjects sub
            ON sub.subject_uid = ts.subject_uid

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
$assignment_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$assigned_classes  = [];
$assigned_sections = [];
$assigned_subjects = [];

foreach ($assignment_rows as $assignment_row) {

    if (trim((string)$assignment_row["class_uid"]) !== "") {
        $assigned_classes[$assignment_row["class_uid"]] = $assignment_row["class_name"];
    }

    if (trim((string)$assignment_row["section_uid"]) !== "") {

        $section_key = $assignment_row["section_uid"];

        if (!isset($assigned_sections[$section_key])) {
            $assigned_sections[$section_key] = [
                "label"     => $assignment_row["section_name"],
                "class_uid" => $assignment_row["class_uid"],
            ];
        }
    }

    if (trim((string)$assignment_row["subject_uid"]) !== "") {
        $assigned_subjects[$assignment_row["subject_uid"]] = $assignment_row["subject_name"];
    }
}

/* "Class 1 - Section A" style labels for the assigned class chips */
$assigned_class_labels = [];

foreach ($assigned_sections as $section_data) {

    $class_name = $assigned_classes[$section_data["class_uid"]] ?? "";

    $assigned_class_labels[] = trim(
        (($class_name !== "") ? $class_name : "Class")
        . " - "
        . (($section_data["label"] !== "") ? $section_data["label"] : "Section")
    );
}

$assigned_class_labels = array_values(array_unique($assigned_class_labels));



/*
 |--------------------------------------------------------------------------
 | DISPLAY HELPERS
 |--------------------------------------------------------------------------
 */

/* "-" instead of an empty / zero date */
function profileDateLabel($value)
{
    $value = trim((string)$value);

    if ($value === "" || strpos($value, "0000-00-00") === 0) {
        return "-";
    }

    $stamp = strtotime($value);

    return ($stamp === false) ? $value : date("d M Y", $stamp);
}

function profileDateTimeLabel($value)
{
    $value = trim((string)$value);

    if ($value === "" || strpos($value, "0000-00-00") === 0) {
        return "Never";
    }

    $stamp = strtotime($value);

    return ($stamp === false) ? $value : date("d M Y, h:i A", $stamp);
}

/* "male" -> "Male" */
function profileGenderLabel($value)
{
    $value = trim((string)$value);

    return ($value === "") ? "Not Specified" : ucfirst($value);
}

/* the best phone available, so the header shows something useful */
function profilePrimaryPhone($profile)
{
    foreach (["teacher_mobile", "account_mobile"] as $key) {
        if (trim((string)($profile[$key] ?? "")) !== "") {
            return $profile[$key];
        }
    }

    return "";
}

function profilePrimaryEmail($profile)
{
    foreach (["teacher_email", "account_email"] as $key) {
        if (trim((string)($profile[$key] ?? "")) !== "") {
            return $profile[$key];
        }
    }

    return "";
}

/* the photo to show; only a plain relative path or an absolute URL is used */
function profilePhotoPath($profile)
{
    foreach (["profile_photo", "account_photo"] as $key) {

        $value = trim((string)($profile[$key] ?? ""));

        if ($value === "") {
            continue;
        }

        if (strpos($value, "..") !== false) {
            continue;
        }

        return $value;
    }

    return "";
}

/* initials for the avatar placeholder, e.g. "Anjali Verma" -> "AV" */
function profileInitials($name)
{
    $parts = preg_split("/\s+/", trim((string)$name), -1, PREG_SPLIT_NO_EMPTY);

    if (empty($parts)) {
        return "T";
    }

    $first = strtoupper(mb_substr($parts[0], 0, 1));
    $last  = (count($parts) > 1) ? strtoupper(mb_substr($parts[count($parts) - 1], 0, 1)) : "";

    return $first . $last;
}

/* the teacher is allowed to edit their own contact details */
function profileCanEditContact($profile)
{
    return ((int)($profile["can_edit_contact"] ?? 0) === 1);
}



/*
 |--------------------------------------------------------------------------
 | SHARED STYLES
 |--------------------------------------------------------------------------
 */
function profileModuleStyles()
{
    return <<<'CSS'

        body {
            font-family: Arial, sans-serif;
            background: #f5f6fa;
            margin: 0;
            padding: 30px;
        }

        .container { max-width: 1100px; margin: auto; }

        .card {
            background: #fff;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,.08);
        }

        h1, h2, h3 { margin-top: 0; }

        .back-link {
            display: inline-block;
            margin-bottom: 15px;
            color: #007bff;
            text-decoration: none;
        }

        .muted { color: #6b7280; font-size: 13px; }

        .empty { padding: 20px; background: #fff3cd; border-radius: 6px; }

        .msg {
            padding: 12px 15px;
            border-radius: 6px;
            font-size: 14px;
            margin-bottom: 15px;
        }

        .msg-ok   { background: #d4edda; border-left: 4px solid #198754; color: #155724; }
        .msg-err  { background: #f8d7da; border-left: 4px solid #dc3545; color: #721c24; }
        .msg-info { background: #e7f1ff; border-left: 4px solid #0d6efd; color: #084298; }

        /* ---------- PROFILE HEADER ---------- */

        .profile-head {
            display: flex;
            align-items: center;
            gap: 22px;
            flex-wrap: wrap;
        }

        .avatar {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            background: #0d6efd;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            font-weight: bold;
            overflow: hidden;
            flex-shrink: 0;
        }

        .avatar img { width: 100%; height: 100%; object-fit: cover; }

        .head-info { flex: 1; min-width: 220px; }

        .head-info h1 { margin: 0 0 6px; font-size: 26px; }

        .head-sub { color: #555; font-size: 14px; margin-bottom: 10px; }

        .badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            color: #fff;
            background: #6c757d;
        }

        .badge-active   { background: #198754; }
        .badge-inactive { background: #6c757d; }
        .badge-blocked  { background: #dc3545; }
        .badge-role     { background: #0d6efd; }
        .badge-locked   { background: #495057; }

        /* ---------- DETAIL GRID ---------- */

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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

        .detail-item strong { font-size: 14px; word-break: break-word; }

        .full-width { grid-column: 1 / -1; }

        .chip {
            display: inline-block;
            padding: 5px 11px;
            border-radius: 6px;
            font-size: 13px;
            background: #eef1f6;
            color: #333;
            border: 1px solid #e0e4ec;
            margin: 0 6px 6px 0;
        }

        /* ---------- BUTTONS ---------- */

        .btn {
            display: inline-block;
            padding: 10px 17px;
            border: 1px solid #0d6efd;
            background: #0d6efd;
            color: #fff;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            cursor: pointer;
        }

        .btn-outline   { background: #fff; color: #0d6efd; }
        .btn-secondary { background: #6c757d; border-color: #6c757d; }
        .btn-green     { background: #198754; border-color: #198754; }
        .btn-danger    { background: #dc3545; border-color: #dc3545; }

        .toolbar {
            margin-top: 16px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .inline-form { display: inline; }

        /* ---------- FORMS ---------- */

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }

        .form-grid label {
            display: block;
            font-size: 13px;
            font-weight: bold;
            color: #444;
            margin-bottom: 5px;
        }

        .form-grid input,
        .form-grid select,
        .form-grid textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            background: #fff;
        }

        .form-grid textarea { resize: vertical; min-height: 90px; }

        .hint { font-size: 12px; color: #6b7280; margin-top: 4px; }

        .section-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: .3px;
            margin: 24px 0 12px;
            padding-bottom: 6px;
            border-bottom: 1px solid #eee;
        }

        .lock-note {
            background: #f1f3f7;
            border-left: 4px solid #6c757d;
            color: #444;
            padding: 12px 15px;
            border-radius: 6px;
            font-size: 14px;
        }

        table { width: 100%; border-collapse: collapse; }

        th, td {
            padding: 10px;
            border-bottom: 1px solid #eee;
            text-align: left;
            font-size: 14px;
            vertical-align: middle;
        }

        th { background: #f1f1f1; font-size: 13px; }

        @media print {
            body { background: #fff; padding: 0; }
            .no-print { display: none !important; }
            .card { box-shadow: none; border: 1px solid #ddd; }
        }

CSS;
}

