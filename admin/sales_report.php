
<?php
// =====================================================
// REVIEW SALES - ADMIN DASHBOARD MODULE
// Included inside admin/dashboard.php
// =====================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

// ADMIN AUTHENTICATION
if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["role"] ?? "") !== "admin"
) {
    header("Location: ../index.php");
    exit;
}

// DATABASE CONNECTION
if (!isset($conn) || !($conn instanceof mysqli)) {
    error_log("Review Sales: database connection unavailable.");
    http_response_code(500);
    exit("Unable to connect to the database.");
}

$conn->set_charset("utf8mb4");

// =====================================================
// HELPER FUNCTIONS
// =====================================================

function salesReportEscape($value): string
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function salesReportRunQuery(
    mysqli $conn,
    string $sql,
    string $types,
    array $params
): mysqli_stmt {

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log("Review Sales prepare failed: " . $conn->error);
        exit("Unable to load the sales report.");
    }

    if ($types !== "") {
        $bindArgs = [$types];

        foreach ($params as $key => $value) {
            $bindArgs[] = &$params[$key];
        }

        call_user_func_array([$stmt, "bind_param"], $bindArgs);
    }

    if (!$stmt->execute()) {
        error_log("Review Sales execute failed: " . $stmt->error);
        $stmt->close();
        exit("Unable to load the sales report.");
    }

    return $stmt;
}

function salesReportBadge(string $status): string
{
    $status = strtolower(trim($status));

    if ($status === "paid") {
        return "bg-success";
    }

    if ($status === "unpaid") {
        return "bg-danger";
    }

    if (strpos($status, "pending") !== false) {
        return "bg-warning text-dark";
    }

    return "bg-secondary";
}

// =====================================================
// DATE FILTERS
// =====================================================

$today = new DateTimeImmutable("today");

$timeframe = $_GET["timeframe"] ?? "month";

if (!is_string($timeframe) || !in_array($timeframe, ["week", "month", "custom"], true)) {
    $timeframe = "month";
}

$startDate = $today->modify("first day of this month")->format("Y-m-d");
$endDate = $today->format("Y-m-d");

if ($timeframe === "week") {

    $startDate = $today->modify("monday this week")->format("Y-m-d");
    $endDate = $today->modify("sunday this week")->format("Y-m-d");

} elseif ($timeframe === "custom") {

    $requestedStart = isset($_GET["start_date"]) && is_string($_GET["start_date"])
        ? $_GET["start_date"] : "";

    $requestedEnd = isset($_GET["end_date"]) && is_string($_GET["end_date"])
        ? $_GET["end_date"] : "";

    $startObj = DateTime::createFromFormat("!Y-m-d", $requestedStart);
    $endObj = DateTime::createFromFormat("!Y-m-d", $requestedEnd);

    if (
        $startObj &&
        $startObj->format("Y-m-d") === $requestedStart &&
        $endObj &&
        $endObj->format("Y-m-d") === $requestedEnd &&
        $requestedStart <= $requestedEnd
    ) {
        $startDate = $requestedStart;
        $endDate = $requestedEnd;
    } else {
        $timeframe = "month";
    }
}

// =====================================================
// SEARCH AND STATUS FILTERS
// =====================================================

$search = isset($_GET["search"]) && is_string($_GET["search"])
    ? trim($_GET["search"]) : "";

$statusFilter = isset($_GET["status"]) && is_string($_GET["status"])
    ? trim($_GET["status"]) : "";

// Get existing payment statuses
$statusOptions = [];

$statusResult = $conn->query("
    SELECT DISTINCT payment_status
    FROM subscriptions
    WHERE payment_status IS NOT NULL
    AND payment_status <> ''
    ORDER BY payment_status
");

if (!$statusResult) {
    error_log("Review Sales status query failed: " . $conn->error);
    exit("Unable to load payment statuses.");
}

while ($statusRow = $statusResult->fetch_assoc()) {
    $statusOptions[] = $statusRow["payment_status"];
}

$statusResult->free();

if (
    $statusFilter !== "" &&
    !in_array($statusFilter, $statusOptions, true)
) {
    $statusFilter = "";
}

// =====================================================
// BUILD QUERY CONDITIONS
// =====================================================

$where = ["DATE(s.created_at) BETWEEN ? AND ?"];

$params = [$startDate, $endDate];
$types = "ss";

if ($search !== "") {

    $where[] = "(
        u.fullname LIKE ?
        OR u.users LIKE ?
        OR CAST(s.id AS CHAR) LIKE ?
    )";

    $like = "%" . $search . "%";

    $params[] = $like;
    $params[] = $like;
    $params[] = $like;

    $types .= "sss";
}

