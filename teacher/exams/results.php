<?php

/*
|--------------------------------------------------------------------------
| TEACHER - EXAMS : VIEW MARKS STATUS (read only)
|--------------------------------------------------------------------------
| results.php?uid=<schedule_uid>
|
| Shows, for one exam + subject + class + section, how far the marks entry
| has reached: entered / pending students, absent, exempted, grades and the
| draft / submitted state of every student.
|
| Marks can only be changed from the Marks Entry module.
*/

require_once __DIR__ . "/includes/exam_common.php";

$schedule_uid = trim($_GET["uid"] ?? "");

$exam = examsFetchSchedule($conn, $school_uid, $teacher_uid, $schedule_uid);

if (!$exam) {

    ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Marks Status</title>

    <style>
    <?= examsModuleStyles() ?>
    </style>

    </head>

    <body>

    <div class="container">

        <div class="card">

            <a class="back-link" href="index.php">&larr; Back to Exam List</a>

            <h1>Marks Status</h1>

            <div class="error">
                Exam not found, or it does not belong to the classes, sections
                and subjects assigned to you.
            </div>

            <div class="toolbar">
                <a class="btn" href="index.php">Back to Exam List</a>
            </div>

        </div>

    </div>

    </body>

    </html>
    <?php

    exit();
}

/*
|--------------------------------------------------------------------------
| MARKS STATUS
|--------------------------------------------------------------------------
*/

$bundle = examsMarksStatusBundle(
    $conn,
    $school_uid,
    (string)$exam["exam_uid"],
    (string)$exam["subject_uid"],
    (string)$exam["class_uid"],
    (string)$exam["section_uid"],
    (string)$exam["academic_year_uid"]
);

$max_marks_value = ((float)$bundle["max_marks"] > 0)
    ? (float)$bundle["max_marks"]
    : (float)$exam["max_marks"];

$enter_marks_url = "../marks/index.php?action=load"
    . "&academic_year_uid=" . urlencode((string)$exam["academic_year_uid"])
    . "&class_uid=" . urlencode((string)$exam["class_uid"])
    . "&section_uid=" . urlencode((string)$exam["section_uid"])
    . "&subject_uid=" . urlencode((string)$exam["subject_uid"])
    . "&exam_uid=" . urlencode((string)$exam["exam_uid"])
    . "&term=" . urlencode((string)$exam["term"])
    . "&max_marks=" . urlencode(examsNumberLabel($exam["max_marks"]));

$detail_url = "view.php?uid=" . urlencode((string)$exam["schedule_uid"]);

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Marks Status</title>

<style>
<?= examsModuleStyles() ?>
</style>

</head>

<body>

