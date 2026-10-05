<?php

session_start();

require_once __DIR__ . "/../../function/function.php";

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

$assignment_uid = $_GET["assignment_uid"] ?? "";

if (!$assignment_uid) {
    header("Location: index.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| VERIFY ASSIGNMENT
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

$class_uid         = $assignment["class_uid"];
$section_uid       = $assignment["section_uid"];
$academic_year_uid = $assignment["academic_year_uid"];

/*
|--------------------------------------------------------------------------
| DATE FILTER
|--------------------------------------------------------------------------
*/

$from_date = $_GET["from_date"] ?? date("Y-m-01");
$to_date   = $_GET["to_date"] ?? date("Y-m-d");

/*
|--------------------------------------------------------------------------
| GET ATTENDANCE
|--------------------------------------------------------------------------
*/

$sql = "SELECT
            sa.attendance_uid,
            sa.student_uid,
            s.student_name,
            s.admission_no,
            sa.attendance_date,
            sa.status,
            sa.marked_by_user_uid,
            sa.remarks
        FROM student_attendance sa
        INNER JOIN students s
            ON s.student_uid = sa.student_uid
        INNER JOIN student_academic sac
            ON sac.student_uid = sa.student_uid
           AND sac.school_uid = sa.school_uid
           AND sac.class_uid = sa.class_uid
           AND sac.section_uid = sa.section_uid
           AND sac.academic_year_uid = ?
        WHERE sa.school_uid = ?
          AND sa.class_uid = ?
          AND sa.section_uid = ?
          AND sa.attendance_date BETWEEN ? AND ?
        ORDER BY
            sa.attendance_date DESC,
            sac.roll_no ASC,
            s.student_name ASC";

$stmt = $conn->prepare($sql);

$stmt->bind_param(
    "ssssss",
    $academic_year_uid,
    $school_uid,
    $class_uid,
    $section_uid,
    $from_date,
    $to_date
);

$stmt->execute();

$attendance = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<title>Student Attendance History</title>

<style>

body {
    font-family: Arial, sans-serif;
    background: #f5f6fa;
    padding: 30px;
    margin: 0;
}

.container {
    max-width: 1200px;
    margin: auto;
}

.card {
    background: white;
    padding: 25px;
    border-radius: 10px;
    margin-bottom: 20px;
}

form {
    display: flex;
    gap: 15px;
    align-items: end;
    flex-wrap: wrap;
}

input {
    padding: 9px;
}

button {
    padding: 10px 18px;
    background: #007bff;
    color: white;
    border: none;
    border-radius: 5px;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th,
td {
    padding: 11px;
    border-bottom: 1px solid #eee;
    text-align: left;
}

th {
    background: #f1f1f1;
}

.present {
    color: green;
    font-weight: bold;
}

.absent {
    color: red;
    font-weight: bold;
}

.late {
    color: orange;
    font-weight: bold;
}

.btn {
    display: inline-block;
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

<h2>Student Attendance History</h2>

<p>
Teacher:
<strong><?= htmlspecialchars($teacher_name) ?></strong>
</p>

<p>
Class:
<strong><?= htmlspecialchars($class_uid) ?></strong>
&nbsp;&nbsp;

Section:
<strong><?= htmlspecialchars($section_uid) ?></strong>
&nbsp;&nbsp;

Subject:
<strong><?= htmlspecialchars($assignment["subject_uid"]) ?></strong>
</p>

<form method="GET">

<input
    type="hidden"
    name="assignment_uid"
    value="<?= htmlspecialchars($assignment_uid) ?>"
>

<div>
<label>From</label><br>

<input
    type="date"
    name="from_date"
    value="<?= htmlspecialchars($from_date) ?>"
>
</div>

<div>
<label>To</label><br>

<input
    type="date"
    name="to_date"
    value="<?= htmlspecialchars($to_date) ?>"
>
</div>

<div>

<button type="submit">
Filter
</button>

</div>

</form>

</div>


<div class="card">

<table>

<thead>

<tr>
    <th>Date</th>
    <th>Student</th>
    <th>Admission No</th>
    <th>Status</th>
    <th>Remarks</th>
    <th>Action</th>
</tr>

</thead>

<tbody>

<?php if (!empty($attendance)): ?>

<?php foreach ($attendance as $row): ?>

<tr>

<td>
<?= htmlspecialchars($row["attendance_date"]) ?>
</td>

<td>
<strong>
<?= htmlspecialchars($row["student_name"]) ?>
</strong>
</td>

<td>
<?= htmlspecialchars($row["admission_no"]) ?>
</td>

<td class="<?= htmlspecialchars($row["status"]) ?>">
<?= ucfirst(htmlspecialchars($row["status"])) ?>
</td>

<td>
<?= htmlspecialchars($row["remarks"] ?: "-") ?>
</td>

<td>

<a
    class="btn"
    href="edit.php?id=<?= urlencode($row["attendance_uid"]) ?>&assignment_uid=<?= urlencode($assignment_uid) ?>"
>
Edit
</a>

</td>

</tr>

<?php endforeach; ?>

<?php else: ?>

<tr>

<td colspan="6">
No attendance records found.
</td>

</tr>

<?php endif; ?>

</tbody>

</table>

<br>

<a class="btn" href="index.php">
← Back to Attendance
</a>

</div>

</div>

</body>

</html>