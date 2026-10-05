<?php

/*
 |--------------------------------------------------------------------------
 | TEACHER - CLASS TIMETABLE : PERIOD DETAILS
 |--------------------------------------------------------------------------
 | Opened by tapping any subject cell in the weekly grid or the day view.
 |
 | Read only: the period, its times, the subject, the room and the assigned
 | teacher are only displayed here, never changed.
 |
 | Tables used: timetable_slots, timetable_periods (timetable_module_setup.sql)
 */

require_once __DIR__ . "/includes/timetable_common.php";


/*
 |--------------------------------------------------------------------------
 | REQUESTED SLOT
 |--------------------------------------------------------------------------
 | The slot is only accepted when it belongs to a class / section / subject
 | the teacher is actually assigned to.
 */

$slot_uid = trim($_GET["slot"] ?? "");

$slot   = null;
$period = null;

$notice = "";

if ($slot_uid === "") {
    $notice = "No period was selected.";
} else {

    $sql = "SELECT
                s.slot_uid,
                s.school_uid,
                s.academic_year_uid,
                s.class_uid,
                s.section_uid,
                s.day_of_week,
                s.period_no,
                s.subject_uid,
                s.teacher_uid,
                s.room_no,
                s.note,
                COALESCE(sub.subject_name, '') AS subject_name,
                COALESCE(t.teacher_name, '')    AS teacher_name,
                c.class_name,
                sec.section_name,
                COALESCE(ay.year_name, '')     AS year_name
            FROM timetable_slots s

            LEFT JOIN subjects sub
                ON sub.subject_uid = s.subject_uid

            LEFT JOIN teachers t
                ON t.teacher_uid = s.teacher_uid

            INNER JOIN classes c
                ON c.class_uid = s.class_uid

            INNER JOIN sections sec
                ON sec.section_uid = s.section_uid

            LEFT JOIN academic_years ay
                ON ay.academic_year_uid = s.academic_year_uid

            INNER JOIN teacher_subjects ts
                ON ts.school_uid        = s.school_uid
               AND ts.class_uid         = s.class_uid
               AND ts.section_uid       = s.section_uid
               AND ts.academic_year_uid = s.academic_year_uid
               AND ts.teacher_uid       = ?

            WHERE s.slot_uid = ?
              AND s.school_uid = ?
              AND s.status = 'active'
            LIMIT 1";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("Period query failed: " . $conn->error);
    }

    $stmt->bind_param("sss", $teacher_uid, $slot_uid, $school_uid);
    $stmt->execute();
    $slot = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$slot) {
        $notice = "Period not found, or it does not belong to the classes, "
                . "sections and subjects assigned to you.";
    }
}

if ($slot !== null) {

    /* the period row gives the label, the type and the times */
    $sql = "SELECT period_no, period_label, period_type, start_time, end_time
            FROM timetable_periods
            WHERE school_uid = ?
              AND academic_year_uid = ?
              AND period_no = ?
              AND status = 'active'
            LIMIT 1";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("Period query failed: " . $conn->error);
    }

    $stmt->bind_param("ssi", $slot["school_uid"], $slot["academic_year_uid"], $slot["period_no"]);
    $stmt->execute();
    $period = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$period) {
        $notice = "The period structure of this session is no longer available.";
        $slot   = null;
    }
}

/* whether this period is the one running right now */
$is_ongoing = ($slot !== null && $period !== null)
    ? ttIsOngoing($slot["day_of_week"], $period["start_time"], $period["end_time"])
    : false;

$is_mine = ($slot !== null && $slot["teacher_uid"] === $teacher_uid);

/* link back to the same day in the grid */
$back_url = "index.php";

