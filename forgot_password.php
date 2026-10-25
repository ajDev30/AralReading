<?php
session_start();
require_once 'config.php';
require_once 'SimpleSMTP.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');

    if (empty($identifier)) {
        $error = 'Please enter your registered email or username.';
    } else {
        // Find user by email or username
        $stmt = $pdo->prepare("SELECT id, fname, lname, email, username FROM users WHERE email = :id OR username = :id LIMIT 1");
        $stmt->execute(['id' => $identifier]);
        $user = $stmt->fetch();

        // Always show the same neutral message to prevent user enumeration attacks
        $message = "If an account matching that username or email exists, a password reset link has been sent to the associated email address.";

        if ($user && !empty($user['email'])) {
            // Generate secure 32-byte random token
            $token = bin2hex(random_bytes(32));

            // Store in DB using MySQL DATE_ADD(NOW(), INTERVAL 30 MINUTE) to match DB timezone exactly
            $upd = $pdo->prepare("UPDATE users SET reset_token = :token, reset_expires = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = :uid");
            $upd->execute([
                'token'   => $token,
                'uid'     => $user['id']
            ]);

            // Fetch SMTP settings
            $sStmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%'");
            $smtpSettings = [];
            while ($row = $sStmt->fetch()) {
                $smtpSettings[$row['setting_key']] = $row['setting_value'];
            }

            // Determine host URL for reset link
            $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
            $resetUrl = "{$scheme}://{$httpHost}{$dir}/reset_password.php?token={$token}";

            $subject = "Password Reset Request - ARAL Reading Assessment";
            $name = htmlspecialchars($user['fname'] . ' ' . $user['lname']);
            
            $htmlBody = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; background-color: #ffffff;'>
                <div style='text-align: center; margin-bottom: 20px;'>
                    <h2 style='color: #1e3a8a; margin: 0;'>ARAL Reading Assessment</h2>
                    <p style='color: #64748b; font-size: 14px;'>Password Reset Request</p>
                </div>
                <p>Hello <strong>{$name}</strong>,</p>
                <p>We received a request to reset your password for your ARAL Reading account. Click the button below to choose a new password:</p>
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='{$resetUrl}' style='display: inline-block; background-color: #2563eb; color: #ffffff; text-decoration: none; padding: 12px 28px; font-weight: bold; border-radius: 6px; font-size: 16px;'>Reset My Password</a>
                </div>
                <p style='font-size: 13px; color: #64748b;'>This link will expire in <strong>30 minutes</strong>.</p>
                <p style='font-size: 12px; color: #94a3b8; word-break: break-all;'>If the button doesn't work, copy and paste this link into your browser:<br><a href='{$resetUrl}'>{$resetUrl}</a></p>
                <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 25px 0;'>
                <p style='font-size: 12px; color: #94a3b8;'>If you did not request a password reset, you can safely ignore this email. Your password will remain unchanged.</p>
            </div>
            ";

            $sendResult = SimpleSMTP::send($user['email'], $subject, $htmlBody, $smtpSettings);
            
            // If SMTP is not yet configured, provide a helpful development notice
            if (!$sendResult['success']) {
                if (empty($smtpSettings['smtp_host'])) {
                    $error = "Notice: SMTP is not yet configured in Settings. (Dev reset link: <a href='{$resetUrl}' class='underline font-bold'>Click here to reset</a>)";
                    $message = '';
                } else {
                    $error = "Mail delivery notice: " . htmlspecialchars($sendResult['error']);
                    $message = '';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - ARAL Reading</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-50 flex items-center justify-center min-h-screen p-4">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-8 max-w-md w-full">
        <div class="text-center mb-6">
            <div class="inline-flex items-center justify-center w-12 h-12 bg-blue-50 text-blue-600 rounded-full mb-3">
                <i class="fas fa-key text-xl"></i>
            </div>
            <h1 class="text-2xl font-bold text-slate-800">Forgot Password</h1>
            <p class="text-sm text-slate-500 mt-1">Enter your email or username and we'll send you a link to reset your password.</p>
        </div>

        <?php if($message): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-lg mb-6 text-sm flex items-start">
                <i class="fas fa-check-circle mt-0.5 mr-2 shrink-0"></i>
                <span><?php echo $message; ?></span>
            </div>
        <?php endif; ?>

        <?php if($error): ?>
            <div class="bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 rounded-lg mb-6 text-sm flex items-start">
                <i class="fas fa-info-circle mt-0.5 mr-2 shrink-0"></i>
                <div><?php echo $error; ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" action="" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Username or Email</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <input type="text" name="identifier" required placeholder="e.g. learner@gmail.com or username"
                        class="w-full pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm transition">
                </div>
            </div>

            <button type="submit" 
                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-lg transition duration-150 shadow-sm flex items-center justify-center gap-2">
                <i class="fas fa-paper-plane text-xs"></i> Send Reset Link
            </button>
        </form>

        <div class="mt-6 text-center text-sm text-slate-500">
            <a href="index.php" class="text-blue-600 hover:underline font-medium flex items-center justify-center gap-1">
                <i class="fas fa-arrow-left text-xs"></i> Back to Sign In
            </a>
        </div>
    </div>
</body>
</html>