if ($statusFilter !== "") {

    $where[] = "s.payment_status = ?";

    $params[] = $statusFilter;
    $types .= "s";
}

$whereSql = "WHERE " . implode(" AND ", $where);

// =====================================================
// SUMMARY INFORMATION
// =====================================================

$summarySql = "
    SELECT
        COALESCE(
            SUM(
                CASE
                    WHEN LOWER(s.payment_status) = 'paid'
                    THEN s.amount_paid
                    ELSE 0
                END
            ), 0
        ) AS revenue_collected,

        COUNT(*) AS transaction_count,

        COALESCE(
            SUM(
                CASE
                    WHEN LOWER(s.payment_status) = 'paid'
                    THEN 1 ELSE 0
                END
            ), 0
        ) AS paid_count,

        COALESCE(
            SUM(
                CASE
                    WHEN LOWER(s.payment_status) = 'unpaid'
                    THEN 1 ELSE 0
                END
            ), 0
        ) AS unpaid_count,

        COALESCE(
            SUM(
                CASE
                    WHEN LOWER(s.payment_status) LIKE '%pending%'
                    THEN 1 ELSE 0
                END
            ), 0
        ) AS pending_count,

        COALESCE(
            SUM(GREATEST(s.balance_due, 0)), 0
        ) AS total_balance

    FROM subscriptions s
    INNER JOIN users u ON u.id = s.user_id

    $whereSql
";

$summaryStmt = salesReportRunQuery(
    $conn,
    $summarySql,
    $types,
    $params
);

$summaryResult = $summaryStmt->get_result();
$summary = $summaryResult->fetch_assoc() ?: [];

$summaryStmt->close();

$revenueCollected = (float)($summary["revenue_collected"] ?? 0);
$transactionCount = (int)($summary["transaction_count"] ?? 0);
$paidCount = (int)($summary["paid_count"] ?? 0);
$unpaidCount = (int)($summary["unpaid_count"] ?? 0);
$pendingCount = (int)($summary["pending_count"] ?? 0);
$totalBalance = (float)($summary["total_balance"] ?? 0);

// =====================================================
// TRANSACTION RECORDS
// =====================================================

$listSql = "
    SELECT
        s.id AS subscription_id,
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
        s.created_at,

        u.fullname,
        u.users AS username

    FROM subscriptions s

    INNER JOIN users u ON u.id = s.user_id

    $whereSql

    ORDER BY s.created_at DESC, s.id DESC
";

$listStmt = salesReportRunQuery(
    $conn,
    $listSql,
    $types,
    $params
);

$listResult = $listStmt->get_result();

$transactions = [];

while ($row = $listResult->fetch_assoc()) {
    $transactions[] = $row;
}

$listStmt->close();

// =====================================================
// CSV DATA
// =====================================================

$csvRows = [];

$csvRows[] = [
    "Subscription ID",
    "Resident Full Name",
    "Username",
    "Service Fee",
    "Months",
    "Original Amount",
    "Points Redeemed",
    "Discount Amount",
    "Total Amount",
    "Amount Paid",
    "Balance Due",
    "Subscription Status",
    "Payment Status",
    "Created At"
];

foreach ($transactions as $row) {

    $csvRows[] = [
        $row["subscription_id"],
        $row["fullname"],
        $row["username"],
        $row["service_fee"],
        $row["months"],
        $row["original_amount"],
        $row["points_redeemed"],
        $row["discount_amount"],
        $row["total_amount"],
        $row["amount_paid"],
        $row["balance_due"],
        $row["subscription_status"],
        $row["payment_status"],
        $row["created_at"]
    ];
}

// Preserve dashboard routing parameters when resetting filters
$routeParams = $_GET;

