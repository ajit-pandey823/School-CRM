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
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check Submission</title>

    <style>

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f5f7fb;
            color: #1f2937;
        }

        .header {
            background: #ffffff;
            padding: 20px 25px;
            border-bottom: 1px solid #e5e7eb;
        }

        .header h1 { margin: 0; font-size: 26px; }

        .container { padding: 25px; }

        .back {
            display: inline-block;
            margin-bottom: 20px;
            text-decoration: none;
            color: #2563eb;
            font-weight: 600;
        }

        .card {
            background: #ffffff;
            padding: 26px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .card h2 { margin: 0 0 16px; }
        .card h3 { margin: 0 0 12px; font-size: 17px; color: #374151; }

        .info-line {
            color: #6b7280;
            font-size: 14px;
            margin: 6px 0;
        }

        .info-line strong { color: #111827; }

        .badge {
            display: inline-block;
            padding: 4px 11px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            vertical-align: middle;
        }

        .badge.pending { background: #fef3c7; color: #b45309; }
        .badge.submitted { background: #dbeafe; color: #1d4ed8; }
        .badge.checked { background: #dcfce7; color: #15803d; }
        .badge.late { background: #fee2e2; color: #b91c1c; }

        .banner {
            background: #dcfce7;
            color: #15803d;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-weight: 600;
        }

        .error-box {
            background: #fee2e2;
            color: #b91c1c;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-weight: 600;
        }

        .submission-box {
            background: #f8f9fa;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 16px;
            white-space: pre-wrap;
            line-height: 1.6;
            color: #374151;
            margin-bottom: 16px;
        }

        .form-group { margin-bottom: 16px; }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 14px;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            font-family: Arial, sans-serif;
            outline: none;
            background: #ffffff;
        }

        .form-group input:focus,
        .form-group textarea:focus { border-color: #2563eb; }

        .form-group textarea { min-height: 110px; resize: vertical; }

        .row2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #2563eb;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }

        .btn:hover { background: #1d4ed8; }

        .hint { color: #6b7280; font-size: 13px; margin-top: 5px; }

        .marks-line {
            font-size: 15px;
            margin: 8px 0;
            color: #374151;
        }

        .marks-line strong { color: #111827; }

    </style>

</head>

<body>

<div class="header">

    <h1>View / Check Submission</h1>

</div>

<div class="container">

    <a
        class="back"
        href="submissions.php?hw=<?php echo urlencode($homework_uid); ?>"
    >
        ← Back to Submissions
    </a>


    <?php if ($message !== ""): ?>

        <div class="banner">
            <?php echo htmlspecialchars($message); ?>
        </div>

    <?php endif; ?>


    <?php if ($error !== ""): ?>

        <div class="error-box">
            <?php echo htmlspecialchars($error); ?>
        </div>

    <?php endif; ?>


    <div class="card">

        <h2>
            <?php echo htmlspecialchars($student["student_name"]); ?>

            <span class="badge <?php echo $sub_state; ?>" style="margin-left:10px;">
                <?php echo ucfirst($sub_state); ?>
            </span>

            <?php if ($is_late): ?>

                <span class="badge late" style="margin-left:6px;">Late</span>

            <?php endif; ?>

        </h2>

        <p class="info-line">
            Admission No:
            <strong><?php echo htmlspecialchars($student["admission_no"]); ?></strong>
            &nbsp;|&nbsp;
            Roll No:
            <strong><?php echo htmlspecialchars($student["roll_no"] ?? "-"); ?></strong>
        </p>

        <p class="info-line">
            Homework:
            <strong><?php echo htmlspecialchars($homework["title"]); ?></strong>
        </p>

        <p class="info-line">
            Due:
            <strong><?php echo date("d M Y", strtotime($homework["due_date"])); ?></strong>
            &nbsp;|&nbsp;
            Max Marks:
            <strong>
                <?php echo htmlspecialchars(rtrim(rtrim((string) $homework["max_marks"], "0"), ".")); ?>
            </strong>
        </p>

    </div>


    <?php if ($sub_state === "pending"): ?>

        <div class="card">

            <h3>Submission Not Received Yet</h3>

            <p class="info-line">
                Record the submission received from this student.
            </p>

            <form method="POST">

                <?php echo csrfField(); ?>

                <input type="hidden" name="action" value="receive">

                <div class="form-group">

                    <label>Submission Text *</label>

                    <textarea
                        name="submission_text"
                        placeholder="Enter the homework solution / submission..."
                        required
                    ><?php echo htmlspecialchars($submission["submission_text"] ?? ""); ?></textarea>

                </div>

                <div class="form-group" style="max-width:300px;">

                    <label>Submitted Date *</label>

                    <input
                        type="date"
                        name="submitted_date"
                        value="<?php echo date("Y-m-d"); ?>"
                        required
                    >

                </div>

                <button class="btn" type="submit">
                    Mark Received
                </button>

            </form>

        </div>

    <?php else: ?>

        <div class="card">

            <h3>Submission</h3>

            <p class="info-line">
                Submitted On:
                <strong>
                    <?php echo date("d M Y", strtotime($submission["submitted_date"])); ?>
                </strong>
            </p>

            <div class="submission-box"><?php echo htmlspecialchars($submission["submission_text"] ?? "-"); ?></div>

            <?php if ($sub_state === "checked"): ?>

                <p class="marks-line">
                    Marks:
                    <strong>
                        <?php echo htmlspecialchars(rtrim(rtrim((string) ($submission["marks"] ?? "0"), "0"), ".")); ?>
                        /
                        <?php echo htmlspecialchars(rtrim(rtrim((string) $homework["max_marks"], "0"), ".")); ?>
                    </strong>
                </p>

                <p class="marks-line">
                    Feedback:
                    <strong>
                        <?php echo htmlspecialchars(trim($submission["feedback"] ?? "") !== "" ? $submission["feedback"] : "-"); ?>
                    </strong>
                </p>

                <p class="marks-line">
                    Checked At:
                    <strong>
                        <?php echo !empty($submission["checked_at"]) ? date("d M Y h:i A", strtotime($submission["checked_at"])) : "-"; ?>
                    </strong>
                </p>

            <?php endif; ?>

        </div>


        <div class="card">

            <h3>
                <?php echo $sub_state === "checked" ? "Update Check" : "Check Submission"; ?>
            </h3>

            <form method="POST">

                <?php echo csrfField(); ?>

                <input type="hidden" name="action" value="check">

                <div class="row2">

                    <div class="form-group">

                        <label>
                            Marks (0 -
                            <?php echo htmlspecialchars(rtrim(rtrim((string) $homework["max_marks"], "0"), ".")); ?>
                            ) *
                        </label>

                        <input
                            type="number"
                            name="marks"
                            min="0"
                            max="<?php echo htmlspecialchars($homework['max_marks'], ENT_QUOTES); ?>"
                            step="0.5"
                            value="<?php echo $sub_state === 'checked' && isset($submission['marks']) ? htmlspecialchars(rtrim(rtrim((string) $submission['marks'], '0'), '.')) : ''; ?>"
                            required
                        >

                    </div>

                </div>

                <div class="form-group">

                    <label>Feedback</label>

                    <textarea
                        name="feedback"
                        placeholder="Write feedback for the student..."
                    ><?php echo htmlspecialchars($submission["feedback"] ?? ""); ?></textarea>

                </div>

                <button class="btn" type="submit">
                    <?php echo $sub_state === "checked" ? "Update Check" : "Save Check"; ?>
                </button>

            </form>

        </div>

    <?php endif; ?>

</div>

</body>

</html>




