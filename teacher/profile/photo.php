<?php

/*
 |--------------------------------------------------------------------------
 | TEACHER - PROFILE : UPDATE PROFILE PHOTO
 |--------------------------------------------------------------------------
 | Optional feature. The teacher may replace their own photo, and nothing
 | else: the file is validated, stored under uploads/teachers/ and only
 | `teachers.profile_photo` and `users.profile_photo` are written.
 |
 | No other column of either table is touched by this page.
 */

require_once __DIR__ . "/includes/profile_common.php";

/* CSRF - sirf POST verify hota hai */
csrfVerify();

$errors = [];

/* upload folder, relative to the project root */
$upload_relative_dir = "uploads/teachers";
$upload_root         = __DIR__ . "/../../" . $upload_relative_dir;

if (!is_dir($upload_root)) {
    @mkdir($upload_root, 0755, true);
}

/* only these image types are accepted */
$allowed_types = [
    "image/jpeg" => "jpg",
    "image/pjpeg" => "jpg",
    "image/png"  => "png",
    "image/webp" => "webp",
];

$max_bytes = 2 * 1024 * 1024; /* 2 MB */

if (($_SERVER["REQUEST_METHOD"] ?? "") === "POST") {

    if (!isset($_FILES["profile_photo"]) || !is_array($_FILES["profile_photo"])) {

        $errors[] = "Please choose an image to upload.";

    } else {

        $file = $_FILES["profile_photo"];

        if ($file["error"] !== UPLOAD_ERR_OK) {

            $errors[] = "The file could not be uploaded. Please try again.";

        } elseif (!is_uploaded_file($file["tmp_name"])) {

            $errors[] = "The upload could not be verified. Please try again.";

        } elseif ($file["size"] > $max_bytes) {

            $errors[] = "The image must be 2 MB or smaller.";
            $err_key  = "photo_type";

        } else {

            /* the real type is read from the file content, not from the name */
            $detected = @mime_content_type($file["tmp_name"]);

            if (!isset($allowed_types[$detected])) {
                $errors[] = "Only JPG, PNG or WEBP images can be used as a profile photo.";
                $err_key  = "photo_type";
            } else {

                $extension = $allowed_types[$detected];

                /* the file name is generated, never taken from the upload */
                $new_name = "TCH_" . $teacher_uid . "_" . time() . "." . $extension;
                $target   = $upload_root . DIRECTORY_SEPARATOR . $new_name;

                if (!@move_uploaded_file($file["tmp_name"], $target)) {

                    $errors[] = "The photo could not be saved. Please try again.";
                    $err_key  = "photo_fail";

                } else {

                    $relative_path = $upload_relative_dir . "/" . $new_name;

                    /* 1. the teacher record */
                    $sql = "UPDATE teachers
                            SET profile_photo = ?
                            WHERE teacher_uid = ?
                              AND school_uid = ?
                              AND user_uid = ?";

                    $stmt = $conn->prepare($sql);

                    if ($stmt) {

                        $stmt->bind_param("ssss", $relative_path, $teacher_uid, $school_uid, $user_uid);
                        $stmt->execute();
                        $stmt->close();
                    }

                    /* 2. the login account, so the dashboard shows it too */
                    $sql = "UPDATE users
                            SET profile_photo = ?
                            WHERE user_uid = ?
                              AND school_uid = ?";

                    $stmt = $conn->prepare($sql);

                    if ($stmt) {

                        $stmt->bind_param("sss", $relative_path, $user_uid, $school_uid);
                        $stmt->execute();
                        $stmt->close();
                    }

                    $_SESSION["profile_photo"] = $relative_path;

                    header("Location: index.php?ok=photo");
                    exit();
                }
            }
        }
    }
}

$err_key        = ($err_key ?? "photo_fail");
$current_photo   = profilePhotoPath($profile);


?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Update Profile Photo</title>

<style>
<?= profileModuleStyles() ?>
</style>

</head>

<body>

<div class="container">

    <div class="card">

        <a class="back-link" href="index.php">&larr; Back to Profile</a>

        <h1>Update Profile Photo</h1>

        <div class="msg msg-info">
            Upload a JPG, PNG or WEBP image of up to 2 MB. The picture is
            shown next to your name on the dashboard and on the profile page.
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

        <div class="profile-head" style="margin-bottom:20px;">

            <div class="avatar">
                <?php if ($current_photo !== ""): ?>
                    <img src="../../<?= htmlspecialchars($current_photo) ?>" alt="Current Photo">
                <?php else: ?>
                    <?= htmlspecialchars(profileInitials($teacher_name)) ?>
                <?php endif; ?>
            </div>

            <div class="head-info">
                <h1 style="font-size:20px;"><?= htmlspecialchars($teacher_name) ?></h1>
                <div class="head-sub">Current profile photo</div>
            </div>

        </div>

        <form method="post" action="photo.php" enctype="multipart/form-data">

            <?php echo csrfField(); ?>

            <div class="form-grid">

                <div style="grid-column: 1 / -1;">
                    <label for="profile_photo">Choose a photo</label>
                    <input type="file" id="profile_photo" name="profile_photo" accept="image/jpeg,image/png,image/webp" required>
                </div>

            </div>

            <div class="toolbar">
                <button type="submit" class="btn btn-green">Upload Photo</button>
                <a class="btn btn-secondary" href="index.php">Cancel</a>
            </div>

        </form>

    </div>

</div>

</body>

</html>

