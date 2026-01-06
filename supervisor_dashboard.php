<?php
require_once 'db/conn.php';
require_once 'auth/authenticate.php';
restrictAccess(['Supervisor', 'Team Leader']);

// -------------------------------------------------------------------------
// 1. HEADER PROFILE LOGIC: Fetch Logged-in Supervisor's EmployeeID
// -------------------------------------------------------------------------
$currentFullName = $_SESSION['full_name'];
$myEmployeeID = null;

// Query the master list using the Full Name to get the ID
$empIdStmt = $conn->prepare("SELECT TOP 1 EmployeeID FROM LRNPH_E.dbo.lrn_master_list WHERE (FirstName + ' ' + LastName) = ?");
$empIdStmt->execute([$currentFullName]);
$myEmployeeID = $empIdStmt->fetchColumn();

// We set the default URL to .jpg. The JS will handle the switch if it's .png/.jpeg
$supervisorProfileUrl = $myEmployeeID ? "http://10.2.0.8/lrnph/emp_photos/" . $myEmployeeID . ".jpg" : null;
// -------------------------------------------------------------------------

// Stats
$pendingCount = $conn->query("SELECT COUNT(*) FROM uniform_headers WHERE Status = 'Pending'")->fetchColumn();
$approvedToday = $conn->query("SELECT COUNT(*) FROM uniform_headers WHERE Status = 'Approved' AND CAST(DateUpdated AS DATE) = CAST(GETDATE() AS DATE)")->fetchColumn();
$totalWashed = $conn->query("SELECT SUM(d.QtyWashed) FROM uniform_details d JOIN uniform_headers h ON d.InspectionID = h.InspectionID WHERE h.Status = 'Approved'")->fetchColumn() ?? 0;
$totalRepair = $conn->query("SELECT SUM(d.QtyRepair) FROM uniform_details d JOIN uniform_headers h ON d.InspectionID = h.InspectionID WHERE h.Status = 'Approved'")->fetchColumn() ?? 0;
$totalDisposal = $conn->query("SELECT SUM(d.QtyDisposal) FROM uniform_details d JOIN uniform_headers h ON d.InspectionID = h.InspectionID WHERE h.Status = 'Approved'")->fetchColumn() ?? 0;

