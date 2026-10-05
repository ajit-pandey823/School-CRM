<?php
/**
 * SCHOOL MANAGEMENT SaaS
 * Complete database functions
 *
 * Expected location:
 * school-admin/function.php
 *
 * Database connection:
 * ../config/db.php
 *
 * This file matches the currently created school_management database:
 * schools, subscription_plans, school_subscriptions, roles, permissions,
 * users, role_permissions, user_permissions, academic_years, classes,
 * sections, subjects, teachers, students, student_academic, parents,
 * student_parents, teacher_subjects, student_attendance,
 * teacher_attendance, fee_types.
 */

require_once __DIR__ . "/../config/db.php";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/* =========================================================
   1. COMMON HELPERS
========================================================= */

function executeStatement($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return false;
    }

    if ($types !== "" && !empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return false;
    }

    return $stmt;
}

function fetchAllRows($stmt)
{
    $result = mysqli_stmt_get_result($stmt);

    if (!$result) {
        return [];
    }

    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

function fetchSingleRow($stmt)
{
    $result = mysqli_stmt_get_result($stmt);

    if (!$result) {
        return false;
    }

    return mysqli_fetch_assoc($result);
}

/*
 * UID columns in the current database are normal VARCHAR columns,
 * not GENERATED columns. Therefore new records need a UID manually.
 */
function generateUid($conn, $table, $uidColumn, $prefix)
{
    $allowed = [
        "schools"              => "school_uid",
        "subscription_plans"   => "plan_uid",
        "school_subscriptions" => "subscription_uid",
        "roles"                => "role_uid",
        "permissions"          => "permission_uid",
        "users"                => "user_uid",
        "role_permissions"     => "role_permission_uid",
        "user_permissions"     => "user_permission_uid",
        "academic_years"       => "academic_year_uid",
        "classes"              => "class_uid",
        "sections"             => "section_uid",
        "subjects"             => "subject_uid",
        "teachers"             => "teacher_uid",
        "students"             => "student_uid",
        "student_academic"     => "academic_uid",
        "parents"              => "parent_uid",
        "student_parents"      => "relation_uid",
        "teacher_subjects"     => "assignment_uid",
        "student_attendance"   => "attendance_uid",
        "teacher_attendance"   => "teacher_attendance_uid",
        "fee_types"            => "fee_type_uid"
    ];

    if (!isset($allowed[$table]) || $allowed[$table] !== $uidColumn) {
        return false;
    }

    $sql = "SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM `$table`";
    $result = mysqli_query($conn, $sql);

    if (!$result) {
        return false;
    }

    $row = mysqli_fetch_assoc($result);
    $nextId = (int)$row["next_id"];

    return $prefix . str_pad((string)$nextId, 8, "0", STR_PAD_LEFT);
}

function getCurrentSchoolUid()
{
    return $_SESSION["school_uid"] ?? null;
}


/* =========================================================
   2. AUTHENTICATION / SESSION
========================================================= */

function isLoggedIn()
{
    return isset($_SESSION["user_uid"]);
}

function getLoggedInUserUid()
{
    return $_SESSION["user_uid"] ?? null;
}

function getLoggedInSchoolUid()
{
    return $_SESSION["school_uid"] ?? null;
}

function getLoggedInUserType()
{
    return $_SESSION["user_type"] ?? null;
}

function requireLogin()
{
    if (!isset($_SESSION["user_uid"])) {
        header("Location: ../login.php");
        exit;
    }
}

function requireSchoolAdmin()
{
    if (!isset($_SESSION["user_uid"])) {
        header("Location: ../login.php");
        exit;
    }

    if (
        !isset($_SESSION["user_type"]) ||
        $_SESSION["user_type"] !== "school_admin"
    ) {
        header("Location: ../login.php");
        exit;
    }

    if (empty($_SESSION["school_uid"])) {
        header("Location: ../login.php");
        exit;
    }
}

function getCurrentAdmin($conn)
{
    $user_uid = getLoggedInUserUid();

    if (!$user_uid) {
        return false;
    }

    $sql = "SELECT
                user_uid,
                school_uid,
                role_uid,
                username,
                name,
                email,
                mobile,
                profile_photo,
                user_type,
                status,
                last_login
            FROM users
            WHERE user_uid = ?
            LIMIT 1";

    $stmt = executeStatement($conn, $sql, "s", [$user_uid]);

    return $stmt ? fetchSingleRow($stmt) : false;
}

function logoutAdmin()
{
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            "",
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();

    header("Location: ../login.php");
    exit;
}

function loginUser($conn, $username, $password)
{
    $sql = "SELECT
                user_uid,
                school_uid,
                role_uid,
                username,
                password,
                name,
                email,
                mobile,
                profile_photo,
                user_type,
                status
            FROM users
            WHERE username = ?
            LIMIT 1";

    $stmt = executeStatement($conn, $sql, "s", [$username]);

    if (!$stmt) {
        return false;
    }

    $user = fetchSingleRow($stmt);

    if (!$user) {
        return false;
    }

    if ($user["status"] !== "active") {
        return false;
    }

    if (!password_verify($password, $user["password"])) {
        return false;
    }

    $_SESSION["user_uid"]  = $user["user_uid"];
    $_SESSION["school_uid"] = $user["school_uid"];
    $_SESSION["role_uid"]   = $user["role_uid"];
    $_SESSION["username"]   = $user["username"];
    $_SESSION["name"]       = $user["name"];
    $_SESSION["user_type"]  = $user["user_type"];

    $update = "UPDATE users SET last_login = NOW() WHERE user_uid = ?";
    executeStatement($conn, $update, "s", [$user["user_uid"]]);

    return $user;
}


/* =========================================================
   3. SCHOOLS
========================================================= */

function getAllSchools($conn)
{
    $sql = "SELECT *
            FROM schools
            ORDER BY school_name ASC";

    $stmt = executeStatement($conn, $sql);

    return $stmt ? fetchAllRows($stmt) : [];
}

function getSchoolDetails($conn, $school_uid)
{
    $sql = "SELECT *
            FROM schools
            WHERE school_uid = ?
            LIMIT 1";

    $stmt = executeStatement($conn, $sql, "s", [$school_uid]);

    return $stmt ? fetchSingleRow($stmt) : false;
}

function addSchool(
    $conn,
    $school_code,
    $school_name,
    $short_name,
    $email,
    $phone,
    $alternate_phone,
    $address,
    $city,
    $state,
    $pincode,
    $country,
    $logo,
    $affiliation_no,
    $udise_code,
    $principal_name
) {
    $uid = generateUid($conn, "schools", "school_uid", "SCH");

    if (!$uid) {
        return false;
    }

    $sql = "INSERT INTO schools
            (
                school_uid,
                school_code,
                school_name,
                short_name,
                email,
                phone,
                alternate_phone,
                address,
                city,
                state,
                pincode,
                country,
                logo,
                affiliation_no,
                udise_code,
                principal_name
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $params = [
        $uid,
        $school_code,
        $school_name,
        $short_name,
        $email,
        $phone,
        $alternate_phone,
        $address,
        $city,
        $state,
        $pincode,
        $country,
        $logo,
        $affiliation_no,
        $udise_code,
        $principal_name
    ];

    $stmt = executeStatement(
        $conn,
        $sql,
        str_repeat("s", 16),
        $params
    );

    return $stmt !== false;
}

function updateSchoolProfile(
    $conn,
    $school_uid,
    $school_name,
    $short_name,
    $email,
    $phone,
    $alternate_phone,
    $address,
    $city,
    $state,
    $pincode,
    $principal_name
) {
    $sql = "UPDATE schools
            SET
                school_name = ?,
                short_name = ?,
                email = ?,
                phone = ?,
                alternate_phone = ?,
                address = ?,
                city = ?,
                state = ?,
                pincode = ?,
                principal_name = ?
            WHERE school_uid = ?";

    $params = [
        $school_name,
        $short_name,
        $email,
        $phone,
        $alternate_phone,
        $address,
        $city,
        $state,
        $pincode,
        $principal_name,
        $school_uid
    ];

    $stmt = executeStatement(
        $conn,
        $sql,
        str_repeat("s", 11),
        $params
    );

    return $stmt !== false;
}

function updateSchoolStatus($conn, $school_uid, $status)
{
    $sql = "UPDATE schools
            SET status = ?
            WHERE school_uid = ?";

    $stmt = executeStatement($conn, $sql, "ss", [$status, $school_uid]);

    return $stmt !== false;
}


/* =========================================================
   4. SUBSCRIPTION PLANS
========================================================= */

function getAllSubscriptionPlans($conn, $status = null)
{
    if ($status !== null) {
        $sql = "SELECT *
                FROM subscription_plans
                WHERE status = ?
                ORDER BY monthly_price ASC";

        $stmt = executeStatement($conn, $sql, "s", [$status]);
    } else {
        $sql = "SELECT *
                FROM subscription_plans
                ORDER BY monthly_price ASC";

        $stmt = executeStatement($conn, $sql);
    }

    return $stmt ? fetchAllRows($stmt) : [];
}

function getSubscriptionPlanByUid($conn, $plan_uid)
{
    $sql = "SELECT *
            FROM subscription_plans
            WHERE plan_uid = ?
            LIMIT 1";

    $stmt = executeStatement($conn, $sql, "s", [$plan_uid]);

    return $stmt ? fetchSingleRow($stmt) : false;
}

function addSubscriptionPlan(
    $conn,
    $plan_name,
    $description,
    $monthly_price,
    $quarterly_price,
    $half_yearly_price,
    $yearly_price,
    $student_limit,
    $teacher_limit,
    $staff_limit
) {
    $uid = generateUid($conn, "subscription_plans", "plan_uid", "PLN");

    if (!$uid) {
        return false;
    }

    $sql = "INSERT INTO subscription_plans
            (
                plan_uid,
                plan_name,
                description,
                monthly_price,
                quarterly_price,
                half_yearly_price,
                yearly_price,
                student_limit,
                teacher_limit,
                staff_limit
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = executeStatement(
        $conn,
        $sql,
        "sssddddiii",
        [
            $uid,
            $plan_name,
            $description,
            $monthly_price,
            $quarterly_price,
            $half_yearly_price,
            $yearly_price,
            $student_limit,
            $teacher_limit,
            $staff_limit
        ]
    );

    return $stmt !== false;
}

function updateSubscriptionPlanStatus($conn, $plan_uid, $status)
{
    $sql = "UPDATE subscription_plans
            SET status = ?
            WHERE plan_uid = ?";

    $stmt = executeStatement($conn, $sql, "ss", [$status, $plan_uid]);

    return $stmt !== false;
}


/* =========================================================
   5. SCHOOL SUBSCRIPTIONS
========================================================= */

function getSchoolSubscriptions($conn, $school_uid)
{
    $sql = "SELECT
                ss.*,
                sp.plan_name
            FROM school_subscriptions ss
            LEFT JOIN subscription_plans sp
                ON sp.plan_uid = ss.plan_uid
            WHERE ss.school_uid = ?
            ORDER BY ss.expiry_date DESC";

    $stmt = executeStatement($conn, $sql, "s", [$school_uid]);

    return $stmt ? fetchAllRows($stmt) : [];
}

function getActiveSchoolSubscription($conn, $school_uid)
{
    $sql = "SELECT
                ss.*,
                sp.plan_name,
                sp.student_limit,
                sp.teacher_limit,
                sp.staff_limit
            FROM school_subscriptions ss
            LEFT JOIN subscription_plans sp
                ON sp.plan_uid = ss.plan_uid
            WHERE ss.school_uid = ?
              AND ss.status = 'active'
            ORDER BY ss.expiry_date DESC
            LIMIT 1";

    $stmt = executeStatement($conn, $sql, "s", [$school_uid]);

    return $stmt ? fetchSingleRow($stmt) : false;
}

function addSchoolSubscription(
    $conn,
    $school_uid,
    $plan_uid,
    $start_date,
    $expiry_date,
    $amount,
    $payment_status = "pending",
    $status = "active"
) {
    $uid = generateUid(
        $conn,
        "school_subscriptions",
        "subscription_uid",
        "SUB"
    );

    if (!$uid) {
        return false;
    }

    $sql = "INSERT INTO school_subscriptions
            (
                subscription_uid,
                school_uid,
                plan_uid,
                start_date,
                expiry_date,
                amount,
                payment_status,
                status
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = executeStatement(
        $conn,
        $sql,
        "sssssiss",
        [
            $uid,
            $school_uid,
            $plan_uid,
            $start_date,
            $expiry_date,
            $amount,
            $payment_status,
            $status
        ]
    );

    return $stmt !== false;
}

function updateSubscriptionStatus($conn, $subscription_uid, $status)
{
    $sql = "UPDATE school_subscriptions
            SET status = ?
            WHERE subscription_uid = ?";

    $stmt = executeStatement($conn, $sql, "ss", [$status, $subscription_uid]);

    return $stmt !== false;
}


/* =========================================================
   6. ROLES
========================================================= */

function getRoles($conn, $school_uid = null)
{
    if ($school_uid !== null) {
        $sql = "SELECT *
                FROM roles
                WHERE school_uid = ? OR school_uid IS NULL
                ORDER BY role_name ASC";

        $stmt = executeStatement($conn, $sql, "s", [$school_uid]);
    } else {
        $sql = "SELECT *
                FROM roles
                ORDER BY role_name ASC";

        $stmt = executeStatement($conn, $sql);
    }

    return $stmt ? fetchAllRows($stmt) : [];
}

function getRoleByUid($conn, $role_uid)
{
    $sql = "SELECT *
            FROM roles
            WHERE role_uid = ?
            LIMIT 1";

    $stmt = executeStatement($conn, $sql, "s", [$role_uid]);

    return $stmt ? fetchSingleRow($stmt) : false;
}

function addRole(
    $conn,
    $school_uid,
    $role_name,
    $description,
    $is_system_role = 0
) {
    $uid = generateUid($conn, "roles", "role_uid", "ROL");

    if (!$uid) {
        return false;
    }

    $sql = "INSERT INTO roles
            (
                role_uid,
                school_uid,
                role_name,
                description,
                is_system_role
            )
            VALUES (?, ?, ?, ?, ?)";

    $stmt = executeStatement(
        $conn,
        $sql,
        "ssssi",
        [
            $uid,
            $school_uid,
            $role_name,
            $description,
            $is_system_role
        ]
    );

    return $stmt !== false;
}

function updateRoleStatus($conn, $role_uid, $status)
{
    $sql = "UPDATE roles
            SET status = ?
            WHERE role_uid = ?";

    $stmt = executeStatement($conn, $sql, "ss", [$status, $role_uid]);

    return $stmt !== false;
}


/* =========================================================
   7. PERMISSIONS
========================================================= */

function getAllPermissions($conn)
{
    $sql = "SELECT *
            FROM permissions
            ORDER BY module_name ASC, permission_name ASC";

    $stmt = executeStatement($conn ,$sql);

    return $stmt ? fetchAllRows($stmt) : [];
}

function getPermissionsByModule($conn, $module_name)
{
    $sql = "SELECT *
            FROM permissions
            WHERE module_name = ?
            ORDER BY permission_name ASC";

    $stmt = executeStatement($conn, $sql, "s", [$module_name]);

    return $stmt ? fetchAllRows($stmt) : [];
}

function addPermission(
    $conn,
    $module_name,
    $permission_name,
    $permission_key,
    $action_name,
    $description
) {
    $uid = generateUid($conn, "permissions", "permission_uid", "PER");

    if (!$uid) {
        return false;
    }

    $sql = "INSERT INTO permissions
            (
                permission_uid,
                module_name,
                permission_name,
                permission_key,
                action_name,
                description
            )
            VALUES (?, ?, ?, ?, ?, ?)";

    $stmt = executeStatement(
        $conn,
        $sql,
        "ssssss",
        [
            $uid,
            $module_name,
            $permission_name,
            $permission_key,
            $action_name,
            $description
        ]
    );

    return $stmt !== false;
}


/* =========================================================
   8. USERS
========================================================= */

function getAllUsers($conn, $school_uid = null)
{
    if ($school_uid !== null) {
        $sql = "SELECT
                    user_uid,
                    school_uid,
                    role_uid,
                    username,
                    name,
                    email,
                    mobile,
                    profile_photo,
                    user_type,
                    status,
                    last_login,
                    created_at,
                    updated_at
                FROM users
                WHERE school_uid = ?
                ORDER BY name ASC";

        $stmt = executeStatement($conn, $sql, "s", [$school_uid]);
    } else {
        $sql = "SELECT
                    user_uid,
                    school_uid,
                    role_uid,
                    username,
                    name,
                    email,
                    mobile,
                    profile_photo,
                    user_type,
                    status,
                    last_login,
                    created_at,
                    updated_at
                FROM users
                ORDER BY name ASC";

        $stmt = executeStatement($conn, $sql);
    }

    return $stmt ? fetchAllRows($stmt) : [];
}

function getUserByUid($conn, $user_uid)
{
    $sql = "SELECT *
            FROM users
            WHERE user_uid = ?
            LIMIT 1";

    $stmt = executeStatement($conn, $sql, "s", [$user_uid]);

    return $stmt ? fetchSingleRow($stmt) : false;
}

function addUser(
    $conn,
    $school_uid,
    $role_uid,
    $username,
    $password,
    $name,
    $email,
    $mobile,
    $user_type
) {
    $uid = generateUid($conn, "users", "user_uid", "USR");

    if (!$uid) {
        return false;
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $sql = "INSERT INTO users
            (
                user_uid,
                school_uid,
                role_uid,
                username,
                password,
                name,
                email,
                mobile,
                user_type
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = executeStatement(
        $conn,
        $sql,
        "sssssssss",
        [
            $uid,
            $school_uid,
            $role_uid,
            $username,
            $passwordHash,
            $name,
            $email,
            $mobile,
            $user_type
        ]
    );

    return $stmt !== false;
}

function updateUser(
    $conn,
    $user_uid,
    $name,
    $email,
    $mobile,
    $role_uid,
    $user_type
) {
    $sql = "UPDATE users
            SET
                name = ?,
                email = ?,
                mobile = ?,
                role_uid = ?,
                user_type = ?
            WHERE user_uid = ?";

    $stmt = executeStatement(
        $conn,
        $sql,
        "ssssss",
        [$name, $email, $mobile, $role_uid, $user_type, $user_uid]
    );

    return $stmt !== false;
}

function changeUserPassword($conn, $user_uid, $newPassword)
{
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);

    $sql = "UPDATE users
            SET password = ?
            WHERE user_uid = ?";

    $stmt = executeStatement($conn, $sql, "ss", [$hash, $user_uid]);

    return $stmt !== false;
}

function updateUserStatus($conn, $user_uid, $status)
{
    $sql = "UPDATE users
            SET status = ?
            WHERE user_uid = ?";

    $stmt = executeStatement($conn, $sql, "ss", [$status, $user_uid]);

    return $stmt !== false;
}


/* =========================================================
   9. ROLE PERMISSIONS
========================================================= */

function getRolePermissions($conn, $role_uid)
{
    $sql = "SELECT
                rp.role_permission_uid,
                rp.role_uid,
                rp.permission_uid,
                p.module_name,
                p.permission_name,
                p.permission_key,
                p.action_name
            FROM role_permissions rp
            INNER JOIN permissions p
                ON p.permission_uid = rp.permission_uid
            WHERE rp.role_uid = ?
            ORDER BY p.module_name, p.permission_name";

    $stmt = executeStatement($conn, $sql, "s", [$role_uid]);

    return $stmt ? fetchAllRows($stmt) : [];
}

function addRolePermission($conn, $role_uid, $permission_uid)
{
    $uid = generateUid(
        $conn,
        "role_permissions",
        "role_permission_uid",
        "RPER"
    );

    if (!$uid) {
        return false;
    }

    $sql = "INSERT INTO role_permissions
            (
                role_permission_uid,
                role_uid,
                permission_uid
            )
            VALUES (?, ?, ?)";

    $stmt = executeStatement(
        $conn,
        $sql,
        "sss",
        [$uid, $role_uid, $permission_uid]
    );

    return $stmt !== false;
}

function removeRolePermission($conn, $role_uid, $permission_uid)
{
    $sql = "DELETE FROM role_permissions
            WHERE role_uid = ?
              AND permission_uid = ?";

    $stmt = executeStatement(
        $conn,
        $sql,
        "ss",
        [$role_uid, $permission_uid]
    );

    return $stmt !== false;
}


/* =========================================================
   10. USER PERMISSIONS
========================================================= */

function getUserPermissions($conn, $user_uid)
{
    $sql = "SELECT
                up.user_permission_uid,
                up.user_uid,
                up.permission_uid,
                up.permission_type,
                p.module_name,
                p.permission_name,
                p.permission_key,
                p.action_name
            FROM user_permissions up
            INNER JOIN permissions p
                ON p.permission_uid = up.permission_uid
            WHERE up.user_uid = ?
            ORDER BY p.module_name, p.permission_name";

    $stmt = executeStatement($conn, $sql, "s", [$user_uid]);

    return $stmt ? fetchAllRows($stmt) : [];
}

function addUserPermission(
    $conn,
    $user_uid,
    $permission_uid,
    $permission_type = "allow"
) {
    $uid = generateUid(
        $conn,
        "user_permissions",
        "user_permission_uid",
        "UPER"
    );

    if (!$uid) {
        return false;
    }

    $sql = "INSERT INTO user_permissions
            (
                user_permission_uid,
                user_uid,
                permission_uid,
                permission_type
            )
            VALUES (?, ?, ?, ?)";

    $stmt = executeStatement(
        $conn,
        $sql,
        "ssss",
        [$uid, $user_uid, $permission_uid, $permission_type]
    );

    return $stmt !== false;
}

function removeUserPermission($conn, $user_uid, $permission_uid)
{
    $sql = "DELETE FROM user_permissions
            WHERE user_uid = ?
              AND permission_uid = ?";

    $stmt = executeStatement(
        $conn,
        $sql,
        "ss",
        [$user_uid, $permission_uid]
    );

    return $stmt !== false;
}


/* =========================================================
   11. ACADEMIC YEARS
========================================================= */

function getAcademicYears($conn, $school_uid)
{
    $sql = "SELECT
                academic_year_uid,
                year_name,
                start_date,
                end_date,
                is_current,
                status
            FROM academic_years
            WHERE school_uid = ?
            ORDER BY start_date DESC";

    $stmt = executeStatement($conn, $sql, "s", [$school_uid]);

    return $stmt ? fetchAllRows($stmt) : [];
}

function getCurrentAcademicYear($conn, $school_uid)
{
    $sql = "SELECT
                academic_year_uid,
                year_name,
                start_date,
                end_date,
                is_current,
                status
            FROM academic_years
            WHERE school_uid = ?
              AND is_current = 1
              AND status = 'active'
            LIMIT 1";

    $stmt = executeStatement($conn, $sql, "s", [$school_uid]);

    return $stmt ? fetchSingleRow($stmt) : false;
}

function addAcademicYear(
    $conn,
    $school_uid,
    $year_name,
    $start_date,
    $end_date,
    $is_current = 0
) {
    $uid = generateUid(
        $conn,
        "academic_years",
        "academic_year_uid",
        "ACY"
    );

    if (!$uid) {
        return false;
    }

    if ((int)$is_current === 1) {
        $reset = "UPDATE academic_years
                  SET is_current = 0
                  WHERE school_uid = ?";
        executeStatement($conn, $reset, "s", [$school_uid]);
    }

    $sql = "INSERT INTO academic_years
            (
                academic_year_uid,
                school_uid,
                year_name,
                start_date,
                end_date,
                is_current
            )
            VALUES (?, ?, ?, ?, ?, ?)";

    $stmt = executeStatement(
        $conn,
        $sql,
        "sssssi",
        [
            $uid,
            $school_uid,
            $year_name,
            $start_date,
            $end_date,
            $is_current
        ]
    );

    return $stmt !== false;
}

function setCurrentAcademicYear($conn, $school_uid, $academic_year_uid)
{
    $reset = "UPDATE academic_years
              SET is_current = 0
              WHERE school_uid = ?";

    if (!executeStatement($conn, $reset, "s", [$school_uid])) {
        return false;
    }

    $sql = "UPDATE academic_years
            SET is_current = 1
            WHERE school_uid = ?
              AND academic_year_uid = ?";

    $stmt = executeStatement(
        $conn,
        $sql,
        "ss",
        [$school_uid, $academic_year_uid]
    );

    return $stmt !== false;
}


/* =========================================================
   12. CLASSES
========================================================= */

function getAllClasses($conn, $school_uid)
{
    $sql = "SELECT
                class_uid,
                class_name,
                numeric_order,
                status,
                created_at
            FROM classes
            WHERE school_uid = ?
            ORDER BY numeric_order ASC, class_name ASC";

    $stmt = executeStatement($conn, $sql, "s", [$school_uid]);

    return $stmt ? fetchAllRows($stmt) : [];
}

function getClassByUid($conn, $school_uid, $class_uid)
{
    $sql = "SELECT *
            FROM classes
            WHERE school_uid = ?
              AND class_uid = ?
            LIMIT 1";

    $stmt = executeStatement(
        $conn,
        $sql,
        "ss",
        [$school_uid, $class_uid]
    );

    return $stmt ? fetchSingleRow($stmt) : false;
}

function addClass($conn, $school_uid, $class_name, $numeric_order = 0)
{
    $uid = generateUid($conn, "classes", "class_uid", "CLS");

    if (!$uid) {
        return false;
    }

    $sql = "INSERT INTO classes
            (
                class_uid,
                school_uid,
                class_name,
                numeric_order
            )
            VALUES (?, ?, ?, ?)";

    $stmt = executeStatement(
        $conn,
        $sql,
        "sssi",
        [$uid, $school_uid, $class_name, $numeric_order]
    );

    return $stmt !== false;
}

function updateClass(
    $conn,
    $school_uid,
    $class_uid,
    $class_name,
    $numeric_order
) {
    $sql = "UPDATE classes
            SET class_name = ?,
                numeric_order = ?
            WHERE school_uid = ?
              AND class_uid = ?";

    $stmt = executeStatement(
        $conn,
        $sql,
        "siss",
        [$class_name, $numeric_order, $school_uid, $class_uid]
    );

    return $stmt !== false;
}

function updateClassStatus($conn, $school_uid, $class_uid, $status)
{
    $sql = "UPDATE classes
            SET status = ?
            WHERE school_uid = ?
              AND class_uid = ?";

    $stmt = executeStatement(
        $conn,
        $sql,
        "sss",
        [$status, $school_uid, $class_uid]
    );

    return $stmt !== false;
}


/* =========================================================
   13. SECTIONS
========================================================= */

function getAllSections($conn, $school_uid)
{
    $sql = "SELECT
                s.section_uid,
                s.section_name,
                s.class_uid,
                s.class_teacher_uid,
                s.status,
                c.class_name
            FROM sections s
            LEFT JOIN classes c
                ON c.class_uid = s.class_uid
               AND c.school_uid = s.school_uid
            WHERE s.school_uid = ?
            ORDER BY c.numeric_order ASC, s.section_name ASC";

    $stmt = executeStatement($conn, $sql, "s", [$school_uid]);

    return $stmt ? fetchAllRows($stmt) : [];
}

function getSectionsByClass($conn, $school_uid, $class_uid)
{
    $sql = "SELECT
                section_uid,
                section_name,
                class_uid,
                class_teacher_uid,
                status
            FROM sections
            WHERE school_uid = ?
              AND class_uid = ?
            ORDER BY section_name ASC";

    $stmt = executeStatement(
        $conn,
        $sql,
        "ss",
        [$school_uid, $class_uid]
    );

    return $stmt ? fetchAllRows($stmt) : [];
}

function addSection($conn, $school_uid, $class_uid, $section_name)
{
    $uid = generateUid($conn, "sections", "section_uid", "SEC");

    if (!$uid) {
        return false;
    }

    $sql = "INSERT INTO sections
            (
                section_uid,
                school_uid,
                class_uid,
                section_name
            )
            VALUES (?, ?, ?, ?)";

    $stmt = executeStatement(
        $conn,
        $sql,
        "ssss",
        [$uid, $school_uid, $class_uid, $section_name]
    );

    return $stmt !== false;
}

function updateSection(
    $conn,
    $school_uid,
    $section_uid,
    $section_name,
    $class_teacher_uid = null
) {
    $sql = "UPDATE sections
            SET section_name = ?,
                class_teacher_uid = ?
            WHERE school_uid = ?
              AND section_uid = ?";

    $stmt = executeStatement(
        $conn,
        $sql,
        "ssss",
        [
            $section_name,
            $class_teacher_uid,
            $school_uid,
            $section_uid
        ]
    );

    return $stmt !== false;
}

function updateSectionStatus($conn, $school_uid, $section_uid, $status)
{
    $sql = "UPDATE sections
            SET status = ?
            WHERE school_uid = ?
              AND section_uid = ?";

    $stmt = executeStatement(
        $conn,
        $sql,
        "sss",
        [$status, $school_uid, $section_uid]
    );

    return $stmt !== false;
}


/* =========================================================
   14. SUBJECTS
========================================================= */

function getAllSubjects($conn, $school_uid)
{
    $sql = "SELECT
                subject_uid,
                subject_name,
                subject_code,
                subject_type,
                status,
                created_at
            FROM subjects
            WHERE school_uid = ?
            ORDER BY subject_name ASC";

    $stmt = executeStatement($conn, $sql, "s", [$school_uid]);

    return $stmt ? fetchAllRows($stmt) : [];
}

function getSubjectByUid($conn, $school_uid, $subject_uid)
{
    $sql = "SELECT *
            FROM subjects
            WHERE school_uid = ?
              AND subject_uid = ?
            LIMIT 1";

    $stmt = executeStatement(
        $conn,
        $sql,
        "ss",
        [$school_uid, $subject_uid]
    );

    return $stmt ? fetchSingleRow($stmt) : false;
}

function addSubject(
    $conn,
    $school_uid,
    $subject_name,
    $subject_code,
    $subject_type = "theory"
) {
    $uid = generateUid($conn, "subjects", "subject_uid", "SUBJ");

    if (!$uid) {
        return false;
    }

    $sql = "INSERT INTO subjects
            (
                subject_uid,
                school_uid,
                subject_name,
                subject_code,
                subject_type
            )
            VALUES (?, ?, ?, ?, ?)";

    $stmt = executeStatement(
        $conn,
        $sql,
        "sssss",
        [
            $uid,
            $school_uid,
            $subject_name,
            $subject_code,
            $subject_type
        ]
    );

    return $stmt !== false;
}

function updateSubject(
    $conn,
    $school_uid,
    $subject_uid,
    $subject_name,
    $subject_code,
    $subject_type
) {
    $sql = "UPDATE subjects
            SET subject_name = ?,
                subject_code = ?,
                subject_type = ?
            WHERE school_uid = ?
              AND subject_uid = ?";

    $stmt = executeStatement(
        $conn,
        $sql,
        "sssss",
        [
            $subject_name,
            $subject_code,
            $subject_type,
            $school_uid,
            $subject_uid
        ]
    );

    return $stmt !== false;
}

function updateSubjectStatus(
    $conn,
    $school_uid,
    $subject_uid,
    $status
) {
    $sql = "UPDATE subjects
            SET status = ?
            WHERE school_uid = ?
              AND subject_uid = ?";

    $stmt = executeStatement(
        $conn,
        $sql,
        "sss",
        [$status, $school_uid, $subject_uid]
    );

    return $stmt !== false;
}


/* =========================================================
   15. TEACHERS
========================================================= */

function getAllTeachers($conn, $school_uid)
{
    $sql = "SELECT
                teacher_uid,
                employee_code,
                teacher_name,
                gender,
                dob,
                email,
                mobile,
                qualification,
                experience,
                joining_date,
                address,
                profile_photo,
                status,
                user_uid
            FROM teachers
            WHERE school_uid = ?
            ORDER BY teacher_name ASC";

    $stmt = executeStatement($conn, $sql, "s", [$school_uid]);

    return $stmt ? fetchAllRows($stmt) : [];
}

function getTeacherByUid($conn, $school_uid, $teacher_uid)
{
    $sql = "SELECT *
            FROM teachers
            WHERE school_uid = ?
              AND teacher_uid = ?
            LIMIT 1";

    $stmt = executeStatement(
        $conn,
        $sql,
        "ss",
        [$school_uid, $teacher_uid]
    );

    return $stmt ? fetchSingleRow($stmt) : false;
}

function addTeacher(
    $conn,
    $school_uid,
    $employee_code,
    $teacher_name,
    $gender,
    $dob,
    $email,
    $mobile,
    $qualification,
    $experience,
    $joining_date,
    $address
) {
    $uid = generateUid($conn, "teachers", "teacher_uid", "TCH");

    if (!$uid) {
        return false;
    }

    $sql = "INSERT INTO teachers
            (
                teacher_uid,
                school_uid,
                employee_code,
                teacher_name,
                gender,
                dob,
                email,
                mobile,
                qualification,
                experience,
                joining_date,
                address
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = executeStatement(
        $conn,
        $sql,
        str_repeat("s", 12),
        [
            $uid,
            $school_uid,
            $employee_code,
            $teacher_name,
            $gender,
            $dob,
            $email,
            $mobile,
            $qualification,
            $experience,
            $joining_date,
            $address
        ]
    );

    return $stmt !== false;
}

function updateTeacher(
    $conn,
    $school_uid,
    $teacher_uid,
    $employee_code,
    $teacher_name,
    $gender,
    $dob,
    $email,
    $mobile,
    $qualification,
    $experience,
    $joining_date,
    $address
) {
    $sql = "UPDATE teachers
            SET employee_code = ?,
                teacher_name = ?,
                gender = ?,
                dob = ?,
                email = ?,
                mobile = ?,
                qualification = ?,
                experience = ?,
                joining_date = ?,
                address = ?
            WHERE school_uid = ?
              AND teacher_uid = ?";

    $stmt = executeStatement(
        $conn,
        $sql,
        str_repeat("s", 12),
        [
            $employee_code,
            $teacher_name,
            $gender,
            $dob,
            $email,
            $mobile,
            $qualification,
            $experience,
            $joining_date,
            $address,
            $school_uid,
            $teacher_uid
        ]
    );

    return $stmt !== false;
}

function updateTeacherStatus(
    $conn,
    $school_uid,
    $teacher_uid,
    $status
) {
    $sql = "UPDATE teachers
            SET status = ?
            WHERE school_uid = ?
              AND teacher_uid = ?";

    $stmt = executeStatement(
        $conn,
        $sql,
        "sss",
        [$status, $school_uid, $teacher_uid]
    );

    return $stmt !== false;
}


/* =========================================================
   16. STUDENTS
========================================================= */

function getAllStudents(
    $conn,
    $school_uid,
    $academic_year_uid = null
) {
    if ($academic_year_uid !== null) {
        $sql = "SELECT
                    s.*,
                    sa.academic_uid,
                    sa.class_uid,
                    sa.section_uid,
                    sa.academic_year_uid,
                    sa.roll_no AS academic_roll_no,
                    sa.status AS academic_status,
                    c.class_name,
                    sec.section_name
                FROM students s
                LEFT JOIN student_academic sa
                    ON sa.student_uid = s.student_uid
                   AND sa.school_uid = s.school_uid
                   AND sa.academic_year_uid = ?
                LEFT JOIN classes c
                    ON c.class_uid = sa.class_uid
                   AND c.school_uid = s.school_uid
                LEFT JOIN sections sec
                    ON sec.section_uid = sa.section_uid
                   AND sec.school_uid = s.school_uid
                WHERE s.school_uid = ?
                ORDER BY s.student_name ASC";

        $stmt = executeStatement(
            $conn,
            $sql,
            "ss",
            [$academic_year_uid, $school_uid]
        );
    } else {
        $sql = "SELECT *
                FROM students
                WHERE school_uid = ?
                ORDER BY student_name ASC";

        $stmt = executeStatement($conn, $sql, "s", [$school_uid]);
    }

    return $stmt ? fetchAllRows($stmt) : [];
}

function getStudentByUid($conn, $school_uid, $student_uid)
{
    $sql = "SELECT *
            FROM students
            WHERE school_uid = ?
              AND student_uid = ?
            LIMIT 1";

    $stmt = executeStatement(
        $conn,
        $sql,
        "ss",
        [$school_uid, $student_uid]
    );

    return $stmt ? fetchSingleRow($stmt) : false;
}

function addStudent(
    $conn,
    $school_uid,
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
) {
    $uid = generateUid($conn, "students", "student_uid", "STU");

    if (!$uid) {
        return false;
    }

    $sql = "INSERT INTO students
            (
                student_uid,
                school_uid,
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
                admission_date
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $params = [
        $uid,
        $school_uid,
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
    ];

    $stmt = executeStatement(
        $conn,
        $sql,
        str_repeat("s", 18),
        $params
    );

    return $stmt !== false;
}

function updateStudent(
    $conn,
    $school_uid,
    $student_uid,
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
) {
    $sql = "UPDATE students
            SET admission_no = ?,
                roll_no = ?,
                student_name = ?,
                gender = ?,
                dob = ?,
                blood_group = ?,
                father_name = ?,
                mother_name = ?,
                father_mobile = ?,
                mother_mobile = ?,
                email = ?,
                address = ?,
                city = ?,
                state = ?,
                pincode = ?,
                admission_date = ?
            WHERE school_uid = ?
              AND student_uid = ?";

    $params = [
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
        $admission_date,
        $school_uid,
        $student_uid
    ];

    $stmt = executeStatement(
        $conn,
        $sql,
        str_repeat("s", 18),
        $params
    );

    return $stmt !== false;
}

function updateStudentStatus(
    $conn,
    $school_uid,
    $student_uid,
    $status
) {
    $sql = "UPDATE students
            SET status = ?
            WHERE school_uid = ?
              AND student_uid = ?";

    $stmt = executeStatement(
        $conn,
        $sql,
        "sss",
        [$status, $school_uid, $student_uid]
    );

    return $stmt !== false;
}


/* =========================================================
   17. STUDENT ACADEMIC
========================================================= */

function assignStudentAcademic(
    $conn,
    $school_uid,
    $student_uid,
    $class_uid,
    $section_uid,
    $academic_year_uid,
    $roll_no
) {
    $uid = generateUid(
        $conn,
        "student_academic",
        "academic_uid",
        "SAC"
    );

    if (!$uid) {
        return false;
    }

    $sql = "INSERT INTO student_academic
            (
                academic_uid,
                school_uid,
                student_uid,
                class_uid,
                section_uid,
                academic_year_uid,
                roll_no
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)";

    $stmt = executeStatement(
        $conn,
        $sql,
        "sssssss",
        [
            $uid,
            $school_uid,
            $student_uid,
            $class_uid,
            $section_uid,
            $academic_year_uid,
            $roll_no
        ]
    );

    return $stmt !== false;
}

function updateStudentAcademic(
    $conn,
    $school_uid,
    $student_uid,
    $academic_year_uid,
    $class_uid,
    $section_uid,
    $roll_no
) {
    $sql = "UPDATE student_academic
            SET class_uid = ?,
                section_uid = ?,
                roll_no = ?
            WHERE school_uid = ?
              AND student_uid = ?
              AND academic_year_uid = ?";

    $stmt = executeStatement(
        $conn,
        $sql,
        "ssssss",
        [
            $class_uid,
            $section_uid,
            $roll_no,
            $school_uid,
            $student_uid,
            $academic_year_uid
        ]
    );

    return $stmt !== false;
}

function getStudentAcademic(
    $conn,
    $school_uid,
    $student_uid,
    $academic_year_uid
) {
    $sql = "SELECT
                sa.*,
                c.class_name,
                sec.section_name,
                ay.year_name
            FROM student_academic sa
            LEFT JOIN classes c
                ON c.class_uid = sa.class_uid
               AND c.school_uid = sa.school_uid
            LEFT JOIN sections sec
                ON sec.section_uid = sa.section_uid
               AND sec.school_uid = sa.school_uid
            LEFT JOIN academic_years ay
                ON ay.academic_year_uid = sa.academic_year_uid
               AND ay.school_uid = sa.school_uid
            WHERE sa.school_uid = ?
              AND sa.student_uid = ?
              AND sa.academic_year_uid = ?
            LIMIT 1";

    $stmt = executeStatement(
        $conn,
        $sql,
        "sss",
        [$school_uid, $student_uid, $academic_year_uid]
    );

    return $stmt ? fetchSingleRow($stmt) : false;
}


/* =========================================================
   18. PARENTS
========================================================= */

function getAllParents($conn, $school_uid)
{
    $sql = "SELECT *
            FROM parents
            WHERE school_uid = ?
            ORDER BY parent_uid DESC";

    $stmt = executeStatement($conn, $sql, "s", [$school_uid]);

    return $stmt ? fetchAllRows($stmt) : [];
}

function getParentByUid($conn, $school_uid, $parent_uid)
{
    $sql = "SELECT *
            FROM parents
            WHERE school_uid = ?
              AND parent_uid = ?
            LIMIT 1";

    $stmt = executeStatement(
        $conn,
        $sql,
        "ss",
        [$school_uid, $parent_uid]
    );

    return $stmt ? fetchSingleRow($stmt) : false;
}

function addParent(
    $conn,
    $school_uid,
    $father_name,
    $mother_name,
    $father_mobile,
    $mother_mobile,
    $father_email,
    $mother_email,
    $address
) {
    $uid = generateUid($conn, "parents", "parent_uid", "PAR");

    if (!$uid) {
        return false;
    }

    $sql = "INSERT INTO parents
            (
                parent_uid,
                school_uid,
                father_name,
                mother_name,
                father_mobile,
                mother_mobile,
                father_email,
                mother_email,
                address
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = executeStatement(
        $conn,
        $sql,
        "sssssssss",
        [
            $uid,
            $school_uid,
            $father_name,
            $mother_name,
            $father_mobile,
            $mother_mobile,
            $father_email,
            $mother_email,
            $address
        ]
    );

    return $stmt !== false;
}

function connectStudentParent(
    $conn,
    $school_uid,
    $student_uid,
    $parent_uid,
    $relation_type
) {
    $uid = generateUid(
        $conn,
        "student_parents",
        "relation_uid",
        "SP"
    );

    if (!$uid) {
        return false;
    }

    $sql = "INSERT INTO student_parents
            (
                relation_uid,
                school_uid,
                student_uid,
                parent_uid,
                relation_type
            )
            VALUES (?, ?, ?, ?, ?)";

    $stmt = executeStatement(
        $conn,
        $sql,
        "sssss",
        [
            $uid,
            $school_uid,
            $student_uid,
            $parent_uid,
            $relation_type
        ]
    );

    return $stmt !== false;
}

function getStudentParents(
    $conn,
    $school_uid,
    $student_uid
) {
    $sql = "SELECT
                sp.relation_uid,
                sp.relation_type,
                p.parent_uid,
                p.father_name,
                p.mother_name,
                p.father_mobile,
                p.mother_mobile,
                p.father_email,
                p.mother_email,
                p.address
            FROM student_parents sp
            INNER JOIN parents p
                ON p.parent_uid = sp.parent_uid
               AND p.school_uid = sp.school_uid
            WHERE sp.school_uid = ?
              AND sp.student_uid = ?";

    $stmt = executeStatement(
        $conn,
        $sql,
        "ss",
        [$school_uid, $student_uid]
    );

    return $stmt ? fetchAllRows($stmt) : [];
}


/* =========================================================
   19. TEACHER SUBJECT ASSIGNMENT
========================================================= */

function assignTeacherSubject(
    $conn,
    $school_uid,
    $teacher_uid,
    $subject_uid,
    $class_uid,
    $section_uid,
    $academic_year_uid
) {
    $uid = generateUid(
        $conn,
        "teacher_subjects",
        "assignment_uid",
        "TSUB"
    );

    if (!$uid) {
        return false;
    }

    $sql = "INSERT INTO teacher_subjects
            (
                assignment_uid,
                school_uid,
                teacher_uid,
                subject_uid,
                class_uid,
                section_uid,
                academic_year_uid
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)";

    $stmt = executeStatement(
        $conn,
        $sql,
        "sssssss",
        [
            $uid,
            $school_uid,
            $teacher_uid,
            $subject_uid,
            $class_uid,
            $section_uid,
            $academic_year_uid
        ]
    );

    return $stmt !== false;
}

function getTeacherAssignments(
    $conn,
    $school_uid,
    $teacher_uid
) {
    $sql = "SELECT
                ts.assignment_uid,
                ts.teacher_uid,
                ts.subject_uid,
                ts.class_uid,
                ts.section_uid,
                ts.academic_year_uid,
                sub.subject_name,
                c.class_name,
                sec.section_name
            FROM teacher_subjects ts
            LEFT JOIN subjects sub
                ON sub.subject_uid = ts.subject_uid
               AND sub.school_uid = ts.school_uid
            LEFT JOIN classes c
                ON c.class_uid = ts.class_uid
               AND c.school_uid = ts.school_uid
            LEFT JOIN sections sec
                ON sec.section_uid = ts.section_uid
               AND sec.school_uid = ts.school_uid
            WHERE ts.school_uid = ?
              AND ts.teacher_uid = ?";

    $stmt = executeStatement(
        $conn,
        $sql,
        "ss",
        [$school_uid, $teacher_uid]
    );

    return $stmt ? fetchAllRows($stmt) : [];
}


/* =========================================================
   20. STUDENT ATTENDANCE
========================================================= */

function getStudentAttendanceByDate(
    $conn,
    $school_uid,
    $class_uid,
    $section_uid,
    $attendance_date
) {
    $sql = "SELECT
                sa.attendance_uid,
                sa.student_uid,
                sa.attendance_date,
                sa.status,
                sa.remarks,
                s.student_name,
                s.admission_no,
                s.roll_no
            FROM student_attendance sa
            INNER JOIN students s
                ON s.student_uid = sa.student_uid
               AND s.school_uid = sa.school_uid
            WHERE sa.school_uid = ?
              AND sa.class_uid = ?
              AND sa.section_uid = ?
              AND sa.attendance_date = ?
            ORDER BY s.roll_no ASC, s.student_name ASC";

    $stmt = executeStatement(
        $conn,
        $sql,
        "ssss",
        [
            $school_uid,
            $class_uid,
            $section_uid,
            $attendance_date
        ]
    );

    return $stmt ? fetchAllRows($stmt) : [];
}

function markStudentAttendance(
    $conn,
    $school_uid,
    $student_uid,
    $class_uid,
    $section_uid,
    $attendance_date,
    $status,
    $marked_by_user_uid = null,
    $remarks = null
) {
    $uid = generateUid(
        $conn,
        "student_attendance",
        "attendance_uid",
        "ATT"
    );

    if (!$uid) {
        return false;
    }

    $sql = "INSERT INTO student_attendance
            (
                attendance_uid,
                school_uid,
                student_uid,
                class_uid,
                section_uid,
                attendance_date,
                status,
                marked_by_user_uid,
                remarks
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                class_uid = VALUES(class_uid),
                section_uid = VALUES(section_uid),
                status = VALUES(status),
                marked_by_user_uid = VALUES(marked_by_user_uid),
                remarks = VALUES(remarks)";

    $stmt = executeStatement(
        $conn,
        $sql,
        "sssssssss",
        [
            $uid,
            $school_uid,
            $student_uid,
            $class_uid,
            $section_uid,
            $attendance_date,
            $status,
            $marked_by_user_uid,
            $remarks
        ]
    );

    return $stmt !== false;
}

function getStudentAttendance(
    $conn,
    $school_uid,
    $student_uid,
    $from_date = null,
    $to_date = null
) {
    if ($from_date && $to_date) {
        $sql = "SELECT *
                FROM student_attendance
                WHERE school_uid = ?
                  AND student_uid = ?
                  AND attendance_date BETWEEN ? AND ?
                ORDER BY attendance_date DESC";

        $stmt = executeStatement(
            $conn,
            $sql,
            "ssss",
            [
                $school_uid,
                $student_uid,
                $from_date,
                $to_date
            ]
        );
    } else {
        $sql = "SELECT *
                FROM student_attendance
                WHERE school_uid = ?
                  AND student_uid = ?
                ORDER BY attendance_date DESC";

        $stmt = executeStatement(
            $conn,
            $sql,
            "ss",
            [$school_uid, $student_uid]
        );
    }

    return $stmt ? fetchAllRows($stmt) : [];
}


/* =========================================================
   21. TEACHER ATTENDANCE
========================================================= */

function markTeacherAttendance(
    $conn,
    $school_uid,
    $teacher_uid,
    $attendance_date,
    $check_in,
    $check_out,
    $status,
    $remarks = null
) {
    $uid = generateUid(
        $conn,
        "teacher_attendance",
        "teacher_attendance_uid",
        "TATT"
    );

    if (!$uid) {
        return false;
    }

    $sql = "INSERT INTO teacher_attendance
            (
                teacher_attendance_uid,
                school_uid,
                teacher_uid,
                attendance_date,
                check_in,
                check_out,
                status,
                remarks
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                check_in = VALUES(check_in),
                check_out = VALUES(check_out),
                status = VALUES(status),
                remarks = VALUES(remarks)";

    $stmt = executeStatement(
        $conn,
        $sql,
        "ssssssss",
        [
            $uid,
            $school_uid,
            $teacher_uid,
            $attendance_date,
            $check_in,
            $check_out,
            $status,
            $remarks
        ]
    );

    return $stmt !== false;
}

function getTeacherAttendance(
    $conn,
    $school_uid,
    $teacher_uid,
    $from_date = null,
    $to_date = null
) {
    if ($from_date && $to_date) {
        $sql = "SELECT *
                FROM teacher_attendance
                WHERE school_uid = ?
                  AND teacher_uid = ?
                  AND attendance_date BETWEEN ? AND ?
                ORDER BY attendance_date DESC";

        $stmt = executeStatement(
            $conn,
            $sql,
            "ssss",
            [
                $school_uid,
                $teacher_uid,
                $from_date,
                $to_date
            ]
        );
    } else {
        $sql = "SELECT *
                FROM teacher_attendance
                WHERE school_uid = ?
                  AND teacher_uid = ?
                ORDER BY attendance_date DESC";

        $stmt = executeStatement(
            $conn,
            $sql,
            "ss",
            [$school_uid, $teacher_uid]
        );
    }

    return $stmt ? fetchAllRows($stmt) : [];
}


/* =========================================================
   22. FEE TYPES
========================================================= */

function getAllFeeTypes($conn, $school_uid)
{
    $sql = "SELECT
                fee_type_uid,
                fee_name,
                description,
                status,
                created_at
            FROM fee_types
            WHERE school_uid = ?
            ORDER BY fee_name ASC";

    $stmt = executeStatement($conn, $sql, "s", [$school_uid]);

    return $stmt ? fetchAllRows($stmt) : [];
}

function getFeeTypeByUid($conn, $school_uid, $fee_type_uid)
{
    $sql = "SELECT *
            FROM fee_types
            WHERE school_uid = ?
              AND fee_type_uid = ?
            LIMIT 1";

    $stmt = executeStatement(
        $conn,
        $sql,
        "ss",
        [$school_uid, $fee_type_uid]
    );

    return $stmt ? fetchSingleRow($stmt) : false;
}

function addFeeType(
    $conn,
    $school_uid,
    $fee_name,
    $description = null
) {
    $uid = generateUid(
        $conn,
        "fee_types",
        "fee_type_uid",
        "FET"
    );

    if (!$uid) {
        return false;
    }

    $sql = "INSERT INTO fee_types
            (
                fee_type_uid,
                school_uid,
                fee_name,
                description
            )
            VALUES (?, ?, ?, ?)";

    $stmt = executeStatement(
        $conn,
        $sql,
        "ssss",
        [
            $uid,
            $school_uid,
            $fee_name,
            $description
        ]
    );

    return $stmt !== false;
}

function updateFeeTypeStatus(
    $conn,
    $school_uid,
    $fee_type_uid,
    $status
) {
    $sql = "UPDATE fee_types
            SET status = ?
            WHERE school_uid = ?
              AND fee_type_uid = ?";

    $stmt = executeStatement(
        $conn,
        $sql,
        "sss",
        [$status, $school_uid, $fee_type_uid]
    );

    return $stmt !== false;
}


/* =========================================================
   23. DASHBOARD
========================================================= */

function countStudents($conn, $school_uid)
{
    $sql = "SELECT COUNT(*) AS total
            FROM students
            WHERE school_uid = ?
              AND status = 'active'";

    $stmt = executeStatement($conn, $sql, "s", [$school_uid]);

    if (!$stmt) {
        return 0;
    }

    $row = fetchSingleRow($stmt);

    return (int)($row["total"] ?? 0);
}

function countTeachers($conn, $school_uid)
{
    $sql = "SELECT COUNT(*) AS total
            FROM teachers
            WHERE school_uid = ?
              AND status = 'active'";

    $stmt = executeStatement($conn, $sql, "s", [$school_uid]);

    if (!$stmt) {
        return 0;
    }

    $row = fetchSingleRow($stmt);

    return (int)($row["total"] ?? 0);
}

function countParents($conn, $school_uid)
{
    $sql = "SELECT COUNT(*) AS total
            FROM parents
            WHERE school_uid = ?";

    $stmt = executeStatement($conn, $sql, "s", [$school_uid]);

    if (!$stmt) {
        return 0;
    }

    $row = fetchSingleRow($stmt);

    return (int)($row["total"] ?? 0);
}

function countClasses($conn, $school_uid)
{
    $sql = "SELECT COUNT(*) AS total
            FROM classes
            WHERE school_uid = ?
              AND status = 'active'";

    $stmt = executeStatement($conn, $sql, "s", [$school_uid]);

    if (!$stmt) {
        return 0;
    }

    $row = fetchSingleRow($stmt);

    return (int)($row["total"] ?? 0);
}

function getDashboardStats($conn, $school_uid)
{
    return [
        "students" => countStudents($conn, $school_uid),
        "teachers" => countTeachers($conn, $school_uid),
        "parents"  => countParents($conn, $school_uid),
        "classes"  => countClasses($conn, $school_uid)
    ];
}

?>
