<?php

session_start();

require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../auth.php";

// LOGIN CHECK
if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit();
}

/* CSRF - sirf POST verify hota hai */
csrfVerify();

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

// TEACHER ASSIGNMENTS (FORM OPTIONS)
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

$js_assignments = [];

foreach ($assignments as $a) {
    $js_assignments[] = [
        "class_name" => $a["class_name"],
        "class_uid" => $a["class_uid"],
        "section_name" => $a["section_name"],
        "section_uid" => $a["section_uid"],
        "subject_name" => $a["subject_name"],
        "subject_uid" => $a["subject_uid"],
        "academic_year_uid" => $a["academic_year_uid"],
    ];
}

// FORM DEFAULTS
$error = "";

$old = [
    "class_uid" => "",
    "section_uid" => "",
    "subject_uid" => "",
    "academic_year_uid" => "",
    "title" => "",
    "description" => "",
    "assigned_date" => date("Y-m-d"),
    "due_date" => date("Y-m-d", strtotime("+7 days")),
    "max_marks" => "10",
];

// FORM SUBMISSION
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $old["class_uid"] = trim($_POST["class_uid"] ?? "");
    $old["section_uid"] = trim($_POST["section_uid"] ?? "");
    $old["subject_uid"] = trim($_POST["subject_uid"] ?? "");
    $old["academic_year_uid"] = trim($_POST["academic_year_uid"] ?? "");
    $old["title"] = trim($_POST["title"] ?? "");
    $old["description"] = trim($_POST["description"] ?? "");
    $old["assigned_date"] = trim($_POST["assigned_date"] ?? "");
    $old["due_date"] = trim($_POST["due_date"] ?? "");
    $old["max_marks"] = trim($_POST["max_marks"] ?? "");

    if ($old["title"] === "") {
        $error = "Homework title is required.";
    } elseif (strlen($old["title"]) > 255) {
        $error = "Title is too long (max 255 characters).";
    } elseif (
        $old["class_uid"] === "" ||
        $old["section_uid"] === "" ||
        $old["subject_uid"] === "" ||
        $old["academic_year_uid"] === ""
    ) {
        $error = "Please select class, section and subject.";
    } elseif (
        !preg_match("/^\d{4}-\d{2}-\d{2}$/", $old["assigned_date"]) ||
        !preg_match("/^\d{4}-\d{2}-\d{2}$/", $old["due_date"])
    ) {
        $error = "Please enter valid dates.";
    } elseif (strtotime($old["due_date"]) < strtotime($old["assigned_date"])) {
        $error = "Due date cannot be before assigned date.";
    } elseif (
        $old["max_marks"] === "" ||
        !is_numeric($old["max_marks"]) ||
        $old["max_marks"] <= 0 ||
        $old["max_marks"] > 1000
    ) {
        $error = "Max marks must be between 1 and 1000.";
    } else {

        // VERIFY ASSIGNMENT BELONGS TO THIS TEACHER
        $sql = "
            SELECT assignment_uid
            FROM teacher_subjects
            WHERE teacher_uid = ?
            AND class_uid = ?
            AND section_uid = ?
            AND subject_uid = ?
            AND academic_year_uid = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "sssss",
            $teacher_uid,
            $old["class_uid"],
            $old["section_uid"],
            $old["subject_uid"],
            $old["academic_year_uid"]
        );
        $stmt->execute();
        $assignment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$assignment) {
            $error = "This class, section and subject is not assigned to you.";
        } else {

            // NEXT HOMEWORK UID
            $result = mysqli_query(
                $conn,
                "SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM homework"
            );
            $row = mysqli_fetch_assoc($result);

            $homework_uid =
                "HW" . str_pad((string) $row["next_id"], 8, "0", STR_PAD_LEFT);

            $max_marks = (float) $old["max_marks"];

            $sql = "
                INSERT INTO homework
                (
                    homework_uid,
                    school_uid,
                    teacher_uid,
                    class_uid,
                    section_uid,
                    subject_uid,
                    academic_year_uid,
                    title,
                    description,
                    assigned_date,
                    due_date,
                    max_marks
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $stmt = $conn->prepare($sql);

            if (!$stmt) {
                $error = "Could not save homework: " . $conn->error;
            } else {

                $description = $old["description"];

                $stmt->bind_param(
                    "sssssssssssd",
                    $homework_uid,
                    $school_uid,
                    $teacher_uid,
                    $old["class_uid"],
                    $old["section_uid"],
                    $old["subject_uid"],
                    $old["academic_year_uid"],
                    $old["title"],
                    $description,
                    $old["assigned_date"],
                    $old["due_date"],
                    $max_marks
                );

                if ($stmt->execute()) {
                    $stmt->close();
                    header("Location: index.php?created=1");
                    exit();
                } else {
                    $error = "Could not save homework: " . $stmt->error;
                    $stmt->close();
                }
            }
        }
    }
}

