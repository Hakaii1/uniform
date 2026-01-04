<?php
session_start();
require_once 'db/conn.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user_input = $_POST['username'];
    $pass_input = $_POST['password'];

    // This query JOINs the two tables using id and EmployeeID
    $sql = "SELECT m.id, m.FullName, m.JobLevel, m.Department 
            FROM accounts a
            JOIN lrn_master_list m ON a.EmployeeID = m.id
            WHERE a.Username = ? AND a.Password = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$user_input, $pass_input]);
    $user = $stmt->fetch();

    if ($user) {
        // Use 'id' from lrn_master_list as the session user_id
        $_SESSION['user_id'] = $user['id']; 
        $_SESSION['full_name'] = $user['FullName'];
        $_SESSION['job_level'] = $user['JobLevel'];
        $_SESSION['dept'] = $user['Department'];

        // Redirect based on JobLevel found in lrn_master_list
        if ($user['JobLevel'] === 'Supervisor' || $user['JobLevel'] === 'Team Leader') {
            header("Location: supervisor_dashboard.php");
        } else {
            header("Location: staff_entry.php");
        }
        exit();
    } else {
        header("Location: login.php?error=invalid_credentials");
        exit();
    }
}
?>