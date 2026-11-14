
<?php
session_start();

require_once __DIR__ . '/../../config/database.php';

// ADMIN ACCESS
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../../login.php');
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection error. Please check config/database.php.');
}

mysqli_set_charset($conn, 'utf8mb4');

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function peso($amount): string
{
    return '₱' . number_format((float)$amount, 2);
}

// CSRF TOKEN
if (empty($_SESSION['subscription_admin_csrf'])) {
    $_SESSION['subscription_admin_csrf'] = bin2hex(random_bytes(32));
}

$success_message = '';
$error_message = '';

// ==========================================
// PROCESS PAYMENT CONFIRMATION / REJECTION
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $posted_token = $_POST['csrf_token'] ?? '';
    $subscription_id = filter_input(INPUT_POST, 'subscription_id', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';

    if (
        !is_string($posted_token) ||
        !hash_equals($_SESSION['subscription_admin_csrf'], $posted_token)
    ) {
        $error_message = 'Your session token is invalid. Please refresh the page and try again.';

    } elseif (!$subscription_id || $subscription_id < 1) {
        $error_message = 'Invalid subscription record.';

    } elseif (!in_array($action, ['confirm', 'reject'], true)) {
        $error_message = 'Invalid administrator action.';

    } else {

        try {

            $conn->begin_transaction();

            $lock_stmt = $conn->prepare("
                SELECT id, total_amount, payment_status, subscription_status
                FROM subscriptions
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");

            if (!$lock_stmt) {
                throw new Exception('Unable to prepare the subscription lookup.');
            }

            $lock_stmt->bind_param('i', $subscription_id);
            $lock_stmt->execute();

            $subscription = $lock_stmt->get_result()->fetch_assoc();
            $lock_stmt->close();

            if (!$subscription) {
                throw new Exception('Subscription record was not found.');
            }

            if (
                $subscription['payment_status'] !== 'Pending Confirmation' ||
                $subscription['subscription_status'] !== 'Pending Approval'
            ) {
                throw new Exception('This record is no longer awaiting payment confirmation. Refresh the page.');
            }

            if ($action === 'confirm') {

                // CONFIRM PAYMENT
                $update_stmt = $conn->prepare("
                    UPDATE subscriptions
                    SET amount_paid = total_amount,
                        balance_due = 0.00,
                        payment_status = 'Paid',
                        subscription_status = 'Active'
                    WHERE id = ?
                      AND payment_status = 'Pending Confirmation'
                      AND subscription_status = 'Pending Approval'
                ");

                if (!$update_stmt) {
                    throw new Exception('Unable to prepare the payment confirmation.');
                }

                $update_stmt->bind_param('i', $subscription_id);
                $update_stmt->execute();

                $changed = $update_stmt->affected_rows;
                $update_stmt->close();

                if ($changed !== 1) {
                    throw new Exception('The subscription could not be confirmed. Refresh and try again.');
                }

                // SAVE APPROVAL HISTORY
                $history_stmt = $conn->prepare("
                    INSERT INTO subscription_approval_history
                        (subscription_id, admin_id)
                    VALUES (?, ?)
                ");

                if (!$history_stmt) {
                    throw new Exception('Unable to prepare approval history.');
                }

                $admin_id = (int)$_SESSION['user_id'];

                $history_stmt->bind_param(
                    'ii',
                    $subscription_id,
                    $admin_id
                );

                if (!$history_stmt->execute()) {
                    throw new Exception('Unable to save approval history.');
                }

                $history_stmt->close();

                $success_message = 'Payment successfully confirmed. The subscription is now active and marked as paid.';

            } else {

                // REJECT PAYMENT
                $update_stmt = $conn->prepare("
                    UPDATE subscriptions
                    SET payment_status = 'Unpaid'
                    WHERE id = ?
                      AND payment_status = 'Pending Confirmation'
                      AND subscription_status = 'Pending Approval'
                ");

                if (!$update_stmt) {
                    throw new Exception('Unable to prepare the payment rejection.');
                }

                $update_stmt->bind_param('i', $subscription_id);
                $update_stmt->execute();

                $changed = $update_stmt->affected_rows;
                $update_stmt->close();

                if ($changed !== 1) {
                    throw new Exception('The payment notice could not be rejected. Refresh and try again.');
                }

                $success_message = 'Payment notice rejected. The subscription remains pending approval and unpaid.';
            }

            $conn->commit();

            $_SESSION['subscription_admin_csrf'] = bin2hex(random_bytes(32));

        } catch (Throwable $exception) {

            $conn->rollback();
            $error_message = $exception->getMessage();

        }
    }
}

// ==========================================
// RETRIEVE SUBSCRIPTIONS
// ==========================================

$list_sql = "
    SELECT
        s.id,
        s.user_id,
        u.fullname,
        u.users AS username,
        s.service_fee,
        s.months,
        s.original_amount,
        s.points_redeemed,
        s.discount_amount,
        s.total_amount,
        s.amount_paid,
        s.balance_due,
        s.subscription_status,
        s.payment_status,
        s.created_at
    FROM subscriptions AS s
    INNER JOIN users AS u ON u.id = s.user_id
    ORDER BY
        CASE
            WHEN s.payment_status = 'Pending Confirmation' THEN 0
            WHEN s.subscription_status = 'Pending Approval' THEN 1
            ELSE 2
        END,
        s.created_at DESC
";

$result = $conn->query($list_sql);

if (!$result) {
    die('Unable to load subscriptions: ' . e($conn->error));
}

$all_rows = [];
$total_records = $result->num_rows;
$pending_confirmations = 0;
$active_subscriptions = 0;

while ($row = $result->fetch_assoc()) {

    $all_rows[] = $row;

    if ($row['payment_status'] === 'Pending Confirmation') {
        $pending_confirmations++;
    }

    if (
        $row['subscription_status'] === 'Active' &&
        $row['payment_status'] === 'Paid' &&
        (float)$row['balance_due'] <= 0
    ) {
        $active_subscriptions++;
    }
}

// ==========================================
// SEARCH AND FILTERS
// ==========================================

$search = trim($_GET['search'] ?? '');

$subscription_filter = $_GET['subscription_status'] ?? 'all';
$payment_filter = $_GET['payment_status'] ?? 'all';
$date_filter = $_GET['date_filter'] ?? 'all';

$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

$allowed_subscription = ['all', 'Pending Approval', 'Active'];
$allowed_payment = ['all', 'Pending Confirmation', 'Paid', 'Unpaid'];
$allowed_dates = ['all', 'today', 'week', 'month', 'custom'];

if (!in_array($subscription_filter, $allowed_subscription, true)) {
    $subscription_filter = 'all';
}

if (!in_array($payment_filter, $allowed_payment, true)) {
    $payment_filter = 'all';
}

if (!in_array($date_filter, $allowed_dates, true)) {
    $date_filter = 'all';
}

// Validate date format and actual calendar dates
if (
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) ||
    !strtotime($start_date) ||
    date('Y-m-d', strtotime($start_date)) !== $start_date
) {
    $start_date = '';
}

if (
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date) ||
    !strtotime($end_date) ||
    date('Y-m-d', strtotime($end_date)) !== $end_date
) {
    $end_date = '';
}

// Filter records
$rows = array_values(array_filter($all_rows, function ($row) use (
    $search,
    $subscription_filter,
    $payment_filter,
    $date_filter,
    $start_date,
    $end_date
) {

    // SEARCH
    if ($search !== '') {

        $search_match =
            stripos($row['fullname'] ?? '', $search) !== false ||
            stripos($row['username'] ?? '', $search) !== false ||
            stripos((string)$row['user_id'], $search) !== false;

        if (!$search_match) {
            return false;
        }
    }

    // SUBSCRIPTION STATUS
    if (
        $subscription_filter !== 'all' &&
        $row['subscription_status'] !== $subscription_filter
    ) {
        return false;
    }

    // PAYMENT STATUS
    if (
        $payment_filter !== 'all' &&
        $row['payment_status'] !== $payment_filter
    ) {
        return false;
    }

    // DATE FILTER
    $created_date = date('Y-m-d', strtotime($row['created_at']));

    if ($date_filter === 'today') {

        if ($created_date !== date('Y-m-d')) {
            return false;
        }

    } elseif ($date_filter === 'week') {

        $week_start = date('Y-m-d', strtotime('monday this week'));
        $week_end = date('Y-m-d', strtotime('sunday this week'));

        if ($created_date < $week_start || $created_date > $week_end) {
            return false;
        }

    } elseif ($date_filter === 'month') {

        if (date('Y-m', strtotime($created_date)) !== date('Y-m')) {
            return false;
        }

    } elseif ($date_filter === 'custom') {

        if ($start_date !== '' && $created_date < $start_date) {
            return false;
        }

        if ($end_date !== '' && $created_date > $end_date) {
            return false;
        }
    }

    return true;
}));

$filtered_records = count($rows);

// ==========================================
// APPROVAL HISTORY
// ==========================================

$history_sql = "
    SELECT
        h.id,
        h.subscription_id,
        h.admin_id,
        h.approved_at,
        admin.fullname AS admin_name
    FROM subscription_approval_history AS h
    LEFT JOIN users AS admin
        ON admin.id = h.admin_id
    ORDER BY h.approved_at DESC
";

$history_result = $conn->query($history_sql);

if (!$history_result) {
    die('Unable to load approval history: ' . e($conn->error));
}

$history_count = $history_result->num_rows;

// Preserve the current dashboard/module URL when resetting filters.
$reset_params = $_GET;

foreach ([
    'search',
    'subscription_status',
    'payment_status',
    'date_filter',
    'start_date',
    'end_date'
] as $filter_key) {
    unset($reset_params[$filter_key]);
}

$reset_url = strtok($_SERVER['REQUEST_URI'], '?');

if (!empty($reset_params)) {
    $reset_url .= '?' . http_build_query($reset_params);
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manage Subscriptions | Admin</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>

        body {
            background: #f3f7f5;
            font-family: Arial, sans-serif;
            color: #263b30;
        }

        /* PAGE HEADER */

        .page-header {
            background: linear-gradient(135deg, #146c43, #198754);
            color: white;
            border-radius: 15px;
            padding: 28px;
            box-shadow: 0 4px 15px rgba(25, 135, 84, .15);
        }

        .page-header p {
            color: rgba(255,255,255,.85);
        }

        .page-header .btn {
            border-radius: 8px;
            font-weight: 600;
        }

        /* SUMMARY CARDS */

        .summary-card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 3px 12px rgba(0,0,0,.045);
            transition: .2s ease;
            height: 100%;
        }

        .summary-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 16px rgba(0,0,0,.08);
        }

        .summary-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .icon-green {
            background: #e4f4eb;
            color: #198754;
        }

        .icon-yellow {
            background: #fff4d6;
            color: #b78103;
        }

        .icon-blue {
            background: #e5efff;
            color: #2764b5;
        }

        /* GENERAL CARDS */

        .content-card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 3px 14px rgba(0,0,0,.05);
            overflow: hidden;
        }

        .content-card-header {
            background: #fff;
            padding: 20px 22px;
            border-bottom: 1px solid #edf1ee;
        }

        /* SEARCH AND FILTER PANEL */

        .filter-panel {
            background: #f8fbf9;
            border: 1px solid #e1ece5;
            border-radius: 13px;
            padding: 22px;
            margin-bottom: 25px;
        }

        .filter-heading {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            color: #185c39;
            margin-bottom: 5px;
        }

        .filter-heading-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: #e2f2e9;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #198754;
            font-size: 18px;
        }

        .filter-description {
            color: #75847b;
            font-size: 13px;
            margin-bottom: 20px;
        }

        .filter-panel .form-label {
            font-size: 13px;
            font-weight: 700;
            color: #41594a;
            margin-bottom: 7px;
        }

        .filter-panel .form-control,
        .filter-panel .form-select {
            min-height: 43px;
            border: 1px solid #dce7df;
            border-radius: 8px;
            font-size: 13px;
            background-color: white;
        }

        .filter-panel .form-control:focus,
        .filter-panel .form-select:focus {
            border-color: #198754;
            box-shadow: 0 0 0 .2rem rgba(25,135,84,.12);
        }

        .search-wrapper {
            position: relative;
        }

        .search-wrapper .search-icon {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: #198754;
            font-size: 17px;
            pointer-events: none;
        }

        .search-wrapper input {
            padding-left: 40px;
        }

        .filter-btn {
            min-height: 43px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
        }

        .filter-divider {
            border-top: 1px solid #e4ece6;
            margin: 20px 0;
        }

        .date-range-box {
            background: #fff;
            border: 1px dashed #b9d8c4;
            padding: 15px;
            border-radius: 10px;
        }

        /* APPROVAL HISTORY BUTTON */

        .history-btn {
            border: 1px solid #198754;
            color: #198754;
            border-radius: 9px;
            font-weight: 600;
            padding: 9px 15px;
            transition: .2s ease;
        }

        .history-btn:hover {
            background: #198754;
            color: white;
        }

        .history-count {
            background: #e4f4eb;
            color: #146c43;
            border-radius: 20px;
            padding: 3px 8px;
            font-size: 11px;
            margin-left: 5px;
        }

        /* TABLE */

        .table thead th {
            white-space: nowrap;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .3px;
            color: #53665a;
            padding: 14px 12px;
            background: #f1f6f3;
            border-bottom: 1px solid #e0e9e3;
        }

        .table td {
            vertical-align: middle;
            padding: 14px 12px;
            font-size: 13px;
        }

        .table tbody tr:hover {
            background: #f7fbf8;
        }

        .status-badge {
            font-size: 11px;
            padding: 6px 9px;
            border-radius: 6px;
        }

        .action-form {
            display: inline-block;
            margin: 2px;
        }

        .action-form button {
            border-radius: 7px;
            font-size: 11px;
            font-weight: 600;
        }

        /* RESULT COUNT */

        .result-count {
            background: #e8f4ec;
            color: #17683e;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 600;
        }

        /* EMPTY STATE */

        .empty-state {
            text-align: center;
            padding: 45px 20px;
            color: #829087;
        }

        .empty-state i {
            font-size: 42px;
            color: #a8cbb5;
        }

        .empty-state h6 {
            color: #405849;
            font-weight: 700;
            margin-top: 12px;
        }

        /* APPROVAL HISTORY MODAL */

        .history-modal .modal-content {
            border: none;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 35px rgba(0,0,0,.15);
        }

        .history-modal .modal-header {
            background: linear-gradient(135deg, #146c43, #198754);
            color: white;
            padding: 20px 24px;
            border: none;
        }

        .history-modal .modal-body {
            padding: 24px;
            background: #f8faf9;
        }

        .history-intro {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #e8f4ec;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            color: #245b3d;
            font-size: 13px;
        }

        .history-intro i {
            font-size: 25px;
            color: #198754;
        }

        .history-table-container {
            background: white;
            border: 1px solid #e5ece7;
            border-radius: 10px;
            overflow: hidden;
        }

        .history-table {
            margin-bottom: 0;
        }

        .history-table thead th {
            background: #e8f4ec;
            color: #205c3a;
            font-size: 12px;
            padding: 14px;
        }

        .history-table td {
            padding: 14px;
        }

        .history-id {
            background: #edf5ef;
            color: #17683e;
            border-radius: 6px;
            padding: 5px 9px;
            font-weight: 700;
            font-size: 12px;
        }

        .admin-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #e2f2e9;
            color: #198754;
            margin-right: 8px;
            font-size: 15px;
        }

        .history-empty {
            text-align: center;
            padding: 45px 15px;
            color: #7d8b82;
        }

        .history-empty i {
            font-size: 45px;
            color: #a8cbb5;
        }

        @media (max-width: 768px) {

            .page-header {
                padding: 22px;
            }

            .filter-panel {
                padding: 16px;
            }

            .content-card-header {
                padding: 16px;
            }

        }

    </style>
