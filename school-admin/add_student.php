<?php

session_start();

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../auth.php";


// =====================================================
// 0. AUTH GUARD (login token + school_admin role)
// =====================================================

authRequireType($conn, ["school_admin"], "../login.php", "../");

/* CSRF - sirf POST verify hota hai (GET bilkul same) */
csrfVerify();


// =====================================================
// 1. LOGIN CHECK
// =====================================================

if (!isset($_SESSION["user_id"])) {

    header("Location: ../login.php");
    exit();

}


$user_id = $_SESSION["user_id"];


// =====================================================
// 3. FETCH LOGGED-IN ADMIN SCHOOL
// =====================================================

$sql = "
    SELECT
        id,
        school_uid,
        name,
        username
    FROM users
    WHERE id = ?
    AND user_type = 'school_admin'
    AND status = 'active'
    LIMIT 1
";


$stmt = $conn->prepare($sql);


if (!$stmt) {

    die("Database query failed: " . $conn->error);

}


$stmt->bind_param("i", $user_id);

$stmt->execute();

$result = $stmt->get_result();


if ($result->num_rows !== 1) {

    $stmt->close();

    session_unset();
    session_destroy();

    header("Location: ../login.php");
    exit();

}


$admin = $result->fetch_assoc();

$stmt->close();


$school_uid = $admin["school_uid"];


// =====================================================
// 4. FETCH SCHOOL NAME
// =====================================================

$school_name = "School";

$sql = "
    SELECT school_name
    FROM schools
    WHERE school_uid = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if ($stmt) {

    $stmt->bind_param("s", $school_uid);

    $stmt->execute();

    $school_result = $stmt->get_result();

    if ($school_result->num_rows === 1) {

        $school = $school_result->fetch_assoc();

        $school_name = $school["school_name"];

    }

    $stmt->close();
}


// =====================================================
// 5. STUDENT ROLE UID
// =====================================================
//
// Screenshot ke according:
// student = ROLE000000004
//
// Agar tumhare roles table mein student ka role_uid
// different hai, sirf is value ko change karo.
//

$student_role_uid = "ROLE000000004";


// =====================================================
// 6. FORM SUBMISSION
// =====================================================

$error = "";
$success = "";


