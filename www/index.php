<?php
// Homepage: the whole app is the flashcard review flow, so send signed-in
// users there and everyone else to the login page.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Application.php';

Application::init();

if (current_user()) {
    header('Location: /review/');
} else {
    header('Location: /login.php');
}
exit;
