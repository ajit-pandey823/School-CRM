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

<?php
$UI_ROOT         = '../';
$UI_ACTIVE       = 'attendance';
$UI_TITLE        = 'Student Attendance';
$UI_CRUMBS       = [['label' => 'Attendance', 'url' => 'index.php']];
$UI_PROFILE_NAME = $teacher_name;
include __DIR__ . '/../includes/header.php';
?>

<div class="card mb-4">
    <div class="card-header">
        <strong>Student Attendance</strong>
    </div>
    <div class="card-body">

        <p class="text-body-secondary">
            Teacher:
            <strong><?= htmlspecialchars($teacher_name) ?></strong>
        </p>

        <?php if (empty($assignments)): ?>

            <div class="alert alert-warning mb-0" role="alert">
                No class or subject has been assigned to you yet.
            </div>

        <?php else: ?>

            <?php foreach ($assignments as $assignment): ?>

                <div class="card mb-3">
                    <div class="card-body">

                    <h5 class="card-title">Assigned Class</h5>

                    <div class="mb-1 text-body-secondary">
                        Class:
                        <strong>
                            <?= htmlspecialchars($assignment["class_uid"]) ?>
                        </strong>
                    </div>

                    <div class="mb-1 text-body-secondary">
                        Section:
                        <strong>
                            <?= htmlspecialchars($assignment["section_uid"]) ?>
                        </strong>
                    </div>

                    <div class="mb-1 text-body-secondary">
                        Subject:
                        <strong>
                            <?= htmlspecialchars($assignment["subject_uid"]) ?>
                        </strong>
                    </div>

                    <div class="mb-1 text-body-secondary">
                        Academic Year:
                        <strong>
                            <?= htmlspecialchars($assignment["academic_year_uid"]) ?>
                        </strong>
                    </div>

                    <a
                        class="btn btn-primary mt-2 me-2"
                        href="take.php?assignment_uid=<?= urlencode($assignment["assignment_uid"]) ?>"
                    >
                        Take Attendance
                    </a>

                    <a
                        class="btn btn-outline-primary mt-2"
                        href="history.php?assignment_uid=<?= urlencode($assignment["assignment_uid"]) ?>"
                    >
                        Attendance History
                    </a>

                    </div>
                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>