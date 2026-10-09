<?php

session_start();

require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../auth.php";

// LOGIN CHECK
if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit();
}

/* CSRF - sirf POST verify hota hai */
csrfVerify();

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
$student_uid = trim($_GET["student"] ?? "");

if ($homework_uid === "" || $student_uid === "") {
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

// GET STUDENT + VERIFY HE STUDIES IN HOMEWORK CLASS
$sql = "
    SELECT
        s.student_uid,
        s.student_name,
        s.admission_no,
        sa.roll_no
    FROM student_academic sa

    INNER JOIN students s
        ON s.student_uid = sa.student_uid

    WHERE sa.student_uid = ?
    AND sa.class_uid = ?
    AND sa.section_uid = ?
    AND sa.academic_year_uid = ?
    AND sa.status = 'active'

    LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param(
    "ssss",
    $student_uid,
    $homework["class_uid"],
    $homework["section_uid"],
    $homework["academic_year_uid"]
);
$stmt->execute();

$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    header("Location: submissions.php?hw=" . urlencode($homework_uid));
    exit();
}

// FETCH SUBMISSION (MAY NOT EXIST YET)
function fetchSubmissionRow($conn, $homework_uid, $student_uid)
{
    $sql = "
        SELECT *
        FROM homework_submissions
        WHERE homework_uid = ?
        AND student_uid = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("ss", $homework_uid, $student_uid);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

$submission = fetchSubmissionRow($conn, $homework_uid, $student_uid);

$message = "";
$error = "";

// FORM SUBMISSION
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = trim($_POST["action"] ?? "");

    // MARK RECEIVED (PENDING -> SUBMITTED)
    if ($action === "receive") {

        $text = trim($_POST["submission_text"] ?? "");
        $submitted_date = trim($_POST["submitted_date"] ?? date("Y-m-d"));

        if ($text === "") {
            $error = "Please enter the submission content.";
        } elseif (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $submitted_date)) {
            $error = "Please enter a valid submitted date.";
        } elseif ($submission && $submission["status"] !== "pending") {
            $error = "This submission is already recorded.";
        } else {

            if ($submission) {

                $sql = "
                    UPDATE homework_submissions
                    SET submission_text = ?,
                        submitted_date = ?,
                        status = 'submitted'
                    WHERE homework_uid = ?
                    AND student_uid = ?
                ";

                $stmt = $conn->prepare($sql);
                $stmt->bind_param(
                    "ssss",
                    $text,
                    $submitted_date,
                    $homework_uid,
                    $student_uid
                );
                $stmt->execute();
                $stmt->close();

            } else {

                $result = mysqli_query(
                    $conn,
                    "SELECT COALESCE(MAX(id), 0) + 1 AS next_id
                     FROM homework_submissions"
                );
                $row = mysqli_fetch_assoc($result);

                $submission_uid =
                    "HWS" . str_pad((string) $row["next_id"], 8, "0", STR_PAD_LEFT);

                $sql = "
                    INSERT INTO homework_submissions
                    (
                        submission_uid,
                        school_uid,
                        homework_uid,
                        student_uid,
                        submission_text,
                        submitted_date,
                        status
                    )
                    VALUES (?, ?, ?, ?, ?, ?, 'submitted')
                ";

                $stmt = $conn->prepare($sql);
                $stmt->bind_param(
                    "ssssss",
                    $submission_uid,
                    $homework["school_uid"],
                    $homework_uid,
                    $student_uid,
                    $text,
                    $submitted_date
                );
                $stmt->execute();
                $stmt->close();
            }

            $submission = fetchSubmissionRow($conn, $homework_uid, $student_uid);
            $message = "Submission marked as received.";
        }
    }

    // SAVE CHECK (MARKS + FEEDBACK)
    if ($action === "check") {

        $marks = trim($_POST["marks"] ?? "");
        $feedback = trim($_POST["feedback"] ?? "");

        if (!$submission || $submission["status"] === "pending") {
            $error = "Mark the submission as received before checking.";
        } elseif (
            $marks === "" ||
            !is_numeric($marks) ||
            $marks < 0 ||
            $marks > $homework["max_marks"]
        ) {
            $error =
                "Marks must be between 0 and " . $homework["max_marks"] . ".";
        } else {

            $sql = "
                UPDATE homework_submissions
                SET marks = ?,
                    feedback = ?,
                    status = 'checked',
                    checked_by_user_uid = ?,
                    checked_at = NOW()
                WHERE homework_uid = ?
                AND student_uid = ?
            ";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                "dssss",
                $marks,
                $feedback,
                $user_uid,
                $homework_uid,
                $student_uid
            );
            $stmt->execute();
            $stmt->close();

            $submission = fetchSubmissionRow($conn, $homework_uid, $student_uid);
            $message = "Submission checked and marks saved.";
        }
    }
}

