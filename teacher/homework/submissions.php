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
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Submissions</title>

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

        .title-box {
            background: #ffffff;
            padding: 22px 25px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .title-box h2 { margin: 0 0 6px; }
        .title-box p { margin: 4px 0 0; color: #6b7280; font-size: 14px; }

        .summary {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 14px;
        }

        .sum-chip {
            background: #f3f4f6;
            border-radius: 8px;
            padding: 8px 14px;
            font-size: 13px;
            color: #374151;
        }

        .sum-chip strong { color: #111827; }

        .table-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 8px 22px 18px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 750px;
        }

        th, td {
            padding: 13px 10px;
            border-bottom: 1px solid #eef0f2;
            text-align: left;
            font-size: 14px;
        }

        th {
            background: #f8f9fa;
            color: #4b5563;
            font-size: 13px;
        }

        tr:last-child td { border-bottom: none; }

        .badge {
            display: inline-block;
            padding: 4px 11px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge.pending { background: #fef3c7; color: #b45309; }
        .badge.submitted { background: #dbeafe; color: #1d4ed8; }
        .badge.checked { background: #dcfce7; color: #15803d; }
        .badge.late { background: #fee2e2; color: #b91c1c; }

        .link {
            color: #2563eb;
            font-weight: 600;
            text-decoration: none;
            white-space: nowrap;
        }

        .link:hover { text-decoration: underline; }

        .muted { color: #9ca3af; }

        .empty {
            background: #ffffff;
            padding: 40px;
            border-radius: 12px;
            text-align: center;
            color: #6b7280;
        }

        .empty h3 { margin: 0 0 8px; color: #4b5563; }

    </style>

</head>

<body>

<div class="header">

    <h1>Student Submissions</h1>

</div>

<div class="container">

    <a
        class="back"
        href="view.php?hw=<?php echo urlencode($homework["homework_uid"]); ?>"
    >
        ← Back to Homework Details
    </a>


    <div class="title-box">

        <div>

            <h2>
                <?php echo htmlspecialchars($homework["title"]); ?>
            </h2>

            <p>
                Class
                <?php echo htmlspecialchars($homework["class_uid"]); ?>
                &nbsp;•&nbsp;
                Section
                <?php echo htmlspecialchars($homework["section_uid"]); ?>
                &nbsp;•&nbsp;
                Due:
                <?php echo date("d M Y", strtotime($homework["due_date"])); ?>
            </p>

            <div class="summary">

                <span class="sum-chip">
                    Total: <strong><?php echo $summary["total"]; ?></strong>
                </span>

                <span class="sum-chip">
                    Pending: <strong><?php echo $summary["pending"]; ?></strong>
                </span>

                <span class="sum-chip">
                    Submitted: <strong><?php echo $summary["submitted"]; ?></strong>
                </span>

                <span class="sum-chip">
                    Checked: <strong><?php echo $summary["checked"]; ?></strong>
                </span>

                <span class="sum-chip">
                    Late: <strong><?php echo $summary["late"]; ?></strong>
                </span>

            </div>

        </div>

    </div>


    <?php if (count($rows) === 0): ?>

        <div class="empty">

            <h3>No Students Found</h3>

            <p>
                No active students were found
                in this class.
            </p>

        </div>

    <?php else: ?>

        <div class="table-card">

            <table>

                <thead>

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

                                <span class="badge <?php echo $sub_state; ?>">
                                    <?php
                                    echo ucfirst($sub_state);
                                    ?>
                                </span>

                                <?php if ($is_late_row): ?>

                                    <span class="badge late">Late</span>

                                <?php endif; ?>

                            </td>

                            <td>
                                <?php if (!empty($row["submitted_date"])): ?>
                                    <?php echo date("d M Y", strtotime($row["submitted_date"])); ?>
                                <?php else: ?>
                                    <span class="muted">-</span>
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
                                    <span class="muted">-</span>
                                <?php endif; ?>
                            </td>

                            <td>

                                <a
                                    class="link"
                                    href="check.php?hw=<?php echo urlencode($homework["homework_uid"]); ?>&student=<?php echo urlencode($row["student_uid"]); ?>"
                                >
                                    View / Check →
                                </a>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php endif; ?>

</div>

</body>

</html>


