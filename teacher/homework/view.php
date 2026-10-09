<?php

session_start();

require_once __DIR__ . "/../../config/db.php";

// LOGIN CHECK
if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit();
}

// GET TEACHER UID FROM LOGGED-IN USER
$user_uid = $_SESSION["user_uid"] ?? "";

$teacher_uid = "";
$teacher_name = "";

$sql_teacher = "
    SELECT teacher_uid, teacher_name
    FROM teachers
    WHERE user_uid = ?
    AND status = 'active'
    LIMIT 1
";

$stmt_teacher = $conn->prepare($sql_teacher);

if (!$stmt_teacher) {
    die("Teacher query error: " . $conn->error);
}

$stmt_teacher->bind_param("s", $user_uid);
$stmt_teacher->execute();

$result_teacher = $stmt_teacher->get_result();

if ($result_teacher->num_rows > 0) {
    $teacher = $result_teacher->fetch_assoc();
    $teacher_uid = $teacher["teacher_uid"];
    $teacher_name = $teacher["teacher_name"];
}

$stmt_teacher->close();

if ($teacher_uid === "") {
    die("Teacher profile not found.");
}

// GET HOMEWORK + VERIFY OWNER
$homework_uid = trim($_GET["hw"] ?? "");

if ($homework_uid === "") {
    header("Location: index.php");
    exit();
}

$sql = "
    SELECT
        h.*,
        c.class_name,
        sec.section_name,
        sub.subject_name,
        ay.year_name
    FROM homework h

    INNER JOIN classes c
        ON c.class_uid = h.class_uid

    INNER JOIN sections sec
        ON sec.section_uid = h.section_uid

    INNER JOIN subjects sub
        ON sub.subject_uid = h.subject_uid

    INNER JOIN academic_years ay
        ON ay.academic_year_uid = h.academic_year_uid

    WHERE h.homework_uid = ?
    AND h.teacher_uid = ?

    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Homework query error: " . $conn->error);
}

$stmt->bind_param("ss", $homework_uid, $teacher_uid);
$stmt->execute();

$homework = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$homework) {
    header("Location: index.php");
    exit();
}

// STATS
$total_students = 0;

$sql = "
    SELECT COUNT(*) AS total
    FROM student_academic
    WHERE class_uid = ?
    AND section_uid = ?
    AND academic_year_uid = ?
    AND status = 'active'
";

$stmt = $conn->prepare($sql);
$stmt->bind_param(
    "sss",
    $homework["class_uid"],
    $homework["section_uid"],
    $homework["academic_year_uid"]
);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$total_students = (int) $row["total"];
$stmt->close();

$submitted_count = 0;
$checked_count = 0;
$late_count = 0;

$sql = "
    SELECT
        SUM(status = 'submitted') AS submitted_count,
        SUM(status = 'checked') AS checked_count,
        SUM(
            status = 'submitted'
            AND submitted_date > ?
        ) AS late_count
    FROM homework_submissions
    WHERE homework_uid = ?
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $homework["due_date"], $homework_uid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$submitted_count = (int) ($row["submitted_count"] ?? 0);
$checked_count = (int) ($row["checked_count"] ?? 0);
$late_count = (int) ($row["late_count"] ?? 0);

$pending_count = $total_students - $submitted_count - $checked_count;

if ($pending_count < 0) {
    $pending_count = 0;
}

$done_count = $total_students - $pending_count;

$progress = 0;

if ($total_students > 0) {
    $progress = (int) round($done_count * 100 / $total_students);
}

$state = homeworkStateDefined($homework);

function homeworkStateDefined($hw)
{
    if ($hw["status"] !== "active") {
        return "inactive";
    }

    if (strtotime($hw["due_date"]) < strtotime(date("Y-m-d"))) {
        return "closed";
    }

    return "open";
}

function homeworkStateLabelDefined($state)
{
    if ($state === "open") {
        return "Open";
    }

    if ($state === "closed") {
        return "Closed";
    }

    return "Inactive";
}

?>

<?php
$UI_ROOT         = '../';
$UI_ACTIVE       = 'homework';
$UI_TITLE        = 'Homework Details';
$UI_CRUMBS       = [['label' => 'Homework', 'url' => 'index.php']];
$UI_PROFILE_NAME = $teacher_name;
include __DIR__ . '/../includes/header.php';
?>

<?php
$state_badge = "bg-secondary";

if ($state === "open") {
    $state_badge = "bg-success";
} elseif ($state === "inactive") {
    $state_badge = "bg-danger";
}
?>

<a class="btn btn-outline-secondary btn-sm mb-3" href="index.php">
    &larr; Back to Homework
</a>

