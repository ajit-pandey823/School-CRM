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
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Exams</title>

<style>
<?= examsModuleStyles() ?>
</style>

</head>

<body>

<div class="container">

    <!-- ================= HEADER ================= -->

    <div class="card">

        <a class="back-link" href="../dashboard.php">&larr; Back to Dashboard</a>

        <h1>Exams</h1>

        <div class="teacher-info">
            Teacher:
            <strong><?= htmlspecialchars($teacher_name) ?></strong>
        </div>

        <div class="notice">
            You can view exam information and enter marks.
            Adding, editing or deleting exams and changing the exam schedule is
            managed by the school administrator.
        </div>

        <div class="toolbar">
            <a class="btn btn-outline" href="index.php">📋 Exam List</a>
            <a class="btn btn-outline" href="schedule.php">📅 View Schedule</a>
            <a class="btn" href="../marks/index.php">📝 Enter Marks</a>
        </div>

    </div>

<?php if (!$has_assignments): ?>

    <div class="card">

        <div class="empty">
            No class or subject has been assigned to you yet, so there are no
            exams to show. Please contact the school administrator.
        </div>

    </div>

<?php else: ?>

    <!-- ================= 1. EXAM OVERVIEW ================= -->

    <div class="card">

        <h2>Exam Overview</h2>

        <p class="muted">
            Counts are based on your assigned classes, sections and subjects
            using the filters below.
        </p>

        <div class="summary">

            <div class="summary-card accent-total">
                <span>Total Exams</span>
                <strong><?= (int)$overview["total"] ?></strong>
            </div>

            <div class="summary-card accent-upcoming">
                <span>Upcoming Exams</span>
                <strong><?= (int)$overview["upcoming"] ?></strong>
            </div>

            <div class="summary-card accent-ongoing">
                <span>Ongoing Exams</span>
                <strong><?= (int)$overview["ongoing"] ?></strong>
            </div>

            <div class="summary-card accent-completed">
                <span>Completed Exams</span>
                <strong><?= (int)$overview["completed"] ?></strong>
            </div>

        </div>

    </div>



    <!-- ================= 3. FILTERS ================= -->

    <div class="card">

        <h2>Filters</h2>

        <form method="get" action="index.php" id="examFilterForm">

            <div class="filter-grid">

                <div>
                    <label for="academic_year_uid">Academic Session</label>
                    <select name="academic_year_uid" id="academic_year_uid">
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

                <div>
                    <label for="class_uid">Class</label>
                    <select name="class_uid" id="class_uid">
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

                <div>
                    <label for="section_uid">Section</label>
                    <select name="section_uid" id="section_uid">
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

                <div>
                    <label for="subject_uid">Subject</label>
                    <select name="subject_uid" id="subject_uid">
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

                <div>
                    <label for="exam_uid">Exam</label>
                    <select name="exam_uid" id="exam_uid">
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

                <div>
                    <label for="date_from">Date From</label>
                    <input
                        type="date"
                        name="date_from"
                        id="date_from"
                        value="<?= htmlspecialchars($filter_from) ?>">
                </div>

                <div>
                    <label for="date_to">Date To</label>
                    <input
                        type="date"
                        name="date_to"
                        id="date_to"
                        value="<?= htmlspecialchars($filter_to) ?>">
                </div>

                <div>
                    <label for="status">Status</label>
                    <select name="status" id="status">
                        <option value="">All Status</option>
                        <option value="upcoming"  <?= ($filter_status === "upcoming")  ? "selected" : "" ?>>Upcoming</option>
                        <option value="ongoing"   <?= ($filter_status === "ongoing")   ? "selected" : "" ?>>Ongoing</option>
                        <option value="completed" <?= ($filter_status === "completed") ? "selected" : "" ?>>Completed</option>
                        <option value="scheduled" <?= ($filter_status === "scheduled") ? "selected" : "" ?>>Scheduled</option>
                        <option value="cancelled" <?= ($filter_status === "cancelled") ? "selected" : "" ?>>Cancelled</option>
                    </select>
                </div>

            </div>

            <div class="toolbar">
                <button type="submit" class="btn">Apply Filters</button>
                <a class="btn btn-secondary" href="<?= htmlspecialchars($clear_filters_url) ?>">Clear Filters</a>
                <a class="btn btn-outline" href="schedule.php">📅 View Schedule</a>
            </div>

        </form>

    </div>


    <!-- ================= 2. EXAM LIST ================= -->

    <div class="card">

        <h2>Exam List</h2>

        <p class="muted">
            <?= (int)$list_count ?> exam<?= ($list_count === 1) ? "" : "s" ?> found
            <?= $filters_applied ? "for the selected filters" : "for your classes" ?>.
            Click <strong>View Details</strong> to open an exam.
        </p>

        <?php if ($list_count === 0): ?>

            <div class="empty">
                No exams found. Try changing or clearing the filters.
            </div>

        <?php else: ?>

            <table>

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

                            <div class="muted">
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

                        <td class="row-actions">

                            <a
                                class="btn btn-outline"
                                href="view.php?uid=<?= urlencode((string)$exam_row["schedule_uid"]) ?>">
                                👁️ View Details
                            </a>

                            <a
                                class="btn btn-outline"
                                href="syllabus.php?uid=<?= urlencode((string)$exam_row["schedule_uid"]) ?>">
                                📄 Syllabus
                            </a>

                            <a
                                class="btn btn-outline"
                                href="schedule.php?academic_year_uid=<?= urlencode((string)$exam_row["academic_year_uid"]) ?>&amp;class_uid=<?= urlencode((string)$exam_row["class_uid"]) ?>&amp;section_uid=<?= urlencode((string)$exam_row["section_uid"]) ?>">
                                📅 Schedule
                            </a>

                            <a
                                class="btn"
                                href="../marks/index.php?action=load&amp;academic_year_uid=<?= urlencode((string)$exam_row["academic_year_uid"]) ?>&amp;class_uid=<?= urlencode((string)$exam_row["class_uid"]) ?>&amp;section_uid=<?= urlencode((string)$exam_row["section_uid"]) ?>&amp;subject_uid=<?= urlencode((string)$exam_row["subject_uid"]) ?>&amp;exam_uid=<?= urlencode((string)$exam_row["exam_uid"]) ?>&amp;term=<?= urlencode((string)$exam_row["term"]) ?>&amp;max_marks=<?= urlencode((string)$exam_row["max_marks"]) ?>">
                                📝 Enter Marks
                            </a>

                            <a
                                class="btn btn-outline"
                                href="results.php?uid=<?= urlencode((string)$exam_row["schedule_uid"]) ?>">
                                📊 Marks Status
                            </a>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        <?php endif; ?>

    </div>

<?php endif; ?>

</div>

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

</body>

</html>

