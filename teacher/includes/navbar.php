<?php
/* ======================================================================
 * TEACHER PANEL - SHARED TOP NAVBAR (CoreUI app header)
 * ======================================================================
 * UI shell only. Loaded by teacher/includes/header.php.
 * Same CoreUI app-header, breadcrumb and dropdown look as reference.
 *
 * Optional variables (set by the including page before header.php):
 *     $UI_TITLE        - current page title (shown in breadcrumb)
 *     $UI_CRUMBS       - breadcrumb trail, e.g.
 *                        [['label' => 'Attendance', 'url' => 'index.php']]
 *     $UI_PROFILE_NAME - teacher display name (shown in profile dropdown)
 * ====================================================================== */

if (!isset($UI_ROOT)) {
    $UI_ROOT = '';
}

$t_ui_title = $UI_TITLE ?? 'Dashboard';

$t_ui_crumbs = [];

if (isset($UI_CRUMBS) && is_array($UI_CRUMBS)) {
    $t_ui_crumbs = $UI_CRUMBS;
}

$t_ui_profile_name = 'Teacher';

if (isset($UI_PROFILE_NAME) && trim((string) $UI_PROFILE_NAME) !== '') {
    $t_ui_profile_name = (string) $UI_PROFILE_NAME;
} elseif (isset($_SESSION['name']) && trim((string) $_SESSION['name']) !== '') {
    $t_ui_profile_name = (string) $_SESSION['name'];
}
?>
<header class="header header-sticky p-0 mb-4">
  <div class="container-fluid border-bottom px-4">
    <button class="header-toggler" type="button" onclick="coreui.Sidebar.getInstance(document.querySelector('#sidebar')).toggle()" style="margin-inline-start: -14px">
      <svg class="icon icon-lg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
        <path fill="var(--ci-primary-color, currentcolor)" d="M80 96h352v32H80zm0 144h352v32H80zm0 144h352v32H80z" class="ci-primary" />
      </svg>
    </button>
    <ul class="header-nav ms-auto">
      <li class="nav-item">
        <a class="nav-link" href="<?php echo htmlspecialchars($UI_ROOT . 'notices/index.php', ENT_QUOTES, 'UTF-8'); ?>">
          <svg class="icon icon-lg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
            <path fill="var(--ci-primary-color, currentcolor)" d="m450.27 348.569-43.67-80.624V184c0-83.813-68.187-152-152-152s-152 68.187-152 152v83.945l-43.672 80.623A24 24 0 0 0 80.031 384h86.935a89 89 0 0 0-.367 8 88 88 0 0 0 176 0c0-2.7-.129-5.364-.367-8h86.935a24 24 0 0 0 21.1-35.431ZM310.6 392a56 56 0 1 1-111.419-8h110.837a56 56 0 0 1 .582 8M93.462 352l41.138-75.945V184a120 120 0 1 1 240 0v92.055L415.736 352Z" class="ci-primary" />
          </svg>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="<?php echo htmlspecialchars($UI_ROOT . 'timetable/index.php', ENT_QUOTES, 'UTF-8'); ?>">
          <svg class="icon icon-lg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
            <path fill="var(--ci-primary-color, currentcolor)" d="M136 24H16v120h120Zm-32 88H48V56h56Zm32 88H16v120h120Zm-32 88H48v-56h56Zm32 88H16v120h120Zm-32 88H48v-56h56Zm72-440.002h320v32H176zm0 88h256v32H176zm0 88h320v32H176zm0 88h256v32H176zm0 176h256v32H176zm0-88h320v32H176z" class="ci-primary" />
          </svg>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="<?php echo htmlspecialchars($UI_ROOT . 'homework/index.php', ENT_QUOTES, 'UTF-8'); ?>">
          <svg class="icon icon-lg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
            <path fill="var(--ci-primary-color, currentcolor)" d="M274.6 25.623a32.01 32.01 0 0 0-37.2 0L16 183.766V496h480V183.766ZM464 402.693 339.97 322.96 464 226.492ZM256 51.662 454.429 193.4 311.434 304.615 256 268.979l-55.434 35.636L57.571 193.4ZM48 226.492l124.03 96.468L48 402.693ZM464 464H48v-23.265l208-133.714 208 133.714Z" class="ci-primary" />
          </svg>
        </a>
      </li>
    </ul>


    <ul class="header-nav">
      <li class="nav-item py-1">
        <div class="vr h-100 mx-2 text-body text-opacity-75"></div>
      </li>
      <li class="nav-item dropdown">
        <button class="btn btn-link nav-link py-2 px-2 d-flex align-items-center" type="button" aria-expanded="false" data-coreui-toggle="dropdown">
          <svg class="icon icon-lg theme-icon-active" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
            <path fill="var(--ci-primary-color, currentcolor)" d="M256 16C123.452 16 16 123.452 16 256s107.452 240 240 240 240-107.452 240-240S388.548 16 256 16m-22 446.849a208.35 208.35 0 0 1-169.667-125.9c-.364-.859-.706-1.724-1.057-2.587L234 429.939Zm0-69.582L50.889 290.76A210 210 0 0 1 48 256q0-9.912.922-19.67L234 339.939Zm0-90L54.819 202.96a206 206 0 0 1 9.514-27.913Q67.1 168.5 70.3 162.191L234 253.934Zm0-86.015L86.914 134.819a209.4 209.4 0 0 1 22.008-25.9q3.72-3.72 7.6-7.228L234 166.027Zm0-87.708-89.648-49.093A206.95 206.95 0 0 1 234 49.151ZM464 256a207.775 207.775 0 0 1-198 207.761V48.239A207.79 207.79 0 0 1 464 256" class="ci-primary" />
          </svg>
        </button>
        <ul class="dropdown-menu dropdown-menu-end" style="--cui-dropdown-min-width: 8rem">
          <li>
            <button class="dropdown-item d-flex align-items-center" type="button" data-coreui-theme-value="light">
              <svg class="icon icon-lg me-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
                <path fill="var(--ci-primary-color, currentcolor)" d="M256 104c-83.813 0-152 68.187-152 152s68.187 152 152 152 152-68.187 152-152-68.187-152-152-152m0 272a120 120 0 1 1 120-120 120.136 120.136 0 0 1-120 120M240 16h32v48h-32zm0 432h32v48h-32zm208-208h48v32h-48zm-432 0h48v32H16zm372.687 171.314 22.627-22.627 32 32-22.627 22.627zm-320-320 22.628-22.628 32 32-22.628 22.628z" class="ci-primary" />
              </svg>Light
            </button>
          </li>
          <li>
            <button class="dropdown-item d-flex align-items-center" type="button" data-coreui-theme-value="dark">
              <svg class="icon icon-lg me-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
                <path fill="var(--ci-primary-color, currentcolor)" d="M268.279 496c-67.574 0-130.978-26.191-178.534-73.745S16 311.293 16 243.718A252.25 252.25 0 0 1 154.183 18.676a24.44 24.44 0 0 1 34.46 28.958" class="ci-primary" />
              </svg>Dark
            </button>
          </li>
          <li>
            <button class="dropdown-item d-flex align-items-center" type="button" data-coreui-theme-value="auto">
              <svg class="icon icon-lg me-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
                <path fill="var(--ci-primary-color, currentcolor)" d="M272 288h208v96H272zm-48-32h-48v32h48zm16-96h-32v32h32zm-32-96h16v48h-16zm80-32h96v96h-96zm32 32h32v32h-32zm-80 288H64v96h176z" class="ci-primary" />
              </svg>Auto
            </button>
          </li>
        </ul>
      </li>
      <li class="nav-item py-1">
        <div class="vr h-100 mx-2 text-body text-opacity-75"></div>
      </li>
      <li class="nav-item dropdown">
        <button class="btn btn-link nav-link py-2 px-2 d-flex align-items-center" type="button" aria-expanded="false" data-coreui-toggle="dropdown">
          <div class="avatar avatar-md">
            <img class="avatar-img" src="<?php echo htmlspecialchars($UI_ROOT . '../incl/assets/img/avatars/8.jpg', ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($t_ui_profile_name, ENT_QUOTES, 'UTF-8'); ?>">
            <span class="avatar-status bg-success"></span>
          </div>
        </button>
        <ul class="dropdown-menu dropdown-menu-end pt-0">
          <li>
            <h6 class="dropdown-header bg-body-tertiary text-body-secondary fw-semibold rounded-top mb-2">Account</h6>
          </li>
          <li>
            <a class="dropdown-item" href="<?php echo htmlspecialchars($UI_ROOT . 'profile/index.php', ENT_QUOTES, 'UTF-8'); ?>">
              <svg class="icon me-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
                <path fill="var(--ci-primary-color, currentcolor)" d="m411.6 343.656-72.823-47.334 27.455-50.334A80.2 80.2 0 0 0 376 207.681V128a112 112 0 0 0-224 0v79.681a80.24 80.24 0 0 0 9.768 38.308l27.455 50.333-72.823 47.334A79.72 79.72 0 0 0 80 410.732V496h368v-85.268a79.73 79.73 0 0 0-36.4-67.076M416 464H112v-53.268a47.84 47.84 0 0 1 21.841-40.246l97.66-63.479-41.64-76.341A48.15 48.15 0 0 1 184 207.681V128a80 80 0 0 1 160 0v79.681a48.15 48.15 0 0 1-5.861 22.985L296.5 307.007l97.662 63.479A47.84 47.84 0 0 1 416 410.732Z" class="ci-primary" />
              </svg>
              <?php echo htmlspecialchars($t_ui_profile_name, ENT_QUOTES, 'UTF-8'); ?>
            </a>
          </li>
          <li>
            <h6 class="dropdown-header bg-body-tertiary text-body-secondary fw-semibold my-2">Settings</h6>
          </li>
          <li>
            <a class="dropdown-item" href="<?php echo htmlspecialchars($UI_ROOT . 'profile/index.php', ENT_QUOTES, 'UTF-8'); ?>">
              <svg class="icon me-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
                <path fill="var(--ci-primary-color, currentcolor)" d="m411.6 343.656-72.823-47.334 27.455-50.334A80.2 80.2 0 0 0 376 207.681V128a112 112 0 0 0-224 0v79.681a80.24 80.24 0 0 0 9.768 38.308l27.455 50.333-72.823 47.334A79.72 79.72 0 0 0 80 410.732V496h368v-85.268a79.73 79.73 0 0 0-36.4-67.076M416 464H112v-53.268a47.84 47.84 0 0 1 21.841-40.246l97.66-63.479-41.64-76.341A48.15 48.15 0 0 1 184 207.681V128a80 80 0 0 1 160 0v79.681a48.15 48.15 0 0 1-5.861 22.985L296.5 307.007l97.662 63.479A47.84 47.84 0 0 1 416 410.732Z" class="ci-primary" />
              </svg>
              Profile
            </a>
          </li>
          <li>
            <a class="dropdown-item" href="<?php echo htmlspecialchars($UI_ROOT . 'profile/change-password.php', ENT_QUOTES, 'UTF-8'); ?>">
              <svg class="icon me-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
                <path fill="var(--ci-primary-color, currentcolor)" d="M384 200v-56a128 128 0 0 0-256 0v56H88v128c0 92.635 75.364 168 168 168s168-75.365 168-168V200Zm-224-56a96 96 0 0 1 192 0v56H160Zm232 184c0 74.99-61.01 136-136 136s-136-61.01-136-136v-96h272Z" class="ci-primary" />
              </svg>
              Change Password
            </a>
          </li>
          <div class="dropdown-divider"></div>
          <li>
            <a class="dropdown-item" href="<?php echo htmlspecialchars($UI_ROOT . '../logout.php', ENT_QUOTES, 'UTF-8'); ?>">
              <svg class="icon me-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
                <path fill="var(--ci-primary-color, currentcolor)" d="M77.155 272.034H351.75v-32.001H77.155l75.053-75.053v-.001l-22.628-22.626-113.681 113.68.001.001h-.001L129.58 369.715l22.628-22.627v-.001z" class="ci-primary" />
                <path fill="var(--ci-primary-color, currentcolor)" d="M160 16v32h304v416H160v32h336V16z" class="ci-primary" />
              </svg>
              Logout
            </a>
          </li>
        </ul>
      </li>
    </ul>
  </div>
  <div class="container-fluid px-4">
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb my-0">
        <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars($UI_ROOT . 'dashboard.php', ENT_QUOTES, 'UTF-8'); ?>">Home</a></li>
        <?php foreach ($t_ui_crumbs as $t_ui_crumb): ?>
        <li class="breadcrumb-item">
          <?php if (!empty($t_ui_crumb['url'])): ?>
          <a href="<?php echo htmlspecialchars($t_ui_crumb['url'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($t_ui_crumb['label'], ENT_QUOTES, 'UTF-8'); ?></a>
          <?php else: ?>
          <span><?php echo htmlspecialchars($t_ui_crumb['label'], ENT_QUOTES, 'UTF-8'); ?></span>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
        <li class="breadcrumb-item active"><span><?php echo htmlspecialchars($t_ui_title, ENT_QUOTES, 'UTF-8'); ?></span></li>
      </ol>
    </nav>
  </div>
</header>