<div class="card mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <strong>Homework Details</strong>
        <span class="d-flex flex-wrap gap-2">
            <span class="badge <?php echo $state_badge; ?>">
                <?php echo homeworkStateLabelDefined($state); ?>
            </span>
            <span class="badge bg-light text-dark border">
                <?php echo htmlspecialchars($homework["subject_name"]); ?>
            </span>
        </span>
    </div>
    <div class="card-body">

        <h4 class="mb-3">
            <?php echo htmlspecialchars($homework["title"]); ?>
        </h4>

        <div class="row g-3">
            <div class="col-6 col-md-4 col-xl-3">
                <div class="border rounded p-3 bg-light h-100">
                    <div class="text-body-secondary small mb-1">Class</div>
                    <strong><?php echo htmlspecialchars($homework["class_name"]); ?></strong>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-3">
                <div class="border rounded p-3 bg-light h-100">
                    <div class="text-body-secondary small mb-1">Section</div>
                    <strong><?php echo htmlspecialchars($homework["section_name"]); ?></strong>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-3">
                <div class="border rounded p-3 bg-light h-100">
                    <div class="text-body-secondary small mb-1">Subject</div>
                    <strong><?php echo htmlspecialchars($homework["subject_name"]); ?></strong>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-3">
                <div class="border rounded p-3 bg-light h-100">
                    <div class="text-body-secondary small mb-1">Academic Year</div>
                    <strong><?php echo htmlspecialchars($homework["year_name"]); ?></strong>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-3">
                <div class="border rounded p-3 bg-light h-100">
                    <div class="text-body-secondary small mb-1">Assigned Date</div>
                    <strong><?php echo date("d M Y", strtotime($homework["assigned_date"])); ?></strong>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-3">
                <div class="border rounded p-3 bg-light h-100">
                    <div class="text-body-secondary small mb-1">Due Date</div>
                    <strong><?php echo date("d M Y", strtotime($homework["due_date"])); ?></strong>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-3">
                <div class="border rounded p-3 bg-light h-100">
                    <div class="text-body-secondary small mb-1">Max Marks</div>
                    <strong><?php echo htmlspecialchars(rtrim(rtrim((string) $homework["max_marks"], "0"), ".")); ?></strong>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-3">
                <div class="border rounded p-3 bg-light h-100">
                    <div class="text-body-secondary small mb-1">Total Students</div>
                    <strong><?php echo $total_students; ?></strong>
                </div>
            </div>
        </div>

        <?php if (trim($homework["description"] ?? "") !== ""): ?>

            <div class="text-body-secondary small mt-4 mb-1">Description</div>
            <div class="border rounded p-3 bg-light" style="white-space: pre-wrap;">
                <?php echo htmlspecialchars($homework["description"]); ?>
            </div>

        <?php endif; ?>

    </div>
</div>
<div class="card mb-4">
    <div class="card-header"><strong>Submission Summary</strong></div>
    <div class="card-body">

        <div class="row g-3">
            <div class="col-6 col-md">
                <div class="border rounded p-3 bg-light text-center h-100">
                    <div class="text-body-secondary small">Total Students</div>
                    <div class="fs-5 fw-semibold"><?php echo $total_students; ?></div>
                </div>
            </div>
            <div class="col-6 col-md">
                <div class="border rounded p-3 bg-light text-center h-100">
                    <div class="text-body-secondary small">Submitted</div>
                    <div class="fs-5 fw-semibold"><?php echo $submitted_count; ?></div>
                </div>
            </div>
            <div class="col-6 col-md">
                <div class="border rounded p-3 bg-light text-center h-100">
                    <div class="text-body-secondary small">Checked</div>
                    <div class="fs-5 fw-semibold"><?php echo $checked_count; ?></div>
                </div>
            </div>
            <div class="col-6 col-md">
                <div class="border rounded p-3 bg-light text-center h-100">
                    <div class="text-body-secondary small">Pending</div>
                    <div class="fs-5 fw-semibold"><?php echo $pending_count; ?></div>
                </div>
            </div>
            <div class="col-6 col-md">
                <div class="border rounded p-3 bg-light text-center h-100">
                    <div class="text-body-secondary small">Late</div>
                    <div class="fs-5 fw-semibold"><?php echo $late_count; ?></div>
                </div>
            </div>
        </div>

        <div class="mt-4">
            <div class="text-body-secondary small mb-1">
                Completion: <?php echo $progress; ?>%
                (<?php echo $done_count; ?> of <?php echo $total_students; ?> students)
            </div>
            <div class="progress" style="height: 10px;">
                <div
                    class="progress-bar"
                    role="progressbar"
                    style="width: <?php echo $progress; ?>%;"
                    aria-valuenow="<?php echo $progress; ?>"
                    aria-valuemin="0"
                    aria-valuemax="100"
                ></div>
            </div>
        </div>

    </div>
</div>

<div class="d-flex flex-wrap gap-2 mb-4">
    <a
        class="btn btn-primary"
        href="submissions.php?hw=<?php echo urlencode($homework["homework_uid"]); ?>"
    >
        Student Submissions
    </a>
    <a
        class="btn btn-outline-primary"
        href="edit.php?hw=<?php echo urlencode($homework["homework_uid"]); ?>"
    >
        Edit Homework
    </a>
    <a class="btn btn-outline-secondary" href="index.php">
        All Homework
    </a>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