</head>

<body>

<div class="container-fluid py-4 px-3 px-lg-4">

    <!-- PAGE HEADER -->

    <div class="page-header mb-4">

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">

            <div>
                <h1 class="h3 fw-bold mb-2">
                    <i class="bi bi-wallet2 me-2"></i>
                    Manage Subscriptions and Payments
                </h1>

                <p class="mb-0">
                    Review resident subscriptions, verify payment notices, and manage payment records.
                </p>
            </div>

            <a href="../dashboard.php" class="btn btn-light">
                <i class="bi bi-arrow-left me-1"></i>
                Back to Dashboard
            </a>

        </div>

    </div>

    <!-- ALERT MESSAGES -->

    <?php if ($success_message !== ''): ?>

        <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?= e($success_message) ?>

            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>

    <?php endif; ?>

    <?php if ($error_message !== ''): ?>

        <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?= e($error_message) ?>

            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>

    <?php endif; ?>

    <!-- SIMULATION NOTICE -->

    <div class="alert alert-warning border-0 shadow-sm">
        <i class="bi bi-info-circle-fill me-2"></i>

        <strong>School-project simulation:</strong>
        "Cash Simulation" is only a simulated payment notice.
        This page does not receive or process real money. The administrator must manually verify and confirm each notice.
    </div>

    <!-- SUMMARY CARDS -->

    <div class="row g-3 mb-4">

        <div class="col-sm-6 col-xl-4">

            <div class="card summary-card">
                <div class="card-body p-4">

                    <div class="d-flex justify-content-between align-items-center">

                        <div>
                            <div class="text-secondary small mb-1">
                                All Subscription Records
                            </div>

                            <div class="fs-3 fw-bold">
                                <?= number_format($total_records) ?>
                            </div>
                        </div>

                        <div class="summary-icon icon-green">
                            <i class="bi bi-people-fill"></i>
                        </div>

                    </div>

                </div>
            </div>

        </div>

        <div class="col-sm-6 col-xl-4">

            <div class="card summary-card">
                <div class="card-body p-4">

                    <div class="d-flex justify-content-between align-items-center">

                        <div>
                            <div class="text-secondary small mb-1">
                                Awaiting Payment Confirmation
                            </div>

                            <div class="fs-3 fw-bold text-warning">
                                <?= number_format($pending_confirmations) ?>
                            </div>
                        </div>

                        <div class="summary-icon icon-yellow">
                            <i class="bi bi-hourglass-split"></i>
                        </div>

                    </div>

                </div>
            </div>

        </div>

        <div class="col-sm-6 col-xl-4">

            <div class="card summary-card">
                <div class="card-body p-4">

                    <div class="d-flex justify-content-between align-items-center">

                        <div>
                            <div class="text-secondary small mb-1">
                                Active and Paid Subscriptions
                            </div>

                            <div class="fs-3 fw-bold text-success">
                                <?= number_format($active_subscriptions) ?>
                            </div>
                        </div>

                        <div class="summary-icon icon-blue">
                            <i class="bi bi-check-circle-fill"></i>
                        </div>

                    </div>

                </div>
            </div>

        </div>

    </div>

    <!-- SUBSCRIPTION RECORDS CARD -->

    <div class="card content-card">

        <div class="content-card-header">

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">

                <div>
                    <h2 class="h5 fw-bold mb-1">
                        <i class="bi bi-card-list text-success me-2"></i>
                        Subscription Records
                    </h2>

                    <small class="text-secondary">
                        View and manage all resident subscription payments.
                    </small>
                </div>

                <button
                    type="button"
                    class="btn history-btn"
                    data-bs-toggle="modal"
                    data-bs-target="#approvalHistoryModal"
                >
                    <i class="bi bi-clock-history me-1"></i>
                    Approval History

                    <span class="history-count">
                        <?= number_format($history_count) ?>
                    </span>
                </button>

            </div>

        </div>

        <div class="card-body p-3 p-lg-4">

            <!-- SEARCH AND FILTER PANEL -->

            <div class="filter-panel">

                <div class="filter-heading">

                    <div class="filter-heading-icon">
                        <i class="bi bi-funnel-fill"></i>
                    </div>

                    <div>
                        Search and Filter
                    </div>

                </div>

                <p class="filter-description">
                    Find specific subscription records using resident details, payment status, or date created.
                </p>

                <form method="GET" action="">

                    <div class="row g-3 align-items-end">

                        <!-- SEARCH -->

                        <div class="col-lg-4 col-md-6">

                            <label class="form-label">
                                Search Resident
                            </label>

                            <div class="search-wrapper">

                                <i class="bi bi-search search-icon"></i>

                                <input
                                    type="text"
                                    name="search"
                                    class="form-control"
                                    placeholder="Name, username, or resident ID..."
                                    value="<?= e($search) ?>"
                                >

                            </div>

                        </div>

                        <!-- SUBSCRIPTION STATUS -->

                        <div class="col-lg-2 col-md-6">

                            <label class="form-label">
                                Subscription Status
                            </label>

                            <select name="subscription_status" class="form-select">

                                <option value="all"
                                    <?= $subscription_filter === 'all' ? 'selected' : '' ?>>
                                    All Status
                                </option>

                                <option value="Pending Approval"
                                    <?= $subscription_filter === 'Pending Approval' ? 'selected' : '' ?>>
                                    Pending Approval
                                </option>

                                <option value="Active"
                                    <?= $subscription_filter === 'Active' ? 'selected' : '' ?>>
                                    Active
                                </option>

                            </select>

                        </div>

                        <!-- PAYMENT STATUS -->

                        <div class="col-lg-2 col-md-6">

                            <label class="form-label">
                                Payment Status
                            </label>

                            <select name="payment_status" class="form-select">

                                <option value="all"
                                    <?= $payment_filter === 'all' ? 'selected' : '' ?>>
                                    All Payments
                                </option>

                                <option value="Pending Confirmation"
                                    <?= $payment_filter === 'Pending Confirmation' ? 'selected' : '' ?>>
                                    Pending Confirmation
                                </option>

                                <option value="Paid"
                                    <?= $payment_filter === 'Paid' ? 'selected' : '' ?>>
                                    Paid
                                </option>

                                <option value="Unpaid"
                                    <?= $payment_filter === 'Unpaid' ? 'selected' : '' ?>>
                                    Unpaid
                                </option>

                            </select>

                        </div>

                        <!-- DATE FILTER -->

                        <div class="col-lg-2 col-md-6">

                            <label class="form-label">
                                Date Created
                            </label>

                            <select
                                name="date_filter"
                                id="dateFilter"
                                class="form-select"
                            >

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

                        <!-- BUTTONS -->

                        <div class="col-lg-2 col-md-12">

                            <div class="d-flex gap-2">

                                <button type="submit" class="btn btn-success filter-btn flex-grow-1">
                                    <i class="bi bi-search me-1"></i>
                                    Apply
                                </button>

                                <a
                                    href="<?= e($reset_url) ?>"
                                    class="btn btn-outline-secondary filter-btn"
                                    title="Reset Filters"
                                >
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                </a>

                            </div>

                        </div>

                    </div>

                    <!-- CUSTOM DATE RANGE -->

                    <div
                        id="customDateContainer"
                        class="date-range-box mt-3"
                        style="display: <?= $date_filter === 'custom' ? 'block' : 'none' ?>;"
                    >

                        <div class="d-flex align-items-center gap-2 mb-3">
                            <i class="bi bi-calendar-range text-success"></i>

                            <span class="fw-bold small text-success">
                                Custom Date Range
                            </span>
                        </div>

                        <div class="row g-3">

                            <div class="col-md-4">

                                <label class="form-label">
                                    Start Date
                                </label>

                                <input
                                    type="date"
                                    name="start_date"
                                    class="form-control"
                                    value="<?= e($start_date) ?>"
                                >

                            </div>

                            <div class="col-md-4">

                                <label class="form-label">
                                    End Date
                                </label>

                                <input
                                    type="date"
                                    name="end_date"
                                    class="form-control"
                                    value="<?= e($end_date) ?>"
                                >

                            </div>

                            <div class="col-md-4 d-flex align-items-end">

                                <small class="text-secondary">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Leave either date blank if you do not need that boundary.
                                </small>

                            </div>

                        </div>

                    </div>

                </form>

            </div>

            <!-- RESULT COUNT -->

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">

                <div class="fw-bold text-dark">
                    <i class="bi bi-table text-success me-1"></i>
                    Records
                </div>

                <div class="result-count">
                    <i class="bi bi-funnel me-1"></i>

                    Showing <?= number_format($filtered_records) ?>
                    of <?= number_format($total_records) ?> records
                </div>

            </div>

            <!-- SUBSCRIPTION TABLE -->

            <?php if (count($rows) === 0): ?>

                <div class="empty-state">

                    <i class="bi bi-search"></i>

                    <h6>No Subscription Records Found</h6>

                    <p class="small mb-3">
                        No records matched your search or selected filters.
                    </p>

                    <a href="<?= e($reset_url) ?>" class="btn btn-outline-success btn-sm">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>
                        Clear Filters
                    </a>

                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table table-hover table-striped align-middle">

                        <thead>
                            <tr>
                                <th>Resident</th>
                                <th>Subscription</th>
                                <th>Eco Points</th>
                                <th>Total Due</th>
                                <th>Amount Paid</th>
                                <th>Balance</th>
                                <th>Subscription Status</th>
                                <th>Payment Status</th>
                                <th>Date Created</th>
                                <th style="min-width: 190px;">Administrator Action</th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($rows as $row): ?>

                            <?php

                                $payment_status = $row['payment_status'];
                                $subscription_status = $row['subscription_status'];

                                $payment_badge = 'bg-secondary';

                                if ($payment_status === 'Paid') {
                                    $payment_badge = 'bg-success';

                                } elseif ($payment_status === 'Pending Confirmation') {
                                    $payment_badge = 'bg-warning text-dark';

                                } elseif ($payment_status === 'Unpaid') {
                                    $payment_badge = 'bg-danger';
                                }

                                $subscription_badge = $subscription_status === 'Active'
                                    ? 'bg-success'
                                    : 'bg-secondary';

                            ?>

                            <tr>

                                <!-- RESIDENT -->

                                <td>

                                    <div class="fw-semibold">
                                        <?= e($row['fullname']) ?>
                                    </div>

                                    <small class="text-secondary">
                                        <?= e($row['username']) ?>
                                        · ID <?= (int)$row['user_id'] ?>
                                    </small>

                                </td>

                                <!-- SUBSCRIPTION -->

                                <td>

                                    <?= (int)$row['months'] ?> month(s)

                                    <br>

                                    <small class="text-secondary">
                                        Fee: <?= peso($row['service_fee']) ?>
                                    </small>

                                </td>

                                <!-- ECO POINTS -->

                                <td>

                                    <?= number_format((int)$row['points_redeemed']) ?> point(s)

                                    <br>

                                    <small class="text-secondary">
                                        Discount: <?= peso($row['discount_amount']) ?>
                                    </small>

                                </td>

                                <!-- TOTAL DUE -->

                                <td class="fw-semibold">
                                    <?= peso($row['total_amount']) ?>
                                </td>

                                <!-- AMOUNT PAID -->

                                <td>
                                    <?= peso($row['amount_paid']) ?>
                                </td>

                                <!-- BALANCE -->

                                <td>
                                    <?= peso($row['balance_due']) ?>
                                </td>

                                <!-- SUBSCRIPTION STATUS -->

                                <td>

                                    <span class="badge status-badge <?= $subscription_badge ?>">
                                        <?= e($subscription_status) ?>
                                    </span>

                                </td>

                                <!-- PAYMENT STATUS -->

                                <td>

                                    <span class="badge status-badge <?= $payment_badge ?>">
                                        <?= e($payment_status) ?>
                                    </span>

                                    <?php if ($payment_status === 'Pending Confirmation'): ?>

                                        <div>
                                            <small class="text-secondary">
                                                Cash Simulation
                                            </small>
                                        </div>

                                    <?php endif; ?>

                                </td>

                                <!-- DATE CREATED -->

                                <td>
                                    <?= e(date('M d, Y', strtotime($row['created_at']))) ?>
                                </td>

                                <!-- ADMIN ACTION -->

                                <td>

                                    <?php if (
                                        $payment_status === 'Pending Confirmation' &&
                                        $subscription_status === 'Pending Approval'
                                    ): ?>

                                        <form
                                            method="POST"
                                            class="action-form"
                                            onsubmit="return confirm('Confirm this simulated cash payment and activate the subscription?');"
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= e($_SESSION['subscription_admin_csrf']) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="subscription_id"
                                                value="<?= (int)$row['id'] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="confirm"
                                            >

                                            <button type="submit" class="btn btn-sm btn-success">
                                                <i class="bi bi-check-circle me-1"></i>
                                                Confirm
                                            </button>

                                        </form>

                                        <form
                                            method="POST"
                                            class="action-form"
                                            onsubmit="return confirm('Reject this payment notice? Its payment status will return to Unpaid.');"
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= e($_SESSION['subscription_admin_csrf']) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="subscription_id"
                                                value="<?= (int)$row['id'] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="reject"
                                            >

                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-x-circle me-1"></i>
                                                Reject
                                            </button>

                                        </form>

                                    <?php elseif (
                                        $subscription_status === 'Active' &&
                                        $payment_status === 'Paid'
                                    ): ?>

                                        <span class="text-success small fw-semibold">
                                            <i class="bi bi-check-circle-fill me-1"></i>
                                            Payment confirmed
                                        </span>

                                    <?php else: ?>

                                        <span class="text-secondary small">
                                            No action available
                                        </span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>

