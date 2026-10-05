<?php

session_start();

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../auth.php";


// =====================================================
// 0. AUTH GUARD (login token + student role)
// =====================================================

authRequireType($conn, ["student"], "../login.php", "../");


// =====================================================
// 1. LOGIN CHECK
// =====================================================

if (!isset($_SESSION["user_id"])) {

    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION["user_id"];


// =====================================================
// 2. FETCH LOGGED-IN USER + ROLE + SCHOOL
// =====================================================

$sql = "SELECT
            u.id,
            u.user_uid,
            u.school_uid,
            u.role_uid,
            u.username,
            u.name,
            u.email,
            u.mobile,
            u.profile_photo,
            u.user_type,
            u.status,

            r.role_uid AS db_role_uid,
            r.role_name,
            r.status AS role_status,

            s.school_name

        FROM users u

        LEFT JOIN roles r
            ON u.role_uid = r.role_uid

        LEFT JOIN schools s
            ON u.school_uid = s.school_uid

        WHERE u.id = ?

        LIMIT 1";


$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Database query failed: " . $conn->error);
}

$stmt->bind_param("i", $user_id);

$stmt->execute();

$result = $stmt->get_result();


// =====================================================
// 3. USER NOT FOUND
// =====================================================

if ($result->num_rows !== 1) {

    $stmt->close();

    session_unset();
    session_destroy();

    header("Location: ../login.php");
    exit();
}


$user = $result->fetch_assoc();

$stmt->close();


// =====================================================
// 4. USER STATUS CHECK
// =====================================================

if ($user["status"] !== "active") {

    session_unset();
    session_destroy();

    header("Location: ../login.php");
    exit();
}


// =====================================================
// 5. ROLE UID MATCH
// =====================================================

if (
    empty($user["role_uid"]) ||
    empty($user["db_role_uid"]) ||
    $user["role_uid"] !== $user["db_role_uid"]
) {

    session_unset();
    session_destroy();

    header("Location: ../login.php");
    exit();
}


// =====================================================
// 6. ROLE STATUS
// =====================================================

if ($user["role_status"] !== "active") {

    session_unset();
    session_destroy();

    die("Your assigned role is inactive.");
}


// =====================================================
// 7. STUDENT ROLE CHECK
// =====================================================

if (
    strtolower(trim($user["role_name"])) !== "student"
) {

    header("Location: ../login.php");
    exit();
}


// =====================================================
// 8. SCHOOL CHECK
// =====================================================

if (empty($user["school_uid"])) {

    die("School is not assigned to this student.");
}


$school_uid = $user["school_uid"];


// =====================================================
// 9. FETCH FEES FROM DATABASE
// =====================================================
//
// Sirf logged-in student's school ki active fees.
//

$fee_sql = "SELECT
                id,
                fee_uid,
                school_uid,
                fee_name,
                fee_type,
                description,
                amount,
                frequency,
                academic_year,
                due_date,
                late_fee,
                status,
                created_at,
                updated_at

            FROM fees

            WHERE school_uid = ?

            AND status = 'active'

            ORDER BY id ASC";


$fee_stmt = $conn->prepare($fee_sql);

if (!$fee_stmt) {

    die(
        "Fees query failed: " .
        $conn->error
    );
}


$fee_stmt->bind_param(
    "s",
    $school_uid
);

$fee_stmt->execute();

$fees_result = $fee_stmt->get_result();


// =====================================================
// 10. STORE FEES + TOTAL
// =====================================================

$fees = [];

$total_amount = 0;


while ($fee = $fees_result->fetch_assoc()) {

    $fees[] = $fee;

    $total_amount += (float)$fee["amount"];
}


$fee_stmt->close();


// =====================================================
// 11. TOTAL FEE COUNT
// =====================================================

$fee_count = count($fees);


// =====================================================
// 12. ACADEMIC YEAR
// =====================================================

$academic_year = "N/A";

if (!empty($fees)) {

    $academic_year =
        $fees[0]["academic_year"];
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

<title>
    Student Fees
</title>


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
    width: 90%;
    max-width: 1100px;
    margin: 40px auto;
}


