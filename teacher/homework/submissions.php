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
    $teacher_name = $teacher["teacher_name"] ?? "";
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
    SELECT h.*
    FROM homework h
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

// STUDENTS + SUBMISSION STATUS
$rows = [];

$sql = "
    SELECT
        s.student_uid,
        s.student_name,
        s.admission_no,
        sa.roll_no,
        hs.submission_uid,
        hs.status AS sub_status,
        hs.submitted_date,
        hs.marks,
        hs.feedback
    FROM student_academic sa

    INNER JOIN students s
        ON s.student_uid = sa.student_uid

    LEFT JOIN homework_submissions hs
        ON hs.homework_uid = ?
        AND hs.student_uid = s.student_uid

    WHERE sa.school_uid = ?
    AND sa.class_uid = ?
    AND sa.section_uid = ?
    AND sa.academic_year_uid = ?
    AND sa.status = 'active'
    AND s.status = 'active'

    ORDER BY
        CAST(sa.roll_no AS UNSIGNED) ASC,
        s.student_name ASC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Students query error: " . $conn->error);
}

$stmt->bind_param(
    "sssss",
    $homework_uid,
    $homework["school_uid"],
    $homework["class_uid"],
    $homework["section_uid"],
    $homework["academic_year_uid"]
);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// SUMMARY
$summary = [
    "total" => count($rows),
    "pending" => 0,
    "submitted" => 0,
    "checked" => 0,
    "late" => 0,
];

function submissionState($row, $due_date)
{
    if (empty($row["sub_status"]) || $row["sub_status"] === "pending") {
        return "pending";
    }

    if ($row["sub_status"] === "checked") {
        return "checked";
    }

    return "submitted";
}

foreach ($rows as $row) {

    $sub_state = submissionState($row, $homework["due_date"]);

    $summary[$sub_state]++;

    if (
        $sub_state === "submitted" &&
        !empty($row["submitted_date"]) &&
        strtotime($row["submitted_date"]) > strtotime($homework["due_date"])
    ) {
        $summary["late"]++;
    }
}

?>

<?php
$UI_ROOT         = '../';
$UI_ACTIVE       = 'homework';
$UI_TITLE        = 'Student Submissions';
$UI_CRUMBS       = [['label' => 'Homework', 'url' => 'index.php']];
$UI_PROFILE_NAME = $teacher_name;
include __DIR__ . '/../includes/header.php';
?>

<a
    class="btn btn-outline-secondary btn-sm mb-3"
    href="view.php?hw=<?php echo urlencode($homework["homework_uid"]); ?>"
>
    &larr; Back to Homework Details
</a>

<div class="card mb-4">
    <div class="card-header"><strong>Student Submissions</strong></div>
    <div class="card-body">

        <h5 class="mb-1">
            <?php echo htmlspecialchars($homework["title"]); ?>
        </h5>

        <p class="text-body-secondary">
            Class
            <?php echo htmlspecialchars($homework["class_uid"]); ?>
            &bull; Section
            <?php echo htmlspecialchars($homework["section_uid"]); ?>
            &bull; Due:
            <?php echo date("d M Y", strtotime($homework["due_date"])); ?>
        </p>

        <div class="d-flex flex-wrap gap-2">
            <span class="badge bg-light text-dark border">
                Total: <strong><?php echo $summary["total"]; ?></strong>
            </span>
            <span class="badge bg-warning text-dark">
                Pending: <strong><?php echo $summary["pending"]; ?></strong>
            </span>
            <span class="badge bg-info text-dark">
                Submitted: <strong><?php echo $summary["submitted"]; ?></strong>
            </span>
            <span class="badge bg-success">
                Checked: <strong><?php echo $summary["checked"]; ?></strong>
            </span>
            <span class="badge bg-danger">
                Late: <strong><?php echo $summary["late"]; ?></strong>
            </span>
        </div>

    </div>
</div>

<?php if (count($rows) === 0): ?>

    <div class="alert alert-warning" role="alert">
        <strong>No Students Found</strong><br>
        No active students were found in this class.
    </div>

<?php else: ?>

    <div class="card mb-4">
        <div class="card-header"><strong>Student List</strong></div>
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Roll No</th>
                        <th>Student</th>
                        <th>Admission No</th>
                        <th>Status</th>
                        <th>Submitted On</th>
                        <th>Marks</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>

                        <?php

                        $sub_state = submissionState($row, $homework["due_date"]);

                        $is_late_row = false;

                        if (
                            $sub_state === "submitted" &&
                            !empty($row["submitted_date"]) &&
                            strtotime($row["submitted_date"]) >
                                strtotime($homework["due_date"])
                        ) {
                            $is_late_row = true;
                        }

                        $row_badge = "bg-warning text-dark";

                        if ($sub_state === "submitted") {
                            $row_badge = "bg-info text-dark";
                        } elseif ($sub_state === "checked") {
                            $row_badge = "bg-success";
                        }

                        ?>

                        <tr>

                            <td>
                                <?php echo htmlspecialchars($row["roll_no"] ?? "-"); ?>
                            </td>

                            <td>
                                <strong>
                                    <?php echo htmlspecialchars($row["student_name"]); ?>
                                </strong>
                            </td>

                            <td>
                                <?php echo htmlspecialchars($row["admission_no"]); ?>
                            </td>

                            <td>
                                <span class="badge <?php echo $row_badge; ?>">
                                    <?php echo ucfirst($sub_state); ?>
                                </span>

                                <?php if ($is_late_row): ?>
                                    <span class="badge bg-danger">Late</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if (!empty($row["submitted_date"])): ?>
                                    <?php echo date("d M Y", strtotime($row["submitted_date"])); ?>
                                <?php else: ?>
                                    <span class="text-body-secondary">-</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($sub_state === "checked" && $row["marks"] !== null): ?>
                                    <?php
                                    echo htmlspecialchars(
                                        rtrim(rtrim((string) $row["marks"], "0"), ".")
                                    );
                                    ?>
                                    /
                                    <?php
                                    echo htmlspecialchars(
                                        rtrim(rtrim((string) $homework["max_marks"], "0"), ".")
                                    );
                                    ?>
                                <?php else: ?>
                                    <span class="text-body-secondary">-</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <a
                                    class="btn btn-sm btn-outline-primary"
                                    href="check.php?hw=<?php echo urlencode($homework["homework_uid"]); ?>&student=<?php echo urlencode($row["student_uid"]); ?>"
                                >
                                    View / Check
                                </a>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>
    </div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>

