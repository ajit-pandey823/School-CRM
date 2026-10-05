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
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Homework</title>

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

        .error-box {
            background: #fee2e2;
            color: #b91c1c;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-weight: 600;
        }

        .form-card {
            background: #ffffff;
            padding: 26px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            max-width: 850px;
        }

        .form-card h2 { margin: 0 0 20px; }

        .form-group { margin-bottom: 16px; }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 14px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            font-family: Arial, sans-serif;
            outline: none;
            background: #ffffff;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #2563eb;
        }

        .form-group textarea {
            min-height: 120px;
            resize: vertical;
        }

        .row2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #2563eb;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }

        .btn:hover { background: #1d4ed8; }

        .btn.secondary { background: #6b7280; }
        .btn.secondary:hover { background: #4b5563; }

        .hint { color: #6b7280; font-size: 13px; margin-top: 5px; }

        .actions { margin-top: 22px; display: flex; gap: 12px; flex-wrap: wrap; }

    </style>

</head>

<body>

<div class="header">

    <h1>Add Homework</h1>

</div>

<div class="container">

    <a href="index.php" class="back">
        ← Back to Homework
    </a>


    <?php if ($error !== ""): ?>

        <div class="error-box">
            <?php echo htmlspecialchars($error); ?>
        </div>

    <?php endif; ?>


    <div class="form-card">

        <h2>Add Homework</h2>

        <?php if (count($assignments) === 0): ?>

            <p class="hint">
                No class or subject has been assigned to you yet.
            </p>

        <?php else: ?>

            <form method="POST" id="hwForm">

                <?php echo csrfField(); ?>

                <div class="row2">

                    <div class="form-group">

                        <label>Class *</label>

                        <select id="classSel" required>

                            <option value="">Select Class</option>

                        </select>

                    </div>

                    <div class="form-group">

                        <label>Section *</label>

                        <select id="sectionSel" required>

                            <option value="">Select Section</option>

                        </select>

                    </div>

                </div>

                <div class="form-group">

                    <label>Subject *</label>

                    <select id="subjectSel" required>

                        <option value="">Select Subject</option>

                    </select>

                </div>

                <input type="hidden" name="class_uid" id="classUid" value="<?php echo htmlspecialchars($old['class_uid'], ENT_QUOTES); ?>">
                <input type="hidden" name="section_uid" id="sectionUid" value="<?php echo htmlspecialchars($old['section_uid'], ENT_QUOTES); ?>">
                <input type="hidden" name="subject_uid" id="subjectUid" value="<?php echo htmlspecialchars($old['subject_uid'], ENT_QUOTES); ?>">
                <input type="hidden" name="academic_year_uid" id="yearUid" value="<?php echo htmlspecialchars($old['academic_year_uid'] ?? '', ENT_QUOTES); ?>">

                <div class="form-group">

                    <label>Title *</label>

                    <input
                        type="text"
                        name="title"
                        maxlength="255"
                        placeholder="e.g. Chapter 5 - Algebra Exercises"
                        value="<?php echo htmlspecialchars($old['title'], ENT_QUOTES); ?>"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Description</label>

                    <textarea
                        name="description"
                        placeholder="Instructions, questions or details..."
                    ><?php echo htmlspecialchars($old['description']); ?></textarea>

                </div>

                <div class="row2">

                    <div class="form-group">

                        <label>Assigned Date *</label>

                        <input
                            type="date"
                            name="assigned_date"
                            value="<?php echo htmlspecialchars($old['assigned_date'], ENT_QUOTES); ?>"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label>Due Date *</label>

                        <input
                            type="date"
                            name="due_date"
                            value="<?php echo htmlspecialchars($old['due_date'], ENT_QUOTES); ?>"
                            required
                        >

                    </div>

                </div>

                <div class="form-group" style="max-width:300px;">

                    <label>Max Marks *</label>

                    <input
                        type="number"
                        name="max_marks"
                        min="1"
                        max="1000"
                        step="0.5"
                        value="<?php echo htmlspecialchars($old['max_marks'], ENT_QUOTES); ?>"
                        required
                    >

                    <p class="hint">
                        Default is 10. Range: 1 to 1000.
                    </p>

                </div>

                <div class="actions">

                    <button class="btn" type="submit">
                        Save Homework
                    </button>

                    <a class="btn secondary" href="index.php">
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

</body>

</html>




