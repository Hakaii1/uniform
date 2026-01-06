<?php
session_start();
require_once 'db/conn.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: staff_entry.php");
    exit;
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

try {
    $conn->beginTransaction();

    // 1. Insert the Header First
    $shift = $_POST['shift'] ?? null;
    if (empty($shift)) {
        throw new Exception('Shift is required.');
    }
    $stmtH = $conn->prepare("
        INSERT INTO uniform_headers (StaffUID, Status, DateCreated, Shift) 
        OUTPUT INSERTED.InspectionID
        VALUES (?, 'Pending', GETDATE(), ?)
    ");
    $stmtH->execute([$_SESSION['user_id'], $shift]);
    $inspection_id = $stmtH->fetchColumn();

    if (!$inspection_id) {
        throw new Exception("Failed to create inspection header.");
    }

    // 2. Prepare Directory for photos
    $target_dir = "uploads/photos/";
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    $raw_desc_list = $_POST['desc'] ?? [];
    $total_posted_rows = count($raw_desc_list);
    $saved_items_count = 0; // Counter for valid items

    // 3. Loop through ALL submitted rows
    for ($i = 0; $i < $total_posted_rows; $i++) {
        $description = trim($raw_desc_list[$i] ?? '');

        // FIX: If this specific row is empty, SKIP it (don't break the whole process)
        if (empty($description)) {
            continue;
        }

        // Increment valid item counter for sequential ItemCodes (001, 002, etc.)
        $saved_items_count++; 
        
        $removal_of_dirt = $_POST['dirt'][$i] ?? null;
        $qty_washed      = (int)($_POST['qty_w'][$i] ?? 0);
        $qty_repair      = (int)($_POST['qty_r'][$i] ?? 0);
        $qty_disposal    = (int)($_POST['qty_d'][$i] ?? 0);
        $remarks         = $_POST['remarks'][$i] ?? null;

        // Generate clean ItemCode (e.g., 001, 002) based on saved count, not loop index
        $item_code = str_pad($saved_items_count, 3, '0', STR_PAD_LEFT);

        // Handle photo upload
        $photo_path = null;
        // Check if a file exists at this specific index $i
        if (isset($_FILES['photo']['name'][$i]) && $_FILES['photo']['error'][$i] === UPLOAD_ERR_OK) {
            $file_ext = strtolower(pathinfo($_FILES["photo"]["name"][$i], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];

            if (in_array($file_ext, $allowed)) {
                // Unique filename
                $new_filename = "photo_" . $inspection_id . "_" . $item_code . "_" . time() . "." . $file_ext;
                $target_file = $target_dir . $new_filename;

                if (move_uploaded_file($_FILES["photo"]["tmp_name"][$i], $target_file)) {
                    $photo_path = $target_file;
                }
            }
        }

        // Insert detail
        $stmtD = $conn->prepare("
            INSERT INTO uniform_details 
            (InspectionID, ItemCode, Description, RemovalOfDirt, QtyWashed, QtyRepair, QtyDisposal, Remarks, StaffPhoto) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmtD->execute([
            $inspection_id,
            $item_code,
            $description,
            $removal_of_dirt,
            $qty_washed,
            $qty_repair,
            $qty_disposal,
            $remarks,
            $photo_path
        ]);
    }

    // 4. Final Check: Did we save at least one item?
    if ($saved_items_count === 0) {
        throw new Exception("Please provide at least one item with a description.");
    }

    $conn->commit();
    header("Location: staff_entry.php?msg=success");
    exit;

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    // Log error for debugging if needed: error_log($e->getMessage());
    header("Location: staff_entry.php?msg=error&details=" . urlencode($e->getMessage()));
    exit;
}
?>