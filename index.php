<?php
session_start();
require_once 'config.php';

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    // Anti-SQL Injection: Using prepared statements
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username");
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        // Set session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['fname'] = $user['fname'];

        // Role-Based Access Control (RBAC)
        if ($user['role'] === 'teacher') {
            header("Location: dashboard_teacher.php");
        } else {
            header("Location: dashboard_student.php");
        }
        exit;
    } else {
        $error = "Invalid username or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="aral_logoindex.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - ARAL READING Program</title>
    <!-- Tailwind CSS -->
    <script>
        const originalWarn = console.warn;
        console.warn = function() {
            if (arguments[0] && typeof arguments[0] === 'string' && arguments[0].includes('cdn.tailwindcss.com should not be used in production')) return;
            originalWarn.apply(console, arguments);
        };
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #F8FAFC; }
    .sidebar { background-color: #1a365d; }
    </style>

    <script src="js/htmx.min.js"></script>
    <meta name="htmx-config" content='{"globalViewTransitions":true}'>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="flex items-center justify-center min-h-screen">

    <div class="w-full max-w-md bg-white rounded-xl shadow-sm border border-slate-200 p-4 md:p-8">
        
        <!-- Logo & Header -->
        <div class="text-center mb-8">
            <img src="aral_logoindex.png" alt="ARAL Reading Program Logo" class="mx-auto h-36 mb-4 object-contain drop-shadow-sm">
            <h2 class="text-2xl font-bold text-slate-800">Welcome to ARAL PROGRAM</h2>
            <p class="text-sm text-slate-500 mt-1">Sign in to the ARAL READING Program</p>
        </div>

        <!-- Success Message -->
        <?php if(isset($_GET['registered']) && $_GET['registered'] === '1'): ?>
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-lg mb-6 text-sm flex items-start">
            <i class="fas fa-check-circle mt-0.5 mr-2"></i>
            <span>Registration successful! You may now sign in with your credentials.</span>
        </div>
        <?php endif; ?>

        <!-- Error Message -->
        <?php if($error): ?>
        <div class="bg-rose-50 border border-rose-200 text-rose-600 px-4 py-3 rounded-lg mb-6 text-sm flex items-start">
            <i class="fas fa-exclamation-circle mt-0.5 mr-2"></i>
            <span><?php echo $error; ?></span>
        </div>
        <?php endif; ?>
        
        <!-- Login Form -->
        <form method="POST" action="" class="space-y-5">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Username</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                        <i class="fas fa-user"></i>
                    </div>
                    <input type="text" name="username" autocomplete="username" required 
                        class="w-full pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm transition">
                </div>
            </div>
            
            <div>
                <div class="flex flex-col md:flex-row md:items-center justify-between mb-1">
                    <label class="block text-sm font-medium text-slate-700">Password</label>
                </div>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                        <i class="fas fa-lock"></i>
                    </div>
                    <input type="password" name="password" id="login_password" autocomplete="current-password" required 
                        class="w-full pl-10 pr-10 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm transition">
                    <button type="button" onclick="togglePasswordVisibility('login_password', 'login_pass_icon')" tabindex="-1"
                        class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 focus:outline-none" title="Show/Hide Password">
                        <i id="login_pass_icon" class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            
            <button type="submit" 
                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-lg transition duration-150 shadow-sm">
                Sign In
            </button>
        </form>

        <!-- Footer -->
        <div class="mt-8 text-center text-sm text-slate-500">
            <div>
                Don't have an account? 
                <a href="register.php" class="text-blue-600 hover:text-blue-700 font-medium hover:underline">Register here</a>
            </div>
            <div class="mt-2">
                <a href="forgot_password.php" class="text-xs text-slate-500 hover:text-blue-600 font-medium hover:underline">Forgot password?</a>
            </div>
        </div>
    </div>


    <script>
        function togglePasswordVisibility(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (!input || !icon) return;
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        window.toggleSidebar = function() {
            const sidebar = document.getElementById('appSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if(sidebar) sidebar.classList.toggle('-translate-x-full');
            if(overlay) overlay.classList.toggle('hidden');
        }
    </script>
</body>
</html>
