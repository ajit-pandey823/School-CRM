<?php

/*
|--------------------------------------------------------------------------
| TEACHER - EXAMS : EXAM DETAILS (read only)
|--------------------------------------------------------------------------
| Flow: Dashboard > Exams > Exam List > Exam Details > Enter Marks
|
| Shows full exam information for one exam of the teacher's assigned classes
| and offers the only actions a teacher has:
|
|     View Details | View / Download Syllabus | View Schedule |
|     Enter Marks  | View Marks Status
|
| There is no add / edit / delete exam and no schedule change here.
*/

require_once __DIR__ . "/includes/exam_common.php";

$schedule_uid = trim($_GET["uid"] ?? "");

/* returns null when the exam does not exist or is not this teacher's */
$exam = examsFetchSchedule($conn, $school_uid, $teacher_uid, $schedule_uid);

if (!$exam) {

    ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Exam Details</title>

    <style>
    <?= examsModuleStyles() ?>
    </style>

    </head>

    <body>

    <div class="container">

        <div class="card">

            <a class="back-link" href="index.php">&larr; Back to Exam List</a>

            <h1>Exam Details</h1>

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
| TEACHER ACTIONS (only the five read only / marks actions)
|--------------------------------------------------------------------------
*/

$enter_marks_url = "../marks/index.php?action=load"
    . "&academic_year_uid=" . urlencode((string)$exam["academic_year_uid"])
    . "&class_uid=" . urlencode((string)$exam["class_uid"])
    . "&section_uid=" . urlencode((string)$exam["section_uid"])
    . "&subject_uid=" . urlencode((string)$exam["subject_uid"])
    . "&exam_uid=" . urlencode((string)$exam["exam_uid"])
    . "&term=" . urlencode((string)$exam["term"])
    . "&max_marks=" . urlencode(examsNumberLabel($exam["max_marks"]));

$syllabus_url = "syllabus.php?uid=" . urlencode((string)$exam["schedule_uid"]);

$marks_status_url = "results.php?uid=" . urlencode((string)$exam["schedule_uid"]);

/* schedule of the same class + section for the exam week */
$week_from = "";
$week_to   = "";

if (trim((string)$exam["exam_date"]) !== "" && $exam["exam_date"] !== "0000-00-00") {

    $exam_stamp = strtotime((string)$exam["exam_date"]);

    if ($exam_stamp !== false) {
        $week_from = date("Y-m-d", strtotime("-7 days", $exam_stamp));
        $week_to   = date("Y-m-d", strtotime("+7 days", $exam_stamp));
    }
}

$schedule_url = "schedule.php"
    . "?academic_year_uid=" . urlencode((string)$exam["academic_year_uid"])
    . "&class_uid=" . urlencode((string)$exam["class_uid"])
    . "&section_uid=" . urlencode((string)$exam["section_uid"])
    . (($week_from !== "") ? "&date_from=" . urlencode($week_from) : "")
    . (($week_to !== "") ? "&date_to=" . urlencode($week_to) : "");

/*
|--------------------------------------------------------------------------
| MARKS STATUS FOR THIS EXAM
|--------------------------------------------------------------------------
*/

$marks_bundle = examsMarksStatusBundle(
    $conn,
    $school_uid,
    (string)$exam["exam_uid"],
    (string)$exam["subject_uid"],
    (string)$exam["class_uid"],
    (string)$exam["section_uid"],
    (string)$exam["academic_year_uid"]
);

$exam_status = examsScheduleStatus($exam);

$syllabus_text     = trim((string)$exam["syllabus"]);
$instructions_text = trim((string)$exam["instructions"]);

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Exam Details</title>

<style>
<?= examsModuleStyles() ?>
</style>

</head>

<body>

<div class="container">

    <!-- ================= HEADER ================= -->

    <div class="card">

        <a class="back-link" href="index.php">&larr; Back to Exam List</a>

        <h1><?= htmlspecialchars((string)$exam["exam_name"]) ?></h1>

        <div class="teacher-info">
            Teacher:
            <strong><?= htmlspecialchars($teacher_name) ?></strong>
        </div>

        <div class="toolbar" style="margin-top:10px;">

            <span class="badge <?= htmlspecialchars($exam_status["css"]) ?>">
                <?= htmlspecialchars($exam_status["label"]) ?>
            </span>

            <span class="muted">
                <?= htmlspecialchars((string)$exam["class_name"]) ?>
                -
                <?= htmlspecialchars((string)$exam["section_name"]) ?>
                &middot;
                <?= htmlspecialchars((string)$exam["subject_name"]) ?>
                &middot;
                <?= htmlspecialchars(examsDateLabel($exam["exam_date"])) ?>
            </span>

        </div>

        <div class="notice" style="margin-top:15px;">
            This page is read only. To add, edit or delete an exam or to change
            the exam schedule, please contact the school administrator.
        </div>

    </div>

    <!-- ================= 5. TEACHER ACTIONS ================= -->

    <div class="card">

        <h2>Teacher Actions</h2>

        <div class="actions">

            <a class="action-btn is-current" href="view.php?uid=<?= urlencode((string)$exam["schedule_uid"]) ?>">
                👁️ View Details
                <span class="hint">Exam information, syllabus and instructions</span>
            </a>

            <a class="action-btn" href="<?= htmlspecialchars($syllabus_url) ?>">
                📄 View / Download Syllabus
                <span class="hint">Open the syllabus or download it</span>
            </a>

            <a class="action-btn" href="<?= htmlspecialchars($schedule_url) ?>">
                📅 View Schedule
                <span class="hint">Exam dates for this class and section</span>
            </a>

            <a class="action-btn" href="<?= htmlspecialchars($enter_marks_url) ?>">
                📝 Enter Marks
                <span class="hint">Open Marks Entry with this exam pre-selected</span>
            </a>

            <a class="action-btn" href="<?= htmlspecialchars($marks_status_url) ?>">
                📊 View Marks Status
                <span class="hint">Who is entered, who is still pending</span>
            </a>

        </div>

    </div>

    <!-- ================= 4. EXAM DETAILS ================= -->

    <div class="card">

        <h2>Exam Information</h2>

        <div class="detail-grid">

            <div class="detail-item">
                <span>Exam Name</span>
                <strong><?= htmlspecialchars((string)$exam["exam_name"]) ?></strong>
            </div>

            <div class="detail-item">
                <span>Exam Type</span>
                <strong><?= htmlspecialchars(ucfirst((string)$exam["exam_type"])) ?></strong>
            </div>

            <div class="detail-item">
                <span>Term</span>
                <strong>
                    <?= htmlspecialchars((trim((string)$exam["term"]) !== "") ? (string)$exam["term"] : "-") ?>
                </strong>
            </div>

            <div class="detail-item">
                <span>Academic Session</span>
                <strong>
                    <?= htmlspecialchars(
                        (trim((string)$exam["year_name"]) !== "")
                            ? (string)$exam["year_name"]
                            : (string)$exam["academic_year_uid"]
                    ) ?>
                </strong>
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
                <span>Exam Date</span>
                <strong><?= htmlspecialchars(examsDateLabel($exam["exam_date"])) ?></strong>
            </div>

            <div class="detail-item">
                <span>Time</span>
                <strong><?= htmlspecialchars(examsTimeLabel($exam["start_time"])) ?></strong>
            </div>

            <div class="detail-item">
                <span>Duration</span>
                <strong>
                    <?= htmlspecialchars(examsDurationLabel($exam["duration_minutes"])) ?>

                    <?php if ((int)$exam["duration_minutes"] > 0): ?>
                        <span class="muted">(<?= (int)$exam["duration_minutes"] ?> minutes)</span>
                    <?php endif; ?>
                </strong>
            </div>

            <div class="detail-item">
                <span>Maximum Marks</span>
                <strong><?= htmlspecialchars(examsNumberLabel($exam["max_marks"])) ?></strong>
            </div>

            <div class="detail-item">
                <span>Passing Marks</span>
                <strong><?= htmlspecialchars(examsNumberLabel($exam["passing_marks"])) ?></strong>
            </div>

            <div class="detail-item">
                <span>Status</span>
                <strong><?= htmlspecialchars($exam_status["label"]) ?></strong>
            </div>

        </div>

        <div class="detail-block">

            <h3>Syllabus / Topics</h3>

            <?php if ($syllabus_text === ""): ?>

                <p class="muted">Syllabus has not been published for this exam yet.</p>

            <?php else: ?>

                <pre class="prewrap"><?= htmlspecialchars($syllabus_text) ?></pre>

                <div class="toolbar no-print">
                    <a class="btn btn-outline" href="<?= htmlspecialchars($syllabus_url) ?>">
                        📄 View / Download Syllabus
                    </a>
                </div>

            <?php endif; ?>

        </div>

        <div class="detail-block">

            <h3>Instructions</h3>

            <?php if ($instructions_text === ""): ?>

                <p class="muted">No special instructions for this exam.</p>

            <?php else: ?>

                <pre class="prewrap"><?= htmlspecialchars($instructions_text) ?></pre>

            <?php endif; ?>

        </div>

    </div>

    <!-- ================= MARKS STATUS SUMMARY ================= -->

    <div class="card">

        <h2>Marks Status</h2>

        <p class="muted">
            <?= htmlspecialchars((string)$exam["subject_name"]) ?>
            &middot;
            <?= htmlspecialchars((string)$exam["class_name"]) ?>
            -
            <?= htmlspecialchars((string)$exam["section_name"]) ?>
            &middot;
            <?= htmlspecialchars((string)$exam["exam_name"]) ?>
        </p>

        <div class="summary">

            <div class="summary-card accent-total">
                <span>Total Students</span>
                <strong><?= (int)$marks_bundle["total"] ?></strong>
            </div>

            <div class="summary-card accent-completed">
                <span>Marks Entered</span>
                <strong><?= (int)$marks_bundle["entered"] ?></strong>
            </div>

            <div class="summary-card accent-ongoing">
                <span>Pending</span>
                <strong><?= (int)$marks_bundle["pending"] ?></strong>
            </div>

            <div class="summary-card accent-upcoming">
                <span>Submission</span>
                <strong style="font-size:16px;">
                    <?= htmlspecialchars($marks_bundle["status_label"]) ?>
                </strong>
            </div>

        </div>

        <div class="toolbar">

            <a class="btn btn-green" href="<?= htmlspecialchars($enter_marks_url) ?>">
                📝 Enter Marks
            </a>

            <a class="btn btn-outline" href="<?= htmlspecialchars($marks_status_url) ?>">
                📊 View Marks Status
            </a>

            <a class="btn btn-secondary" href="index.php">Back to Exam List</a>

        </div>

    </div>

</div>

</body>

</html>

