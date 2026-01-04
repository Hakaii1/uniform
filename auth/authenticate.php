<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

function restrictAccess($allowedLevels) {
    if (!isset($_SESSION['job_level']) || !in_array($_SESSION['job_level'], $allowedLevels)) {
        header("Location: login.php?error=unauthorized");
        exit();
    }
}
?>