<?php

/*
|--------------------------------------------------------------------------
| TEACHER - EXAMS : VIEW SCHEDULE (read only)
|--------------------------------------------------------------------------
| Date wise exam schedule of the teacher's own classes / sections.
| Filters: session, class, section, date from, date to.
|
| The schedule can only be viewed - it cannot be changed from here.
*/

require_once __DIR__ . "/includes/exam_common.php";

/*
|--------------------------------------------------------------------------
| FILTERS (validated against the teacher's own assignments)
|--------------------------------------------------------------------------
*/

$filter_year    = trim($_GET["academic_year_uid"] ?? "");
$filter_class   = trim($_GET["class_uid"] ?? "");
$filter_section = trim($_GET["section_uid"] ?? "");
$filter_from    = trim($_GET["date_from"] ?? "");
$filter_to      = trim($_GET["date_to"] ?? "");

if ($filter_year !== "" && !isset($exams_year_options[$filter_year])) {
    $filter_year = "";
}

if ($filter_class !== "" && !isset($exams_class_options[$filter_class])) {
    $filter_class = "";
}

if ($filter_section !== "" && !isset($exams_section_options[$filter_section])) {
    $filter_section = "";
}

if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $filter_from)) {
    $filter_from = "";
}

if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $filter_to)) {
    $filter_to = "";
}

if ($filter_from !== "" && $filter_to !== "" && $filter_from > $filter_to) {
    $swap        = $filter_from;
    $filter_from = $filter_to;
    $filter_to   = $swap;
}

/*
|--------------------------------------------------------------------------
| LOAD SCHEDULE
|--------------------------------------------------------------------------
*/

$schedule_rows = [];

if ($has_assignments) {

    $sql = "SELECT
                es.schedule_uid,
                es.exam_uid,
                es.class_uid,
                es.section_uid,
                es.subject_uid,
                es.academic_year_uid,
                es.exam_date,
                es.start_time,
                es.duration_minutes,
                es.max_marks,
                es.passing_marks,
                es.status AS schedule_status,
                e.exam_name,
                e.exam_type,
                e.term,
                c.class_name,
                c.numeric_order,
                sec.section_name,
                sub.subject_name,
                COALESCE(ay.year_name, '') AS year_name
            FROM exam_schedule es

            INNER JOIN exams e
                ON e.exam_uid = es.exam_uid
                AND e.status = 'active'

            INNER JOIN classes c
                ON c.class_uid = es.class_uid

            INNER JOIN sections sec
                ON sec.section_uid = es.section_uid

            INNER JOIN subjects sub
                ON sub.subject_uid = es.subject_uid

            LEFT JOIN academic_years ay
                ON ay.academic_year_uid = es.academic_year_uid

            INNER JOIN teacher_subjects ts
                ON ts.school_uid = es.school_uid
                AND ts.teacher_uid = ?
                AND ts.class_uid = es.class_uid
                AND ts.section_uid = es.section_uid
                AND ts.subject_uid = es.subject_uid
                AND ts.academic_year_uid = es.academic_year_uid

            WHERE es.school_uid = ?";

    $bind_types  = "ss";
    $bind_values = [$teacher_uid, $school_uid];

    if ($filter_year !== "") {
        $sql          .= " AND es.academic_year_uid = ?";
        $bind_types   .= "s";
        $bind_values[] = $filter_year;
    }

    if ($filter_class !== "") {
        $sql          .= " AND es.class_uid = ?";
        $bind_types   .= "s";
        $bind_values[] = $filter_class;
    }

    if ($filter_section !== "") {
        $sql          .= " AND es.section_uid = ?";
        $bind_types   .= "s";
        $bind_values[] = $filter_section;
    }

    if ($filter_from !== "") {
        $sql          .= " AND es.exam_date >= ?";
        $bind_types   .= "s";
        $bind_values[] = $filter_from;
    }

    if ($filter_to !== "") {
        $sql          .= " AND es.exam_date <= ?";
        $bind_types   .= "s";
        $bind_values[] = $filter_to;
    }

    $sql .= " ORDER BY
                es.exam_date ASC,
                es.start_time ASC,
                c.numeric_order ASC,
                sec.section_name ASC,
                sub.subject_name ASC";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("Schedule query failed: " . $conn->error);
    }

    $stmt->bind_param($bind_types, ...$bind_values);
    $stmt->execute();

    $schedule_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| OVERVIEW + GROUP BY DATE
|--------------------------------------------------------------------------
*/

$schedule_counts = [
    "total"     => 0,
    "upcoming"  => 0,
    "ongoing"   => 0,
    "completed" => 0,
    "cancelled" => 0,
];

$schedule_days = [];