if ($slot !== null) {

    $back_url = "index.php?" . http_build_query([
        "view"              => "day",
        "day"               => (int)$slot["day_of_week"],
        "academic_year_uid" => $slot["academic_year_uid"],
        "class_uid"         => $slot["class_uid"],
        "section_uid"       => $slot["section_uid"],
        "subject_uid"       => $slot["subject_uid"],
    ]);
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Period Details</title>

<style>
<?= ttModuleStyles() ?>
</style>

</head>

<body>

<div class="container">

    <div class="card no-print">

        <a class="back-link" href="../dashboard.php">&larr; Back to Dashboard</a>

        <h1>Period Details</h1>

        <div class="teacher-info">
            Teacher:
            <strong><?= htmlspecialchars($teacher_name) ?></strong>
        </div>

        <div class="toolbar">
            <a class="btn btn-outline" href="<?= htmlspecialchars($back_url) ?>">🗓️ Back to Timetable</a>
            <a class="btn btn-green" href="<?= htmlspecialchars($back_url) ?>" onclick="window.print(); return false;">🖨️ Print / Download</a>
        </div>

    </div>

<?php if ($slot === null): ?>

    <div class="card">
        <div class="empty">
            <?= htmlspecialchars($notice) ?>
        </div>
    </div>

<?php else: ?>

    <!-- ================= PERIOD SUMMARY ================= -->

    <div class="card">

        <div class="top-info">

            <div class="info-box">
                <span>Day</span>
                <strong><?= htmlspecialchars(ttDayLabel($slot["day_of_week"])) ?></strong>
            </div>

            <div class="info-box">
                <span>Period</span>
                <strong><?= htmlspecialchars($period["period_label"]) ?></strong>
            </div>

            <div class="info-box <?= $is_ongoing ? "is-today" : "" ?>">
                <span>Time</span>
                <strong><?= htmlspecialchars(ttPeriodTimeLabel($period["start_time"], $period["end_time"])) ?></strong>
            </div>

            <div class="info-box">
                <span>Type</span>
                <strong>
                    <?php
                    $tt_type = strtolower(trim((string)$period["period_type"]));

                    echo htmlspecialchars(
                        ($tt_type === "subject")
                            ? "Subject Period"
                            : ttPeriodTypeLabel($tt_type, $period["period_label"])
                    );
                    ?>
                </strong>
            </div>

        </div>

        <?php if ($is_ongoing): ?>
            <div class="notice" style="margin-top:14px;">
                This period is running right now.
            </div>
        <?php endif; ?>

    </div>

    <!-- ================= PERIOD INFORMATION ================= -->

    <div class="card">

        <h2>Period Information</h2>

        <table>
            <tbody>
                <tr>
                    <th>Subject</th>
                    <td><?= htmlspecialchars(($slot["subject_name"] !== "") ? $slot["subject_name"] : "Not assigned") ?></td>
                </tr>
                <tr>
                    <th>Teacher</th>
                    <td>
                        <?php if ($is_mine): ?>
                            <strong><?= htmlspecialchars($teacher_name) ?></strong> (You)
                        <?php else: ?>
                            <?= htmlspecialchars(($slot["teacher_name"] !== "") ? $slot["teacher_name"] : "To be decided") ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Class</th>
                    <td><?= htmlspecialchars($slot["class_name"]) ?></td>
                </tr>
                <tr>
                    <th>Section</th>
                    <td><?= htmlspecialchars($slot["section_name"]) ?></td>
                </tr>
                <tr>
                    <th>Room / Classroom</th>
                    <td><?= htmlspecialchars(($slot["room_no"] !== "") ? $slot["room_no"] : "Room not set") ?></td>
                </tr>
                <tr>
                    <th>Period Time</th>
                    <td><?= htmlspecialchars(ttPeriodTimeLabel($period["start_time"], $period["end_time"])) ?></td>
                </tr>
                <tr>
                    <th>Academic Session</th>
                    <td><?= htmlspecialchars(($slot["year_name"] !== "") ? $slot["year_name"] : $slot["academic_year_uid"]) ?></td>
                </tr>
                <?php if (trim((string)$slot["note"]) !== ""): ?>
                    <tr>
                        <th>Note</th>
                        <td><?= htmlspecialchars($slot["note"]) ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

    </div>

<?php endif; ?>

</div>

</body>

</html>

