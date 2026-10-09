<?php

/*
|--------------------------------------------------------------------------
| TEACHER - MARKS ENTRY
|--------------------------------------------------------------------------
| Flow:
| Marks Entry > Select Class > Select Section > Select Subject >
| Select Exam > Load Students > Enter Marks > Save > Submit
|
| New tables used (created via marks_entry_setup.sql):
|   exams, student_marks
|
| No existing page/table is modified by this file.
*/

session_start();

require_once __DIR__ . "/../../function/function.php";
require_once __DIR__ . "/../../auth.php";

if (!isset($_SESSION["user_uid"])) {
    header("Location: ../../login.php");
    exit();
}

if (($_SESSION["user_type"] ?? "") !== "teacher") {
    header("Location: ../../login.php");
    exit();
}

/* CSRF - sirf POST verify hota hai (filter + save dono forms) */
csrfVerify();

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
| TEACHER ASSIGNMENTS (Class / Section / Subject / Session options)
|--------------------------------------------------------------------------
*/

$sql = "SELECT
            ts.class_uid,
            ts.section_uid,
            ts.subject_uid,
            ts.academic_year_uid,
            c.class_name,
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
$assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/*
|--------------------------------------------------------------------------
| EXAMS / ASSESSMENTS
|--------------------------------------------------------------------------
*/

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
$exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/*
|--------------------------------------------------------------------------
| ENTRY FLOW - VALIDATION, STUDENT LIST AND SAVE / SUBMIT HANDLING
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| GRADE CALCULATION (Auto grade from percentage)
|--------------------------------------------------------------------------
*/

function marksEntryGrade($marks, $max_marks)
{
    if ($max_marks <= 0) {
        return "";
    }

    $percentage = ($marks / $max_marks) * 100;

    if ($percentage >= 90) {
        return "A+";
    }
    if ($percentage >= 80) {
        return "A";
    }
    if ($percentage >= 70) {
        return "B+";
    }
    if ($percentage >= 60) {
        return "B";
    }
    if ($percentage >= 50) {
        return "C";
    }
    if ($percentage >= 40) {
        return "D";
    }

    return "F";
}

/*
|--------------------------------------------------------------------------
| FILTER VALUES
|--------------------------------------------------------------------------
*/

/*
| The Exams module deep links here (Exam Details > Enter Marks) with the
| filters in the query string. A GET action is only ever honoured as "load",
| so a GET request can never save or submit marks. A POST always wins.
*/
$get_action = trim($_GET["action"] ?? "");
$get_action = ($get_action === "load") ? "load" : "";

$action = trim($_POST["action"] ?? "");
$action = ($action !== "") ? $action : $get_action;

$class_uid         = trim($_POST["class_uid"] ?? $_GET["class_uid"] ?? "");
$section_uid       = trim($_POST["section_uid"] ?? $_GET["section_uid"] ?? "");
$subject_uid       = trim($_POST["subject_uid"] ?? $_GET["subject_uid"] ?? "");
$exam_uid          = trim($_POST["exam_uid"] ?? $_GET["exam_uid"] ?? "");
$academic_year_uid = trim($_POST["academic_year_uid"] ?? $_GET["academic_year_uid"] ?? "");
$term              = trim($_POST["term"] ?? $_GET["term"] ?? "");
$max_marks_raw     = trim($_POST["max_marks"] ?? $_GET["max_marks"] ?? "100");

$message = "";
$error   = "";

$students = [];
$rows     = [];
$loaded    = false;

$valid_actions = ["load", "draft", "submit"];

/*
|--------------------------------------------------------------------------
| HANDLE FORM
|--------------------------------------------------------------------------
*/