/* =========================================
   HEADER
========================================= */

.header {
    background: white;
    padding: 25px;
    border-radius: 12px;
    margin-bottom: 25px;

    box-shadow:
        0 3px 12px
        rgba(0, 0, 0, 0.08);
}


.header h1 {
    font-size: 30px;
    margin-bottom: 8px;
}


.header h1 span {
    color: #007bff;
}


.header p {
    color: #777;
}


/* =========================================
   SUMMARY
========================================= */

.summary {

    display: grid;

    grid-template-columns:
        repeat(auto-fit, minmax(220px, 1fr));

    gap: 18px;

    margin-bottom: 25px;
}


.summary-card {

    background: white;

    padding: 20px;

    border-radius: 10px;

    box-shadow:
        0 3px 12px
        rgba(0, 0, 0, 0.08);
}


.summary-card label {

    display: block;

    color: #777;

    font-size: 13px;

    margin-bottom: 8px;
}


.summary-card strong {

    font-size: 24px;
}


/* =========================================
   FEES CARD
========================================= */

.fees-card {

    background: white;

    padding: 30px;

    border-radius: 12px;

    box-shadow:
        0 3px 12px
        rgba(0, 0, 0, 0.08);
}


.fees-card h2 {

    margin-bottom: 20px;
}


/* =========================================
   FEE ITEM
========================================= */

.fee-item {

    background: #f8f9fa;

    padding: 20px;

    border-radius: 10px;

    margin-bottom: 15px;

    border: 1px solid #eee;
}


.fee-header {

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 20px;

    margin-bottom: 15px;
}


.fee-name {

    font-size: 18px;

    font-weight: bold;
}


.amount {

    font-size: 20px;

    font-weight: bold;

    color: #007bff;
}


/* =========================================
   FEE DETAILS
========================================= */

.fee-details {

    display: grid;

    grid-template-columns: 1fr 1fr;

    gap: 15px;
}


.fee-detail {

    padding: 12px;

    background: white;

    border-radius: 7px;
}


.fee-detail label {

    display: block;

    color: #777;

    font-size: 12px;

    margin-bottom: 5px;
}


.fee-detail strong {

    font-size: 14px;
}


/* =========================================
   GREEN STATUS
========================================= */

.fee-status {

    color: #28a745;

    font-weight: bold;
}


/* =========================================
   DESCRIPTION
========================================= */

.description {

    margin-top: 15px;

    padding-top: 12px;

    border-top: 1px solid #eee;

    color: #666;

    font-size: 14px;

    line-height: 1.5;
}


/* =========================================
   EMPTY
========================================= */

.empty {

    text-align: center;

    padding: 40px;

    color: #777;
}


/* =========================================
   BACK
========================================= */

.back {

    display: inline-block;

    margin-top: 25px;

    padding: 11px 20px;

    background: #6c757d;

    color: white;

    text-decoration: none;

    border-radius: 6px;
}


.back:hover {

    background: #5a6268;
}


/* =========================================
   MOBILE
========================================= */

@media(max-width: 700px) {

    .fee-header {

        flex-direction: column;

        align-items: flex-start;
    }


    .fee-details {

        grid-template-columns: 1fr;
    }

}

</style>

</head>


<body>


