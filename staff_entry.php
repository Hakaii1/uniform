<?php 
session_start(); 
if(!isset($_SESSION['user_id'])) header("Location: login.php"); 
require_once 'auth/authenticate.php';
require_once 'db/conn.php';
restrictAccess(['Staff']);

$full_name = $_SESSION['full_name'] ?? 'Staff Member';
$department = $_SESSION['dept'] ?? 'Facilities Management';
$current_date = date('F j, Y');

// Fetch previous submissions count for badge
$previousCount = $conn->query("SELECT COUNT(*) FROM uniform_headers WHERE StaffUID = {$_SESSION['user_id']}")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Uniform Inspection • La Rose Noire</title>
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
            <a href="staff_entry.php" class="nav-item active flex items-center space-x-4 px-6 py-4 rounded-2xl bg-gradient-to-r from-primary to-primary-dark text-white shadow-lg shadow-pink-200/50 transition-all duration-300 group">
                <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform">
                    <i class="fas fa-plus-circle text-lg"></i>
                </div>
                <span class="font-bold">New Inspection</span>
            </a>
            
            <a href="staff_history.php" class="nav-item flex items-center justify-between px-6 py-4 rounded-2xl text-gray-600 hover:bg-pink-50/50 hover:text-primary transition-all duration-300 group">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-gray-100 rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform">
                        <i class="fas fa-history text-lg"></i>
                    </div>
                    <span class="font-medium">History</span>
                </div>
                <?php if($previousCount > 0): ?>
                    <span class="bg-pink-100 text-pink-600 px-2 py-0.5 rounded-full text-xs font-bold"><?php echo $previousCount; ?></span>
                <?php endif; ?>
            </a>
        </nav>

        <div class="p-6 border-t border-gray-100">
            <a href="logout.php" class="flex items-center space-x-3 text-gray-500 hover:text-red-400 transition pl-2">
                <i class="fas fa-sign-out-alt"></i>
                <span class="font-semibold">Log Out</span>
            </a>
        </div>
    </aside>

    <main class="flex-1 overflow-y-auto relative">
        <div class="p-8 max-w-full mx-auto">
            
            <header class="flex justify-between items-end mb-8">
                <div>
                    <h1 class="text-4xl font-bold text-gray-800 mb-2">Uniform Inspection</h1>
                    <div class="flex items-center text-gray-500 text-sm space-x-4 bg-white/50 px-4 py-2 rounded-full inline-flex backdrop-blur-sm">
                        <span><i class="far fa-calendar-alt mr-2 text-primary"></i><?php echo $current_date; ?></span>
                        <span class="w-1 h-1 bg-gray-300 rounded-full"></span>
                        <span><i class="far fa-user mr-2 text-primary"></i><?php echo htmlspecialchars($full_name); ?> <span class="text-gray-400">•</span> <?php echo htmlspecialchars($department); ?></span>
                    </div>
                </div>
                <div class="flex gap-4">
                    <div class="text-right">
                        <label class="block text-xs font-bold text-gray-400 mb-1 uppercase tracking-wider">Supervisor</label>
                        <select class="form-input text-sm w-40">
                            <option>Select Supervisor...</option>
                            <option>John Doe</option>
                            <option>Jane Smith</option>
                        </select>
                    </div>
                </div>
            </header>

            <div class="card p-2 mb-8">
                <form action="process_submission.php" method="POST" enctype="multipart/form-data">
                    
                    <div class="px-8 py-6 border-b border-white/30 flex justify-between items-center bg-white/30 rounded-t-3xl">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 bg-gradient-to-r from-pink-400 to-purple-400 rounded-xl flex items-center justify-center shadow-lg">
                                <i class="fas fa-clipboard-list text-lg text-white"></i>
                            </div>
                            <div>
                                <h2 class="text-xl font-bold text-gray-800">Inspection Items</h2>
                                <p class="text-sm text-gray-500">Add and manage your laundry items below</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <label class="block text-xs font-bold text-gray-400 mb-1 uppercase tracking-wider" for="shift">Shift</label>
                            <select class="form-input text-sm w-40" name="shift" id="shift" required>
                                <option value="">Select Shift</option>
                                <option value="1st Shift">1st Shift</option>
                                <option value="2nd Shift">2nd Shift</option>
                                <option value="3rd Shift">3rd Shift</option>
                            </select>
                            <button type="button" onclick="addRow()" class="btn-primary flex items-center gap-3 px-6 py-3 hover:scale-105 transition-transform">
                                <i class="fas fa-plus"></i>
                                <span>Add Item</span>
                            </button>
                        </div>
                    </div>

                    <div class="p-8">
                        <div class="table overflow-x-auto rounded-2xl border border-white/50 shadow-xl bg-white/20 mb-8 overflow-hidden">
                            <table class="w-full" id="itemsTable" style="table-layout: auto;">
                                <thead class="rounded-t-2xl overflow-hidden">
                                    <tr>
                                        <th class="text-left p-4 align-top" style="min-width: 180px;">
                                            <div class="flex items-center gap-2">
                                                <i class="fas fa-tag text-pink-400"></i>
                                                <span>Description</span>
                                            </div>
                                        </th>
                                        <th class="text-left p-4 align-top" style="min-width: 250px;">
                                            <div class="flex items-center gap-2">
                                                <i class="fas fa-exclamation-triangle text-orange-400"></i>
                                                <span>Removal of Dirt / Foreign Objects</span>
                                            </div>
                                        </th>
                                        <th class="text-center p-4 align-top" style="min-width: 140px;">
                                            <div class="flex items-center justify-center gap-2">
                                                <i class="fas fa-check-circle text-green-400"></i>
                                                <span>Quantity Washed</span>
                                            </div>
                                        </th>
                                        <th class="text-center p-4 align-top" style="min-width: 140px;">
                                            <div class="flex items-center justify-center gap-2">
                                                <i class="fas fa-tools text-blue-400"></i>
                                                <span>Quantity for Repair</span>
                                            </div>
                                        </th>
                                        <th class="text-center p-4 align-top" style="min-width: 140px;">
                                            <div class="flex items-center justify-center gap-2">
                                                <i class="fas fa-trash-alt text-red-400"></i>
                                                <span>Quantity for Disposal</span>
                                            </div>
                                        </th>
                                        <th class="text-left p-4 align-top" style="min-width: 250px;">
                                            <div class="flex items-center gap-2">
                                                <i class="fas fa-camera text-purple-400"></i>
                                                <span>Photo & Remarks</span>
                                            </div>
                                        </th>
                                        <th class="text-center p-4 align-middle" style="min-width: 80px;">
                                            <div class="flex items-center justify-center gap-2">
                                                <i class="fas fa-cog text-gray-400"></i>
                                                <span>Actions</span>
                                            </div>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="group hover:bg-white/30 transition-all duration-300">
                                        <td class="p-4 align-top">
                                            <input type="text" name="desc[]" required placeholder="Item Name (e.g. Jacket)"
                                                   class="form-input w-full text-sm">
                                        </td>
                                        <td class="p-4 align-top">
                                            <textarea name="dirt[]" rows="3" placeholder="Stains, dirt, foreign objects..."
                                                      class="form-input w-full text-sm resize-none"></textarea>
                                        </td>
                                        <td class="p-4 align-top text-center">
                                            <div class="flex justify-center">
                                                <input type="number" name="qty_w[]" min="0" value="0"
                                                       class="w-20 px-2 py-2 bg-green-50 border-2 border-green-100 rounded-lg text-center font-bold text-green-600 focus:ring-2 focus:ring-green-200 outline-none">
                                            </div>
                                        </td>
                                        <td class="p-4 align-top text-center">
                                            <div class="flex justify-center">
                                                <input type="number" name="qty_r[]" min="0" value="0"
                                                       class="w-20 px-2 py-2 bg-orange-50 border-2 border-orange-100 rounded-lg text-center font-bold text-orange-500 focus:ring-2 focus:ring-orange-200 outline-none">
                                            </div>
                                        </td>
                                        <td class="p-4 align-top text-center">
                                            <div class="flex justify-center">
                                                <input type="number" name="qty_d[]" min="0" value="0"
                                                       class="w-20 px-2 py-2 bg-red-50 border-2 border-red-100 rounded-lg text-center font-bold text-red-500 focus:ring-2 focus:ring-red-200 outline-none">
                                            </div>
                                        </td>
                                        <td class="p-4 align-top space-y-3">
                                            <div class="flex items-center gap-2">
                                                <input type="file" name="photo[]" accept="image/*" onchange="handlePhotoChange(this)"
                                                       class="flex-1 text-xs text-gray-500 file:mr-2 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-gradient-to-r file:from-pink-50 file:to-purple-50 file:text-pink-600 hover:file:from-pink-100 hover:file:to-purple-100 transition-all">
                                                <button type="button" onclick="removePhoto(this)" class="remove-photo-btn hidden w-8 h-8 flex items-center justify-center rounded-lg bg-red-500 text-white hover:bg-red-600 transition-all shadow-lg" title="Remove photo">
                                                    <i class="fas fa-times text-xs"></i>
                                                </button>
                                            </div>
                                            <input type="text" name="remarks[]" placeholder="Additional remarks..."
                                                   class="form-input w-full text-sm">
                                        </td>
                                        <td class="p-4 align-middle text-center">
                                            <div class="flex justify-center">
                                                <button type="button" onclick="deleteRow(this)" class="w-8 h-8 flex items-center justify-center rounded-lg text-gray-400 hover:bg-red-50 hover:text-red-500 transition-all duration-300" title="Delete item">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit" class="btn-primary px-10 py-4 text-lg font-semibold flex items-center gap-3 group">
                                <i class="fas fa-paper-plane"></i>
                                <span>Submit Inspection</span>
                                <i class="fas fa-arrow-right group-hover:translate-x-1 transition-transform"></i>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <div id="errorModal" class="fixed inset-0 z-50 hidden items-center justify-center" style="background: rgba(0, 0, 0, 0.5); backdrop-filter: blur(4px);">
        <div class="bg-white rounded-3xl shadow-2xl max-w-md w-full mx-4 transform scale-95 transition-all duration-300" id="errorModalContent">
            <div class="p-8">
                <div class="flex items-center justify-center mb-6">
                    <div class="w-20 h-20 bg-red-100 rounded-full flex items-center justify-center shadow-lg">
                        <i class="fas fa-exclamation-circle text-red-500 text-4xl"></i>
                    </div>
                </div>
                <div class="text-center mb-6">
                    <h3 class="text-2xl font-bold text-gray-800 mb-3">Validation Error</h3>
                    <p id="errorMessage" class="text-gray-600 text-base leading-relaxed"></p>
                </div>
                <div class="flex justify-center">
                    <button onclick="closeErrorNotification()" class="btn-primary px-8 py-3 flex items-center gap-2">
                        <i class="fas fa-check"></i>
                        <span>Got it</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="successModal" class="fixed inset-0 z-50 hidden items-center justify-center" style="background: rgba(0, 0, 0, 0.5); backdrop-filter: blur(4px);">
        <div class="bg-white rounded-3xl shadow-2xl max-w-md w-full mx-4 transform scale-95 transition-all duration-300" id="successModalContent">
            <div class="p-8">
                <div class="flex items-center justify-center mb-6">
                    <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center shadow-lg">
                        <i class="fas fa-check-circle text-green-500 text-4xl"></i>
                    </div>
                </div>
                <div class="text-center mb-6">
                    <h3 class="text-2xl font-bold text-gray-800 mb-3">Submitted!</h3>
                    <p class="text-gray-600 text-base leading-relaxed">Your inspection has been sent successfully.</p>
                </div>
                <div class="flex justify-center">
                    <button onclick="closeSuccessNotification()" class="btn-primary px-8 py-3 flex items-center gap-2 bg-gradient-to-r from-green-400 to-emerald-500 shadow-green-200 border-none">
                        <i class="fas fa-check"></i>
                        <span>Great!</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Check for URL parameters on page load
        window.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            
            // Check for success
            if (urlParams.get('msg') === 'success') {
                showSuccessNotification();
                // Clean URL parameters
                const url = new URL(window.location);
                url.searchParams.delete('msg');
                window.history.replaceState({}, '', url);
            } 
            // Check for PHP-redirected errors
            else if (urlParams.get('msg') === 'error') {
                const details = urlParams.get('details');
                showErrorNotification(details || 'An error occurred during submission.');
                // Clean URL parameters
                const url = new URL(window.location);
                url.searchParams.delete('msg');
                url.searchParams.delete('details');
                window.history.replaceState({}, '', url);
            }
        });

        // Show error modal
        function showErrorNotification(message) {
            const modal = document.getElementById('errorModal');
            const modalContent = document.getElementById('errorModalContent');
            const errorMessage = document.getElementById('errorMessage');
            
            errorMessage.textContent = message;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            
            // Trigger animation
            setTimeout(() => {
                modalContent.classList.remove('scale-95');
                modalContent.classList.add('scale-100');
            }, 10);
        }

        // Close error modal
        function closeErrorNotification() {
            const modal = document.getElementById('errorModal');
            const modalContent = document.getElementById('errorModalContent');
            
            modalContent.classList.remove('scale-100');
            modalContent.classList.add('scale-95');
            
            setTimeout(() => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }, 300);
        }

        // NEW: Show Success Notification
        function showSuccessNotification() {
            const modal = document.getElementById('successModal');
            const modalContent = document.getElementById('successModalContent');
            
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            
            setTimeout(() => {
                modalContent.classList.remove('scale-95');
                modalContent.classList.add('scale-100');
            }, 10);

            // Auto-close after 4 seconds (Temporary notification)
            setTimeout(closeSuccessNotification, 4000);
        }

        // NEW: Close Success Notification
        function closeSuccessNotification() {
            const modal = document.getElementById('successModal');
            const modalContent = document.getElementById('successModalContent');
            
            modalContent.classList.remove('scale-100');
            modalContent.classList.add('scale-95');
            
            setTimeout(() => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }, 300);
        }

        // Close modal when clicking outside
        document.getElementById('errorModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeErrorNotification();
            }
        });

        document.getElementById('successModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeSuccessNotification();
            }
        });

        // Handle photo change - show/hide X button
        function handlePhotoChange(input) {
            const removeBtn = input.nextElementSibling;
            if (input.files && input.files.length > 0) {
                removeBtn.classList.remove('hidden');
            } else {
                removeBtn.classList.add('hidden');
            }
        }

        // Remove photo and reset file input
        function removePhoto(btn) {
            const fileInput = btn.previousElementSibling;
            fileInput.value = '';
            btn.classList.add('hidden');
        }

        // Add new row
        function addRow() {
            const tbody = document.getElementById('itemsTable').querySelector('tbody');
            const firstRow = tbody.querySelector('tr');
            const newRow = firstRow.cloneNode(true);
            
            // Reset inputs
            newRow.querySelectorAll('input').forEach(i => {
                if(i.type === 'number') i.value = '0';
                else if(i.type !== 'file') i.value = '';
                else if(i.type === 'file') {
                    i.value = '';
                    // Hide remove button if it exists
                    const removeBtn = i.nextElementSibling;
                    if (removeBtn && removeBtn.classList.contains('remove-photo-btn')) {
                        removeBtn.classList.add('hidden');
                    }
                }
            });
            newRow.querySelectorAll('textarea').forEach(t => t.value = '');
            
            tbody.appendChild(newRow);
        }

        // Delete row
        function deleteRow(btn) {
            const tbody = document.getElementById('itemsTable').querySelector('tbody');
            if (tbody.children.length > 1) {
                btn.closest('tr').remove();
            } else {
                showErrorNotification("At least one item is required. You cannot delete the last item.");
            }
        }

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const shift = document.getElementById('shift').value;
            if (!shift) {
                e.preventDefault();
                showErrorNotification('Please select a shift before submitting.');
                return;
            }
            const descriptions = document.querySelectorAll('input[name="desc[]"]');
            let hasValidItem = false;
            
            descriptions.forEach(desc => {
                if (desc.value.trim() !== '') {
                    hasValidItem = true;
                }
            });
            
            if (!hasValidItem) {
                e.preventDefault();
                showErrorNotification('Please add at least one item description before submitting.');
            }
        });
    </script>
</body>
</html>