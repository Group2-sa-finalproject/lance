<?php
session_start();
require_once __DIR__ . '/../config/database.php';

/*
|--------------------------------------------------------------------------
| RESIDENT LOGIN VALIDATION
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'residents'
) {
    header("Location: ../index.php");
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection is unavailable.");
}

mysqli_set_charset($conn, "utf8mb4");

$user_id = (int)$_SESSION['user_id'];

$monthly_fee = 150.00;

$errors = [];
$success = '';

/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['subscription_csrf'])) {
    $_SESSION['subscription_csrf'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['subscription_csrf'];

/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function peso($amount): string
{
    return '₱' . number_format((float)$amount, 2);
}

function escapeHtml($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| GET RESIDENT INFORMATION
|--------------------------------------------------------------------------
*/

$user_sql = $conn->prepare("
    SELECT id, fullname
    FROM users
    WHERE id = ?
      AND role = 'residents'
    LIMIT 1
");

if (!$user_sql) {
    die("Unable to verify resident account.");
}

$user_sql->bind_param("i", $user_id);
$user_sql->execute();

$user = $user_sql->get_result()->fetch_assoc();

$user_sql->close();

if (!$user) {
    session_unset();
    session_destroy();

    header("Location: ../index.php");
    exit;
}

$resident_name = $user['fullname'];

/*
|--------------------------------------------------------------------------
| HANDLE FORM SUBMISSIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($postedToken) ||
        !hash_equals($csrf, $postedToken)
    ) {

        $errors[] = "Your session token is invalid. Refresh the page and try again.";

    } else {

        $action = $_POST['action'] ?? '';

        /*
        |--------------------------------------------------------------------------
        | APPLY FOR SUBSCRIPTION WITH ECO POINTS
        |--------------------------------------------------------------------------
        */

        if ($action === 'create_subscription') {

            try {

                $conn->begin_transaction();

                /*
                * Lock resident account.
                */

                $userLock = $conn->prepare("
                    SELECT id
                    FROM users
                    WHERE id = ?
                    LIMIT 1
                    FOR UPDATE
                ");

                if (!$userLock) {
                    throw new RuntimeException(
                        "Unable to verify your account."
                    );
                }

                $userLock->bind_param("i", $user_id);
                $userLock->execute();

                $userExists = $userLock->get_result()->fetch_assoc();

                $userLock->close();

                if (!$userExists) {
                    throw new RuntimeException(
                        "Resident account was not found."
                    );
                }

                /*
                * Check existing subscription.
                */

                $existingStmt = $conn->prepare("
                    SELECT id
                    FROM subscriptions
                    WHERE user_id = ?
                    LIMIT 1
                    FOR UPDATE
                ");

                if (!$existingStmt) {
                    throw new RuntimeException(
                        "Unable to check your subscription."
                    );
                }

                $existingStmt->bind_param("i", $user_id);
                $existingStmt->execute();

                $existingSubscription = $existingStmt
                    ->get_result()
                    ->fetch_assoc();

                $existingStmt->close();

                if ($existingSubscription) {

                    throw new RuntimeException(
                        "You already have a subscription record. Please check your Subscription and Billing Details."
                    );

                }

                /*
                |--------------------------------------------------------------------------
                | GET RESERVED ECO POINTS
                |--------------------------------------------------------------------------
                |
                | These records are created by eco_points.php.
                | Do not create another redemption record here.
                |
                */

                $reservedStmt = $conn->prepare("
                    SELECT
                        id,
                        points_redeemed,
                        discount_amount
                    FROM subscription_point_redemptions
                    WHERE user_id = ?
                      AND subscription_id IS NULL
                    ORDER BY id ASC
                    FOR UPDATE
                ");

                if (!$reservedStmt) {
                    throw new RuntimeException(
                        "Unable to retrieve your reserved Eco Points."
                    );
                }

                $reservedStmt->bind_param("i", $user_id);
                $reservedStmt->execute();

                $reservedResult = $reservedStmt->get_result();

                $reserved_ids = [];

                $points_used = 0;
                $discount = 0.00;

                while ($row = $reservedResult->fetch_assoc()) {

                    $row_points = (int)$row['points_redeemed'];

                    $row_discount = (float)$row['discount_amount'];

                    if (
                        $row_points <= 0 ||
                        $row_discount < 0
                    ) {

                        throw new RuntimeException(
                            "Your Eco Points redemption records contain invalid values. Please contact the administrator."
                        );

                    }

                    $reserved_ids[] = (int)$row['id'];

                    $points_used += $row_points;

                    $discount += $row_discount;
                }

                $reservedStmt->close();

                /*
                |--------------------------------------------------------------------------
                | VALIDATE ECO POINTS
                |--------------------------------------------------------------------------
                |
                | 1 Eco Point = ₱1.00
                | Maximum discount = ₱150
                |
                */

                if (
                    $points_used > 150 ||
                    $discount > 150
                ) {

                    throw new RuntimeException(
                        "Your reserved Eco Points exceed the maximum ₱150 subscription discount. Please contact the administrator."
                    );

                }

                if (abs($discount - $points_used) > 0.001) {

                    throw new RuntimeException(
                        "Your Eco Points and discount records do not match. Please contact the administrator."
                    );

                }

                /*
                |--------------------------------------------------------------------------
                | CALCULATE BILLING
                |--------------------------------------------------------------------------
                */

                $original_amount = $monthly_fee;

                $total_due = max(
                    0,
                    $original_amount - $discount
                );

                $amount_paid = 0.00;

                $balance_due = $total_due;

                $months = 1;

                $service_fee = $monthly_fee;

                /*
                |--------------------------------------------------------------------------
                | INSERT SUBSCRIPTION
                |--------------------------------------------------------------------------
                */

                $insert = $conn->prepare("
                    INSERT INTO subscriptions (
                        user_id,
                        service_fee,
                        months,
                        original_amount,
                        points_redeemed,
                        discount_amount,
                        total_amount,
                        amount_paid,
                        balance_due,
                        subscription_status,
                        payment_status,
                        created_at
                    )
                    VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        'Pending Approval',
                        'Unpaid',
                        NOW()
                    )
                ");

                if (!$insert) {

                    throw new RuntimeException(
                        "Unable to create your subscription application."
                    );

                }

                $insert->bind_param(
                    "idididddd",
                    $user_id,
                    $service_fee,
                    $months,
                    $original_amount,
                    $points_used,
                    $discount,
                    $total_due,
                    $amount_paid,
                    $balance_due
                );

                if (!$insert->execute()) {

                    throw new RuntimeException(
                        "Unable to save your subscription application."
                    );

                }

                $subscription_id = (int)$conn->insert_id;

                $insert->close();

                /*
                |--------------------------------------------------------------------------
                | ATTACH RESERVED ECO POINTS TO SUBSCRIPTION
                |--------------------------------------------------------------------------
                |
                | Existing redemption records are connected to
                | the newly created subscription.
                |
                */

                if (!empty($reserved_ids)) {

                    $attachStmt = $conn->prepare("
                        UPDATE subscription_point_redemptions
                        SET subscription_id = ?
                        WHERE user_id = ?
                          AND subscription_id IS NULL
                    ");

                    if (!$attachStmt) {

                        throw new RuntimeException(
                            "Unable to attach your reserved Eco Points."
                        );

                    }

                    $attachStmt->bind_param(
                        "ii",
                        $subscription_id,
                        $user_id
                    );

                    if (!$attachStmt->execute()) {

                        throw new RuntimeException(
                            "Unable to attach your reserved Eco Points."
                        );

                    }

                    $attached_count = $attachStmt->affected_rows;

                    $attachStmt->close();

                    if ($attached_count !== count($reserved_ids)) {

                        throw new RuntimeException(
                            "Not all reserved Eco Points could be attached. Please try again."
                        );

                    }

                }

                /*
                |--------------------------------------------------------------------------
                | COMMIT TRANSACTION
                |--------------------------------------------------------------------------
                */

                $conn->commit();

                $success = "
                    Your subscription application has been submitted successfully.
                    Your previously reserved Eco Points have been automatically applied.
                    You can review your billing details below.
                ";

                $_SESSION['subscription_csrf'] = bin2hex(
                    random_bytes(32)
                );

                $csrf = $_SESSION['subscription_csrf'];

            } catch (Throwable $e) {

                $conn->rollback();

                $errors[] = $e instanceof RuntimeException
                    ? $e->getMessage()
                    : "Unable to process your subscription application. Please try again.";

            }

        }

        /*
        |--------------------------------------------------------------------------
        | CASH SIMULATION PAYMENT NOTICE
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'submit_cash_notice') {

            try {

                $conn->begin_transaction();

                $lock = $conn->prepare("
                    SELECT
                        id,
                        total_amount,
                        balance_due,
                        subscription_status,
                        payment_status
                    FROM subscriptions
                    WHERE user_id = ?
                    LIMIT 1
                    FOR UPDATE
                ");

                if (!$lock) {

                    throw new RuntimeException(
                        "Unable to check your subscription."
                    );

                }

                $lock->bind_param("i", $user_id);
                $lock->execute();

                $current = $lock->get_result()->fetch_assoc();

                $lock->close();

                if (!$current) {

                    throw new RuntimeException(
                        "No subscription record was found."
                    );

                }

                if (
                    $current['subscription_status'] !== 'Pending Approval'
                ) {

                    throw new RuntimeException(
                        "Your subscription is not awaiting payment notice."
                    );

                }

                if (
                    $current['payment_status'] !== 'Unpaid'
                ) {

                    throw new RuntimeException(
                        "Your payment notice has already been submitted or processed."
                    );

                }

                if (
                    (float)$current['total_amount'] <= 0 ||
                    (float)$current['balance_due'] <= 0
                ) {

                    throw new RuntimeException(
                        "There is no remaining amount to submit for cash payment."
                    );

                }

                /*
                * Update payment status.
                */

                $update = $conn->prepare("
                    UPDATE subscriptions
                    SET payment_status = 'Pending Confirmation'
                    WHERE id = ?
                      AND payment_status = 'Unpaid'
                      AND subscription_status = 'Pending Approval'
                ");

                if (!$update) {

                    throw new RuntimeException(
                        "Unable to submit your payment notice."
                    );

                }

                $subscription_id = (int)$current['id'];

                $update->bind_param(
                    "i",
                    $subscription_id
                );

                $update->execute();

                $changed = $update->affected_rows;

                $update->close();

                if ($changed !== 1) {

                    throw new RuntimeException(
                        "Your payment notice could not be submitted. Please refresh and try again."
                    );

                }

                $conn->commit();

                $success = "
                    Your Cash Simulation payment notice has been submitted successfully.
                    You may now print your Statement of Account and present it to the cashier.
                ";

                $_SESSION['subscription_csrf'] = bin2hex(
                    random_bytes(32)
                );

                $csrf = $_SESSION['subscription_csrf'];

            } catch (Throwable $e) {

                $conn->rollback();

                $errors[] = $e instanceof RuntimeException
                    ? $e->getMessage()
                    : "Unable to submit your payment notice. Please try again.";

            }

        }

        else {

            $errors[] = "Invalid request.";

        }
    }
}

/*
|--------------------------------------------------------------------------
| RETRIEVE AVAILABLE ECO POINTS
|--------------------------------------------------------------------------
*/

$available_points = 0;

$earned_points = 0;

$redeemed_points = 0;

$reserved_points = 0;

$reserved_discount = 0.00;

/*
 * Available points =
 * Total earned points - all reserved/redeemed points.
 */

$availableStmt = $conn->prepare("
    SELECT
        COALESCE((
            SELECT SUM(points)
            FROM eco_points
            WHERE user_id = ?
        ), 0) AS earned_points,

        COALESCE((
            SELECT SUM(points_redeemed)
            FROM subscription_point_redemptions
            WHERE user_id = ?
        ), 0) AS redeemed_points
");

if ($availableStmt) {

    $availableStmt->bind_param(
        "ii",
        $user_id,
        $user_id
    );

    $availableStmt->execute();

    $availableRow = $availableStmt
        ->get_result()
        ->fetch_assoc();

    $earned_points = (int)$availableRow['earned_points'];

    $redeemed_points = (int)$availableRow['redeemed_points'];

    $available_points = max(
        0,
        $earned_points - $redeemed_points
    );

    $availableStmt->close();
}

/*
|--------------------------------------------------------------------------
| RETRIEVE RESERVED ECO POINTS
|--------------------------------------------------------------------------
*/

$reservedStmt = $conn->prepare("
    SELECT
        COALESCE(SUM(points_redeemed), 0) AS reserved_points,
        COALESCE(SUM(discount_amount), 0) AS reserved_discount
    FROM subscription_point_redemptions
    WHERE user_id = ?
      AND subscription_id IS NULL
");

if ($reservedStmt) {

    $reservedStmt->bind_param("i", $user_id);

    $reservedStmt->execute();

    $reservedRow = $reservedStmt
        ->get_result()
        ->fetch_assoc();

    $reserved_points = (int)$reservedRow['reserved_points'];

    $reserved_discount = (float)$reservedRow['reserved_discount'];

    $reservedStmt->close();
}

/*
|--------------------------------------------------------------------------
| RETRIEVE SUBSCRIPTION DETAILS
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        id,
        service_fee,
        months,
        original_amount,
        points_redeemed,
        discount_amount,
        total_amount,
        amount_paid,
        balance_due,
        subscription_status,
        payment_status,
        created_at
    FROM subscriptions
    WHERE user_id = ?
    LIMIT 1
");

if (!$stmt) {
    die("Unable to load your subscription record.");
}

$stmt->bind_param("i", $user_id);

$stmt->execute();

$subscription = $stmt->get_result()->fetch_assoc();

$stmt->close();

/*
|--------------------------------------------------------------------------
| RETRIEVE LATEST PAYMENT RECORD
|--------------------------------------------------------------------------
|
| This is kept separate from subscriptions.payment_status.
| It displays the latest record from resident_payments.
|
*/

$payment = null;

$payment_status = "Unpaid";

$payment_amount = 0.00;

$paid_at = null;

$payment_sql = $conn->prepare("
    SELECT amount, status, paid_at
    FROM resident_payments
    WHERE user_id = ?
    ORDER BY id DESC
    LIMIT 1
");

if ($payment_sql) {

    $payment_sql->bind_param("i", $user_id);

    $payment_sql->execute();

    $payment = $payment_sql
        ->get_result()
        ->fetch_assoc();

    $payment_sql->close();

    if ($payment) {

        $payment_status = $payment['status'];

        $payment_amount = (float)$payment['amount'];

        $paid_at = $payment['paid_at'];

    }
}

/*
|--------------------------------------------------------------------------
| SUBSCRIPTION PAYMENT CONDITIONS
|--------------------------------------------------------------------------
*/

$can_print_soa = $subscription && in_array(
    $subscription['payment_status'],
    ['Pending Confirmation', 'Paid'],
    true
);

$is_pending_payment = $subscription &&
    $subscription['payment_status'] === 'Pending Confirmation';

$is_paid = $subscription &&
    $subscription['payment_status'] === 'Paid';

$can_submit_cash_notice = $subscription &&
    $subscription['subscription_status'] === 'Pending Approval' &&
    $subscription['payment_status'] === 'Unpaid' &&
    (float)$subscription['balance_due'] > 0;

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Waste Collection Subscription</title>

    <link
        rel="stylesheet"
        href="../assets/vendor/bootstrap/css/bootstrap.min.css"
    >

    <style>

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Arial, sans-serif;
            background: #f4f7f4;
            color: #26332b;
        }

        /* Navigation bar */

        .navbar {
            background: #198754;
        }

        .navbar-brand {
            font-weight: bold;
            white-space: normal;
        }

        /* Main container */

        .subscription-container {
            max-width: 1000px;
            margin: 35px auto;
            padding: 0 20px;
        }

        /* Cards */

        .subscription-card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.08);
            border: 1px solid #eeeeee;
            margin-bottom: 25px;
        }

        /* Headings */

        .subscription-card h2,
        .subscription-card h4 {
            color: #198754;
            font-weight: bold;
        }

        .subscription-card h2 {
            margin-bottom: 15px;
        }

        .subscription-card h4 {
            margin-top: 20px;
            margin-bottom: 15px;
        }

        /* Service fee */

        .service-fee {
            background: #f1f8f4;
            border: 1px solid #d5eadd;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            margin: 20px 0;
        }

        .service-fee h2 {
            font-size: 32px;
            margin: 5px 0;
        }

        /* Eco points */

        .eco-box {
            background: #f1f8f4;
            border: 1px solid #d5eadd;
            border-radius: 10px;
            padding: 18px;
            height: 100%;
        }

        .eco-box h3 {
            color: #198754;
            font-weight: bold;
            margin-top: 8px;
        }

        /* Buttons */

        .btn-subscribe {
            width: 100%;
            background: #198754;
            color: white;
            padding: 12px;
            border: none;
            border-radius: 6px;
            font-weight: bold;
        }

        .btn-subscribe:hover {
            background: #146c43;
            color: white;
        }

        .btn-dashboard {
            color: #198754;
            border: 1px solid #198754;
        }

        .btn-dashboard:hover {
            background: #198754;
            color: white;
        }

        .btn-green {
            background: #198754;
            color: white;
            border: none;
        }

        .btn-green:hover {
            background: #146c43;
            color: white;
        }

        /* Notices */

        .notice {
            background: #fff7df;
            color: #76520b;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
        }

        /* Billing table */

        .billing-table td,
        .billing-table th {
            padding: 12px;
            vertical-align: middle;
        }

        /* Statement of Account */

        #soa {
            background: white;
        }

        /* Mobile layout */

        @media (max-width: 576px) {

            .subscription-container {
                margin: 20px auto;
                padding: 0 12px;
            }

            .subscription-card {
                padding: 20px;
            }

            .navbar-brand {
                font-size: 15px;
                max-width: 70%;
            }

        }

        /* PRINT ONLY THE STATEMENT OF ACCOUNT */

        @media print {

            @page {
                size: A4;
                margin: 15mm;
            }

            body {
                background: white !important;
            }

            body * {
                visibility: hidden !important;
            }

            #soa,
            #soa * {
                visibility: visible !important;
            }

            #soa {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                margin: 0;
                padding: 20px;
                box-shadow: none;
                border: 1px solid #ccc;
                border-radius: 0;
            }

            .no-print {
                display: none !important;
            }

            #soa h1,
            #soa h2 {
                color: #000 !important;
            }

            #soa table {
                width: 100%;
            }

            #soa td {
                padding: 10px;
                border-bottom: 1px solid #ccc;
            }

        }

    </style>

