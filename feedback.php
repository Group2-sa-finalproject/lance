
<?php
session_start();

include "../config/database.php";

// Check if the user is logged in as a resident
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "residents"
) {
    header("Location: ../index.php");
    exit;
}

// Get resident ID from session
$user_id = (int) $_SESSION["user_id"];

// Set database charset
if ($conn instanceof mysqli) {
    $conn->set_charset("utf8mb4");
} else {
    die("Database connection failed.");
}

// Generate CSRF token
if (!isset($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$feedback_type = "";
$message = "";
$error = "";

// Check if feedback was successfully submitted
$success = isset($_GET["sent"]) && $_GET["sent"] == "1";

// Process feedback submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $feedback_type = trim($_POST["feedback_type"] ?? "");
    $message = trim($_POST["message"] ?? "");
    $csrf_token = $_POST["csrf_token"] ?? "";

    // Validate CSRF token
    if (!hash_equals($_SESSION["csrf_token"], $csrf_token)) {
        $error = "Invalid request. Please refresh the page and try again.";
    }

    // Validate feedback type
    elseif (!in_array($feedback_type, [
        "Complaint",
        "Suggestion",
        "Satisfaction"
    ], true)) {
        $error = "Please select a valid feedback type.";
    }

    // Validate message
    elseif ($message === "") {
        $error = "Please enter your feedback message.";
    }

    else {

        // Insert feedback into database
        $sql = "INSERT INTO feedback (user_id, feedback_type, message)
                VALUES (?, ?, ?)";

        $stmt = mysqli_prepare($conn, $sql);

        if ($stmt) {

            mysqli_stmt_bind_param(
                $stmt,
                "iss",
                $user_id,
                $feedback_type,
                $message
            );

            if (mysqli_stmt_execute($stmt)) {

                // Clear form values
                $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

                // Redirect after successful submission
                header("Location: feedback.php?sent=1");
                exit;

            } else {
                $error = "Unable to submit your feedback. Please try again.";
            }

            mysqli_stmt_close($stmt);

        } else {
            $error = "Database query failed. Please check your feedback table.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Send Feedback | Waste Collection Management System</title>

    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f7f6;
            margin: 0;
        }

        /* NAVBAR */
        .navbar {
            background-color: #198754;
            padding: 12px 0;
        }

        .navbar-brand {
            font-weight: 600;
            color: white;
        }

        /* MAIN CONTENT */
        .main-container {
            max-width: 750px;
            margin: 35px auto;
            padding: 0 15px;
        }

        .page-header {
            margin-bottom: 20px;
        }

        .page-header h2 {
            color: #198754;
            font-weight: 700;
        }

        .page-header p {
            color: #777;
            font-size: 14px;
        }

        /* FEEDBACK CARD */
        .content-card {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.06);
        }

        .form-label {
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }

        .form-control,
        .form-select {
            padding: 11px;
            font-size: 14px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #198754;
            box-shadow: 0 0 0 0.2rem rgba(25, 135, 84, 0.15);
        }

        .btn-success {
            background-color: #198754;
            border-color: #198754;
        }

        .btn-success:hover {
            background-color: #146c43;
            border-color: #146c43;
        }

        /* FOOTER */
        .page-footer {
            margin-top: 30px;
            padding: 20px 0;
            border-top: 1px solid #e2e9e5;
            color: #929b96;
            font-size: 12px;
            text-align: center;
        }

        @media (max-width: 576px) {
            .content-card {
                padding: 20px;
            }

            .page-header h2 {
                font-size: 23px;
            }
        }
    </style>
</head>

<body>

    <!-- NAVBAR -->
    <nav class="navbar navbar-dark">
        <div class="container-fluid px-4">

            <span class="navbar-brand">
                <i class="bi bi-recycle me-2"></i>
                Waste Collection Management System
            </span>

            <a href="dashboard.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-arrow-left me-1"></i>
                Dashboard
            </a>

        </div>
    </nav>

    <!-- MAIN CONTENT -->
    <main class="main-container">

        <!-- PAGE HEADER -->
        <div class="page-header">

            <h2>
                <i class="bi bi-chat-left-text me-2"></i>
                Send Feedback
            </h2>

            <p>
                Share your complaints, suggestions, or satisfaction
                about our waste collection services.
            </p>

        </div>

        <!-- FEEDBACK FORM CARD -->
        <div class="content-card">

            <?php if ($success) { ?>

                <div class="alert alert-success">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    Your feedback has been submitted successfully!
                </div>

            <?php } ?>

            <?php if ($error !== "") { ?>

                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-circle-fill me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>

            <?php } ?>

            <form method="POST" action="feedback.php">

                <input type="hidden"
                    name="csrf_token"
                    value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">

                <!-- FEEDBACK TYPE -->
                <div class="mb-3">

                    <label for="feedback_type" class="form-label">
                        Feedback Type
                    </label>

                    <select name="feedback_type"
                        id="feedback_type"
                        class="form-select"
                        required>

                        <option value="">Select Feedback Type</option>

                        <option value="Complaint"
                            <?php echo $feedback_type === "Complaint" ? "selected" : ""; ?>>
                            Complaint
                        </option>

                        <option value="Suggestion"
                            <?php echo $feedback_type === "Suggestion" ? "selected" : ""; ?>>
                            Suggestion
                        </option>

                        <option value="Satisfaction"
                            <?php echo $feedback_type === "Satisfaction" ? "selected" : ""; ?>>
                            Satisfaction
                        </option>

                    </select>

                </div>

                <!-- MESSAGE -->
                <div class="mb-4">

                    <label for="message" class="form-label">
                        Your Message
                    </label>

                    <textarea name="message"
                        id="message"
                        class="form-control"
                        rows="5"
                        placeholder="Write your feedback here..."
                        required><?php echo htmlspecialchars($message); ?></textarea>

                </div>

                <!-- BUTTONS -->
                <div class="d-flex flex-wrap gap-2">

                    <button type="submit" class="btn btn-success px-4">
                        <i class="bi bi-send me-1"></i>
                        Submit Feedback
                    </button>

                    <a href="feedback_history.php"
                        class="btn btn-outline-success px-4">
                        <i class="bi bi-clock-history me-1"></i>
                        Feedback History
                    </a>

                </div>

            </form>

        </div>

        <!-- FOOTER -->
        <div class="page-footer">

            Waste Collection Management System by Group 2
            &copy; <?php echo date("Y"); ?>

            <br>

            Resident - Send Feedback

        </div>

    </main>

    <!-- Bootstrap JavaScript -->
    <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

</body>
</html>
