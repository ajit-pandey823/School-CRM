<?php

/*
 |--------------------------------------------------------------------------
 | TEACHER - CLASS TIMETABLE : WEEKLY GRID + DAY VIEW + FILTERS
 |--------------------------------------------------------------------------
 | Flow:
 | Dashboard > Class Timetable > (filters + weekly grid) > select a day >
 | period details
 |
 | Read only for the teacher: no period can be added, edited or deleted, and
 | neither the period times nor the assigned teacher can be changed here.
 |
 | Tables used: timetable_periods, timetable_slots
 |              (timetable_module_setup.sql), teacher_subjects, classes,
 |              sections, subjects, academic_years
 */

require_once __DIR__ . "/includes/timetable_common.php";


/*
 |--------------------------------------------------------------------------
 | FILTER VALUES (GET, so a view can be deep linked / bookmarked)
 |--------------------------------------------------------------------------
 | Every value is validated against the teacher's own assignments, so an
 | invalid or foreign value is simply ignored.
 */

$filter_year    = trim($_GET["academic_year_uid"] ?? "");
$filter_class   = trim($_GET["class_uid"] ?? "");
$filter_section = trim($_GET["section_uid"] ?? "");
$filter_day     = trim($_GET["day"] ?? "");
$filter_subject = trim($_GET["subject_uid"] ?? "");

if ($filter_year !== "" && !isset($tt_year_options[$filter_year])) {
    $filter_year = "";
}

if ($filter_class !== "" && !isset($tt_class_options[$filter_class])) {
    $filter_class = "";
}

if ($filter_section !== "" && !isset($tt_section_options[$filter_section])) {
    $filter_section = "";
}

if ($filter_subject !== "" && !isset($tt_subject_options[$filter_subject])) {
    $filter_subject = "";
}

/* the day must be a real school day (Monday..Saturday) */
$filter_day = (int)$filter_day;

if ($filter_day < 1 || $filter_day > 6) {
    $filter_day = 0;
}

/*
 | A class + section is always required to show a grid. The first assignment
 | is used as the default so the page is never empty on a normal login.
 */

if ($filter_section === "") {

    if ($filter_class !== "") {

        foreach ($tt_section_options as $tt_section_key => $tt_section_data) {

            if ($tt_section_data["class_uid"] === $filter_class) {
                $filter_section = $tt_section_key;
                break;
            }
        }
    }

    if ($filter_section === "" && !empty($tt_assignments)) {
        $filter_section = $tt_assignments[0]["section_uid"];
    }

    if ($filter_class === "" && $filter_section !== "" && isset($tt_section_options[$filter_section])) {
        $filter_class = $tt_section_options[$filter_section]["class_uid"];
    }
}

if ($filter_year === "" && !empty($tt_assignments)) {
    $filter_year = $tt_assignments[0]["academic_year_uid"];
}

$session_label = ($filter_year !== "" && isset($tt_year_options[$filter_year]))
    ? $tt_year_options[$filter_year]
    : "-";

$class_label = ($filter_class !== "" && isset($tt_class_options[$filter_class]))
    ? $tt_class_options[$filter_class]
    : "-";

$section_label = ($filter_section !== "" && isset($tt_section_options[$filter_section]))
    ? $tt_section_options[$filter_section]["label"]
    : "-";

$subject_label = ($filter_subject !== "" && isset($tt_subject_options[$filter_subject]))
    ? $tt_subject_options[$filter_subject]
    : "All Subjects";


/*
 |--------------------------------------------------------------------------
 | LOAD THE TIMETABLE
 |--------------------------------------------------------------------------
 | $periods : grid rows (periods + breaks) of the selected session
 | $slots   : "day|period" => slot, already filtered by subject when asked
 */

$periods = [];
$slots   = [];

if ($has_assignments && $filter_year !== "" && $filter_class !== "" && $filter_section !== "") {

    $periods = ttFetchPeriods($conn, $school_uid, $filter_year);
}

/*
 |--------------------------------------------------------------------------
 | MY OWN PERIODS ACROSS THE WEEK
 |--------------------------------------------------------------------------
 | Counted from the unfiltered week so the overview stays stable even when
 | the Day or Subject filter hides part of the grid.
 */

$week_slots = [];
$slots       = [];

