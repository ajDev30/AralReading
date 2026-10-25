<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$success_msg = '';
$error_msg = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $fname = trim($_POST['fname'] ?? '');
        $lname = trim($_POST['lname'] ?? '');
        $mname = trim($_POST['mname'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        // Handle Profile Picture Upload (No DB required)
        if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/uploads/profiles/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            
            // Force save as JPG for simplicity, overriding existing
            $target_file = $upload_dir . 'user_' . $user_id . '.jpg';
            
            // Move uploaded file
            move_uploaded_file($_FILES['profile_pic']['tmp_name'], $target_file);
        }
        
        try {
            $stmt = $pdo->prepare("UPDATE users SET fname=?, lname=?, mname=?, username=?, email=? WHERE id=?");
            $stmt->execute([$fname, $lname, $mname, $username, $email, $user_id]);
            $success_msg = "Profile updated successfully!";
        } catch (PDOException $e) {
            $error_msg = "Error updating profile. Username or email might already be taken.";
        }
    }
    
    if (isset($_POST['update_password'])) {
        $new_pass = $_POST['new_password'] ?? '';
        if (strlen($new_pass) >= 4) { // keep it simple for now
            $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password=? WHERE id=?");
            $stmt->execute([$hashed, $user_id]);
            $success_msg = "Password updated successfully!";
        } else {
            $error_msg = "Password must be at least 4 characters.";
        }
    }

    if (isset($_POST['toggle_theme'])) {
        $theme = $_POST['theme'] ?? 'light';
        setcookie('theme', $theme, time() + (86400 * 365), "/"); // 1 year
        $_COOKIE['theme'] = $theme; // Update for current load
        $success_msg = "Theme preferences updated!";
    }
}

// Get user info
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
$level = $user['current_level'];

