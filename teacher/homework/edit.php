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
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Homework</title>

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

        .banner {
            background: #dcfce7;
            color: #15803d;
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

        .form-card h2 { margin: 0 0 8px; }

        .fixed-info {
            color: #6b7280;
            font-size: 14px;
            margin: 0 0 20px;
        }

        .fixed-info strong { color: #111827; }

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

    <h1>Edit Homework</h1>

</div>

<div class="container">

    <a
        class="back"
        href="view.php?hw=<?php echo urlencode($homework["homework_uid"]); ?>"
    >
        ← Back to Homework Details
    </a>


    <?php if ($success !== ""): ?>

        <div class="banner">
            <?php echo htmlspecialchars($success); ?>
        </div>

    <?php endif; ?>


    <?php if ($error !== ""): ?>

        <div class="error-box">
            <?php echo htmlspecialchars($error); ?>
        </div>

    <?php endif; ?>


    <div class="form-card">

        <h2>Edit Homework</h2>

        <p class="fixed-info">
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

            <div class="form-group">

                <label>Title *</label>

                <input
                    type="text"
                    name="title"
                    maxlength="255"
                    value="<?php echo htmlspecialchars($old["title"], ENT_QUOTES); ?>"
                    required
                >

            </div>

            <div class="form-group">

                <label>Description</label>

                <textarea name="description"><?php echo htmlspecialchars($old["description"]); ?></textarea>

            </div>

            <div class="row2">

                <div class="form-group">

                    <label>Assigned Date *</label>

                    <input
                        type="date"
                        name="assigned_date"
                        value="<?php echo htmlspecialchars($old["assigned_date"], ENT_QUOTES); ?>"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Due Date *</label>

                    <input
                        type="date"
                        name="due_date"
                        value="<?php echo htmlspecialchars($old["due_date"], ENT_QUOTES); ?>"
                        required
                    >

                </div>

            </div>

            <div class="row2">

                <div class="form-group">

                    <label>Max Marks *</label>

                    <input
                        type="number"
                        name="max_marks"
                        min="1"
                        max="1000"
                        step="0.5"
                        value="<?php echo htmlspecialchars($old["max_marks"], ENT_QUOTES); ?>"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Status</label>

                    <select name="status">

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

            <div class="actions">

                <button class="btn" type="submit">
                    Save Changes
                </button>

                <a
                    class="btn secondary"
                    href="view.php?hw=<?php echo urlencode($homework["homework_uid"]); ?>"
                >
                    Cancel
                </a>

            </div>

        </form>

    </div>

</div>

</body>

</html>


