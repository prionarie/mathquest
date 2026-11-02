<?php
session_start();
require '../../../config/dbcon.php';

header('Content-Type: application/json');

if (!isset($_SESSION['teacher_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$material_id = $_GET['id'] ?? 0;
$teacher_id = $_SESSION['teacher_id'];

$stmt = $conn->prepare("SELECT file_path FROM learning_materials WHERE material_id = ? AND teacher_id = ?");
$stmt->bind_param("ii", $material_id, $teacher_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $material = $result->fetch_assoc();
 
    if ($material['file_path'] && file_exists($material['file_path'])) {
        unlink($material['file_path']);
    }

    $delete_stmt = $conn->prepare("DELETE FROM learning_materials WHERE material_id = ? AND teacher_id = ?");
    $delete_stmt->bind_param("ii", $material_id, $teacher_id);
    
    if ($delete_stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Material not found or unauthorized']);
}
?>