?>

<?php
$UI_ROOT         = '../';
$UI_ACTIVE       = 'homework';
$UI_TITLE        = 'Create Homework';
$UI_CRUMBS       = [['label' => 'Homework', 'url' => 'index.php']];
$UI_PROFILE_NAME = $teacher_name;
include __DIR__ . '/../includes/header.php';
?>

<a class="btn btn-outline-secondary btn-sm mb-3" href="index.php">
    &larr; Back to Homework
</a>

<?php if ($error !== ""): ?>
    <div class="alert alert-danger" role="alert">
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header"><strong>Add Homework</strong></div>
    <div class="card-body">

        <?php if (count($assignments) === 0): ?>

            <div class="alert alert-warning mb-0" role="alert">
                No class or subject has been assigned to you yet.
            </div>

        <?php else: ?>

            <form method="POST" id="hwForm">

                <?php echo csrfField(); ?>

                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="classSel">Class *</label>
                        <select class="form-select" id="classSel" required>
                            <option value="">Select Class</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="sectionSel">Section *</label>
                        <select class="form-select" id="sectionSel" required>
                            <option value="">Select Section</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="subjectSel">Subject *</label>
                        <select class="form-select" id="subjectSel" required>
                            <option value="">Select Subject</option>
                        </select>
                    </div>
                </div>

                <input type="hidden" name="class_uid" id="classUid" value="<?php echo htmlspecialchars($old['class_uid'], ENT_QUOTES); ?>">
                <input type="hidden" name="section_uid" id="sectionUid" value="<?php echo htmlspecialchars($old['section_uid'], ENT_QUOTES); ?>">
                <input type="hidden" name="subject_uid" id="subjectUid" value="<?php echo htmlspecialchars($old['subject_uid'], ENT_QUOTES); ?>">
                <input type="hidden" name="academic_year_uid" id="yearUid" value="<?php echo htmlspecialchars($old['academic_year_uid'] ?? '', ENT_QUOTES); ?>">

                <div class="mt-3">
                    <label class="form-label" for="hwTitle">Title *</label>
                    <input
                        type="text"
                        class="form-control"
                        id="hwTitle"
                        name="title"
                        maxlength="255"
                        placeholder="e.g. Chapter 5 - Algebra Exercises"
                        value="<?php echo htmlspecialchars($old['title'], ENT_QUOTES); ?>"
                        required
                    >
                </div>
                <div class="mt-3">
                    <label class="form-label" for="hwDescription">Description</label>
                    <textarea
                        class="form-control"
                        id="hwDescription"
                        name="description"
                        rows="4"
                        placeholder="Instructions, questions or details..."
                    ><?php echo htmlspecialchars($old['description']); ?></textarea>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="assignedDate">Assigned Date *</label>
                        <input
                            type="date"
                            class="form-control"
                            id="assignedDate"
                            name="assigned_date"
                            value="<?php echo htmlspecialchars($old['assigned_date'], ENT_QUOTES); ?>"
                            required
                        >
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="dueDate">Due Date *</label>
                        <input
                            type="date"
                            class="form-control"
                            id="dueDate"
                            name="due_date"
                            value="<?php echo htmlspecialchars($old['due_date'], ENT_QUOTES); ?>"
                            required
                        >
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="maxMarks">Max Marks *</label>
                        <input
                            type="number"
                            class="form-control"
                            id="maxMarks"
                            name="max_marks"
                            min="1"
                            max="1000"
                            step="0.5"
                            value="<?php echo htmlspecialchars($old['max_marks'], ENT_QUOTES); ?>"
                            required
                        >
                        <div class="form-text">Default is 10. Range: 1 to 1000.</div>
                    </div>
                </div>

                <div class="mt-4 d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" type="submit">
                        Save Homework
                    </button>
                    <a class="btn btn-outline-secondary" href="index.php">
                        Cancel
                    </a>
                </div>

            </form>

        <?php endif; ?>

    </div>
