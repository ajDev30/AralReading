<?php
session_start();
require_once 'config.php';

$error = '';
$success = '';

$sections_by_grade = get_sections_by_grade($pdo);
$posted_grade = $_POST['grade_level'] ?? '';
$posted_section = $_POST['section'] ?? '';
$posted_gender = $_POST['gender'] ?? '';
$school_id = '301942';
$posted_lrn = preg_replace('/[^0-9]/', '', $_POST['lrn'] ?? '');
if (strpos($posted_lrn, $school_id) !== 0) {
    $posted_lrn = $school_id . substr(preg_replace('/^301942/', '', $posted_lrn), 0, 6);
} else {
    $posted_lrn = substr($posted_lrn, 0, 12);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $fname = trim($_POST['fname'] ?? '');
    $mname = trim($_POST['mname'] ?? '');
    $lname = trim($_POST['lname'] ?? '');
    $age = (int)($_POST['age'] ?? 0);
    $gender = trim($_POST['gender'] ?? '');
    $section = trim($_POST['section'] ?? '');
    $grade_level = trim($_POST['grade_level'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';
    $repass = $_POST['repassword'] ?? '';
    $role = 'student';
    $lrn = $posted_lrn;

    // Validation
    if ($age <= 0) {
        $error = "Age must be a positive number.";
    } elseif (!in_array($gender, ['Male', 'Female'], true)) {
        $error = "Please select a valid Gender.";
    } elseif (!preg_match('/^301942[0-9]{6}$/', $lrn)) {
        $error = "Invalid LRN. LRN must be 12 digits starting with 301942.";
    } elseif ($pass !== $repass) {
        $error = "Passwords do not match!";
    } else {
        $hashed_password = password_hash($pass, PASSWORD_BCRYPT);

        // Check for duplicate Email, Username, or LRN
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email OR username = :username OR lrn = :lrn");
        $stmt->execute(['email' => $email, 'username' => $username, 'lrn' => $lrn]);
        
        if ($stmt->rowCount() > 0) {
            $error = "Email, Username, or LRN already exists!";
        } else {
            $insert = $pdo->prepare("INSERT INTO users (fname, mname, lname, lrn, age, gender, section, grade_level, email, username, password, role) VALUES (:fname, :mname, :lname, :lrn, :age, :gender, :section, :grade_level, :email, :username, :password, :role)");
            $result = $insert->execute([
                'fname' => $fname,
                'mname' => $mname,
                'lname' => $lname,
                'lrn' => $lrn,
                'age' => $age,
                'gender' => $gender,
                'section' => $section,
                'grade_level' => $grade_level,
                'email' => $email,
                'username' => $username,
                'password' => $hashed_password,
                'role' => $role
            ]);

            if ($result) {
                header("Location: index.php?registered=1");
                exit;
            } else {
                $error = "Registration failed. Please try again.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="aral_logoindex.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - ARAL READING Program</title>
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
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0f7ff 0%, #f8fafc 50%, #eef2ff 100%);
        }
    </style>

    <script src="js/htmx.min.js"></script>
    <meta name="htmx-config" content='{"globalViewTransitions":true}'>
</head>
<body class="flex items-center justify-center min-h-screen py-10 px-4">

    <div class="w-full max-w-3xl bg-white rounded-2xl shadow-lg border border-slate-200/80 overflow-hidden">
        
        <!-- Top Accent Banner & Logo Header -->
        <div class="bg-gradient-to-b from-blue-50/70 to-white px-6 pt-8 pb-6 border-b border-slate-100 text-center">
            <img src="aral_logoindex.png" alt="ARAL Reading Program Logo" class="mx-auto h-36 mb-3 object-contain drop-shadow-sm">
            <h2 class="text-2xl md:text-3xl font-bold text-slate-800 tracking-tight">Create a Student Account</h2>
            <p class="text-sm text-slate-500 mt-1">Fill in your student details and DepEd Learner Reference Number (LRN) below</p>
        </div>

        <div class="p-6 md:p-8">
            <!-- Messages -->
            <?php if($error): ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-600 px-4 py-3 rounded-xl mb-6 text-sm flex items-start shadow-xs">
                <i class="fas fa-exclamation-circle mt-0.5 mr-2.5 text-base"></i>
                <span class="font-medium"><?php echo htmlspecialchars($error); ?></span>
            </div>
            <?php endif; ?>
            <?php if($success): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-xl mb-6 text-sm flex items-start shadow-xs">
                <i class="fas fa-check-circle mt-0.5 mr-2.5 text-base"></i>
                <span class="font-medium"><?php echo htmlspecialchars($success); ?></span>
            </div>
            <?php endif; ?>

            <!-- Registration Form -->
            <form method="POST" action="" class="space-y-6">
                
                <!-- SECTION 1: Personal Details -->
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-wider text-blue-600 mb-3 flex items-center gap-2">
                        <i class="fas fa-id-card"></i> Personal Information
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">First Name <span class="text-rose-500">*</span></label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 pointer-events-none"><i class="fas fa-user text-xs"></i></span>
                                <input type="text" name="fname" required value="<?php echo htmlspecialchars($_POST['fname'] ?? ''); ?>" placeholder="Juan"
                                    class="w-full pl-8 pr-3 py-2.5 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm transition">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Middle Name <span class="text-rose-500">*</span></label>
                            <input type="text" name="mname" required value="<?php echo htmlspecialchars($_POST['mname'] ?? ''); ?>" placeholder="Santos"
                                class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm transition">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Last Name <span class="text-rose-500">*</span></label>
                            <input type="text" name="lname" required value="<?php echo htmlspecialchars($_POST['lname'] ?? ''); ?>" placeholder="Dela Cruz"
                                class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm transition">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Age <span class="text-rose-500">*</span></label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 pointer-events-none"><i class="fas fa-birthday-cake text-xs"></i></span>
                                <input type="number" name="age" required min="1" max="99" value="<?php echo htmlspecialchars($_POST['age'] ?? ''); ?>" placeholder="e.g. 13"
                                    class="w-full pl-8 pr-3 py-2.5 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm transition">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Gender <span class="text-rose-500">*</span></label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 pointer-events-none"><i class="fas fa-venus-mars text-xs"></i></span>
                                <select name="gender" required
                                    class="w-full pl-8 pr-3 py-2.5 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm bg-white transition">
                                    <option value="" disabled <?php echo empty($posted_gender) ? 'selected' : ''; ?>>Select Gender</option>
                                    <option value="Male" <?php echo $posted_gender === 'Male' ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo $posted_gender === 'Female' ? 'selected' : ''; ?>>Female</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 2: Learner Reference Number (LRN) -->
                <div class="pt-4 border-t border-slate-100">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-blue-600 mb-3 flex items-center gap-2">
                        <i class="fas fa-graduation-cap"></i> Learner Reference Number (LRN)
                    </h3>

                    <div class="mb-4">
                        <label class="block text-xs font-semibold text-slate-700 mb-1">
                            LRN (12 Digits) <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 pointer-events-none"><i class="fas fa-id-badge text-xs"></i></span>
                            <input type="text" id="lrn" name="lrn" required maxlength="12" minlength="12" pattern="301942[0-9]{6}" inputmode="numeric"
                                value="<?php echo htmlspecialchars($posted_lrn ?: '301942'); ?>"
                                placeholder="301942XXXXXX"
                                oninput="enforceFixedLrnPrefix(this)"
                                onkeydown="preventPrefixDelete(event, this)"
                                onclick="ensureCursorAfterPrefix(this)"
                                onfocus="ensureCursorAfterPrefix(this)"
                                class="w-full pl-8 pr-3 py-2.5 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm font-mono tracking-wider bg-white transition">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Grade Level <span class="text-rose-500">*</span></label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 pointer-events-none"><i class="fas fa-layer-group text-xs"></i></span>
                                <select name="grade_level" id="grade_level" required onchange="updateSectionDropdown()"
                                    class="w-full pl-8 pr-3 py-2.5 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm bg-white transition">
                                    <option value="" disabled <?php echo empty($posted_grade) ? 'selected' : ''; ?>>Select Grade</option>
                                    <option value="Grade 7" <?php echo $posted_grade === 'Grade 7' ? 'selected' : ''; ?>>Grade 7</option>
                                    <option value="Grade 8" <?php echo $posted_grade === 'Grade 8' ? 'selected' : ''; ?>>Grade 8</option>
                                    <option value="Grade 9" <?php echo $posted_grade === 'Grade 9' ? 'selected' : ''; ?>>Grade 9</option>
                                    <option value="Grade 10" <?php echo $posted_grade === 'Grade 10' ? 'selected' : ''; ?>>Grade 10</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Section <span class="text-rose-500">*</span></label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 pointer-events-none"><i class="fas fa-users text-xs"></i></span>
                                <select name="section" id="section" required 
                                    class="w-full pl-8 pr-3 py-2.5 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm bg-white transition disabled:bg-slate-100 disabled:text-slate-400 disabled:cursor-not-allowed">
                                    <option value="">Select Grade Level First</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 3: Account Credentials -->
                <div class="pt-4 border-t border-slate-100">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-blue-600 mb-3 flex items-center gap-2">
                        <i class="fas fa-shield-alt"></i> Account Credentials
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Email Address <span class="text-rose-500">*</span></label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 pointer-events-none"><i class="fas fa-envelope text-xs"></i></span>
                                <input type="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" placeholder="student@email.com"
                                    class="w-full pl-8 pr-3 py-2.5 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm transition">
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Username <span class="text-rose-500">*</span></label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 pointer-events-none"><i class="fas fa-at text-xs"></i></span>
                                <input type="text" name="username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" placeholder="Choose a username"
                                    class="w-full pl-8 pr-3 py-2.5 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm transition">
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Password <span class="text-rose-500">*</span></label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 pointer-events-none"><i class="fas fa-lock text-xs"></i></span>
                                <input type="password" name="password" id="reg_password" autocomplete="new-password" required placeholder="Enter password"
                                    class="w-full pl-8 pr-10 py-2.5 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm transition">
                                <button type="button" onclick="togglePasswordVisibility('reg_password', 'reg_pass_icon')" tabindex="-1"
                                    class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 focus:outline-none" title="Show/Hide Password">
                                    <i id="reg_pass_icon" class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Re-type Password <span class="text-rose-500">*</span></label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 pointer-events-none"><i class="fas fa-lock text-xs"></i></span>
                                <input type="password" name="repassword" id="reg_repassword" autocomplete="new-password" required placeholder="Confirm password"
                                    class="w-full pl-8 pr-10 py-2.5 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm transition">
                                <button type="button" onclick="togglePasswordVisibility('reg_repassword', 'reg_repass_icon')" tabindex="-1"
                                    class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 focus:outline-none" title="Show/Hide Password">
                                    <i id="reg_repass_icon" class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="pt-4 border-t border-slate-100">
                    <button type="submit" 
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-xl transition duration-150 shadow-sm flex items-center justify-center gap-2">
                        <i class="fas fa-user-plus text-sm"></i> Create Student Account
                    </button>
                </div>
            </form>

            <!-- Footer -->
            <div class="mt-6 text-center text-sm text-slate-500">
                Already have an account? 
                <a href="index.php" class="text-blue-600 hover:text-blue-700 font-semibold hover:underline">Sign in here</a>
            </div>
        </div>
    </div>

    <script>
        const sectionsByGrade = <?php echo json_encode($sections_by_grade, JSON_UNESCAPED_UNICODE); ?>;
        const initialSelectedSection = <?php echo json_encode($posted_section); ?>;

        const fixedPrefix = '301942';

        function enforceFixedLrnPrefix(input) {
            let digits = input.value.replace(/[^0-9]/g, '');
            if (digits.startsWith(fixedPrefix)) {
                digits = digits.slice(fixedPrefix.length);
            } else {
                // Strip any partial prefix remnants if user pasted or edited
                digits = digits.replace(/^3?0?1?9?4?2?/, '');
            }
            input.value = fixedPrefix + digits.slice(0, 6);
        }

        function preventPrefixDelete(e, input) {
            const start = input.selectionStart || 0;
            const end = input.selectionEnd || 0;
            if ((e.key === 'Backspace' && start <= 6 && end <= 6) ||
                (e.key === 'Delete' && start < 6)) {
                e.preventDefault();
                ensureCursorAfterPrefix(input);
            } else if (start < 6 && e.key.length === 1 && !e.ctrlKey && !e.metaKey) {
                if (end <= 6) {
                    input.setSelectionRange(input.value.length, input.value.length);
                }
            }
        }

        function ensureCursorAfterPrefix(input) {
            setTimeout(function() {
                if ((input.selectionStart || 0) < 6) {
                    input.setSelectionRange(input.value.length, input.value.length);
                }
            }, 0);
        }

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

        function updateSectionDropdown() {
            const gradeSelect = document.getElementById('grade_level');
            const secSelect = document.getElementById('section');
            const selectedGrade = gradeSelect.value;

            secSelect.innerHTML = '';

            if (!selectedGrade || !sectionsByGrade[selectedGrade]) {
                const opt = document.createElement('option');
                opt.value = '';
                opt.textContent = 'Select Grade Level First';
                secSelect.appendChild(opt);
                secSelect.disabled = true;
                return;
            }

            const sections = sectionsByGrade[selectedGrade] || [];
            secSelect.disabled = false;

            if (sections.length === 0) {
                const opt = document.createElement('option');
                opt.value = '';
                opt.textContent = 'No sections available for ' + selectedGrade;
                secSelect.appendChild(opt);
                return;
            }

            const defaultOpt = document.createElement('option');
            defaultOpt.value = '';
            defaultOpt.textContent = 'Select Section (' + selectedGrade + ')';
            secSelect.appendChild(defaultOpt);

            sections.forEach(function(sec) {
                const opt = document.createElement('option');
                opt.value = sec;
                opt.textContent = sec;
                if (initialSelectedSection && initialSelectedSection === sec) {
                    opt.selected = true;
                }
                secSelect.appendChild(opt);
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('grade_level').value) {
                updateSectionDropdown();
            } else {
                document.getElementById('section').disabled = true;
            }
        });
    </script>
</body>
</html>