if (in_array($action, $valid_actions, true)) {

    /* ----------------------------------------------------------
       1. FILTER VALIDATION
    ---------------------------------------------------------- */

    if (
        $class_uid === ""
        || $section_uid === ""
        || $subject_uid === ""
        || $exam_uid === ""
        || $academic_year_uid === ""
    ) {
        $error = "Please select Class, Section, Subject, Exam and Academic Session.";
    }

    /* ----------------------------------------------------------
       2. MAX MARKS VALIDATION
    ---------------------------------------------------------- */

    $max_marks = 100;

    if ($error === "") {
        if (
            !is_numeric($max_marks_raw)
            || (float)$max_marks_raw <= 0
            || (float)$max_marks_raw > 1000
        ) {
            $error = "Maximum Marks must be a number between 1 and 1000.";
        } else {
            $max_marks = (float)$max_marks_raw;
        }
    }

    /* ----------------------------------------------------------
       3. VERIFY CLASS / SECTION / SUBJECT IS ASSIGNED TO TEACHER
    ---------------------------------------------------------- */

    if ($error === "") {
        $sql = "SELECT assignment_uid
                FROM teacher_subjects
                WHERE school_uid = ?
                  AND teacher_uid = ?
                  AND class_uid = ?
                  AND section_uid = ?
                  AND subject_uid = ?
                  AND academic_year_uid = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "ssssss",
            $school_uid,
            $teacher_uid,
            $class_uid,
            $section_uid,
            $subject_uid,
            $academic_year_uid
        );
        $stmt->execute();
        $assignment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$assignment) {
            $error = "This class, section and subject is not assigned to you.";
        }
    }

    /* ----------------------------------------------------------
       4. VERIFY EXAM BELONGS TO SCHOOL AND SHARES THE SESSION
    ---------------------------------------------------------- */

    if ($error === "") {
        $sql = "SELECT
                    exam_uid,
                    academic_year_uid,
                    exam_name,
                    term
                FROM exams
                WHERE school_uid = ?
                  AND exam_uid = ?
                  AND status = 'active'
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $school_uid, $exam_uid);
        $stmt->execute();
        $exam = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$exam) {
            $error = "Selected exam not found.";
        } elseif ((string)$exam["academic_year_uid"] !== $academic_year_uid) {
            $error = "The selected exam does not belong to the selected academic session.";
        }
    }

    /* ----------------------------------------------------------
       4b. TERM MUST MATCH THE SELECTED EXAM
    ---------------------------------------------------------- */

    if ($error === "" && $term !== "") {

        $exam_term = trim((string)($exam["term"] ?? ""));

        if ($exam_term !== "" && strcasecmp($exam_term, $term) !== 0) {
            $error = "The selected exam does not belong to the selected term.";
        }
    }

    /* ----------------------------------------------------------
       5. LOAD ACTIVE STUDENTS OF CLASS + SECTION + SESSION
    ---------------------------------------------------------- */

    if ($error === "") {
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
        $stmt->bind_param(
            "ssss",
            $school_uid,
            $class_uid,
            $section_uid,
            $academic_year_uid
        );
        $stmt->execute();
        $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($students)) {
            $error = "No active students found for this class and section.";
        }
    }

    /* ----------------------------------------------------------
       6. SAVE DRAFT / SAVE & SUBMIT
    ---------------------------------------------------------- */

    $save_failed = false;

    if ($error === "" && ($action === "draft" || $action === "submit")) {

        $posted_marks   = $_POST["marks"]   ?? [];
        $posted_absent  = $_POST["absent"]  ?? [];
        $posted_exempt  = $_POST["exempt"]  ?? [];
        $posted_remarks = $_POST["remarks"] ?? [];

        $valid_students = [];

        foreach ($students as $student) {
            $valid_students[$student["student_uid"]] = true;
        }

        /* --- 6a. VALIDATE ALL MARKS FIRST --- */

        foreach ($valid_students as $student_uid => $true) {

            if (!isset($posted_marks[$student_uid])) {
                continue;
            }

            $marks_value = trim((string)$posted_marks[$student_uid]);

            if ($marks_value === "") {
                continue;
            }

            if (
                !is_numeric($marks_value)
                || (float)$marks_value < 0
                || (float)$marks_value > $max_marks
            ) {
                $save_failed = true;
                $error =
                    "Marks must be between 0 and "
                    . rtrim(rtrim(number_format($max_marks, 2, ".", ""), "0"), ".")
                    . ". Please check the highlighted fields.";
                break;
            }
        }

        /* --- 6b. SAVE EACH STUDENT --- */

        if (!$save_failed) {

            $entry_status = ($action === "submit") ? "submitted" : "draft";

            foreach ($valid_students as $student_uid => $true) {

                $marks_value = trim((string)($posted_marks[$student_uid] ?? ""));
                $is_absent   = isset($posted_absent[$student_uid]) ? 1 : 0;
                $is_exempt   = isset($posted_exempt[$student_uid]) ? 1 : 0;
                $remark      = trim((string)($posted_remarks[$student_uid] ?? ""));

                /* Absent / Exempted overrides marks */
                if ($is_absent || $is_exempt) {
                    $marks_value = "";
                }

                $has_data = (
                    $marks_value !== ""
                    || $is_absent
                    || $is_exempt
                    || $remark !== ""
                );

                /* Nothing entered -> mark pending (clears old value) */
                if (!$has_data) {
                    $new_marks  = null;
                    $new_grade  = null;
                    $new_status = "pending";
                    $new_absent = 0;
                    $new_exempt = 0;
                } else {
                    $new_marks = ($marks_value !== "")
                        ? (float)$marks_value
                        : null;

                    if ($is_absent) {
                        $new_grade = "AB";
                    } elseif ($is_exempt) {
                        $new_grade = "EX";
                    } elseif ($new_marks !== null) {
                        $new_grade = marksEntryGrade($new_marks, $max_marks);
                    } else {
                        $new_grade = null;
                    }

                    $new_status = $entry_status;
                    $new_absent = $is_absent;
                    $new_exempt = $is_exempt;
                }

                /* CHECK EXISTING MARKS ROW */

                $sql = "SELECT marks_uid
                        FROM student_marks
                        WHERE school_uid = ?
                          AND student_uid = ?
                          AND exam_uid = ?
                          AND subject_uid = ?
                          AND class_uid = ?
                          AND section_uid = ?
                          AND academic_year_uid = ?
                        LIMIT 1";

                $stmt = $conn->prepare($sql);
                $stmt->bind_param(
                    "sssssss",
                    $school_uid,
                    $student_uid,
                    $exam_uid,
                    $subject_uid,
                    $class_uid,
                    $section_uid,
                    $academic_year_uid
                );
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($existing) {

                    $sql = "UPDATE student_marks
                            SET max_marks = ?,
                                marks_obtained = ?,
                                is_absent = ?,
                                is_exempted = ?,
                                grade = ?,
                                remarks = ?,
                                entry_status = ?,
                                entered_by_user_uid = ?
                            WHERE marks_uid = ?
                              AND school_uid = ?";

                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param(
                        "ddiissssss",
                        $max_marks,
                        $new_marks,
                        $new_absent,
                        $new_exempt,
                        $new_grade,
                        $remark,
                        $new_status,
                        $user_uid,
                        $existing["marks_uid"],
                        $school_uid
                    );
                    $stmt->execute();
                    $stmt->close();

                } elseif ($has_data) {

                    /* NEXT MARKS UID */
                    $result = mysqli_query(
                        $conn,
                        "SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM student_marks"
                    );
                    $row = mysqli_fetch_assoc($result);

                    $marks_uid = "MRK" . str_pad(
                        (string)$row["next_id"],
                        9,
                        "0",
                        STR_PAD_LEFT
                    );

                    $sql = "INSERT INTO student_marks
                            (
                                marks_uid,
                                school_uid,
                                student_uid,
                                class_uid,
                                section_uid,
                                subject_uid,
                                academic_year_uid,
                                exam_uid,
                                max_marks,
                                marks_obtained,
                                is_absent,
                                is_exempted,
                                grade,
                                remarks,
                                entry_status,
                                entered_by_user_uid
                            )
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                    $stmt = $conn->prepare($sql);

                    if (!$stmt) {
                        $save_failed = true;
                        $error = "Could not save marks: " . $conn->error;
                        break;
                    }

                    $stmt->bind_param(
                        "ssssssssddiissss",
                        $marks_uid,
                        $school_uid,
                        $student_uid,
                        $class_uid,
                        $section_uid,
                        $subject_uid,
                        $academic_year_uid,
                        $exam_uid,
                        $max_marks,
                        $new_marks,
                        $new_absent,
                        $new_exempt,
                        $new_grade,
                        $remark,
                        $new_status,
                        $user_uid
                    );
                    $stmt->execute();
                    $stmt->close();
                }
            }

            if (!$save_failed) {
                $message = ($action === "submit")
                    ? "Marks submitted successfully."
                    : "Marks saved as draft.";
            }
        }
    }

    /* ----------------------------------------------------------
       7. LOAD EXISTING MARKS FOR DISPLAY
    ---------------------------------------------------------- */

    if ($error === "" && !empty($students)) {

        $loaded = true;

        $sql = "SELECT
                    student_uid,
                    max_marks,
                    marks_obtained,
                    is_absent,
                    is_exempted,
                    grade,
                    remarks,
                    entry_status
                FROM student_marks
                WHERE school_uid = ?
                  AND exam_uid = ?
                  AND subject_uid = ?
                  AND class_uid = ?
                  AND section_uid = ?
                  AND academic_year_uid = ?";

        $stmt = $conn->prepare($sql);
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

        $existing_marks = [];
        $result = $stmt->get_result();
        while ($mark_row = $result->fetch_assoc()) {
            $existing_marks[$mark_row["student_uid"]] = $mark_row;
        }
        $stmt->close();

        /* --- BUILD DISPLAY ROWS --- */

        foreach ($students as $student) {

            $student_uid = $student["student_uid"];
            $saved = $existing_marks[$student_uid] ?? null;

            $marks_value = "";
            $is_absent   = false;
            $is_exempt   = false;
            $remark      = "";
            $grade       = "";
            $entry       = "pending";

            if ($save_failed && isset($posted_marks[$student_uid])) {

                /* Keep user input after validation error */
                $marks_value = trim((string)$posted_marks[$student_uid]);
                $is_absent   = isset($posted_absent[$student_uid]);
                $is_exempt   = isset($posted_exempt[$student_uid]);
                $remark      = trim((string)($posted_remarks[$student_uid] ?? ""));

                if ($is_absent) {
                    $grade = "AB";
                } elseif ($is_exempt) {
                    $grade = "EX";
                } elseif ($marks_value !== "" && is_numeric($marks_value)) {
                    $grade = marksEntryGrade((float)$marks_value, $max_marks);
                }

                $entry = ($marks_value !== "" || $is_absent || $is_exempt || $remark !== "")
                    ? (($action === "submit") ? "submitted" : "draft")
                    : "pending";

            } elseif ($saved) {

                if ($saved["marks_obtained"] !== null) {
                    $marks_value = rtrim(
                        rtrim(number_format((float)$saved["marks_obtained"], 2, ".", ""), "0"),
                        "."
                    );
                }

                $is_absent = (int)$saved["is_absent"] === 1;
                $is_exempt = (int)$saved["is_exempted"] === 1;
                $remark    = (string)$saved["remarks"];
                $grade     = (string)$saved["grade"];
                $entry     = (string)$saved["entry_status"];
            }

            $rows[] = [
                "student_uid"  => $student_uid,
                "roll_no"      => (string)$student["roll_no"],
                "student_name" => (string)$student["student_name"],
                "admission_no" => (string)$student["admission_no"],
                "max_marks"    => $max_marks,
                "marks"        => $marks_value,
                "is_absent"    => $is_absent,
                "is_exempt"    => $is_exempt,
                "remarks"      => $remark,
                "grade"        => $grade,
                "entry_status" => $entry,
                "status_label" => ($entry === "pending") ? "Pending" : "Entered",
            ];
        }
    }
}

