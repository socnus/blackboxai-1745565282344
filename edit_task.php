<?php
require_once 'includes/init.php';
require_once 'includes/task_helpers.php';

// Ensure user is admin
if (!isLoggedIn() || !isAdmin(getCurrentUserId())) {
    $_SESSION['flash_message'] = 'Access denied. Admin privileges required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: tasks.php');
    exit;
}

// Get task ID
$taskId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$taskId) {
    $_SESSION['flash_message'] = 'Invalid task ID.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: tasks.php');
    exit;
}

// Get task details
$stmt = $pdo->prepare("
    SELECT t.*, 
           tc.name as category_name,
           tc.color as category_color,
           u.username as creator_name,
           GROUP_CONCAT(DISTINCT ta.user_id) as assigned_users
    FROM tasks t
    LEFT JOIN task_categories tc ON t.category_id = tc.id
    LEFT JOIN users u ON t.user_id = u.id
    LEFT JOIN task_assignments ta ON t.id = ta.task_id
    WHERE t.id = ?
    GROUP BY t.id
");
$stmt->execute([$taskId]);
$task = $stmt->fetch();

if (!$task) {
    // Debug information
    error_log("Task not found. ID: $taskId");
    error_log("SQL Error: " . print_r($pdo->errorInfo(), true));
    
    $_SESSION['flash_message'] = 'Task not found. Please make sure you have run database_updates_v3.sql and database_updates_v4.sql';
    $_SESSION['flash_type'] = 'danger';
    header('Location: tasks.php');
    exit;
}

// Debug information
error_log("Task found: " . print_r($task, true));

// Get all users for assignment
$stmt = $pdo->query("SELECT id, username FROM users ORDER BY username");
$users = $stmt->fetchAll();

// Get all categories
$stmt = $pdo->query("SELECT * FROM task_categories ORDER BY name");
$categories = $stmt->fetchAll();

// Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
        $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
        $priority = filter_input(INPUT_POST, 'priority', FILTER_SANITIZE_STRING);
        $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
        $categoryId = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
        $dueDate = filter_input(INPUT_POST, 'due_date', FILTER_SANITIZE_STRING);
        $assignees = $_POST['assignees'] ?? [];

        // Calculate due date if not provided
        if (empty($dueDate)) {
            // Use created_at date from existing task
            $createdAt = $task['created_at'] ?? date('Y-m-d');
            $workingDays = getWorkingDaysByPriority($priority);
            $dueDate = addWorkingDays(date('Y-m-d', strtotime($createdAt)), $workingDays);
        }

    if (empty($title) || empty($description) || empty($assignees)) {
        $_SESSION['flash_message'] = 'Please fill in all required fields.';
        $_SESSION['flash_type'] = 'danger';
    } else {
        try {
            $pdo->beginTransaction();

            // Update task
            $stmt = $pdo->prepare("
                UPDATE tasks 
                SET category_id = ?, 
                    title = ?, 
                    description = ?, 
                    priority = ?,
                    status = ?,
                    due_date = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $categoryId,
                $title,
                $description,
                $priority,
                $status,
                $dueDate ?: null,
                $taskId
            ]);

            // Update assignments
            $stmt = $pdo->prepare("DELETE FROM task_assignments WHERE task_id = ?");
            $stmt->execute([$taskId]);

            $stmt = $pdo->prepare("
                INSERT INTO task_assignments (task_id, user_id)
                VALUES (?, ?)
            ");
            foreach ($assignees as $userId) {
                $stmt->execute([$taskId, $userId]);
            }

            // Log activity
            logActivity(getCurrentUserId(), 'edit_task', "Updated task: $title");

            $pdo->commit();
            
            $_SESSION['flash_message'] = 'Task updated successfully.';
            $_SESSION['flash_type'] = 'success';
            header("Location: view_task.php?id=$taskId");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash_message'] = 'Failed to update task.';
            $_SESSION['flash_type'] = 'danger';
        }
    }
}

require_once 'includes/header.php';
?>

