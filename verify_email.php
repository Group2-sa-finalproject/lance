
<?php
session_start();
include "config/database.php";
require_once "config/send_email.php";

$message = "";
$success = "";

if (!isset($_SESSION["pending_verification_user"])) {
    header("Location: register.php");
    exit;
}

$user_id = (int) $_SESSION["pending_verification_user"];

// Get resident details
$user_stmt = mysqli_prepare(
    $conn,
    "SELECT fullname, email, email_verified
     FROM users WHERE id = ? LIMIT 1"
);

mysqli_stmt_bind_param($user_stmt, "i", $user_id);
mysqli_stmt_execute($user_stmt);

$user_result = mysqli_stmt_get_result($user_stmt);
$user = mysqli_fetch_assoc($user_result);
mysqli_stmt_close($user_stmt);

if (!$user) {
    unset($_SESSION["pending_verification_user"]);
    header("Location: register.php");
    exit;
}

if ((int)$user["email_verified"] === 1) {
    unset($_SESSION["pending_verification_user"]);
    header("Location: index.php?verified=1");
    exit;
}

// RESEND OTP
if (isset($_POST["resend"])) {

    $latest_stmt = mysqli_prepare(
        $conn,
        "SELECT created_at FROM email_otps
         WHERE user_id = ?
         ORDER BY id DESC LIMIT 1"
    );

    mysqli_stmt_bind_param($latest_stmt, "i", $user_id);
    mysqli_stmt_execute($latest_stmt);

    $latest_result = mysqli_stmt_get_result($latest_stmt);
    $latest = mysqli_fetch_assoc($latest_result);
    mysqli_stmt_close($latest_stmt);

    if ($latest) {

        $last_sent = strtotime($latest["created_at"]);
        $remaining = 60 - (time() - $last_sent);

        if ($remaining > 0) {
            $message = "Please wait " . $remaining .
                       " seconds before requesting another OTP.";
        }
    }

    if ($message === "") {

        $otp = (string) random_int(100000, 999999);
        $hashed_otp = password_hash($otp, PASSWORD_DEFAULT);

        $expires_at = date("Y-m-d H:i:s", time() + 300);

        $subject = "Your New Email Verification Code";

        $body = "
            <h2>Waste Collection Management System</h2>

            <p>Hello " . htmlspecialchars($user["fullname"]) . ",</p>

            <p>Here is your new email verification code:</p>

            <h1 style='color:#198754; letter-spacing:5px;'>
                $otp
            </h1>

            <p>This code expires in 5 minutes.</p>

            <p>If you did not request this code, please ignore this email.</p>
        ";

        if (sendEmail($user["email"], $subject, $body)) {

            mysqli_begin_transaction($conn);

            try {

                $delete = mysqli_prepare(
                    $conn,
                    "DELETE FROM email_otps WHERE user_id = ?"
                );

                mysqli_stmt_bind_param($delete, "i", $user_id);
                mysqli_stmt_execute($delete);
                mysqli_stmt_close($delete);

                $insert = mysqli_prepare(
                    $conn,
                    "INSERT INTO email_otps
                    (user_id, otp_code, expires_at, attempts)
                    VALUES (?, ?, ?, 0)"
                );

                mysqli_stmt_bind_param(
                    $insert,
                    "iss",
                    $user_id,
                    $hashed_otp,
                    $expires_at
                );

                if (!mysqli_stmt_execute($insert)) {
                    throw new Exception("Unable to save new OTP.");
                }

                mysqli_stmt_close($insert);

                mysqli_commit($conn);

                $success = "A new OTP has been sent to your email.";

            } catch (Exception $e) {

                mysqli_rollback($conn);
                $message = "Unable to save your new OTP. Please try again.";

                error_log($e->getMessage());
            }

        } else {
            $message = "Unable to send email. Please try again later.";
        }
    }
}