foreach ($schedule_rows as $schedule_row) {

    $schedule_counts["total"]++;
    $schedule_counts[examsScheduleStatus($schedule_row)["key"]]++;

    $day_key = trim((string)$schedule_row["exam_date"]);

    if ($day_key === "" || $day_key === "0000-00-00") {
        $day_key = "no-date";
    }

    if (!isset($schedule_days[$day_key])) {
        $schedule_days[$day_key] = [];
    }

    $schedule_days[$day_key][] = $schedule_row;
}


/*
| Link used by every row to jump into Marks Entry with the exam pre-selected.
*/
$enter_marks_link = function ($row) {
    return "../marks/index.php?action=load"
        . "&academic_year_uid=" . urlencode((string)$row["academic_year_uid"])
        . "&class_uid=" . urlencode((string)$row["class_uid"])
        . "&section_uid=" . urlencode((string)$row["section_uid"])
        . "&subject_uid=" . urlencode((string)$row["subject_uid"])
        . "&exam_uid=" . urlencode((string)$row["exam_uid"])
        . "&term=" . urlencode((string)$row["term"])
        . "&max_marks=" . urlencode(examsNumberLabel($row["max_marks"]));
};

?>
<?php
$UI_ROOT         = '../';
$UI_ACTIVE       = 'exams';
$UI_TITLE        = 'Exam Schedule';
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
    @media print { .no-print { display: none !important; } }
</style>

<div class="card mb-4">

    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <strong>Exam Schedule</strong>
        <a href="index.php" class="btn btn-outline-secondary btn-sm">&larr; Back to Exam List</a>
    </div>

    <div class="card-body">

        <p class="text-body-secondary mb-2">
            Teacher: <strong><?= htmlspecialchars($teacher_name) ?></strong>
        </p>

        <div class="alert alert-info">
            This is a read only view of your exam schedule. Exam dates and timings
            can only be changed by the school administrator.
        </div>

        <div class="row g-3">

            <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="text-body-secondary small text-uppercase">Total Exams</div><div class="fs-4 fw-semibold"><?= (int)$schedule_counts["total"] ?></div></div></div></div>

            <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="text-body-secondary small text-uppercase">Upcoming</div><div class="fs-4 fw-semibold"><?= (int)$schedule_counts["upcoming"] ?></div></div></div></div>

            <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="text-body-secondary small text-uppercase">Ongoing</div><div class="fs-4 fw-semibold"><?= (int)$schedule_counts["ongoing"] ?></div></div></div></div>

            <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="text-body-secondary small text-uppercase">Completed</div><div class="fs-4 fw-semibold"><?= (int)$schedule_counts["completed"] ?></div></div></div></div>

        </div>

    </div>

</div>

<?php if (!$has_assignments): ?>

    <div class="alert alert-warning" role="alert">
        No class or subject has been assigned to you yet, so no exam
        schedule is available. Please contact the school administrator.
    </div>