</head>

<body>

<!--
|--------------------------------------------------------------------------
| NAVIGATION BAR
|--------------------------------------------------------------------------
-->

<nav class="navbar navbar-dark no-print">

    <div class="container">

        <span class="navbar-brand">
            Waste Collection Management System
        </span>

        <a href="../logout.php" class="btn btn-light">
            Logout
        </a>

    </div>

</nav>


<div class="subscription-container">

    <!--
    |--------------------------------------------------------------------------
    | MAIN SUBSCRIPTION INFORMATION
    |--------------------------------------------------------------------------
    -->

    <div class="subscription-card no-print">

        <h2>Waste Collection Subscription</h2>

        <p>
            Welcome,
            <?= escapeHtml($resident_name) ?>!
        </p>

        <hr>

        <h4>Service Information</h4>

        <p>
            Subscribe your household to the waste collection service.
            Your subscription will be reviewed and approved by the administrator.
        </p>

        <div class="service-fee">

            <p class="mb-1">Monthly Subscription Fee</p>

            <h2>
                <?= peso($monthly_fee) ?>
            </h2>

            <small class="text-muted">
                Per month
            </small>

        </div>

        <hr>

        <h4>Subscription Terms</h4>

        <ul>

            <li>
                The service is intended for registered resident households.
            </li>

            <li>
                The monthly service fee is ₱150.00.
            </li>

            <li>
                Administrator approval is required before the service begins.
            </li>

            <li>
                Payment must be recorded before the service can begin.
            </li>

            <li>
                Submitting a subscription does not automatically activate the service.
            </li>

            <li>
                Eligible Eco Points can be used as a discount on your subscription.
            </li>

        </ul>

        <hr>

        <h4>Payment Instructions</h4>

        <p>
            After applying for a subscription, review your billing details below.
            You may submit your Cash Simulation Payment Notice when you have
            completed the simulated payment.
        </p>

        <p>
            Your payment will remain pending until the administrator confirms it.
        </p>

    </div>


    <!--
    |--------------------------------------------------------------------------
    | SUCCESS MESSAGES
    |--------------------------------------------------------------------------
    -->

    <?php foreach ($errors as $error): ?>

        <div class="alert alert-danger no-print">

            <?= escapeHtml($error) ?>

        </div>

    <?php endforeach; ?>


    <?php if (!empty($success)): ?>

        <div class="alert alert-success no-print">

            <?= escapeHtml($success) ?>

        </div>

    <?php endif; ?>


    <!--
    |--------------------------------------------------------------------------
    | SUBSCRIPTION APPLICATION
    |--------------------------------------------------------------------------
    -->

    <?php if (!$subscription): ?>

        <div class="subscription-card no-print">

            <h4>Apply for Subscription</h4>

            <p>
                Review your Eco Points and billing information before submitting
                your subscription application.
            </p>

            <!-- ECO POINTS INFORMATION -->

            <div class="row g-3 mt-2">

                <div class="col-md-4">

                    <div class="eco-box">

                        <span class="text-muted">
                            Available Eco Points
                        </span>

                        <h3>
                            <?= number_format($available_points) ?>
                        </h3>

                    </div>

                </div>


                <div class="col-md-4">

                    <div class="eco-box">

                        <span class="text-muted">
                            Reserved Eco Points
                        </span>

                        <h3>
                            <?= number_format($reserved_points) ?>
                        </h3>

                    </div>

                </div>


                <div class="col-md-4">

                    <div class="eco-box">

                        <span class="text-muted">
                            Duration
                        </span>

                        <h3>
                            1 Month
                        </h3>

                    </div>

                </div>

            </div>


            <!-- ECO POINTS DISCOUNT INFORMATION -->

            <div class="notice">

                <strong>Eco Points Discount</strong>

                <p class="mb-1 mt-2">

                    1 Eco Point = ₱1.00 discount.

                </p>

                <p class="mb-1">

                    Maximum discount for one month: ₱150.

                </p>

                <p class="mb-0">

                    Your previously reserved Eco Points will automatically
                    be applied when you submit your subscription application.

                </p>

            </div>


            <!-- BILLING PREVIEW -->

            <h4 class="mt-4">
                Billing Summary
            </h4>

            <table class="table table-bordered billing-table mt-3">

                <tr>

                    <th>
                        Monthly Subscription Fee
                    </th>

                    <td class="text-end">
                        <?= peso($monthly_fee) ?>
                    </td>

                </tr>


                <tr>

                    <th>
                        Reserved Eco Points
                    </th>

                    <td class="text-end">
                        <?= number_format($reserved_points) ?> Points
                    </td>

                </tr>


                <tr>

                    <th>
                        Eco Points Discount
                    </th>

                    <td class="text-end">
                        <?= peso($reserved_discount) ?>
                    </td>

                </tr>


                <tr>

                    <th>
                        Total Amount Due
                    </th>

                    <td class="text-end">

                        <strong>

                            <?= peso(
                                max(
                                    0,
                                    $monthly_fee - $reserved_discount
                                )
                            ) ?>

                        </strong>

                    </td>

                </tr>

            </table>


            <!-- APPLY BUTTON -->

            <form
                method="POST"
                action="subscription.php"
                onsubmit="return confirm('Submit your subscription application? Your reserved Eco Points will automatically be applied.');"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= escapeHtml($csrf) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="create_subscription"
                >

                <button
                    type="submit"
                    class="btn btn-subscribe"
                >

                    Apply for Subscription

                </button>

            </form>


            <!-- ECO POINTS PAGE BUTTON -->

            <div class="mt-3">

                <a
                    href="eco_points.php"
                    class="btn btn-outline-success"
                >

                    View / Redeem Eco Points

                </a>

            </div>

        </div>


    <?php else: ?>


        <!--
        |--------------------------------------------------------------------------
        | EXISTING SUBSCRIPTION DETAILS
        |--------------------------------------------------------------------------
        -->

        <div class="subscription-card no-print">

            <h4>Your Subscription Details</h4>

            <div class="row g-3 mt-2">

                <div class="col-md-4">

                    <div class="eco-box">

                        <span class="text-muted">
                            Subscription ID
                        </span>

                        <h3>
                            #<?= (int)$subscription['id'] ?>
                        </h3>

                    </div>

                </div>


                <div class="col-md-4">

                    <div class="eco-box">

                        <span class="text-muted">
                            Subscription Status
                        </span>

                        <h3 style="font-size:18px">

                            <?= escapeHtml(
                                $subscription['subscription_status']
                            ) ?>

                        </h3>

                    </div>

                </div>


                <div class="col-md-4">

                    <div class="eco-box">

                        <span class="text-muted">
                            Payment Status
                        </span>

                        <h3 style="font-size:18px">

                            <?= escapeHtml(
                                $subscription['payment_status']
                            ) ?>

                        </h3>

                    </div>

                </div>

            </div>


            <!-- BILLING TABLE -->

            <h4 class="mt-4">
                Subscription and Billing Details
            </h4>

            <table class="table table-bordered billing-table mt-3">

                <tr>

                    <th>
                        Date Submitted
                    </th>

                    <td>
                        <?= escapeHtml($subscription['created_at']) ?>
                    </td>

                </tr>


                <tr>

                    <th>
                        Duration
                    </th>

                    <td>
                        <?= (int)$subscription['months'] ?> month(s)
                    </td>

                </tr>


                <tr>

                    <th>
                        Monthly Subscription Fee
                    </th>

                    <td>
                        <?= peso($subscription['original_amount']) ?>
                    </td>

                </tr>


                <tr>

                    <th>
                        Eco Points Redeemed
                    </th>

                    <td>
                        <?= number_format(
                            (int)$subscription['points_redeemed']
                        ) ?>
                    </td>

                </tr>


                <tr>

                    <th>
                        Eco Points Discount
                    </th>

                    <td>
                        <?= peso($subscription['discount_amount']) ?>
                    </td>

                </tr>


                <tr>

                    <th>
                        Total Subscription Amount
                    </th>

                    <td>
                        <?= peso($subscription['total_amount']) ?>
                    </td>

                </tr>


                <tr>

                    <th>
                        Amount Paid
                    </th>

                    <td>
                        <?= peso($subscription['amount_paid']) ?>
                    </td>

                </tr>


                <tr>

                    <th>
                        Remaining Balance
                    </th>

                    <td>

                        <strong>

                            <?= peso($subscription['balance_due']) ?>

                        </strong>

                    </td>

                </tr>

            </table>


            <!--
            |--------------------------------------------------------------------------
            | SUBSCRIPTION STATUS MESSAGES
            |--------------------------------------------------------------------------
            -->

            <?php if (
                $subscription['subscription_status'] === 'Pending Approval'
            ): ?>

                <div class="alert alert-warning">

                    Your subscription is waiting for administrator approval.

                </div>

            <?php elseif (
                $subscription['subscription_status'] === 'Rejected'
            ): ?>

                <div class="alert alert-danger">

                    Your subscription has been rejected.
                    Please contact the administrator.

                </div>

            <?php elseif (
                $subscription['subscription_status'] === 'Active'
            ): ?>

                <div class="alert alert-success">

                    Your subscription is active.

                </div>

            <?php endif; ?>


            <!--
            |--------------------------------------------------------------------------
            | CASH SIMULATION PAYMENT
            |--------------------------------------------------------------------------
            -->

            <?php if ($can_submit_cash_notice): ?>

                <div class="notice">

                    <strong>Cash Simulation Payment</strong>

                    <p class="mt-2">

                        This is a school-project payment simulation,
                        not a real money transaction.

                    </p>

                    <p>

                        If you have completed the simulated cash payment,
                        submit your payment notice below.

                    </p>

                    <p>

                        Your subscription will remain pending until
                        the administrator confirms your payment.

                    </p>

                    <p class="mb-0">

                        Note that the subscription will be automatically
                        rejected if not paid after 3 days.

                    </p>

                </div>


                <form
                    method="POST"
                    action="subscription.php"
                    onsubmit="return confirm('Submit your Cash Simulation payment notice?');"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= escapeHtml($csrf) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="submit_cash_notice"
                    >

                    <button
                        type="submit"
                        class="btn btn-green mt-3"
                    >

                        Submit Cash Simulation Payment Notice

                    </button>

                </form>


            <?php elseif ($is_pending_payment): ?>

                <div class="alert alert-warning mt-3">

                    <strong>Payment Notice Submitted!</strong>

                    <p class="mb-0 mt-2">

                        Your payment notice has been submitted successfully.

                        Please present your printed Statement of Account
                        to the cashier.

                        Your payment is still awaiting administrator confirmation.

                    </p>

                </div>


            <?php elseif ($is_paid): ?>

                <div class="alert alert-success mt-3">

                    Your payment has been confirmed by the administrator.

                    Your subscription is now
                    <?= escapeHtml(
                        $subscription['subscription_status']
                    ) ?>.

                </div>


            <?php elseif (
                (float)$subscription['total_amount'] <= 0
            ): ?>

                <div class="alert alert-success mt-3">

                    Your Eco Points covered the full subscription fee.

                    No cash payment is required.

                    Your subscription still requires administrator review.

                </div>


            <?php else: ?>

                <div class="notice">

                    Your subscription is awaiting administrator review.

                    Please check this page for updates.

                </div>

            <?php endif; ?>


            <!--
            |--------------------------------------------------------------------------
            | LATEST PAYMENT RECORD
            |--------------------------------------------------------------------------
            -->

            <h4 class="mt-4">
                Latest Payment Record
            </h4>

            <table class="table table-bordered billing-table mt-3">

                <tr>

                    <th>
                        Latest Payment Status
                    </th>

                    <td>
                        <?= escapeHtml($payment_status) ?>
                    </td>

                </tr>


                <?php if ($payment): ?>

                    <tr>

                        <th>
                            Amount Recorded
                        </th>

                        <td>
                            <?= peso($payment_amount) ?>
                        </td>

                    </tr>


                    <tr>

                        <th>
                            Date Paid
                        </th>

                        <td>

                            <?= !empty($paid_at)
                                ? escapeHtml(
                                    date(
                                        "F d, Y h:i A",
                                        strtotime($paid_at)
                                    )
                                )
                                : "Not available"
                            ?>

                        </td>

                    </tr>

                <?php else: ?>

                    <tr>

                        <td colspan="2" class="text-center text-muted">

                            No payment record found.

                        </td>

                    </tr>

                <?php endif; ?>

            </table>


            <!--
            |--------------------------------------------------------------------------
            | PAY NOW BUTTON
            |--------------------------------------------------------------------------
            |
            | This retains the original payment.php link.
            |
            -->

            <?php if (
                $payment_status !== "Paid" &&
                $subscription['payment_status'] === "Unpaid" &&
                (float)$subscription['balance_due'] > 0
            ): ?>

                <a
                    href="payment.php?subscription_id=<?= (int)$subscription['id'] ?>"
                    class="btn btn-primary mt-2"
                >

                    Pay Now

                </a>

            <?php endif; ?>


            <!--
            |--------------------------------------------------------------------------
            | PRINT STATEMENT OF ACCOUNT BUTTON
            |--------------------------------------------------------------------------
            -->

            <?php if ($can_print_soa): ?>

                <button
                    type="button"
                    class="btn btn-green mt-3"
                    onclick="window.print()"
                >

                    Print Statement of Account

                </button>

            <?php endif; ?>


            <!-- ECO POINTS HISTORY -->

            <div class="mt-4">

                <a
                    href="eco_points.php"
                    class="btn btn-outline-success"
                >

                    View Eco Points and Redemption History

                </a>

            </div>

        </div>


        <!--
        |--------------------------------------------------------------------------
        | PRINTABLE STATEMENT OF ACCOUNT
        |--------------------------------------------------------------------------
        -->

        <?php if ($can_print_soa): ?>

            <div class="subscription-card" id="soa">

                <div class="text-center mb-4">

                    <h1 style="color:#198754">
                        ECO PHP
                    </h1>

                    <p class="mb-1">
                        WASTE MANAGEMENT SYSTEM
                    </p>

                    <h2 class="mt-4" style="color:#222">
                        STATEMENT OF ACCOUNT
                    </h2>

                    <p class="text-muted">
                        SUBSCRIPTION PAYMENT SLIP
                    </p>

                </div>


                <hr>


                <!-- RESIDENT INFORMATION -->

                <h4>
                    Resident Information
                </h4>

                <table class="table">

                    <tr>

                        <td>
                            Resident Name
                        </td>

                        <td class="text-end">
                            <?= escapeHtml($resident_name) ?>
                        </td>

                    </tr>


                    <tr>

                        <td>
                            Resident ID
                        </td>

                        <td class="text-end">
                            <?= $user_id ?>
                        </td>

                    </tr>


                    <tr>

                        <td>
                            Subscription ID
                        </td>

                        <td class="text-end">
                            #<?= (int)$subscription['id'] ?>
                        </td>

                    </tr>


                    <tr>

                        <td>
                            Subscription Date
                        </td>

                        <td class="text-end">
                            <?= escapeHtml($subscription['created_at']) ?>
                        </td>

                    </tr>


                    <tr>

                        <td>
                            Statement Printed
                        </td>

                        <td class="text-end">
                            <?= date("F d, Y h:i A") ?>
                        </td>

                    </tr>

                </table>


                <!-- PAYMENT BREAKDOWN -->

                <h4 class="mt-4">
                    Subscription Payment Breakdown
                </h4>

                <table class="table">

                    <tr>

                        <td>
                            Monthly Subscription Fee
                        </td>

                        <td class="text-end">
                            <?= peso($subscription['original_amount']) ?>
                        </td>

                    </tr>


                    <tr>

                        <td>
                            Subscription Duration
                        </td>

                        <td class="text-end">
                            <?= (int)$subscription['months'] ?> month(s)
                        </td>

                    </tr>


                    <tr>

                        <td>
                            Eco Points Redeemed
                        </td>

                        <td class="text-end">
                            <?= number_format(
                                (int)$subscription['points_redeemed']
                            ) ?>
                        </td>

                    </tr>


                    <tr>

                        <td>
                            Eco Points Discount
                        </td>

                        <td class="text-end">
                            <?= peso($subscription['discount_amount']) ?>
                        </td>

                    </tr>


                    <tr>

                        <td>
                            <strong>Total Subscription Amount</strong>
                        </td>

                        <td class="text-end">
                            <strong>
                                <?= peso($subscription['total_amount']) ?>
                            </strong>
                        </td>

                    </tr>


                    <tr>

                        <td>
                            Amount Paid / Recorded
                        </td>

                        <td class="text-end">
                            <?= peso($subscription['amount_paid']) ?>
                        </td>

                    </tr>


                    <tr>

                        <td>
                            <strong>Remaining Balance</strong>
                        </td>

                        <td class="text-end">
                            <strong>
                                <?= peso($subscription['balance_due']) ?>
                            </strong>
                        </td>

                    </tr>

                </table>


                <!-- PAYMENT INFORMATION -->

                <h4 class="mt-4">
                    Payment Information
                </h4>

                <table class="table">

                    <tr>

                        <td>
                            Payment Method
                        </td>

                        <td class="text-end">
                            Cash Simulation
                        </td>

                    </tr>


                    <tr>

                        <td>
                            Payment Status
                        </td>

                        <td class="text-end">
                            <?= escapeHtml(
                                $subscription['payment_status']
                            ) ?>
                        </td>

                    </tr>


                    <tr>

                        <td>
                            Subscription Status
                        </td>

                        <td class="text-end">
                            <?= escapeHtml(
                                $subscription['subscription_status']
                            ) ?>
                        </td>

                    </tr>

                </table>


                <!-- IMPORTANT NOTICE -->

                <div class="mt-4 p-3 border">

                    <strong>
                        IMPORTANT NOTICE
                    </strong>

                    <p class="mt-2">

                        This Statement of Account is presented
                        for cashier verification and payment reference.

                    </p>


                    <?php if ($is_pending_payment): ?>

                        <p>

                            Payment notice has been submitted and is awaiting
                            administrator confirmation.

                            This document is NOT an official receipt
                            or proof of confirmed payment.

                        </p>

                    <?php else: ?>

                        <p>

                            Payment has been confirmed in the system.

                            This statement is not a substitute
                            for an official receipt.

                        </p>

                    <?php endif; ?>

                </div>


                <!-- SIGNATURES -->

                <div class="row mt-5">

                    <div class="col-6 text-center">

                        <hr>

                        Resident Signature

                    </div>


                    <div class="col-6 text-center">

                        <hr>

                        Cashier / Authorized Personnel

                    </div>

                </div>


                <p class="text-center mt-5">

                    Thank you for supporting our waste management program!

                </p>

            </div>

        <?php endif; ?>

    <?php endif; ?>


    <!--
    |--------------------------------------------------------------------------
    | BACK TO DASHBOARD
    |--------------------------------------------------------------------------
    -->

    <div class="text-center mb-4 no-print">

        <a
            href="dashboard.php"
            class="btn btn-dashboard"
        >

            Back to Dashboard

        </a>

    </div>

</div>


<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

</body>
</html>