// --- Get Filter & Pagination Parameters ---
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$staff_search = $_GET['staff_search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 5; 

// --- Sorting Logic ---
$sort_by_raw = $_GET['sort_by'] ?? 'DateCreated';
$sort_order_raw = $_GET['sort_order'] ?? 'DESC';
$allowed_sort = ['DateCreated', 'StaffName', 'InspectionID'];
$sort_by = in_array($sort_by_raw, $allowed_sort) ? $sort_by_raw : 'DateCreated';
$sort_order = strtoupper($sort_order_raw) === 'ASC' ? 'ASC' : 'DESC';

$sort_column_map = [
    'DateCreated' => 'h.DateCreated',
    'StaffName' => 'm.FirstName', 
    'InspectionID' => 'h.InspectionID'
];
$sort_sql_column = $sort_column_map[$sort_by];

if ($sort_by !== 'InspectionID') {
    $order_by = "ORDER BY $sort_sql_column $sort_order, h.InspectionID DESC";
} else {
    $order_by = "ORDER BY $sort_sql_column $sort_order";
}

// --- Build WHERE clause ---
$where_conditions = ["h.Status = 'Pending'"];
$params = [];

if (!empty($date_from)) {
    $where_conditions[] = "CAST(h.DateCreated AS DATE) >= :date_from";
    $params[':date_from'] = $date_from;
}
if (!empty($date_to)) {
    $where_conditions[] = "CAST(h.DateCreated AS DATE) <= :date_to";
    $params[':date_to'] = $date_to;
}
if (!empty($staff_search)) {
    $where_conditions[] = "(m.FirstName + ' ' + m.LastName) LIKE :staff_search";
    $params[':staff_search'] = '%' . $staff_search . '%';
}

$where_clause = implode(' AND ', $where_conditions);

// --- Get total count for pagination ---
$count_sql = "SELECT COUNT(DISTINCT h.InspectionID) 
              FROM uniform_headers h 
              LEFT JOIN LRNPH_E.dbo.lrn_master_list m ON h.StaffUID = m.id 
              WHERE $where_clause";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);
$offset = ($page - 1) * $per_page;

// --- Fetch pending inspections with pagination ---
$sql = "SELECT DISTINCT h.InspectionID, h.DateCreated, h.DateUpdated, h.Status, h.SupervisorSign, h.StaffUID,
        m.FirstName, m.LastName, m.EmployeeID
        FROM uniform_headers h
        LEFT JOIN LRNPH_E.dbo.lrn_master_list m ON h.StaffUID = m.id 
        WHERE $where_clause
        $order_by
        OFFSET :offset ROWS FETCH NEXT :per_page ROWS ONLY";

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
$stmt->execute();
$headers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Get staff names/IDs ---
$staff_names = [];
$staff_ids = [];

if (!empty($headers)) {
    $staff_uids = array_values(array_unique(array_column($headers, 'StaffUID')));
    
    if (!empty($staff_uids)) {
        $placeholders = implode(',', array_fill(0, count($staff_uids), '?'));
        $staff_sql = "SELECT id, FirstName, LastName, EmployeeID FROM lrn_master_list WHERE id IN ($placeholders)";
        
        if (function_exists('safeQueryAll')) {
            $staff_results = safeQueryAll($staff_sql, $staff_uids, true); 
        } else {
            $staff_stmt = $conn->prepare($staff_sql);
            $staff_stmt->execute($staff_uids);
            $staff_results = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        foreach ($staff_results as $staff) {
            $staff_names[$staff['id']] = trim($staff['FirstName'] . ' ' . $staff['LastName']);
            $staff_ids[$staff['id']] = $staff['EmployeeID'];
        }
    }
}

// Map back to headers
foreach ($headers as &$header) {
    if (!empty($header['FirstName'])) {
        $header['StaffName'] = trim($header['FirstName'] . ' ' . $header['LastName']);
    } else {
        $header['StaffName'] = $staff_names[$header['StaffUID']] ?? 'Unknown Staff';
        $header['EmployeeID'] = $staff_ids[$header['StaffUID']] ?? null;
    }
}
unset($header); 

// --- Get inspection Details ---
$inspection_ids = array_column($headers, 'InspectionID');
$inspections = [];

if (!empty($inspection_ids)) {
    $inspection_ids = array_values($inspection_ids);
    $placeholders = implode(',', array_fill(0, count($inspection_ids), '?'));
    
    $details_sql = "SELECT h.InspectionID, h.DateCreated, h.DateUpdated, h.Status, h.SupervisorSign, h.StaffUID,
                    d.DetailID, d.ItemCode, d.Description, d.RemovalOfDirt, d.QtyWashed, d.QtyRepair, d.QtyDisposal, d.Remarks, d.StaffPhoto
                    FROM uniform_headers h
                    LEFT JOIN uniform_details d ON h.InspectionID = d.InspectionID
                    WHERE h.InspectionID IN ($placeholders)
                    ORDER BY h.InspectionID, d.ItemCode";
    $details_stmt = $conn->prepare($details_sql);
    $details_stmt->execute($inspection_ids);
    $rows = $details_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $id = $row['InspectionID'];
        $staff_name = $staff_names[$row['StaffUID']] ?? 'Unknown Staff';
        
        if (!isset($inspections[$id])) {
            $inspections[$id] = [
                'header' => [
                    'InspectionID' => $row['InspectionID'], 
                    'DateCreated' => $row['DateCreated'], 
                    'StaffName' => $staff_name,
                    'EmployeeID' => null
                ], 
                'items' => []
            ];
        }
        if ($row['DetailID']) $inspections[$id]['items'][] = $row;
    }
}

$sorted_inspections = [];
foreach ($headers as $h) {
    if (isset($inspections[$h['InspectionID']])) {
        $inspections[$h['InspectionID']]['header']['StaffName'] = $h['StaffName'];
        $inspections[$h['InspectionID']]['header']['EmployeeID'] = $h['EmployeeID'];
        $sorted_inspections[] = $inspections[$h['InspectionID']];
    }
}
$inspections = $sorted_inspections;


// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve'])) {
        $inspection_id = $_POST['inspection_id'] ?? null;
        if ($inspection_id) {
            if (empty($_FILES['signature']['name'])) { 
                $error = "Please upload signature."; 
            } else {
                $target_dir = "uploads/signatures/";
                if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
                $file_ext = strtolower(pathinfo($_FILES["signature"]["name"], PATHINFO_EXTENSION));
                $new_filename = "sign_" . $inspection_id . "_" . time() . "." . $file_ext;
                if (move_uploaded_file($_FILES["signature"]["tmp_name"], $target_dir . $new_filename)) {
                    if (!empty($_POST['codes'])) {
                        foreach ($_POST['codes'] as $detail_id => $code) {
                            $conn->prepare("UPDATE uniform_details SET ItemCode = ? WHERE DetailID = ?")->execute([trim($code), $detail_id]);
                        }
                    }
                    $conn->prepare("UPDATE uniform_headers SET Status = 'Approved', SupervisorUID = ?, DateUpdated = GETDATE(), SupervisorSign = ? WHERE InspectionID = ?")->execute([$_SESSION['user_id'], $target_dir . $new_filename, $inspection_id]);
                    header("Location: supervisor_dashboard.php?msg=approved"); exit;
                }
            }
        }
    } 
    elseif (isset($_POST['confirm_reject'])) {
        $inspection_id = $_POST['reject_inspection_id'] ?? null;
        $reason = $_POST['rejection_reason'] ?? null;
        
        if ($inspection_id) {
            $stmt = $conn->prepare("UPDATE uniform_headers SET Status = 'Rejected', SupervisorUID = ?, DateUpdated = GETDATE(), RejectionReason = ? WHERE InspectionID = ?");
            $stmt->execute([$_SESSION['user_id'], $reason, $inspection_id]);
            header("Location: supervisor_dashboard.php?msg=rejected"); exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Supervisor Dashboard • La Rose Noire</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="styles/style.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#f472b6',
                        'primary-dark': '#ec4899',
                        secondary: '#a78bfa',
                        success: '#34d399',
                        warning: '#fbbf24',
                        danger: '#f87171'
                    }
                }
            }
        }
        
        // --- Handle Image Extension Fallback (jpg -> png -> jpeg) ---
        function handleAvatarError(img, empId, fallbackId) {
            if (!empId) {
                img.style.display = 'none';
                if(document.getElementById(fallbackId)) document.getElementById(fallbackId).style.display = 'flex';
                return;
            }

            const currentSrc = img.src;
            const baseUrl = "http://10.2.0.8/lrnph/emp_photos/";
            
            // Try chain: .jpg (default) -> .png -> .jpeg -> fallback
            if (currentSrc.includes('.jpg')) {
                img.src = baseUrl + empId + ".png";
            } else if (currentSrc.includes('.png')) {
                img.src = baseUrl + empId + ".jpeg";
            } else {
                // All failed
                img.style.display = 'none';
                if(document.getElementById(fallbackId)) {
                    document.getElementById(fallbackId).style.display = 'flex';
                }
            }
        }
    </script>