if ($has_assignments && $filter_year !== "" && $filter_class !== "" && $filter_section !== "") {

    /* the grid is filtered by the Subject filter */
    $slots = ttFetchSlots(
        $conn,
        $school_uid,
        $filter_year,
        $filter_class,
        $filter_section,
        [],
        $filter_subject
    );

    /* the overview counts always use the full week */
    $week_slots = ttFetchSlots($conn, $school_uid, $filter_year, $filter_class, $filter_section);
}

$my_periods_by_day = array_fill(1, 6, 0);
$my_periods_total   = 0;
$teaching_days      = 0;

foreach ($tt_days as $tt_day_key => $tt_day_name) {

    $day_has_mine = false;

    foreach ($periods as $tt_period) {

        $tt_cell_key = $tt_day_key . "|" . $tt_period["period_no"];

        if (!isset($week_slots[$tt_cell_key])) {
            continue;
        }

        if ($week_slots[$tt_cell_key]["teacher_uid"] === $teacher_uid) {
            $my_periods_by_day[$tt_day_key]++;
            $my_periods_total++;
            $day_has_mine = true;
        }
    }

    if ($day_has_mine) {
        $teaching_days++;
    }
}

/* "view" is the single toggle: week (default) or one single day */
$view_mode = (($_GET["view"] ?? "") === "day") ? "day" : "week";

/* in the day view fall back to today when no valid day was chosen */
$active_day = $filter_day;

if ($view_mode === "day" && $active_day === 0) {
    $active_day = $tt_current_day;
}

/* the days actually rendered in the current view */
$view_days = ($view_mode === "day") ? [$active_day] : array_keys($tt_days);


/*
 |--------------------------------------------------------------------------
 | BASE URL BUILDER
 |--------------------------------------------------------------------------
 | Used by every link so the current session / class / section / subject are
 | carried over.
 */

$ttBaseUrl = function ($overrides = []) use (
    $filter_year,
    $filter_class,
    $filter_section,
    $filter_subject
) {
    $query = [];

    if ($filter_year    !== "") { $query["academic_year_uid"] = $filter_year; }
    if ($filter_class   !== "") { $query["class_uid"]        = $filter_class; }
    if ($filter_section !== "") { $query["section_uid"]      = $filter_section; }
    if ($filter_subject !== "") { $query["subject_uid"]      = $filter_subject; }

    foreach ($overrides as $key => $value) {

        if ($value === "" || $value === null) {
            unset($query[$key]);
            continue;
        }

        $query[$key] = $value;
    }

    return "index.php?" . http_build_query($query);
};

$print_url = $ttBaseUrl(["view" => $view_mode, "day" => ($view_mode === "day" ? $active_day : "")]);

?>
<?php
$UI_ROOT         = '../';
$UI_ACTIVE       = 'timetable';
$UI_TITLE        = 'Class Timetable';
$UI_CRUMBS       = [];
$UI_PROFILE_NAME = $teacher_name;
include __DIR__ . '/../includes/header.php';
?>

