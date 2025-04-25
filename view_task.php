<?php
ob_start(); // Start output buffering
require_once 'includes/init.php';
require_once 'includes/task_helpers.php';
require_once 'includes/header.php';

// Ensure user is logged in
if (!isLoggedIn()) {
    $_SESSION['flash_message'] = $translations[$currentLang]['msg_login_required_tasks'];
    $_SESSION['flash_type'] = 'warning';
    header('Location: login.php');
    exit;
}

// Get task ID
$taskId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$taskId) {
    $_SESSION['flash_message'] = $translations[$currentLang]['msg_invalid_task'];
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
           GROUP_CONCAT(DISTINCT a.username) as assignees,
           GROUP_CONCAT(DISTINCT a.id) as assignee_ids
    FROM tasks t
    LEFT JOIN task_categories tc ON t.category_id = tc.id
    LEFT JOIN users u ON t.user_id = u.id
    LEFT JOIN task_assignments ta ON t.id = ta.task_id
    LEFT JOIN users a ON ta.user_id = a.id
    WHERE t.id = ?
    GROUP BY t.id
");
$stmt->execute([$taskId]);
$task = $stmt->fetch();

if (!$task) {
    $_SESSION['flash_message'] = $translations[$currentLang]['msg_task_not_found'];
    $_SESSION['flash_type'] = 'danger';
    header('Location: tasks.php');
    exit;
}

// Check if user has access (admin or assignee)
$assigneeIds = explode(',', $task['assignee_ids']);
if (!isAdmin(getCurrentUserId()) && !in_array(getCurrentUserId(), $assigneeIds)) {
    $_SESSION['flash_message'] = $translations[$currentLang]['msg_access_denied'];
    $_SESSION['flash_type'] = 'danger';
    header('Location: tasks.php');
    exit;
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['status'])) {
    $newStatus = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    $comment = filter_input(INPUT_POST, 'comment', FILTER_SANITIZE_STRING);
    
    try {
        $pdo->beginTransaction();

        // Update task status
        $stmt = $pdo->prepare("UPDATE tasks SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $taskId]);

        // Add comment if provided
        if (!empty($comment)) {
            $stmt = $pdo->prepare("
                INSERT INTO task_comments (task_id, user_id, comment)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$taskId, getCurrentUserId(), $comment]);
        }

        // Log activity
        logActivity(getCurrentUserId(), 'update_task', "Updated task status to: $newStatus");

        $pdo->commit();
        
        $_SESSION['flash_message'] = $translations[$currentLang]['msg_status_updated_task'];
        $_SESSION['flash_type'] = 'success';
        header("Location: view_task.php?id=$taskId");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['flash_message'] = $translations[$currentLang]['msg_status_update_failed_task'];
        $_SESSION['flash_type'] = 'danger';
    }
}