if (isset($_POST["add_student"])) {


    // -------------------------------------------------
    // BASIC DETAILS
    // -------------------------------------------------

    $student_name = trim($_POST["student_name"] ?? "");

    $admission_no = trim($_POST["admission_no"] ?? "");

    $roll_no = trim($_POST["roll_no"] ?? "");

    $gender = trim($_POST["gender"] ?? "");

    $dob = trim($_POST["dob"] ?? "");

    $blood_group = trim($_POST["blood_group"] ?? "");


    // -------------------------------------------------
    // PARENT DETAILS
    // -------------------------------------------------

    $father_name = trim($_POST["father_name"] ?? "");

    $mother_name = trim($_POST["mother_name"] ?? "");

    $father_mobile = trim($_POST["father_mobile"] ?? "");

    $mother_mobile = trim($_POST["mother_mobile"] ?? "");


    // -------------------------------------------------
    // CONTACT DETAILS
    // -------------------------------------------------

    $email = trim($_POST["email"] ?? "");

    $mobile = trim($_POST["mobile"] ?? "");

    $address = trim($_POST["address"] ?? "");

    $city = trim($_POST["city"] ?? "");

    $state = trim($_POST["state"] ?? "");

    $pincode = trim($_POST["pincode"] ?? "");


    // -------------------------------------------------
    // LOGIN DETAILS
    // -------------------------------------------------

    $username = trim($_POST["username"] ?? "");

    $password = $_POST["password"] ?? "";


    // -------------------------------------------------
    // VALIDATION
    // -------------------------------------------------

    if (
        $student_name === "" ||
        $admission_no === "" ||
        $roll_no === "" ||
        $gender === "" ||
        $dob === "" ||
        $father_name === "" ||
        $mother_name === "" ||
        $father_mobile === "" ||
        $username === "" ||
        $password === ""
    ) {

        $error = "Please fill all required fields.";

    }


    // -------------------------------------------------
    // EMAIL VALIDATION
    // -------------------------------------------------

    if (
        $error === "" &&
        $email !== "" &&
        !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {

        $error = "Please enter a valid email address.";

    }


    // -------------------------------------------------
    // USERNAME CHECK
    // -------------------------------------------------

    if ($error === "") {

        $sql = "
            SELECT id
            FROM users
            WHERE username = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {

            $error = "Database error.";

        } else {

            $stmt->bind_param("s", $username);

            $stmt->execute();

            $result = $stmt->get_result();

            if ($result->num_rows > 0) {

                $error = "Username already exists.";

            }

            $stmt->close();

        }

    }


    // -------------------------------------------------
    // ADMISSION NUMBER CHECK
    // -------------------------------------------------

    if ($error === "") {

        $sql = "
            SELECT id
            FROM students
            WHERE school_uid = ?
            AND admission_no = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {

            $error = "Database error.";

        } else {

            $stmt->bind_param(
                "ss",
                $school_uid,
                $admission_no
            );

            $stmt->execute();

            $result = $stmt->get_result();

            if ($result->num_rows > 0) {

                $error = "Admission number already exists in this school.";

            }

            $stmt->close();

        }

    }


    // =================================================
    // 7. INSERT STUDENT
    // =================================================

    if ($error === "") {


        try {


            // Start transaction

            $conn->begin_transaction();


            // -----------------------------------------
            // GENERATE USER UID
            // -----------------------------------------

            $sql = "
                SELECT user_uid
                FROM users
                WHERE user_uid LIKE 'USR%'
                ORDER BY id DESC
                LIMIT 1
            ";

            $stmt = $conn->prepare($sql);

            $last_number = 0;

            if ($stmt) {

                $stmt->execute();

                $result = $stmt->get_result();

                if ($result->num_rows === 1) {

                    $last_user = $result->fetch_assoc();

                    $last_uid = $last_user["user_uid"];

                    $number = (int) preg_replace(
                        "/[^0-9]/",
                        "",
                        $last_uid
                    );

                    $last_number = $number;

                }

                $stmt->close();

            }


            $new_user_number = $last_number + 1;

            $new_user_uid =
                "USR" .
                str_pad(
                    $new_user_number,
                    8,
                    "0",
                    STR_PAD_LEFT
                );


            // -----------------------------------------
            // GENERATE STUDENT UID
            // -----------------------------------------

            $sql = "
                SELECT student_uid
                FROM students
                WHERE student_uid LIKE 'STU%'
                ORDER BY id DESC
                LIMIT 1
            ";

            $stmt = $conn->prepare($sql);

            $last_student_number = 0;

            if ($stmt) {

                $stmt->execute();

                $result = $stmt->get_result();

                if ($result->num_rows === 1) {

                    $last_student = $result->fetch_assoc();

                    $last_student_uid =
                        $last_student["student_uid"];

                    $number = (int) preg_replace(
                        "/[^0-9]/",
                        "",
                        $last_student_uid
                    );

                    $last_student_number = $number;

                }

                $stmt->close();

            }


            $new_student_number =
                $last_student_number + 1;


            $student_uid =
                "STU" .
                str_pad(
                    $new_student_number,
                    8,
                    "0",
                    STR_PAD_LEFT
                );


            // -----------------------------------------
            // PASSWORD HASH
            // -----------------------------------------

            $password_hash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );


            // -----------------------------------------
            // INSERT INTO USERS
            // -----------------------------------------

            $sql = "
                INSERT INTO users
                (
                    user_uid,
                    school_uid,
                    role_uid,
                    username,
                    password,
                    name,
                    email,
                    mobile,
                    user_type,
                    status
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    'student',
                    'active'
                )
            ";


            $stmt = $conn->prepare($sql);


            if (!$stmt) {

                throw new Exception(
                    "Unable to prepare user query: " .
                    $conn->error
                );

            }


            $stmt->bind_param(
                "ssssssss",
                $new_user_uid,
                $school_uid,
                $student_role_uid,
                $username,
                $password_hash,
                $student_name,
                $email,
                $mobile
            );


            if (!$stmt->execute()) {

                throw new Exception(
                    "Unable to create student login: " .
                    $stmt->error
                );

            }


            // Get newly created user ID

            $new_user_id = $conn->insert_id;


            $stmt->close();


            // -----------------------------------------
            // INSERT INTO STUDENTS
            // -----------------------------------------

            $admission_date = date("Y-m-d");


            $sql = "
                INSERT INTO students
                (
                    student_uid,
                    school_uid,
                    user_id,
                    admission_no,
                    roll_no,
                    student_name,
                    gender,
                    dob,
                    blood_group,
                    father_name,
                    mother_name,
                    father_mobile,
                    mother_mobile,
                    email,
                    address,
                    city,
                    state,
                    pincode,
                    admission_date,
                    status
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    'active'
                )
            ";


            $stmt = $conn->prepare($sql);


            if (!$stmt) {

                throw new Exception(
                    "Unable to prepare student query: " .
                    $conn->error
                );

            }


            $stmt->bind_param(
                "ssisssssssssssssssss",
                $student_uid,
                $school_uid,
                $new_user_id,
                $admission_no,
                $roll_no,
                $student_name,
                $gender,
                $dob,
                $blood_group,
                $father_name,
                $mother_name,
                $father_mobile,
                $mother_mobile,
                $email,
                $address,
                $city,
                $state,
                $pincode,
                $admission_date
            );


            if (!$stmt->execute()) {

                throw new Exception(
                    "Unable to create student record: " .
                    $stmt->error
                );

            }


            $stmt->close();


            // -----------------------------------------
            // COMMIT
            // -----------------------------------------

            $conn->commit();


            $success =
                "Student added successfully. " .
                "Student UID: " .
                $student_uid;


            // Clear form fields

            $_POST = [];


        } catch (Exception $e) {


            // Rollback

            $conn->rollback();


            $error = $e->getMessage();

        }

    }

}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Add Student</title>


    <style>

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: Arial, sans-serif;
        }


        body {
            background: #f4f6f8;
            color: #222;
        }


        .container {
            width: 92%;
            max-width: 1000px;
            margin: 35px auto;
        }


        .header {
            background: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.08);
        }


        .header h1 {
            margin-bottom: 8px;
        }


        .header p {
            color: #777;
        }


        .school-name {
            color: #28a745;
            font-weight: bold;
        }


        .card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.08);
        }


        .section-title {
            font-size: 20px;
            margin: 25px 0 18px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }


        .section-title:first-child {
            margin-top: 0;
        }


        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }


        .form-group {
            display: flex;
            flex-direction: column;
        }


        .form-group.full {
            grid-column: 1 / -1;
        }


        label {
            font-size: 13px;
            color: #555;
            margin-bottom: 7px;
            font-weight: bold;
        }


        input,
        select,
        textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 7px;
            font-size: 14px;
            outline: none;
        }


        input:focus,
        select:focus,
        textarea:focus {
            border-color: #28a745;
        }


        textarea {
            resize: vertical;
            min-height: 90px;
        }


        .required {
            color: #dc3545;
        }


        .school-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }


        .school-box strong {
            color: #28a745;
        }


        .error {
            background: #f8d7da;
            color: #842029;
            padding: 13px;
            border-radius: 7px;
            margin-bottom: 20px;
        }


        .success {
            background: #d1e7dd;
            color: #0f5132;
            padding: 13px;
            border-radius: 7px;
            margin-bottom: 20px;
        }


        .actions {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            margin-top: 30px;
        }


        .btn {
            border: none;
            padding: 13px 22px;
            border-radius: 7px;
            text-decoration: none;
            cursor: pointer;
            font-weight: bold;
            font-size: 14px;
        }


        .btn-back {
            background: #6c757d;
            color: white;
        }


        .btn-submit {
            background: #28a745;
            color: white;
        }


        .btn-submit:hover {
            background: #218838;
        }


        .btn-back:hover {
            background: #5c636a;
        }


        @media(max-width: 700px) {

            .container {
                width: 95%;
            }


            .form-grid {
                grid-template-columns: 1fr;
            }


            .form-group.full {
                grid-column: auto;
            }


            .actions {
                flex-direction: column;
            }


            .btn {
                width: 100%;
                text-align: center;
            }

        }

    </style>

