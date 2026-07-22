<?php
// ============================================================
// ADMIN-ZUGANGSDATEN
// ============================================================
// Benutzername für den Login:
define('ADMIN_USER', 'Admin');

// Passwort-Hash (NICHT das Passwort selbst!).
// Diesen Wert mit generate_hash.php erzeugen und hier einfügen.
define('ADMIN_PASS_HASH', '$2y$12$y8pxGDuXRr1D5en18kyD8uVOzd3bcTYsrb6M/SSGR5umDfsVrwVjW');

// Pfad zur Datendatei mit den Jobangeboten
define('JOBS_FILE', __DIR__ . '/../jobs.json');

// Pfad zur Datendatei mit den Website-Texten (Startseite etc.)
define('CONTENT_FILE', __DIR__ . '/../content.json');