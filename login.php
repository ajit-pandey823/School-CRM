<?php

/* =====================================================
   LOGIN PAGE
   - Agar user pehle se logged in hai to apne dashboard par
   - Error / success messages yahan dikhaye jate hain
===================================================== */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/auth.php";

$auth_user = authValidate($conn);

if ($auth_user !== false) {

    $auth_dashboard = authDashboardUrl($auth_user["user_type"]);

    if ($auth_dashboard !== null) {
        header("Location: " . $auth_dashboard);
        exit();
    }

    /* Role ke liye koi dashboard nahi hai → login page par hi roko */

} elseif (!empty($_SESSION["user_id"])) {

    /* Purana / invalid session hai → destroy kar do */

    authDestroySession();
}

$login_error   = isset($_GET["error"]) ? (string)$_GET["error"] : "";
$login_message = isset($_GET["msg"])   ? (string)$_GET["msg"]   : "";

?>
<!DOCTYPE html>

<html lang="en">
<head>
    <base href="./">
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <meta name="description" content="CoreUI - Open Source Bootstrap Admin Template">
    <meta name="author" content="Łukasz Holeczek">
    <meta name="keyword" content="Bootstrap,Admin,Template,Open,Source,jQuery,CSS,HTML,RWD,Dashboard">
    <title>login Penal</title>
    <link rel="apple-touch-icon" sizes="57x57" href="incl/assets/favicon/apple-icon-57x57.png">
    <link rel="apple-touch-icon" sizes="60x60" href="incl/assets/favicon/apple-icon-60x60.png">
    <link rel="apple-touch-icon" sizes="72x72" href="incl/assets/favicon/apple-icon-72x72.png">
    <link rel="apple-touch-icon" sizes="76x76" href="incl/assets/favicon/apple-icon-76x76.png">
    <link rel="apple-touch-icon" sizes="114x114" href="incl/assets/favicon/apple-icon-114x114.png">
    <link rel="apple-touch-icon" sizes="120x120" href="incl/assets/favicon/apple-icon-120x120.png">
    <link rel="apple-touch-icon" sizes="144x144" href="incl/assets/favicon/apple-icon-144x144.png">
    <link rel="apple-touch-icon" sizes="152x152" href="incl/assets/favicon/apple-icon-152x152.png">
    <link rel="apple-touch-icon" sizes="180x180" href="incl/assets/favicon/apple-icon-180x180.png">
    <link rel="icon" type="image/png" sizes="192x192" href="incl/assets/favicon/android-icon-192x192.png">
    <link rel="icon" type="image/png" sizes="32x32" href="incl/assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="96x96" href="incl/assets/favicon/favicon-96x96.png">
    <!-- <link rel="icon" type="image/png" sizes="16x16" href="incl/assets/favicon/favicon-16x16.png"> -->
    <link rel="manifest" href="assets/favicon/manifest.json">
    <meta name="msapplication-TileColor" content="#ffffff">
    <meta name="msapplication-TileImage" content="assets/favicon/ms-icon-144x144.png">
    <meta name="theme-color" content="#ffffff">
    <!-- Vendors styles-->
    <link rel="stylesheet" href="incl/vendors/simplebar/css/simplebar.css">
    <link rel="stylesheet" href="incl/css/vendors/simplebar.css">
    <!-- Main styles for this application-->
    <link href="incl/css/style.css" rel="stylesheet">
    <!-- We use those styles to show code examples, you should remove them in your application.-->
    <link href="incl/css/examples.css" rel="stylesheet">
    <script src="incl/js/config.js"></script>
    <script src="incl/js/color-modes.js"></script>
</head>

