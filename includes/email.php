<?php
/**
 * Email helper — NEVER prints, NEVER throws.
 * Writes the activation log to storage/ if writable, otherwise falls back to /tmp.
 */

function emailLogPath(): string {
    $primary = __DIR__ . '/../storage/email-log.txt';
    $dir = dirname($primary);

    // Use storage/ only if it exists writable OR we can create it
    if (@is_writable($dir) || @mkdir($dir, 0777, true)) {
        return $primary;
    }
    // Container fallback (always writable)
    return sys_get_temp_dir() . '/azkejora-email-log.txt';
}

function sendActivationEmail(string $to, string $name, string $link): bool {
    // 1) Log the link (suppressed — a failed log must NEVER break the redirect)
    $entry = '[' . date('Y-m-d H:i:s') . "] $to :: $link\n";
    @file_put_contents(emailLogPath(), $entry, FILE_APPEND | LOCK_EX);

    // 2) Best-effort real email
    $subject = 'Activate your AZ Kejora SaaS account';
    $body = "Hi $name,\n\n"
          . "Welcome to AZ Kejora SaaS. Click the link below to activate your account "
          . "and start your free trial:\n\n$link\n\n"
          . "This link expires in 24 hours.\n\n— The AZ Kejora team";
    $headers = "From: AZ Kejora SaaS <noreply@azkejora.io>\r\n";
    @mail($to, $subject, $body, $headers);

    return true;
}