/*
|--------------------------------------------------------------------------
| SUMMARY COUNTS
|--------------------------------------------------------------------------
*/

$total_students = count($rows);
$marks_entered  = 0;
$marks_pending  = 0;
$absent_count   = 0;
$exempt_count   = 0;
$submitted_all  = true;
$any_submitted  = false;

foreach ($rows as $row) {
    if ($row["entry_status"] === "pending") {
        $marks_pending++;
        $submitted_all = false;
    } else {
        $marks_entered++;
        if ($row["entry_status"] === "submitted") {
            $any_submitted = true;
        } else {
            $submitted_all = false;
        }
    }

    if ($row["is_absent"]) {
        $absent_count++;
    }
    if ($row["is_exempt"]) {
        $exempt_count++;
    }
}

if ($total_students === 0) {
    $submitted_label = "-";
} elseif ($submitted_all && $marks_entered > 0) {
    $submitted_label = "Submitted";
} elseif ($any_submitted || $marks_entered > 0) {
    $submitted_label = "Partial";
} else {
    $submitted_label = "Not Submitted";
}

/*
|--------------------------------------------------------------------------
| FILTER OPTION LISTS (built from teacher assignments)
|--------------------------------------------------------------------------
*/

$class_options   = [];
$section_options = [];
$subject_options = [];
$year_options    = [];