<body>
    <div class="bg-body-tertiary min-vh-100 d-flex flex-row align-items-center">
        <div class="container" style="max-width: 32rem">
            <div class="d-flex flex-column gap-4">
                <svg
                    role="img"
                    aria-label="Sanvira Global Solutions Logo"
                    height="80"
                    xmlns="http://www.w3.org/2000/svg"
                    xmlns:xlink="http://www.w3.org/1999/xlink"
                    viewBox="0 0 312 115">

                    <image
                        href="./incl/sgs-white.png"
                        x="0"
                        y="0"
                        width="312"
                        height="115"
                        preserveAspectRatio="xMidYMid meet" />

                </svg>

                <div class="card p-4">
                    <div class="card-body d-flex flex-column gap-4">
                        <h2 class="h5 text-center">Login to your account</h2>

                        <?php if ($login_error !== ""): ?>
                            <div class="alert alert-danger mb-0" role="alert">
                                <?php echo htmlspecialchars(authLoginErrorMessage($login_error)); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($login_message === "logout"): ?>
                            <div class="alert alert-success mb-0" role="alert">
                                You have been logged out successfully.
                            </div>
                        <?php elseif ($login_message === "logout_all"): ?>
                            <div class="alert alert-success mb-0" role="alert">
                                You have been logged out from all devices.
                            </div>
                        <?php endif; ?>

                        <form class="row gap-3" action="login_process.php" method="post" autocomplete="off" novalidate>
                            <div>
                                <label class="form-label" for="username">Username</label>
                                <input class="form-control" id="username" name="username" type="text" placeholder="Your username" autocomplete="off">
                            </div>
                            <div>
                                <div class="d-flex justify-content-between">
                                    <label class="form-label" for="password">Password</label>
                                    <a href="#">I forgot password</a>
                                </div>
                                <div class="input-group">
                                    <input class="form-control" id="password" name="password" type="password" placeholder="Your password" autocomplete="off">
                                    <span class="input-group-text">
                                        <button class="bg-transparent border-0 p-0 link-secondary" type="button" data-coreui-toggle="tooltip" aria-label="Show password" data-coreui-original-title="Show password">
                                            <svg class="icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
                                                <path class="ci-primary" fill="var(--ci-primary-color, currentcolor)" d="M256 144.927a103.309 103.309 0 1 0 103.309 103.309A103.426 103.426 0 0 0 256 144.927m0 174.618a71.309 71.309 0 1 1 71.309-71.309A71.39 71.39 0 0 1 256 319.545"></path>
                                                <path class="ci-primary" fill="var(--ci-primary-color, currentcolor)" d="m397.222 131.1-.218-.223c-77.75-77.749-204.258-77.749-282.008 0L16 233.79v28.893l98.778 102.689.218.222a199.41 199.41 0 0 0 282.008 0l99-102.911V233.79ZM464 249.79l-89.732 93.285a167.41 167.41 0 0 1-236.536 0L48 249.79v-3.107l89.729-93.283c65.247-65.13 171.3-65.13 236.542 0L464 246.683Z"></path>
                                                <path class="ci-primary" fill="var(--ci-primary-color, currentcolor)" d="M240 232h32v32h-32z"></path>
                                            </svg>
                                        </button>
                                    </span>
                                </div>
                            </div>
                            <div>
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox">
                                    <span class="form-check-label">Remember me on this device</span>
                                </label>
                            </div>
                            <div>
                                <button class="btn btn-primary w-100" type="submit">Sign in</button>
                            </div>
                        </form>
                        <div class="position-relative">
                            <hr>
                            <div class="position-absolute top-50 start-50 translate-middle bg-body px-2 text-body-tertiary text-uppercase small">or</div>
                        </div>
                        <div class="row">
                            <div class="col">
                                <a class="btn btn-outline w-100" href="#">
                                    <svg class="icon me-1" aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M24 12.27C24 11.48 23.9284 10.73 23.8058 9.99998H12.2605V14.51H18.871C18.5747 15.99 17.7062 17.24 16.4189 18.09V21.09H20.3627C22.6717 19 24 15.92 24 12.27Z" fill="#4285F4"></path>
                                        <path d="M12.2606 24C15.571 24 18.3398 22.92 20.3628 21.09L16.419 18.09C15.3156 18.81 13.9158 19.25 12.2606 19.25C9.06269 19.25 6.35515 17.14 5.38453 14.29H1.31812V17.38C3.33089 21.3 7.46882 24 12.2606 24Z" fill="#34A853"></path>
                                        <path d="M5.38442 14.2901C5.12899 13.5701 4.99617 12.8001 4.99617 12.0001C4.99617 11.2001 5.13921 10.4301 5.38442 9.71009V6.62009H1.31801C0.480203 8.24009 0 10.0601 0 12.0001C0 13.9401 0.480203 15.7601 1.31801 17.3801L5.38442 14.2901Z" fill="#FBBC05"></path>
                                        <path d="M12.2606 4.74998C14.0691 4.74998 15.6834 5.35999 16.9605 6.54999L20.4548 3.12999C18.3398 1.18999 15.571 -1.52588e-05 12.2606 -1.52588e-05C7.46882 -1.52588e-05 3.33089 2.69999 1.31812 6.61999L5.38453 9.70999C6.35515 6.85999 9.06269 4.74998 12.2606 4.74998Z" fill="#EA4335"></path>
                                    </svg>
                                    Login with Google
                                </a>
                            </div>
                            <div class="col">
                                <a class="btn btn-outline w-100" href="#">
                                    <svg class="icon me-1" aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M17.9954 12.6879C18.0263 16.0172 20.915 17.1251 20.947 17.1393C20.9225 17.2173 20.4854 18.7181 19.4251 20.2682C18.5084 21.6085 17.5571 22.9437 16.0585 22.9714C14.586 22.9985 14.1124 22.0979 12.4289 22.0979C10.7459 22.0979 10.2198 22.9437 8.82587 22.9985C7.37932 23.0533 6.27775 21.5493 5.35356 20.214C3.465 17.4827 2.02175 12.4959 3.95967 9.12967C4.92239 7.45801 6.64284 6.39944 8.51023 6.3723C9.9307 6.34519 11.2714 7.3283 12.1398 7.3283C13.0077 7.3283 14.6369 6.14603 16.3498 6.31966C17.0668 6.34952 19.0796 6.60941 20.372 8.50192C20.2679 8.56651 17.9704 9.90449 17.9954 12.6879ZM15.2279 4.51263C15.9959 3.58268 16.5128 2.2881 16.3717 1C15.2648 1.04451 13.9262 1.73794 13.1321 2.66738C12.4205 3.49044 11.7974 4.8078 11.9655 6.07041C13.1994 6.16591 14.4599 5.44317 15.2279 4.51263Z" fill="black"></path>
                                    </svg>
                                    Login with Apple
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="text-center text-body-secondary">
                    Need an account?
                    <a href="#">Sign up</a>
                </div>
            </div>
        </div>
    </div>
    <!-- SGS and necessary plugins-->
    <script src="incl/vendors/@coreui/coreui/js/coreui.bundle.min.js"></script>
    <script src="incl/vendors/simplebar/js/simplebar.min.js"></script>
    <script>
        const header = document.querySelector("header.header");

        document.addEventListener("scroll", () => {
            if (header) {
                header.classList.toggle("shadow-sm", document.documentElement.scrollTop > 0);
            }
        });
    </script>
    <script>
        const tooltipTriggerList = document.querySelectorAll('[data-coreui-toggle="tooltip"]');
        const tooltipList = [...tooltipTriggerList].map((tooltipTriggerEl) => new coreui.Tooltip(tooltipTriggerEl));
    </script>
</body>

</html>