// CURRENT STATE
$sub_state = "pending";

if ($submission) {
    $sub_state = $submission["status"];
}

$is_late = false;

if (
    $submission &&
    $submission["status"] !== "pending" &&
    !empty($submission["submitted_date"]) &&
    strtotime($submission["submitted_date"]) > strtotime($homework["due_date"])
) {
    $is_late = true;
}

?>

<?php
$UI_ROOT         = '../';
$UI_ACTIVE       = 'homework';
$UI_TITLE        = 'Check Submission';
$UI_CRUMBS       = [['label' => 'Homework', 'url' => 'index.php']];
$UI_PROFILE_NAME = $teacher_name;
include __DIR__ . '/../includes/header.php';
?>

<?php
$sub_badge = "bg-warning text-dark";

if ($sub_state === "submitted") {
    $sub_badge = "bg-info text-dark";
} elseif ($sub_state === "checked") {
    $sub_badge = "bg-success";
}
?>

<a
    class="btn btn-outline-secondary btn-sm mb-3"
    href="submissions.php?hw=<?php echo urlencode($homework_uid); ?>"
>
    &larr; Back to Submissions
</a>

<?php if ($message !== ""): ?>
    <div class="alert alert-success" role="alert">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<?php if ($error !== ""): ?>
    <div class="alert alert-danger" role="alert">
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header"><strong>View / Check Submission</strong></div>
    <div class="card-body">

        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <h5 class="mb-0">
                <?php echo htmlspecialchars($student["student_name"]); ?>
            </h5>
            <span class="badge <?php echo $sub_badge; ?>">
                <?php echo ucfirst($sub_state); ?>
            </span>
            <?php if ($is_late): ?>
                <span class="badge bg-danger">Late</span>
            <?php endif; ?>
        </div>

        <p class="text-body-secondary mb-1">
            Admission No:
            <strong><?php echo htmlspecialchars($student["admission_no"]); ?></strong>
            &nbsp;|&nbsp;
            Roll No:
            <strong><?php echo htmlspecialchars($student["roll_no"] ?? "-"); ?></strong>
        </p>

        <p class="text-body-secondary mb-1">
            Homework:
            <strong><?php echo htmlspecialchars($homework["title"]); ?></strong>
        </p>

        <p class="text-body-secondary mb-0">
            Due:
            <strong><?php echo date("d M Y", strtotime($homework["due_date"])); ?></strong>
            &nbsp;|&nbsp;
            Max Marks:
            <strong>
                <?php echo htmlspecialchars(rtrim(rtrim((string) $homework["max_marks"], "0"), ".")); ?>
            </strong>
        </p>

    </div>
</div>

