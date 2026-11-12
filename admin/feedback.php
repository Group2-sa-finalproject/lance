<?php
session_start();
require_once "../config/database.php";

// Check admin access
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: ../index.php");
    exit;
}

$conn->set_charset("utf8mb4");

// Get all feedback
$sql = "SELECT id, user_id, feedback_type, message, created_at
        FROM feedback
        ORDER BY created_at DESC";

$result = $conn->query($sql);

if (!$result) {
    die("Database error: " . $conn->error);
}
// ==========================================
// SEARCH AND FILTER FEEDBACK
// ==========================================

$search = trim($_GET['search'] ?? '');
$type_filter = $_GET['type'] ?? 'all';
$date_filter = $_GET['date_filter'] ?? 'all';

$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

$allowed_types = ['all', 'Complaint', 'Suggestion', 'Satisfaction'];

if (!in_array($type_filter, $allowed_types, true)) {
    $type_filter = 'all';
}

$sql = "SELECT id, user_id, feedback_type, message, created_at
        FROM feedback
        WHERE 1=1";

$params = [];
$param_types = "";

// Search Resident ID or Message
if ($search !== '') {
    $sql .= " AND (CAST(user_id AS CHAR) LIKE ? OR message LIKE ?)";
    $search_value = "%".$search."%";

    $params[] = $search_value;
    $params[] = $search_value;

    $param_types .= "ss";
}

// Filter Feedback Type
if ($type_filter !== 'all') {
    $sql .= " AND feedback_type = ?";
    $params[] = $type_filter;
    $param_types .= "s";
}

// Filter Date
if ($date_filter === 'today') {
    $sql .= " AND DATE(created_at) = CURDATE()";

} elseif ($date_filter === 'week') {
    $sql .= " AND YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)";

} elseif ($date_filter === 'month') {
    $sql .= " AND MONTH(created_at) = MONTH(CURDATE())
              AND YEAR(created_at) = YEAR(CURDATE())";

} elseif ($date_filter === 'custom') {

    if ($start_date !== '') {
        $sql .= " AND DATE(created_at) >= ?";
        $params[] = $start_date;
        $param_types .= "s";
    }

    if ($end_date !== '') {
        $sql .= " AND DATE(created_at) <= ?";
        $params[] = $end_date;
        $param_types .= "s";
    }
}

$sql .= " ORDER BY created_at DESC";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">

<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Admin - Resident Feedback</title>

<link href="../assets/vendor/bootstrap/css/bootstrap.min.css"
      rel="stylesheet">

<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<link href="../assets/css/style.css" rel="stylesheet">

<style>
body {
    background: #f4f7f6;
    font-family: Arial, sans-serif;
    padding-top: 85px;
}

.navbar {
    background: #198754;
    padding: 12px 25px;
    position: fixed;
    top: 0;
    width: 100%;
    z-index: 9999;
}

.container {
    max-width: 1100px;
}

.card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 3px 12px rgba(0,0,0,.05);
}

.table th {
    background: #198754;
    color: white;
}

.table td {
    vertical-align: middle;
}

.page-title {
    color: #263b32;
    font-weight: bold;
}

