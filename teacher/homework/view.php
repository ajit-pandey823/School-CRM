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
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Homework Details</title>

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

        .hw-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            flex-wrap: wrap;
        }

        .hw-head h2 { margin: 0; }

        .badges { display: flex; gap: 8px; flex-wrap: wrap; }

        .badge {
            display: inline-block;
            padding: 4px 11px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge.open { background: #dcfce7; color: #15803d; }
        .badge.closed { background: #e5e7eb; color: #4b5563; }
        .badge.inactive { background: #fee2e2; color: #b91c1c; }
        .badge.subject { background: #e7f1ff; color: #2563eb; }

        .details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-top: 20px;
        }

        .detail {
            background: #f8f9fa;
            padding: 13px 15px;
            border-radius: 8px;
        }

        .detail label {
            display: block;
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 5px;
        }

        .detail strong { font-size: 15px; color: #111827; word-break: break-word; }

        .desc {
            margin-top: 20px;
            padding: 16px;
            background: #f8f9fa;
            border-radius: 8px;
            white-space: pre-wrap;
            color: #374151;
            line-height: 1.6;
        }

        .desc-title {
            margin: 22px 0 8px;
            font-size: 15px;
            color: #374151;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }

        .stat {
            background: #f8f9fa;
            padding: 16px;
            border-radius: 8px;
            text-align: center;
        }

        .stat label { color: #6b7280; font-size: 13px; }
        .stat .num { font-size: 24px; font-weight: bold; margin-top: 6px; }

        .progress-wrap { margin-top: 18px; }

        .progress-wrap label { font-size: 13px; color: #6b7280; }

        .progress {
            background: #e5e7eb;
            border-radius: 8px;
            height: 12px;
            overflow: hidden;
            margin-top: 8px;
        }

        .progress span {
            display: block;
            height: 100%;
            background: #2563eb;
        }

        .actions { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 4px; }

        .btn {
            display: inline-block;
            padding: 12px 22px;
            background: #2563eb;
            color: #ffffff;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
        }

        .btn:hover { background: #1d4ed8; }

        .btn.gray { background: #6b7280; }
        .btn.gray:hover { background: #4b5563; }

    </style>

</head>

<body>

<div class="header">

    <h1>Homework Details</h1>

</div>

<div class="container">

    <a href="index.php" class="back">
        ← Back to Homework
    </a>


    <div class="card">

        <div class="hw-head">

            <h2>
                <?php echo htmlspecialchars($homework["title"]); ?>
            </h2>

            <div class="badges">

                <span class="badge <?php echo $state; ?>">
                    <?php echo homeworkStateLabelDefined($state); ?>
                </span>

                <span class="badge subject">
                    <?php echo htmlspecialchars($homework["subject_name"]); ?>
                </span>

            </div>

        </div>

        <div class="details">

            <div class="detail">
                <label>Class</label>
                <strong><?php echo htmlspecialchars($homework["class_name"]); ?></strong>
            </div>

            <div class="detail">
                <label>Section</label>
                <strong><?php echo htmlspecialchars($homework["section_name"]); ?></strong>
            </div>

            <div class="detail">
                <label>Subject</label>
                <strong><?php echo htmlspecialchars($homework["subject_name"]); ?></strong>
            </div>

            <div class="detail">
                <label>Academic Year</label>
                <strong><?php echo htmlspecialchars($homework["year_name"]); ?></strong>
            </div>

            <div class="detail">
                <label>Assigned Date</label>
                <strong><?php echo date("d M Y", strtotime($homework["assigned_date"])); ?></strong>
            </div>

            <div class="detail">
                <label>Due Date</label>
                <strong><?php echo date("d M Y", strtotime($homework["due_date"])); ?></strong>
            </div>

            <div class="detail">
                <label>Max Marks</label>
                <strong><?php echo htmlspecialchars(rtrim(rtrim((string) $homework["max_marks"], "0"), ".")); ?></strong>
            </div>

            <div class="detail">
                <label>Total Students</label>
                <strong><?php echo $total_students; ?></strong>
            </div>

        </div>

        <?php if (trim($homework["description"] ?? "") !== ""): ?>

            <p class="desc-title">Description</p>

            <div class="desc">
                <?php echo htmlspecialchars($homework["description"]); ?>
            </div>

        <?php endif; ?>

    </div>


    <div class="card">

        <h3>Submission Summary</h3>

        <div class="stats">

            <div class="stat">
                <label>Total Students</label>
                <div class="num"><?php echo $total_students; ?></div>
            </div>

            <div class="stat">
                <label>Submitted</label>
                <div class="num"><?php echo $submitted_count; ?></div>
            </div>

            <div class="stat">
                <label>Checked</label>
                <div class="num"><?php echo $checked_count; ?></div>
            </div>

            <div class="stat">
                <label>Pending</label>
                <div class="num"><?php echo $pending_count; ?></div>
            </div>

            <div class="stat">
                <label>Late</label>
                <div class="num"><?php echo $late_count; ?></div>
            </div>

        </div>

        <div class="progress-wrap">

            <label>
                Completion: <?php echo $progress; ?>%
                (<?php echo $done_count; ?> of <?php echo $total_students; ?> students)
            </label>

            <div class="progress">
                <span style="width: <?php echo $progress; ?>%"></span>
            </div>

        </div>

    </div>


    <div class="actions">

        <a
            class="btn"
            href="submissions.php?hw=<?php echo urlencode($homework["homework_uid"]); ?>"
        >
            Student Submissions
        </a>

        <a
            class="btn gray"
            href="edit.php?hw=<?php echo urlencode($homework["homework_uid"]); ?>"
        >
            Edit Homework
        </a>

        <a class="btn gray" href="index.php">
            All Homework
        </a>

    </div>

</div>

</body>

</html>