<div class="container">


    <!-- =====================================
         HEADER
    ====================================== -->

    <div class="header">

        <h1>

            Student Fees -

            <span>

                <?= htmlspecialchars(
                    $user["name"]
                ) ?>

            </span>

        </h1>


        <p>

            <?= htmlspecialchars(
                $user["school_name"] ?? "School"
            ) ?>

        </p>

    </div>



    <!-- =====================================
         SUMMARY
    ====================================== -->

    <div class="summary">


        <!-- TOTAL FEE TYPES -->

        <div class="summary-card">

            <label>
                Total Fee Types
            </label>

            <strong>

                <?= $fee_count ?>

            </strong>

        </div>


        <!-- TOTAL AMOUNT -->

        <div class="summary-card">

            <label>
                Total Fee Amount
            </label>

            <strong>

                ₹<?= number_format(
                    $total_amount,
                    2
                ) ?>

            </strong>

        </div>


        <!-- ACADEMIC YEAR -->

        <div class="summary-card">

            <label>
                Academic Year
            </label>

            <strong style="font-size:18px;">

                <?= htmlspecialchars(
                    $academic_year
                ) ?>

            </strong>

        </div>


    </div>



    <!-- =====================================
         ALL FEES
    ====================================== -->

    <div class="fees-card">

        <h2>
            Fee Details
        </h2>


        <?php if (!empty($fees)): ?>


            <?php foreach ($fees as $fee): ?>


                <div class="fee-item">


                    <!-- =================================
                         FEE NAME + AMOUNT
                    ================================== -->

                    <div class="fee-header">


                        <div class="fee-name">

                            <?= htmlspecialchars(
                                $fee["fee_name"]
                            ) ?>

                        </div>


                        <div class="amount">

                            ₹<?= number_format(
                                (float)$fee["amount"],
                                2
                            ) ?>

                        </div>


                    </div>



                    <!-- =================================
                         FEE DETAILS
                    ================================== -->

                    <div class="fee-details">


                        <!-- FEE UID -->

                        <div class="fee-detail">

                            <label>
                                Fee UID
                            </label>

                            <strong>

                                <?= htmlspecialchars(
                                    $fee["fee_uid"]
                                ) ?>

                            </strong>

                        </div>


                        <!-- FEE TYPE -->

                        <div class="fee-detail">

                            <label>
                                Fee Type
                            </label>

                            <strong>

                                <?= htmlspecialchars(
                                    $fee["fee_type"]
                                ) ?>

                            </strong>

                        </div>


                        <!-- FREQUENCY -->

                        <div class="fee-detail">

                            <label>
                                Frequency
                            </label>

                            <strong>

                                <?= htmlspecialchars(
                                    $fee["frequency"]
                                ) ?>

                            </strong>

                        </div>


                        <!-- ACADEMIC YEAR -->

                        <div class="fee-detail">

                            <label>
                                Academic Year
                            </label>

                            <strong>

                                <?= htmlspecialchars(
                                    $fee["academic_year"]
                                ) ?>

                            </strong>

                        </div>


                        <!-- DUE DATE -->

                        <div class="fee-detail">

                            <label>
                                Due Date
                            </label>

                            <strong>

                                <?= !empty(
                                    $fee["due_date"]
                                )
                                ?
                                htmlspecialchars(
                                    $fee["due_date"]
                                )
                                :
                                "N/A"
                                ?>

                            </strong>

                        </div>


                        <!-- LATE FEE -->

                        <div class="fee-detail">

                            <label>
                                Late Fee
                            </label>

                            <strong>

                                ₹<?= number_format(
                                    (float)$fee["late_fee"],
                                    2
                                ) ?>

                            </strong>

                        </div>


                        <!-- SCHOOL UID -->

                        <div class="fee-detail">

                            <label>
                                School UID
                            </label>

                            <strong>

                                <?= htmlspecialchars(
                                    $fee["school_uid"]
                                ) ?>

                            </strong>

                        </div>


                        <!-- STATUS -->

                        <div class="fee-detail">

                            <label>
                                Status
                            </label>

                            <strong class="fee-status">

                                <?= htmlspecialchars(
                                    $fee["status"]
                                ) ?>

                            </strong>

                        </div>


                    </div>



                    <!-- =================================
                         DESCRIPTION
                    ================================== -->

                    <?php if (
                        !empty(
                            $fee["description"]
                        )
                    ): ?>

                        <div class="description">

                            <strong>
                                Description:
                            </strong>

                            <?= htmlspecialchars(
                                $fee["description"]
                            ) ?>

                        </div>

                    <?php endif; ?>


                </div>


            <?php endforeach; ?>


        <?php else: ?>


            <div class="empty">

                No active fees found for your school.

            </div>


        <?php endif; ?>


        <!-- BACK -->

        <a
            href="../students/dashboard.php"
            class="back"
        >

            Back to Student Dashboard

        </a>


    </div>


</div>


</body>

</html>