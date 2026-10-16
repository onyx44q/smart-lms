<?php
/**
 * login_settings.php — security switches for the public login screens.
 *
 * LOGIN_SHOW_ROLE_SELECTOR
 *   false (default, secure): the login and forgot-password forms do NOT show
 *   a "Role" dropdown. The user types only their email/admission number and
 *   password; the server looks the account up and reads its role from the
 *   database. This stops the public page advertising which privileged
 *   portals exist (Admin, HR, Finance…) and means the role is never taken
 *   from the browser.
 *
 *   true: restores the original behaviour (role dropdown shown and used as
 *   an extra filter when looking up the account).
 */
if (!defined('LOGIN_SHOW_ROLE_SELECTOR')) {
    define('LOGIN_SHOW_ROLE_SELECTOR', false);
}

/**
 * ALLOW_PUBLIC_LECTURER_SIGNUP
 *   false (default, secure): the public signup page creates STUDENT accounts
 *   only. Lecturer accounts are created by an administrator (Admin dashboard
 *   → Register New Lecturer) with a temporary password the lecturer must
 *   change at first login.
 *   true: restores the old behaviour where anyone could self-register as a
 *   lecturer.
 */
if (!defined('ALLOW_PUBLIC_LECTURER_SIGNUP')) {
    define('ALLOW_PUBLIC_LECTURER_SIGNUP', false);
}