</head>
<body class="h-screen flex overflow-hidden">

    <aside class="glass-sidebar flex flex-col z-20 w-80 transition-all duration-300">
        <div class="p-8 border-b border-white/20">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 bg-gradient-to-r from-pink-400 to-purple-400 rounded-2xl flex items-center justify-center shadow-lg">
                    <i class="fas fa-spa text-2xl text-white"></i>
                </div>
                <div>
                    <h2 class="text-3xl font-black text-primary tracking-tight">La Rose Noire</h2>
                    <p class="text-xs font-bold text-gray-500 mt-1 uppercase tracking-widest">Facilities Management Department</p>
                </div>
            </div>
        </div>

        <nav class="flex-1 px-6 py-8 space-y-4">
            <a href="supervisor_dashboard.php" class="nav-item active flex items-center space-x-4 px-6 py-5 rounded-2xl bg-gradient-to-r from-primary to-primary-dark text-white shadow-lg shadow-pink-200/50 transition-all duration-300 group">
                <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform">
                    <i class="fas fa-clipboard-check text-xl"></i>
                </div>
                <span class="font-bold text-lg">Uniform Inspections</span>
            </a>

            <a href="supervisor_reports.php" class="nav-item flex items-center space-x-4 px-6 py-5 rounded-2xl text-gray-500 hover:bg-pink-50/50 hover:text-primary transition-all duration-300 group">
                <div class="w-12 h-12 bg-gray-100 rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform">
                    <i class="fas fa-chart-bar text-xl"></i>
                </div>
                <span class="font-bold text-lg">Reports & History</span>
            </a>
        </nav>

        <div class="p-6 border-t border-white/20">
            <!-- Logo Footer -->
            <div class="flex flex-col items-center mb-4">
                <img src="images/logo.png" alt="Logo" class="h-20 w-auto opacity-80 mb-2">
                <hr class="w-full border-gray-500">
            </div>
            <a href="logout.php" class="flex items-center space-x-4 px-4 py-3 rounded-xl text-gray-500 hover:bg-red-50 hover:text-red-400 transition-all duration-300 group">
                <div class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform">
                    <i class="fas fa-sign-out-alt"></i>
                </div>
                <span class="font-semibold">Logout</span>
            </a>
        </div>
    </aside>

    <main class="flex-1 overflow-y-auto relative">
        <header class="flex justify-between items-center p-8 pb-6">
            <div>
                <h1 class="text-4xl font-bold text-gray-800 mb-2">Pending Inspections</h1>
                <p class="text-gray-500 text-lg">Review and approve uniform inspection requests</p>
            </div>
            <div class="flex items-center gap-4 bg-white/60 backdrop-blur-md px-6 py-3 rounded-full shadow-sm">
                <div class="text-right hidden md:block">
                    <div class="text-sm font-bold text-gray-800"><?php echo $_SESSION['full_name']; ?></div>
                    <div class="text-xs text-primary font-bold uppercase"><?php echo htmlspecialchars($_SESSION['position']); ?></div>
                </div>
                
                <div class="relative w-12 h-12 rounded-full overflow-hidden shadow-md bg-gray-200">
                    <?php if ($supervisorProfileUrl): ?>
                        <img src="<?php echo htmlspecialchars($supervisorProfileUrl); ?>" 
                             alt="Profile" 
                             class="w-full h-full object-cover"
                             onerror="handleAvatarError(this, '<?php echo $myEmployeeID; ?>', 'header-fallback-avatar')">
                    <?php endif; ?>

                    <div id="header-fallback-avatar" 
                         class="<?php echo $supervisorProfileUrl ? 'hidden' : 'flex'; ?> w-full h-full bg-gradient-to-tr from-primary to-purple-400 items-center justify-center text-white font-bold text-xl absolute top-0 left-0">
                        <?php echo strtoupper(substr($_SESSION['full_name'], 0, 1)); ?>
                    </div>
                </div>
            </div>
        </header>

        <div class="px-8 pb-6">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-6">
                <div class="card p-6 relative overflow-hidden text-center group cursor-pointer">
                    <div class="absolute -right-8 -top-8 w-32 h-32 bg-gradient-to-br from-orange-100 to-orange-200 rounded-full opacity-60 group-hover:scale-110 transition-transform duration-500"></div>
                    <div class="relative z-10">
                        <div class="w-16 h-16 bg-gradient-to-r from-orange-400 to-orange-500 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg group-hover:shadow-xl transition-shadow">
                            <i class="fas fa-clock text-3xl text-white"></i>
                        </div>
                        <div class="text-5xl font-black text-gray-700 mb-2 group-hover:scale-105 transition-transform">
                            <?php echo $pendingCount; ?>
                        </div>
                        <p class="text-sm font-bold text-gray-500 uppercase tracking-wider">Pending Reviews</p>
                        <div class="mt-4 w-12 h-1 bg-gradient-to-r from-orange-400 to-orange-500 rounded-full mx-auto group-hover:w-16 transition-all"></div>
                    </div>
                </div>
                <div class="card p-6 relative overflow-hidden text-center group cursor-pointer">
                    <div class="absolute -right-8 -top-8 w-32 h-32 bg-gradient-to-br from-green-100 to-emerald-100 rounded-full opacity-60 group-hover:scale-110 transition-transform duration-500"></div>
                    <div class="relative z-10">
                        <div class="w-16 h-16 bg-gradient-to-r from-green-400 to-emerald-500 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg group-hover:shadow-xl transition-shadow">
                            <i class="fas fa-check-circle text-3xl text-white"></i>
                        </div>
                        <div class="text-5xl font-black text-gray-700 mb-2 group-hover:scale-105 transition-transform">
                            <?php echo $approvedToday; ?>
                        </div>
                        <p class="text-sm font-bold text-gray-500 uppercase tracking-wider">Approved Today</p>
                        <div class="mt-4 w-12 h-1 bg-gradient-to-r from-green-400 to-emerald-500 rounded-full mx-auto group-hover:w-16 transition-all"></div>
                    </div>
                </div>
                <div class="card p-6 relative overflow-hidden text-center group cursor-pointer">
                    <div class="absolute -right-8 -top-8 w-32 h-32 bg-gradient-to-br from-pink-100 to-rose-100 rounded-full opacity-60 group-hover:scale-110 transition-transform duration-500"></div>
                    <div class="relative z-10">
                        <div class="w-16 h-16 bg-gradient-to-r from-pink-400 to-rose-500 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg group-hover:shadow-xl transition-shadow">
                            <i class="fas fa-tshirt text-3xl text-white"></i>
                        </div>
                        <div class="text-5xl font-black text-gray-700 mb-2 group-hover:scale-105 transition-transform">
                            <?php echo $totalWashed; ?>
                        </div>
                        <p class="text-sm font-bold text-gray-500 uppercase tracking-wider">Items Washed</p>
                        <div class="mt-4 w-12 h-1 bg-gradient-to-r from-pink-400 to-rose-500 rounded-full mx-auto group-hover:w-16 transition-all"></div>
                    </div>
                </div>
                <div class="card p-6 relative overflow-hidden text-center group cursor-pointer">
                    <div class="absolute -right-8 -top-8 w-32 h-32 bg-gradient-to-br from-blue-100 to-indigo-100 rounded-full opacity-60 group-hover:scale-110 transition-transform duration-500"></div>
                    <div class="relative z-10">
                        <div class="w-16 h-16 bg-gradient-to-r from-blue-400 to-indigo-500 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg group-hover:shadow-xl transition-shadow">
                            <i class="fas fa-tools text-3xl text-white"></i>
                        </div>
                        <div class="text-5xl font-black text-gray-700 mb-2 group-hover:scale-105 transition-transform">
                            <?php echo $totalRepair; ?>
                        </div>
                        <p class="text-sm font-bold text-gray-500 uppercase tracking-wider">Items for Repair</p>
                        <div class="mt-4 w-12 h-1 bg-gradient-to-r from-blue-400 to-indigo-500 rounded-full mx-auto group-hover:w-16 transition-all"></div>
                    </div>
                </div>
                <div class="card p-6 relative overflow-hidden text-center group cursor-pointer">
                    <div class="absolute -right-8 -top-8 w-32 h-32 bg-gradient-to-br from-red-100 to-rose-100 rounded-full opacity-60 group-hover:scale-110 transition-transform duration-500"></div>
                    <div class="relative z-10">
                        <div class="w-16 h-16 bg-gradient-to-r from-red-400 to-rose-500 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg group-hover:shadow-xl transition-shadow">
                            <i class="fas fa-trash-alt text-3xl text-white"></i>
                        </div>
                        <div class="text-5xl font-black text-gray-700 mb-2 group-hover:scale-105 transition-transform">
                            <?php echo $totalDisposal; ?>
                        </div>
                        <p class="text-sm font-bold text-gray-500 uppercase tracking-wider">Items for Disposal</p>
                        <div class="mt-4 w-12 h-1 bg-gradient-to-r from-red-400 to-rose-500 rounded-full mx-auto group-hover:w-16 transition-all"></div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (isset($_GET['msg'])): ?>
            <div id="notification-toast" class="mx-8 mb-6 transition-opacity duration-500 ease-out">
                <div class="p-4 rounded-2xl shadow-lg <?php echo $_GET['msg'] === 'approved' ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200'; ?>">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center <?php echo $_GET['msg'] === 'approved' ? 'bg-green-100' : 'bg-red-100'; ?>">
                            <i class="fas <?php echo $_GET['msg'] === 'approved' ? 'fa-check text-green-600' : 'fa-times text-red-600'; ?>"></i>
                        </div>
                        <div>
                            <p class="font-semibold <?php echo $_GET['msg'] === 'approved' ? 'text-green-800' : 'text-red-800'; ?>">
                                <?php echo $_GET['msg'] === 'approved' ? 'Inspection approved successfully!' : 'Inspection rejected.'; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="mx-8 mb-6">
            <div class="card">
                <form method="GET" action="supervisor_dashboard.php" class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-2 uppercase tracking-wider">
                                <i class="fas fa-calendar-alt mr-1 text-primary"></i> Date From
                            </label>
                            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="form-input w-full">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-2 uppercase tracking-wider">
                                <i class="fas fa-calendar-alt mr-1 text-primary"></i> Date To
                            </label>
                            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="form-input w-full">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-2 uppercase tracking-wider">
                                <i class="fas fa-user mr-1 text-primary"></i> Staff Name
                            </label>
                            <input type="text" name="staff_search" value="<?php echo htmlspecialchars($staff_search); ?>" placeholder="Search staff..." class="form-input w-full">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-2 uppercase tracking-wider">
                                <i class="fas fa-sort mr-1 text-primary"></i> Sort By
                            </label>
                            <select name="sort_by" class="form-input w-full">
                                <option value="DateCreated" <?php echo $sort_by === 'DateCreated' ? 'selected' : ''; ?>>Date Created</option>
                                <option value="StaffName" <?php echo $sort_by === 'StaffName' ? 'selected' : ''; ?>>Staff Name</option>
                                <option value="InspectionID" <?php echo $sort_by === 'InspectionID' ? 'selected' : ''; ?>>Inspection ID</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div class="flex items-center gap-2">
                                <label class="text-xs font-bold text-gray-600 uppercase tracking-wider">Order:</label>
                                <select name="sort_order" class="form-input">
                                    <option value="DESC" <?php echo $sort_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                                    <option value="ASC" <?php echo $sort_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                                </select>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" class="btn-primary flex items-center gap-2">
                                <i class="fas fa-search"></i> Apply Filters
                            </button>
                            <a href="supervisor_dashboard.php" class="btn-secondary flex items-center gap-2">
                                <i class="fas fa-redo"></i> Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($total_records > 0): ?>
        <div class="mx-8 mb-4 flex items-center justify-between text-sm text-gray-600">
            <div>
                Showing <span class="font-bold text-primary"><?php echo $offset + 1; ?></span> to 
                <span class="font-bold text-primary"><?php echo min($offset + $per_page, $total_records); ?></span> of 
                <span class="font-bold text-primary"><?php echo $total_records; ?></span> pending inspections
            </div>
            <div class="text-xs text-gray-500">
                Page <?php echo $page; ?> of <?php echo $total_pages; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="px-8 pb-8">
            <div class="space-y-6">
                <?php if (!empty($inspections)): ?>
                    <?php foreach ($inspections as $insp): ?>
                    <div class="card overflow-hidden group">
                        <div class="px-8 py-6 border-b border-white/30 bg-gradient-to-r from-white/40 to-white/20 flex justify-between items-center">
                            <div class="flex items-center gap-4">
                                <?php 
                                    $staffEmpID = $insp['header']['EmployeeID'];
                                    $staffPhotoUrl = $staffEmpID ? "http://10.2.0.8/lrnph/emp_photos/" . $staffEmpID . ".jpg" : null;
                                    $fallbackId = 'staff-fallback-' . $insp['header']['InspectionID'];
                                ?>
                                <div class="relative w-14 h-14 rounded-xl overflow-hidden shadow-lg bg-gray-100 flex items-center justify-center">
                                    <?php if ($staffPhotoUrl): ?>
                                        <img src="<?php echo htmlspecialchars($staffPhotoUrl); ?>" 
                                             alt="Staff" 
                                             class="w-full h-full object-cover"
                                             onerror="handleAvatarError(this, '<?php echo $staffEmpID; ?>', '<?php echo $fallbackId; ?>')">
                                    <?php endif; ?>
                                    
                                    <div id="<?php echo $fallbackId; ?>" 
                                         class="<?php echo $staffPhotoUrl ? 'hidden' : 'flex'; ?> w-full h-full bg-gradient-to-r from-blue-400 to-purple-400 items-center justify-center text-white text-xl absolute top-0 left-0">
                                        <i class="fas fa-user"></i>
                                    </div>
                                </div>
                                <div>
                                    <h2 class="text-2xl font-bold text-gray-800">
                                        <?php echo str_pad($insp['header']['InspectionID'], 4, '0', STR_PAD_LEFT); ?>
                                    </h2>
                                    <p class="text-sm text-gray-500 mt-1">
                                        <span class="font-semibold text-primary"><?php echo htmlspecialchars($insp['header']['StaffName'] ?? 'Unknown Staff'); ?></span>
                                    </p>
                                </div>
                            </div>
                            <div class="text-right">
                                <span class="status-badge status-pending mb-3 block">
                                    <i class="fas fa-clock"></i> Pending Review
                                </span>
                                <div class="text-xs text-gray-400">
                                    <i class="fas fa-calendar mr-1"></i>
                                    <?php echo date('M d, Y h:i A', strtotime($insp['header']['DateCreated'])); ?>
                                </div>
                            </div>
                        </div>

                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="inspection_id" value="<?php echo $insp['header']['InspectionID']; ?>">

                            <div class="overflow-x-auto p-8">
                                <div class="table rounded-2xl overflow-hidden">
                                    <table class="w-full">
                                        <thead>
                                            <tr>
                                                <th class="text-left">Item Code</th>
                                                <th class="text-left">Description</th>
                                                <th class="text-left">Removal of Dirt / Foreign Objects</th>
                                                <th class="text-center">Quantity Washed</th>
                                                <th class="text-center">Quantity for Repair</th>
                                                <th class="text-center">Quantity for Disposal</th>
                                                <th class="text-left">Remarks</th>
                                                <th class="text-left">Date</th>
                                                <th class="text-center">Photo</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $itemCounter = 1; ?>
                                            <?php foreach ($insp['items'] as $item): ?>
                                            <tr>
                                                <td>
                                                    <div class="item-code-container flex items-center justify-center gap-2">
                                                        <span class="item-code-display font-mono font-bold text-gray-700 text-sm cursor-pointer hover:text-primary transition-colors"
                                                              onclick="editItemCode(this, '<?php echo $item['DetailID']; ?>')">
                                                            <?php echo htmlspecialchars($item['ItemCode'] ?? 'UNI' . str_pad($itemCounter, 3, '0', STR_PAD_LEFT)); ?>
                                                        </span>
                                                        <button type="button" class="edit-code-btn text-gray-400 hover:text-primary transition-colors" onclick="editItemCode(this.previousElementSibling, '<?php echo $item['DetailID']; ?>')">
                                                            <i class="fas fa-pencil-alt text-xs"></i>
                                                        </button>
                                                        <input type="text" name="codes[<?php echo $item['DetailID']; ?>]"
                                                               value="<?php echo htmlspecialchars($item['ItemCode'] ?? 'UNI' . str_pad($itemCounter, 3, '0', STR_PAD_LEFT)); ?>"
                                                               class="item-code-input form-input w-full font-mono font-bold text-center text-sm hidden">
                                                    </div>
                                                </td>
                                                <td class="font-bold text-gray-700"><?php echo htmlspecialchars($item['Description']); ?></td>
                                                <td class="text-gray-500 italic text-wrap-cell"><?php echo nl2br(htmlspecialchars($item['RemovalOfDirt'] ?? '—')); ?></td>
                                                <td class="text-center">
                                                    <span class="quantity-badge quantity-washed"><?php echo $item['QtyWashed']; ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="quantity-badge quantity-repair"><?php echo $item['QtyRepair']; ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="quantity-badge quantity-disposal"><?php echo $item['QtyDisposal']; ?></span>
                                                </td>
                                                <td class="text-gray-600 text-wrap-cell"><?php echo nl2br(htmlspecialchars($item['Remarks'] ?? '—')); ?></td>
                                                <td class="text-xs text-gray-400 whitespace-nowrap">
                                                    <?php echo date('M d, Y', strtotime($insp['header']['DateCreated'])); ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($item['StaffPhoto'] && file_exists($item['StaffPhoto'])): ?>
                                                        <img src="<?php echo htmlspecialchars($item['StaffPhoto']); ?>" 
                                                             class="photo-preview mx-auto"
                                                             onclick="openPhotoModal('<?php echo htmlspecialchars($item['StaffPhoto']); ?>')">
                                                    <?php else: ?>
                                                        <div class="photo-placeholder mx-auto">
                                                            <i class="fas fa-image text-lg"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php $itemCounter++; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="px-8 py-6 bg-gradient-to-r from-white/40 to-white/20 flex flex-col md:flex-row justify-between items-center gap-6 border-t border-white/30">
                                <div class="w-full md:w-1/2">
                                    <label class="block text-sm font-bold text-gray-600 mb-3 uppercase tracking-wider flex items-center gap-2">
                                        <i class="fas fa-signature text-pink-400"></i>
                                        Signature (Required for Approval)
                                    </label>
                                    <div class="relative">
                                        <input type="file" name="signature" id="sig_<?php echo $insp['header']['InspectionID']; ?>" 
                                               accept="image/*" class="form-input file-input w-full pr-12" onchange="handleSignatureChange(this, '<?php echo $insp['header']['InspectionID']; ?>')">
                                        <button type="button" onclick="removeSignature(this, '<?php echo $insp['header']['InspectionID']; ?>')" class="remove-signature-btn hidden absolute right-3 top-1/2 -translate-y-1/2 w-7 h-7 flex items-center justify-center rounded-lg bg-red-500 text-white hover:bg-red-600 transition-all shadow-lg z-10" title="Remove signature">
                                            <i class="fas fa-times text-xs"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="flex gap-4">
                                    <button type="button" onclick="openRejectModal('<?php echo $insp['header']['InspectionID']; ?>')" class="btn-danger flex items-center gap-2">
                                        <i class="fas fa-times"></i>
                                        <span>Reject</span>
                                    </button>
                                    <button type="button" onclick="validateApproval('<?php echo $insp['header']['InspectionID']; ?>')"
                                            class="btn-success flex items-center gap-2">
                                        <i class="fas fa-check"></i>
                                        <span>Approve</span>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="card p-16 text-center">
                        <div class="w-24 h-24 bg-gradient-to-r from-pink-100 to-purple-100 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i class="fas fa-check-circle text-6xl text-pink-400"></i>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-800 mb-2">All Caught Up!</h3>
                        <p class="text-gray-500 text-lg">No pending inspections to review</p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="mt-6 flex justify-center items-center gap-2">
                <?php
                $query_params = $_GET;
                unset($query_params['page']);
                $base_url = 'supervisor_dashboard.php?' . http_build_query($query_params);
                ?>
                
                <?php if ($page > 1): ?>
                    <a href="<?php echo $base_url . '&page=1'; ?>" class="px-4 py-2 bg-white rounded-lg border border-gray-200 hover:bg-primary hover:text-white transition-all">
                        <i class="fas fa-angle-double-left"></i>
                    </a>
                <?php else: ?>
                    <span class="px-4 py-2 bg-gray-100 rounded-lg border border-gray-200 text-gray-400 cursor-not-allowed">
                        <i class="fas fa-angle-double-left"></i>
                    </span>
                <?php endif; ?>

                <?php if ($page > 1): ?>
                    <a href="<?php echo $base_url . '&page=' . ($page - 1); ?>" class="px-4 py-2 bg-white rounded-lg border border-gray-200 hover:bg-primary hover:text-white transition-all">
                        <i class="fas fa-angle-left"></i> Previous
                    </a>
                <?php else: ?>
                    <span class="px-4 py-2 bg-gray-100 rounded-lg border border-gray-200 text-gray-400 cursor-not-allowed">
                        <i class="fas fa-angle-left"></i> Previous
                    </span>
                <?php endif; ?>

                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                
                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                    <?php if ($i == $page): ?>
                        <span class="px-4 py-2 bg-gradient-to-r from-primary to-primary-dark text-white rounded-lg font-bold shadow-lg">
                            <?php echo $i; ?>
                        </span>
                    <?php else: ?>
                        <a href="<?php echo $base_url . '&page=' . $i; ?>" class="px-4 py-2 bg-white rounded-lg border border-gray-200 hover:bg-primary hover:text-white transition-all">
                            <?php echo $i; ?>
                        </a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="<?php echo $base_url . '&page=' . ($page + 1); ?>" class="px-4 py-2 bg-white rounded-lg border border-gray-200 hover:bg-primary hover:text-white transition-all">
                        Next <i class="fas fa-angle-right"></i>
                    </a>
                <?php else: ?>
                    <span class="px-4 py-2 bg-gray-100 rounded-lg border border-gray-200 text-gray-400 cursor-not-allowed">
                        Next <i class="fas fa-angle-right"></i>
                    </span>
                <?php endif; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="<?php echo $base_url . '&page=' . $total_pages; ?>" class="px-4 py-2 bg-white rounded-lg border border-gray-200 hover:bg-primary hover:text-white transition-all">
                        <i class="fas fa-angle-double-right"></i>
                    </a>
                <?php else: ?>
                    <span class="px-4 py-2 bg-gray-100 rounded-lg border border-gray-200 text-gray-400 cursor-not-allowed">
                        <i class="fas fa-angle-double-right"></i>
                    </span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <div id="photo-modal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h3 class="text-lg font-bold text-gray-800">Inspection Photo</h3>
                <button class="modal-close" onclick="closePhotoModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <img id="modal-photo" src="" alt="Inspection Photo" class="w-full h-auto rounded-lg">
            </div>
        </div>
    </div>

    <div id="reject-modal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-exclamation-triangle text-red-500"></i>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-gray-800">Reject Inspection</h3>
                        <p class="text-sm text-gray-600">This action cannot be undone</p>
                    </div>
                </div>
                <button class="modal-close" onclick="closeRejectModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" class="modal-body">
                <input type="hidden" name="reject_inspection_id" id="reject_inspection_id">
                
                <div class="space-y-4">
                    <label class="block text-sm font-bold text-gray-700">Reason for Rejection (Optional)</label>
                    <textarea name="rejection_reason" rows="4" 
                              class="form-input resize-none" 
                              placeholder="e.g., Photos are blurry, quantities mismatch..."></textarea>
                </div>
                
                <div class="flex gap-4 mt-8">
                    <button type="button" onclick="closeRejectModal()" class="btn-secondary flex-1">Cancel</button>
                    <button type="submit" name="confirm_reject" class="btn-danger flex-1">Confirm Reject</button>
                </div>
            </form>
        </div>
    </div>

    <div id="signature-modal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-orange-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-pen-nib text-orange-500"></i>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-gray-800">Signature Required</h3>
                        <p class="text-sm text-gray-600">Action cannot be completed</p>
                    </div>
                </div>
                <button class="modal-close" onclick="closeSignatureModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body text-center p-4">
                <p class="text-gray-600 mb-6">You must attach your signature before approving this inspection.</p>
                <button type="button" onclick="closeSignatureModal()" class="btn-primary w-full">Okay, I'll attach it</button>
            </div>
        </div>
    </div>

    <div id="approve-confirm-modal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-check-double text-green-600"></i>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-gray-800">Confirm Approval</h3>
                        <p class="text-sm text-gray-600">Are you sure you want to approve this?</p>
                    </div>
                </div>
                <button class="modal-close" onclick="closeApproveConfirmModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <p class="text-gray-600 mb-6">This will mark the inspection as approved and record your signature.</p>
                <div class="flex gap-4">
                    <button type="button" onclick="closeApproveConfirmModal()" class="btn-secondary flex-1">Cancel</button>
                    <button type="button" onclick="performApproval()" class="btn-success flex-1">Yes, Approve It</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // --- Auto-Dismiss Notification Logic ---
        document.addEventListener('DOMContentLoaded', function() {
            const toast = document.getElementById('notification-toast');
            if (toast) {
                setTimeout(() => {
                    toast.style.opacity = '0';
                    setTimeout(() => {
                        toast.remove();
                        const url = new URL(window.location);
                        url.searchParams.delete('msg');
                        window.history.replaceState({}, '', url);
                    }, 500);
                }, 4000);
            }
        });

        let currentFormToSubmit = null; 

        // Photo Modal
        function openPhotoModal(src) {
            document.getElementById('modal-photo').src = src;
            document.getElementById('photo-modal').classList.add('active');
        }

        function closePhotoModal() {
            document.getElementById('photo-modal').classList.remove('active');
        }

        // Reject Modal
        function openRejectModal(id) {
            document.getElementById('reject_inspection_id').value = id;
            document.getElementById('reject-modal').classList.add('active');
        }

        function closeRejectModal() {
            document.getElementById('reject-modal').classList.remove('active');
            document.getElementById('reject_inspection_id').value = '';
        }

        // Signature Modal
        function closeSignatureModal() {
            document.getElementById('signature-modal').classList.remove('active');
        }

        // --- Approval Logic ---

        function openApproveConfirmModal() {
            document.getElementById('approve-confirm-modal').classList.add('active');
        }

        function closeApproveConfirmModal() {
            document.getElementById('approve-confirm-modal').classList.remove('active');
            currentFormToSubmit = null;
        }

        function validateApproval(id) {
            const fileInput = document.getElementById('sig_' + id);
            
            if (!fileInput || fileInput.files.length === 0) {
                document.getElementById('signature-modal').classList.add('active');
                return;
            }

            currentFormToSubmit = fileInput.closest('form');
            openApproveConfirmModal();
        }

        function performApproval() {
            if (currentFormToSubmit) {
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'approve';
                hiddenInput.value = '1';
                currentFormToSubmit.appendChild(hiddenInput);
                currentFormToSubmit.submit();
            }
        }

        function handleSignatureChange(input, inspectionId) {
            const container = input.parentElement;
            const removeBtn = container.querySelector('.remove-signature-btn');
            
            if (input.files && input.files.length > 0) {
                removeBtn.classList.remove('hidden');
            } else {
                removeBtn.classList.add('hidden');
            }
        }

        function removeSignature(btn, inspectionId) {
            const fileInput = document.getElementById('sig_' + inspectionId);
            if (fileInput) {
                fileInput.value = '';
                btn.classList.add('hidden');
            }
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closePhotoModal();
                closeRejectModal();
                closeSignatureModal();
                closeApproveConfirmModal();
            }
        });

        window.onclick = function(e) {
            if (e.target.classList.contains('modal-overlay')) {
                e.target.classList.remove('active');
                if(e.target.id === 'reject-modal') document.getElementById('reject_inspection_id').value = '';
                if(e.target.id === 'approve-confirm-modal') currentFormToSubmit = null;
            }
        }

        // --- Item Code Editing Functions ---
        function editItemCode(displaySpan, detailId) {
            const container = displaySpan.closest('.item-code-container');
            const displayElement = container.querySelector('.item-code-display');
            const editBtn = container.querySelector('.edit-code-btn');
            const inputElement = container.querySelector('.item-code-input');

            // Hide display and show input
            displayElement.classList.add('hidden');
            editBtn.classList.add('hidden');
            inputElement.classList.remove('hidden');

            // Focus the input and select all text
            inputElement.focus();
            inputElement.select();

            // Add event listeners for saving/canceling
            inputElement.addEventListener('blur', () => saveItemCode(container, detailId));
            inputElement.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    saveItemCode(container, detailId);
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    cancelItemCodeEdit(container);
                }
            });
        }

        function saveItemCode(container, detailId) {
            const displayElement = container.querySelector('.item-code-display');
            const editBtn = container.querySelector('.edit-code-btn');
            const inputElement = container.querySelector('.item-code-input');

            // Update the display text
            displayElement.textContent = inputElement.value.trim() || displayElement.textContent;

            // Hide input and show display
            inputElement.classList.add('hidden');
            displayElement.classList.remove('hidden');
            editBtn.classList.remove('hidden');

            // Remove event listeners
            inputElement.removeEventListener('blur', () => saveItemCode(container, detailId));
            inputElement.removeEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    saveItemCode(container, detailId);
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    cancelItemCodeEdit(container);
                }
            });
        }

        function cancelItemCodeEdit(container) {
            const displayElement = container.querySelector('.item-code-display');
            const editBtn = container.querySelector('.edit-code-btn');
            const inputElement = container.querySelector('.item-code-input');

            // Reset input value to current display value
            inputElement.value = displayElement.textContent.trim();

            // Hide input and show display
            inputElement.classList.add('hidden');
            displayElement.classList.remove('hidden');
            editBtn.classList.remove('hidden');

            // Remove event listeners
            inputElement.removeEventListener('blur', () => saveItemCode(container, detailId));
            inputElement.removeEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    saveItemCode(container, detailId);
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    cancelItemCodeEdit(container);
                }
            });
        }
    </script>
</body>
</html>