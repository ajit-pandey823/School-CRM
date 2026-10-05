<?php

/*
|--------------------------------------------------------------------------
| TEACHER - EXAMS : VIEW / DOWNLOAD SYLLABUS (read only)
|--------------------------------------------------------------------------
| syllabus.php?uid=<schedule_uid>             view + print syllabus
| syllabus.php?uid=<schedule_uid>&download=1  download as a .txt file
|
| No exam data can be changed from this page.
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

    <title>Syllabus</title>

    <style>
    <?= examsModuleStyles() ?>
    </style>

    </head>

    <body>

    <div class="container">

        <div class="card">

            <a class="back-link" href="index.php">&larr; Back to Exam List</a>

            <h1>Syllabus</h1>

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

$syllabus_text     = trim((string)$exam["syllabus"]);
$instructions_text = trim((string)$exam["instructions"]);

$syllabus_text = ($syllabus_text === "")
    ? "Syllabus has not been published for this exam yet."
    : $syllabus_text;

$file_base = preg_replace(
    "/[^A-Za-z0-9]+/",
    "_",
    (string)$exam["exam_name"]
        . "_" . (string)$exam["subject_name"]
        . "_" . (string)$exam["class_name"]
        . "_" . (string)$exam["section_name"]
);

$download_url = "syllabus.php?uid=" . urlencode((string)$exam["schedule_uid"]) . "&download=1";

/*
|--------------------------------------------------------------------------
| DOWNLOAD AS PLAIN TEXT
|--------------------------------------------------------------------------
*/

if (trim($_GET["download"] ?? "") === "1") {

    $file_name = "Syllabus_" . $file_base . ".txt";

    $file_text = "SYLLABUS" . PHP_EOL
        . str_repeat("=", 60) . PHP_EOL
        . "Exam      : " . (string)$exam["exam_name"] . PHP_EOL
        . "Class     : " . (string)$exam["class_name"] . " - " . (string)$exam["section_name"] . PHP_EOL
        . "Subject   : " . (string)$exam["subject_name"] . PHP_EOL
        . "Session   : " . ((trim((string)$exam["year_name"]) !== "") ? (string)$exam["year_name"] : (string)$exam["academic_year_uid"]) . PHP_EOL
        . "Term      : " . ((trim((string)$exam["term"]) !== "") ? (string)$exam["term"] : "-") . PHP_EOL
        . "Date      : " . examsDateLabel($exam["exam_date"]) . PHP_EOL
        . "Time      : " . examsTimeLabel($exam["start_time"]) . PHP_EOL
        . "Duration  : " . examsDurationLabel($exam["duration_minutes"]) . PHP_EOL
        . "Max Marks : " . examsNumberLabel($exam["max_marks"]) . PHP_EOL
        . "Pass Marks: " . examsNumberLabel($exam["passing_marks"]) . PHP_EOL
        . str_repeat("=", 60) . PHP_EOL
        . PHP_EOL
        . $syllabus_text . PHP_EOL;

    if ($instructions_text !== "") {
        $file_text .= PHP_EOL
            . str_repeat("-", 60) . PHP_EOL
            . "INSTRUCTIONS" . PHP_EOL
            . str_repeat("-", 60) . PHP_EOL
            . $instructions_text . PHP_EOL;
    }

    $file_text .= PHP_EOL . "Teacher: " . $teacher_name . PHP_EOL;

    header("Content-Type: text/plain; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"" . $file_name . "\"");
    header("Content-Length: " . strlen($file_text));
    header("Cache-Control: no-store");

    echo $file_text;
    exit();
}

$enter_marks_url = "../marks/index.php?action=load"
    . "&academic_year_uid=" . urlencode((string)$exam["academic_year_uid"])
    . "&class_uid=" . urlencode((string)$exam["class_uid"])
    . "&section_uid=" . urlencode((string)$exam["section_uid"])
    . "&subject_uid=" . urlencode((string)$exam["subject_uid"])
    . "&exam_uid=" . urlencode((string)$exam["exam_uid"])
    . "&term=" . urlencode((string)$exam["term"])
    . "&max_marks=" . urlencode(examsNumberLabel($exam["max_marks"]));

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Syllabus</title>

<style>
<?= examsModuleStyles() ?>
</style>

</head>

<body>

<div class="container">

    <div class="card">

        <a class="back-link" href="view.php?uid=<?= urlencode((string)$exam["schedule_uid"]) ?>">
            &larr; Back to Exam Details
        </a>

        <h1>Syllabus / Topics</h1>

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
                <span>Session / Term</span>
                <strong>
                    <?= htmlspecialchars(
                        (trim((string)$exam["year_name"]) !== "")
                            ? (string)$exam["year_name"]
                            : (string)$exam["academic_year_uid"]
                    ) ?>
                    <?php if (trim((string)$exam["term"]) !== ""): ?>
                        - <?= htmlspecialchars((string)$exam["term"]) ?>
                    <?php endif; ?>
                </strong>
            </div>

            <div class="detail-item">
                <span>Exam Date &amp; Time</span>
                <strong>
                    <?= htmlspecialchars(examsDateLabel($exam["exam_date"])) ?>
                    &middot;
                    <?= htmlspecialchars(examsTimeLabel($exam["start_time"])) ?>
                </strong>
            </div>

            <div class="detail-item">
                <span>Maximum Marks</span>
                <strong><?= htmlspecialchars(examsNumberLabel($exam["max_marks"])) ?></strong>
            </div>

        </div>

        <div class="detail-block">

            <h2>Syllabus</h2>

            <pre class="prewrap"><?= htmlspecialchars($syllabus_text) ?></pre>

        </div>

        <?php if ($instructions_text !== ""): ?>

            <div class="detail-block">

                <h2>Instructions</h2>

                <pre class="prewrap"><?= htmlspecialchars($instructions_text) ?></pre>

            </div>

        <?php endif; ?>

        <div class="toolbar no-print">

            <a class="btn" href="<?= htmlspecialchars($download_url) ?>">
                ⬇️ Download Syllabus (.txt)
            </a>

            <button type="button" class="btn btn-secondary" onclick="window.print();">
                🖨️ Print
            </button>

            <a class="btn btn-outline" href="view.php?uid=<?= urlencode((string)$exam["schedule_uid"]) ?>">
                👁️ Back to Exam Details
            </a>

            <a class="btn btn-green" href="<?= htmlspecialchars($enter_marks_url) ?>">
                📝 Enter Marks
            </a>

        </div>

    </div>

</div>

</body>

</html>

