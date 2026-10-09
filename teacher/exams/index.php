<?php

/*
|--------------------------------------------------------------------------
| TEACHER - EXAMS : EXAM OVERVIEW + FILTERS + EXAM LIST
|--------------------------------------------------------------------------
| Flow:
| Dashboard > Exams > (Overview + filters + exam list) > Exam Details > Enter Marks
|
| Read only for the teacher: no exam can be added, edited or deleted and no
| schedule can be changed from here.
|
| Tables used: exam_schedule (exam_module_setup.sql), exams,
|              teacher_subjects, classes, sections, subjects, academic_years
*/

require_once __DIR__ . "/includes/exam_common.php";

/*
|--------------------------------------------------------------------------
| FILTER VALUES (GET, so a list can be deep linked / bookmarked)
|--------------------------------------------------------------------------
| Every value is validated against the teacher's own assignments, so an
| invalid or foreign value is simply ignored.
*/

$filter_year    = trim($_GET["academic_year_uid"] ?? "");
$filter_class   = trim($_GET["class_uid"] ?? "");
$filter_section = trim($_GET["section_uid"] ?? "");
$filter_subject = trim($_GET["subject_uid"] ?? "");
$filter_exam    = trim($_GET["exam_uid"] ?? "");
$filter_from    = trim($_GET["date_from"] ?? "");
$filter_to      = trim($_GET["date_to"] ?? "");
$filter_status  = strtolower(trim($_GET["status"] ?? ""));

if ($filter_year !== "" && !isset($exams_year_options[$filter_year])) {
    $filter_year = "";
}

if ($filter_class !== "" && !isset($exams_class_options[$filter_class])) {
    $filter_class = "";
}

if ($filter_section !== "" && !isset($exams_section_options[$filter_section])) {
    $filter_section = "";
}

if ($filter_subject !== "" && !isset($exams_subject_options[$filter_subject])) {
    $filter_subject = "";
}

if ($filter_exam !== "" && !isset($exams_exam_options[$filter_exam])) {
    $filter_exam = "";
}

/* date filters: only a real YYYY-MM-DD value is accepted */
$is_valid_date = function ($value) {
    return (bool)preg_match("/^\d{4}-\d{2}-\d{2}$/", $value);
};

if ($filter_from !== "" && !$is_valid_date($filter_from)) {
    $filter_from = "";
}

if ($filter_to !== "" && !$is_valid_date($filter_to)) {
    $filter_to = "";
}

/* keep the range the right way round */
if ($filter_from !== "" && $filter_to !== "" && $filter_from > $filter_to) {
    $swap        = $filter_from;
    $filter_from = $filter_to;
    $filter_to   = $swap;
}

$valid_status_filters = ["scheduled", "upcoming", "ongoing", "completed", "cancelled"];

if ($filter_status !== "" && !in_array($filter_status, $valid_status_filters, true)) {
    $filter_status = "";
}

/*
|--------------------------------------------------------------------------
| LOAD EXAM SCHEDULE OF THIS TEACHER (with filters applied)
|--------------------------------------------------------------------------
*/

$exam_rows = [];

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

            /* only classes / sections / subjects assigned to this teacher */
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

    if ($filter_subject !== "") {
        $sql          .= " AND es.subject_uid = ?";
        $bind_types   .= "s";
        $bind_values[] = $filter_subject;
    }

    if ($filter_exam !== "") {
        $sql          .= " AND es.exam_uid = ?";
        $bind_types   .= "s";
        $bind_values[] = $filter_exam;
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
        die("Exam list query failed: " . $conn->error);
    }

    $stmt->bind_param($bind_types, ...$bind_values);
    $stmt->execute();

    $exam_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| EXAM OVERVIEW COUNTS
|--------------------------------------------------------------------------
| Counted before the status filter so the overview cards stay stable while
| browsing a single status.
*/

$overview = [
    "total"     => 0,
    "scheduled" => 0,
    "upcoming"  => 0,
    "ongoing"   => 0,
    "completed" => 0,
    "cancelled" => 0,
];

foreach ($exam_rows as $exam_row) {
    $overview["total"]++;
    $overview[examsScheduleStatus($exam_row)["key"]]++;
}

/* status filter is applied to the list only */
if ($filter_status !== "") {

    $status_filtered_rows = [];

    foreach ($exam_rows as $exam_row) {

        if (examsScheduleStatus($exam_row)["key"] === $filter_status) {
            $status_filtered_rows[] = $exam_row;
        }
    }

    $exam_rows = $status_filtered_rows;
}

$list_count = count($exam_rows);

$filters_applied = (
    $filter_year !== ""
    || $filter_class !== ""
    || $filter_section !== ""
    || $filter_subject !== ""
    || $filter_exam !== ""
    || $filter_from !== ""
    || $filter_to !== ""
    || $filter_status !== ""
);

