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
    if (!isset($_SESSION['job_level'])) {
        header("Location: login.php?error=unauthorized");
        exit();
    }

    $userLevel = strtolower(trim($_SESSION['job_level']));
    $userPosition = strtolower(trim($_SESSION['position'] ?? ''));

    // 1. Define Supervisor/Leader Roles explicitly
    $supervisorRoles = [
        'supervisor a', 
        'supervisor b', 
        'supervisor c', 
        'team leader', 
        'teamlead', 
        'tl'
    ];

    // 2. Normalize the User's Role
    // Logic: If they are in the supervisor list, they are 'supervisor'.
    //        If they are NOT in that list, they are AUTOMATICALLY 'staff'.
    if (in_array($userLevel, $supervisorRoles) || in_array($userPosition, $supervisorRoles)) {
        $normalizedLevel = 'supervisor';
    } else {
        $normalizedLevel = 'staff';
    }

    // 3. Normalize the Allowed Levels (passed to this function)
    $allowed = array_map('strtolower', $allowedLevels);

    // 4. Check Access
    if (!in_array($normalizedLevel, $allowed)) {
        // Optional: Allow Supervisors to access Staff pages if needed
        if ($normalizedLevel === 'supervisor' && in_array('staff', $allowed)) {
            return; // Grant access
        }

        // Debugging (Uncomment if needed)
        // error_log("Auth Failed. User Level: $userLevel -> Normalized: $normalizedLevel. Allowed: " . implode(',', $allowed));
        
        header("Location: login.php?error=unauthorized");
        exit();
    }
}
?>