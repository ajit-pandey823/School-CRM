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

<?php
$UI_ROOT         = '../';
$UI_ACTIVE       = 'students';
$UI_TITLE        = 'My Students';
$UI_CRUMBS       = [['label' => 'My Students', 'url' => 'index.php']];
$UI_PROFILE_NAME = $teacher_name;
include __DIR__ . '/../includes/header.php';
?>

<div class="card mb-4">
    <div class="card-header">
        <strong>My Students</strong>
    </div>
    <div class="card-body">

        <?php if ($teacher_uid === ""): ?>

            <div class="alert alert-danger" role="alert">
                Teacher profile not found.
            </div>

        <?php else: ?>

            <p class="text-body-secondary mb-1">
                Teacher:
                <strong><?= htmlspecialchars($teacher_name) ?></strong>
                &nbsp;&nbsp;|&nbsp;&nbsp;
                Total Students:
                <strong><?= count($students) ?></strong>
            </p>

            <p class="text-body-secondary">
                Students studying in your assigned classes and sections.
            </p>

        <?php endif; ?>


        <?php if (count($students) === 0): ?>

            <div class="alert alert-warning mb-0" role="alert">
                No active students were found in your assigned classes.
            </div>

        <?php else: ?>

            <!-- SEARCH & FILTERS -->
            <div class="row g-2 align-items-end mb-3">

                <div class="col-12 col-md">
                    <label class="form-label small mb-1" for="searchInput">
                        Search
                    </label>
                    <input
                        type="text"
                        id="searchInput"
                        class="form-control"
                        placeholder="Search Student..."
                        autocomplete="off"
                    >
                </div>

                <div class="col-6 col-md-auto">
                    <label class="form-label small mb-1" for="classFilter">
                        Class
                    </label>
                    <select id="classFilter" class="form-select">
                        <option value="">All Classes</option>
                        <?php foreach ($class_options as $class_option): ?>
                            <option value="<?= htmlspecialchars($class_option, ENT_QUOTES) ?>">
                                <?= htmlspecialchars($class_option) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-6 col-md-auto">
                    <label class="form-label small mb-1" for="sectionFilter">
                        Section
                    </label>
                    <select id="sectionFilter" class="form-select">
                        <option value="">All Sections</option>
                        <?php foreach ($section_options as $section_option => $section_classes): ?>
                            <option
                                value="<?= htmlspecialchars($section_option, ENT_QUOTES) ?>"
                                data-classes="<?= htmlspecialchars(implode('|', $section_classes), ENT_QUOTES) ?>"
                            >
                                <?= htmlspecialchars($section_option) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-6 col-md-auto">
                    <label class="form-label small mb-1" for="statusFilter">
                        Status
                    </label>
                    <select id="statusFilter" class="form-select">
                        <option value="">All Status</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>

                <div class="col-12">
                    <p class="text-body-secondary small mb-0" id="resultLine">
                        Showing
                        <?= count($students) ?>
                        of
                        <?= count($students) ?>
                        students
                    </p>
                </div>

            </div>


        <div class="row g-3">

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

                $badge_map = [
                    "active"   => "bg-success",
                    "inactive" => "bg-danger",
                    "other"    => "bg-warning text-dark"
                ];

                $badge_class = $badge_map[
                    studentStatusBadge($row["student_status"])
                ] ?? "bg-secondary";

                ?>

                <div
                    class="col-12 col-md-6 col-xl-4 student-card"
                    data-name="<?php echo htmlspecialchars($student_name, ENT_QUOTES); ?>"
                    data-admission="<?php echo htmlspecialchars($row['admission_no'], ENT_QUOTES); ?>"
                    data-roll="<?php echo htmlspecialchars($row['roll_no'] ?? '', ENT_QUOTES); ?>"
                    data-class="<?php echo htmlspecialchars($row['class_name'], ENT_QUOTES); ?>"
                    data-section="<?php echo htmlspecialchars($row['section_name'], ENT_QUOTES); ?>"
                    data-status="<?php echo htmlspecialchars($row['student_status'], ENT_QUOTES); ?>"
                >

                    <div class="card h-100">
                        <div class="card-body">

                            <div class="d-flex align-items-center gap-3 pb-3 mb-3 border-bottom">

                                <div
                                    class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-bold flex-shrink-0"
                                    style="width:48px;height:48px;font-size:18px;"
                                >
                                    <?php echo htmlspecialchars($initial); ?>
                                </div>

                                <div>

                                    <h5 class="mb-1">
                                        <?php echo htmlspecialchars($student_name); ?>
                                    </h5>

                                    <span class="badge <?= $badge_class ?>">
                                        <?php echo htmlspecialchars(ucfirst($row["student_status"])); ?>
                                    </span>

                                </div>

                            </div>

                        <div class="row g-2">

                            <div class="col-6">
                                <div class="text-body-secondary small mb-1">Student ID / Admission No.</div>
                                <strong><?php echo htmlspecialchars($row["admission_no"]); ?></strong>
                            </div>

                            <div class="col-6">
                                <div class="text-body-secondary small mb-1">Roll No.</div>
                                <strong><?php echo htmlspecialchars($row["roll_no"] ?? "-"); ?></strong>
                            </div>

                            <div class="col-6">
                                <div class="text-body-secondary small mb-1">Class</div>
                                <strong><?php echo htmlspecialchars($row["class_name"]); ?></strong>
                            </div>

                            <div class="col-6">
                                <div class="text-body-secondary small mb-1">Section</div>
                                <strong><?php echo htmlspecialchars($row["section_name"]); ?></strong>
                            </div>

                            <div class="col-6">
                                <div class="text-body-secondary small mb-1">Gender</div>
                                <strong><?php echo htmlspecialchars(ucfirst($row["gender"] ?? "-")); ?></strong>
                            </div>

                            <div class="col-6">
                                <div class="text-body-secondary small mb-1">Date of Birth</div>
                                <strong><?php echo htmlspecialchars($dob_text); ?></strong>
                            </div>

                            <div class="col-6">
                                <div class="text-body-secondary small mb-1">Parent / Guardian Name</div>
                                <strong><?php echo htmlspecialchars(studentGuardianName($row)); ?></strong>
                            </div>

                            <div class="col-6">
                                <div class="text-body-secondary small mb-1">Contact Number</div>
                                <strong><?php echo htmlspecialchars(studentContactNumber($row)); ?></strong>
                            </div>

                        </div>


                            <a
                                class="btn btn-primary btn-sm mt-3"
                                href="view.php?student=<?php echo urlencode($row['student_uid']); ?>"
                            >
                                View Profile
                            </a>

                        </div>
                    </div>

                </div>

            <?php endforeach; ?>

        </div>


        <div class="alert alert-info mb-0 mt-3" id="noMatch" style="display:none" role="alert">

            <strong>No Students Found</strong>

            <div class="mt-1">
                No students match your search or selected filters.
            </div>

        </div>

    <?php endif; ?>

    </div>
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

<?php include __DIR__ . '/../includes/footer.php'; ?>



