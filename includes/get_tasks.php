<?php
require_once '../config/database.php';
session_start();

header('Content-Type: application/json');

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0); // Disable direct output of errors

// Debug logging
error_log("Starting get_tasks.php");

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    error_log("User not logged in");
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit;
}

$user_id = $_SESSION['user_id'];
$project_id = filter_var($_GET['id'] ?? '', FILTER_SANITIZE_NUMBER_INT);

error_log("User ID: " . $user_id . ", Project ID: " . $project_id);

if (!$project_id) {
    error_log("Invalid project ID");
    echo json_encode(['status' => 'error', 'message' => 'Invalid project ID']);
    exit;
}

try {
    // Check if user has access to this project
    $access_check = $conn->prepare("
        SELECT 1 
        FROM projects p
        WHERE p.project_id = ? 
        AND (p.user_id = ? OR EXISTS (
            SELECT 1 FROM project_members 
            WHERE project_id = p.project_id 
            AND user_id = ?
        ))
    ");

    if (!$access_check) {
        throw new Exception("Failed to prepare access check query: " . $conn->error);
    }

    $access_check->bind_param("iii", $project_id, $user_id, $user_id);
    if (!$access_check->execute()) {
        throw new Exception("Failed to execute access check: " . $access_check->error);
    }

    $access_result = $access_check->get_result();
    error_log("Access check rows: " . $access_result->num_rows);

    if ($access_result->num_rows === 0) {
        error_log("Access denied for user " . $user_id . " to project " . $project_id);
        echo json_encode(['status' => 'error', 'message' => 'Access denied']);
        exit;
    }

    // Get tasks with assignee names
    $tasks_query = $conn->prepare("
        SELECT t.*, GROUP_CONCAT(u.full_name) as assignee, GROUP_CONCAT(u.user_id) as assignee_ids
        FROM tasks t
        LEFT JOIN task_assignees ta ON t.task_id = ta.task_id
        LEFT JOIN users u ON ta.user_id = u.user_id
        WHERE t.project_id = ?
        GROUP BY t.task_id
        ORDER BY 
            CASE t.priority
                WHEN 'High' THEN 1
                WHEN 'Medium' THEN 2
                WHEN 'Low' THEN 3
            END,
            t.due_date ASC
    ");

    if (!$tasks_query) {
        throw new Exception("Failed to prepare tasks query: " . $conn->error);
    }

    $tasks_query->bind_param("i", $project_id);
    if (!$tasks_query->execute()) {
        throw new Exception("Failed to execute tasks query: " . $tasks_query->error);
    }

    $result = $tasks_query->get_result();
    error_log("Found " . $result->num_rows . " tasks for project " . $project_id);

    $tasks = [];
    while ($task = $result->fetch_assoc()) {
        error_log("Processing task: " . json_encode($task));
        $tasks[] = [
            'id' => $task['task_id'],
            'title' => $task['title'],
            'description' => $task['description'],
            'status' => $task['status'],
            'priority' => $task['priority'],
            'due_date' => $task['created_at'],
            'assignee' => $task['assignee'] ?? '-',
            'assignee_id' => $task['assignee_ids']
        ];
    }

    $response = [
        'status' => 'success',
        'tasks' => $tasks
    ];

    error_log("Sending response: " . json_encode($response));
    echo json_encode($response);

} catch (Exception $e) {
    error_log("Error in get_tasks.php: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?> 