</head>


<body>


<div class="container">


    <!-- =========================================
         HEADER
    ========================================== -->

    <div class="header">

        <h1>
            Add Student
        </h1>

        <p>
            Add a new student to
            <span class="school-name">
                <?= htmlspecialchars($school_name) ?>
            </span>
        </p>

    </div>


    <!-- =========================================
         FORM CARD
    ========================================== -->

    <div class="card">


        <?php if ($error !== ""): ?>

            <div class="error">
                <?= htmlspecialchars($error) ?>
            </div>

        <?php endif; ?>


        <?php if ($success !== ""): ?>

            <div class="success">
                <?= htmlspecialchars($success) ?>
            </div>

        <?php endif; ?>


        <!-- SCHOOL -->

        <div class="school-box">

            <strong>School:</strong>

            <?= htmlspecialchars($school_name) ?>

            <br>

            <strong>School UID:</strong>

            <?= htmlspecialchars($school_uid) ?>

        </div>


        <form
            method="POST"
            action=""
        >

            <?php echo csrfField(); ?>

            <!-- =====================================
                 STUDENT BASIC DETAILS
            ====================================== -->

            <h2 class="section-title">
                Student Details
            </h2>


            <div class="form-grid">


                <div class="form-group">

                    <label>
                        Student Name
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        name="student_name"
                        value="<?= htmlspecialchars($_POST["student_name"] ?? "") ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Admission Number
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        name="admission_no"
                        value="<?= htmlspecialchars($_POST["admission_no"] ?? "") ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Roll Number
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        name="roll_no"
                        value="<?= htmlspecialchars($_POST["roll_no"] ?? "") ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Gender
                        <span class="required">*</span>
                    </label>

                    <select name="gender" required>

                        <option value="">
                            Select Gender
                        </option>

                        <option
                            value="male"
                            <?= (($_POST["gender"] ?? "") === "male")
                                ? "selected"
                                : "" ?>
                        >
                            Male
                        </option>

                        <option
                            value="female"
                            <?= (($_POST["gender"] ?? "") === "female")
                                ? "selected"
                                : "" ?>
                        >
                            Female
                        </option>

                    </select>

                </div>


                <div class="form-group">

                    <label>
                        Date of Birth
                        <span class="required">*</span>
                    </label>

                    <input
                        type="date"
                        name="dob"
                        value="<?= htmlspecialchars($_POST["dob"] ?? "") ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Blood Group
                    </label>

                    <input
                        type="text"
                        name="blood_group"
                        placeholder="Example: B+"
                        value="<?= htmlspecialchars($_POST["blood_group"] ?? "") ?>"
                    >

                </div>


            </div>


            <!-- =====================================
                 PARENT DETAILS
            ====================================== -->

            <h2 class="section-title">
                Parent Details
            </h2>


            <div class="form-grid">


                <div class="form-group">

                    <label>
                        Father Name
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        name="father_name"
                        value="<?= htmlspecialchars($_POST["father_name"] ?? "") ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Mother Name
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        name="mother_name"
                        value="<?= htmlspecialchars($_POST["mother_name"] ?? "") ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Father Mobile
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        name="father_mobile"
                        value="<?= htmlspecialchars($_POST["father_mobile"] ?? "") ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Mother Mobile
                    </label>

                    <input
                        type="text"
                        name="mother_mobile"
                        value="<?= htmlspecialchars($_POST["mother_mobile"] ?? "") ?>"
                    >

                </div>


            </div>


            <!-- =====================================
                 CONTACT DETAILS
            ====================================== -->

            <h2 class="section-title">
                Contact Details
            </h2>


            <div class="form-grid">


                <div class="form-group">

                    <label>
                        Student Email
                    </label>

                    <input
                        type="email"
                        name="email"
                        value="<?= htmlspecialchars($_POST["email"] ?? "") ?>"
                    >

                </div>


                <div class="form-group">

                    <label>
                        Student Mobile
                    </label>

                    <input
                        type="text"
                        name="mobile"
                        value="<?= htmlspecialchars($_POST["mobile"] ?? "") ?>"
                    >

                </div>


                <div class="form-group full">

                    <label>
                        Address
                    </label>

                    <textarea
                        name="address"
                    ><?= htmlspecialchars($_POST["address"] ?? "") ?></textarea>

                </div>


                <div class="form-group">

                    <label>
                        City
                    </label>

                    <input
                        type="text"
                        name="city"
                        value="<?= htmlspecialchars($_POST["city"] ?? "") ?>"
                    >

                </div>


                <div class="form-group">

                    <label>
                        State
                    </label>

                    <input
                        type="text"
                        name="state"
                        value="<?= htmlspecialchars($_POST["state"] ?? "") ?>"
                    >

                </div>


                <div class="form-group">

                    <label>
                        Pincode
                    </label>

                    <input
                        type="text"
                        name="pincode"
                        value="<?= htmlspecialchars($_POST["pincode"] ?? "") ?>"
                    >

                </div>


            </div>


            <!-- =====================================
                 LOGIN DETAILS
            ====================================== -->

            <h2 class="section-title">
                Student Login Details
            </h2>


            <div class="form-grid">


                <div class="form-group">

                    <label>
                        Username
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        name="username"
                        value="<?= htmlspecialchars($_POST["username"] ?? "") ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Password
                        <span class="required">*</span>
                    </label>

                    <input
                        type="password"
                        name="password"
                        required
                    >

                </div>


            </div>


            <!-- =====================================
                 BUTTONS
            ====================================== -->

            <div class="actions">


                <a
                    href="dashboard.php"
                    class="btn btn-back"
                >
                    ← Back to Dashboard
                </a>


                <button
                    type="submit"
                    name="add_student"
                    class="btn btn-submit"
                >
                    + Add Student
                </button>


            </div>


        </form>


    </div>


</div>


</body>

</html>