foreach ($assignments as $item) {

    $a_class_uid   = (string)($item["class_uid"] ?? "");
    $a_section_uid = (string)($item["section_uid"] ?? "");
    $a_subject_uid = (string)($item["subject_uid"] ?? "");
    $a_year_uid    = (string)($item["academic_year_uid"] ?? "");

    if ($a_class_uid !== "" && !isset($class_options[$a_class_uid])) {
        $class_options[$a_class_uid] = (string)$item["class_name"];
    }

    if ($a_section_uid !== "" && !isset($section_options[$a_section_uid])) {
        $section_options[$a_section_uid] = (string)$item["section_name"];
    }

    if ($a_subject_uid !== "" && !isset($subject_options[$a_subject_uid])) {
        $subject_options[$a_subject_uid] = (string)$item["subject_name"];
    }

    if ($a_year_uid !== "" && !isset($year_options[$a_year_uid])) {
        $year_options[$a_year_uid] = (trim((string)($item["year_name"] ?? "")) !== "")
            ? trim((string)$item["year_name"])
            : $a_year_uid;
    }
}

/*
|--------------------------------------------------------------------------
| EXAM LIST OF THE ASSIGNED SESSIONS + TERM OPTIONS
|--------------------------------------------------------------------------
*/

$exam_options = [];
$term_options = [];

foreach ($exams as $exam_item) {

    $exam_year_uid = (string)$exam_item["academic_year_uid"];

    if (!isset($year_options[$exam_year_uid])) {
        continue;
    }

    $exam_key = (string)$exam_item["exam_uid"];

    $exam_options[$exam_key] = [
        "exam_uid"         => $exam_key,
        "academic_year_uid" => $exam_year_uid,
        "exam_name"        => (string)$exam_item["exam_name"],
        "term"             => trim((string)($exam_item["term"] ?? "")),
    ];

    $exam_term = trim((string)($exam_item["term"] ?? ""));

    if ($exam_term !== "" && !isset($term_options[$exam_term])) {
        $term_options[$exam_term] = $exam_term;
    }
}

$selected_exam_term = "";

if ($exam_uid !== "" && isset($exam_options[$exam_uid])) {
    $selected_exam_term = $exam_options[$exam_uid]["term"];
}

$has_assignments = !empty($assignments);

?>

<?php
$UI_ROOT         = '../';
$UI_ACTIVE       = 'marks';
$UI_TITLE        = 'Marks Entry';
$UI_CRUMBS       = [];
$UI_PROFILE_NAME = $teacher_name;
include __DIR__ . '/../includes/header.php';
?>

