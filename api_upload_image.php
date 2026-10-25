<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die(json_encode(['error' => 'Unauthorized']));
}

$target_dir = __DIR__ . "/uploads/images/";
if (!file_exists($target_dir)) {
    mkdir($target_dir, 0777, true);
}

if ($_FILES['file']['error'] === UPLOAD_ERR_OK) {
    $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
    if (!$ext) $ext = 'png';
    $new_filename = uniqid('img_') . '.' . $ext;
    $target_file = $target_dir . $new_filename;
    
    if (move_uploaded_file($_FILES['file']['tmp_name'], $target_file)) {
        echo json_encode(['location' => 'uploads/images/' . $new_filename]);
        exit;
    }
}

http_response_code(500);
echo json_encode(['error' => 'Upload failed']);
?>
