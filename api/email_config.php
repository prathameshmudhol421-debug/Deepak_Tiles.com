<?php
/**
 * Email settings for the forgot-password flow.
 *
 * Edit MAIL_FROM to match your XAMPP sendmail configuration (C:\xampp\sendmail\sendmail.ini
 * has `auth_username` and `from` settings). On a default XAMPP install with no SMTP
 * configured, mail() still succeeds but the message is dropped in C:\xampp\mailoutput\
 * — the OTP is also written to api/otp_log.txt either way, so the flow is testable
 * without real mail delivery.
 *
 * OTP_INCLUDE_DEBUG controls whether the API response to requestOtp includes the
 * plaintext OTP. Leave true for local development; set to false (or remove the
 * line) before deploying anywhere a third party can hit the API.
 */
declare(strict_types=1);

define('MAIL_FROM',          'no-reply@spider-shop.local');
define('MAIL_FROM_NAME',     'Depak Tiles & Granite');
define('OTP_INCLUDE_DEBUG',  true);
define('OTP_TTL_SECONDS',    600);     // 10 minutes
define('OTP_MAX_ATTEMPTS',   5);