unset(
    $routeParams["timeframe"],
    $routeParams["start_date"],
    $routeParams["end_date"],
    $routeParams["search"],
    $routeParams["status"],
    $routeParams["export"]
);

$resetUrl = $_SERVER["PHP_SELF"];

if (!empty($routeParams)) {
    $resetUrl .= "?" . http_build_query($routeParams);
}

?>

<style>

/* =====================================================
   REVIEW SALES - DESIGN
   ===================================================== */

.sales-report {
    color: #263b32;
    width: 100%;
}

.sales-report .report-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 25px;
}

.sales-report .report-title {
    color: #183d2b;
    font-size: 25px;
    font-weight: 800;
    margin-bottom: 5px;
}

.sales-report .report-subtitle {
    color: #7b8981;
    font-size: 13px;
    margin: 0;
}

.sales-report .report-date {
    background: #e8f5ed;
    color: #198754;
    padding: 9px 14px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
}

.sales-report .summary-card {
    background: #ffffff;
    border: 1px solid #edf1ee;
    border-radius: 13px;
    padding: 20px;
    height: 100%;
    box-shadow: 0 3px 12px rgba(0,0,0,.035);
    transition: .2s ease;
}

.sales-report .summary-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 18px rgba(0,0,0,.07);
}

.sales-report .summary-label {
    color: #819087;
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 8px;
}

.sales-report .summary-value {
    color: #203d2e;
    font-size: 23px;
    font-weight: 800;
    word-break: break-word;
}

.sales-report .summary-icon {
    width: 45px;
    height: 45px;
    min-width: 45px;
    display: flex;
    justify-content: center;
    align-items: center;
    border-radius: 11px;
    font-size: 21px;
}

.sales-report .icon-green {
    background: #e5f4eb;
    color: #198754;
}

.sales-report .icon-blue {
    background: #e8f1fb;
    color: #2875bb;
}

.sales-report .icon-orange {
    background: #fff1df;
    color: #e58b20;
}

.sales-report .icon-red {
    background: #fdeaea;
    color: #d94a4a;
}

.sales-report .report-card {
    background: #fff;
    border: 1px solid #edf1ee;
    border-radius: 13px;
    padding: 22px;
    margin-bottom: 22px;
    box-shadow: 0 3px 12px rgba(0,0,0,.035);
}

.sales-report .section-heading {
    font-size: 16px;
    font-weight: 800;
    color: #244331;
    margin-bottom: 17px;
}

.sales-report .section-heading i {
    color: #198754;
}

.sales-report .form-label {
    color: #52665a;
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 7px;
}

.sales-report .form-control,
.sales-report .form-select {
    min-height: 42px;
    border-radius: 8px;
    border-color: #dfe8e2;
    font-size: 13px;
}

.sales-report .form-control:focus,
.sales-report .form-select:focus {
    border-color: #198754;
    box-shadow: 0 0 0 .18rem rgba(25,135,84,.12);
}

.sales-report .btn {
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    padding: 10px 15px;
}

.sales-report .table-container {
    width: 100%;
    overflow-x: auto;
}

.sales-report .table {
    font-size: 12px;
    vertical-align: middle;
    min-width: 950px;
    margin-bottom: 0;
}

.sales-report .table thead th {
    background: #17452f;
    color: #fff;
    border-color: #17452f;
    padding: 13px 12px;
    white-space: nowrap;
    font-weight: 700;
}

.sales-report .table tbody td {
    padding: 13px 12px;
    border-color: #edf1ee;
}

.sales-report .table tbody tr:hover {
    background: #f6fbf7;
}

.sales-report .resident-name {
    color: #254632;
    font-weight: 800;
}

.sales-report .username {
    color: #8a968f;
    font-size: 11px;
}

.sales-report .money {
    white-space: nowrap;
    font-weight: 700;
}

.sales-report .fee-breakdown {
    line-height: 1.8;
    min-width: 165px;
}

.sales-report .fee-breakdown strong {
    color: #244b34;
}

.sales-report .transaction-id {
    color: #198754;
    font-weight: 800;
}

.sales-report .record-count {
    color: #829087;
    font-size: 12px;
}

.sales-report .empty-state {
    padding: 45px 15px !important;
    text-align: center;
    color: #8a968f;
}

