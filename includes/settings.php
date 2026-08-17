<?php
/* Module-based admin settings store (Supabase PG). Auto-creates table + seeds defaults. */

function ensure_settings_table(PDO $pdo): void {
    if ($pdo->query("SELECT to_regclass('public.settings')")->fetchColumn()) return;

    $pdo->exec("CREATE TABLE settings (
        id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
        module VARCHAR(50)  NOT NULL DEFAULT 'general',
        key    VARCHAR(100) NOT NULL,
        value  TEXT,
        label  VARCHAR(255),
        hint   TEXT,
        updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
        UNIQUE (module, key)
    )");

    $pdo->exec("INSERT INTO settings (module, key, value, label, hint) VALUES
      ('general',  'trial_default_hours', '1',  'Default trial period (hours)',   'Applied when provisioning or extending customer trials'),
      ('einvoice', 'sst_rate',            '8',  'SST rate (%)',                   'Service tax used by the e-Invoice extraction engine'),
      ('booking',  'default_slot_min',    '60', 'Default slot interval (minutes)','Slot granularity suggested for new facilities')");
}

function get_setting(PDO $pdo, string $module, string $key, $default = null) {
    $st = $pdo->prepare("SELECT value FROM settings WHERE module = ? AND key = ?");
    $st->execute([$module, $key]);
    $v = $st->fetchColumn();
    return ($v === false || $v === null) ? $default : $v;
}

function set_setting(PDO $pdo, string $module, string $key, $value): void {
    $pdo->prepare("INSERT INTO settings (module, key, value) VALUES (?,?,?)
                  ON CONFLICT (module, key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()")
        ->execute([$module, $key, $value]);
}

function all_settings(PDO $pdo): array {
    return $pdo->query("SELECT module, key, value, label, hint FROM settings ORDER BY module, key")->fetchAll();
}
