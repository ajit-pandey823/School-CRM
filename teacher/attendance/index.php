<?php

session_start();

require_once __DIR__ . "/../../function/function.php";

if (!isset($_SESSION["user_uid"])) {
    header("Location: ../../login.php");
    exit();
}

if (($_SESSION["user_type"] ?? "") !== "teacher") {
    header("Location: ../../login.php");
    exit();
}

$school_uid = $_SESSION["school_uid"];
$user_uid   = $_SESSION["user_uid"];

/*
|--------------------------------------------------------------------------
| GET TEACHER
|--------------------------------------------------------------------------
*/

$sql = "SELECT teacher_uid, teacher_name
        FROM teachers
        WHERE school_uid = ?
          AND user_uid = ?
          AND status = 'active'
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $school_uid, $user_uid);
$stmt->execute();

$result = $stmt->get_result();
$teacher = $result->fetch_assoc();

if (!$teacher) {
    die("Teacher profile not found.");
}

$teacher_uid  = $teacher["teacher_uid"];
$teacher_name = $teacher["teacher_name"];

/*
|--------------------------------------------------------------------------
| GET TEACHER ASSIGNMENTS
|--------------------------------------------------------------------------
*/

$sql = "SELECT
            ts.assignment_uid,
            ts.subject_uid,
            ts.class_uid,
            ts.section_uid,
            ts.academic_year_uid
        FROM teacher_subjects ts
        WHERE ts.school_uid = ?
          AND ts.teacher_uid = ?
        ORDER BY ts.class_uid, ts.section_uid";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $school_uid, $teacher_uid);
$stmt->execute();

$assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Student Attendance</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f5f6fa;
            padding: 30px;
            margin: 0;
        }

        .container {
            max-width: 1000px;
            margin: auto;
        }

        .card {
            background: #fff;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,.08);
        }

        h1 {
            margin-top: 0;
        }

        .teacher-info {
            color: #555;
            margin-bottom: 20px;
        }

        .assignment {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 18px;
            margin-bottom: 15px;
        }

        .assignment h3 {
            margin-top: 0;
        }

        .info {
            margin: 8px 0;
            color: #555;
        }

        .btn {
            display: inline-block;
            padding: 10px 18px;
            background: #007bff;
            color: #fff;
            text-decoration: none;
            border-radius: 6px;
            margin-top: 10px;
            margin-right: 8px;
        }

        .btn:hover {
            background: #0056b3;
        }

        .empty {
            padding: 20px;
            background: #fff3cd;
            border-radius: 6px;
        }
    </style>
</head>

<body>

<div class="container">

    <div class="card">

        <h1>Student Attendance</h1>

        <div class="teacher-info">
            Teacher:
            <strong><?= htmlspecialchars($teacher_name) ?></strong>
        </div>

        <?php if (empty($assignments)): ?>

            <div class="empty">
                No class or subject has been assigned to you yet.
            </div>

        <?php else: ?>

            <?php foreach ($assignments as $assignment): ?>

                <div class="assignment">

                    <h3>Assigned Class</h3>

                    <div class="info">
                        Class:
                        <strong>
                            <?= htmlspecialchars($assignment["class_uid"]) ?>
                        </strong>
                    </div>

                    <div class="info">
                        Section:
                        <strong>
                            <?= htmlspecialchars($assignment["section_uid"]) ?>
                        </strong>
                    </div>

                    <div class="info">
                        Subject:
                        <strong>
                            <?= htmlspecialchars($assignment["subject_uid"]) ?>
                        </strong>
                    </div>

                    <div class="info">
                        Academic Year:
                        <strong>
                            <?= htmlspecialchars($assignment["academic_year_uid"]) ?>
                        </strong>
                    </div>

                    <a
                        class="btn"
                        href="take.php?assignment_uid=<?= urlencode($assignment["assignment_uid"]) ?>"
                    >
                        Take Attendance
                    </a>

                    <a
                        class="btn"
                        href="history.php?assignment_uid=<?= urlencode($assignment["assignment_uid"]) ?>"
                    >
                        Attendance History
                    </a>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>

</div>

</body>
</html>