.sales-report .empty-state i {
    display: block;
    font-size: 38px;
    margin-bottom: 10px;
    color: #bdc9c1;
}

.sales-report .report-footer {
    color: #8c9991;
    font-size: 11px;
    text-align: center;
    padding: 15px 0;
}

.sales-report .custom-date {
    display: none;
}

@media (max-width: 768px) {

    .sales-report .report-title {
        font-size: 21px;
    }

    .sales-report .report-card {
        padding: 16px;
    }

    .sales-report .summary-value {
        font-size: 20px;
    }

    .sales-report .report-date {
        width: 100%;
    }
}

@media print {

    .sales-report .filter-card,
    .sales-report .no-print {
        display: none !important;
    }

    .sales-report .summary-card,
    .sales-report .report-card {
        box-shadow: none;
        border: 1px solid #ddd;
    }

    .sales-report .table {
        font-size: 10px;
        min-width: 0;
    }

    .sales-report .table thead th {
        background: #17452f !important;
        color: #fff !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}

</style>

<div class="sales-report">

    <!-- PAGE HEADER -->
    <div class="report-header">

        <div>
            <h2 class="report-title">
                <i class="bi bi-cash-coin text-success me-2"></i>
                Review Sales
            </h2>

            <p class="report-subtitle">
                Monitor subscription payments, collected revenue, and outstanding balances.
            </p>
        </div>

        <div class="report-date">
            <i class="bi bi-calendar3 me-1"></i>
            <?= salesReportEscape(date("M d, Y", strtotime($startDate))) ?>
            –
            <?= salesReportEscape(date("M d, Y", strtotime($endDate))) ?>
        </div>

    </div>

    <!-- SUMMARY CARDS -->
    <div class="row g-3 mb-4">

        <div class="col-12 col-md-6 col-xl-3">
            <div class="summary-card">

                <div class="d-flex justify-content-between align-items-center">

                    <div>
                        <div class="summary-label">Total Revenue Collected</div>

                        <div class="summary-value">
                            ₱<?= number_format($revenueCollected, 2) ?>
                        </div>
                    </div>

                    <div class="summary-icon icon-green">
                        <i class="bi bi-cash-stack"></i>
                    </div>

                </div>

            </div>
        </div>

        <div class="col-12 col-md-6 col-xl-3">
            <div class="summary-card">

                <div class="d-flex justify-content-between align-items-center">

                    <div>
                        <div class="summary-label">Subscription Transactions</div>

                        <div class="summary-value">
                            <?= number_format($transactionCount) ?>
                        </div>
                    </div>

                    <div class="summary-icon icon-blue">
                        <i class="bi bi-receipt"></i>
                    </div>

                </div>

            </div>
        </div>

        <div class="col-12 col-md-6 col-xl-3">
            <div class="summary-card">

                <div class="d-flex justify-content-between align-items-center">

                    <div>
                        <div class="summary-label">Paid Transactions</div>

                        <div class="summary-value">
                            <?= number_format($paidCount) ?>
                        </div>
                    </div>

                    <div class="summary-icon icon-green">
                        <i class="bi bi-check-circle"></i>
                    </div>

                </div>

            </div>
        </div>

        <div class="col-12 col-md-6 col-xl-3">
            <div class="summary-card">

                <div class="d-flex justify-content-between align-items-center">

                    <div>
                        <div class="summary-label">Unpaid / Pending</div>

                        <div class="summary-value">
                            <?= number_format($unpaidCount + $pendingCount) ?>
                        </div>
                    </div>

                    <div class="summary-icon icon-orange">
                        <i class="bi bi-hourglass-split"></i>
                    </div>

                </div>

            </div>
        </div>

    </div>

    <!-- SEARCH AND FILTER -->
    <section class="report-card filter-card">

        <h5 class="section-heading">
            <i class="bi bi-funnel me-2"></i>
            Search and Filter
        </h5>

        <form method="GET" action="<?= salesReportEscape($_SERVER["PHP_SELF"]) ?>" class="row g-3 align-items-end">

            <?php foreach ($routeParams as $key => $value): ?>
                <?php if (is_string($value) || is_numeric($value)): ?>
                    <input
                        type="hidden"
                        name="<?= salesReportEscape($key) ?>"
                        value="<?= salesReportEscape($value) ?>"
                    >
                <?php endif; ?>
            <?php endforeach; ?>

            <div class="col-12 col-md-3">

                <label for="timeframe" class="form-label">Timeframe</label>

                <select
                    name="timeframe"
                    id="timeframe"
                    class="form-select"
                    onchange="salesToggleDates()"
                >
                    <option value="week" <?= $timeframe === "week" ? "selected" : "" ?>>
                        This Week
                    </option>

                    <option value="month" <?= $timeframe === "month" ? "selected" : "" ?>>
                        This Month
                    </option>

                    <option value="custom" <?= $timeframe === "custom" ? "selected" : "" ?>>
                        Custom Date Range
                    </option>
                </select>

            </div>

            <div class="col-12 col-md-2 custom-date">

                <label for="start_date" class="form-label">Start Date</label>

                <input
                    type="date"
                    name="start_date"
                    id="start_date"
                    class="form-control"
                    value="<?= salesReportEscape($startDate) ?>"
                >

            </div>

            <div class="col-12 col-md-2 custom-date">

                <label for="end_date" class="form-label">End Date</label>

                <input
                    type="date"
                    name="end_date"
                    id="end_date"
                    class="form-control"
                    value="<?= salesReportEscape($endDate) ?>"
                >

            </div>

            <div class="col-12 col-md-3">

                <label for="search" class="form-label">Search Resident / ID</label>

                <input
                    type="search"
                    name="search"
                    id="search"
                    class="form-control"
                    placeholder="Name, username, or subscription ID"
                    value="<?= salesReportEscape($search) ?>"
                >

            </div>

            <div class="col-12 col-md-2">

                <label for="status" class="form-label">Payment Status</label>

                <select name="status" id="status" class="form-select">

                    <option value="">All Statuses</option>

                    <?php foreach ($statusOptions as $option): ?>

                        <option
                            value="<?= salesReportEscape($option) ?>"
                            <?= $statusFilter === $option ? "selected" : "" ?>
                        >
                            <?= salesReportEscape($option) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="col-12 d-flex flex-wrap gap-2">

                <button type="submit" class="btn btn-success">
                    <i class="bi bi-search me-1"></i>
                    Apply Filters
                </button>

                <a href="<?= salesReportEscape($resetUrl) ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>
                    Reset
                </a>

                <div class="ms-md-auto d-flex flex-wrap gap-2 no-print">

                    <button
                        type="button"
                        class="btn btn-outline-success"
                        onclick="salesDownloadCSV()"
                    >
                        <i class="bi bi-file-earmark-spreadsheet me-1"></i>
                        Export CSV
                    </button>

                    <button
                        type="button"
                        class="btn btn-outline-dark"
                        onclick="window.print()"
                    >
                        <i class="bi bi-printer me-1"></i>
                        Print
                    </button>

                </div>

            </div>

        </form>

    </section>

    <!-- TRANSACTION TABLE -->
    <section class="report-card">

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">

            <div>
                <h5 class="section-heading mb-1">
                    <i class="bi bi-receipt-cutoff me-2"></i>
                    Subscription Transactions
                </h5>

                <div class="record-count">
                    <?= number_format(count($transactions)) ?> record(s) found
                </div>
            </div>

            <span class="badge bg-light text-success border px-3 py-2">
                <i class="bi bi-calendar-check me-1"></i>
                <?= salesReportEscape($timeframe === "week" ? "Weekly Report" : ($timeframe === "custom" ? "Custom Report" : "Monthly Report")) ?>
            </span>

        </div>

        <div class="table-container">

            <table class="table table-bordered table-hover">

                <thead>
                    <tr>
                        <th>Subscription ID</th>
                        <th>Resident</th>
                        <th>Package / Fee Breakdown</th>
                        <th>Amount Paid</th>
                        <th>Balance Due</th>
                        <th>Payment Status</th>
                        <th>Date Created</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($transactions)): ?>

                    <tr>
                        <td colspan="7" class="empty-state">

                            <i class="bi bi-inbox"></i>

                            <strong>No transactions found</strong>

                            <div class="small mt-1">
                                Try changing your search or filter settings.
                            </div>

                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($transactions as $transaction): ?>

                        <?php
                        $status = (string)($transaction["payment_status"] ?? "");
                        ?>

                        <tr>

                            <td class="transaction-id">
                                #<?= (int)$transaction["subscription_id"] ?>
                            </td>

                            <td>

                                <div class="resident-name">
                                    <?= salesReportEscape($transaction["fullname"]) ?>
                                </div>

                                <div class="username">
                                    @<?= salesReportEscape($transaction["username"]) ?>
                                </div>

                            </td>

                            <td>

                                <div class="fee-breakdown">

                                    <div>
                                        Service Fee:
                                        <strong>
                                            ₱<?= number_format((float)$transaction["service_fee"], 2) ?>
                                        </strong>
                                    </div>

                                    <div>
                                        Months:
                                        <?= (int)$transaction["months"] ?>
                                    </div>

                                    <div>
                                        Original Amount:
                                        ₱<?= number_format((float)$transaction["original_amount"], 2) ?>
                                    </div>

                                    <div>
                                        Points Redeemed:
                                        <?= (int)$transaction["points_redeemed"] ?>
                                    </div>

                                    <div>
                                        Discount:
                                        ₱<?= number_format((float)$transaction["discount_amount"], 2) ?>
                                    </div>

                                    <div>
                                        Total:
                                        <strong>
                                            ₱<?= number_format((float)$transaction["total_amount"], 2) ?>
                                        </strong>
                                    </div>

                                </div>

                            </td>

                            <td class="money">
                                ₱<?= number_format((float)$transaction["amount_paid"], 2) ?>
                            </td>

                            <td class="money">
                                ₱<?= number_format((float)$transaction["balance_due"], 2) ?>
                            </td>

                            <td>

                                <span class="badge <?= salesReportEscape(salesReportBadge($status)) ?>">
                                    <?= salesReportEscape($status !== "" ? $status : "Not Set") ?>
                                </span>

                            </td>

                            <td class="text-nowrap">

                                <?= salesReportEscape(date("M d, Y", strtotime($transaction["created_at"]))) ?>

                                <div class="username">
                                    <?= salesReportEscape(date("h:i A", strtotime($transaction["created_at"]))) ?>
                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">

            <div class="record-count">
                Showing <?= number_format(count($transactions)) ?> subscription record(s).
            </div>

            <div class="small text-muted">
                Outstanding Balance:
                <strong class="text-danger">
                    ₱<?= number_format($totalBalance, 2) ?>
                </strong>
            </div>

        </div>

        <div class="small text-muted mt-2">
            Revenue includes only records marked Paid and uses subscriptions.amount_paid.
        </div>

    </section>

    <div class="report-footer">
        Waste Collection Management System by Group 2
        &copy; <?= date("Y") ?>
        <br>
        Admin Dashboard – Review Sales
    </div>

</div>

<script>

// =====================================================
// CUSTOM DATE FILTER
// =====================================================

function salesToggleDates() {

    const timeframe = document.getElementById("timeframe");

    if (!timeframe) return;

    const isCustom = timeframe.value === "custom";

    document.querySelectorAll(".sales-report .custom-date").forEach(function(element) {
        element.style.display = isCustom ? "block" : "none";
    });
}

salesToggleDates();


// =====================================================
// CSV EXPORT
// Exports the currently filtered transaction records.
// =====================================================

const salesCSVData = <?= json_encode(
    $csvRows,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
) ?>;

function salesDownloadCSV() {

    let csvContent = salesCSVData.map(function(row) {

        return row.map(function(value) {

            let text = String(value ?? "");

            // Prevent spreadsheet formula injection
            if (/^[\s]*[=+\-@]/.test(text)) {
                text = "'" + text;
            }

            return '"' + text.replace(/"/g, '""') + '"';

        }).join(",");

    }).join("\r\n");

    const blob = new Blob(
        ["\uFEFF" + csvContent],
        { type: "text/csv;charset=utf-8;" }
    );

    const url = URL.createObjectURL(blob);

    const link = document.createElement("a");

    link.href = url;

    link.download =
        "sales-report-<?= salesReportEscape($startDate) ?>-to-<?= salesReportEscape($endDate) ?>.csv";

    document.body.appendChild(link);

    link.click();

    document.body.removeChild(link);

    URL.revokeObjectURL(url);
}

</script>
