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
<?php
$UI_ROOT         = '../';
$UI_ACTIVE       = 'exams';
$UI_TITLE        = 'Exam Details';
$UI_CRUMBS       = [];
$UI_PROFILE_NAME = $teacher_name;
include __DIR__ . '/../includes/header.php';
?>

<div class="card mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <strong>Exam Details</strong>
        <a href="index.php" class="btn btn-outline-secondary btn-sm">&larr; Back to Exam List</a>
    </div>
    <div class="card-body">
        <div class="alert alert-danger mb-0">
            Exam not found, or it does not belong to the classes, sections
            and subjects assigned to you.
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
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

<?php
$UI_ROOT         = '../';
$UI_ACTIVE       = 'exams';
$UI_TITLE        = 'Exam Details';
$UI_CRUMBS       = [];
$UI_PROFILE_NAME = $teacher_name;
include __DIR__ . '/../includes/header.php';
?>

<style>
    .badge-upcoming  { background:#0d6efd; color:#fff; }
    .badge-ongoing   { background:#fd7e14; color:#fff; }
    .badge-completed { background:#198754; color:#fff; }
    .badge-scheduled { background:#6c757d; color:#fff; }
    .badge-cancelled { background:#dc3545; color:#fff; }
    .prewrap         { white-space: pre-wrap; word-break: break-word; font-family: inherit; }
    .detail-item     { background: var(--bs-tertiary-bg, #f8f9fa); border: 1px solid var(--bs-border-color, #dee2e6); border-radius: .375rem; padding: .5rem .75rem; }
    .detail-item > span { display: block; font-size: .75rem; color: var(--bs-secondary-color, #6c757d); text-transform: uppercase; letter-spacing: .03em; }
    @media print { .no-print { display: none !important; } }
</style>

<div class="card mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <strong><?= htmlspecialchars((string)$exam["exam_name"]) ?></strong>
        <a href="index.php" class="btn btn-outline-secondary btn-sm">&larr; Back to Exam List</a>
    </div>
    <div class="card-body">

        <p class="text-body-secondary mb-2">
            Teacher: <strong><?= htmlspecialchars($teacher_name) ?></strong>
        </p>

        <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="badge <?= htmlspecialchars($exam_status["css"]) ?>">
                <?= htmlspecialchars($exam_status["label"]) ?>
            </span>
            <span class="text-body-secondary">
                <?= htmlspecialchars((string)$exam["class_name"]) ?>
                -
                <?= htmlspecialchars((string)$exam["section_name"]) ?>
                &middot;
                <?= htmlspecialchars((string)$exam["subject_name"]) ?>
                &middot;
                <?= htmlspecialchars(examsDateLabel($exam["exam_date"])) ?>
            </span>
        </div>

        <div class="alert alert-info mt-3 mb-0">
            This page is read only. To add, edit or delete an exam or to change
            the exam schedule, please contact the school administrator.
        </div>

    </div>
</div>

<!-- ================= 5. TEACHER ACTIONS ================= -->

<div class="card mb-4">

    <div class="card-header"><strong>Teacher Actions</strong></div>

    <div class="card-body">
        <div class="row g-3">

            <div class="col-12 col-md-6 col-xl-4">
                <a href="view.php?uid=<?= urlencode((string)$exam["schedule_uid"]) ?>"
                   class="btn btn-primary w-100 d-flex flex-column align-items-start gap-1">
                    👁️ View Details
                    <span class="small opacity-75">Exam information, syllabus and instructions</span>
                </a>
            </div>

            <div class="col-12 col-md-6 col-xl-4">
                <a href="<?= htmlspecialchars($syllabus_url) ?>"
                   class="btn btn-outline-primary w-100 d-flex flex-column align-items-start gap-1">
                    📄 View / Download Syllabus
                    <span class="small opacity-75">Open the syllabus or download it</span>
                </a>
            </div>

            <div class="col-12 col-md-6 col-xl-4">
                <a href="<?= htmlspecialchars($schedule_url) ?>"
                   class="btn btn-outline-primary w-100 d-flex flex-column align-items-start gap-1">
                    📅 View Schedule
                    <span class="small opacity-75">Exam dates for this class and section</span>
                </a>
            </div>

            <div class="col-12 col-md-6 col-xl-4">
                <a href="<?= htmlspecialchars($enter_marks_url) ?>"
                   class="btn btn-success w-100 d-flex flex-column align-items-start gap-1">
                    📝 Enter Marks
                    <span class="small opacity-75">Open Marks Entry with this exam pre-selected</span>
                </a>
            </div>

            <div class="col-12 col-md-6 col-xl-4">
                <a href="<?= htmlspecialchars($marks_status_url) ?>"
                   class="btn btn-outline-primary w-100 d-flex flex-column align-items-start gap-1">
                    📊 View Marks Status
                    <span class="small opacity-75">Who is entered, who is still pending</span>
                </a>
            </div>

        </div>
    </div>

</div>

<!-- ================= 4. EXAM DETAILS ================= -->

<div class="card mb-4">

    <div class="card-header"><strong>Exam Information</strong></div>

    <div class="card-body">
        <div class="row g-3">

            <div class="detail-item col-12 col-md-6 col-xl-3">
                <span>Exam Name</span>
                <strong><?= htmlspecialchars((string)$exam["exam_name"]) ?></strong>
            </div>

            <div class="detail-item col-12 col-md-6 col-xl-3">
                <span>Exam Type</span>
                <strong><?= htmlspecialchars(ucfirst((string)$exam["exam_type"])) ?></strong>
            </div>

            <div class="detail-item col-12 col-md-6 col-xl-3">
                <span>Term</span>
                <strong>
                    <?= htmlspecialchars((trim((string)$exam["term"]) !== "") ? (string)$exam["term"] : "-") ?>
                </strong>
            </div>

            <div class="detail-item col-12 col-md-6 col-xl-3">
                <span>Academic Session</span>
                <strong>
                    <?= htmlspecialchars(
                        (trim((string)$exam["year_name"]) !== "")
                            ? (string)$exam["year_name"]
                            : (string)$exam["academic_year_uid"]
                    ) ?>
                </strong>
            </div>

            <div class="detail-item col-12 col-md-6 col-xl-3">
                <span>Class &amp; Section</span>
                <strong>
                    <?= htmlspecialchars((string)$exam["class_name"]) ?>
                    -
                    <?= htmlspecialchars((string)$exam["section_name"]) ?>
                </strong>
            </div>

            <div class="detail-item col-12 col-md-6 col-xl-3">
                <span>Subject</span>
                <strong><?= htmlspecialchars((string)$exam["subject_name"]) ?></strong>
            </div>

            <div class="detail-item col-12 col-md-6 col-xl-3">
                <span>Exam Date</span>
                <strong><?= htmlspecialchars(examsDateLabel($exam["exam_date"])) ?></strong>
            </div>

            <div class="detail-item col-12 col-md-6 col-xl-3">
                <span>Time</span>
                <strong><?= htmlspecialchars(examsTimeLabel($exam["start_time"])) ?></strong>
            </div>

            <div class="detail-item col-12 col-md-6 col-xl-3">
                <span>Duration</span>
                <strong>
                    <?= htmlspecialchars(examsDurationLabel($exam["duration_minutes"])) ?>

                    <?php if ((int)$exam["duration_minutes"] > 0): ?>
                        <span class="small text-body-secondary">(<?= (int)$exam["duration_minutes"] ?> minutes)</span>
                    <?php endif; ?>
                </strong>
            </div>

            <div class="detail-item col-12 col-md-6 col-xl-3">
                <span>Maximum Marks</span>
                <strong><?= htmlspecialchars(examsNumberLabel($exam["max_marks"])) ?></strong>
            </div>

            <div class="detail-item col-12 col-md-6 col-xl-3">
                <span>Passing Marks</span>
                <strong><?= htmlspecialchars(examsNumberLabel($exam["passing_marks"])) ?></strong>
            </div>

            <div class="detail-item col-12 col-md-6 col-xl-3">
                <span>Status</span>
                <strong><?= htmlspecialchars($exam_status["label"]) ?></strong>
            </div>

        </div>

        <div class="mt-4">

            <h5 class="mb-2">Syllabus / Topics</h5>

            <?php if ($syllabus_text === ""): ?>

                <p class="text-body-secondary mb-0">Syllabus has not been published for this exam yet.</p>

            <?php else: ?>

                <pre class="prewrap"><?= htmlspecialchars($syllabus_text) ?></pre>

                <div class="no-print">
                    <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($syllabus_url) ?>">
                        📄 View / Download Syllabus
                    </a>
                </div>

            <?php endif; ?>

        </div>

        <div class="mt-4">

            <h5 class="mb-2">Instructions</h5>

            <?php if ($instructions_text === ""): ?>

                <p class="text-body-secondary mb-0">No special instructions for this exam.</p>

            <?php else: ?>

                <pre class="prewrap"><?= htmlspecialchars($instructions_text) ?></pre>

            <?php endif; ?>

        </div>

    </div>

    <!-- ================= MARKS STATUS SUMMARY ================= -->

    <div class="card mb-4">

        <div class="card-header"><strong>Marks Status</strong></div>

        <div class="card-body">

            <p class="text-body-secondary">
                <?= htmlspecialchars((string)$exam["subject_name"]) ?>
                &middot;
                <?= htmlspecialchars((string)$exam["class_name"]) ?>
                -
                <?= htmlspecialchars((string)$exam["section_name"]) ?>
                &middot;
                <?= htmlspecialchars((string)$exam["exam_name"]) ?>
            </p>

            <div class="row g-3">

                <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="text-body-secondary small text-uppercase">Total Students</div><div class="fs-4 fw-semibold"><?= (int)$marks_bundle["total"] ?></div></div></div></div>

                <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="text-body-secondary small text-uppercase">Marks Entered</div><div class="fs-4 fw-semibold text-success"><?= (int)$marks_bundle["entered"] ?></div></div></div></div>

                <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="text-body-secondary small text-uppercase">Pending</div><div class="fs-4 fw-semibold text-warning"><?= (int)$marks_bundle["pending"] ?></div></div></div></div>

                <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="text-body-secondary small text-uppercase">Submission</div><div class="fs-5 fw-semibold"><?= htmlspecialchars($marks_bundle["status_label"]) ?></div></div></div></div>

            </div>

            <div class="d-flex flex-wrap gap-2 mt-3">

                <a class="btn btn-success" href="<?= htmlspecialchars($enter_marks_url) ?>">
                    📝 Enter Marks
                </a>

                <a class="btn btn-outline-primary" href="<?= htmlspecialchars($marks_status_url) ?>">
                    📊 View Marks Status
                </a>

                <a class="btn btn-secondary" href="index.php">Back to Exam List</a>

            </div>

        </div>

    </div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