<?php if ($sub_state === "pending"): ?>

    <div class="card mb-4">
        <div class="card-header"><strong>Submission Not Received Yet</strong></div>
        <div class="card-body">

            <p class="text-body-secondary">
                Record the submission received from this student.
            </p>

            <form method="POST">

                <?php echo csrfField(); ?>

                <input type="hidden" name="action" value="receive">

                <div class="mb-3">
                    <label class="form-label" for="submissionText">Submission Text *</label>
                    <textarea
                        class="form-control"
                        id="submissionText"
                        name="submission_text"
                        rows="5"
                        placeholder="Enter the homework solution / submission..."
                        required
                    ><?php echo htmlspecialchars($submission["submission_text"] ?? ""); ?></textarea>
                </div>

                <div class="mb-3" style="max-width: 300px;">
                    <label class="form-label" for="submittedDate">Submitted Date *</label>
                    <input
                        type="date"
                        class="form-control"
                        id="submittedDate"
                        name="submitted_date"
                        value="<?php echo date("Y-m-d"); ?>"
                        required
                    >
                </div>

                <button class="btn btn-primary" type="submit">
                    Mark Received
                </button>

            </form>

        </div>
    </div>

<?php else: ?>
    <div class="card mb-4">
        <div class="card-header"><strong>Submission</strong></div>
        <div class="card-body">

            <p class="text-body-secondary">
                Submitted On:
                <strong>
                    <?php echo date("d M Y", strtotime($submission["submitted_date"])); ?>
                </strong>
            </p>

            <div class="border rounded p-3 bg-light mb-3" style="white-space: pre-wrap;">
                <?php echo htmlspecialchars($submission["submission_text"] ?? "-"); ?>
            </div>

            <?php if ($sub_state === "checked"): ?>

                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <div class="text-body-secondary small">Marks</div>
                        <strong>
                            <?php echo htmlspecialchars(rtrim(rtrim((string) ($submission["marks"] ?? "0"), "0"), ".")); ?>
                            /
                            <?php echo htmlspecialchars(rtrim(rtrim((string) $homework["max_marks"], "0"), ".")); ?>
                        </strong>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="text-body-secondary small">Feedback</div>
                        <strong>
                            <?php echo htmlspecialchars(trim($submission["feedback"] ?? "") !== "" ? $submission["feedback"] : "-"); ?>
                        </strong>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="text-body-secondary small">Checked At</div>
                        <strong>
                            <?php echo !empty($submission["checked_at"]) ? date("d M Y h:i A", strtotime($submission["checked_at"])) : "-"; ?>
                        </strong>
                    </div>
                </div>

            <?php endif; ?>

        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <strong>
                <?php echo $sub_state === "checked" ? "Update Check" : "Check Submission"; ?>
            </strong>
        </div>
        <div class="card-body">

            <form method="POST">

                <?php echo csrfField(); ?>

                <input type="hidden" name="action" value="check">

                <div class="mb-3" style="max-width: 300px;">
                    <label class="form-label" for="marksInput">
                        Marks (0 -
                        <?php echo htmlspecialchars(rtrim(rtrim((string) $homework["max_marks"], "0"), ".")); ?>
                        ) *
                    </label>
                    <input
                        type="number"
                        class="form-control"
                        id="marksInput"
                        name="marks"
                        min="0"
                        max="<?php echo htmlspecialchars($homework['max_marks'], ENT_QUOTES); ?>"
                        step="0.5"
                        value="<?php echo $sub_state === 'checked' && isset($submission['marks']) ? htmlspecialchars(rtrim(rtrim((string) $submission['marks'], '0'), '.')) : ''; ?>"
                        required
                    >
                </div>

                <div class="mb-3">
                    <label class="form-label" for="feedbackInput">Feedback</label>
                    <textarea
                        class="form-control"
                        id="feedbackInput"
                        name="feedback"
                        rows="4"
                        placeholder="Write feedback for the student..."
                    ><?php echo htmlspecialchars($submission["feedback"] ?? ""); ?></textarea>
                </div>

                <button class="btn btn-primary" type="submit">
                    <?php echo $sub_state === "checked" ? "Update Check" : "Save Check"; ?>
                </button>

            </form>

        </div>
    </div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>

