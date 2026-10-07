<?php

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

/* CSRF - sirf POST verify hota hai */
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
$stmt->bind_param("ss", $school_uid, $user_uid);
$stmt->execute();

$teacher = $stmt->get_result()->fetch_assoc();

if (!$teacher) {
    die("Teacher profile not found.");
}

$teacher_uid  = $teacher["teacher_uid"];
$teacher_name = $teacher["teacher_name"];

/*
|--------------------------------------------------------------------------
| ASSIGNMENT
|--------------------------------------------------------------------------
*/

$assignment_uid = $_GET["assignment_uid"]
    ?? $_POST["assignment_uid"]
    ?? "";

if (!$assignment_uid) {
    header("Location: index.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| VERIFY ASSIGNMENT BELONGS TO TEACHER
|--------------------------------------------------------------------------
*/

$sql = "SELECT
            assignment_uid,
            class_uid,
            section_uid,
            subject_uid,
            academic_year_uid
        FROM teacher_subjects
        WHERE assignment_uid = ?
          AND school_uid = ?
          AND teacher_uid = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param(
    "sss",
    $assignment_uid,
    $school_uid,
    $teacher_uid
);

$stmt->execute();

$assignment = $stmt->get_result()->fetch_assoc();

if (!$assignment) {
    die("This class is not assigned to you.");
}

$class_uid        = $assignment["class_uid"];
$section_uid      = $assignment["section_uid"];
$academic_year_uid = $assignment["academic_year_uid"];

/*
|--------------------------------------------------------------------------
| DATE
|--------------------------------------------------------------------------
*/

$attendance_date = $_POST["attendance_date"]
    ?? $_GET["attendance_date"]
    ?? date("Y-m-d");

$message = "";
$error   = "";

/*
|--------------------------------------------------------------------------
| SAVE ATTENDANCE
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $statuses = $_POST["status"] ?? [];
    $remarks  = $_POST["remarks"] ?? [];

    $allowed_status = [
        "present",
        "absent",
        "late"
    ];

    if (empty($statuses)) {

        $error = "Please mark attendance for at least one student.";

    } else {

        /*
        |--------------------------------------------------------------------------
        | GET VALID STUDENTS OF THIS CLASS
        |--------------------------------------------------------------------------
        */

        $sql = "SELECT
                    sa.student_uid
                FROM student_academic sa
                WHERE sa.school_uid = ?
                  AND sa.class_uid = ?
                  AND sa.section_uid = ?
                  AND sa.academic_year_uid = ?
                  AND sa.status = 'active'";

        $stmt = $conn->prepare($sql);

        $stmt->bind_param(
            "ssss",
            $school_uid,
            $class_uid,
            $section_uid,
            $academic_year_uid
        );

        $stmt->execute();

        $valid_students = [];

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $valid_students[$row["student_uid"]] = true;
        }

        /*
        |--------------------------------------------------------------------------
        | SAVE EACH STUDENT
        |--------------------------------------------------------------------------
        */

        foreach ($statuses as $student_uid => $status) {

            if (!isset($valid_students[$student_uid])) {
                continue;
            }

            if (!in_array($status, $allowed_status, true)) {
                continue;
            }

            $remark = trim($remarks[$student_uid] ?? "");

            /*
            |--------------------------------------------------------------------------
            | CHECK EXISTING ATTENDANCE
            |--------------------------------------------------------------------------
            */

            $sql = "SELECT attendance_uid
                    FROM student_attendance
                    WHERE school_uid = ?
                      AND student_uid = ?
                      AND class_uid = ?
                      AND section_uid = ?
                      AND attendance_date = ?
                    LIMIT 1";

            $stmt = $conn->prepare($sql);

            $stmt->bind_param(
                "sssss",
                $school_uid,
                $student_uid,
                $class_uid,
                $section_uid,
                $attendance_date
            );

            $stmt->execute();

            $existing = $stmt->get_result()->fetch_assoc();

            /*
            |--------------------------------------------------------------------------
            | UPDATE EXISTING
            |--------------------------------------------------------------------------
            */

            if ($existing) {

                $attendance_uid = $existing["attendance_uid"];

                $sql = "UPDATE student_attendance
                        SET
                            status = ?,
                            marked_by_user_uid = ?,
                            remarks = ?
                        WHERE attendance_uid = ?
                          AND school_uid = ?";

                $stmt = $conn->prepare($sql);

                $stmt->bind_param(
                    "sssss",
                    $status,
                    $user_uid,
                    $remark,
                    $attendance_uid,
                    $school_uid
                );

                $stmt->execute();

            }

            /*
            |--------------------------------------------------------------------------
            | INSERT NEW
            |--------------------------------------------------------------------------
            */

            else {

                /*
                | Generate next attendance UID
                */

                $sql = "SELECT MAX(id) AS max_id
                        FROM student_attendance";

                $stmt = $conn->prepare($sql);
                $stmt->execute();

                $max = $stmt->get_result()->fetch_assoc();

                $next_id = ((int)($max["max_id"] ?? 0)) + 1;

                $attendance_uid =
                    "ATT" . str_pad(
                        $next_id,
                        9,
                        "0",
                        STR_PAD_LEFT
                    );

                $sql = "INSERT INTO student_attendance
                        (
                            attendance_uid,
                            school_uid,
                            student_uid,
                            class_uid,
                            section_uid,
                            attendance_date,
                            status,
                            marked_by_user_uid,
                            remarks
                        )
                        VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?)";

                $stmt = $conn->prepare($sql);

                $stmt->bind_param(
                    "sssssssss",
                    $attendance_uid,
                    $school_uid,
                    $student_uid,
                    $class_uid,
                    $section_uid,
                    $attendance_date,
                    $status,
                    $user_uid,
                    $remark
                );

                $stmt->execute();
            }
        }

        $message = "Student attendance saved successfully.";
    }
}

