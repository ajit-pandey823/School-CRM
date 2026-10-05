<?php

/*
 |--------------------------------------------------------------------------
 | TEACHER - PROFILE : PROFILE VIEW
 |--------------------------------------------------------------------------
 | Flow: Dashboard > Profile > (view > edit contact / change password)
 |
 | Read only page. Nothing is written here.
 |
 | The password is NEVER shown: `users.password` is not even selected by the
 | query in profile_common.php, so the hash cannot reach this page.
 */

require_once __DIR__ . "/includes/profile_common.php";

$photo_path = profilePhotoPath($profile);
$phone      = profilePrimaryPhone($profile);
$email      = profilePrimaryEmail($profile);
$can_edit   = profileCanEditContact($profile);

$teacher_ok = (strtolower((string)$profile["teacher_status"]) === "active");
$account_ok = (strtolower((string)$profile["account_status"]) === "active");

/* school address on one line, built once so the template stays readable */
$school_address_parts = array_filter([
    $profile["school_address"] ?? "",
    $profile["school_city"] ?? "",
    $profile["school_state"] ?? "",
    $profile["school_pincode"] ?? "",
], function ($part) {
    return trim((string)$part) !== "";
});

$school_address = (empty($school_address_parts)) ? "-" : implode(", ", $school_address_parts);

/*
 | Flash message coming back from edit.php / change-password.php / photo.php.
 | Only these known keys are echoed, never the raw query string.
 */
$ok_messages = [
    "contact"  => "Your contact details have been updated.",
    "photo"    => "Your profile photo has been updated.",
    "password" => "Your password has been changed. Please use it the next time you log in.",
];

$err_messages = [
    "not_allowed"    => "Your school does not allow you to change these details.",
    "validation"     => "Please correct the highlighted fields and try again.",
    "password_wrong" => "Your current password is not correct.",
    "password_weak"  => "The new password must be at least 8 characters long.",
    "password_same"  => "The new password must be different from the current one.",
    "password_fail"  => "The password could not be changed. Please try again.",
    "photo_type"     => "Only JPG, PNG or WEBP images up to 2 MB can be used as a profile photo.",
    "photo_fail"     => "The photo could not be saved. Please try again.",
];

$flash_ok  = trim($_GET["ok"] ?? "");
$flash_err = trim($_GET["err"] ?? "");

$show_ok  = (isset($ok_messages[$flash_ok]))  ? $ok_messages[$flash_ok]  : "";
$show_err = (isset($err_messages[$flash_err])) ? $err_messages[$flash_err] : "";

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1">

<title>My Profile</title>

<style>
<?= profileModuleStyles() ?>
</style>

</head>

<body>

