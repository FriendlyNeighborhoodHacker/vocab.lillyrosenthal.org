<?php
declare(strict_types=1);

// Test bootstrap: load the app, then point every pdo() call at a dedicated
// test database (recreated from schema.sql on every run) so tests never touch
// the real database.

require_once __DIR__ . '/../../www/config.php';
require_once __DIR__ . '/../../www/lib/UserManagement.php';
require_once __DIR__ . '/../../www/lib/WordManagement.php';
require_once __DIR__ . '/../../www/lib/FlashcardProgress.php';
require_once __DIR__ . '/../../www/lib/CsvImport.php';
require_once __DIR__ . '/../../www/lib/WordCsvImport.php';
require_once __DIR__ . '/../../www/lib/ActivityLog.php';

const TEST_DB_NAME = 'vocab_lillyrosenthal_test';

$server = new PDO(
    'mysql:host=' . DB_HOST . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$server->exec('DROP DATABASE IF EXISTS `' . TEST_DB_NAME . '`');
$server->exec('CREATE DATABASE `' . TEST_DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

$testPdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . TEST_DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
$testPdo->exec((string)file_get_contents(__DIR__ . '/../../www/schema.sql'));

set_pdo_for_testing($testPdo);

// Helper for tests: wipe all domain tables back to a clean slate.
function test_reset_all(): void {
    $pdo = pdo();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ([
        'activity_log', 'emails_sent',
        'word_review_events', 'user_word_state', 'user_deck_positions', 'word_tags', 'tags', 'words',
        'users',
    ] as $table) {
        $pdo->exec('TRUNCATE TABLE ' . $table);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

// Helper for tests: seed a verified admin and return their UserContext.
function test_seed_admin(string $email = 'admin@example.com'): UserContext {
    $st = pdo()->prepare(
        "INSERT INTO users (first_name, last_name, email, password_hash, is_admin, email_verified_at)
         VALUES ('Admin', 'User', ?, 'hash', 1, NOW())"
    );
    $st->execute([$email]);
    $ctx = new UserContext((int)pdo()->lastInsertId(), true);
    UserContext::set($ctx);
    return $ctx;
}

// Helper for tests: seed a verified non-admin and return their UserContext.
function test_seed_user(string $email = 'user@example.com'): UserContext {
    $st = pdo()->prepare(
        "INSERT INTO users (first_name, last_name, email, password_hash, is_admin, email_verified_at)
         VALUES ('Regular', 'User', ?, 'hash', 0, NOW())"
    );
    $st->execute([$email]);
    return new UserContext((int)pdo()->lastInsertId(), false);
}
