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
<?php
$UI_ROOT         = '../';
$UI_ACTIVE       = 'exams';
$UI_TITLE        = 'Marks Status';
$UI_CRUMBS       = [];
$UI_PROFILE_NAME = $teacher_name;
include __DIR__ . '/../includes/header.php';
?>

<div class="card mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <strong>Marks Status</strong>
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
<?php
$UI_ROOT         = '../';
$UI_ACTIVE       = 'exams';
$UI_TITLE        = 'Marks Status';
$UI_CRUMBS       = [];
$UI_PROFILE_NAME = $teacher_name;
include __DIR__ . '/../includes/header.php';
?>

<style>
    .badge-pending   { background:#adb5bd; color:#fff; }
    .badge-submitted { background:#198754; color:#fff; }
    .badge-draft     { background:#ffc107; color:#4d3b00; }
    .detail-item     { background: var(--bs-tertiary-bg, #f8f9fa); border: 1px solid var(--bs-border-color, #dee2e6); border-radius: .375rem; padding: .5rem .75rem; }
    .detail-item > span { display: block; font-size: .75rem; color: var(--bs-secondary-color, #6c757d); text-transform: uppercase; letter-spacing: .03em; }
    @media print { .no-print { display: none !important; } }
</style>

<div class="card mb-4">

    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <strong>Marks Status</strong>
        <a href="<?= htmlspecialchars($detail_url) ?>" class="btn btn-outline-secondary btn-sm">&larr; Back to Exam Details</a>
    </div>

    <div class="card-body">

        <p class="text-body-secondary mb-3">
            Teacher: <strong><?= htmlspecialchars($teacher_name) ?></strong>
        </p>

        <div class="row g-3 mb-4">

            <div class="detail-item col-12 col-md-6 col-xl-4">
                <span>Exam</span>
                <strong><?= htmlspecialchars((string)$exam["exam_name"]) ?></strong>
            </div>

            <div class="detail-item col-12 col-md-6 col-xl-4">
                <span>Class &amp; Section</span>
                <strong>
                    <?= htmlspecialchars((string)$exam["class_name"]) ?>
                    -
                    <?= htmlspecialchars((string)$exam["section_name"]) ?>
                </strong>
            </div>

            <div class="detail-item col-12 col-md-6 col-xl-4">
                <span>Subject</span>
                <strong><?= htmlspecialchars((string)$exam["subject_name"]) ?></strong>
            </div>

            <div class="detail-item col-12 col-md-6 col-xl-4">
                <span>Maximum Marks</span>
                <strong><?= htmlspecialchars(examsNumberLabel($max_marks_value)) ?></strong>
            </div>

            <div class="detail-item col-12 col-md-6 col-xl-4">
                <span>Exam Date</span>
                <strong><?= htmlspecialchars(examsDateLabel($exam["exam_date"])) ?></strong>
            </div>

            <div class="detail-item col-12 col-md-6 col-xl-4">
                <span>Exam Status</span>
                <strong><?= htmlspecialchars(examsScheduleStatus($exam)["label"]) ?></strong>
            </div>

        </div>

        <div class="row g-3 mt-4">

            <div class="col-6 col-lg-4 col-xl"><div class="card h-100"><div class="card-body"><div class="text-body-secondary small text-uppercase">Total Students</div><div class="fs-5 fw-semibold"><?= (int)$bundle["total"] ?></div></div></div></div>

            <div class="col-6 col-lg-4 col-xl"><div class="card h-100"><div class="card-body"><div class="text-body-secondary small text-uppercase">Marks Entered</div><div class="fs-5 fw-semibold"><?= (int)$bundle["entered"] ?></div></div></div></div>

            <div class="col-6 col-lg-4 col-xl"><div class="card h-100"><div class="card-body"><div class="text-body-secondary small text-uppercase">Pending</div><div class="fs-5 fw-semibold"><?= (int)$bundle["pending"] ?></div></div></div></div>

            <div class="col-6 col-lg-4 col-xl"><div class="card h-100"><div class="card-body"><div class="text-body-secondary small text-uppercase">Absent</div><div class="fs-5 fw-semibold"><?= (int)$bundle["absent"] ?></div></div></div></div>

            <div class="col-6 col-lg-4 col-xl"><div class="card h-100"><div class="card-body"><div class="text-body-secondary small text-uppercase">Exempted</div><div class="fs-5 fw-semibold"><?= (int)$bundle["exempted"] ?></div></div></div></div>

            <div class="col-6 col-lg-4 col-xl"><div class="card h-100"><div class="card-body"><div class="text-body-secondary small text-uppercase">Submission</div><div class="fs-5 fw-semibold">
                    <?= htmlspecialchars($bundle["status_label"]) ?>
                </div></div></div></div>

        </div>

        <div class="alert alert-info mb-0">
            Marks Entry status: <strong>Submitted</strong> =
            <?= (int)$bundle["submitted"] ?>,
            <strong>Draft</strong> = <?= (int)$bundle["draft"] ?>,
            <strong>Pending</strong> = <?= (int)$bundle["pending"] ?>.
            Marks are entered and changed only from the Marks Entry module.
        </div>

    </div>

</div>


<div class="card mb-4">

    <div class="card-header"><strong>Student wise Marks Status</strong></div>

    <div class="card-body">

        <?php if ((int)$bundle["total"] === 0): ?>

            <div class="alert alert-warning mb-0">
                No active students found in this class and section for the
                selected academic session.
            </div>

        <?php else: ?>

            <div class="table-responsive"><table class="table table-striped table-hover align-middle mb-0">

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
                                <span class="badge bg-light text-dark border"><?= htmlspecialchars((string)$student_row["grade"]) ?></span>
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

            </table></div>

        <?php endif; ?>

        <div class="d-flex flex-wrap gap-2 mt-3 no-print">

            <a class="btn btn-success" href="<?= htmlspecialchars($enter_marks_url) ?>">
                📝 Enter Marks
            </a>

            <a class="btn btn-outline-primary" href="<?= htmlspecialchars($detail_url) ?>">
                👁️ Back to Exam Details
            </a>

            <button type="button" class="btn btn-secondary" onclick="window.print();">
                🖨️ Print
            </button>

            <a class="btn btn-outline-primary" href="index.php">📋 Exam List</a>

        </div>

    </div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

