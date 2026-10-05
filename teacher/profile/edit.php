<?php

/*
 |--------------------------------------------------------------------------
 | TEACHER - PROFILE : EDIT CONTACT DETAILS
 |--------------------------------------------------------------------------
 | The ONLY fields a teacher may change, and only when
 | teacher_profiles.can_edit_contact = 1:
 |
 |     mobile, email, address  -> teachers
 |     city, state, pincode    -> teacher_profiles
 |
 | NOTHING ELSE IS EVER WRITTEN
 | -----------------------------
 | employee_code, teacher_name, gender, dob, qualification, experience,
 | joining_date, status, school_uid, user_uid, role_uid, username and the
 | teacher_subjects assignments are never part of an UPDATE here. They are
 | managed by the school admin, and the write list below is a hard coded
 | allow list - a crafted POST field name cannot reach any other column.
 */

require_once __DIR__ . "/includes/profile_common.php";

/* CSRF - sirf POST verify hota hai */
csrfVerify();

$can_edit = profileCanEditContact($profile);

$errors = [];

$form = [
    "mobile"  => profilePrimaryPhone($profile),
    "email"   => profilePrimaryEmail($profile),
    "address" => (string)$profile["teacher_address"],
    "city"    => (string)$profile["city"],
    "state"   => (string)$profile["state"],
    "pincode" => (string)$profile["pincode"],
];


/*
 |--------------------------------------------------------------------------
 | SAVE
 |--------------------------------------------------------------------------
 */

if (($_SERVER["REQUEST_METHOD"] ?? "") === "POST") {

    /* the school may lock the teacher out of editing their own contact */
    if (!$can_edit) {
        header("Location: index.php?err=not_allowed");
        exit();
    }

    $mobile  = trim($_POST["mobile"] ?? "");
    $email   = trim($_POST["email"] ?? "");
    $address = trim($_POST["address"] ?? "");
    $city    = trim($_POST["city"] ?? "");
    $state   = trim($_POST["state"] ?? "");
    $pincode = trim($_POST["pincode"] ?? "");

    /* show back what was typed, so nothing is lost on a validation error */
    $form = [
        "mobile"  => $mobile,
        "email"   => $email,
        "address" => $address,
        "city"    => $city,
        "state"   => $state,
        "pincode" => $pincode,
    ];

    if ($mobile !== "" && !preg_match("/^[0-9+\-\s()]{6,20}$/", $mobile)) {
        $errors[] = "Please enter a valid phone number.";
    }

    if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }

    if (mb_strlen($address) > 500) {
        $errors[] = "The address is too long (maximum 500 characters).";
    }

    if ($pincode !== "" && !preg_match("/^[0-9]{4,10}$/", $pincode)) {
        $errors[] = "Please enter a valid PIN code.";
    }

    if (mb_strlen($city) > 100 || mb_strlen($state) > 100) {
        $errors[] = "City and state must be under 100 characters each.";
    }

    if (empty($errors)) {

        /* 1. contact fields held on the teacher record */
        $sql = "UPDATE teachers
                SET mobile = ?,
                    email = ?,
                    address = ?
                WHERE teacher_uid = ?
                  AND school_uid = ?
                  AND user_uid = ?";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            die("Could not save your details. Please try again.");
        }

        $stmt->bind_param("ssssss", $mobile, $email, $address, $teacher_uid, $school_uid, $user_uid);
        $saved = $stmt->execute();
        $stmt->close();

        /* 2. city / state / pincode on the profile row */
        if ($saved) {

            $sql = "UPDATE teacher_profiles
                    SET city = ?,
                        state = ?,
                        pincode = ?
                    WHERE teacher_uid = ?
                      AND school_uid = ?";

            $stmt = $conn->prepare($sql);

            if ($stmt) {

                $stmt->bind_param("sssss", $city, $state, $pincode, $teacher_uid, $school_uid);
                $stmt->execute();
                $stmt->close();
            }

            /* keep the login account contact in step with the teacher record */
            $sql = "UPDATE users
                    SET mobile = ?,
                        email = ?
                    WHERE user_uid = ?
                      AND school_uid = ?";

            $stmt = $conn->prepare($sql);

            if ($stmt) {

                $stmt->bind_param("ssss", $mobile, $email, $user_uid, $school_uid);
                $stmt->execute();
                $stmt->close();
            }

            /* keep the session copy in step, so the dashboard shows it too */
            $_SESSION["mobile"] = $mobile;
            $_SESSION["email"]  = $email;

            header("Location: index.php?ok=contact");
            exit();
        }
    }
}


