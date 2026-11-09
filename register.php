
<?php
session_start();
include "config/database.php";
require_once "config/send_email.php";

$message = "";

if (isset($_POST["register"])) {

    $fullname = trim($_POST["fullname"] ?? "");
    $username = trim($_POST["username"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if (
        $fullname === "" ||
        $username === "" ||
        $email === "" ||
        $password === "" ||
        $confirm_password === ""
    ) {
        $message = "Please complete all required fields.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";

    } elseif (strlen($password) < 8) {
        $message = "Password must be at least 8 characters.";

    } elseif ($password !== $confirm_password) {
        $message = "Passwords do not match.";

    } else {

        // Check duplicate username or email
        $check = mysqli_prepare(
            $conn,
            "SELECT id FROM users
             WHERE users = ? OR email = ?
             LIMIT 1"
        );

        mysqli_stmt_bind_param($check, "ss", $username, $email);
        mysqli_stmt_execute($check);
        mysqli_stmt_store_result($check);

        if (mysqli_stmt_num_rows($check) > 0) {

            $message = "Username or email already exists.";

        } else {

            $hashed_password = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $role = "residents";

            // Generate 6-digit OTP
            $otp = (string) random_int(100000, 999999);
            $hashed_otp = password_hash($otp, PASSWORD_DEFAULT);

            // Create unverified resident account
            $sql = mysqli_prepare(
                $conn,
                "INSERT INTO users
                (fullname, users, email, password, role, email_verified)
                VALUES (?, ?, ?, ?, ?, 0)"
            );

            mysqli_stmt_bind_param(
                $sql,
                "sssss",
                $fullname,
                $username,
                $email,
                $hashed_password,
                $role
            );

            if (mysqli_stmt_execute($sql)) {

                $user_id = mysqli_insert_id($conn);

                // Save OTP
                $expires_at = date(
                    "Y-m-d H:i:s",
                    time() + 300
                );

                $otp_sql = mysqli_prepare(
                    $conn,
                    "INSERT INTO email_otps
                    (user_id, otp_code, expires_at)
                    VALUES (?, ?, ?)"
                );

                mysqli_stmt_bind_param(
                    $otp_sql,
                    "iss",
                    $user_id,
                    $hashed_otp,
                    $expires_at
                );

                if (mysqli_stmt_execute($otp_sql)) {

                    // Send OTP email
                    $subject = "Verify Your Waste Collection Account";

                    $body = "
                        <h2>Waste Collection Management System</h2>

                        <p>Hello " . htmlspecialchars($fullname) . ",</p>

                        <p>Thank you for registering!</p>

                        <p>Your email verification code is:</p>

                        <h1 style='color:#198754; letter-spacing:5px;'>
                            $otp
                        </h1>

                        <p>This OTP will expire in 5 minutes.</p>

                        <p>If you did not create this account,
                        please ignore this email.</p>
                    ";

                    if (sendEmail($email, $subject, $body)) {

                        $_SESSION["pending_verification_user"] = $user_id;

                        header("Location: verify_email.php");
                        exit;

                    } else {

                        // Remove account if email could not be sent
                        mysqli_query(
                            $conn,
                            "DELETE FROM email_otps
                             WHERE user_id = " . (int)$user_id
                        );

                        mysqli_query(
                            $conn,
                            "DELETE FROM users
                             WHERE id = " . (int)$user_id
                        );

                        $message = "Unable to send verification email. Please check your email address and try again.";
                    }

                } else {
                    $message = "Unable to save OTP. Please try again.";
                    error_log(mysqli_stmt_error($otp_sql));
                }

                mysqli_stmt_close($otp_sql);
                mysqli_stmt_close($sql);

            } else {
                $message = "Registration failed. Please try again.";
            }
        }

        mysqli_stmt_close($check);
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Register - Waste Collection Management System</title>

    <link rel="stylesheet"
          href="assets/vendor/bootstrap/css/bootstrap.min.css">
</head>

<body>

<div class="register-container">

    <h2>Create an Account</h2>

    <?php if ($message != "") { ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php } ?>

    <form method="POST" action="">

        <div class="mb-3">
            <label class="form-label">Full Name</label>
            <input type="text" name="fullname"
                   class="form-control"
                   placeholder="Enter your full name"
                   required>
        </div>

        <div class="mb-3">
            <label class="form-label">Username</label>
            <input type="text" name="username"
                   class="form-control"
                   placeholder="Create a username"
                   required>
        </div>

        <div class="mb-3">
            <label class="form-label">Email Address</label>
            <input type="email" name="email"
                   class="form-control"
                   placeholder="Enter your email address"
                   required>
        </div>

        <div class="mb-3">
            <label class="form-label">Password</label>
            <input type="password" name="password"
                   class="form-control"
                   placeholder="Create a password"
                   minlength="8"
                   required>
        </div>

        <div class="mb-3">
            <label class="form-label">Confirm Password</label>
            <input type="password" name="confirm_password"
                   class="form-control"
                   placeholder="Confirm your password"
                   minlength="8"
                   required>
        </div>

        <button type="submit" name="register"
                class="btn-register">
            Register
        </button>

    </form>

    <div class="login-link">
        Already have an account?
        <a href="index.php">Login Here</a>
    </div>

</div>

<script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

</body>
</html>
