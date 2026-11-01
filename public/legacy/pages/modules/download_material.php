<?php
session_start();
require '../../../config/dbcon.php';

if (!isset($_SESSION['teacher_id'])) {
    header("Location: ../login.php");
    exit();
}

$material_id = $_GET['id'] ?? 0;
$teacher_id = $_SESSION['teacher_id'];

error_log("Download request - Material ID: $material_id, Teacher ID: $teacher_id");


$stmt = $conn->prepare("SELECT file_path, title FROM learning_materials WHERE material_id = ? AND teacher_id = ?");
$stmt->bind_param("ii", $material_id, $teacher_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $material = $result->fetch_assoc();
    
    error_log("Material found - Title: " . $material['title']);
    error_log("File path in DB: " . $material['file_path']);
    
    if (!empty($material['file_path'])) {
        $filepath = $material['file_path'];

        if (file_exists($filepath)) {
            error_log("File found at path: $filepath");
            serveFile($filepath, $material['title']);
            exit;
        }

        $absolute_path = $_SERVER['DOCUMENT_ROOT'] . $filepath;
        if (file_exists($absolute_path)) {
            error_log("File found at absolute path: $absolute_path");
            serveFile($absolute_path, $material['title']);
            exit;
        }
        
        $filename = basename($filepath);
        $uploads_path = $_SERVER['DOCUMENT_ROOT'] . '/mathquest/assets/uploads/learning_materials/' . $filename;
        if (file_exists($uploads_path)) {
            error_log("File found in uploads directory: $uploads_path");
            serveFile($uploads_path, $material['title']);
            exit;
        }
        
        error_log("File not found at any path");
    } else {
        error_log("No file path in database record");
    }
} else {
    error_log("Material not found or unauthorized");
}

header("Location: ../modules.php?error=file_not_found&id=" . urlencode($material_id));
exit();

function serveFile($filepath, $title) {
    $filename = basename($filepath);
    $extension = pathinfo($filename, PATHINFO_EXTENSION);

    $safe_title = preg_replace('/[^a-zA-Z0-9\.\-]/', '_', $title);
    $download_filename = $safe_title . '.' . $extension;

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $download_filename . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . filesize($filepath));
    
    ob_clean();
    flush();
    
    readfile($filepath);
    exit;
}
?>