$mname = $user['mname'] ?? '';
$mi = !empty($mname) ? strtoupper(substr($mname, 0, 1)) . '.' : '';
$fullName = htmlspecialchars($user['fname'] . ' ' . $mi . ' ' . $user['lname']);
$is_dark = isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="https://tibungcodistrict.wordpress.com/wp-content/uploads/2018/06/untitled-1.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Phil-IRI</title>
    <script>
        const originalWarn = console.warn;
        console.warn = function() {
            if (arguments[0] && typeof arguments[0] === 'string' && arguments[0].includes('cdn.tailwindcss.com should not be used in production')) return;
            originalWarn.apply(console, arguments);
        };
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #F8FAFC; transition: all 0.3s ease; }
        .sidebar { background-color: #1a365d; }
        
    
                <?php $is_dark = isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark'; ?>
        <?php if($is_dark): ?>
        /* Refined Slate Dark Mode */
        body { background-color: #0f172a !important; color: #f8fafc !important; }
        .bg-white, .bg-slate-50 { background-color: #1e293b !important; border-color: #334155 !important; color: #f8fafc !important; }
        
        .text-slate-800, .text-slate-700 { color: #f1f5f9 !important; }
        .text-slate-600, .text-slate-500, .text-slate-400 { color: #cbd5e1 !important; }
        .border-slate-200, .border-slate-100, .border-b { border-color: #334155 !important; }
        .border-slate-300 { border-color: #475569 !important; }
        .sidebar { background-color: #0b1120 !important; border-right: 1px solid #1e293b !important; }
        input, select, textarea { background-color: #0f172a !important; color: white !important; border-color: #475569 !important; }
        .shadow-sm { box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.5) !important; }

        /* Colored Badges / Cards Fixes */
        .bg-blue-50, .bg-blue-100 { background-color: rgba(59, 130, 246, 0.2) !important; color: #93c5fd !important; }
        .text-blue-600, .text-blue-700, .text-blue-800 { color: #60a5fa !important; }
        .border-blue-100, .border-blue-200 { border-color: rgba(59, 130, 246, 0.3) !important; }

        .bg-emerald-50, .bg-emerald-100 { background-color: rgba(16, 185, 129, 0.2) !important; color: #6ee7b7 !important; }
        .text-emerald-600, .text-emerald-700, .text-emerald-800 { color: #34d399 !important; }
        .border-emerald-100, .border-emerald-200 { border-color: rgba(16, 185, 129, 0.3) !important; }

        .bg-amber-50, .bg-amber-100 { background-color: rgba(245, 158, 11, 0.2) !important; color: #fcd34d !important; }
        .text-amber-600, .text-amber-700, .text-amber-800 { color: #fbbf24 !important; }
        .border-amber-100, .border-amber-200 { border-color: rgba(245, 158, 11, 0.3) !important; }

        .bg-rose-50, .bg-rose-100 { background-color: rgba(244, 63, 94, 0.2) !important; color: #fda4af !important; }
        .text-rose-600, .text-rose-700, .text-rose-800 { color: #fb7185 !important; }
        .border-rose-100, .border-rose-200 { border-color: rgba(244, 63, 94, 0.3) !important; }
        
        .bg-purple-50, .bg-purple-100 { background-color: rgba(168, 85, 247, 0.2) !important; color: #d8b4fe !important; }
        .text-purple-600, .text-purple-700, .text-purple-800 { color: #c084fc !important; }
        
        
        /* Bug Fixes for "Not Taken", "Pre-Test", and Table Hovers */
        .bg-slate-100, .bg-slate-200 { background-color: #334155 !important; color: #e2e8f0 !important; }
        .hover\:bg-slate-50:hover { background-color: #334155 !important; }
        tr:hover { background-color: #334155 !important; }
/* Logo Fix */
        .sidebar img { background-color: transparent !important; filter: drop-shadow(0px 0px 2px rgba(255,255,255,0.5)) !important; }
        <?php endif; ?>
    </style>



    <script src="js/htmx.min.js"></script>
    <meta name="htmx-config" content='{"globalViewTransitions":true}'>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="flex h-screen overflow-hidden text-slate-800">

    <!-- Sidebar -->
    <?php include 'teacher_sidebar.php'; ?>

    <div class="flex-1 flex flex-col h-screen overflow-hidden">
        <header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-4 md:px-8 shrink-0">
            <div class="flex items-center">
                <button onclick="toggleSidebar()" class="md:hidden mr-4 text-slate-500 hover:text-blue-600 focus:outline-none"><i class="fas fa-bars text-xl"></i></button>
                <div>
                <h2 class="font-bold text-lg text-slate-800">Account Settings</h2>
                <p class="text-sm text-slate-500">Manage your profile, password, and preferences.</p>
            </div></div>
            <div class="flex items-center space-x-3 border-l pl-4 border-slate-200">
                <?php 
                $avatarPath = 'uploads/profiles/user_' . $user_id . '.jpg';
                if (file_exists($avatarPath)): 
                ?>
                    <img src="<?php echo $avatarPath; ?>?t=<?php echo time(); ?>" class="w-10 h-10 rounded-full object-cover border border-slate-200">
                <?php else: ?>
                    <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 font-bold">
                        <?php echo substr($_SESSION['fname'], 0, 1); ?>
                    </div>
                <?php endif; ?>
                <div class="leading-tight">
                    <p class="font-semibold text-sm"><?php echo htmlspecialchars($_SESSION['fname']); ?></p>
                    <p class="text-xs text-slate-500 capitalize">Teacher</p>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-4 md:p-8 bg-slate-50">
            <div class="max-w-4xl mx-auto">
                
                <?php if($success_msg): ?>
                    <div class="bg-emerald-50 text-emerald-700 p-4 rounded border border-emerald-200 mb-6 font-bold shadow-sm flex items-center">
                        <i class="fas fa-check-circle mr-2"></i> <?php echo $success_msg; ?>
                    </div>
                <?php endif; ?>

                <?php if($error_msg): ?>
                    <div class="bg-rose-50 text-rose-700 p-4 rounded border border-rose-200 mb-6 font-bold shadow-sm flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i> <?php echo $error_msg; ?>
                    </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:p-8">
                    
                    <!-- Profile Update Form -->
                    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                        <h3 class="font-bold text-slate-800 text-lg mb-4 border-b pb-2">Profile Information</h3>
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="update_profile" value="1">
                            
                            <!-- Profile Picture Upload -->
                            <div class="mb-6 flex items-center space-x-4">
                                <?php 
                                $avatarPath = 'uploads/profiles/user_' . $user_id . '.jpg';
                                if (file_exists($avatarPath)): 
                                ?>
                                    <img src="<?php echo $avatarPath; ?>?t=<?php echo time(); ?>" class="w-16 h-16 rounded-full object-cover border-2 border-blue-500 shadow-sm">
                                <?php else: ?>
                                    <div class="w-16 h-16 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 font-bold text-2xl border-2 border-blue-200">
                                        <i class="fas fa-user"></i>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <label class="block text-sm font-bold text-slate-600 mb-1">Profile Picture</label>
                                    <input type="file" name="profile_pic" accept="image/*" class="block w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 transition">
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label class="block text-sm font-bold text-slate-600 mb-1">First Name</label>
                                    <input type="text" name="fname" value="<?php echo htmlspecialchars($user['fname']); ?>" class="w-full border border-slate-300 rounded p-2" required>
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-600 mb-1">Last Name</label>
                                    <input type="text" name="lname" value="<?php echo htmlspecialchars($user['lname']); ?>" class="w-full border border-slate-300 rounded p-2" required>
                                </div>
                            </div>
                            <div class="mb-4">
                                <label class="block text-sm font-bold text-slate-600 mb-1">Middle Name</label>
                                <input type="text" name="mname" value="<?php echo htmlspecialchars($user['mname']); ?>" class="w-full border border-slate-300 rounded p-2">
                            </div>
                            <div class="mb-4">
                                <label class="block text-sm font-bold text-slate-600 mb-1">Username</label>
                                <input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" class="w-full border border-slate-300 rounded p-2" required>
                            </div>
                            <div class="mb-6">
                                <label class="block text-sm font-bold text-slate-600 mb-1">Email Address</label>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" class="w-full border border-slate-300 rounded p-2 placeholder-slate-400" placeholder="student@example.com">
                            </div>
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded w-full transition">Save Profile</button>
                        </form>
                    </div>

                    <div class="space-y-8">
                        <!-- Password Update Form -->
                        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                            <h3 class="font-bold text-slate-800 text-lg mb-4 border-b pb-2">Change Password</h3>
                            <form method="POST">
                                <input type="hidden" name="update_password" value="1">
                                <input type="text" name="username" value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" autocomplete="username" class="hidden" readonly tabindex="-1" aria-hidden="true">
                                <div class="mb-6">
                                    <label class="block text-sm font-bold text-slate-600 mb-1">New Password</label>
                                    <input type="password" name="new_password" autocomplete="new-password" class="w-full border border-slate-300 rounded p-2" required minlength="4">
                                </div>
                                <button type="submit" class="bg-slate-800 hover:bg-slate-900 text-white font-bold py-2 px-4 rounded w-full transition">Update Password</button>
                            </form>
                        </div>

                        <!-- Theme Toggle -->
                        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                            <h3 class="font-bold text-slate-800 text-lg mb-4 border-b pb-2">Appearance</h3>
                            <form method="POST" class="flex flex-col md:flex-row md:items-center justify-between">
                                <input type="hidden" name="toggle_theme" value="1">
                                <div>
                                    <p class="font-bold text-slate-700">Dark Mode</p>
                                    <p class="text-xs text-slate-500">Switch to a dark color scheme.</p>
                                </div>
                                    <?php if($is_dark): ?>
                                    <input type="hidden" name="theme" value="light">
                                    <button type="submit" class="bg-slate-700 text-white px-4 py-2 rounded-full font-bold text-sm hover:bg-slate-800 transition"><i class="fas fa-moon mr-2"></i> Dark Mode ON</button>
                                <?php else: ?>
                                    <input type="hidden" name="theme" value="dark">
                                    <button type="submit" class="bg-slate-200 text-slate-700 px-4 py-2 rounded-full font-bold text-sm hover:bg-slate-300 transition"><i class="fas fa-sun mr-2"></i> Dark Mode OFF</button>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>

                </div>
            </div>
        </main>
    </div>

    <script>
        window.toggleSidebar = function() {
            const sidebar = document.getElementById('appSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if(sidebar) sidebar.classList.toggle('-translate-x-full');
            if(overlay) overlay.classList.toggle('hidden');
        }
    </script>
</body>
</html>
