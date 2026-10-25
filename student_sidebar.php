<?php
if (!isset($level)) {
    if (isset($overall_profile)) {
        $level = $overall_profile;
    } elseif (isset($_SESSION['user_id']) && isset($pdo) && function_exists('get_student_reading_level')) {
        $level = get_student_reading_level($pdo, $_SESSION['user_id'], $user ?? null);
    } elseif (isset($user['current_level'])) {
        $level = $user['current_level'];
    } else {
        $level = 'Instructional';
    }
}
?>
<div id="sidebarOverlay" class="fixed inset-0 bg-slate-900/50 z-40 hidden md:hidden" onclick="toggleSidebar()"></div>
<aside id="appSidebar" class="sidebar keep-colors w-64 text-white flex flex-col shrink-0 fixed inset-y-0 left-0 z-50 transform -translate-x-full md:relative md:translate-x-0 transition-transform duration-300 ease-in-out">
    <div class="h-20 flex items-center px-6 border-b border-white/10 shrink-0">
        <img src="https://tibungcodistrict.wordpress.com/wp-content/uploads/2018/06/untitled-1.png?w=490" class="h-10 object-contain mr-3 bg-white rounded p-1">
        <div>
            <h1 class="font-bold text-xl leading-tight">Phil-IRI</h1>
            <p class="text-xs text-blue-200">Reading Assessment</p>
        </div>
    </div>
    
    <nav class="flex-1 mt-4" hx-boost="true">
        <ul class="space-y-1">
            <?php if(($level ?? '') !== 'Pending'): ?>
                <li><a href="dashboard_student.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard_student.php' ? 'flex items-center px-6 py-3 bg-blue-600 border-l-4 border-white' : 'flex items-center px-6 py-3 text-blue-100 hover:bg-blue-800 transition'; ?>"><i class="fas fa-home w-6"></i> Dashboard</a></li>
                
                <?php if(!in_array($level ?? '', ['Independent', 'Non-Reader'])): ?>
                    <li><a href="student_course.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'student_course.php' ? 'flex items-center px-6 py-3 bg-blue-600 border-l-4 border-white' : 'flex items-center px-6 py-3 text-blue-100 hover:bg-blue-800 transition'; ?>"><i class="fas fa-book-reader w-6"></i> Materials</a></li>
                <?php endif; ?>

                <?php if(($level ?? '') !== 'Non-Reader'): ?>
                    <li><a href="student_grades.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'student_grades.php' ? 'flex items-center px-6 py-3 bg-blue-600 border-l-4 border-white' : 'flex items-center px-6 py-3 text-blue-100 hover:bg-blue-800 transition'; ?>"><i class="fas fa-award w-6"></i> Grades</a></li>
                <?php endif; ?>

                <li><a href="student_progress.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'student_progress.php' ? 'flex items-center px-6 py-3 bg-blue-600 border-l-4 border-white' : 'flex items-center px-6 py-3 text-blue-100 hover:bg-blue-800 transition'; ?>"><i class="fas fa-chart-line w-6"></i> Progress</a></li>
            <?php endif; ?>
            
            <li class="<?php echo (($level ?? '') !== 'Pending') ? 'mt-8' : ''; ?>"><a href="student_settings.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'student_settings.php' ? 'flex items-center px-6 py-3 bg-blue-600 border-l-4 border-white' : 'flex items-center px-6 py-3 text-blue-100 hover:bg-blue-800 transition'; ?>"><i class="fas fa-cog w-6"></i> Settings</a></li>
            
            <li><a href="logout.php" hx-boost="false" class="flex items-center px-6 py-3 text-red-300 hover:bg-blue-800 transition"><i class="fas fa-sign-out-alt w-6"></i> Logout</a></li>
        </ul>
    </nav>
    
    <div class="absolute bottom-0 left-0 w-full p-6 opacity-20 pointer-events-none shrink-0">
        <svg viewBox="0 0 100 100" class="w-full h-auto fill-current"><path d="M0,50 Q25,25 50,50 T100,50 L100,100 L0,100 Z"></path></svg>
    </div>
    <div class="absolute bottom-8 left-6 text-xs text-blue-200 opacity-50 italic shrink-0">"Better reading builds a brighter future."</div>
</aside>
