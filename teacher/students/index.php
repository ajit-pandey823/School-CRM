<?php

session_start();

require_once __DIR__ . "/../../config/db.php";

// =====================================================
// LOGIN CHECK
// =====================================================

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit();
}

// =====================================================
// GET TEACHER UID FROM LOGGED-IN USER
// =====================================================
//
// teachers.user_uid VARCHAR column hai (jaise "USR00000003"),
// isliye session ka numeric user_id nahi,
// session ka user_uid use karna hai.
//

$user_uid = $_SESSION["user_uid"] ?? "";

$teacher_uid = "";
$teacher_name = "";

$sql_teacher = "
    SELECT teacher_uid, teacher_name
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
}

$stmt_teacher->close();


// =====================================================
// GET STUDENTS OF TEACHER'S ASSIGNED CLASSES
// =====================================================
//
// Sirf unhi students ko dikhana hai jo isi teacher ki
// assigned class + section + academic year me hain.
//

$students = [];

if ($teacher_uid !== "") {

    $sql = "
        SELECT DISTINCT
            s.student_uid,
            s.admission_no,
            s.student_name,
            s.gender,
            s.dob,
            s.father_name,
            s.mother_name,
            s.father_mobile,
            s.mother_mobile,
            s.status AS student_status,
            sa.roll_no,
            c.class_name,
            sec.section_name
        FROM teacher_subjects ts

        INNER JOIN student_academic sa
            ON sa.school_uid = ts.school_uid
            AND sa.class_uid = ts.class_uid
            AND sa.section_uid = ts.section_uid
            AND sa.academic_year_uid = ts.academic_year_uid

        INNER JOIN students s
            ON s.student_uid = sa.student_uid

        INNER JOIN classes c
            ON c.class_uid = sa.class_uid

        INNER JOIN sections sec
            ON sec.section_uid = sa.section_uid

        WHERE ts.teacher_uid = ?
        AND sa.status = 'active'
        AND s.status IN ('active', 'inactive')
        AND c.status = 'active'
        AND sec.status = 'active'

        ORDER BY
            c.numeric_order ASC,
            sec.section_name ASC,
            CAST(sa.roll_no AS UNSIGNED) ASC,
            s.student_name ASC
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("Students query error: " . $conn->error);
    }

    $stmt->bind_param("s", $teacher_uid);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }

    $stmt->close();
}


// =====================================================
// HELPER FUNCTIONS
// =====================================================

function studentGuardianName($row)
{
    $guardian = trim($row["father_name"] ?? "");

    if ($guardian === "") {
        $guardian = trim($row["mother_name"] ?? "");
    }

    return $guardian !== "" ? $guardian : "-";
}

function studentContactNumber($row)
{
    $contact = trim($row["father_mobile"] ?? "");

    if ($contact === "") {
        $contact = trim($row["mother_mobile"] ?? "");
    }

    return $contact !== "" ? $contact : "-";
}

function studentStatusBadge($status)
{
    if ($status === "active") {
        return "active";
    }

    if ($status === "inactive") {
        return "inactive";
    }

    return "other";
}


// =====================================================
// SEARCH & FILTER OPTIONS
// =====================================================

$class_options = [];
$section_options = [];