<div class="container">

    <div class="card no-print">
        <a class="back-link" href="../dashboard.php">&larr; Back to Dashboard</a>
    </div>

    <?php if ($show_ok !== ""): ?>
        <div class="msg msg-ok"><?= htmlspecialchars($show_ok) ?></div>
    <?php endif; ?>

    <?php if ($show_err !== ""): ?>
        <div class="msg msg-err"><?= htmlspecialchars($show_err) ?></div>
    <?php endif; ?>

    <!-- ================= 1. PROFILE HEADER ================= -->

    <div class="card">

        <div class="profile-head">

            <div class="avatar">
                <?php if ($photo_path !== ""): ?>
                    <img
                        src="../../<?= htmlspecialchars($photo_path) ?>"
                        alt="<?= htmlspecialchars($teacher_name) ?>">
                <?php else: ?>
                    <?= htmlspecialchars(profileInitials($teacher_name)) ?>
                <?php endif; ?>
            </div>

            <div class="head-info">

                <h1><?= htmlspecialchars($teacher_name) ?></h1>

                <div class="head-sub">
                    <?= htmlspecialchars(($profile["designation"] !== "") ? $profile["designation"] : "Teacher") ?>
                    <?php if ($profile["employee_code"] !== ""): ?>
                        &middot; Employee ID:
                        <strong><?= htmlspecialchars($profile["employee_code"]) ?></strong>
                    <?php endif; ?>
                </div>

                <div class="head-sub">
                    <?php if ($profile["department"] !== ""): ?>
                        Department: <strong><?= htmlspecialchars($profile["department"]) ?></strong>
                    <?php endif; ?>
                </div>

                <div>
                    <?php if ($account_ok): ?>
                        <span class="badge badge-active"><?= htmlspecialchars(ucfirst((string)$profile["account_status"])) ?></span>
                    <?php elseif (strtolower((string)$profile["account_status"]) === "blocked"): ?>
                        <span class="badge badge-blocked">Blocked</span>
                    <?php else: ?>
                        <span class="badge badge-inactive"><?= htmlspecialchars(ucfirst((string)$profile["account_status"])) ?></span>
                    <?php endif; ?>

                    <?php if (!$teacher_ok): ?>
                        <span class="badge badge-locked"><?= htmlspecialchars(ucfirst((string)$profile["teacher_status"])) ?></span>
                    <?php endif; ?>

                    <?php if ($phone !== ""): ?>
                        <span class="chip">📞 <?= htmlspecialchars($phone) ?></span>
                    <?php endif; ?>

                    <?php if ($email !== ""): ?>
                        <span class="chip">✉️ <?= htmlspecialchars($email) ?></span>
                    <?php endif; ?>
                </div>

            </div>

        </div>

    </div>

    <!-- ================= 2. PERSONAL INFORMATION ================= -->

    <div class="card">

        <h2>Personal Information</h2>

        <div class="detail-grid">

            <div class="detail-item">
                <span>Full Name</span>
                <strong><?= htmlspecialchars($teacher_name) ?></strong>
            </div>

            <div class="detail-item">
                <span>Date of Birth</span>
                <strong><?= htmlspecialchars(profileDateLabel($profile["dob"])) ?></strong>
            </div>

            <div class="detail-item">
                <span>Gender</span>
                <strong><?= htmlspecialchars(profileGenderLabel($profile["gender"])) ?></strong>
            </div>

            <div class="detail-item">
                <span>Phone Number</span>
                <strong><?= htmlspecialchars(($phone !== "") ? $phone : "-") ?></strong>
            </div>

            <div class="detail-item">
                <span>Email Address</span>
                <strong><?= htmlspecialchars(($email !== "") ? $email : "-") ?></strong>
            </div>

            <div class="detail-item full-width">
                <span>Address</span>
                <strong><?= htmlspecialchars((trim((string)$profile["teacher_address"]) !== "") ? $profile["teacher_address"] : "-") ?></strong>
            </div>

            <div class="detail-item">
                <span>City</span>
                <strong><?= htmlspecialchars(($profile["city"] !== "") ? $profile["city"] : "-") ?></strong>
            </div>

            <div class="detail-item">
                <span>State</span>
                <strong><?= htmlspecialchars(($profile["state"] !== "") ? $profile["state"] : "-") ?></strong>
            </div>

            <div class="detail-item">
                <span>PIN Code</span>
                <strong><?= htmlspecialchars(($profile["pincode"] !== "") ? $profile["pincode"] : "-") ?></strong>
            </div>

        </div>

        <div class="toolbar no-print">
            <?php if ($can_edit): ?>
                <a class="btn btn-outline" href="edit.php">✏️ Edit Contact Details</a>
            <?php else: ?>
                <span class="badge badge-locked">Contact details are managed by the school</span>
            <?php endif; ?>
        </div>

    </div>



    <!-- ================= 3. PROFESSIONAL INFORMATION ================= -->

    <div class="card">

        <h2>Professional Information</h2>

        <div class="detail-grid">

            <div class="detail-item">
                <span>Employee ID</span>
                <strong>
                    <?= htmlspecialchars(($profile["employee_code"] !== "") ? $profile["employee_code"] : "-") ?>
                    <span class="badge badge-locked">Managed by school</span>
                </strong>
            </div>

            <div class="detail-item">
                <span>Designation</span>
                <strong>
                    <?= htmlspecialchars(($profile["designation"] !== "") ? $profile["designation"] : "-") ?>
                    <span class="badge badge-locked">Managed by school</span>
                </strong>
            </div>

            <div class="detail-item">
                <span>Department</span>
                <strong>
                    <?= htmlspecialchars(($profile["department"] !== "") ? $profile["department"] : "-") ?>
                    <span class="badge badge-locked">Managed by school</span>
                </strong>
            </div>

            <div class="detail-item">
                <span>Joining Date</span>
                <strong>
                    <?= htmlspecialchars(profileDateLabel($profile["joining_date"])) ?>
                    <span class="badge badge-locked">Managed by school</span>
                </strong>
            </div>

            <div class="detail-item">
                <span>Qualification</span>
                <strong><?= htmlspecialchars((trim((string)$profile["qualification"]) !== "") ? $profile["qualification"] : "-") ?></strong>
            </div>

            <div class="detail-item">
                <span>Experience</span>
                <strong><?= htmlspecialchars((trim((string)$profile["experience"]) !== "") ? $profile["experience"] : "-") ?></strong>
            </div>

            <div class="detail-item full-width">
                <span>Assigned Classes</span>
                <div>
                    <?php if (empty($assigned_class_labels)): ?>
                        <strong>-</strong>
                    <?php else: ?>
                        <?php foreach ($assigned_class_labels as $class_label): ?>
                            <span class="chip"><?= htmlspecialchars($class_label) ?></span>
                        <?php endforeach; ?>
                        <span class="badge badge-locked">Managed by school</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="detail-item full-width">
                <span>Assigned Subjects</span>
                <div>
                    <?php if (empty($assigned_subjects)): ?>
                        <strong>-</strong>
                    <?php else: ?>
                        <?php foreach ($assigned_subjects as $subject_name): ?>
                            <span class="chip"><?= htmlspecialchars($subject_name) ?></span>
                        <?php endforeach; ?>
                        <span class="badge badge-locked">Managed by school</span>
                    <?php endif; ?>
                </div>
            </div>

        </div>

    </div>



    <!-- ================= 4. SCHOOL INFORMATION ================= -->

    <div class="card">

        <h2>School Information</h2>

        <div class="detail-grid">

            <div class="detail-item">
                <span>School Name</span>
                <strong><?= htmlspecialchars(($profile["school_name"] !== "") ? $profile["school_name"] : "-") ?></strong>
            </div>

            <div class="detail-item">
                <span>School ID</span>
                <strong>
                    <?= htmlspecialchars(($profile["school_code"] !== "") ? $profile["school_code"] : "-") ?>
                    <span class="badge badge-locked">Managed by school</span>
                </strong>
            </div>

            <div class="detail-item">
                <span>Academic Session</span>
                <strong><?= htmlspecialchars($session_name) ?></strong>
            </div>

            <div class="detail-item">
                <span>Branch / Campus</span>
                <strong><?= htmlspecialchars(($profile["school_name"] !== "") ? $profile["school_name"] : "Main Campus") ?></strong>
            </div>

            <div class="detail-item">
                <span>School Contact</span>
                <strong><?= htmlspecialchars((trim((string)$profile["school_phone"]) !== "") ? $profile["school_phone"] : "-") ?></strong>
            </div>

            <div class="detail-item">
                <span>School Email</span>
                <strong><?= htmlspecialchars(($profile["school_email"] !== "") ? $profile["school_email"] : "-") ?></strong>
            </div>

            <div class="detail-item full-width">
                <span>School Address</span>
                <strong><?= htmlspecialchars($school_address) ?></strong>
            </div>

            <?php if ($profile["affiliation_no"] !== "" || $profile["udise_code"] !== ""): ?>

                <div class="detail-item">
                    <span>Affiliation No</span>
                    <strong><?= htmlspecialchars(($profile["affiliation_no"] !== "") ? $profile["affiliation_no"] : "-") ?></strong>
                </div>

                <div class="detail-item">
                    <span>UDISE Code</span>
                    <strong><?= htmlspecialchars(($profile["udise_code"] !== "") ? $profile["udise_code"] : "-") ?></strong>
                </div>

            <?php endif; ?>

        </div>

    </div>

    <!-- ================= 5. ACCOUNT & SECURITY ================= -->

    <div class="card">

        <h2>Account &amp; Security</h2>

        <div class="detail-grid">

            <div class="detail-item">
                <span>Username</span>
                <strong><?= htmlspecialchars($profile["username"]) ?></strong>
            </div>

            <div class="detail-item">
                <span>Role</span>
                <strong>
                    <?= htmlspecialchars(($profile["role_name"] !== "") ? $profile["role_name"] : "Teacher") ?>
                    <span class="badge badge-locked">Managed by school</span>
                </strong>
            </div>

            <div class="detail-item">
                <span>Account Status</span>
                <strong>
                    <?php if ($account_ok): ?>
                        <span class="badge badge-active">Active</span>
                    <?php else: ?>
                        <span class="badge badge-blocked"><?= htmlspecialchars(ucfirst((string)$profile["account_status"])) ?></span>
                    <?php endif; ?>
                </strong>
            </div>

            <div class="detail-item">
                <span>Last Login</span>
                <strong><?= htmlspecialchars(profileDateTimeLabel($profile["last_login"])) ?></strong>
            </div>

            <div class="detail-item full-width">
                <span>Password</span>
                <div class="lock-note">
                    Your password is never displayed. You can only change it
                    yourself using <strong>Change Password</strong>, which needs
                    your current password.
                </div>
            </div>

        </div>

        <div class="toolbar no-print">
            <a class="btn" href="change-password.php">🔐 Change Password</a>
            <a class="btn btn-secondary" href="photo.php">🖼️ Update Profile Photo</a>
            <a class="btn btn-danger" href="logout.php">🚪 Logout from Account</a>
        </div>

    </div>

</div>

</body>

</html>

