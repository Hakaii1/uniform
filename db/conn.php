<?php
// Enable error reporting in database connection file
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/*
DATABASE CONFIGURATION GUIDE
===========================

This file connects to TWO separate databases:

1. LRNPH_E (Authentication Database)
   - Contains: lrnph_users (login accounts), lrn_master_list (user roles/info)
   - Used for: Login authentication and user authorization

2. LRNPH_OJT (Application Data Database)
   - Contains: uniform_headers, uniform_details, etc. (application data)
   - Used for: Storing and retrieving uniform inspection data

CONFIGURATION:
- Change the database names, usernames, and passwords below as needed
- Each database can have different credentials
- The server name is shared between both databases

ERROR HANDLING:
- Connection errors will show which database failed
- Query errors will show the database name and SQL statement
*/

// Database Configuration - CHANGE THESE VALUES AS NEEDED
// =================================================================================

// Server connection details
$serverName = "10.2.0.9";

// Database credentials for LRNPH_E (Authentication Database)
$authDbConfig = [
    'database' => 'LRNPH_E',           // CHANGE: Your auth database name
    'username' => 'sa',                // CHANGE: Your auth database username
    'password' => 'S3rverDB02lrn25'    // CHANGE: Your auth database password
];

// Database credentials for LRNPH_OJT (Application Data Database)
$dataDbConfig = [
    'database' => 'LRNPH_OJT',         // CHANGE: Your data database name
    'username' => 'kgulapa',           // CHANGE: Your data database username
    'password' => 'Admin?!@#'          // CHANGE: Your data database password
];

// =================================================================================

// Database configurations array
$databases = [
    'auth' => $authDbConfig['database'],     // For accounts and roles (lrnph_users, lrn_master_list)
    'data' => $dataDbConfig['database']      // For application data (uniform_headers, etc.)
];

// Create connections for both databases with their respective credentials
$connections = [];
$dbConfigs = [
    'auth' => $authDbConfig,
    'data' => $dataDbConfig
];

foreach ($dbConfigs as $key => $config) {
    try {
        // *** THE FIX IS HERE ***
        // Added "tcp:" before server name and ",1433" after it.
        $connections[$key] = new PDO(
            //"sqlsrv:Server=$serverName;Database=" . $config['database'],
            "sqlsrv:Server=tcp:$serverName,1433;Database=" . $config['database'],
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
    } catch (PDOException $e) {
        die("Database Connection Error ($key - " . $config['database'] . "): " . $e->getMessage());
    }
}

// Set default connection (application data)
$conn = $connections['data'];

// Get auth connection for login/authentication
function getAuthConnection() {
    global $connections;
    return $connections['auth'];
}

// Get data connection for application operations
function getDataConnection() {
    global $connections;
    return $connections['data'];
}

// Centralized database query function with error handling
function safeQuery($sql, $params = [], $useAuthDb = false) {
    global $authDbConfig, $dataDbConfig;
    $dbConn = $useAuthDb ? getAuthConnection() : getDataConnection();
    $dbName = $useAuthDb ? $authDbConfig['database'] . ' (Auth)' : $dataDbConfig['database'] . ' (Data)';

    try {
        $stmt = $dbConn->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        die("Database Query Error [$dbName]: " . $e->getMessage() . "<br>SQL: " . $sql);
    } catch (Exception $e) {
        die("Database Error [$dbName]: " . $e->getMessage());
    }
}

// Helper function for simple queries that return single values
function safeQuerySingle($sql, $params = [], $useAuthDb = false) {
    $stmt = safeQuery($sql, $params, $useAuthDb);
    return $stmt->fetchColumn();
}

// Helper function for queries that return multiple rows
function safeQueryAll($sql, $params = [], $useAuthDb = false) {
    $stmt = safeQuery($sql, $params, $useAuthDb);
    return $stmt->fetchAll();
}

// Helper function for queries that return single row
function safeQueryRow($sql, $params = [], $useAuthDb = false) {
    $stmt = safeQuery($sql, $params, $useAuthDb);
    return $stmt->fetch();
}
?>