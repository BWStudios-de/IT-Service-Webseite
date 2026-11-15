<?php
// Kopiere diese Datei zu "mail-config.php" und trage dort die echten Zugangsdaten ein.
// mail-config.php ist in .gitignore und wird NIE ins Repository eingecheckt.

return [
    'smtp_host' => 'bwstudios.de',
    'smtp_port' => 465,          // 465 = SSL (Plesk-Vorgabe fuer bwstudios.de)
    'smtp_secure' => 'ssl',
    'smtp_user' => 'info@bwstudios.de',
    'smtp_pass' => 'HIER_DAS_ECHTE_POSTFACH_PASSWORT_EINTRAGEN',
    'mail_to' => 'info@bwstudios.de',
    'mail_from' => 'info@bwstudios.de',
    'mail_from_name' => 'Kontaktformular it.bwstudios.de',
];