<style>
    .badge-pending { background-color: #fff3cd; color: #856404; }
    .badge-draft { background-color: #cce5ff; color: #004085; }
    .badge-submitted { background-color: #d4edda; color: #155724; }
</style>

<?php if ($message !== ""): ?>
    <div class="alert alert-success" role="alert">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<?php if ($error !== ""): ?>
    <div class="alert alert-danger" role="alert">
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header"><strong>Marks Entry</strong></div>
    <div class="card-body">

        <p class="text-body-secondary">
            Teacher:
            <strong><?= htmlspecialchars($teacher_name) ?></strong>
        </p>

        <?php if (!$has_assignments): ?>

            <div class="alert alert-warning mb-0" role="alert">
                No class or subject has been assigned to you yet.
                Please contact the school administrator.
            </div>

        <?php else: ?>

            <form method="post" action="index.php" id="filterForm">

                <?php echo csrfField(); ?>

                <div class="row g-3">

                    <div class="col-12 col-md-6 col-xl-4">
                        <label class="form-label" for="academic_year_uid">Academic Session</label>
                        <select class="form-select" name="academic_year_uid" id="academic_year_uid" required>
                            <option value="">Select Session</option>
                            <?php foreach ($year_options as $year_uid => $year_name): ?>
                                <option
                                    value="<?= htmlspecialchars($year_uid) ?>"
                                    <?= ($academic_year_uid === $year_uid) ? "selected" : "" ?>>
                                    <?= htmlspecialchars($year_name) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-6 col-xl-4">
                        <label class="form-label" for="class_uid">Class</label>
                        <select class="form-select" name="class_uid" id="class_uid" required>
                            <option value="">Select Class</option>
                            <?php foreach ($class_options as $opt_class_uid => $opt_class_name): ?>
                                <option
                                    value="<?= htmlspecialchars($opt_class_uid) ?>"
                                    <?= ($class_uid === $opt_class_uid) ? "selected" : "" ?>>
                                    <?= htmlspecialchars($opt_class_name) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-6 col-xl-4">
                        <label class="form-label" for="section_uid">Section</label>
                        <select class="form-select" name="section_uid" id="section_uid" required>
                            <option value="">Select Section</option>
                            <?php foreach ($section_options as $opt_section_uid => $opt_section_name): ?>
                                <option
                                    value="<?= htmlspecialchars($opt_section_uid) ?>"
                                    <?= ($section_uid === $opt_section_uid) ? "selected" : "" ?>>
                                    <?= htmlspecialchars($opt_section_name) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-6 col-xl-4">
                        <label class="form-label" for="subject_uid">Subject</label>
                        <select class="form-select" name="subject_uid" id="subject_uid" required>
                            <option value="">Select Subject</option>
                            <?php foreach ($subject_options as $opt_subject_uid => $opt_subject_name): ?>
                                <option
                                    value="<?= htmlspecialchars($opt_subject_uid) ?>"
                                    <?= ($subject_uid === $opt_subject_uid) ? "selected" : "" ?>>
                                    <?= htmlspecialchars($opt_subject_name) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-6 col-xl-4">
                        <label class="form-label" for="term">Term</label>
                        <select class="form-select" name="term" id="term">
                            <option value="">All Terms</option>
                            <?php foreach ($term_options as $opt_term): ?>
                                <option
                                    value="<?= htmlspecialchars($opt_term) ?>"
                                    <?= ($term === $opt_term) ? "selected" : "" ?>>
                                    <?= htmlspecialchars($opt_term) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-6 col-xl-4">
                        <label class="form-label" for="exam_uid">Exam / Assessment</label>
                        <select class="form-select" name="exam_uid" id="exam_uid" required>
                            <option value="">Select Exam</option>
                            <?php foreach ($exam_options as $opt_exam): ?>
                                <option
                                    value="<?= htmlspecialchars($opt_exam["exam_uid"]) ?>"
                                    data-year="<?= htmlspecialchars($opt_exam["academic_year_uid"]) ?>"
                                    data-term="<?= htmlspecialchars($opt_exam["term"]) ?>"
                                    <?= ($exam_uid === $opt_exam["exam_uid"]) ? "selected" : "" ?>>
                                    <?= htmlspecialchars($opt_exam["exam_name"]) ?>
                                    <?= ($opt_exam["term"] !== "") ? " (" . htmlspecialchars($opt_exam["term"]) . ")" : "" ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-6 col-xl-4">
                        <label class="form-label" for="max_marks">Maximum Marks</label>
                        <input
                            type="number"
                            class="form-control"
                            name="max_marks"
                            id="max_marks"
                            min="1"
                            max="1000"
                            step="0.01"
                            value="<?= htmlspecialchars($max_marks_raw) ?>"
                            required>
                    </div>

                </div>

                <div class="mt-3 d-flex flex-wrap gap-2">
                    <button type="submit" name="action" value="load" class="btn btn-primary">
                        Load Students
                    </button>
                    <a class="btn btn-outline-secondary" href="index.php">Clear Filters</a>
                </div>

                <p class="form-text mt-2 mb-0">
                    Only the classes and subjects assigned to you are listed here.
                </p>

            </form>

        <?php endif; ?>

    </div>
</div>

<?php if ($loaded): ?>

    <div class="card mb-4">
        <div class="card-header"><strong>Enter Marks</strong></div>
        <div class="card-body">

            <p class="text-body-secondary mb-1">
                Class:
                <strong><?= htmlspecialchars($class_options[$class_uid] ?? $class_uid) ?></strong>
                &nbsp;|&nbsp;
                Section:
                <strong><?= htmlspecialchars($section_options[$section_uid] ?? $section_uid) ?></strong>
                &nbsp;|&nbsp;
                Subject:
                <strong><?= htmlspecialchars($subject_options[$subject_uid] ?? $subject_uid) ?></strong>
            </p>

            <p class="text-body-secondary">
                Exam:
                <strong><?= htmlspecialchars($exam_options[$exam_uid]["exam_name"] ?? $exam_uid) ?></strong>
                <?php if ($selected_exam_term !== ""): ?>
                    &nbsp;|&nbsp; Term:
                    <strong><?= htmlspecialchars($selected_exam_term) ?></strong>
                <?php endif; ?>
                &nbsp;|&nbsp;
                Session:
                <strong><?= htmlspecialchars($year_options[$academic_year_uid] ?? $academic_year_uid) ?></strong>
                &nbsp;|&nbsp;
                Max Marks:
                <strong><?= htmlspecialchars((string)$max_marks) ?></strong>
            </p>

            <div class="row g-3 mb-4">

                <div class="col-6 col-md-4 col-xl-2">
                    <div class="border rounded p-3 bg-light h-100">
                        <div class="text-body-secondary small">Total Students</div>
                        <div class="fs-5 fw-semibold" id="sum_total"><?= (int)$total_students ?></div>
                    </div>
                </div>

                <div class="col-6 col-md-4 col-xl-2">
                    <div class="border rounded p-3 bg-light h-100">
                        <div class="text-body-secondary small">Marks Entered</div>
                        <div class="fs-5 fw-semibold" id="sum_entered"><?= (int)$marks_entered ?></div>
                    </div>
                </div>

                <div class="col-6 col-md-4 col-xl-2">
                    <div class="border rounded p-3 bg-light h-100">
                        <div class="text-body-secondary small">Pending</div>
                        <div class="fs-5 fw-semibold" id="sum_pending"><?= (int)$marks_pending ?></div>
                    </div>
                </div>

                <div class="col-6 col-md-4 col-xl-2">
                    <div class="border rounded p-3 bg-light h-100">
                        <div class="text-body-secondary small">Absent</div>
                        <div class="fs-5 fw-semibold" id="sum_absent"><?= (int)$absent_count ?></div>
                    </div>
                </div>

                <div class="col-6 col-md-4 col-xl-2">
                    <div class="border rounded p-3 bg-light h-100">
                        <div class="text-body-secondary small">Exempted</div>
                        <div class="fs-5 fw-semibold" id="sum_exempt"><?= (int)$exempt_count ?></div>
                    </div>
                </div>

                <div class="col-6 col-md-4 col-xl-2">
                    <div class="border rounded p-3 bg-light h-100">
                        <div class="text-body-secondary small">Status</div>
                        <div class="fs-5 fw-semibold" id="sum_status"><?= htmlspecialchars($submitted_label) ?></div>
                    </div>
                </div>

            </div>

            <form method="post" action="index.php" id="marksForm">

                <?php echo csrfField(); ?>

                <input type="hidden" name="class_uid" value="<?= htmlspecialchars($class_uid) ?>">
                <input type="hidden" name="section_uid" value="<?= htmlspecialchars($section_uid) ?>">
                <input type="hidden" name="subject_uid" value="<?= htmlspecialchars($subject_uid) ?>">
                <input type="hidden" name="exam_uid" value="<?= htmlspecialchars($exam_uid) ?>">
                <input type="hidden" name="academic_year_uid" value="<?= htmlspecialchars($academic_year_uid) ?>">
                <input type="hidden" name="term" value="<?= htmlspecialchars($term) ?>">
                <input type="hidden" name="max_marks" value="<?= htmlspecialchars((string)$max_marks) ?>">

                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Roll No</th>
                                <th>Admission No</th>
                                <th>Student Name</th>
                                <th>Marks (out of <?= htmlspecialchars((string)$max_marks) ?>)</th>
                                <th>Grade</th>
                                <th>Absent</th>
                                <th>Exempted</th>
                                <th>Remarks</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php $serial = 1; ?>

                            <?php foreach ($rows as $row): ?>

                                <?php
                                $row_uid = (string)$row["student_uid"];

                                $badge_class = "badge-pending";
                                $badge_text  = "Pending";

                                if ($row["entry_status"] === "draft") {
                                    $badge_class = "badge-draft";
                                    $badge_text  = "Draft";
                                } elseif ($row["entry_status"] === "submitted") {
                                    $badge_class = "badge-submitted";
                                    $badge_text  = "Submitted";
                                }
                                ?>

                                <tr class="student-row"
                                    data-uid="<?= htmlspecialchars($row_uid) ?>">

                                    <td><?= (int)$serial ?></td>

                                    <td><?= htmlspecialchars($row["roll_no"]) ?></td>

                                    <td><?= htmlspecialchars($row["admission_no"]) ?></td>

                                    <td><?= htmlspecialchars($row["student_name"]) ?></td>

                                    <td>
                                        <input
                                            type="number"
                                            class="marks-input form-control form-control-sm"
                                            style="max-width: 120px;"
                                            name="marks[<?= htmlspecialchars($row_uid) ?>]"
                                            id="marks_<?= htmlspecialchars($row_uid) ?>"
                                            data-max="<?= htmlspecialchars((string)$row["max_marks"]) ?>"
                                            min="0"
                                            max="<?= htmlspecialchars((string)$row["max_marks"]) ?>"
                                            step="0.01"
                                            value="<?= htmlspecialchars($row["marks"]) ?>"
                                            <?= ($row["is_absent"] || $row["is_exempt"]) ? "disabled" : "" ?>>
                                    </td>

                                    <td>
                                        <span
                                            class="badge bg-light text-dark border"
                                            id="grade_<?= htmlspecialchars($row_uid) ?>">
                                            <?= htmlspecialchars($row["grade"]) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <input
                                            type="checkbox"
                                            class="absent-box form-check-input"
                                            name="absent[<?= htmlspecialchars($row_uid) ?>]"
                                            id="absent_<?= htmlspecialchars($row_uid) ?>"
                                            value="1"
                                            <?= $row["is_absent"] ? "checked" : "" ?>>
                                    </td>

                                    <td>
                                        <input
                                            type="checkbox"
                                            class="exempt-box form-check-input"
                                            name="exempt[<?= htmlspecialchars($row_uid) ?>]"
                                            id="exempt_<?= htmlspecialchars($row_uid) ?>"
                                            value="1"
                                            <?= $row["is_exempt"] ? "checked" : "" ?>>
                                    </td>

                                    <td>
                                        <input
                                            type="text"
                                            class="remarks-input form-control form-control-sm"
                                            style="max-width: 200px;"
                                            name="remarks[<?= htmlspecialchars($row_uid) ?>]"
                                            id="remarks_<?= htmlspecialchars($row_uid) ?>"
                                            maxlength="255"
                                            value="<?= htmlspecialchars($row["remarks"]) ?>">
                                    </td>

                                    <td>
                                        <span
                                            class="badge <?= $badge_class ?>"
                                            id="status_<?= htmlspecialchars($row_uid) ?>">
                                            <?= $badge_text ?>
                                        </span>
                                    </td>

                                </tr>

                                <?php $serial++; ?>

                            <?php endforeach; ?>

                        </tbody>
                    </table>
                </div>

                <div class="mt-3 d-flex flex-wrap gap-2">

                    <button type="submit" name="action" value="draft" class="btn btn-secondary">
                        Save Draft
                    </button>

                    <button
                        type="submit"
                        name="action"
                        value="submit"
                        class="btn btn-success"
                        id="submitButton">
                        Save &amp; Submit
                    </button>

                    <button type="button" class="btn btn-outline-warning" id="resetButton">
                        Reset
                    </button>

                    <a class="btn btn-outline-secondary" href="index.php">Cancel</a>

                </div>

                <p class="form-text mt-2 mb-0">
                    Enter marks out of the maximum marks shown above.
                    Tick <strong>Absent</strong> or <strong>Exempted</strong> to disable the marks box.
                    Grade is calculated automatically from the percentage.
                </p>

            </form>

        </div>
    </div>

<?php endif; ?>

<script>

(function () {

    var yearSelect = document.getElementById("academic_year_uid");
    var termSelect = document.getElementById("term");
    var examSelect = document.getElementById("exam_uid");

    if (!yearSelect || !termSelect || !examSelect) {
        return;
    }

    var examOptions = Array.prototype.slice.call(examSelect.options);

    function filterExams() {

        var year = yearSelect.value;
        var term = termSelect.value;
        var selected = examSelect.value;
        var keepSelected = false;

        examOptions.forEach(function (option) {

            if (option.value === "") {
                return;
            }

            var optionYear = option.getAttribute("data-year") || "";
            var optionTerm = option.getAttribute("data-term") || "";
            var matches = (year === "" || optionYear === year)
                && (term === "" || optionTerm === term);

            option.hidden = !matches;
            option.disabled = !matches;

            if (matches && option.value === selected) {
                keepSelected = true;
            }
        });

        if (selected !== "" && !keepSelected) {
            examSelect.value = "";
        }
    }

    yearSelect.addEventListener("change", filterExams);
    termSelect.addEventListener("change", filterExams);

    filterExams();

})();

(function () {

    var marksForm = document.getElementById("marksForm");

    if (!marksForm) {
        return;
    }

    var maxMarks = <?= json_encode((float)$max_marks) ?>;

    var sumTotal    = document.getElementById("sum_total");
    var sumEntered  = document.getElementById("sum_entered");
    var sumPending  = document.getElementById("sum_pending");
    var sumAbsent   = document.getElementById("sum_absent");
    var sumExempt   = document.getElementById("sum_exempt");
    var sumStatus   = document.getElementById("sum_status");

    function gradeFor(value, max) {

        if (!isFinite(value) || max <= 0) {
            return "";
        }

        var percent = (value / max) * 100;

        if (percent >= 90) { return "A+"; }
        if (percent >= 80) { return "A"; }
        if (percent >= 70) { return "B+"; }
        if (percent >= 60) { return "B"; }
        if (percent >= 50) { return "C"; }
        if (percent >= 40) { return "D"; }

        return "F";
    }

    var rows = marksForm.querySelectorAll("tbody tr.student-row");

    function fieldsOf(row) {

        var uid = row.getAttribute("data-uid");

        return {
            uid: uid,
            marks: document.getElementById("marks_" + uid),
            absent: document.getElementById("absent_" + uid),
            exempt: document.getElementById("exempt_" + uid),
            remarks: document.getElementById("remarks_" + uid),
            grade: document.getElementById("grade_" + uid),
            status: document.getElementById("status_" + uid)
        };
    }

    function updateRow(row, counts) {

        var field = fieldsOf(row);
        var rowMax = parseFloat(field.marks.getAttribute("data-max"));

        if (!isFinite(rowMax) || rowMax <= 0) {
            rowMax = maxMarks;
        }

        var blocked = field.absent.checked || field.exempt.checked;

        field.marks.disabled = blocked;
        field.marks.classList.remove("is-invalid");

        var value = field.marks.value.trim();
        var valid = true;

        if (blocked) {
            field.grade.textContent = field.absent.checked ? "AB" : "EX";
        } else if (value === "") {
            field.grade.textContent = "";
        } else {
            var numeric = parseFloat(value);

            if (isNaN(numeric) || numeric < 0 || numeric > rowMax) {
                valid = false;
                field.marks.classList.add("is-invalid");
                field.grade.textContent = "";
            } else {
                field.grade.textContent = gradeFor(numeric, rowMax);
            }
        }

        var hasData = blocked
            || value !== ""
            || field.remarks.value.trim() !== "";

        if (field.status) {
            if (hasData) {
                field.status.className = "badge badge-draft";
                field.status.textContent = "Entered";
            } else {
                field.status.className = "badge badge-pending";
                field.status.textContent = "Pending";
            }
        }

        if (counts) {
            if (hasData) {
                counts.entered++;
            } else {
                counts.pending++;
            }

            if (field.absent.checked) {
                counts.absent++;
            }

            if (field.exempt.checked) {
                counts.exempt++;
            }
        }

        return valid;
    }

    function refreshSummary() {

        var counts = { entered: 0, pending: 0, absent: 0, exempt: 0 };

        Array.prototype.slice.call(rows).forEach(function (row) {
            updateRow(row, counts);
        });

        if (sumTotal) {
            sumTotal.textContent = rows.length;
        }
        if (sumEntered) {
            sumEntered.textContent = counts.entered;
        }
        if (sumPending) {
            sumPending.textContent = counts.pending;
        }
        if (sumAbsent) {
            sumAbsent.textContent = counts.absent;
        }
        if (sumExempt) {
            sumExempt.textContent = counts.exempt;
        }

        if (sumStatus) {
            if (counts.entered === 0) {
                sumStatus.textContent = "Not Submitted";
            } else if (counts.pending === 0) {
                sumStatus.textContent = "All Entered";
            } else {
                sumStatus.textContent = "Partial";
            }
        }
    }

    function validateAll() {

        var invalid = false;

        Array.prototype.slice.call(rows).forEach(function (row) {
            if (!updateRow(row, null)) {
                invalid = true;
            }
        });

        return !invalid;
    }

    Array.prototype.slice.call(rows).forEach(function (row) {

        var field = fieldsOf(row);

        [field.marks, field.absent, field.exempt].forEach(function (element) {
            if (!element) {
                return;
            }

            element.addEventListener("change", refreshSummary);
            element.addEventListener("input", refreshSummary);
        });

        if (field.remarks) {
            field.remarks.addEventListener("input", refreshSummary);
        }
    });

    marksForm.addEventListener("submit", function (event) {

        if (!validateAll()) {
            event.preventDefault();
            alert("Please correct the highlighted marks. Marks cannot be more than the maximum marks.");
            return;
        }

        var clicked = document.activeElement;

        if (clicked && clicked.value === "submit") {
            if (!confirm("Submit the marks entered for this class, section, subject and exam?")) {
                event.preventDefault();
            }
        }
    });

    var resetButton = document.getElementById("resetButton");

    if (resetButton) {
        resetButton.addEventListener("click", function () {

            if (!confirm("Clear all the marks on this screen? Nothing is saved until you click Save.")) {
                return;
            }

            Array.prototype.slice.call(rows).forEach(function (row) {

                var field = fieldsOf(row);

                if (field.marks) {
                    field.marks.value = "";
                }
                if (field.absent) {
                    field.absent.checked = false;
                }
                if (field.exempt) {
                    field.exempt.checked = false;
                }
                if (field.remarks) {
                    field.remarks.value = "";
                }
            });

            refreshSummary();
        });
    }

    refreshSummary();

})();

</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
