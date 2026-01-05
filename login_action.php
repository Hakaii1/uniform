<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'db/conn.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user_input = $_POST['username']; // This is "40970"
    $pass_input = $_POST['password'];

    try {
        // 3. Handle Login - Single JOIN query approach
        $sql = "SELECT lu.username, lu.password, lu.status, lu.user_id,
                ml.id, ml.FirstName, ml.LastName, ml.JobLevel, ml.Department, ml.PositionTitle, ml.RoleProfile, ml.EmployeeID,
                ml.FirstName + ' ' + ml.LastName as fullname
                FROM lrnph_users lu
                LEFT JOIN lrn_master_list ml
                ON lu.username = ml.BiometricsID
                WHERE lu.username = ? AND LOWER(lu.status) = 'active'";

        $user = safeQueryRow($sql, [$user_input], true);

        if (!$user) {
            header("Location: login.php?error=invalid_credentials");
            exit();
        }

        // 2. Verify Password
        if (!password_verify($pass_input, $user['password'])) {
            header("Location: login.php?error=invalid_credentials");
            exit();
        }

        // Found the correct person!
        // Set Session Variables
        $_SESSION['user_id'] = $user['id']; // Use the master list ID
        $_SESSION['full_name'] = trim($user['fullname']);
        $_SESSION['job_level'] = $user['JobLevel'];
        $_SESSION['position'] = $user['PositionTitle'];
        $_SESSION['dept'] = $user['Department'];

        // 4. Redirect Logic
        $checkLevel = strtolower(trim($user['JobLevel']));
        $checkPosition = strtolower(trim($user['PositionTitle'] ?? ''));

        $supervisorRoles = [
            'supervisor a',
            'supervisor b',
            'supervisor c',
            'team leader',
            'teamlead',
            'tl'
        ];

        if (in_array($checkLevel, $supervisorRoles) || in_array($checkPosition, $supervisorRoles)) {
            header("Location: supervisor_dashboard.php");
        } else {
            header("Location: staff_entry.php");
        }
        exit();
    } catch (PDOException $e) {
        die("Database Error: " . $e->getMessage());
    }
} else {
    header("Location: login.php");
    exit();
}
?>