<?php else: ?>

    <div class="card mb-4 no-print">

        <div class="card-header"><strong>Schedule Filters</strong></div>

        <div class="card-body">

            <form method="get" action="schedule.php" id="scheduleFilterForm">

                <div class="row g-3">

                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label" for="academic_year_uid">Academic Session</label>
                        <select class="form-select" name="academic_year_uid" id="academic_year_uid">
                        <option value="">All Sessions</option>
                        <?php foreach ($exams_year_options as $year_key => $year_label): ?>
                            <option
                                value="<?= htmlspecialchars($year_key) ?>"
                                <?= ($filter_year === (string)$year_key) ? "selected" : "" ?>>
                                <?= htmlspecialchars($year_label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label" for="class_uid">Class</label>
                        <select class="form-select" name="class_uid" id="class_uid">
                        <option value="">All Classes</option>
                        <?php foreach ($exams_class_options as $class_key => $class_label): ?>
                            <option
                                value="<?= htmlspecialchars($class_key) ?>"
                                <?= ($filter_class === (string)$class_key) ? "selected" : "" ?>>
                                <?= htmlspecialchars($class_label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label" for="section_uid">Section</label>
                        <select class="form-select" name="section_uid" id="section_uid">
                        <option value="">All Sections</option>
                        <?php foreach ($exams_section_options as $section_key => $section_data): ?>
                            <option
                                value="<?= htmlspecialchars($section_key) ?>"
                                data-class="<?= htmlspecialchars($section_data["class_uid"]) ?>"
                                <?= ($filter_section === (string)$section_key) ? "selected" : "" ?>>
                                <?= htmlspecialchars($section_data["label"]) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                    <div class="col-6 col-md-3 col-xl-3">
                        <label class="form-label" for="date_from">Date From</label>
                        <input
                            type="date"
                            class="form-control"
                            name="date_from"
                            id="date_from"
                        value="<?= htmlspecialchars($filter_from) ?>">
                </div>

                    <div class="col-6 col-md-3 col-xl-3">
                        <label class="form-label" for="date_to">Date To</label>
                        <input
                            type="date"
                            class="form-control"
                            name="date_to"
                            id="date_to"
                        value="<?= htmlspecialchars($filter_to) ?>">
                </div>

            </div>

                <div class="d-flex flex-wrap gap-2 mt-3">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a class="btn btn-secondary" href="schedule.php">Clear Filters</a>
                    <button type="button" class="btn btn-outline-primary" onclick="window.print();">
                        🖨️ Print Schedule
                    </button>
                    <a class="btn btn-outline-primary" href="index.php">📋 Exam List</a>
                </div>

            </form>

            </div>

        </div>

    <div class="card mb-4">

        <div class="card-header"><strong>Date wise Schedule</strong></div>
        <div class="card-body">

            <p class="text-body-secondary">
                <?= (int)$schedule_counts["total"] ?>
                exam<?= ($schedule_counts["total"] === 1) ? "" : "s" ?>
                for your assigned classes and subjects.
            </p>

            <?php if (empty($schedule_days)): ?>

                <div class="alert alert-warning mb-0">
                    No exams found for the selected filters.
                </div>

            <?php else: ?>

                <?php foreach ($schedule_days as $day_key => $day_rows): ?>

                    <?php $day_status = examsScheduleStatus($day_rows[0]); ?>

                    <div class="border rounded p-3 mb-4">

                        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">

                            <h5 class="mb-0">
                                <?= ($day_key === "no-date")
                                    ? "Date not announced"
                                    : htmlspecialchars(examsDateLabel($day_key)) ?>
                            </h5>

                            <span class="badge <?= htmlspecialchars($day_status["css"]) ?>">
                                <?= htmlspecialchars($day_status["label"]) ?>
                            </span>

                            <span class="text-body-secondary">
                                <?= count($day_rows) ?>
                                exam<?= (count($day_rows) === 1) ? "" : "s" ?>
                            </span>

                        </div>

                        <div class="table-responsive">
                            <table class="table table-striped table-hover align-middle mb-0">

                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Exam Name</th>
                                        <th>Class</th>
                                        <th>Section</th>
                                        <th>Subject</th>
                                        <th>Duration</th>
                                        <th>Max Marks</th>
                                        <th class="no-print">Action</th>
                                    </tr>
                                </thead>

                                <tbody>

                        <?php foreach ($day_rows as $day_row): ?>

                            <tr>

                                <td><?= htmlspecialchars(examsTimeLabel($day_row["start_time"])) ?></td>

                                <td>
                                    <strong><?= htmlspecialchars((string)$day_row["exam_name"]) ?></strong>

                                    <div class="small text-body-secondary">
                                        <?php if (trim((string)$day_row["term"]) !== ""): ?>
                                            <?= htmlspecialchars((string)$day_row["term"]) ?>
                                        <?php endif; ?>
                                        <?php if (trim((string)$day_row["year_name"]) !== ""): ?>
                                            &middot; <?= htmlspecialchars((string)$day_row["year_name"]) ?>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td><?= htmlspecialchars((string)$day_row["class_name"]) ?></td>

                                <td><?= htmlspecialchars((string)$day_row["section_name"]) ?></td>

                                <td><?= htmlspecialchars((string)$day_row["subject_name"]) ?></td>

                                <td><?= htmlspecialchars(examsDurationLabel($day_row["duration_minutes"])) ?></td>

                                <td><?= htmlspecialchars(examsNumberLabel($day_row["max_marks"])) ?></td>

                                <td class="no-print">
                                    <div class="d-flex flex-wrap gap-2">
                                        <a
                                            class="btn btn-sm btn-outline-secondary"
                                            href="view.php?uid=<?= urlencode((string)$day_row["schedule_uid"]) ?>">
                                            View Details
                                        </a>

                                        <a
                                            class="btn btn-sm btn-success"
                                            href="<?= htmlspecialchars($enter_marks_link($day_row)) ?>">
                                            Enter Marks
                                        </a>
                                    </div>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>
                        </div>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

        <div class="d-flex flex-wrap gap-2 mt-3 no-print">
            <a class="btn btn-secondary" href="index.php">Back to Exam List</a>
            <a class="btn btn-outline-primary" href="../marks/index.php">📝 Enter Marks</a>
        </div>

    </div>

</div>

<?php endif; ?>

<script>

/*
| Section list follows the selected class.
*/
(function () {

    var classSelect   = document.getElementById("class_uid");
    var sectionSelect = document.getElementById("section_uid");

    if (!classSelect || !sectionSelect) {
        return;
    }

    var sections = sectionSelect.querySelectorAll("option[data-class]");

    function refreshSections()
    {
        var selectedClass = classSelect.value;

        for (var i = 0; i < sections.length; i++) {

            var option = sections[i];
            var show   = (selectedClass === "" || option.getAttribute("data-class") === selectedClass);

            option.hidden   = !show;
            option.disabled = !show;

            if (!show) {
                option.selected = false;
            }
        }
    }

    classSelect.addEventListener("change", refreshSections);
    refreshSections();

})();

</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