// Get task comments
$stmt = $pdo->prepare("
    SELECT c.*, u.username
    FROM task_comments c
    JOIN users u ON c.user_id = u.id
    WHERE c.task_id = ?
    ORDER BY c.created_at DESC
");
$stmt->execute([$taskId]);
$comments = $stmt->fetchAll();

require_once 'includes/header.php';
?>

<div class="container py-5">
    <div class="row">
        <!-- Task Details -->
        <div class="col-md-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h1 class="h4 mb-0">
                            <?php echo h($task['title']); ?>
                        </h1>
                        <?php if (isAdmin(getCurrentUserId())): ?>
                            <a href="edit_task.php?id=<?php echo $taskId; ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-edit me-2"></i><?php echo $translations[$currentLang]['edit_task']; ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="mb-4">
                        <span class="badge" style="background-color: <?php echo h($task['category_color']); ?>">
                            <?php echo h($task['category_name']); ?>
                        </span>
                        <span class="badge bg-<?php 
                            echo match($task['priority']) {
                                'urgent' => 'danger',
                                'high' => 'warning',
                                'medium' => 'info',
                                'low' => 'secondary'
                            };
                        ?> ms-2">
                            <?php echo $translations[$currentLang]['priority_' . $task['priority']]; ?>
                        </span>
                        <span class="badge bg-<?php 
                            echo match($task['status']) {
                                'pending' => 'secondary',
                                'in_progress' => 'primary',
                                'completed' => 'success',
                                'cancelled' => 'danger'
                            };
                        ?> ms-2">
                            <?php echo $translations[$currentLang]['status_' . $task['status']]; ?>
                        </span>
                    </div>

                    <div class="mb-4">
                        <h5><?php echo $translations[$currentLang]['description']; ?></h5>
                        <p class="mb-0"><?php echo nl2br(h($task['description'])); ?></p>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h5><?php echo $translations[$currentLang]['assigned_to']; ?></h5>
                            <p class="mb-0"><?php echo h($task['assignees']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <h5><?php echo $translations[$currentLang]['due_date']; ?></h5>
                            <p class="mb-0">
                                <?php if ($task['due_date']): ?>
                                    <?php echo date('M d, Y', strtotime($task['due_date'])); ?>
                                    <?php
                                        $isDelayed = isTaskDelayed($task['due_date'], date('Y-m-d'));
                                        if ($isDelayed && $task['status'] !== 'completed' && $task['status'] !== 'cancelled') {
                                            $daysDelayed = getDaysDelayed($task['due_date'], date('Y-m-d'));
                                            echo '<span class="badge bg-danger ms-2">' . $translations[$currentLang]['delayed'] . ' (' . $daysDelayed . ' ' . $translations[$currentLang]['days'] . ')</span>';
                                        }
                                    ?>
                                <?php else: ?>
                                    <span class="text-muted"><?php echo $translations[$currentLang]['no_due_date']; ?></span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <!-- Status Update Form -->
                    <form method="POST" class="mb-4">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <select name="status" class="form-select" required>
                                    <option value="pending" <?php echo $task['status'] === 'pending' ? 'selected' : ''; ?>><?php echo $translations[$currentLang]['status_pending']; ?></option>
                                    <option value="in_progress" <?php echo $task['status'] === 'in_progress' ? 'selected' : ''; ?>><?php echo $translations[$currentLang]['status_in_progress']; ?></option>
                                    <option value="completed" <?php echo $task['status'] === 'completed' ? 'selected' : ''; ?>><?php echo $translations[$currentLang]['status_completed']; ?></option>
                                    <option value="cancelled" <?php echo $task['status'] === 'cancelled' ? 'selected' : ''; ?>><?php echo $translations[$currentLang]['status_cancelled']; ?></option>
                                </select>
                            </div>
                            <div class="col-md-8">
                                <div class="input-group">
                                    <input type="text" name="comment" class="form-control" 
                                           placeholder="<?php echo $translations[$currentLang]['add_comment_placeholder']; ?>">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i><?php echo $translations[$currentLang]['update_status']; ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>

                    <!-- Comments Section -->
                    <h5><?php echo $translations[$currentLang]['comments']; ?></h5>
                    <?php if (empty($comments)): ?>
                        <p class="text-muted"><?php echo $translations[$currentLang]['no_comments']; ?></p>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($comments as $comment): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <strong><?php echo h($comment['username']); ?></strong>
                                        <small class="text-muted">
                                            <?php echo date('M d, Y H:i', strtotime($comment['created_at'])); ?>
                                        </small>
                                    </div>
                                    <p class="mb-0"><?php echo nl2br(h($comment['comment'])); ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-md-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-info-circle me-2"></i><?php echo $translations[$currentLang]['task_info']; ?>
                    </h5>
                </div>
                <div class="list-group list-group-flush">
                    <div class="list-group-item">
                        <small class="text-muted d-block"><?php echo $translations[$currentLang]['created_by']; ?></small>
                        <strong><?php echo h($task['creator_name']); ?></strong>
                    </div>
                    <div class="list-group-item">
                        <small class="text-muted d-block"><?php echo $translations[$currentLang]['created_at']; ?></small>
                        <strong><?php echo date('M d, Y H:i', strtotime($task['created_at'])); ?></strong>
                    </div>
                    <div class="list-group-item">
                        <small class="text-muted d-block"><?php echo $translations[$currentLang]['last_updated']; ?></small>
                        <strong><?php echo date('M d, Y H:i', strtotime($task['updated_at'])); ?></strong>
                    </div>
                </div>
            </div>

            <a href="tasks.php" class="btn btn-outline-secondary w-100">
                <i class="fas fa-arrow-left me-2"></i><?php echo $translations[$currentLang]['back_to_tasks']; ?>
            </a>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