// VERIFY OTP
if (isset($_POST["verify"])) {

    $otp = trim($_POST["otp"] ?? "");

    if (!preg_match('/^[0-9]{6}$/', $otp)) {

        $message = "Please enter a valid 6-digit OTP.";

    } else {

        $stmt = mysqli_prepare(
            $conn,
            "SELECT id, otp_code, expires_at, attempts
             FROM email_otps
             WHERE user_id = ?
             ORDER BY id DESC
             LIMIT 1"
        );

        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);
        $otp_data = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        if (!$otp_data) {

            $message = "No OTP found. Please request a new one.";

        } elseif ((int)$otp_data["attempts"] >= 5) {

            $message = "Too many attempts. Please request a new OTP.";

        } elseif (strtotime($otp_data["expires_at"]) < time()) {

            $message = "OTP expired. Please request a new OTP.";

        } elseif (!password_verify($otp, $otp_data["otp_code"])) {

            $update = mysqli_prepare(
                $conn,
                "UPDATE email_otps
                 SET attempts = attempts + 1
                 WHERE id = ?"
            );

            mysqli_stmt_bind_param(
                $update,
                "i",
                $otp_data["id"]
            );

            mysqli_stmt_execute($update);
            mysqli_stmt_close($update);

            $message = "Incorrect OTP. Please try again.";

        } else {

            mysqli_begin_transaction($conn);

            try {

                $update_user = mysqli_prepare(
                    $conn,
                    "UPDATE users
                     SET email_verified = 1,
                         email_verified_at = NOW()
                     WHERE id = ? AND email_verified = 0"
                );

                mysqli_stmt_bind_param(
                    $update_user,
                    "i",
                    $user_id
                );

                if (!mysqli_stmt_execute($update_user) ||
                    mysqli_stmt_affected_rows($update_user) !== 1) {
                    throw new Exception("Account verification failed.");
                }

                mysqli_stmt_close($update_user);

                $delete_otp = mysqli_prepare(
                    $conn,
                    "DELETE FROM email_otps WHERE user_id = ?"
                );

                mysqli_stmt_bind_param(
                    $delete_otp,
                    "i",
                    $user_id
                );

                mysqli_stmt_execute($delete_otp);
                mysqli_stmt_close($delete_otp);

                mysqli_commit($conn);

                unset($_SESSION["pending_verification_user"]);

                header("Location: index.php?verified=1");
                exit;

            } catch (Exception $e) {

                mysqli_rollback($conn);

                $message = "Verification failed. Please try again.";

                error_log($e->getMessage());
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Verify Email</title>

    <link rel="stylesheet"
          href="assets/vendor/bootstrap/css/bootstrap.min.css">

    <style>
        body {
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background: #f4f7f5;
            font-family: Arial, sans-serif;
        }

        .verify-container {
            width: 100%;
            max-width: 430px;
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }

        .btn-verify {
            background: #198754;
            color: white;
            width: 100%;
        }

        .btn-verify:hover {
            background: #146c43;
            color: white;
        }
    </style>
</head>

<body>

<div class="verify-container">

    <h2 class="text-center text-success mb-3">
        Verify Your Email
    </h2>

    <p class="text-center">
        We sent a 6-digit verification code to:
    </p>

    <p class="text-center fw-bold">
        <?php echo htmlspecialchars($user["email"]); ?>
    </p>

    <?php if ($message !== "") { ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php } ?>

    <?php if ($success !== "") { ?>
        <div class="alert alert-success">
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php } ?>

    <form method="POST">

        <div class="mb-3">
            <label class="form-label">Enter OTP</label>

            <input
                type="text"
                name="otp"
                class="form-control text-center"
                maxlength="6"
                pattern="[0-9]{6}"
                inputmode="numeric"
                placeholder="000000"
                required
            >
        </div>

        <button type="submit"
                name="verify"
                class="btn btn-verify mb-3">
            Verify Email
        </button>

    </form>

    <hr>

    <form method="POST">

        <p class="text-center">
            Didn't receive the code?
        </p>

        <button type="submit"
                name="resend"
                class="btn btn-outline-success w-100">
            Resend OTP
        </button>

    </form>

</div>

</body>
</html>
