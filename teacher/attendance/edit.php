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

$teacher_uid = $teacher["teacher_uid"];

/*
|--------------------------------------------------------------------------
| ATTENDANCE ID
|--------------------------------------------------------------------------
*/

$id = $_GET["id"] ?? "";

if (!$id) {
    header("Location: index.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| GET ATTENDANCE + VERIFY TEACHER ASSIGNMENT
|--------------------------------------------------------------------------
*/

$sql = "SELECT
            sa.attendance_uid,
            sa.student_uid,
            sa.class_uid,
            sa.section_uid,
            sa.attendance_date,
            sa.status,
            sa.remarks,
            s.student_name,
            s.admission_no,
            ts.assignment_uid
        FROM student_attendance sa

        INNER JOIN students s
            ON s.student_uid = sa.student_uid

        INNER JOIN teacher_subjects ts
            ON ts.school_uid = sa.school_uid
           AND ts.teacher_uid = ?
           AND ts.class_uid = sa.class_uid
           AND ts.section_uid = sa.section_uid

        WHERE sa.attendance_uid = ?
          AND sa.school_uid = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);

$stmt->bind_param(
    "sss",
    $teacher_uid,
    $id,
    $school_uid
);

$stmt->execute();

$attendance = $stmt->get_result()->fetch_assoc();

if (!$attendance) {
    die("Attendance record not found or you are not allowed to edit it.");
}

$assignment_uid = $attendance["assignment_uid"];

$message = "";
$error   = "";

/*
|--------------------------------------------------------------------------
| UPDATE
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $attendance_date = $_POST["attendance_date"] ?? "";
    $status          = $_POST["status"] ?? "";
    $remarks         = trim($_POST["remarks"] ?? "");

    $allowed_status = [
        "present",
        "absent",
        "late"
    ];

    if (!in_array($status, $allowed_status, true)) {

        $error = "Invalid attendance status.";

    } elseif (!$attendance_date) {

        $error = "Attendance date is required.";

    } else {

        $sql = "UPDATE student_attendance
                SET
                    attendance_date = ?,
                    status = ?,
                    marked_by_user_uid = ?,
                    remarks = ?
                WHERE attendance_uid = ?
                  AND school_uid = ?";

        $stmt = $conn->prepare($sql);

        $stmt->bind_param(
            "ssssss",
            $attendance_date,
            $status,
            $user_uid,
            $remarks,
            $id,
            $school_uid
        );

        if ($stmt->execute()) {

            $message = "Attendance updated successfully.";

            /*
            | Reload
            */

            $sql = "SELECT
                        sa.attendance_uid,
                        sa.student_uid,
                        sa.class_uid,
                        sa.section_uid,
                        sa.attendance_date,
                        sa.status,
                        sa.remarks,
                        s.student_name,
                        s.admission_no
                    FROM student_attendance sa

                    INNER JOIN students s
                        ON s.student_uid = sa.student_uid

                    WHERE sa.attendance_uid = ?
                      AND sa.school_uid = ?
                    LIMIT 1";

            $stmt = $conn->prepare($sql);

            $stmt->bind_param(
                "ss",
                $id,
                $school_uid
            );

            $stmt->execute();

            $attendance = $stmt->get_result()->fetch_assoc();

        } else {

            $error = "Unable to update attendance.";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<title>Edit Student Attendance</title>

<style>

body {
    font-family: Arial, sans-serif;
    background: #f5f6fa;
    padding: 30px;
}

.container {
    max-width: 650px;
    margin: auto;
}

.card {
    background: white;
    padding: 25px;
    border-radius: 10px;
}

.info {
    margin-bottom: 8px;
}

label {
    display: block;
    margin-top: 15px;
    margin-bottom: 6px;
    font-weight: bold;
}

input,
select,
textarea {
    width: 100%;
    padding: 10px;
    box-sizing: border-box;
}

textarea {
    height: 90px;
}

button {
    margin-top: 20px;
    padding: 11px 20px;
    background: #007bff;
    color: white;
    border: none;
    border-radius: 5px;
}

.success {
    padding: 10px;
    background: #d4edda;
    color: #155724;
    margin-bottom: 15px;
}

.error {
    padding: 10px;
    background: #f8d7da;
    color: #721c24;
    margin-bottom: 15px;
}

.btn {
    display: inline-block;
    margin-top: 15px;
    padding: 9px 15px;
    background: #6c757d;
    color: white;
    text-decoration: none;
    border-radius: 5px;
}

</style>

</head>

<body>

<div class="container">

<div class="card">

<h2>Edit Student Attendance</h2>

<?php if ($message): ?>

<div class="success">
<?= htmlspecialchars($message) ?>
</div>

<?php endif; ?>


<?php if ($error): ?>

<div class="error">
<?= htmlspecialchars($error) ?>
</div>

<?php endif; ?>


<div class="info">
Student:
<strong>
<?= htmlspecialchars($attendance["student_name"]) ?>
</strong>
</div>

<div class="info">
Admission No:
<strong>
<?= htmlspecialchars($attendance["admission_no"]) ?>
</strong>
</div>

<div class="info">
Class:
<strong>
<?= htmlspecialchars($attendance["class_uid"]) ?>
</strong>
</div>

<div class="info">
Section:
<strong>
<?= htmlspecialchars($attendance["section_uid"]) ?>
</strong>
</div>


<form method="POST">

<?php echo csrfField(); ?>

<label>
Attendance Date
</label>

<input
    type="date"
    name="attendance_date"
    value="<?= htmlspecialchars($attendance["attendance_date"]) ?>"
    required
>


<label>
Status
</label>

<select name="status" required>

<option
    value="present"
    <?= $attendance["status"] === "present" ? "selected" : "" ?>
>
Present
</option>

<option
    value="absent"
    <?= $attendance["status"] === "absent" ? "selected" : "" ?>
>
Absent
</option>

<option
    value="late"
    <?= $attendance["status"] === "late" ? "selected" : "" ?>
>
Late
</option>

</select>


<label>
Remarks
</label>

<textarea name="remarks"><?= htmlspecialchars($attendance["remarks"] ?? "") ?></textarea>


<button type="submit">
Update Attendance
</button>

</form>


<a
    class="btn"
    href="history.php?assignment_uid=<?= urlencode($assignment_uid) ?>"
>
← Back to History
</a>

</div>

</div>

</body>

</html>