<?php 
session_start(); 
if(!isset($_SESSION['user_id'])) header("Location: login.php"); 
require_once 'auth/authenticate.php';
require_once 'db/conn.php';
restrictAccess(['Staff']);

$full_name = $_SESSION['full_name'] ?? 'Staff Member';
$department = $_SESSION['dept'] ?? 'Facilities Management';

// --- Get Filter & Pagination Parameters ---
$status_filter = $_GET['status'] ?? 'all';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 10; 

// --- Sorting Logic ---
// 1. Get raw values
$sort_by_raw = $_GET['sort_by'] ?? 'DateCreated';
$sort_order_raw = $_GET['sort_order'] ?? 'DESC';

// 2. Define allowed columns
$allowed_sort = ['DateCreated', 'DateUpdated', 'Status', 'InspectionID'];

// 3. Validate and sanitize
$sort_by = in_array($sort_by_raw, $allowed_sort) ? $sort_by_raw : 'DateCreated';
$sort_order = strtoupper($sort_order_raw) === 'ASC' ? 'ASC' : 'DESC';

// 4. Map to SQL columns
$sort_column_map = [
    'DateCreated' => 'h.DateCreated',
    'DateUpdated' => 'h.DateUpdated',
    'Status' => 'h.Status',
    'InspectionID' => 'h.InspectionID'
];
$sort_sql_column = $sort_column_map[$sort_by];

// 5. Build Order By Clause
if ($sort_by !== 'InspectionID') {
    // Secondary sort by ID to ensure stable pagination
    $order_by = "ORDER BY $sort_sql_column $sort_order, h.InspectionID DESC";
} else {
    $order_by = "ORDER BY $sort_sql_column $sort_order";
}

// --- Build WHERE clause ---
$where_conditions = ["h.StaffUID = :staff_uid"];
$params = [':staff_uid' => $_SESSION['user_id']];

if ($status_filter !== 'all') {
    $where_conditions[] = "h.Status = :status";
    $params[':status'] = $status_filter;
}

if (!empty($date_from)) {
    $where_conditions[] = "CAST(h.DateCreated AS DATE) >= :date_from";
    $params[':date_from'] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "CAST(h.DateCreated AS DATE) <= :date_to";
    $params[':date_to'] = $date_to;
}

$where_clause = implode(' AND ', $where_conditions);

// --- Get total count for pagination ---
$count_sql = "SELECT COUNT(DISTINCT h.InspectionID) 
              FROM uniform_headers h 
              WHERE $where_clause";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);
$offset = ($page - 1) * $per_page;

// --- Fetch Headers (Main Query) ---
$sql = "SELECT DISTINCT h.InspectionID, h.DateCreated, h.DateUpdated, h.Status, h.SupervisorSign, h.RejectionReason
        FROM uniform_headers h 
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

// --- Get Inspection Details ---
$inspection_ids = array_column($headers, 'InspectionID');
$submissions = [];

if (!empty($inspection_ids)) {
    // Reset keys for correct IN clause usage
    $inspection_ids = array_values($inspection_ids);
    $placeholders = implode(',', array_fill(0, count($inspection_ids), '?'));
    
    // Note: This query usually orders by ID/ItemCode for structure, regardless of user sort preference
    $details_sql = "SELECT h.InspectionID, h.DateCreated, h.DateUpdated, h.Status, h.SupervisorSign, h.RejectionReason, 
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
        if (!isset($submissions[$id])) {
            $submissions[$id] = [
                'header' => [
                    'InspectionID' => $row['InspectionID'], 
                    'DateCreated' => $row['DateCreated'], 
                    'DateUpdated' => $row['DateUpdated'], 
                    'Status' => $row['Status'], 
                    'SupervisorSign' => $row['SupervisorSign'],
                    'RejectionReason' => $row['RejectionReason']
                ],
                'items' => []
            ];
        }
        if ($row['ItemCode']) $submissions[$id]['items'][] = $row;
    }
}

