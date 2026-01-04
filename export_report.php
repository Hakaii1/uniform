<?php
require_once 'db/conn.php';
require_once 'auth/authenticate.php';
restrictAccess(['Supervisor', 'Team Leader']);

$start = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end = $_GET['end_date'] ?? date('Y-m-d');
$shift = $_GET['shift'] ?? 'all'; // <--- 1. Capture Shift Parameter

// Validate dates
if (!strtotime($start) || !strtotime($end)) {
    die("Invalid date range.");
}

// 2. Build Query
$sql = "SELECT 
            h.InspectionID,
            h.DateCreated,
            h.Shift,
            m.FullName AS StaffName,
            SUM(d.QtyWashed) AS TotalWashed,
            SUM(d.QtyRepair) AS TotalRepair,
            SUM(d.QtyDisposal) AS TotalDisposal
        FROM uniform_headers h
        JOIN lrn_master_list m ON h.StaffUID = m.id
        JOIN uniform_details d ON h.InspectionID = d.InspectionID
        WHERE h.Status = 'Approved'
          AND CAST(h.DateCreated AS DATE) BETWEEN :start AND :end";

$params = [':start' => $start, ':end' => $end];

// 3. Apply Shift Filter if not 'all'
if ($shift !== 'all') {
    $sql .= " AND h.Shift = :shift";
    $params[':shift'] = $shift;
}

$sql .= " GROUP BY h.InspectionID, h.DateCreated, h.Shift, m.FullName
          ORDER BY h.DateCreated DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Aggregation (Same logic as before)
$dailyStats = [];
$shiftStats = [];
$grandTotal = ['washed' => 0, 'repair' => 0, 'disposal' => 0];

foreach ($results as $row) {
    $date = date('Y-m-d', strtotime($row['DateCreated']));
    $rowShift = $row['Shift'] ?: 'Unknown'; 

    if (!isset($dailyStats[$date])) {
        $dailyStats[$date] = ['washed' => 0, 'repair' => 0, 'disposal' => 0];
    }
    if (!isset($shiftStats[$rowShift])) {
        $shiftStats[$rowShift] = ['washed' => 0, 'repair' => 0, 'disposal' => 0];
    }

    $dailyStats[$date]['washed'] += $row['TotalWashed'];
    $dailyStats[$date]['repair'] += $row['TotalRepair'];
    $dailyStats[$date]['disposal'] += $row['TotalDisposal'];

    $shiftStats[$rowShift]['washed'] += $row['TotalWashed'];
    $shiftStats[$rowShift]['repair'] += $row['TotalRepair'];
    $shiftStats[$rowShift]['disposal'] += $row['TotalDisposal'];

    $grandTotal['washed'] += $row['TotalWashed'];
    $grandTotal['repair'] += $row['TotalRepair'];
    $grandTotal['disposal'] += $row['TotalDisposal'];
}

// Sort
ksort($dailyStats);
ksort($shiftStats);

// 5. Output CSV
// Update filename to reflect shift selection if specific
$filename_shift_part = ($shift !== 'all') ? '_' . str_replace(' ', '_', strtolower($shift)) : '';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="uniform_report' . $filename_shift_part . '_' . $start . '_to_' . $end . '.csv"');

$output = fopen('php://output', 'w');

fputcsv($output, ['--- DETAILED INSPECTION LIST ---']);
fputcsv($output, ['Inspection ID', 'Date', 'Shift', 'Staff Name', 'Total Washed', 'Total Repair', 'Total Disposal']);

foreach ($results as $row) {
    fputcsv($output, [
        $row['InspectionID'],
        date('Y-m-d H:i', strtotime($row['DateCreated'])),
        $row['Shift'],
        $row['StaffName'],
        $row['TotalWashed'] ?? 0,
        $row['TotalRepair'] ?? 0,
        $row['TotalDisposal'] ?? 0
    ]);
}

fputcsv($output, []);
fputcsv($output, []);

fputcsv($output, ['--- DAILY SUMMARY ---']);
fputcsv($output, ['Date', 'Total Washed', 'Total Repair', 'Total Disposal']);
foreach ($dailyStats as $date => $stats) {
    fputcsv($output, [$date, $stats['washed'], $stats['repair'], $stats['disposal']]);
}

fputcsv($output, []);
fputcsv($output, []);

fputcsv($output, ['--- SHIFT SUMMARY ---']);
fputcsv($output, ['Shift', 'Total Washed', 'Total Repair', 'Total Disposal']);
foreach ($shiftStats as $s => $stats) {
    fputcsv($output, [$s, $stats['washed'], $stats['repair'], $stats['disposal']]);
}

fputcsv($output, []);
fputcsv($output, []);

fputcsv($output, ['--- GRAND TOTALS ' . ($shift !== 'all' ? "($shift ONLY)" : "") . ' ---']);
fputcsv($output, ['Category', 'Total Count']);
fputcsv($output, ['Total Items Washed', $grandTotal['washed']]);
fputcsv($output, ['Total Items for Repair', $grandTotal['repair']]);
fputcsv($output, ['Total Items for Disposal', $grandTotal['disposal']]);

fclose($output);
exit();
?>