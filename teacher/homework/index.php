<?php

session_start();

require_once __DIR__ . "/../../config/db.php";

// LOGIN CHECK
if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit();
}

// GET TEACHER UID FROM LOGGED-IN USER
$user_uid = $_SESSION["user_uid"] ?? "";

$teacher_uid = "";
$teacher_name = "";
$school_uid = "";

$sql_teacher = "
    SELECT teacher_uid, teacher_name, school_uid
    FROM teachers
    WHERE user_uid = ?
    AND status = 'active'
    LIMIT 1
";

$stmt_teacher = $conn->prepare($sql_teacher);

if (!$stmt_teacher) {
    die("Teacher query error: " . $conn->error);
}

$stmt_teacher->bind_param("s", $user_uid);
$stmt_teacher->execute();

$result_teacher = $stmt_teacher->get_result();

if ($result_teacher->num_rows > 0) {
    $teacher = $result_teacher->fetch_assoc();
    $teacher_uid = $teacher["teacher_uid"];
    $teacher_name = $teacher["teacher_name"];
    $school_uid = $teacher["school_uid"];
}

$stmt_teacher->close();

if ($teacher_uid === "") {
    die("Teacher profile not found.");
}

// TEACHER ASSIGNMENTS (FOR FILTER OPTIONS)
$sql = "
    SELECT
        ts.class_uid,
        ts.section_uid,
        ts.subject_uid,
        ts.academic_year_uid,
        c.class_name,
        sec.section_name,
        sub.subject_name
    FROM teacher_subjects ts

    INNER JOIN classes c
        ON c.class_uid = ts.class_uid
        AND c.status = 'active'

    INNER JOIN sections sec
        ON sec.section_uid = ts.section_uid
        AND sec.status = 'active'

    INNER JOIN subjects sub
        ON sub.subject_uid = ts.subject_uid
        AND sub.status = 'active'

    WHERE ts.teacher_uid = ?

    ORDER BY
        c.numeric_order ASC,
        sec.section_name ASC,
        sub.subject_name ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $teacher_uid);
$stmt->execute();
$assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// HOMEWORK LIST + COUNTS
$homeworks = [];

$sql = "
    SELECT
        h.*,
        c.class_name,
        sec.section_name,
        sub.subject_name,
        (
            SELECT COUNT(*)
            FROM student_academic sa
            WHERE sa.class_uid = h.class_uid
            AND sa.section_uid = h.section_uid
            AND sa.academic_year_uid = h.academic_year_uid
            AND sa.status = 'active'
        ) AS total_students,
        (
            SELECT COUNT(*)
            FROM homework_submissions hs
            WHERE hs.homework_uid = h.homework_uid
            AND hs.status = 'submitted'
        ) AS submitted_count,
        (
            SELECT COUNT(*)
            FROM homework_submissions hs
            WHERE hs.homework_uid = h.homework_uid
            AND hs.status = 'checked'
        ) AS checked_count
    FROM homework h

    INNER JOIN classes c
        ON c.class_uid = h.class_uid

    INNER JOIN sections sec
        ON sec.section_uid = h.section_uid

    INNER JOIN subjects sub
        ON sub.subject_uid = h.subject_uid

    WHERE h.teacher_uid = ?

    ORDER BY h.assigned_date DESC, h.id DESC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Homework query error: " . $conn->error);
}

$stmt->bind_param("s", $teacher_uid);
$stmt->execute();
$homeworks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// HELPERS + STATS + FILTER OPTIONS
function homeworkState($status, $due_date)
{
    if ($status !== "active") {
        return "inactive";
    }

    if (strtotime($due_date) < strtotime(date("Y-m-d"))) {
        return "closed";
    }

    return "open";
}

function homeworkStateLabel($state)
{
    if ($state === "open") {
        return "Open";
    }

    if ($state === "closed") {
        return "Closed";
    }

    return "Inactive";
}

$stats = [
    "total" => count($homeworks),
    "open" => 0,
    "closed" => 0,
    "inactive" => 0,
    "to_check" => 0,
];

$class_options = [];
$section_options = [];
$subject_options = [];