// --- CRITICAL: Re-sort submissions based on the Header Query order ---
// The details loop above creates the array in ID order. We must re-sort it
// to match the user's selected sort order (from $headers).
$sorted_submissions = [];
foreach ($headers as $h) {
    if (isset($submissions[$h['InspectionID']])) {
        $sorted_submissions[] = $submissions[$h['InspectionID']];
    }
}
$submissions = $sorted_submissions;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Submissions • La Rose Noire</title>
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

        <nav class="flex-1 px-6 py-8 space-y-3">
            <a href="staff_entry.php" class="nav-item flex items-center space-x-4 px-6 py-4 rounded-2xl text-gray-500 hover:bg-pink-50 hover:text-primary transition-all">
                <i class="fas fa-clipboard-list text-xl"></i><span class="font-bold">New Inspection</span>
            </a>
            <a href="staff_history.php" class="nav-item active flex items-center space-x-4 px-6 py-4 rounded-2xl bg-gradient-to-r from-primary to-primary-dark text-white shadow-lg shadow-pink-200">
                <i class="fas fa-history text-xl"></i><span class="font-bold">History</span>
            </a>
        </nav>

        <div class="p-6 border-t border-gray-100">
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
        <header class="p-8 pb-6">
            <h1 class="text-4xl font-bold text-gray-800 mb-2">My Submissions</h1>
            <p class="text-gray-500 text-lg">Track your inspection history and status updates</p>
        </header>

        <div class="px-8 mb-6">
            <div class="card">
                <form method="GET" action="staff_history.php" class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
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
                                <i class="fas fa-sort mr-1 text-primary"></i> Sort By
                            </label>
                            <select name="sort_by" class="form-input w-full">
                                <option value="DateCreated" <?php echo $sort_by === 'DateCreated' ? 'selected' : ''; ?>>Date Created</option>
                                <option value="DateUpdated" <?php echo $sort_by === 'DateUpdated' ? 'selected' : ''; ?>>Date Updated</option>
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
                            <a href="staff_history.php" class="btn-secondary flex items-center gap-2">
                                <i class="fas fa-redo"></i> Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($total_records > 0): ?>
        <div class="px-8 mb-4 flex items-center justify-between text-sm text-gray-600">
            <div>
                Showing <span class="font-bold text-primary"><?php echo $offset + 1; ?></span> to 
                <span class="font-bold text-primary"><?php echo min($offset + $per_page, $total_records); ?></span> of 
                <span class="font-bold text-primary"><?php echo $total_records; ?></span> submissions
            </div>
            <div class="text-xs text-gray-500">
                Page <?php echo $page; ?> of <?php echo $total_pages; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="px-8 pb-8">
            <div class="space-y-6">
                <?php if (!empty($submissions)): ?>
                    <?php foreach ($submissions as $sub): ?>
                    <?php 
                        $statusColor = match($sub['header']['Status']) { 
                            'Approved' => 'status-approved', 
                            'Rejected' => 'status-rejected', 
                            default => 'status-pending' 
                        };
                        $statusIcon = match($sub['header']['Status']) { 
                            'Approved' => 'fa-check-circle', 
                            'Rejected' => 'fa-times-circle', 
                            default => 'fa-clock' 
                        };
                    ?>
                    <div class="card overflow-hidden group">
                        <div class="px-8 py-6 border-b border-white/30 bg-gradient-to-r from-white/40 to-white/20 flex justify-between items-center">
                            <div class="flex items-center gap-4">
                                <div class="w-12 h-12 bg-gradient-to-r from-blue-400 to-purple-400 rounded-xl flex items-center justify-center shadow-lg">
                                    <i class="fas fa-file-alt text-white"></i>
                                </div>
                                <div>
                                    <h2 class="text-2xl font-bold text-gray-800">
                                        Inspection #<?php echo str_pad($sub['header']['InspectionID'], 4, '0', STR_PAD_LEFT); ?>
                                    </h2>
                                    <p class="text-sm text-gray-500 mt-1">
                                        <i class="fas fa-calendar mr-2 text-primary"></i>
                                        <?php echo date('M d, Y - g:i A', strtotime($sub['header']['DateCreated'])); ?>
                                    </p>
                                </div>
                            </div>
                            
                            <div class="flex items-center gap-3">
                                <span class="status-badge <?php echo $statusColor; ?> flex items-center gap-2">
                                    <i class="fas <?php echo $statusIcon; ?>"></i> <?php echo $sub['header']['Status']; ?>
                                </span>

                                <?php if ($sub['header']['Status'] === 'Rejected' && !empty($sub['header']['RejectionReason'])): ?>
                                    <button onclick="openRejectionModal(this.dataset.reason)" 
                                            data-reason="<?php echo htmlspecialchars($sub['header']['RejectionReason']); ?>"
                                            class="flex items-center gap-1.5 px-3 py-1.5 bg-red-100 text-red-600 rounded-lg hover:bg-red-200 transition-colors text-xs font-bold shadow-sm"
                                            title="Click to view why this was rejected">
                                        <i class="fas fa-question-circle"></i>
                                        Why?
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="p-8">
                            <div class="table rounded-2xl overflow-hidden">
                                <table class="w-full">
                                    <thead>
                                        <tr>
                                            <th>Description</th>
                                            <th>Removal of Dirt / Foreign Objects</th>
                                            <th class="text-center">Quantity Washed</th>
                                            <th class="text-center">Quantity for Repair</th>
                                            <th class="text-center">Quantity for Disposal</th>
                                            <th>Remarks</th>
                                            <th class="text-center">Photo</th>
                                            <th class="text-center">Date Created</th>
                                            <th class="text-center">Supervisor Signature</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($sub['items'] as $item): ?>
                                        <tr>
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
                                            <td class="text-center text-sm text-gray-600 whitespace-nowrap">
                                                <i class="fas fa-calendar mr-1 text-primary"></i>
                                                <?php echo date('M d, Y g:i A', strtotime($sub['header']['DateCreated'])); ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if (!empty($sub['header']['SupervisorSign']) && file_exists($sub['header']['SupervisorSign'])): ?>
                                                    <img src="<?php echo htmlspecialchars($sub['header']['SupervisorSign']); ?>" 
                                                         class="photo-preview mx-auto cursor-pointer"
                                                         onclick="openSignatureModal('<?php echo htmlspecialchars($sub['header']['SupervisorSign']); ?>')"
                                                         alt="Supervisor Signature"
                                                         style="max-width: 100px; max-height: 60px; object-fit: contain;">
                                                <?php else: ?>
                                                    <span class="text-gray-400 italic text-sm">Not signed</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="card p-16 text-center">
                        <div class="w-24 h-24 bg-gradient-to-r from-pink-100 to-purple-100 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i class="fas fa-history text-6xl text-pink-400"></i>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-800 mb-2">No Submissions Yet</h3>
                        <p class="text-gray-500 text-lg mb-6">Your inspection history will appear here</p>
                        <a href="staff_entry.php" class="btn-primary inline-flex items-center gap-2">
                            <i class="fas fa-plus"></i>
                            <span>Create First Inspection</span>
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="px-8 mt-6 flex justify-center items-center gap-2">
                <?php
                $query_params = $_GET;
                unset($query_params['page']);
                $base_url = 'staff_history.php?' . http_build_query($query_params);
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

    <div id="signature-modal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h3 class="text-lg font-bold text-gray-800">Supervisor Signature</h3>
                <button class="modal-close" onclick="closeSignatureModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <img id="modal-signature" src="" alt="Supervisor Signature" class="w-full h-auto rounded-lg">
            </div>
        </div>
    </div>

    <div id="rejection-modal" class="modal-overlay">
        <div class="modal max-w-md w-full">
            <div class="modal-header bg-red-50 border-red-100">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-exclamation-triangle text-red-500"></i>
                    </div>
                    <h3 class="text-lg font-bold text-red-800">Reason for Rejection</h3>
                </div>
                <button class="modal-close hover:bg-red-100 text-red-400 hover:text-red-600" onclick="closeRejectionModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body p-6">
                <div class="bg-red-50/50 p-4 rounded-xl border border-red-100">
                    <p id="rejection-reason-text" class="text-gray-700 leading-relaxed whitespace-pre-wrap font-medium"></p>
                </div>
                <div class="mt-6 flex justify-end">
                    <button onclick="closeRejectionModal()" class="px-4 py-2 bg-gray-100 text-gray-600 font-semibold rounded-lg hover:bg-gray-200 transition-colors">
                        Close
                    </button>
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

        // Signature Modal Functions
        function openSignatureModal(src) {
            document.getElementById('modal-signature').src = src;
            document.getElementById('signature-modal').classList.add('active');
        }

        function closeSignatureModal() {
            document.getElementById('signature-modal').classList.remove('active');
        }

        // Rejection Modal Functions
        function openRejectionModal(reason) {
            document.getElementById('rejection-reason-text').textContent = reason;
            document.getElementById('rejection-modal').classList.add('active');
        }

        function closeRejectionModal() {
            document.getElementById('rejection-modal').classList.remove('active');
        }

        // Event Listeners
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closePhotoModal();
                closeSignatureModal();
                closeRejectionModal();
            }
        });

        document.getElementById('photo-modal').addEventListener('click', function(e) {
            if (e.target === this) closePhotoModal();
        });

        document.getElementById('signature-modal').addEventListener('click', function(e) {
            if (e.target === this) closeSignatureModal();
        });

        document.getElementById('rejection-modal').addEventListener('click', function(e) {
            if (e.target === this) closeRejectionModal();
        });
    </script>
</body>
</html>