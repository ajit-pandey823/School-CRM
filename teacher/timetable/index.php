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
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Class Timetable</title>

<style>
<?= ttModuleStyles() ?>
</style>

</head>

<body>

<div class="container">

    <!-- ================= HEADER ================= -->

    <div class="card no-print">

        <a class="back-link" href="../dashboard.php">&larr; Back to Dashboard</a>

        <h1>Class Timetable</h1>

        <div class="teacher-info">
            Teacher:
            <strong><?= htmlspecialchars($teacher_name) ?></strong>
        </div>

        <div class="notice">
            You can view your assigned class timetable, a single day and the
            period details. Creating, editing or deleting a period and changing
            the period time or the assigned teacher is managed by the school
            administrator.
        </div>

        <div class="toolbar">
            <a class="btn btn-outline" href="<?= htmlspecialchars($ttBaseUrl(["view" => "week", "day" => ""])) ?>">🗓️ View Timetable</a>
            <a class="btn btn-outline" href="<?= htmlspecialchars($ttBaseUrl(["view" => "day", "day" => $tt_current_day])) ?>">📅 View Day Schedule</a>
            <a class="btn btn-outline" href="../classes/view.php">🏫 View Class Details</a>
            <a class="btn btn-green" href="<?= htmlspecialchars($print_url) ?>" onclick="window.print(); return false;">🖨️ Print / Download</a>
        </div>

    </div>

<?php if (!$has_assignments): ?>

    <div class="card">

        <div class="empty">
            No class or subject has been assigned to you yet, so there is no
            timetable to show. Please contact the school administrator.
        </div>

    </div>