?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Edit Contact Details</title>

<style>
<?= profileModuleStyles() ?>
</style>

</head>

<body>

<div class="container">

    <div class="card">

        <a class="back-link" href="index.php">&larr; Back to Profile</a>

        <h1>Edit Contact Details</h1>

        <div class="msg msg-info">
            You can update your own phone number, email address and location.
            Your employee ID, designation, department, joining date, role,
            school and assigned classes / subjects are managed by the school
            administrator and cannot be changed from here.
        </div>

        <?php if (!empty($errors)): ?>
            <div class="msg msg-err">
                <ul style="margin:0;padding-left:18px;">
                    <?php foreach ($errors as $error): ?>
                        <?php if ($error !== ""): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="edit.php">

            <?php echo csrfField(); ?>

            <div class="section-label">Personal Contact Details</div>

            <div class="form-grid">

                <div>
                    <label for="mobile">Phone Number</label>
                    <input
                        type="text"
                        id="mobile"
                        name="mobile"
                        maxlength="20"
                        value="<?= htmlspecialchars($form["mobile"]) ?>">
                </div>

                <div>
                    <label for="email">Email Address</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        maxlength="150"
                        value="<?= htmlspecialchars($form["email"]) ?>">
                </div>

                <div style="grid-column: 1 / -1;">
                    <label for="address">Address</label>
                    <textarea
                        id="address"
                        name="address"
                        maxlength="500"
                        rows="3"><?= htmlspecialchars($form["address"]) ?></textarea>
                </div>

                <div>
                    <label for="city">City</label>
                    <input
                        type="text"
                        id="city"
                        name="city"
                        maxlength="100"
                        value="<?= htmlspecialchars($form["city"]) ?>">
                </div>

                <div>
                    <label for="state">State</label>
                    <input
                        type="text"
                        id="state"
                        name="state"
                        maxlength="100"
                        value="<?= htmlspecialchars($form["state"]) ?>">
                </div>

                <div>
                    <label for="pincode">PIN Code</label>
                    <input
                        type="text"
                        id="pincode"
                        name="pincode"
                        maxlength="10"
                        value="<?= htmlspecialchars($form["pincode"]) ?>">
                </div>

            </div>

            <div class="toolbar">
                <button type="submit" class="btn btn-green">Save Changes</button>
                <a class="btn btn-secondary" href="index.php">Cancel</a>
            </div>

        </form>

    </div>

    <!-- Everything below is shown read only, so the teacher can see what they
         are NOT allowed to change. -->

    <div class="card">

        <h2>Managed by the School</h2>

        <div class="lock-note">
            These details are maintained by the school administrator. Contact
            the school office if any of them needs to be corrected.
        </div>

        <div class="detail-grid" style="margin-top:16px;">

            <div class="detail-item">
                <span>Employee ID</span>
                <strong><?= htmlspecialchars(($profile["employee_code"] !== "") ? $profile["employee_code"] : "-") ?></strong>
            </div>

            <div class="detail-item">
                <span>Full Name</span>
                <strong><?= htmlspecialchars($teacher_name) ?></strong>
            </div>

            <div class="detail-item">
                <span>Designation</span>
                <strong><?= htmlspecialchars(($profile["designation"] !== "") ? $profile["designation"] : "-") ?></strong>
            </div>

            <div class="detail-item">
                <span>Department</span>
                <strong><?= htmlspecialchars(($profile["department"] !== "") ? $profile["department"] : "-") ?></strong>
            </div>

            <div class="detail-item">
                <span>Joining Date</span>
                <strong><?= htmlspecialchars(profileDateLabel($profile["joining_date"])) ?></strong>
            </div>

            <div class="detail-item">
                <span>Role</span>
                <strong><?= htmlspecialchars(($profile["role_name"] !== "") ? $profile["role_name"] : "Teacher") ?></strong>
            </div>

            <div class="detail-item">
                <span>School</span>
                <strong><?= htmlspecialchars(($profile["school_name"] !== "") ? $profile["school_name"] : "-") ?></strong>
            </div>

            <div class="detail-item">
                <span>Assigned Classes</span>
                <strong><?= htmlspecialchars(empty($assigned_class_labels) ? "-" : implode(", ", $assigned_class_labels)) ?></strong>
            </div>

        </div>

    </div>

</div>

</body>

</html>