</div>

<script>

(function () {

    const assignments = <?php echo json_encode($js_assignments, JSON_HEX_TAG); ?>;

    if (!assignments.length) {
        return;
    }

    const classSel = document.getElementById("classSel");
    const sectionSel = document.getElementById("sectionSel");
    const subjectSel = document.getElementById("subjectSel");

    const classUid = document.getElementById("classUid");
    const sectionUid = document.getElementById("sectionUid");
    const subjectUid = document.getElementById("subjectUid");
    const yearUid = document.getElementById("yearUid");

    function addOption(select, value, text) {
        const option = document.createElement("option");
        option.value = value;
        option.textContent = text;
        select.appendChild(option);
    }

    // DISTINCT CLASSES
    const classNames = [];

    assignments.forEach(function (a) {
        if (classNames.indexOf(a.class_name) === -1) {
            classNames.push(a.class_name);
        }
    });

    classNames.forEach(function (name) {
        addOption(classSel, name, name);
    });

    function fillSections(selected) {

        while (sectionSel.options.length > 1) {
            sectionSel.remove(1);
        }

        const seen = [];

        assignments.forEach(function (a) {
            if (
                a.class_name === classSel.value &&
                seen.indexOf(a.section_uid) === -1
            ) {
                seen.push(a.section_uid);
                addOption(sectionSel, a.section_uid, a.section_name);
            }
        });

        sectionSel.value = selected || "";
    }

    function fillSubjects(selected) {

        while (subjectSel.options.length > 1) {
            subjectSel.remove(1);
        }

        assignments.forEach(function (a) {
            if (
                a.class_name === classSel.value &&
                a.section_uid === sectionSel.value
            ) {
                addOption(subjectSel, a.subject_uid, a.subject_name);
            }
        });

        subjectSel.value = selected || "";
    }

    function updateHidden() {

        let match = null;

        assignments.forEach(function (a) {
            if (
                a.class_name === classSel.value &&
                a.section_uid === sectionSel.value &&
                a.subject_uid === subjectSel.value
            ) {
                match = a;
            }
        });

        if (match) {
            classUid.value = match.class_uid;
            sectionUid.value = match.section_uid;
            subjectUid.value = match.subject_uid;
            yearUid.value = match.academic_year_uid;
        } else {
            classUid.value = "";
            sectionUid.value = "";
            subjectUid.value = "";
            yearUid.value = "";
        }
    }

    // RESTORE OLD VALUES (AFTER VALIDATION ERROR)
    if (classUid.value) {

        let match = null;

        assignments.forEach(function (a) {
            if (a.class_uid === classUid.value && !match) {
                match = a;
            }
        });

        if (match) {
            classSel.value = match.class_name;
            fillSections(sectionUid.value);
            fillSubjects(subjectUid.value);
            updateHidden();
        }
    }

    classSel.addEventListener("change", function () {
        fillSections("");
        updateHidden();
    });

    sectionSel.addEventListener("change", function () {
        fillSubjects("");
        updateHidden();
    });

    subjectSel.addEventListener("change", updateHidden);

})();

</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

