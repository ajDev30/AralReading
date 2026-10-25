<div id="sidebarOverlay" class="fixed inset-0 bg-slate-900/50 z-40 hidden md:hidden" onclick="toggleSidebar()"></div>
<aside id="appSidebar" class="sidebar w-64 fixed inset-y-0 left-0 z-50 transform -translate-x-full md:relative md:translate-x-0 transition-transform duration-300 ease-in-out text-white flex flex-col shrink-0">
    <div class="h-16 flex items-center px-6 border-b border-white/10">
        <img src="https://tibungcodistrict.wordpress.com/wp-content/uploads/2018/06/untitled-1.png?w=490" class="h-8 object-contain mr-3 bg-white rounded p-1">
        <div>
            <h1 class="font-bold text-lg leading-tight">Phil-IRI</h1>
            <p class="text-[10px] text-blue-200 uppercase tracking-wider">Teacher Portal</p>
        </div>
    </div>
    <nav class="flex-1 py-4 overflow-y-auto">
        <ul class="space-y-1">
            <li><a href="dashboard_teacher.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard_teacher.php' ? 'flex items-center px-6 py-3 bg-blue-600 text-white font-medium border-l-4 border-white' : 'flex items-center px-6 py-3 text-blue-100 hover:bg-blue-800 transition'; ?>"><i class="fas fa-border-all w-6"></i> Dashboard</a></li>
            <li><a href="teacher_user_list.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'teacher_user_list.php' ? 'flex items-center px-6 py-3 bg-blue-600 text-white font-medium border-l-4 border-white' : 'flex items-center px-6 py-3 text-blue-100 hover:bg-blue-800 transition'; ?>"><i class="fas fa-users-cog w-6"></i> User List</a></li>
            <li><a href="manage_preassessment.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_preassessment.php' ? 'flex items-center px-6 py-3 bg-blue-600 text-white font-medium border-l-4 border-white' : 'flex items-center px-6 py-3 text-blue-100 hover:bg-blue-800 transition'; ?>"><i class="fas fa-book w-6"></i> Graded Reading Passages</a></li>
            <li><a href="manage_gst.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_gst.php' ? 'flex items-center px-6 py-3 bg-blue-600 text-white font-medium border-l-4 border-white' : 'flex items-center px-6 py-3 text-blue-100 hover:bg-blue-800 transition'; ?>"><i class="fas fa-file-alt w-6"></i> Manage GST</a></li>
            <li><a href="assessment_result.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'assessment_result.php' ? 'flex items-center px-6 py-3 bg-blue-600 text-white font-medium border-l-4 border-white' : 'flex items-center px-6 py-3 text-blue-100 hover:bg-blue-800 transition'; ?>"><i class="fas fa-microphone-alt w-6"></i> Assessment Results</a></li>
            <li><a href="course.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'course.php' ? 'flex items-center px-6 py-3 bg-blue-600 text-white font-medium border-l-4 border-white' : 'flex items-center px-6 py-3 text-blue-100 hover:bg-blue-800 transition'; ?>"><i class="fas fa-book-reader w-6"></i> Courses (Pre/Post)</a></li>
            <li><a href="teacher_course_grades.php" class="<?php echo in_array(basename($_SERVER['PHP_SELF']), ['teacher_course_grades.php', 'teacher_student_grades.php']) ? 'flex items-center px-6 py-3 bg-blue-600 text-white font-medium border-l-4 border-white' : 'flex items-center px-6 py-3 text-blue-100 hover:bg-blue-800 transition'; ?>"><i class="fas fa-graduation-cap w-6"></i> Course Grades</a></li>
            <li><a href="student_progress_list.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'student_progress_list.php' ? 'flex items-center px-6 py-3 bg-blue-600 text-white font-medium border-l-4 border-white' : 'flex items-center px-6 py-3 text-blue-100 hover:bg-blue-800 transition'; ?>"><i class="fas fa-chart-line w-6"></i> Student Progress</a></li>
            <li class="mt-8"><a href="teacher_profile.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'teacher_profile.php' ? 'flex items-center px-6 py-3 bg-blue-600 text-white font-medium border-l-4 border-white' : 'flex items-center px-6 py-3 text-blue-100 hover:bg-blue-800 transition'; ?>"><i class="fas fa-user-circle w-6"></i> Profile</a></li>
            <li><a href="teacher_settings.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'teacher_settings.php' ? 'flex items-center px-6 py-3 bg-blue-600 text-white font-medium border-l-4 border-white' : 'flex items-center px-6 py-3 text-blue-100 hover:bg-blue-800 transition'; ?>"><i class="fas fa-cog w-6"></i> Settings</a></li>
            <li><a href="logout.php" hx-boost="false" class="flex items-center px-6 py-3 text-red-300 hover:bg-blue-800 transition"><i class="fas fa-sign-out-alt w-6"></i> Logout</a></li>
        </ul>
    </nav>
</aside>