<div class="container">

    <div class="card">

        <a class="back-link" href="<?= htmlspecialchars($detail_url) ?>">
            &larr; Back to Exam Details
        </a>

        <h1>Marks Status</h1>

        <div class="teacher-info">
            Teacher:
            <strong><?= htmlspecialchars($teacher_name) ?></strong>
        </div>

        <div class="detail-grid">

            <div class="detail-item">
                <span>Exam</span>
                <strong><?= htmlspecialchars((string)$exam["exam_name"]) ?></strong>
            </div>

            <div class="detail-item">
                <span>Class &amp; Section</span>
                <strong>
                    <?= htmlspecialchars((string)$exam["class_name"]) ?>
                    -
                    <?= htmlspecialchars((string)$exam["section_name"]) ?>
                </strong>
            </div>

            <div class="detail-item">
                <span>Subject</span>
                <strong><?= htmlspecialchars((string)$exam["subject_name"]) ?></strong>
            </div>

            <div class="detail-item">
                <span>Maximum Marks</span>
                <strong><?= htmlspecialchars(examsNumberLabel($max_marks_value)) ?></strong>
            </div>

            <div class="detail-item">
                <span>Exam Date</span>
                <strong><?= htmlspecialchars(examsDateLabel($exam["exam_date"])) ?></strong>
            </div>

            <div class="detail-item">
                <span>Exam Status</span>
                <strong><?= htmlspecialchars(examsScheduleStatus($exam)["label"]) ?></strong>
            </div>

        </div>

        <div class="summary" style="margin-top:18px;">

            <div class="summary-card accent-total">
                <span>Total Students</span>
                <strong><?= (int)$bundle["total"] ?></strong>
            </div>

            <div class="summary-card accent-completed">
                <span>Marks Entered</span>
                <strong><?= (int)$bundle["entered"] ?></strong>
            </div>

            <div class="summary-card accent-ongoing">
                <span>Pending</span>
                <strong><?= (int)$bundle["pending"] ?></strong>
            </div>

            <div class="summary-card accent-upcoming">
                <span>Absent</span>
                <strong><?= (int)$bundle["absent"] ?></strong>
            </div>

            <div class="summary-card accent-upcoming">
                <span>Exempted</span>
                <strong><?= (int)$bundle["exempted"] ?></strong>
            </div>

            <div class="summary-card accent-total">
                <span>Submission</span>
                <strong style="font-size:16px;">
                    <?= htmlspecialchars($bundle["status_label"]) ?>
                </strong>
            </div>

        </div>

        <div class="notice">
            Marks Entry status: <strong>Submitted</strong> =
            <?= (int)$bundle["submitted"] ?>,
            <strong>Draft</strong> = <?= (int)$bundle["draft"] ?>,
            <strong>Pending</strong> = <?= (int)$bundle["pending"] ?>.
            Marks are entered and changed only from the Marks Entry module.
        </div>

    </div>


    <div class="card">

        <h2>Student wise Marks Status</h2>

        <?php if ((int)$bundle["total"] === 0): ?>

            <div class="empty">
                No active students found in this class and section for the
                selected academic session.
            </div>

        <?php else: ?>

            <table>

                <thead>
                    <tr>
                        <th>#</th>
                        <th>Roll No</th>
                        <th>Admission No</th>
                        <th>Student Name</th>
                        <th>Marks Obtained</th>
                        <th>Max Marks</th>
                        <th>Grade</th>
                        <th>Marks Status</th>
                    </tr>
                </thead>

                <tbody>

                <?php $student_number = 0; ?>

                <?php foreach ($bundle["rows"] as $student_row): ?>

                    <?php
                    $student_number++;

                    if ($student_row["is_absent"]) {
                        $marks_label = "AB (Absent)";
                    } elseif ($student_row["is_exempt"]) {
                        $marks_label = "EX (Exempted)";
                    } elseif ($student_row["marks"] !== null) {
                        $marks_label = examsNumberLabel($student_row["marks"]);
                    } else {
                        $marks_label = "-";
                    }

                    if ($student_row["entry_status"] === "submitted") {
                        $entry_css   = "badge-submitted";
                        $entry_label = "Submitted";
                    } elseif ($student_row["entry_status"] === "draft") {
                        $entry_css   = "badge-draft";
                        $entry_label = "Draft";
                    } else {
                        $entry_css   = "badge-pending";
                        $entry_label = "Pending";
                    }
                    ?>

                    <tr>

                        <td><?= (int)$student_number ?></td>

                        <td>
                            <?= htmlspecialchars(
                                (trim($student_row["roll_no"]) !== "") ? $student_row["roll_no"] : "-"
                            ) ?>
                        </td>

                        <td><?= htmlspecialchars((string)$student_row["admission_no"]) ?></td>

                        <td><strong><?= htmlspecialchars((string)$student_row["student_name"]) ?></strong></td>

                        <td><?= htmlspecialchars($marks_label) ?></td>

                        <td><?= htmlspecialchars(examsNumberLabel($max_marks_value)) ?></td>

                        <td>
                            <?php if (trim((string)$student_row["grade"]) !== ""): ?>
                                <span class="grade-box"><?= htmlspecialchars((string)$student_row["grade"]) ?></span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="badge <?= htmlspecialchars($entry_css) ?>">
                                <?= htmlspecialchars($entry_label) ?>
                            </span>
                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        <?php endif; ?>

        <div class="toolbar no-print">

            <a class="btn btn-green" href="<?= htmlspecialchars($enter_marks_url) ?>">
                📝 Enter Marks
            </a>

            <a class="btn btn-outline" href="<?= htmlspecialchars($detail_url) ?>">
                👁️ Back to Exam Details
            </a>

            <button type="button" class="btn btn-secondary" onclick="window.print();">
                🖨️ Print
            </button>

            <a class="btn btn-outline" href="index.php">📋 Exam List</a>

        </div>

    </div>

</div>

</body>

</html>

