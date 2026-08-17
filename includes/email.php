<?php
/** Email helper. Uses PHP mail(); logs link to disk as fallback so you can copy-paste
    activation URLs even on hosts without SMTP configured. */

function sendActivationEmail(string $to, string $name, string $link): bool {
    $log = __DIR__ . '/../storage/email-log.txt';
    @mkdir(dirname($log), 0777, true);
    $entry = "[".date('Y-m-d H:i:s')."] $to :: $link\n";
    file_put_contents($log, $entry, FILE_APPEND);

    $subject = "Activate your AZ Kejora SaaS account";
    $body = "Hi $name,\n\n"
          . "Welcome to AZ Kejora SaaS. Click the link below to activate your account "
          . "and start your free trial:\n\n$link\n\n"
          . "This link expires in 24 hours.\n\n— The AZ Kejora team";
    $headers = "From: AZ Kejora SaaS <noreply@azkejora.io>\r\n";
    @mail($to, $subject, $body, $headers);
    return true;
}
