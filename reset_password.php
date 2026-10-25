<?php
session_start();
require_once 'config.php';

$token = trim($_GET['token'] ?? '');
$error = '';
$success = '';
$validUser = null;

if (empty($token)) {
    $error = 'No reset token provided. Please request a new password reset link.';
} else {
    // Validate token and expiry
    $stmt = $pdo->prepare("SELECT id, fname, lname, username FROM users WHERE reset_token = :token AND reset_expires > NOW() LIMIT 1");
    $stmt->execute(['token' => $token]);
    $validUser = $stmt->fetch();

    if (!$validUser) {
        $error = 'This password reset link is invalid or has expired. Please request a new one.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validUser) {
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    if (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($password !== $password_confirm) {
        $error = 'Passwords do not match. Please re-enter.';
    } else {
        $hashed = password_hash($password, PASSWORD_BCRYPT);
        
        // Update password and invalidate token
        $upd = $pdo->prepare("UPDATE users SET password = :pass, reset_token = NULL, reset_expires = NULL WHERE id = :uid");
        $upd->execute([
            'pass' => $hashed,
            'uid'  => $validUser['id']
        ]);

        $success = 'Your password has been successfully reset! You can now log in with your new password.';
        $validUser = null; // Form shouldn't be submitted again
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - ARAL Reading</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-50 flex items-center justify-center min-h-screen p-4">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-8 max-w-md w-full">
        <div class="text-center mb-6">
            <div class="inline-flex items-center justify-center w-12 h-12 bg-blue-50 text-blue-600 rounded-full mb-3">
                <i class="fas fa-lock-open text-xl"></i>
            </div>
            <h1 class="text-2xl font-bold text-slate-800">Set New Password</h1>
            <p class="text-sm text-slate-500 mt-1">Please enter your new password below.</p>
        </div>

        <?php if($success): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-lg mb-6 text-sm flex items-start">
                <i class="fas fa-check-circle mt-0.5 mr-2 shrink-0"></i>
                <div>
                    <p class="font-bold"><?php echo $success; ?></p>
                    <a href="index.php" class="inline-block mt-3 bg-emerald-600 hover:bg-emerald-700 text-white font-medium px-4 py-2 rounded-lg text-xs transition">
                        Proceed to Sign In
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <?php if($error): ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-600 px-4 py-3 rounded-lg mb-6 text-sm flex items-start">
                <i class="fas fa-exclamation-circle mt-0.5 mr-2 shrink-0"></i>
                <div>
                    <span><?php echo $error; ?></span>
                    <?php if(!$validUser && !$success): ?>
                        <div class="mt-2">
                            <a href="forgot_password.php" class="text-xs font-bold underline">Request a new reset link</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if($validUser): ?>
            <form method="POST" action="" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">New Password</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                            <i class="fas fa-lock"></i>
                        </div>
                        <input type="password" name="password" required minlength="6" placeholder="At least 6 characters"
                            class="w-full pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm transition">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Confirm New Password</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <input type="password" name="password_confirm" required minlength="6" placeholder="Re-type new password"
                            class="w-full pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm transition">
                    </div>
                </div>

                <button type="submit" 
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-lg transition duration-150 shadow-sm flex items-center justify-center gap-2">
                    <i class="fas fa-check text-xs"></i> Update Password
                </button>
            </form>
        <?php endif; ?>

        <div class="mt-6 text-center text-sm text-slate-500">
            <a href="index.php" class="text-blue-600 hover:underline font-medium flex items-center justify-center gap-1">
                <i class="fas fa-arrow-left text-xs"></i> Back to Sign In
            </a>
        </div>
    </div>
</body>
</html>
