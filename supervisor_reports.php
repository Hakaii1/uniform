<?php
require_once 'db/conn.php';
require_once 'auth/authenticate.php';
restrictAccess(['Supervisor', 'Team Leader']);

// Get filter and pagination parameters
$status_filter = $_GET['status'] ?? 'all';
$shift_filter = $_GET['shift'] ?? 'all';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$staff_search = $_GET['staff_search'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'DateCreated';
$sort_order = $_GET['sort_order'] ?? 'DESC';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 10; 

// Validate sort parameters
$allowed_sort = ['DateCreated', 'DateUpdated', 'StaffName', 'Status', 'InspectionID'];
$sort_by = in_array($sort_by, $allowed_sort) ? $sort_by : 'DateCreated';
$sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';

// --- Filter Conditions ---
$where_conditions = ["1=1"]; 
$params = [];

if ($status_filter !== 'all') {
    $where_conditions[] = "h.Status = :status";
    $params[':status'] = $status_filter;
}

if ($shift_filter !== 'all') {
    $where_conditions[] = "h.Shift = :shift";
    $params[':shift'] = $shift_filter;
}

if (!empty($date_from)) {
    $where_conditions[] = "CAST(h.DateCreated AS DATE) >= :date_from";
    $params[':date_from'] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "CAST(h.DateCreated AS DATE) <= :date_to";
    $params[':date_to'] = $date_to;
}

if (!empty($staff_search)) {
    $where_conditions[] = "m.FullName LIKE :staff_search";
    $params[':staff_search'] = '%' . $staff_search . '%';
}

$where_clause = implode(' AND ', $where_conditions);

// --- Count Query ---
$count_sql = "SELECT COUNT(DISTINCT h.InspectionID) 
              FROM uniform_headers h 
              LEFT JOIN lrn_master_list m ON h.StaffUID = m.id 
              WHERE $where_clause";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);
$offset = ($page - 1) * $per_page;

// --- Prepare Export Data & Modal Summary ---
// Map current parameters to export query string
$export_params = [
    'start_date' => $date_from,  // Mapped to start_date for compatibility
    'end_date' => $date_to,      // Mapped to end_date
    'status' => $status_filter,
    'shift' => $shift_filter,
    'staff_search' => $staff_search,
    'sort_by' => $sort_by,
    'sort_order' => $sort_order
];
$export_url = 'export_report.php?' . http_build_query($export_params);

// Generate summary of active filters for the modal
$active_filters_summary = [];
if ($status_filter !== 'all') $active_filters_summary[] = "Status: <strong>" . htmlspecialchars($status_filter) . "</strong>";
if ($shift_filter !== 'all') $active_filters_summary[] = "Shift: <strong>" . htmlspecialchars($shift_filter) . "</strong>";
if (!empty($date_from)) $active_filters_summary[] = "From: <strong>" . htmlspecialchars($date_from) . "</strong>";
if (!empty($date_to)) $active_filters_summary[] = "To: <strong>" . htmlspecialchars($date_to) . "</strong>";
if (!empty($staff_search)) $active_filters_summary[] = "Staff: <strong>" . htmlspecialchars($staff_search) . "</strong>";
if (empty($active_filters_summary)) $active_filters_summary[] = "<em>No specific filters (All records)</em>";


// --- Order By ---
$sort_column_map = [
    'DateCreated' => 'h.DateCreated',
    'DateUpdated' => 'h.DateUpdated',
    'StaffName' => 'm.FullName',
    'Status' => 'h.Status',
    'InspectionID' => 'h.InspectionID'
];
$sort_column = $sort_column_map[$sort_by] ?? 'h.DateCreated';

if ($sort_by !== 'InspectionID') {
    $order_by = "ORDER BY $sort_column $sort_order, h.InspectionID DESC";
} else {
    $order_by = "ORDER BY $sort_column $sort_order";
}

// --- Main Query: Fetch Headers with StaffUID ---
$sql = "SELECT DISTINCT h.InspectionID, h.DateCreated, h.DateUpdated, h.Status, h.SupervisorSign, h.Shift, h.StaffUID
        FROM uniform_headers h 
        LEFT JOIN lrn_master_list m ON h.StaffUID = m.id 
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

// --- Fetch Staff Names Separately ---
$staff_names = [];
if (!empty($headers)) {
    $staff_uids = array_unique(array_column($headers, 'StaffUID'));
    // CRITICAL: Reset keys to avoid PDO parameter mismatch
    $staff_uids = array_values($staff_uids); 

    if (!empty($staff_uids)) {
        $placeholders = implode(',', array_fill(0, count($staff_uids), '?'));
        $staff_sql = "SELECT id, FirstName, LastName FROM lrn_master_list WHERE id IN ($placeholders)";
        
        if (function_exists('safeQueryAll')) {
            $staff_results = safeQueryAll($staff_sql, $staff_uids, true);
        } else {
            $staff_stmt = $conn->prepare($staff_sql);
            $staff_stmt->execute($staff_uids);
            $staff_results = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        foreach ($staff_results as $staff) {
            $staff_names[$staff['id']] = trim($staff['FirstName'] . ' ' . $staff['LastName']);
        }
    }
}

// Map names back to headers
foreach ($headers as &$header) {
    $header['StaffName'] = $staff_names[$header['StaffUID']] ?? 'Unknown Staff';
}
unset($header);

// --- Fetch Inspection Details ---
$inspection_ids = array_column($headers, 'InspectionID');
$history = [];

if (!empty($inspection_ids)) {
    $placeholders = implode(',', array_fill(0, count($inspection_ids), '?'));
    
    $details_sql = "SELECT h.InspectionID, h.DateCreated, h.DateUpdated, h.Status, h.SupervisorSign, h.Shift, h.StaffUID, 
                           d.ItemCode, d.Description, d.RemovalOfDirt, d.QtyWashed, d.QtyRepair, d.QtyDisposal, d.Remarks, d.StaffPhoto 
                    FROM uniform_headers h 
                    LEFT JOIN uniform_details d ON h.InspectionID = d.InspectionID 
                    WHERE h.InspectionID IN ($placeholders) 
                    ORDER BY h.InspectionID, d.ItemCode";
    $details_stmt = $conn->prepare($details_sql);
    $details_stmt->execute($inspection_ids);
    $rows = $details_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $id = $row['InspectionID'];
        if (!isset($history[$id])) {
            $staffName = $staff_names[$row['StaffUID']] ?? 'Unknown Staff';
            
            $history[$id] = [
                'header' => [
                    'InspectionID' => $row['InspectionID'], 
                    'DateCreated' => $row['DateCreated'], 
                    'DateUpdated' => $row['DateUpdated'], 
                    'Status' => $row['Status'], 
                    'StaffName' => $staffName, 
                    'SupervisorSign' => $row['SupervisorSign'],
                    'Shift' => $row['Shift'] 
                ],
                'items' => []
            ];
        }
        if ($row['Description']) $history[$id]['items'][] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reports & History • La Rose Noire</title>
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
            <a href="supervisor_dashboard.php" class="nav-item flex items-center space-x-4 px-6 py-5 rounded-2xl text-gray-500 hover:bg-pink-50/50 hover:text-primary transition-all duration-300 group">
                <div class="w-12 h-12 bg-gray-100 rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform">
                    <i class="fas fa-clipboard-check text-xl"></i>
                </div>
                <span class="font-bold text-lg">Uniform Inspections</span>
            </a>
            <a href="supervisor_reports.php" class="nav-item active flex items-center space-x-4 px-6 py-5 rounded-2xl bg-gradient-to-r from-primary to-primary-dark text-white shadow-lg shadow-pink-200/50 transition-all duration-300 group">
                <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform">
                    <i class="fas fa-chart-bar text-xl"></i>
                </div>
                <span class="font-bold text-lg">Reports & History</span>
            </a>
        </nav>

        <div class="p-6 border-t border-white/20">
            <a href="logout.php" class="flex items-center space-x-4 px-4 py-3 rounded-xl text-gray-500 hover:bg-red-50 hover:text-red-400 transition-all duration-300 group">
                <div class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform">
                    <i class="fas fa-sign-out-alt"></i>
                </div>
                <span class="font-semibold">Logout</span>
            </a>
        </div>
    </aside>

    <main class="flex-1 overflow-y-auto p-8 relative">
        <header class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-4xl font-bold text-gray-800">Reports & History</h1>
                <p class="text-gray-500 mt-2 text-lg">Complete inspection records and analytics</p>
            </div>
            <div class="flex items-center gap-4 bg-white/60 backdrop-blur-md px-6 py-3 rounded-full shadow-sm">
                <div class="text-right hidden md:block">
                    <div class="text-sm font-bold text-gray-800"><?php echo $_SESSION['full_name']; ?></div>
                    <div class="text-xs text-primary font-bold uppercase"><?php echo htmlspecialchars($_SESSION['job_level']); ?></div>
                </div>
                <div class="w-12 h-12 rounded-full bg-gradient-to-tr from-primary to-purple-400 flex items-center justify-center text-white font-bold text-xl shadow-md">
                    <?php echo strtoupper(substr($_SESSION['full_name'], 0, 1)); ?>
                </div>
            </div>
        </header>

        <div class="card mb-6">
            <form method="GET" action="supervisor_reports.php" class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4 mb-4">
                    
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-2 uppercase tracking-wider">
                            <i class="fas fa-filter mr-1 text-primary"></i> Status
                        </label>
                        <select name="status" class="form-input w-full">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="Pending" <?php echo $status_filter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="Approved" <?php echo $status_filter === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="Rejected" <?php echo $status_filter === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-2 uppercase tracking-wider">
                            <i class="fas fa-clock mr-1 text-primary"></i> Shift
                        </label>
                        <select name="shift" class="form-input w-full">
                            <option value="all" <?php echo $shift_filter === 'all' ? 'selected' : ''; ?>>All Shifts</option>
                            <option value="1st Shift" <?php echo $shift_filter === '1st Shift' ? 'selected' : ''; ?>>1st Shift</option>
                            <option value="2nd Shift" <?php echo $shift_filter === '2nd Shift' ? 'selected' : ''; ?>>2nd Shift</option>
                            <option value="3rd Shift" <?php echo $shift_filter === '3rd Shift' ? 'selected' : ''; ?>>3rd Shift</option>
                        </select>
                    </div>

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
                            <option value="DateUpdated" <?php echo $sort_by === 'DateUpdated' ? 'selected' : ''; ?>>Date Updated</option>
                            <option value="StaffName" <?php echo $sort_by === 'StaffName' ? 'selected' : ''; ?>>Staff Name</option>
                            <option value="Status" <?php echo $sort_by === 'Status' ? 'selected' : ''; ?>>Status</option>
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
                        <a href="supervisor_reports.php" class="btn-secondary flex items-center gap-2">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                        
                        <button type="button" onclick="openExportModal()" class="btn-success flex items-center gap-2">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <?php if ($total_records > 0): ?>
        <div class="mb-4 flex items-center justify-between text-sm text-gray-600">
            <div>
                Showing <span class="font-bold text-primary"><?php echo $offset + 1; ?></span> to 
                <span class="font-bold text-primary"><?php echo min($offset + $per_page, $total_records); ?></span> of 
                <span class="font-bold text-primary"><?php echo $total_records; ?></span> records
            </div>
            <div class="text-xs text-gray-500">
                Page <?php echo $page; ?> of <?php echo $total_pages; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="space-y-6 pb-8">
            <?php if (!empty($history)): ?>
                <?php foreach ($history as $insp): ?>
                <div class="card overflow-hidden group">
                    <div class="px-8 py-6 border-b border-white/30 bg-gradient-to-r from-white/40 to-white/20 flex justify-between items-center">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 bg-gradient-to-r from-blue-400 to-purple-400 rounded-xl flex items-center justify-center shadow-lg">
                                <i class="fas fa-file-check text-white"></i>
                            </div>
                            <div>
                                <h2 class="text-xl font-bold text-gray-800">
                                    #<?php echo str_pad($insp['header']['InspectionID'], 4, '0', STR_PAD_LEFT); ?>
                                </h2>
                                <p class="text-sm text-gray-500 mt-1">
                                    <i class="fas fa-user mr-2 text-primary"></i>
                                    <span class="font-semibold text-primary"><?php echo htmlspecialchars($insp['header']['StaffName']); ?></span>
                                    <span class="mx-2 text-gray-300">|</span>
                                    <span class="font-bold text-gray-600 bg-gray-100 px-2 py-0.5 rounded text-xs"><?php echo htmlspecialchars($insp['header']['Shift'] ?? 'N/A'); ?></span>
                                </p>
                            </div>
                        </div>
                        <div class="text-right">
                            <?php 
                                $statusClass = match($insp['header']['Status']) {
                                    'Approved' => 'status-approved',
                                    'Rejected' => 'status-rejected',
                                    default => 'bg-yellow-100 text-yellow-800'
                                };
                                $statusIcon = match($insp['header']['Status']) {
                                    'Approved' => 'fa-check-circle',
                                    'Rejected' => 'fa-times-circle',
                                    default => 'fa-clock'
                                };
                            ?>
                            <span class="status-badge <?php echo $statusClass; ?> flex items-center gap-2 justify-end mb-2">
                                <i class="fas <?php echo $statusIcon; ?>"></i> <?php echo $insp['header']['Status']; ?>
                            </span>
                        </div>
                    </div>

                    <div class="overflow-x-auto p-8">
                        <div class="table rounded-2xl overflow-hidden">
                        <table class="w-full">
                            <thead>
                                <tr>
                                    <th>Item Code</th>
                                    <th>Description</th>
                                    <th>Removal of Dirt / Foreign Objects</th>
                                    <th class="text-center">Quantity Washed</th>
                                    <th class="text-center">Quantity for Repair</th>
                                    <th class="text-center">Quantity for Disposal</th>
                                    <th>Remarks</th>
                                    <th class="text-center">Photo</th>
                                    <th class="text-center">Signature</th>
                                    <th class="text-center">Date Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($insp['items'])): ?>
                                    <?php foreach ($insp['items'] as $item): ?>
                                    <tr>
                                        <td class="font-mono font-bold text-gray-600"><?php echo htmlspecialchars($item['ItemCode'] ?? '—'); ?></td>
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
                                        
                                        <td class="text-center">
                                            <?php 
                                            $signPath = $insp['header']['SupervisorSign'];
                                            if (!empty($signPath) && file_exists($signPath)): 
                                            ?>
                                                <img src="<?php echo htmlspecialchars($signPath); ?>" 
                                                    class="h-14 mx-auto object-contain cursor-pointer hover:scale-150 transition-transform bg-white rounded border border-gray-200 p-1"
                                                    onclick="openPhotoModal('<?php echo htmlspecialchars($signPath); ?>')"
                                                    alt="Signed">
                                            <?php else: ?>
                                                <span class="text-xs text-gray-400 italic">
                                                    <i class="fas fa-pen-fancy mr-1"></i> Not Signed
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td class="text-center text-sm text-gray-600">
                                            <i class="fas fa-calendar mr-1 text-primary"></i>
                                            <?php echo date('M d, Y H:i', strtotime($insp['header']['DateCreated'])); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="10" class="text-center py-4 text-gray-500 italic">No item details found for this inspection.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="card p-16 text-center">
                    <div class="w-24 h-24 bg-gradient-to-r from-pink-100 to-purple-100 rounded-full flex items-center justify-center mx-auto mb-6">
                        <i class="fas fa-chart-bar text-6xl text-pink-400"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-800 mb-2">No History Available</h3>
                    <p class="text-gray-500 text-lg">Try adjusting your filters to see more results.</p>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="mt-6 flex justify-center items-center gap-2">
            <?php
            $query_params = $_GET;
            unset($query_params['page']);
            $base_url = 'supervisor_reports.php?' . http_build_query($query_params);
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

    <div id="export-modal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-file-excel text-green-600"></i>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-gray-800">Confirm Export</h3>
                        <p class="text-sm text-gray-600">Review your export details</p>
                    </div>
                </div>
                <button class="modal-close" onclick="closeExportModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="bg-gray-50 rounded-xl p-4 mb-6 border border-gray-200">
                    <div class="flex justify-between items-center mb-3">
                        <span class="text-sm font-bold text-gray-500 uppercase">Total Records</span>
                        <span class="text-xl font-bold text-primary"><?php echo number_format($total_records); ?></span>
                    </div>
                    <hr class="border-gray-200 mb-3">
                    <p class="text-sm font-bold text-gray-500 uppercase mb-2">Filters Applied:</p>
                    <ul class="text-sm text-gray-700 space-y-1 pl-2">
                        <?php foreach ($active_filters_summary as $summary): ?>
                            <li class="flex items-center gap-2">
                                <i class="fas fa-check text-green-400 text-xs"></i>
                                <?php echo $summary; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <p class="text-gray-600 mb-6 text-sm">
                    This will download a CSV/Excel file containing the filtered records.
                </p>
                <div class="flex gap-4">
                    <button type="button" onclick="closeExportModal()" class="btn-secondary flex-1">Cancel</button>
                    <a href="<?php echo htmlspecialchars($export_url); ?>" class="btn-success flex-1 text-center justify-center">
                        Yes, Download File
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Photo Modal Functions
        function openPhotoModal(src) {
            document.getElementById('modal-photo').src = src;
            document.getElementById('photo-modal').classList.add('active');
        }
        function closePhotoModal() {
            document.getElementById('photo-modal').classList.remove('active');
        }

        // Export Modal Functions
        function openExportModal() {
            document.getElementById('export-modal').classList.add('active');
        }
        function closeExportModal() {
            document.getElementById('export-modal').classList.remove('active');
        }

        // Close on Escape or Outside Click
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closePhotoModal();
                closeExportModal();
            }
        });
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>