.feedback-message {
    min-width: 200px;
    max-width: 400px;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.type-badge {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 5px;
    font-size: 12px;
    font-weight: bold;
}

.complaint {
    background: #f8d7da;
    color: #842029;
}

.suggestion {
    background: #cff4fc;
    color: #055160;
}

.satisfaction {
    background: #d1e7dd;
    color: #0f5132;
}

</style>
</head>

<body>

<nav class="navbar navbar-dark">
    <div class="container-fluid">

        <span class="navbar-brand">
            <i class="bi bi-recycle me-2"></i>
            Waste Collection Admin
        </span>

        <a href="dashboard.php" class="btn btn-outline-light btn-sm">
            <i class="bi bi-arrow-left me-1"></i>
            Dashboard
        </a>

    </div>
</nav>

<div class="container py-4">

    <h2 class="page-title mb-2">
        <i class="bi bi-chat-left-text me-2"></i>
        Resident Feedback
    </h2>

    <p class="text-muted mb-4">
        View all complaints, suggestions, and satisfaction
        messages submitted by residents.
    </p>

    <div class="card">
        <div class="card-body">
            <!-- SEARCH AND FILTER BAR -->
<div class="mb-4">

    <form method="GET" action="">

        <div class="row g-3 align-items-end">

            <!-- Search -->
            <div class="col-md-4">

                <label class="form-label fw-semibold">
                    Search Feedback
                </label>

                <input
                    type="text"
                    name="search"
                    class="form-control"
                    placeholder="Search Resident ID or Message..."
                    value="<?= htmlspecialchars($search) ?>"
                >

            </div>

            <!-- Feedback Type -->
            <div class="col-md-2">

                <label class="form-label fw-semibold">
                    Feedback Type
                </label>

                <select name="type" class="form-select">

                    <option value="all"
                        <?= $type_filter === 'all' ? 'selected' : '' ?>>
                        All Types
                    </option>

                    <option value="Complaint"
                        <?= $type_filter === 'Complaint' ? 'selected' : '' ?>>
                        Complaint
                    </option>

                    <option value="Suggestion"
                        <?= $type_filter === 'Suggestion' ? 'selected' : '' ?>>
                        Suggestion
                    </option>

                    <option value="Satisfaction"
                        <?= $type_filter === 'Satisfaction' ? 'selected' : '' ?>>
                        Satisfaction
                    </option>

                </select>

            </div>

            <!-- Date Filter -->
            <div class="col-md-2">

                <label class="form-label fw-semibold">
                    Date Submitted
                </label>

                <select name="date_filter"
                        id="dateFilter"
                        class="form-select">

                    <option value="all"
                        <?= $date_filter === 'all' ? 'selected' : '' ?>>
                        All Dates
                    </option>

                    <option value="today"
                        <?= $date_filter === 'today' ? 'selected' : '' ?>>
                        Today
                    </option>

                    <option value="week"
                        <?= $date_filter === 'week' ? 'selected' : '' ?>>
                        This Week
                    </option>

                    <option value="month"
                        <?= $date_filter === 'month' ? 'selected' : '' ?>>
                        This Month
                    </option>

                    <option value="custom"
                        <?= $date_filter === 'custom' ? 'selected' : '' ?>>
                        Custom Range
                    </option>

                </select>

            </div>

            <!-- Custom Start Date -->
            <div class="col-md-2" id="startDateContainer"
                 style="display: <?= $date_filter === 'custom' ? 'block' : 'none' ?>;">

                <label class="form-label fw-semibold">
                    Start Date
                </label>

                <input
                    type="date"
                    name="start_date"
                    class="form-control"
                    value="<?= htmlspecialchars($start_date) ?>"
                >

            </div>

            <!-- Custom End Date -->
            <div class="col-md-2" id="endDateContainer"
                 style="display: <?= $date_filter === 'custom' ? 'block' : 'none' ?>;">

                <label class="form-label fw-semibold">
                    End Date
                </label>

                <input
                    type="date"
                    name="end_date"
                    class="form-control"
                    value="<?= htmlspecialchars($end_date) ?>"
                >

            </div>

            <!-- Buttons -->
            <div class="col-md-2 d-flex gap-2">

                <button type="submit"
                        class="btn btn-success w-100">
                    <i class="bi bi-search me-1"></i>
                    Apply
                </button>

                <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>"
                   class="btn btn-secondary">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </a>

            </div>

        </div>

    </form>

</div>

            <div class="table-responsive">

                <table class="table table-bordered table-hover">

                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Resident ID</th>
                            <th>Feedback Type</th>
                            <th>Message</th>
                            <th>Date Submitted</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if ($result->num_rows > 0): ?>

                        <?php while ($row = $result->fetch_assoc()): ?>

                            <?php
                            $type = $row['feedback_type'];

                            $class = strtolower($type);
                            ?>

                            <tr>

                                <td>
                                    <?= htmlspecialchars($row['id']) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($row['user_id']) ?>
                                </td>

                                <td>
                                    <span class="type-badge <?= htmlspecialchars($class) ?>">
                                        <?= htmlspecialchars($type) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="feedback-message">
                                        <?= htmlspecialchars($row['message']) ?>
                                    </div>
                                </td>

                                <td>
                                    <?= htmlspecialchars(date(
                                        'M d, Y h:i A',
                                        strtotime($row['created_at'])
                                    )) ?>
                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="5" class="text-center text-muted p-4">
                                <i class="bi bi-inbox fs-2"></i>
                                <br>
                                No feedback submitted yet.
                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>
    </div>

</div>

<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {

    const dateFilter = document.getElementById("dateFilter");

    const startDateContainer = document.getElementById("startDateContainer");

    const endDateContainer = document.getElementById("endDateContainer");

    dateFilter.addEventListener("change", function () {

        if (this.value === "custom") {
            startDateContainer.style.display = "block";
            endDateContainer.style.display = "block";
        } else {
            startDateContainer.style.display = "none";
            endDateContainer.style.display = "none";
        }

    });

});
</script>

</body>
</html>
