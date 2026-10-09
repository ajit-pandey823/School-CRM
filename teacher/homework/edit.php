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
    $teacher_name = $teacher["teacher_name"] ?? "";
}

$stmt_teacher->close();

if ($teacher_uid === "") {
    die("Teacher profile not found.");
}

// GET HOMEWORK + VERIFY OWNER
$homework_uid = trim($_GET["hw"] ?? "");

if ($homework_uid === "") {
    header("Location: index.php");
    exit();
}

$sql = "
    SELECT h.*
    FROM homework h
    WHERE h.homework_uid = ?
    AND h.teacher_uid = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Homework query error: " . $conn->error);
}

$stmt->bind_param("ss", $homework_uid, $teacher_uid);
$stmt->execute();

$homework = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$homework) {
    header("Location: index.php");
    exit();
}

$error = "";
$success = "";

$old = [
    "title" => $homework["title"],
    "description" => $homework["description"] ?? "",
    "assigned_date" => $homework["assigned_date"],
    "due_date" => $homework["due_date"],
    "max_marks" => rtrim(rtrim((string) $homework["max_marks"], "0"), "."),
    "status" => $homework["status"],
];

// FORM SUBMISSION
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $old["title"] = trim($_POST["title"] ?? "");
    $old["description"] = trim($_POST["description"] ?? "");
    $old["assigned_date"] = trim($_POST["assigned_date"] ?? "");
    $old["due_date"] = trim($_POST["due_date"] ?? "");
    $old["max_marks"] = trim($_POST["max_marks"] ?? "");
    $old["status"] = trim($_POST["status"] ?? "");

    if ($old["title"] === "") {
        $error = "Homework title is required.";
    } elseif (strlen($old["title"]) > 255) {
        $error = "Title is too long (max 255 characters).";
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
    } elseif (!in_array($old["status"], ["active", "inactive"], true)) {
        $error = "Invalid homework status.";
    } else {

        $max_marks = (float) $old["max_marks"];
        $description = $old["description"];

        $sql = "
            UPDATE homework
            SET title = ?,
                description = ?,
                assigned_date = ?,
                due_date = ?,
                max_marks = ?,
                status = ?
            WHERE homework_uid = ?
            AND teacher_uid = ?
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            $error = "Could not update homework: " . $conn->error;
        } else {

            $stmt->bind_param(
                "ssssdsss",
                $old["title"],
                $description,
                $old["assigned_date"],
                $old["due_date"],
                $max_marks,
                $old["status"],
                $homework_uid,
                $teacher_uid
            );

            if ($stmt->execute()) {
                $success = "Homework updated successfully.";
            } else {
                $error = "Could not update homework: " . $stmt->error;
            }

            $stmt->close();

            // REFRESH LOCAL DATA
            $stmt = $conn->prepare(
                "SELECT h.* FROM homework h
                 WHERE h.homework_uid = ? AND h.teacher_uid = ?
                 LIMIT 1"
            );
            $stmt->bind_param("ss", $homework_uid, $teacher_uid);
            $stmt->execute();
            $homework = $stmt->get_result()->fetch_assoc() ?: $homework;
            $stmt->close();

            $old["assigned_date"] = $homework["assigned_date"];
            $old["due_date"] = $homework["due_date"];
            $old["max_marks"] = rtrim(
                rtrim((string) $homework["max_marks"], "0"),
                "."
            );
            $old["status"] = $homework["status"];
        }
    }
}

?>

<?php
$UI_ROOT         = '../';
$UI_ACTIVE       = 'homework';
$UI_TITLE        = 'Edit Homework';
$UI_CRUMBS       = [['label' => 'Homework', 'url' => 'index.php']];
$UI_PROFILE_NAME = $teacher_name;
include __DIR__ . '/../includes/header.php';
?>

<a
    class="btn btn-outline-secondary btn-sm mb-3"
    href="view.php?hw=<?php echo urlencode($homework["homework_uid"]); ?>"
>
    &larr; Back to Homework Details
</a>

<?php if ($success !== ""): ?>
    <div class="alert alert-success" role="alert">
        <?php echo htmlspecialchars($success); ?>
    </div>
<?php endif; ?>

<?php if ($error !== ""): ?>
    <div class="alert alert-danger" role="alert">
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header"><strong>Edit Homework</strong></div>
    <div class="card-body">

        <p class="text-body-secondary">
            Class:
            <strong><?php echo htmlspecialchars($homework["class_uid"]); ?></strong>
            &nbsp;|&nbsp;
            Section:
            <strong><?php echo htmlspecialchars($homework["section_uid"]); ?></strong>
            &nbsp;|&nbsp;
            Subject:
            <strong><?php echo htmlspecialchars($homework["subject_uid"]); ?></strong>
            &nbsp;(cannot be changed)
        </p>

        <form method="POST">

            <?php echo csrfField(); ?>

            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label" for="hwTitle">Title *</label>
                    <input
                        type="text"
                        class="form-control"
                        id="hwTitle"
                        name="title"
                        maxlength="255"
                        value="<?php echo htmlspecialchars($old["title"], ENT_QUOTES); ?>"
                        required
                    >
                </div>
                <div class="col-12">
                    <label class="form-label" for="hwDescription">Description</label>
                    <textarea
                        class="form-control"
                        id="hwDescription"
                        name="description"
                        rows="4"
                    ><?php echo htmlspecialchars($old["description"]); ?></textarea>
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label" for="assignedDate">Assigned Date *</label>
                    <input
                        type="date"
                        class="form-control"
                        id="assignedDate"
                        name="assigned_date"
                        value="<?php echo htmlspecialchars($old["assigned_date"], ENT_QUOTES); ?>"
                        required
                    >
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label" for="dueDate">Due Date *</label>
                    <input
                        type="date"
                        class="form-control"
                        id="dueDate"
                        name="due_date"
                        value="<?php echo htmlspecialchars($old["due_date"], ENT_QUOTES); ?>"
                        required
                    >
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label" for="maxMarks">Max Marks *</label>
                    <input
                        type="number"
                        class="form-control"
                        id="maxMarks"
                        name="max_marks"
                        min="1"
                        max="1000"
                        step="0.5"
                        value="<?php echo htmlspecialchars($old["max_marks"], ENT_QUOTES); ?>"
                        required
                    >
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label" for="hwStatus">Status</label>
                    <select class="form-select" id="hwStatus" name="status">
                        <option
                            value="active"
                            <?php echo $old["status"] === "active" ? "selected" : ""; ?>
                        >
                            Active
                        </option>
                        <option
                            value="inactive"
                            <?php echo $old["status"] === "inactive" ? "selected" : ""; ?>
                        >
                            Inactive
                        </option>
                    </select>
                </div>

            </div>

            <div class="mt-4 d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">
                    Save Changes
                </button>
                <a
                    class="btn btn-outline-secondary"
                    href="view.php?hw=<?php echo urlencode($homework["homework_uid"]); ?>"
                >
                    Cancel
                </a>
            </div>

        </form>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