<!-- ========================================== -->
<!-- APPROVAL HISTORY MODAL -->
<!-- ========================================== -->

<div
    class="modal fade history-modal"
    id="approvalHistoryModal"
    tabindex="-1"
    aria-labelledby="approvalHistoryLabel"
    aria-hidden="true"
>

    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">

        <div class="modal-content">

            <!-- MODAL HEADER -->

            <div class="modal-header">

                <div>

                    <h5 class="modal-title fw-bold" id="approvalHistoryLabel">
                        <i class="bi bi-clock-history me-2"></i>
                        Subscription Approval History
                    </h5>

                    <small class="text-white-50">
                        Administrator payment confirmation records
                    </small>

                </div>

                <button
                    type="button"
                    class="btn-close btn-close-white"
                    data-bs-dismiss="modal"
                    aria-label="Close"
                ></button>

            </div>

            <!-- MODAL BODY -->

            <div class="modal-body">

                <div class="history-intro">

                    <i class="bi bi-shield-check"></i>

                    <div>
                        <strong>Approval Records</strong>

                        <div>
                            This section displays the administrators who successfully confirmed subscription payments.
                        </div>
                    </div>

                </div>

                <div class="d-flex justify-content-between align-items-center mb-3">

                    <div class="fw-bold text-dark">
                        <i class="bi bi-list-check text-success me-1"></i>
                        Approval Logs
                    </div>

                    <span class="result-count">
                        <?= number_format($history_count) ?> record(s)
                    </span>

                </div>

                <div class="history-table-container">

                    <div class="table-responsive">

                        <table class="table table-hover history-table align-middle">

                            <thead>

                                <tr>
                                    <th>History ID</th>
                                    <th>Subscription ID</th>
                                    <th>Approved By</th>
                                    <th>Approval Date</th>
                                    <th>Approval Time</th>
                                </tr>

                            </thead>

                            <tbody>

                                <?php if ($history_count > 0): ?>

                                    <?php while ($history = $history_result->fetch_assoc()): ?>

                                        <tr>

                                            <td>
                                                <span class="history-id">
                                                    #<?= (int)$history['id'] ?>
                                                </span>
                                            </td>

                                            <td class="fw-semibold text-dark">
                                                #<?= (int)$history['subscription_id'] ?>
                                            </td>

                                            <td>

                                                <div class="d-flex align-items-center">

                                                    <span class="admin-avatar">
                                                        <i class="bi bi-person-fill"></i>
                                                    </span>

                                                    <div>

                                                        <div class="fw-semibold">
                                                            <?= e($history['admin_name'] ?? 'Unknown Admin') ?>
                                                        </div>

                                                        <small class="text-secondary">
                                                            Admin ID: <?= (int)$history['admin_id'] ?>
                                                        </small>

                                                    </div>

                                                </div>

                                            </td>

                                            <td>
                                                <i class="bi bi-calendar3 text-success me-1"></i>

                                                <?= e(date(
                                                    'M d, Y',
                                                    strtotime($history['approved_at'])
                                                )) ?>
                                            </td>

                                            <td>
                                                <i class="bi bi-clock text-success me-1"></i>

                                                <?= e(date(
                                                    'h:i:s A',
                                                    strtotime($history['approved_at'])
                                                )) ?>
                                            </td>

                                        </tr>

                                    <?php endwhile; ?>

                                <?php else: ?>

                                    <tr>

                                        <td colspan="5">

                                            <div class="history-empty">

                                                <i class="bi bi-inbox"></i>

                                                <h6 class="fw-bold mt-3">
                                                    No Approval History Yet
                                                </h6>

                                                <p class="small mb-0">
                                                    Confirmed subscription payments will appear here.
                                                </p>

                                            </div>

                                        </td>

                                    </tr>

                                <?php endif; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            </div>

            <!-- MODAL FOOTER -->

            <div class="modal-footer bg-white">

                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal"
                >
                    <i class="bi bi-x-lg me-1"></i>
                    Close
                </button>

            </div>

        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

document.addEventListener("DOMContentLoaded", function () {

    const dateFilter = document.getElementById("dateFilter");
    const customDateContainer = document.getElementById("customDateContainer");

    if (dateFilter && customDateContainer) {

        dateFilter.addEventListener("change", function () {

            if (this.value === "custom") {
                customDateContainer.style.display = "block";
            } else {
                customDateContainer.style.display = "none";
            }

        });

    }

});

</script>

</body>
</html>
