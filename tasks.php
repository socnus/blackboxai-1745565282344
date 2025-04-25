<?php
require_once 'includes/init.php';

// Ensure user is logged in
if (!isLoggedIn()) {
    $_SESSION['flash_message'] = $translations[$currentLang]['msg_login_required_tasks'];
    $_SESSION['flash_type'] = 'warning';
    header('Location: login.php');
    exit;
}

// Get filter parameters
$status = filter_input(INPUT_GET, 'status', FILTER_SANITIZE_STRING);
$priority = filter_input(INPUT_GET, 'priority', FILTER_SANITIZE_STRING);
$category = filter_input(INPUT_GET, 'category', FILTER_VALIDATE_INT);

// Pagination setup
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Build query based on user role and filters
$params = [];
$whereConditions = [];

// If not admin, only show tasks assigned to user
if (!isAdmin(getCurrentUserId())) {
    $whereConditions[] = "ta.user_id = ?";
    $params[] = getCurrentUserId();
}

if ($status) {
    $whereConditions[] = "t.status = ?";
    $params[] = $status;
}

if ($priority) {
    $whereConditions[] = "t.priority = ?";
    $params[] = $priority;
}

if ($category) {
    $whereConditions[] = "t.category_id = ?";
    $params[] = $category;
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

// Get total tasks count
$countQuery = "
    SELECT COUNT(DISTINCT t.id) 
    FROM tasks t
    LEFT JOIN task_assignments ta ON t.id = ta.task_id
    $whereClause
";
$stmt = $pdo->prepare($countQuery);
$stmt->execute($params);
$totalTasks = $stmt->fetchColumn();
$totalPages = ceil($totalTasks / $perPage);

// Get tasks
$query = "
    SELECT DISTINCT t.*, 
           tc.name as category_name,
           tc.color as category_color,
           u.username as creator_name,
           GROUP_CONCAT(DISTINCT a.username) as assignees
    FROM tasks t
    LEFT JOIN task_assignments ta ON t.id = ta.task_id
    LEFT JOIN users u ON t.user_id = u.id
    LEFT JOIN task_categories tc ON t.category_id = tc.id
    LEFT JOIN task_assignments taa ON t.id = taa.task_id
    LEFT JOIN users a ON taa.user_id = a.id
    $whereClause
    GROUP BY t.id
    ORDER BY 
        CASE t.priority
            WHEN 'urgent' THEN 1
            WHEN 'high' THEN 2
            WHEN 'medium' THEN 3
            WHEN 'low' THEN 4
        END,
        CASE t.status
            WHEN 'pending' THEN 1
            WHEN 'in_progress' THEN 2
            WHEN 'completed' THEN 3
            WHEN 'cancelled' THEN 4
        END,
        t.due_date ASC
    LIMIT ? OFFSET ?
";
$params[] = $perPage;
$params[] = $offset;

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$tasks = $stmt->fetchAll();

// Get categories for filter
$categories = $pdo->query("SELECT * FROM task_categories ORDER BY name")->fetchAll();

require_once 'includes/task_helpers.php';
require_once 'includes/header.php';
?>

<div class="container py-5">
    <div class="col mb-4">
        <nav aria-label="breadcrumb" class="btn btn-outline-secondary w-100 p-3">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="index.php"><?php echo $translations[$currentLang]['nav_home']; ?></a></li>
                <li class="breadcrumb-item"><?php echo $translations[$currentLang]['tasks']; ?></li>
            </ol>
        </nav>
    </div>
    <div class="row mb-4">
        <div class="col-md-8">
            <h1>
                <i class="fas fa-tasks me-2"></i><?php echo $translations[$currentLang]['tasks']; ?>
            </h1>
        </div>
        <div class="col-md-4 text-end">
            <?php if (isAdmin(getCurrentUserId())): ?>
                <a href="add_task.php" class="btn btn-primary">
                    <i class="fas fa-plus me-2"></i><?php echo $translations[$currentLang]['new_task']; ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tasks List -->
    <?php if (empty($tasks)): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i><?php echo $translations[$currentLang]['no_tasks_found']; ?>
            <?php if (isAdmin(getCurrentUserId())): ?>
                <hr>
                <p class="mb-0">
                    <?php echo $translations[$currentLang]['initialize_task_system']; ?>
                    <a href="check_tables.php" class="alert-link"><?php echo $translations[$currentLang]['click_initialize']; ?></a>.
                </p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="card shadow-sm">
            <div class="list-group list-group-flush">
                <?php foreach ($tasks as $task): ?>
                    <div class="list-group-item">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <h5 class="mb-1">
                                    <a href="view_task.php?id=<?php echo $task['id']; ?>" 
                                       class="text-decoration-none">
                                        <?php echo h($task['title']); ?>
                                    </a>
                                </h5>
                                <p class="mb-1 text-muted small">
                                    <span class="badge" 
                                          style="background-color: <?php echo h($task['category_color']); ?>">
                                        <?php echo h($task['category_name']); ?>
                                    </span>
                                    <span class="ms-2">
                                        <i class="fas fa-user me-1"></i>
                                        <?php echo $translations[$currentLang]['created_by']; ?> <?php echo h($task['creator_name']); ?>
                                    </span>
                                    <?php if ($task['assignees']): ?>
                                        <span class="ms-2">
                                            <i class="fas fa-users me-1"></i>
                                            <?php echo $translations[$currentLang]['assigned_to']; ?>: <?php echo h($task['assignees']); ?>
                                        </span>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="col-md-2">
                                <span class="badge bg-<?php 
                                    echo match($task['priority']) {
                                        'urgent' => 'danger',
                                        'high' => 'warning',
                                        'medium' => 'info',
                                        'low' => 'secondary'
                                    };
                                ?>">
                                    <?php echo $translations[$currentLang]['priority_' . $task['priority']]; ?>
                                </span>
                            </div>
                            <div class="col-md-2">
                                <span class="badge bg-<?php 
                                    echo match($task['status']) {
                                        'pending' => 'secondary',
                                        'in_progress' => 'primary',
                                        'completed' => 'success',
                                        'cancelled' => 'danger'
                                    };
                                ?>">
                                    <?php echo $translations[$currentLang]['status_' . $task['status']]; ?>
                                </span>
                            </div>
                <div class="col-md-2 text-end">
                    <?php if ($task['due_date']): ?>
                        <small class="text-muted">
                            <i class="fas fa-calendar me-1"></i>
                            <?php echo date('M d, Y', strtotime($task['due_date'])); ?>
                            <?php
                                $isDelayed = isTaskDelayed($task['due_date'], date('Y-m-d'));
                                if ($isDelayed && $task['status'] !== 'completed' && $task['status'] !== 'cancelled') {
                                    $daysDelayed = getDaysDelayed($task['due_date'], date('Y-m-d'));
                                    echo '<span class="badge bg-danger ms-2">' . $translations[$currentLang]['delayed'] . ' (' . $daysDelayed . ' ' . $translations[$currentLang]['days'] . ')</span>';
                                }
                            ?>
                        </small>
                    <?php endif; ?>
                </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <nav aria-label="Task navigation" class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&status=<?php echo $status; ?>&priority=<?php echo $priority; ?>&category=<?php echo $category; ?>">
                                <?php echo $translations[$currentLang]['previous']; ?>
                            </a>
                        </li>
                    <?php endif; ?>
                    
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo $status; ?>&priority=<?php echo $priority; ?>&category=<?php echo $category; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&status=<?php echo $status; ?>&priority=<?php echo $priority; ?>&category=<?php echo $category; ?>">
                                <?php echo $translations[$currentLang]['next']; ?>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>
