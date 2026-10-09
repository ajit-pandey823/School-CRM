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

<?php
$UI_ROOT         = '../';
$UI_ACTIVE       = 'homework';
$UI_TITLE        = 'Homework';
$UI_CRUMBS       = [];
$UI_PROFILE_NAME = $teacher_name;
include __DIR__ . '/../includes/header.php';
?>

<?php if ($created_banner): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        Homework created successfully.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <strong>Homework</strong>
        <a href="create.php" class="btn btn-primary btn-sm">+ Add Homework</a>
    </div>
    <div class="card-body">
        <p class="text-body-secondary mb-1">
            Create and manage homework for your assigned classes.
        </p>
        <p class="text-body-secondary mb-0">
            Teacher:
            <strong><?php echo htmlspecialchars($teacher_name); ?></strong>
        </p>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-body-secondary small text-uppercase">Total Homework</div>
                <div class="fs-4 fw-semibold"><?php echo $stats["total"]; ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-body-secondary small text-uppercase">Open</div>
                <div class="fs-4 fw-semibold text-success"><?php echo $stats["open"]; ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-body-secondary small text-uppercase">Closed</div>
                <div class="fs-4 fw-semibold"><?php echo $stats["closed"]; ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-body-secondary small text-uppercase">To Check</div>
                <div class="fs-4 fw-semibold text-primary"><?php echo $stats["to_check"]; ?></div>
            </div>
        </div>
    </div>
</div>

<?php if (count($homeworks) === 0): ?>

    <div class="alert alert-warning" role="alert">
        <strong>No Homework Yet</strong><br>
        You have not created any homework for your classes.
    </div>

<?php else: ?>
    <div class="card mb-4">
        <div class="card-header"><strong>Filter Homework</strong></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-md-3">
                    <label class="form-label" for="searchInput">Search</label>
                    <input
                        type="search"
                        class="form-control"
                        id="searchInput"
                        placeholder="Search homework..."
                        autocomplete="off"
                    >
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label" for="classFilter">Class</label>
                    <select class="form-select" id="classFilter">
                        <option value="">All Classes</option>
                        <?php foreach ($class_options as $class_option): ?>
                            <option value="<?php echo htmlspecialchars($class_option, ENT_QUOTES); ?>">
                                <?php echo htmlspecialchars($class_option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label" for="sectionFilter">Section</label>
                    <select class="form-select" id="sectionFilter">
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
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label" for="subjectFilter">Subject</label>
                    <select class="form-select" id="subjectFilter">
                        <option value="">All Subjects</option>
                        <?php foreach ($subject_options as $subject_option): ?>
                            <option value="<?php echo htmlspecialchars($subject_option, ENT_QUOTES); ?>">
                                <?php echo htmlspecialchars($subject_option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label" for="statusFilter">Status</label>
                    <select class="form-select" id="statusFilter">
                        <option value="">All Statuses</option>
                        <option value="open">Open</option>
                        <option value="closed">Closed</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <p class="text-body-secondary small mb-0 mt-3" id="resultLine"></p>
        </div>
    </div>

    <div class="row g-4">
        <?php foreach ($homeworks as $hw):

            $hw_state = homeworkState($hw["status"], $hw["due_date"]);

            $pending_students =
                (int) $hw["total_students"]
                - (int) $hw["submitted_count"]
                - (int) $hw["checked_count"];

            if ($pending_students < 0) {
                $pending_students = 0;
            }

            $hw_badge = "bg-secondary";

            if ($hw_state === "open") {
                $hw_badge = "bg-success";
            } elseif ($hw_state === "closed") {
                $hw_badge = "bg-primary";
            }

        ?>
            <div
                class="col-12 col-xl-6 hw-card"
                data-title="<?php echo htmlspecialchars($hw["title"], ENT_QUOTES); ?>"
                data-class="<?php echo htmlspecialchars($hw["class_name"], ENT_QUOTES); ?>"
                data-section="<?php echo htmlspecialchars($hw["section_name"], ENT_QUOTES); ?>"
                data-subject="<?php echo htmlspecialchars($hw["subject_name"], ENT_QUOTES); ?>"
                data-status="<?php echo htmlspecialchars($hw_state, ENT_QUOTES); ?>"
            >
                <div class="card h-100">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <strong class="text-truncate" title="<?php echo htmlspecialchars($hw["title"], ENT_QUOTES); ?>">
                            <?php echo htmlspecialchars($hw["title"]); ?>
                        </strong>
                        <span class="badge <?php echo $hw_badge; ?>">
                            <?php echo homeworkStateLabel($hw_state); ?>
                        </span>
                    </div>
                    <div class="card-body">

                        <div class="mb-3">
                            <span class="badge bg-light text-dark border">
                                <?php echo htmlspecialchars($hw["subject_name"]); ?>
                            </span>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6 col-md-4">
                                <div class="text-body-secondary small">Class</div>
                                <strong><?php echo htmlspecialchars($hw["class_name"]); ?></strong>
                            </div>
                            <div class="col-6 col-md-4">
                                <div class="text-body-secondary small">Section</div>
                                <strong><?php echo htmlspecialchars($hw["section_name"]); ?></strong>
                            </div>
                            <div class="col-6 col-md-4">
                                <div class="text-body-secondary small">Students</div>
                                <strong><?php echo (int) $hw["total_students"]; ?></strong>
                            </div>
                            <div class="col-6 col-md-4">
                                <div class="text-body-secondary small">Assigned</div>
                                <strong><?php echo date("d M Y", strtotime($hw["assigned_date"])); ?></strong>
                            </div>
                            <div class="col-6 col-md-4">
                                <div class="text-body-secondary small">Due</div>
                                <strong><?php echo date("d M Y", strtotime($hw["due_date"])); ?></strong>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <span class="badge bg-light text-dark border">
                                Submitted: <?php echo (int) $hw["submitted_count"]; ?>
                            </span>
                            <span class="badge bg-light text-dark border">
                                Checked: <?php echo (int) $hw["checked_count"]; ?>
                            </span>
                            <span class="badge bg-light text-dark border">
                                Pending: <?php echo $pending_students; ?>
                            </span>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <a
                                class="btn btn-sm btn-primary"
                                href="view.php?hw=<?php echo urlencode($hw['homework_uid']); ?>"
                            >
                                View Details
                            </a>
                            <a
                                class="btn btn-sm btn-outline-primary"
                                href="submissions.php?hw=<?php echo urlencode($hw['homework_uid']); ?>"
                            >
                                Student Submissions
                            </a>
                            <a
                                class="btn btn-sm btn-outline-secondary"
                                href="edit.php?hw=<?php echo urlencode($hw['homework_uid']); ?>"
                            >
                                Edit
                            </a>
                        </div>

                    </div>
                </div>
            </div>
        <?php endforeach; ?>

    </div>

    <div class="alert alert-warning text-center" id="noMatch" style="display: none;">
        <strong>No Homework Found</strong><br>
        No homework matches your search or selected filters.
    </div>

<?php endif; ?>

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

<?php include __DIR__ . '/../includes/footer.php'; ?>

