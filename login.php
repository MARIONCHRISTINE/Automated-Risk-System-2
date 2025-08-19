<?php
session_start();
include_once 'config/database.php';

// Include compatibility functions if file exists
if (file_exists('includes/php_compatibility.php')) {
    include_once 'includes/php_compatibility.php';
} else {
    // Define essential functions inline if include fails
    function checkPHPVersion() {
        $minVersion = '7.0';
        $currentVersion = phpversion();
        
        if (version_compare($currentVersion, $minVersion, '<')) {
            return [
                'compatible' => false,
                'message' => "Warning: PHP {$currentVersion} detected. Minimum required: {$minVersion}"
            ];
        }
        
        return [
            'compatible' => true,
            'message' => "PHP {$currentVersion} - Compatible ✓"
        ];
    }
}

$error = '';
$database = new Database();
$db = $database->getConnection();

if ($_POST) {
    $email = $_POST['email'];
    $password = $_POST['password'];
    
    $query = "SELECT id, email, password, full_name, role, status FROM users WHERE email = :email";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user['status'] !== 'approved') {
            $error = "Your account is pending approval. Please contact the administrator.";
            // Log attempt for pending/unapproved account
            $database->logActivity($user['id'], 'Login Attempt - Account Pending/Suspended', 'User ' . $email . ' tried to log in but account is ' . $user['status'] . '.', $_SERVER['REMOTE_ADDR']);
        } elseif (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            
            // Log successful login
            $database->logActivity($_SESSION['user_id'], 'Successful Login', 'User ' . $_SESSION['email'] . ' logged in successfully.', $_SERVER['REMOTE_ADDR']);

            // Redirect based on role
            switch ($user['role']) {
                case 'staff':
                    header("Location: staff_dashboard.php");
                    break;
                case 'risk_owner':
                    header("Location: risk_owner_dashboard.php");
                    break;
                case 'compliance_team':
                    header("Location: compliance_dashboard.php");
                    break;
                case 'admin':
                    header("Location: admin_dashboard.php");
                    break;
            }
            exit();
        } else {
            $error = "Invalid email or password.";
            // Log failed login attempt (invalid password)
            $database->logActivity($user['id'], 'Failed Login - Invalid Password', 'User ' . $email . ' entered an invalid password.', $_SERVER['REMOTE_ADDR']);
        }
    } else {
        $error = "Invalid email or password.";
        // Log failed login attempt (user not found)
        $database->logActivity(null, 'Failed Login - User Not Found', 'Attempt to log in with non-existent email: ' . $email, $_SERVER['REMOTE_ADDR']);
    }
}

$phpCheck = checkPHPVersion();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Airtel Risk Management - Login</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #E60012 0%, #B8000E 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .login-container {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(230, 0, 18, 0.2);
            width: 100%;
            max-width: 400px;
            border-top: 5px solid #E60012;
        }
        
        .logo {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .logo h1 {
            color: #E60012;
            font-size: 2rem;
            margin-bottom: 0.5rem;
            font-weight: 700;
        }
        
        .logo p {
            color: #666;
            font-size: 0.9rem;
        }
        
        .airtel-logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 1rem;
            display: block;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(230, 0, 18, 0.2);
            object-fit: cover;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 500;
        }
        
        input[type="email"], input[type="password"] {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        input[type="email"]:focus, input[type="password"]:focus {
            outline: none;
            border-color: #E60012;
            box-shadow: 0 0 0 3px rgba(230, 0, 18, 0.1);
        }
        
        .btn {
            width: 100%;
            padding: 0.75rem;
            background: linear-gradient(135deg, #E60012 0%, #B8000E 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(230, 0, 18, 0.3);
        }
        
        .error {
            background: #ffebee;
            color: #c62828;
            padding: 0.75rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border: 1px solid #ffcdd2;
        }
        
        .register-link {
            text-align: center;
            margin-top: 1.5rem;
        }
        
        .register-link a {
            color: #E60012;
            text-decoration: none;
            font-weight: 500;
        }
        
        .register-link a:hover {
            text-decoration: underline;
        }
        .forgot-password-link {
            text-align: center;
            margin: 1rem 0;
        }
        .forgot-password-link a {
            color: #E60012;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
        }
        .forgot-password-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <img src="image.png" alt="Airtel Logo" class="airtel-logo">
            <h1>Airtel Risk Management</h1>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" required placeholder="your.name@airtel.africa">
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit" class="btn">Sign In</button>
        </form>
        <div class="forgot-password-link">
            <a href="reset_password.php">Forgot your password?</a>
        </div>
        <div class="register-link">
            <p>Don't have an account? <a href="register.php">Register here</a></p>
        </div>
    </div>
</body>
</html>
