<?php

/*
 |--------------------------------------------------------------------------
 | TEACHER - PROFILE : CHANGE PASSWORD
 |--------------------------------------------------------------------------
 | The teacher changes their OWN password only, and only after proving they
 | know the current one.
 |
 | The password is never displayed anywhere: the current hash is read only
 | for password_verify(), and the new one is written only as a
 | password_hash() value. It is never echoed, logged or stored in session.
 */

require_once __DIR__ . "/includes/profile_common.php";

/* CSRF - sirf POST verify hota hai */
csrfVerify();

$errors = [];

$current_password = "";
$new_password     = "";
$confirm_password = "";

if (($_SERVER["REQUEST_METHOD"] ?? "") === "POST") {

    $current_password = (string)($_POST["current_password"] ?? "");
    $new_password     = (string)($_POST["new_password"] ?? "");
    $confirm_password = (string)($_POST["confirm_password"] ?? "");

    /* 1. the current hash, used only for verification and never displayed */
    $sql = "SELECT password
            FROM users
            WHERE user_uid = ?
              AND school_uid = ?
              AND user_type = 'teacher'
            LIMIT 1";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("Could not verify your password. Please try again.");
    }

    $stmt->bind_param("ss", $user_uid, $school_uid);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$account) {
        die("Account not found.");
    }

    if ($current_password === "" || $new_password === "" || $confirm_password === "") {
        $errors[] = "Please fill in all three password fields.";
    }

    if (empty($errors) && !password_verify($current_password, (string)$account["password"])) {
        $errors[] = "Your current password is not correct.";
        $err_key  = "password_wrong";
    }

    if (empty($errors) && mb_strlen($new_password) < 8) {
        $errors[] = "The new password must be at least 8 characters long.";
        $err_key  = "password_weak";
    }

    if (empty($errors) && $new_password !== $confirm_password) {
        $errors[] = "The new password and the confirmation do not match.";
    }

    if (empty($errors) && $new_password === $current_password) {
        $errors[] = "The new password must be different from the current one.";
        $err_key  = "password_same";
    }

    if (empty($errors)) {

        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);

        if ($new_hash === false) {
            $errors[] = "The password could not be changed. Please try again.";
            $err_key  = "password_fail";
        } else {

            /* only this teacher's own account row is touched */
            $sql = "UPDATE users
                    SET password = ?
                    WHERE user_uid = ?
                      AND school_uid = ?
                      AND user_type = 'teacher'";

            $stmt = $conn->prepare($sql);

            if (!$stmt) {
                die("Could not change the password. Please try again.");
            }

            $stmt->bind_param("sss", $new_hash, $user_uid, $school_uid);
            $saved = $stmt->execute();
            $stmt->close();

            if ($saved) {

                /* drop the values so they cannot linger in memory */
                $current_password = "";
                $new_password     = "";
                $confirm_password = "";

                header("Location: index.php?ok=password");
                exit();
            }

            $errors[] = "The password could not be changed. Please try again.";
            $err_key  = "password_fail";
        }
    }
}

$err_key = ($err_key ?? "validation");


?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Change Password</title>

<style>
<?= profileModuleStyles() ?>
</style>

</head>

<body>

<div class="container">

    <div class="card">

        <a class="back-link" href="index.php">&larr; Back to Profile</a>

        <h1>Change Password</h1>

        <div class="msg msg-info">
            You are changing the password of your own account
            (<strong><?= htmlspecialchars($profile["username"]) ?></strong>).
            The new password is stored securely as a one way hash - it can
            never be displayed or recovered, only replaced again.
        </div>

        <?php if (!empty($errors)): ?>
            <div class="msg msg-err">
                <ul style="margin:0;padding-left:18px;">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="change-password.php" autocomplete="off">

            <?php echo csrfField(); ?>

            <div class="form-grid">

                <div style="grid-column: 1 / -1;">
                    <label for="current_password">Current Password</label>
                    <input
                        type="password"
                        id="current_password"
                        name="current_password"
                        maxlength="100"
                        required
                        autocomplete="current-password">
                </div>

                <div style="grid-column: 1 / -1;">
                    <label for="new_password">New Password</label>
                    <input
                        type="password"
                        id="new_password"
                        name="new_password"
                        maxlength="100"
                        required
                        autocomplete="new-password">
                    <div class="hint">Use at least 8 characters. A mix of letters and numbers is safer.</div>
                </div>

                <div style="grid-column: 1 / -1;">
                    <label for="confirm_password">Confirm New Password</label>
                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        maxlength="100"
                        required
                        autocomplete="new-password">
                </div>

            </div>

            <div class="toolbar">
                <button type="submit" class="btn btn-green">Change Password</button>
                <a class="btn btn-secondary" href="index.php">Cancel</a>
            </div>

        </form>

    </div>

</div>

</body>

</html>