<?php else: ?>

    <!-- ================= 1. TOP SECTION ================= -->

    <div class="card">

        <h2>Timetable Details</h2>

        <div class="top-info">

            <div class="info-box">
                <span>Academic Session</span>
                <strong><?= htmlspecialchars($session_label) ?></strong>
            </div>

            <div class="info-box">
                <span>Class</span>
                <strong><?= htmlspecialchars($class_label) ?></strong>
            </div>

            <div class="info-box">
                <span>Section</span>
                <strong><?= htmlspecialchars($section_label) ?></strong>
            </div>

            <div class="info-box is-today">
                <span>Current Day</span>
                <strong><?= htmlspecialchars(ttDayLabel($tt_current_day)) ?></strong>
            </div>

            <div class="info-box is-today">
                <span>Current Date</span>
                <strong><?= htmlspecialchars($tt_current_date) ?></strong>
            </div>

            <div class="info-box">
                <span>My Periods This Week</span>
                <strong><?= (int)$my_periods_total ?></strong>
            </div>

            <div class="info-box">
                <span>Teaching Days</span>
                <strong><?= (int)$teaching_days ?></strong>
            </div>

            <div class="info-box">
                <span>Periods Per Day</span>
                <strong><?= (int)count($periods) ?></strong>
            </div>

        </div>

    </div>

    <!-- ================= 2. FILTERS ================= -->

    <div class="card no-print">

        <h2>Filters</h2>

        <form method="get" action="index.php">

            <div class="filter-grid">

                <div>
                    <label for="academic_year_uid">Academic Session</label>
                    <select name="academic_year_uid" id="academic_year_uid">
                        <option value="">All Sessions</option>
                        <?php foreach ($tt_year_options as $tt_key => $tt_label): ?>
                            <option value="<?= htmlspecialchars($tt_key) ?>" <?= ($filter_year === (string)$tt_key) ? "selected" : "" ?>><?= htmlspecialchars($tt_label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="class_uid">Class</label>
                    <select name="class_uid" id="class_uid">
                        <option value="">All Classes</option>
                        <?php foreach ($tt_class_options as $tt_key => $tt_label): ?>
                            <option value="<?= htmlspecialchars($tt_key) ?>" <?= ($filter_class === (string)$tt_key) ? "selected" : "" ?>><?= htmlspecialchars($tt_label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="section_uid">Section</label>
                    <select name="section_uid" id="section_uid">
                        <option value="">All Sections</option>
                        <?php foreach ($tt_section_options as $tt_key => $tt_data): ?>
                            <option value="<?= htmlspecialchars($tt_key) ?>" data-class="<?= htmlspecialchars($tt_data["class_uid"]) ?>" <?= ($filter_section === (string)$tt_key) ? "selected" : "" ?>><?= htmlspecialchars($tt_data["label"]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="subject_uid">Subject</label>
                    <select name="subject_uid" id="subject_uid">
                        <option value="">All Subjects</option>
                        <?php foreach ($tt_subject_options as $tt_key => $tt_label): ?>
                            <option value="<?= htmlspecialchars($tt_key) ?>" <?= ($filter_subject === (string)$tt_key) ? "selected" : "" ?>><?= htmlspecialchars($tt_label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="day">Day</label>
                    <select name="day" id="day">
                        <option value="">All Days</option>
                        <?php foreach ($tt_days as $tt_key => $tt_label): ?>
                            <option value="<?= (int)$tt_key ?>" <?= ($filter_day === (int)$tt_key) ? "selected" : "" ?>><?= htmlspecialchars($tt_label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="view">View</label>
                    <select name="view" id="view">
                        <option value="week" <?= ($view_mode === "week") ? "selected" : "" ?>>Weekly View</option>
                        <option value="day"  <?= ($view_mode === "day")  ? "selected" : "" ?>>Day View</option>
                    </select>
                </div>

            </div>

            <div class="toolbar">
                <button type="submit" class="btn">Apply Filters</button>
                <a class="btn btn-secondary" href="index.php">Clear Filters</a>
            </div>

        </form>

    </div>

<?php if (empty($periods)): ?>

    <div class="card">
        <div class="empty">
            No periods have been created for this session yet. Please contact
            the school administrator.
        </div>
    </div>

<?php else: ?>


    <!-- ================= 3. DAY VIEW SELECTOR ================= -->

    <div class="card no-print">

        <h2>Select a Day</h2>

        <p class="muted">Tap a day to open that day's full class schedule.</p>

        <div class="day-links">

            <?php foreach ($tt_days as $tt_day_key => $tt_day_name): ?>

                <a
                    class="<?= ($tt_day_key === $tt_current_day) ? "is-today " : "" ?><?= ($tt_day_key === $active_day) ? "is-active" : "" ?>"
                    href="<?= htmlspecialchars($ttBaseUrl(["view" => "day", "day" => $tt_day_key])) ?>">
                    <?= htmlspecialchars($tt_day_name) ?>
                    <?php if ($tt_day_key === $tt_current_day): ?>
                        <span class="muted">(Today)</span>
                    <?php endif; ?>
                </a>

            <?php endforeach; ?>

        </div>

    </div>

    <!-- ================= 4. WEEKLY / DAY GRID ================= -->

    <div class="card">

        <div class="day-heading">

            <h2 style="margin:0;">
                <?= ($view_mode === "day")
                        ? htmlspecialchars(ttDayLabel($active_day)) . " Schedule"
                        : "Weekly Timetable" ?>
            </h2>

            <div class="toolbar no-print" style="margin-top:0;">
                <a class="btn btn-outline" href="<?= htmlspecialchars($ttBaseUrl(["view" => "week", "day" => ""])) ?>">🗓️ Weekly View</a>
                <?php if ($view_mode === "day"): ?>
                    <a class="btn btn-outline" href="<?= htmlspecialchars($ttBaseUrl(["view" => "day", "day" => $tt_current_day])) ?>">📅 Jump to Today</a>
                <?php endif; ?>
                <a class="btn btn-green" href="<?= htmlspecialchars($print_url) ?>" onclick="window.print(); return false;">🖨️ Print / Download</a>
            </div>

        </div>

        <p class="muted">
            <?= htmlspecialchars($class_label) ?>
            &middot; Section <?= htmlspecialchars($section_label) ?>
            &middot; <?= htmlspecialchars($session_label) ?>
            &middot; Subject: <?= htmlspecialchars($subject_label) ?>
        </p>

        <div class="grid-scroll">

            <table class="grid">

                <thead>
                    <tr>
                        <th style="text-align:left;">Period</th>
                        <th style="text-align:left;">Time</th>
                        <?php foreach ($view_days as $tt_view_day): ?>
                            <th class="<?= ($tt_view_day === $tt_current_day) ? "is-today" : "" ?>">
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

        <div class="legend">
            <span><span class="swatch" style="background:#eaf2ff;"></span>Your period</span>
            <span><span class="swatch" style="background:#fff7e6;"></span>Short Break</span>
            <span><span class="swatch" style="background:#ffe9d6;"></span>Lunch Break</span>
            <span><span class="swatch" style="background:#eef4ff;"></span>Activity Period</span>
            <span><span class="swatch" style="background:#f4f4f6;"></span>Free Period</span>
            <span><span class="swatch" style="background:#d4f0de;"></span>Ongoing now</span>
        </div>

    </div>

<?php endif; ?>

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

            option.hidden = !show;
            option.disabled = !show;
        }
    }

    refreshSections();
    classSelect.addEventListener("change", refreshSections);

})();

</script>

</body>

</html>