/*
|--------------------------------------------------------------------------
| GET STUDENTS
|--------------------------------------------------------------------------
*/

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
        ORDER BY sa.roll_no, s.student_name";

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

/*
|--------------------------------------------------------------------------
| GET EXISTING ATTENDANCE FOR SELECTED DATE
|--------------------------------------------------------------------------
*/

$existing_attendance = [];

$sql = "SELECT
            student_uid,
            status,
            remarks
        FROM student_attendance
        WHERE school_uid = ?
          AND class_uid = ?
          AND section_uid = ?
          AND attendance_date = ?";

$stmt = $conn->prepare($sql);

$stmt->bind_param(
    "ssss",
    $school_uid,
    $class_uid,
    $section_uid,
    $attendance_date
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $existing_attendance[$row["student_uid"]] = $row;
}

?>

<?php
$UI_ROOT         = '../';
$UI_ACTIVE       = 'attendance';
$UI_TITLE        = 'Take Student Attendance';
$UI_CRUMBS       = [
    ['label' => 'Attendance', 'url' => 'index.php'],
];
$UI_PROFILE_NAME = $teacher_name;
include __DIR__ . '/../includes/header.php';
?>

<div class="card mb-4">
    <div class="card-header">
        <strong>Take Student Attendance</strong>
    </div>
    <div class="card-body">

        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <div class="border rounded p-3 h-100">
                    <div class="text-body-secondary">Teacher</div>
                    <strong><?= htmlspecialchars($teacher_name) ?></strong>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded p-3 h-100">
                    <div class="text-body-secondary">Class</div>
                    <strong><?= htmlspecialchars($class_uid) ?></strong>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded p-3 h-100">
                    <div class="text-body-secondary">Section</div>
                    <strong><?= htmlspecialchars($section_uid) ?></strong>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded p-3 h-100">
                    <div class="text-body-secondary">Subject</div>
                    <strong><?= htmlspecialchars($assignment["subject_uid"]) ?></strong>
                </div>
            </div>
        </div>

    </div>
</div>


<div class="card mb-4">
    <div class="card-body">

<?php if ($message): ?>

<div class="alert alert-success" role="alert">
<?= htmlspecialchars($message) ?>
</div>

<?php endif; ?>


<?php if ($error): ?>

<div class="alert alert-danger" role="alert">
<?= htmlspecialchars($error) ?>
</div>

<?php endif; ?>


<form method="POST">

<?php echo csrfField(); ?>

<input
    type="hidden"
    name="assignment_uid"
    value="<?= htmlspecialchars($assignment_uid) ?>"
>


<div class="mb-3 col-md-4">
<label class="form-label" for="attendance_date">
<strong>Attendance Date</strong>
</label>

<input
    class="form-control"
    id="attendance_date"
    type="date"
    name="attendance_date"
    value="<?= htmlspecialchars($attendance_date) ?>"
    required
>


<?php if (empty($students)): ?>

<div class="alert alert-warning mb-0" role="alert">No students found for this class and section.</div>

<?php else: ?>

<div class="table-responsive">
<table class="table table-striped table-hover align-middle">

<thead>

<tr>
    <th>Roll No</th>
    <th>Admission No</th>
    <th>Student Name</th>
    <th>Status</th>
    <th>Remarks</th>
</tr>

</thead>

<tbody>

<?php foreach ($students as $student): ?>

<?php

$student_uid = $student["student_uid"];

$current_status =
    $existing_attendance[$student_uid]["status"]
    ?? "present";

$current_remarks =
    $existing_attendance[$student_uid]["remarks"]
    ?? "";

?>

<tr>

<td>
<?= htmlspecialchars($student["roll_no"]) ?>
</td>

<td>
<?= htmlspecialchars($student["admission_no"]) ?>
</td>

<td>
<strong>
<?= htmlspecialchars($student["student_name"]) ?>
</strong>
</td>

<td>

<select class="form-select" name="status[<?= htmlspecialchars($student_uid) ?>]">

<option
    value="present"
    <?= $current_status === "present" ? "selected" : "" ?>
>
Present
</option>

<option
    value="absent"
    <?= $current_status === "absent" ? "selected" : "" ?>
>
Absent
</option>

<option
    value="late"
    <?= $current_status === "late" ? "selected" : "" ?>
>
Late
</option>

</select>

</td>

<td>

<textarea
    class="form-control"
    rows="1"
    name="remarks[<?= htmlspecialchars($student_uid) ?>]"
><?= htmlspecialchars($current_remarks) ?></textarea>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>
</div>

<button class="btn btn-primary mt-3" type="submit">
Save Attendance
</button>

<?php endif; ?>

</form>

<div class="mt-3">

<a class="btn btn-secondary" href="index.php">
← Back
</a>

</div>

</div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>