<?php
/**
 * Email settings and sending utility for the forgot-password flow.
 *
 * Edit MAIL_FROM to match your SMTP or sendmail configuration.
 * On a default XAMPP install with no SMTP configured, mail() falls back to writing
 * the message/OTP to `api/otp_log.txt` so the flow remains fully testable locally.
 *
 * OTP_INCLUDE_DEBUG controls whether the API response to requestOtp includes the
 * plaintext OTP. Set to false in production environments.
 */

declare(strict_types=1);

define('MAIL_FROM',          getenv('MAIL_FROM')          ?: 'no-reply@spider-shop.local');
define('MAIL_FROM_NAME',     getenv('MAIL_FROM_NAME')     ?: 'Depak Tiles & Granite');
define('OTP_INCLUDE_DEBUG',  filter_var(getenv('OTP_INCLUDE_DEBUG') ?? true, FILTER_VALIDATE_BOOLEAN));
define('OTP_TTL_SECONDS',    600);     // 10 minutes
define('OTP_MAX_ATTEMPTS',   5);

/**
 * Sends an OTP email to the given recipient.
 * Logs to api/otp_log.txt on local dev or sendmail failure.
 */
function send_otp_email(string $toEmail, string $otp): bool {
    $subject = 'Your Password Reset OTP - ' . MAIL_FROM_NAME;
    
    $message = "Hello,\n\n"
             . "You requested a password reset for your " . MAIL_FROM_NAME . " account.\n"
             . "Your One-Time Password (OTP) is: {$otp}\n\n"
             . "This code is valid for " . (OTP_TTL_SECONDS / 60) . " minutes.\n"
             . "If you did not request this, please ignore this email.\n";

    $headers   = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-type: text/plain; charset=utf-8';
    $headers[] = 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>';
    $headers[] = 'Reply-To: ' . MAIL_FROM;
    $headers[] = 'X-Mailer: PHP/' . phpversion();

    $headerString = implode("\r\n", $headers);

    // Attempt standard PHP mail()
    $sent = @mail($toEmail, $subject, $message, $headerString);

    // If local dev or mail delivery failed, write to otp_log.txt for quick testing
    if (!$sent || OTP_INCLUDE_DEBUG) {
        $logFile = __DIR__ . '/otp_log.txt';
        $logEntry = sprintf(
            "[%s] To: %s | OTP: %s | Status: %s\n",
            date('Y-m-d H:i:s'),
            $toEmail,
            $otp,
            $sent ? 'SENT' : 'MAIL_FAILED_LOGGED_LOCAL'
        );
        @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    return $sent || OTP_INCLUDE_DEBUG;
}