<div class="container py-5">
    <div class="col mb-4">
        <nav aria-label="breadcrumb" class="btn btn-outline-secondary w-100 p-3">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="index.php"><?php echo $translations[$currentLang]['nav_home']; ?></a></li>
                <li class="breadcrumb-item"><a href="tasks.php"><?php echo $translations[$currentLang]['tasks']; ?></a></li>
                <li class="breadcrumb-item"><a href="view_task.php?id=<?php echo $taskId; ?>"><?php echo h($task['title']); ?></a></li>
                <li class="breadcrumb-item"><?php echo $translations[$currentLang]['edit_task']; ?></li>
            </ol>
        </nav>
    </div>
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h1 class="h4 mb-0">
                        <i class="fas fa-edit me-2"></i><?php echo $translations[$currentLang]['edit_task']; ?>
                    </h1>
                </div>
                <div class="card-body">
                    <form method="POST" class="needs-validation" novalidate>
                        <div class="mb-3">
                            <label for="title" class="form-label"><?php echo $translations[$currentLang]['title']; ?></label>
                            <input type="text" 
                                   class="form-control" 
                                   id="title" 
                                   name="title" 
                                   value="<?php echo h($task['title']); ?>"
                                   required>
                            <div class="invalid-feedback">
                                Please provide a title.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label"><?php echo $translations[$currentLang]['description']; ?></label>
                            <textarea class="form-control" 
                                      id="description" 
                                      name="description" 
                                      rows="5" 
                                      required><?php echo h($task['description']); ?></textarea>
                            <div class="invalid-feedback">
                                Please provide a description.
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="category_id" class="form-label"><?php echo $translations[$currentLang]['category']; ?></label>
                                <select class="form-select" id="category_id" name="category_id" required>
                                    <option value=""><?php echo $translations[$currentLang]['select_category']; ?></option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>"
                                                <?php echo $category['id'] == $task['category_id'] ? 'selected' : ''; ?>>
                                            <?php echo h($category['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">
                                    Please select a category.
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="priority" class="form-label"><?php echo $translations[$currentLang]['priority']; ?></label>
                                <select class="form-select" id="priority" name="priority" required>
                                    <option value="low" <?php echo $task['priority'] === 'low' ? 'selected' : ''; ?>><?php echo $translations[$currentLang]['low']; ?></option>
                                    <option value="medium" <?php echo $task['priority'] === 'medium' ? 'selected' : ''; ?>><?php echo $translations[$currentLang]['medium']; ?></option>
                                    <option value="high" <?php echo $task['priority'] === 'high' ? 'selected' : ''; ?>><?php echo $translations[$currentLang]['high']; ?></option>
                                    <option value="urgent" <?php echo $task['priority'] === 'urgent' ? 'selected' : ''; ?>><?php echo $translations[$currentLang]['urgent']; ?></option>
                                </select>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="status" class="form-label"><?php echo $translations[$currentLang]['status']; ?></label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="pending" <?php echo $task['status'] === 'pending' ? 'selected' : ''; ?>><?php echo $translations[$currentLang]['pending']; ?></option>
                                    <option value="in_progress" <?php echo $task['status'] === 'in_progress' ? 'selected' : ''; ?>><?php echo $translations[$currentLang]['in_progress']; ?></option>
                                    <option value="completed" <?php echo $task['status'] === 'completed' ? 'selected' : ''; ?>><?php echo $translations[$currentLang]['completed']; ?></option>
                                    <option value="cancelled" <?php echo $task['status'] === 'cancelled' ? 'selected' : ''; ?>><?php echo $translations[$currentLang]['cancelled']; ?></option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="due_date" class="form-label"><?php echo $translations[$currentLang]['due_date']; ?></label>
                                <input type="date" 
                                       class="form-control" 
                                       id="due_date" 
                                       name="due_date"
                                       value="<?php echo $task['due_date'] ? date('Y-m-d', strtotime($task['due_date'])) : ''; ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="assignees" class="form-label"><?php echo $translations[$currentLang]['assign_to']; ?></label>
                            <select class="form-select" id="assignees" name="assignees[]" multiple required>
                                <?php 
                                $assignedUsers = $task['assigned_users'] ? explode(',', $task['assigned_users']) : [];
                                foreach ($users as $user): 
                                ?>
                                    <option value="<?php echo $user['id']; ?>"
                                            <?php echo in_array($user['id'], $assignedUsers) ? 'selected' : ''; ?>>
                                        <?php echo h($user['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">
                            <?php echo $translations[$currentLang]['required_fields']; ?>
                            </div>
                            <div class="form-text">
                            <?php echo $translations[$currentLang]['select_multiple']; ?>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="view_task.php?id=<?php echo $taskId; ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i><?php echo $translations[$currentLang]['back_to_task']; ?>
                            </a>
                            <div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i><?php echo $translations[$currentLang]['save_changes']; ?>
                                </button>
                                <a href="delete_task.php?id=<?php echo $taskId; ?>" 
                                   class="btn btn-danger"
                                   onclick="return confirm('<?php echo $translations[$currentLang]['confirm_delete']; ?>');">
                                    <i class="fas fa-trash-alt me-2"></i><?php echo $translations[$currentLang]['delete_task']; ?>
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Form validation
(function() {
    'use strict';
    var forms = document.querySelectorAll('.needs-validation');
    Array.prototype.slice.call(forms).forEach(function(form) {
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });
})();
</script>

<?php require_once 'includes/footer.php'; ?>