<style>
    .tt-grid { min-width: 880px; }
    .tt-grid .cell-break    { background:#fff7e6; }
    .tt-grid .cell-lunch    { background:#ffe9d6; }
    .tt-grid .cell-activity { background:#eef4ff; }
    .tt-grid .cell-free     { background:#f4f4f6; }
    .tt-grid .cell.is-mine  { background:#eaf2ff; }
    .tt-grid .cell.is-mine.is-ongoing { background:#d4f0de; }
    .tt-grid .cell.is-ongoing { outline: 2px solid #198754; outline-offset:-2px; }
    .tt-grid .cell .subject     { font-weight:bold; color:#1a1a1a; }
    .tt-grid .cell .who         { color:#555; font-size:12px; }
    .tt-grid .cell .break-label { font-weight:bold; color:#8a6d3b; }
    .tt-grid .cell-free .break-label { color:#6b7280; }
    .legend .swatch { display:inline-block; width:14px; height:14px; border:1px solid #ddd; border-radius:3px; vertical-align:-2px; margin-right:5px; }
    .now-flag { display:inline-block; margin-top:4px; padding:2px 7px; color:#fff; font-size:10px; font-weight:bold; border-radius:4px; background:#198754; }
    @media print { .no-print { display:none !important; } }
</style>

<!-- ================= HEADER ================= -->

<div class="card mb-4 no-print">

    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <strong>Class Timetable</strong>
        <a href="../dashboard.php" class="btn btn-outline-secondary btn-sm">&larr; Back to Dashboard</a>
    </div>

    <div class="card-body">

        <p class="text-body-secondary mb-2">
            Teacher: <strong><?= htmlspecialchars($teacher_name) ?></strong>
        </p>

        <div class="alert alert-info mb-3">
            You can view your assigned class timetable, a single day and the
            period details. Creating, editing or deleting a period and changing
            the period time or the assigned teacher is managed by the school
            administrator.
        </div>

        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars($ttBaseUrl(["view" => "week", "day" => ""])) ?>">🗓️ View Timetable</a>
            <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars($ttBaseUrl(["view" => "day", "day" => $tt_current_day])) ?>">📅 View Day Schedule</a>
            <a class="btn btn-outline-secondary btn-sm" href="../classes/view.php">🏫 View Class Details</a>
            <a class="btn btn-success btn-sm" href="<?= htmlspecialchars($print_url) ?>" onclick="window.print(); return false;">🖨️ Print / Download</a>
        </div>

    </div>

</div>

<?php if (!$has_assignments): ?>

    <div class="alert alert-warning" role="alert">
        No class or subject has been assigned to you yet, so there is no
        timetable to show. Please contact the school administrator.
    </div>

<?php else: ?>

    <!-- ================= 1. OVERVIEW ================= -->

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-body-secondary small text-uppercase">Academic Session</div>
                    <div class="fs-5 fw-semibold"><?= htmlspecialchars($session_label) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-body-secondary small text-uppercase">Class</div>
                    <div class="fs-5 fw-semibold"><?= htmlspecialchars($class_label) ?> &middot; <?= htmlspecialchars($section_label) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-body-secondary small text-uppercase">Current Day</div>
                    <div class="fs-5 fw-semibold text-success"><?= htmlspecialchars(ttDayLabel($tt_current_day)) ?></div>
                    <div class="small text-body-secondary"><?= htmlspecialchars($tt_current_date) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-body-secondary small text-uppercase">My Periods This Week</div>
                    <div class="fs-4 fw-semibold text-primary"><?= (int)$my_periods_total ?></div>
                    <div class="small text-body-secondary"><?= (int)$teaching_days ?> teaching day(s) &middot; <?= (int)count($periods) ?> periods/day</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ================= 2. FILTERS ================= -->

    <div class="card mb-4">

        <div class="card-header"><strong>Filters</strong></div>

        <div class="card-body">

            <form method="get" action="index.php">

                <div class="row g-3">

                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label" for="academic_year_uid">Academic Session</label>
                        <select class="form-select" name="academic_year_uid" id="academic_year_uid">
                            <option value="">All Sessions</option>
                            <?php foreach ($tt_year_options as $tt_key => $tt_label): ?>
                                <option value="<?= htmlspecialchars($tt_key) ?>" <?= ($filter_year === (string)$tt_key) ? "selected" : "" ?>><?= htmlspecialchars($tt_label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label" for="class_uid">Class</label>
                        <select class="form-select" name="class_uid" id="class_uid">
                            <option value="">All Classes</option>
                            <?php foreach ($tt_class_options as $tt_key => $tt_label): ?>
                                <option value="<?= htmlspecialchars($tt_key) ?>" <?= ($filter_class === (string)$tt_key) ? "selected" : "" ?>><?= htmlspecialchars($tt_label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label" for="section_uid">Section</label>
                        <select class="form-select" name="section_uid" id="section_uid">
                            <option value="">All Sections</option>
                            <?php foreach ($tt_section_options as $tt_key => $tt_data): ?>
                                <option value="<?= htmlspecialchars($tt_key) ?>" data-class="<?= htmlspecialchars($tt_data["class_uid"]) ?>" <?= ($filter_section === (string)$tt_key) ? "selected" : "" ?>><?= htmlspecialchars($tt_data["label"]) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label" for="subject_uid">Subject</label>
                        <select class="form-select" name="subject_uid" id="subject_uid">
                            <option value="">All Subjects</option>
                            <?php foreach ($tt_subject_options as $tt_key => $tt_label): ?>
                                <option value="<?= htmlspecialchars($tt_key) ?>" <?= ($filter_subject === (string)$tt_key) ? "selected" : "" ?>><?= htmlspecialchars($tt_label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label" for="day">Day</label>
                        <select class="form-select" name="day" id="day">
                            <option value="">All Days</option>
                            <?php foreach ($tt_days as $tt_key => $tt_label): ?>
                                <option value="<?= (int)$tt_key ?>" <?= ($filter_day === (int)$tt_key) ? "selected" : "" ?>><?= htmlspecialchars($tt_label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label" for="view">View</label>
                        <select class="form-select" name="view" id="view">
                            <option value="week" <?= ($view_mode === "week") ? "selected" : "" ?>>Weekly View</option>
                            <option value="day"  <?= ($view_mode === "day")  ? "selected" : "" ?>>Day View</option>
                        </select>
                    </div>

                </div>

                <div class="d-flex flex-wrap gap-2 mt-3">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a class="btn btn-secondary" href="index.php">Clear Filters</a>
                </div>

            </form>

        </div>

    </div>

<?php if (empty($periods)): ?>

    <div class="alert alert-warning" role="alert">
        No periods have been created for this session yet. Please contact
        the school administrator.
    </div>

<?php else: ?>


    <!-- ================= 3. DAY VIEW SELECTOR ================= -->

    <div class="card mb-4 no-print">

        <div class="card-header"><strong>Select a Day</strong></div>

        <div class="card-body">

            <p class="text-body-secondary">Tap a day to open that day's full class schedule.</p>

            <div class="d-flex flex-wrap gap-2">

                <?php foreach ($tt_days as $tt_day_key => $tt_day_name): ?>

                    <a
                        class="btn btn-sm <?= ($tt_day_key === $active_day) ? "btn-primary" : "btn-outline-secondary" ?>"
                        href="<?= htmlspecialchars($ttBaseUrl(["view" => "day", "day" => $tt_day_key])) ?>">
                        <?= htmlspecialchars($tt_day_name) ?>
                        <?php if ($tt_day_key === $tt_current_day): ?>
                            <span class="badge bg-success">Today</span>
                        <?php endif; ?>
                    </a>

                <?php endforeach; ?>

            </div>

        </div>

    </div>

    <!-- ================= 4. WEEKLY / DAY GRID ================= -->

    <div class="card mb-4">

        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <strong>
                <?= ($view_mode === "day")
                        ? htmlspecialchars(ttDayLabel($active_day)) . " Schedule"
                        : "Weekly Timetable" ?>
            </strong>
            <div class="d-flex flex-wrap gap-2 no-print">
                <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars($ttBaseUrl(["view" => "week", "day" => ""])) ?>">🗓️ Weekly View</a>
                <?php if ($view_mode === "day"): ?>
                    <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars($ttBaseUrl(["view" => "day", "day" => $tt_current_day])) ?>">📅 Jump to Today</a>
                <?php endif; ?>
                <a class="btn btn-success btn-sm" href="<?= htmlspecialchars($print_url) ?>" onclick="window.print(); return false;">🖨️ Print / Download</a>
            </div>
        </div>

        <div class="card-body">

            <p class="text-body-secondary">
                <?= htmlspecialchars($class_label) ?>
                &middot; Section <?= htmlspecialchars($section_label) ?>
                &middot; <?= htmlspecialchars($session_label) ?>
                &middot; Subject: <?= htmlspecialchars($subject_label) ?>
            </p>

            <div class="table-responsive">

                <table class="table table-bordered align-middle tt-grid mb-0">

                    <thead>
                        <tr>
                            <th style="text-align:left;">Period</th>
                            <th style="text-align:left;">Time</th>
                            <?php foreach ($view_days as $tt_view_day): ?>
                                <th class="<?= ($tt_view_day === $tt_current_day) ? "table-success" : "" ?>">
                                    <?= htmlspecialchars(ttDayLabel($tt_view_day)) ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                </thead>

                <tbody>

                <?php foreach ($periods as $tt_period): ?>

                    <?php
                    $tt_period_type = strtolower(trim((string)$tt_period["period_type"]));
                    ?>

                    <tr>

                        <th>
                            <?php
                            echo htmlspecialchars(
                                ($tt_period_type === "subject")
                                    ? (string)$tt_period["period_label"]
                                    : ttPeriodTypeLabel($tt_period_type, $tt_period["period_label"])
                            );
                            ?>
                        </th>

                        <th>
                            <span class="time">
                                <?= htmlspecialchars(ttPeriodTimeLabel($tt_period["start_time"], $tt_period["end_time"])) ?>
                            </span>
                        </th>

                        <?php foreach ($view_days as $tt_view_day): ?>

                            <?php
                            $tt_cell_key   = $tt_view_day . "|" . $tt_period["period_no"];
                            $tt_slot       = isset($slots[$tt_cell_key]) ? $slots[$tt_cell_key] : null;
                            $tt_is_ongoing = ttIsOngoing($tt_view_day, $tt_period["start_time"], $tt_period["end_time"]);
                            $tt_is_mine    = ($tt_slot !== null && $tt_slot["teacher_uid"] === $teacher_uid);

                            $tt_cell_classes = ["cell", ttPeriodCss($tt_period_type)];

                            if ($tt_is_ongoing) { $tt_cell_classes[] = "is-ongoing"; }
                            if ($tt_is_mine)    { $tt_cell_classes[] = "is-mine"; }
                            ?>

                            <td class="<?= htmlspecialchars(implode(" ", $tt_cell_classes)) ?>">


                                <?php if ($tt_slot !== null): ?>

                                    <?php if ($tt_slot["slot_uid"] !== ""): ?>
                                        <a
                                            style="text-decoration:none;color:inherit;display:block;"
                                            href="view.php?slot=<?= urlencode((string)$tt_slot["slot_uid"]) ?>&amp;day=<?= (int)$tt_view_day ?>&amp;period=<?= (int)$tt_period["period_no"] ?>">
                                    <?php endif; ?>

                                    <span class="subject">
                                        <?= htmlspecialchars(($tt_slot["subject_name"] !== "") ? $tt_slot["subject_name"] : "Free Period") ?>
                                    </span>

                                    <span class="who">
                                        <?= htmlspecialchars(($tt_slot["teacher_name"] !== "") ? $tt_slot["teacher_name"] : "To be decided") ?>
                                    </span>

                                    <span class="who">
                                        <?= htmlspecialchars(($tt_slot["room_no"] !== "") ? $tt_slot["room_no"] : "Room not set") ?>
                                    </span>

                                    <?php if ($tt_is_ongoing): ?>
                                        <span class="now-flag">NOW</span>
                                    <?php endif; ?>

                                    <?php if ($tt_slot["slot_uid"] !== ""): ?>
                                        </a>
                                    <?php endif; ?>

                                <?php else: ?>

                                    <?php if ($tt_period_type === "subject"): ?>

                                        <span class="break-label">Free Period</span>

                                    <?php else: ?>

                                        <span class="break-label">
                                            <?= htmlspecialchars(ttPeriodTypeLabel($tt_period_type, $tt_period["period_label"])) ?>
                                        </span>

                                        <?php if ($tt_is_ongoing): ?>
                                            <span class="now-flag">NOW</span>
                                        <?php endif; ?>

                                    <?php endif; ?>

                                <?php endif; ?>

                            </td>

                        <?php endforeach; ?>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

            </div>

            <div class="legend d-flex flex-wrap gap-3 mt-3 small text-body-secondary">
                <span><span class="swatch" style="background:#eaf2ff;"></span>Your period</span>
                <span><span class="swatch" style="background:#fff7e6;"></span>Short Break</span>
                <span><span class="swatch" style="background:#ffe9d6;"></span>Lunch Break</span>
                <span><span class="swatch" style="background:#eef4ff;"></span>Activity Period</span>
                <span><span class="swatch" style="background:#f4f4f6;"></span>Free Period</span>
                <span><span class="swatch" style="background:#d4f0de;"></span>Ongoing now</span>
            </div>

        </div>

    </div>

<?php endif; ?>

<?php endif; ?>

<script>
/* Section list follows the selected class. */
(function () {
    var classSelect   = document.getElementById("class_uid");
    var sectionSelect = document.getElementById("section_uid");
    if (!classSelect || !sectionSelect) { return; }
    var sections = sectionSelect.querySelectorAll("option[data-class]");
    function refreshSections() {
        var selectedClass = classSelect.value;
        for (var i = 0; i < sections.length; i++) {
            var option = sections[i];
            var show   = (selectedClass === "" || option.getAttribute("data-class") === selectedClass);
            option.hidden = !show;
            option.disabled = !show;
            if (!show) { option.selected = false; }
        }
    }
    classSelect.addEventListener("change", refreshSections);
    refreshSections();
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