foreach ($students as $row) {

    $class_name = $row["class_name"];
    $section_name = $row["section_name"];

    if (!in_array($class_name, $class_options, true)) {
        $class_options[] = $class_name;
    }

    if (!isset($section_options[$section_name])) {
        $section_options[$section_name] = [];
    }

    if (!in_array($class_name, $section_options[$section_name], true)) {
        $section_options[$section_name][] = $class_name;
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Students</title>

    <style>

        * {
            box-sizing: border-box;
        }

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

        .header h1 {
            margin: 0;
            font-size: 26px;
        }

        .container {
            padding: 25px;
        }

        .back {
            display: inline-block;
            margin-bottom: 20px;
            text-decoration: none;
            color: #2563eb;
            font-weight: 600;
        }

        .title-box {
            background: #ffffff;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
        }

        .title-box h2 {
            margin: 0 0 8px;
        }

        .title-box p {
            margin: 6px 0 0;
            color: #6b7280;
        }

        .title-box .teacher-line strong {
            color: #1f2937;
        }

        .warn {
            color: #b91c1c !important;
            font-weight: 600;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 20px;
        }

        .student-card {
            background: #ffffff;
            padding: 22px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .card-head {
            display: flex;
            align-items: center;
            gap: 14px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 15px;
        }

        .avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #2563eb;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: bold;
            flex-shrink: 0;
        }

        .card-head h3 {
            margin: 0 0 6px;
            font-size: 18px;
        }

        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge.active {
            background: #dcfce7;
            color: #15803d;
        }

        .badge.inactive {
            background: #fee2e2;
            color: #b91c1c;
        }

        .badge.other {
            background: #fef3c7;
            color: #b45309;
        }

        .fields {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .field label {
            display: block;
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 3px;
        }

        .field strong {
            font-size: 14px;
            color: #111827;
            word-break: break-word;
        }

        .view-link {
            display: inline-block;
            margin-top: 16px;
            color: #2563eb;
            font-weight: 600;
            text-decoration: none;
        }

        .view-link:hover {
            text-decoration: underline;
        }

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
            min-width: 150px;
            outline: none;
            cursor: pointer;
        }

        .filter-bar input[type="text"]:focus,
        .filter-bar select:focus {
            border-color: #2563eb;
        }

        .result-line {
            flex-basis: 100%;
            margin: 0;
            color: #6b7280;
            font-size: 13px;
        }

        .no-match {
            display: none;
            background: #ffffff;
            padding: 40px;
            border-radius: 12px;
            text-align: center;
            color: #6b7280;
        }

        .no-match h3 {
            margin: 0 0 8px;
            color: #4b5563;
        }

        .empty {
            background: #ffffff;
            padding: 40px;
            border-radius: 12px;
            text-align: center;
            color: #6b7280;
        }

        .empty h3 {
            margin: 0 0 8px;
            color: #4b5563;
        }

    </style>

</head>

<body>

<div class="header">

    <h1>My Students</h1>

</div>

<div class="container">

    <a href="../dashboard.php" class="back">
        ← Back to Dashboard
    </a>


    <div class="title-box">

        <h2>Students List</h2>

        <p>
            Students studying in your assigned classes and sections.
        </p>

        <?php if ($teacher_uid === ""): ?>

            <p class="warn">
                Teacher profile not found.
            </p>

        <?php else: ?>

            <p class="teacher-line">
                Teacher:
                <strong><?php echo htmlspecialchars($teacher_name); ?></strong>
                &nbsp;&nbsp;|&nbsp;&nbsp;
                Total Students:
                <strong><?php echo count($students); ?></strong>
            </p>

        <?php endif; ?>

    </div>


    <?php if (count($students) === 0): ?>

        <div class="empty">

            <h3>No Students Found</h3>

            <p>
                No active students were found
                in your assigned classes.
            </p>

        </div>

    <?php else: ?>

        <div class="filter-bar">

            <input
                type="text"
                id="searchInput"
                placeholder="Search Student..."
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

                <?php foreach ($section_options as $section_option => $section_classes): ?>

                    <option
                        value="<?php echo htmlspecialchars($section_option, ENT_QUOTES); ?>"
                        data-classes="<?php echo htmlspecialchars(implode('|', $section_classes), ENT_QUOTES); ?>"
                    >
                        <?php echo htmlspecialchars($section_option); ?>
                    </option>

                <?php endforeach; ?>

            </select>

            <select id="statusFilter">

                <option value="">All Status</option>

                <option value="active">Active</option>

                <option value="inactive">Inactive</option>

            </select>

            <p class="result-line" id="resultLine">
                Showing
                <?php echo count($students); ?>
                of
                <?php echo count($students); ?>
                students
            </p>

        </div>


        <div class="grid">

            <?php foreach ($students as $row): ?>

                <?php

                $student_name = trim($row["student_name"]);

                $initial = strtoupper(substr($student_name, 0, 1));

                $dob_text = "-";

                if (!empty($row["dob"])) {
                    $dob_text = date(
                        "d M Y",
                        strtotime($row["dob"])
                    );
                }

                ?>

                <div
                    class="student-card"
                    data-name="<?php echo htmlspecialchars($student_name, ENT_QUOTES); ?>"
                    data-admission="<?php echo htmlspecialchars($row['admission_no'], ENT_QUOTES); ?>"
                    data-roll="<?php echo htmlspecialchars($row['roll_no'] ?? '', ENT_QUOTES); ?>"
                    data-class="<?php echo htmlspecialchars($row['class_name'], ENT_QUOTES); ?>"
                    data-section="<?php echo htmlspecialchars($row['section_name'], ENT_QUOTES); ?>"
                    data-status="<?php echo htmlspecialchars($row['student_status'], ENT_QUOTES); ?>"
                >

                    <div class="card-head">

                        <div class="avatar">
                            <?php echo htmlspecialchars($initial); ?>
                        </div>

                        <div>

                            <h3>
                                <?php echo htmlspecialchars($student_name); ?>
                            </h3>

                            <span class="badge <?php echo studentStatusBadge($row['student_status']); ?>">
                                <?php echo htmlspecialchars(ucfirst($row["student_status"])); ?>
                            </span>

                        </div>

                    </div>

                    <div class="fields">

                        <div class="field">

                            <label>Student ID / Admission No.</label>

                            <strong>
                                <?php echo htmlspecialchars($row["admission_no"]); ?>
                            </strong>

                        </div>


                        <div class="field">

                            <label>Roll No.</label>

                            <strong>
                                <?php echo htmlspecialchars($row["roll_no"] ?? "-"); ?>
                            </strong>

                        </div>


                        <div class="field">

                            <label>Class</label>

                            <strong>
                                <?php echo htmlspecialchars($row["class_name"]); ?>
                            </strong>

                        </div>


                        <div class="field">

                            <label>Section</label>

                            <strong>
                                <?php echo htmlspecialchars($row["section_name"]); ?>
                            </strong>

                        </div>


                        <div class="field">

                            <label>Gender</label>

                            <strong>
                                <?php echo htmlspecialchars(ucfirst($row["gender"] ?? "-")); ?>
                            </strong>

                        </div>


                        <div class="field">

                            <label>Date of Birth</label>

                            <strong>
                                <?php echo htmlspecialchars($dob_text); ?>
                            </strong>

                        </div>


                        <div class="field">

                            <label>Parent / Guardian Name</label>

                            <strong>
                                <?php echo htmlspecialchars(studentGuardianName($row)); ?>
                            </strong>

                        </div>


                        <div class="field">

                            <label>Contact Number</label>

                            <strong>
                                <?php echo htmlspecialchars(studentContactNumber($row)); ?>
                            </strong>

                        </div>

                    </div>


                    <a
                        class="view-link"
                        href="view.php?student=<?php echo urlencode($row['student_uid']); ?>"
                    >
                        View Profile →
                    </a>

                </div>

            <?php endforeach; ?>

        </div>


        <div class="no-match" id="noMatch">

            <h3>No Students Found</h3>

            <p>
                No students match your search
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
    const statusFilter = document.getElementById("statusFilter");
    const resultLine = document.getElementById("resultLine");
    const noMatch = document.getElementById("noMatch");

    const cards = Array.prototype.slice.call(
        document.querySelectorAll(".student-card")
    );

    const total = cards.length;

    // -------------------------------------------------
    // SECTION OPTIONS DEPEND ON SELECTED CLASS
    // -------------------------------------------------

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

    // -------------------------------------------------
    // APPLY SEARCH + ALL FILTERS
    // -------------------------------------------------

    function applyFilters() {

        const query = searchInput.value.trim().toLowerCase();
        const selectedClass = classFilter.value;
        const selectedSection = sectionFilter.value;
        const selectedStatus = statusFilter.value;

        let visible = 0;

        for (let i = 0; i < cards.length; i++) {

            const card = cards[i];

            const text = (
                card.getAttribute("data-name") + " " +
                card.getAttribute("data-admission") + " " +
                card.getAttribute("data-roll")
            ).toLowerCase();

            const matches =
                (query === "" || text.indexOf(query) !== -1) &&
                (
                    selectedClass === "" ||
                    card.getAttribute("data-class") === selectedClass
                ) &&
                (
                    selectedSection === "" ||
                    card.getAttribute("data-section") === selectedSection
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
            "Showing " + visible + " of " + total + " students";

        noMatch.style.display = visible === 0 ? "block" : "none";
    }

    // -------------------------------------------------
    // EVENTS
    // -------------------------------------------------

    searchInput.addEventListener("input", applyFilters);

    classFilter.addEventListener("change", function () {
        updateSectionOptions();
        applyFilters();
    });

    sectionFilter.addEventListener("change", applyFilters);

    statusFilter.addEventListener("change", applyFilters);

    // -------------------------------------------------
    // APPLY ON PAGE LOAD
    // -------------------------------------------------

    applyFilters();

})();

</script>

</body>

</html>