/* link that clears every filter */
$clear_filters_url = "index.php";

?>
<?php
$UI_ROOT         = '../';
$UI_ACTIVE       = 'exams';
$UI_TITLE        = 'Exams';
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
    .badge-pending   { background:#adb5bd; color:#fff; }
    .badge-submitted { background:#198754; color:#fff; }
    .badge-draft     { background:#ffc107; color:#4d3b00; }
    .prewrap         { white-space: pre-wrap; word-break: break-word; font-family: inherit; }
    @media print { .no-print { display: none !important; } }
</style>

<!-- ================= HEADER ================= -->

<div class="card mb-4">

    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <strong>Exams</strong>
        <a href="../dashboard.php" class="btn btn-outline-secondary btn-sm">&larr; Back to Dashboard</a>
    </div>

    <div class="card-body">

        <p class="text-body-secondary mb-2">
            Teacher: <strong><?= htmlspecialchars($teacher_name) ?></strong>
        </p>

        <div class="alert alert-info">
            You can view exam information and enter marks.
            Adding, editing or deleting exams and changing the exam schedule is
            managed by the school administrator.
        </div>

        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-primary btn-sm" href="index.php">📋 Exam List</a>
            <a class="btn btn-outline-primary btn-sm" href="schedule.php">📅 View Schedule</a>
            <a class="btn btn-primary btn-sm" href="../marks/index.php">📝 Enter Marks</a>
        </div>

    </div>

</div>

<?php if (!$has_assignments): ?>

    <div class="alert alert-warning" role="alert">
        No class or subject has been assigned to you yet, so there are no
        exams to show. Please contact the school administrator.
    </div>

<?php else: ?>

    <!-- ================= 1. EXAM OVERVIEW ================= -->

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-body-secondary small text-uppercase">Total Exams</div>
                    <div class="fs-4 fw-semibold"><?= (int)$overview["total"] ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-body-secondary small text-uppercase">Upcoming</div>
                    <div class="fs-4 fw-semibold text-primary"><?= (int)$overview["upcoming"] ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-body-secondary small text-uppercase">Ongoing</div>
                    <div class="fs-4 fw-semibold text-warning"><?= (int)$overview["ongoing"] ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-body-secondary small text-uppercase">Completed</div>
                    <div class="fs-4 fw-semibold text-success"><?= (int)$overview["completed"] ?></div>
                </div>
            </div>
        </div>
    </div>



    <!-- ================= 3. FILTERS ================= -->

    <div class="card mb-4">

        <div class="card-header"><strong>Filters</strong></div>

        <div class="card-body">

            <form method="get" action="index.php" id="examFilterForm">

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

                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label" for="subject_uid">Subject</label>
                        <select class="form-select" name="subject_uid" id="subject_uid">
                        <option value="">All Subjects</option>
                        <?php foreach ($exams_subject_options as $subject_key => $subject_label): ?>
                            <option
                                value="<?= htmlspecialchars($subject_key) ?>"
                                <?= ($filter_subject === (string)$subject_key) ? "selected" : "" ?>>
                                <?= htmlspecialchars($subject_label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label" for="exam_uid">Exam</label>
                        <select class="form-select" name="exam_uid" id="exam_uid">
                        <option value="">All Exams</option>
                        <?php foreach ($exams_exam_options as $exam_key => $exam_data): ?>
                            <option
                                value="<?= htmlspecialchars($exam_key) ?>"
                                <?= ($filter_exam === (string)$exam_key) ? "selected" : "" ?>>
                                <?= htmlspecialchars($exam_data["label"]) ?>
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

                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-select" name="status" id="status">
                        <option value="">All Status</option>
                        <option value="upcoming"  <?= ($filter_status === "upcoming")  ? "selected" : "" ?>>Upcoming</option>
                        <option value="ongoing"   <?= ($filter_status === "ongoing")   ? "selected" : "" ?>>Ongoing</option>
                        <option value="completed" <?= ($filter_status === "completed") ? "selected" : "" ?>>Completed</option>
                        <option value="scheduled" <?= ($filter_status === "scheduled") ? "selected" : "" ?>>Scheduled</option>
                        <option value="cancelled" <?= ($filter_status === "cancelled") ? "selected" : "" ?>>Cancelled</option>
                    </select>
                </div>

            </div>

                <div class="d-flex flex-wrap gap-2 mt-3">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a class="btn btn-secondary" href="<?= htmlspecialchars($clear_filters_url) ?>">Clear Filters</a>
                    <a class="btn btn-outline-primary" href="schedule.php">📅 View Schedule</a>
                </div>

            </form>

        </div>

    </div>


    <!-- ================= 2. EXAM LIST ================= -->

    <div class="card mb-4">

        <div class="card-header"><strong>Exam List</strong></div>

        <div class="card-body">

            <p class="text-body-secondary">
                <?= (int)$list_count ?> exam<?= ($list_count === 1) ? "" : "s" ?> found
                <?= $filters_applied ? "for the selected filters" : "for your classes" ?>.
                Click <strong>View Details</strong> to open an exam.
            </p>

            <?php if ($list_count === 0): ?>

                <div class="alert alert-warning mb-0">
                    No exams found. Try changing or clearing the filters.
                </div>

            <?php else: ?>

                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0">

                <thead>
                    <tr>
                        <th>#</th>
                        <th>Exam Name</th>
                        <th>Class</th>
                        <th>Section</th>
                        <th>Subject</th>
                        <th>Exam Date</th>
                        <th>Time</th>
                        <th>Duration</th>
                        <th>Maximum Marks</th>
                        <th>Status</th>
                        <th>Teacher Actions</th>
                    </tr>
                </thead>

                <tbody>

                <?php $row_number = 0; ?>

                <?php foreach ($exam_rows as $exam_row): ?>

                    <?php
                    $row_number++;
                    $exam_status = examsScheduleStatus($exam_row);
                    ?>

                    <tr>

                        <td><?= (int)$row_number ?></td>

                        <td>
                            <strong><?= htmlspecialchars((string)$exam_row["exam_name"]) ?></strong>

                            <div class="small text-body-secondary">
                                <?= htmlspecialchars(ucfirst((string)$exam_row["exam_type"])) ?>
                                <?php if (trim((string)$exam_row["term"]) !== ""): ?>
                                    &middot; <?= htmlspecialchars((string)$exam_row["term"]) ?>
                                <?php endif; ?>
                                <?php if (trim((string)$exam_row["year_name"]) !== ""): ?>
                                    &middot; <?= htmlspecialchars((string)$exam_row["year_name"]) ?>
                                <?php endif; ?>
                            </div>
                        </td>

                        <td><?= htmlspecialchars((string)$exam_row["class_name"]) ?></td>

                        <td><?= htmlspecialchars((string)$exam_row["section_name"]) ?></td>

                        <td><?= htmlspecialchars((string)$exam_row["subject_name"]) ?></td>

                        <td><?= htmlspecialchars(examsDateLabel($exam_row["exam_date"])) ?></td>

                        <td><?= htmlspecialchars(examsTimeLabel($exam_row["start_time"])) ?></td>

                        <td><?= htmlspecialchars(examsDurationLabel($exam_row["duration_minutes"])) ?></td>

                        <td><?= htmlspecialchars(examsNumberLabel($exam_row["max_marks"])) ?></td>

                        <td>
                            <span class="badge <?= htmlspecialchars($exam_status["css"]) ?>">
                                <?= htmlspecialchars($exam_status["label"]) ?>
                            </span>
                        </td>

                        <td>
                            <div class="d-flex flex-wrap gap-2">
                                <a
                                    class="btn btn-sm btn-outline-secondary"
                                    href="view.php?uid=<?= urlencode((string)$exam_row["schedule_uid"]) ?>">
                                    👁️ Details
                                </a>

                                <a
                                    class="btn btn-sm btn-outline-secondary"
                                    href="syllabus.php?uid=<?= urlencode((string)$exam_row["schedule_uid"]) ?>">
                                    📄 Syllabus
                                </a>

                                <a
                                    class="btn btn-sm btn-outline-secondary"
                                    href="schedule.php?academic_year_uid=<?= urlencode((string)$exam_row["academic_year_uid"]) ?>&amp;class_uid=<?= urlencode((string)$exam_row["class_uid"]) ?>&amp;section_uid=<?= urlencode((string)$exam_row["section_uid"]) ?>">
                                    📅 Schedule
                                </a>

                                <a
                                    class="btn btn-sm btn-success"
                                    href="../marks/index.php?action=load&amp;academic_year_uid=<?= urlencode((string)$exam_row["academic_year_uid"]) ?>&amp;class_uid=<?= urlencode((string)$exam_row["class_uid"]) ?>&amp;section_uid=<?= urlencode((string)$exam_row["section_uid"]) ?>&amp;subject_uid=<?= urlencode((string)$exam_row["subject_uid"]) ?>&amp;exam_uid=<?= urlencode((string)$exam_row["exam_uid"]) ?>&amp;term=<?= urlencode((string)$exam_row["term"]) ?>&amp;max_marks=<?= urlencode((string)$exam_row["max_marks"]) ?>">
                                    📝 Enter Marks
                                </a>

                                <a
                                    class="btn btn-sm btn-outline-primary"
                                    href="results.php?uid=<?= urlencode((string)$exam_row["schedule_uid"]) ?>">
                                    📊 Marks Status
                                </a>
                            </div>
                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>
                </div>

            <?php endif; ?>

        </div>

    </div>

<?php endif; ?>

<script>

/*
| Section list follows the selected class (nothing else on the page changes).
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