foreach ($homeworks as $hw) {

    $state = homeworkState($hw["status"], $hw["due_date"]);

    $stats[$state]++;
    $stats["to_check"] += (int) $hw["submitted_count"];

    if (!in_array($hw["class_name"], $class_options, true)) {
        $class_options[] = $hw["class_name"];
    }

    if (!isset($section_options[$hw["section_name"]])) {
        $section_options[$hw["section_name"]] = [];
    }

    if (!in_array($hw["class_name"], $section_options[$hw["section_name"]], true)) {
        $section_options[$hw["section_name"]][] = $hw["class_name"];
    }

    if (!in_array($hw["subject_name"], $subject_options, true)) {
        $subject_options[] = $hw["subject_name"];
    }
}

$created_banner = isset($_GET["created"]);

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Homework</title>

    <style>

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f5f7fb;
            color: #1f2937;
        }

        .header {
            background: #ffffff;
            padding: 20px 25px;
            border-bottom: 1px solid #e5e7eb;
        }

        .header h1 { margin: 0; font-size: 26px; }

        .container { padding: 25px; }

        .back {
            display: inline-block;
            margin-bottom: 20px;
            text-decoration: none;
            color: #2563eb;
            font-weight: 600;
        }

        .title-box {
            background: #ffffff;
            padding: 22px 25px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .title-box h2 { margin: 0 0 6px; }
        .title-box p { margin: 0; color: #6b7280; }

        .btn-add {
            display: inline-block;
            padding: 12px 22px;
            background: #2563eb;
            color: #ffffff;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
        }

        .btn-add:hover { background: #1d4ed8; }

        .banner {
            background: #dcfce7;
            color: #15803d;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-weight: 600;
        }

        .error-box {
            background: #fee2e2;
            color: #b91c1c;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-weight: 600;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat {
            background: #ffffff;
            padding: 18px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .stat label { color: #6b7280; font-size: 13px; }
        .stat .num { font-size: 26px; font-weight: bold; margin-top: 6px; }

        .filter-bar {
            background: #ffffff;
            padding: 18px 22px;
            border-radius: 12px;
            margin-bottom: 18px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .filter-bar input[type="text"] {
            flex: 1 1 250px;
            min-width: 200px;
            padding: 11px 14px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
        }

        .filter-bar select {
            padding: 11px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            background: #ffffff;
            min-width: 145px;
            outline: none;
            cursor: pointer;
        }

        .filter-bar input:focus,
        .filter-bar select:focus { border-color: #2563eb; }

        .result-line {
            flex-basis: 100%;
            margin: 0;
            color: #6b7280;
            font-size: 13px;
        }

        .hw-card {
            background: #ffffff;
            padding: 22px;
            border-radius: 12px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .hw-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            flex-wrap: wrap;
        }

        .hw-head h3 { margin: 0; font-size: 18px; color: #111827; }

        .badges { display: flex; gap: 8px; flex-wrap: wrap; }

        .badge {
            display: inline-block;
            padding: 4px 11px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge.open { background: #dcfce7; color: #15803d; }
        .badge.closed { background: #e5e7eb; color: #4b5563; }
        .badge.inactive { background: #fee2e2; color: #b91c1c; }
        .badge.subject { background: #e7f1ff; color: #2563eb; }

        .hw-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            color: #6b7280;
            font-size: 14px;
            margin-top: 12px;
        }

        .chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }

        .chip {
            background: #f3f4f6;
            border-radius: 6px;
            padding: 6px 12px;
            font-size: 13px;
            color: #374151;
        }

        .hw-actions {
            margin-top: 14px;
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }

        .link { color: #2563eb; font-weight: 600; text-decoration: none; }
        .link:hover { text-decoration: underline; }

        .empty {
            background: #ffffff;
            padding: 40px;
            border-radius: 12px;
            text-align: center;
            color: #6b7280;
            margin-bottom: 16px;
        }

        .empty h3 { margin: 0 0 8px; color: #4b5563; }

        .no-match {
            display: none;
            background: #ffffff;
            padding: 40px;
            border-radius: 12px;
            text-align: center;
            color: #6b7280;
        }

        .no-match h3 { margin: 0 0 8px; color: #4b5563; }

    </style>

</head>

<body>

<div class="header">

    <h1>Homework</h1>

</div>

<div class="container">

    <a href="../dashboard.php" class="back">
        ← Back to Dashboard
    </a>


    <?php if ($created_banner): ?>

        <div class="banner">
            Homework created successfully.
        </div>

    <?php endif; ?>


    <div class="title-box">

        <div>

            <h2>Homework</h2>

            <p>
                Create and manage homework for your assigned classes.
            </p>

            <p style="margin-top:6px;">
                Teacher:
                <strong><?php echo htmlspecialchars($teacher_name); ?></strong>
            </p>

        </div>

        <a href="create.php" class="btn-add">
            + Add Homework
        </a>

    </div>


    <div class="stats">

        <div class="stat">
            <label>Total Homework</label>
            <div class="num"><?php echo $stats["total"]; ?></div>
        </div>

        <div class="stat">
            <label>Open</label>
            <div class="num"><?php echo $stats["open"]; ?></div>
        </div>

        <div class="stat">
            <label>Closed</label>
            <div class="num"><?php echo $stats["closed"]; ?></div>
        </div>

        <div class="stat">
            <label>To Check</label>
            <div class="num"><?php echo $stats["to_check"]; ?></div>
        </div>

    </div>


    <?php if (count($homeworks) === 0): ?>

        <div class="empty">

            <h3>No Homework Yet</h3>

            <p>
                You have not created any homework
                for your classes.
            </p>

        </div>

    <?php else: ?>

        <div class="filter-bar">

            <input
                type="text"
                id="searchInput"
                placeholder="Search Homework..."
                autocomplete="off"
            >

            <select id="classFilter">

                <option value="">All Classes</option>

                <?php foreach ($class_options as $class_option): ?>

                    <option value="<?php echo htmlspecialchars($class_option, ENT_QUOTES); ?>">
                        <?php echo htmlspecialchars($class_option); ?>
                    </option>

                <?php endforeach; ?>

            </select>

            <select id="sectionFilter">

                <option value="">All Sections</option>

                <?php foreach ($section_options as $section_name_opt => $section_classes): ?>

                    <option
                        value="<?php echo htmlspecialchars($section_name_opt, ENT_QUOTES); ?>"
                        data-classes="<?php echo htmlspecialchars(implode('|', $section_classes), ENT_QUOTES); ?>"
                    >
                        <?php echo htmlspecialchars($section_name_opt); ?>
                    </option>

                <?php endforeach; ?>

            </select>

            <select id="subjectFilter">

                <option value="">All Subjects</option>

                <?php foreach ($subject_options as $subject_option): ?>

                    <option value="<?php echo htmlspecialchars($subject_option, ENT_QUOTES); ?>">
                        <?php echo htmlspecialchars($subject_option); ?>
                    </option>

                <?php endforeach; ?>

            </select>

            <select id="statusFilter">

                <option value="">All Status</option>

                <option value="open">Open</option>

                <option value="closed">Closed</option>

                <option value="inactive">Inactive</option>

            </select>

            <p class="result-line" id="resultLine">
                Showing
                <?php echo count($homeworks); ?>
                of
                <?php echo count($homeworks); ?>
                homework
            </p>

        </div>

        <div id="hwList">

            <?php foreach ($homeworks as $hw): ?>

                <?php

                $hw_state = homeworkState($hw["status"], $hw["due_date"]);

                $pending_students =
                    (int) $hw["total_students"] -
                    (int) $hw["submitted_count"] -
                    (int) $hw["checked_count"];

                if ($pending_students < 0) {
                    $pending_students = 0;
                }

                ?>

                <div
                    class="hw-card"
                    data-title="<?php echo htmlspecialchars($hw['title'], ENT_QUOTES); ?>"
                    data-class="<?php echo htmlspecialchars($hw['class_name'], ENT_QUOTES); ?>"
                    data-section="<?php echo htmlspecialchars($hw['section_name'], ENT_QUOTES); ?>"
                    data-subject="<?php echo htmlspecialchars($hw['subject_name'], ENT_QUOTES); ?>"
                    data-status="<?php echo $hw_state; ?>"
                >

                    <div class="hw-head">

                        <h3>
                            <?php echo htmlspecialchars($hw["title"]); ?>
                        </h3>

                        <div class="badges">

                            <span class="badge <?php echo $hw_state; ?>">
                                <?php echo homeworkStateLabel($hw_state); ?>
                            </span>

                            <span class="badge subject">
                                <?php echo htmlspecialchars($hw["subject_name"]); ?>
                            </span>

                        </div>

                    </div>

                    <div class="hw-meta">

                        <span>
                            Class:
                            <strong><?php echo htmlspecialchars($hw["class_name"]); ?></strong>
                        </span>

                        <span>
                            Section:
                            <strong><?php echo htmlspecialchars($hw["section_name"]); ?></strong>
                        </span>

                        <span>
                            Assigned:
                            <strong><?php echo date("d M Y", strtotime($hw["assigned_date"])); ?></strong>
                        </span>

                        <span>
                            Due:
                            <strong><?php echo date("d M Y", strtotime($hw["due_date"])); ?></strong>
                        </span>

                        <span>
                            Students:
                            <strong><?php echo (int) $hw["total_students"]; ?></strong>
                        </span>

                    </div>

                    <div class="chips">

                        <span class="chip">
                            Submitted: <?php echo (int) $hw["submitted_count"]; ?>
                        </span>

                        <span class="chip">
                            Checked: <?php echo (int) $hw["checked_count"]; ?>
                        </span>

                        <span class="chip">
                            Pending: <?php echo $pending_students; ?>
                        </span>

                    </div>

                    <div class="hw-actions">

                        <a
                            class="link"
                            href="view.php?hw=<?php echo urlencode($hw['homework_uid']); ?>"
                        >
                            View Details →
                        </a>

                        <a
                            class="link"
                            href="submissions.php?hw=<?php echo urlencode($hw['homework_uid']); ?>"
                        >
                            Student Submissions →
                        </a>

                        <a
                            class="link"
                            href="edit.php?hw=<?php echo urlencode($hw['homework_uid']); ?>"
                        >
                            Edit
                        </a>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>


        <div class="no-match" id="noMatch">

            <h3>No Homework Found</h3>

            <p>
                No homework matches your search
                or selected filters.
            </p>

        </div>

    <?php endif; ?>

</div>

<script>

(function () {

    const searchInput = document.getElementById("searchInput");

    if (!searchInput) {
        return;
    }

    const classFilter = document.getElementById("classFilter");
    const sectionFilter = document.getElementById("sectionFilter");
    const subjectFilter = document.getElementById("subjectFilter");
    const statusFilter = document.getElementById("statusFilter");
    const resultLine = document.getElementById("resultLine");
    const noMatch = document.getElementById("noMatch");

    const cards = Array.prototype.slice.call(
        document.querySelectorAll(".hw-card")
    );

    const total = cards.length;

    function updateSectionOptions() {

        const selectedClass = classFilter.value;

        for (let i = 0; i < sectionFilter.options.length; i++) {

            const option = sectionFilter.options[i];

            if (option.value === "") {
                option.hidden = false;
                continue;
            }

            const classes = (
                option.getAttribute("data-classes") || ""
            ).split("|");

            const show =
                selectedClass === "" ||
                classes.indexOf(selectedClass) !== -1;

            option.hidden = !show;
        }

        const selectedOption =
            sectionFilter.options[sectionFilter.selectedIndex];

        if (selectedOption && selectedOption.hidden) {
            sectionFilter.value = "";
        }
    }

    function applyFilters() {

        const query = searchInput.value.trim().toLowerCase();
        const selectedClass = classFilter.value;
        const selectedSection = sectionFilter.value;
        const selectedSubject = subjectFilter.value;
        const selectedStatus = statusFilter.value;

        let visible = 0;

        for (let i = 0; i < cards.length; i++) {

            const card = cards[i];

            const title = (
                card.getAttribute("data-title") || ""
            ).toLowerCase();

            const matches =
                (query === "" || title.indexOf(query) !== -1) &&
                (
                    selectedClass === "" ||
                    card.getAttribute("data-class") === selectedClass
                ) &&
                (
                    selectedSection === "" ||
                    card.getAttribute("data-section") === selectedSection
                ) &&
                (
                    selectedSubject === "" ||
                    card.getAttribute("data-subject") === selectedSubject
                ) &&
                (
                    selectedStatus === "" ||
                    card.getAttribute("data-status") === selectedStatus
                );

            card.style.display = matches ? "" : "none";

            if (matches) {
                visible++;
            }
        }

        resultLine.textContent =
            "Showing " + visible + " of " + total + " homework";

        noMatch.style.display = visible === 0 ? "block" : "none";
    }

    searchInput.addEventListener("input", applyFilters);

    classFilter.addEventListener("change", function () {
        updateSectionOptions();
        applyFilters();
    });

    sectionFilter.addEventListener("change", applyFilters);

    subjectFilter.addEventListener("change", applyFilters);

    statusFilter.addEventListener("change", applyFilters);

    applyFilters();

})();

</script>

</body>

</html>




