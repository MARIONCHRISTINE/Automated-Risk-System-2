<?php
include_once 'includes/auth.php';
requireRole('risk_owner');
include_once 'config/database.php';
include_once 'includes/shared_notifications.php';

// Verify session data exists
if (!isset($_SESSION['user_id']) || !isset($_SESSION['email'])) {
    header("Location: login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Get current user info
$user = getCurrentUser();

// Debug: Ensure department is available
if (empty($user['department'])) {
    if (isset($_SESSION['department'])) {
        $user['department'] = $_SESSION['department'];
    } else {
        $dept_query = "SELECT department FROM users WHERE id = :user_id";
        $dept_stmt = $db->prepare($dept_query);
        $dept_stmt->bindParam(':user_id', $_SESSION['user_id']);
        $dept_stmt->execute();
        $dept_result = $dept_stmt->fetch(PDO::FETCH_ASSOC);
        if ($dept_result) {
            $user['department'] = $dept_result['department'];
        }
    }
}

$success = '';
$error = '';

// Handle success message from redirect
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success = 'Risk reported successfully and assigned to you!';
}

// Function to handle file uploads
function handleFileUploads($files, $risk_id, $section_type, $db) {
    $upload_dir = 'uploads/risk_documents/';
    
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $uploaded_files = [];
    $allowed_types = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'jpg', 'jpeg', 'png'];
    $max_size = 10 * 1024 * 1024; // 10MB
    
    if (isset($files['name']) && is_array($files['name'])) {
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] == UPLOAD_ERR_OK) {
                $file_name = $files['name'][$i];
                $file_tmp = $files['tmp_name'][$i];
                $file_size = $files['size'][$i];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                
                if (!in_array($file_ext, $allowed_types)) {
                    throw new Exception("File type not allowed: $file_name");
                }
                
                if ($file_size > $max_size) {
                    throw new Exception("File too large: $file_name (max 10MB)");
                }
                
                $unique_name = $risk_id . '_' . $section_type . '_' . time() . '_' . $i . '.' . $file_ext;
                $file_path = $upload_dir . $unique_name;
                
                if (move_uploaded_file($file_tmp, $file_path)) {
                    $query = "INSERT INTO risk_documents (risk_id, section_type, original_filename, stored_filename, file_path, file_size, uploaded_by, uploaded_at)
                               VALUES (:risk_id, :section_type, :original_filename, :stored_filename, :file_path, :file_size, :uploaded_by, NOW())";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':risk_id', $risk_id);
                    $stmt->bindParam(':section_type', $section_type);
                    $stmt->bindParam(':original_filename', $file_name);
                    $stmt->bindParam(':stored_filename', $unique_name);
                    $stmt->bindParam(':file_path', $file_path);
                    $stmt->bindParam(':file_size', $file_size);
                    $stmt->bindParam(':uploaded_by', $_SESSION['user_id']);
                    $stmt->execute();
                    
                    $uploaded_files[] = $file_name;
                } else {
                    throw new Exception("Failed to upload file: $file_name");
                }
            }
        }
    }
    
    return $uploaded_files;
}

// Handle comprehensive risk reporting
if ($_POST && isset($_POST['submit_comprehensive_risk'])) {
    try {
        $db->beginTransaction();
        
        if (empty($_POST['risk_description']) || empty($_POST['cause_of_risk'])) {
            throw new Exception('Risk Description and Cause are required fields');
        }
        
        if (empty($_POST['risk_categories']) || !is_array($_POST['risk_categories'])) {
            throw new Exception('At least one risk category must be selected');
        }
        
        $risk_name = implode(', ', $_POST['risk_categories']) . ' - ' . substr($_POST['risk_description'], 0, 50) . '...';
        
        $risk_categories_json = json_encode($_POST['risk_categories']);
        
        $category_details = [];
        foreach ($_POST['risk_categories'] as $category) {
            $category_key = str_replace(' ', '_', strtolower($category));
            
            // Check for dropdown values instead of text inputs
            if (isset($_POST['category_value_' . $category_key]) && !empty($_POST['category_value_' . $category_key])) {
                $category_details[$category] = $_POST['category_value_' . $category_key];
            } elseif (isset($_POST['category_desc_' . $category_key]) && !empty($_POST['category_desc_' . $category_key])) {
                $category_details[$category] = $_POST['category_desc_' . $category_key];
            }
        }
        
        $category_details_json = json_encode($category_details);
        
        $query = "INSERT INTO risk_incidents (
            risk_name, risk_description, cause_of_risk, department, reported_by, risk_owner_id,
            existing_or_new, risk_categories, category_details,
            inherent_likelihood, inherent_consequence, inherent_rating,
            residual_likelihood, residual_consequence, residual_rating,
            treatment_action, controls_action_plans, target_completion_date,
            progress_update, treatment_status, risk_status,
            created_at, updated_at
        ) VALUES (
            :risk_name, :risk_description, :cause_of_risk, :department, :reported_by, :risk_owner_id,
            :existing_or_new, :risk_categories, :category_details,
            :inherent_likelihood, :inherent_consequence, :inherent_rating,
            :residual_likelihood, :residual_consequence, :residual_rating,
            :treatment_action, :controls_action_plans, :target_completion_date,
            :progress_update, :treatment_status, :risk_status,
            NOW(), NOW()
        )";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':risk_name', $risk_name);
        $stmt->bindParam(':risk_description', $_POST['risk_description']);
        $stmt->bindParam(':cause_of_risk', $_POST['cause_of_risk']);
        $stmt->bindParam(':department', $user['department']);
        $stmt->bindParam(':reported_by', $_SESSION['user_id']);
        $stmt->bindParam(':risk_owner_id', $_SESSION['user_id']);
        $stmt->bindParam(':existing_or_new', $_POST['existing_or_new']);
        $stmt->bindParam(':risk_categories', $risk_categories_json);
        $stmt->bindParam(':category_details', $category_details_json);
        $stmt->bindParam(':inherent_likelihood', $_POST['inherent_likelihood']);
        $stmt->bindParam(':inherent_consequence', $_POST['inherent_consequence']);
        $stmt->bindParam(':inherent_rating', $_POST['inherent_rating']);
        $stmt->bindParam(':residual_likelihood', $_POST['residual_likelihood']);
        $stmt->bindParam(':residual_consequence', $_POST['residual_consequence']);
        $stmt->bindParam(':residual_rating', $_POST['residual_rating']);
        $stmt->bindParam(':treatment_action', $_POST['treatment_action']);
        $stmt->bindParam(':controls_action_plans', $_POST['controls_action_plans']);
        $stmt->bindParam(':target_completion_date', $_POST['target_completion_date']);
        $stmt->bindParam(':progress_update', $_POST['progress_update']);
        $stmt->bindParam(':treatment_status', $_POST['treatment_status']);
        $stmt->bindParam(':risk_status', $_POST['risk_status']);
        
        if ($stmt->execute()) {
            $risk_id = $db->lastInsertId();
            
            foreach ($_POST['risk_categories'] as $category) {
                $category_value = null;
                $category_description = null;
                $category_type = null;
                
                $category_key = str_replace(' ', '_', strtolower($category));
                
                // Check if it's a value-based category (Financial Exposure, Fraud, etc.)
                if (isset($_POST['category_value_' . $category_key]) && !empty($_POST['category_value_' . $category_key])) {
                    $category_description = $_POST['category_value_' . $category_key]; // Store dropdown selection as description
                    $category_type = 'text';
                } elseif (isset($_POST['category_desc_' . $category_key]) && !empty($_POST['category_desc_' . $category_key])) {
                    $category_description = $_POST['category_desc_' . $category_key];
                    $category_type = 'text';
                }
                
                if ($category_description) {
                    $category_query = "INSERT INTO risk_category_details (
                        risk_id, risk_category, category_value, category_description, category_type
                    ) VALUES (?, ?, ?, ?, ?)";
                    
                    $category_stmt = $db->prepare($category_query);
                    $category_stmt->execute([$risk_id, $category, $category_value, $category_description, $category_type]);
                }
            }
            
            if (isset($_FILES['controls_documents']) && !empty($_FILES['controls_documents']['name'][0])) {
                $controls_files = handleFileUploads($_FILES['controls_documents'], $risk_id, 'controls_action_plans', $db);
            }
            
            if (isset($_FILES['progress_documents']) && !empty($_FILES['progress_documents']['name'][0])) {
                $progress_files = handleFileUploads($_FILES['progress_documents'], $risk_id, 'progress_update', $db);
            }
            
            $db->commit();
            header("Location: report_risk.php?success=1");
            exit();
        } else {
            $db->rollback();
            $error = "Failed to report comprehensive risk.";
        }
    } catch (Exception $e) {
        $db->rollback();
        $error = $e->getMessage();
    }
}

$all_notifications = getNotifications($db, $_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report New Risk - Airtel Risk Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            color: #333;
            line-height: 1.6;
            min-height: 100vh;
            padding-top: 150px;
        }
        
        .header {
            background: #E60012;
            padding: 1.5rem 2rem;
            color: white;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(230, 0, 18, 0.2);
        }
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .logo-circle {
            width: 55px;
            height: 55px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            padding: 5px;
        }
        .logo-circle img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 50%;
        }
        .header-titles {
            display: flex;
            flex-direction: column;
        }
        .main-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: white;
            margin: 0;
            line-height: 1.2;
        }
        .sub-title {
            font-size: 1rem;
            font-weight: 400;
            color: rgba(255, 255, 255, 0.9);
            margin: 0;
            line-height: 1.2;
        }
        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .user-avatar {
            width: 45px;
            height: 45px;
            background: white;
            color: #E60012;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.2rem;
        }
        .user-details {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }
        .user-email {
            font-size: 1rem;
            font-weight: 500;
            color: white;
            margin: 0;
            line-height: 1.2;
        }
        .user-role {
            font-size: 0.9rem;
            font-weight: 400;
            color: rgba(255, 255, 255, 0.9);
            margin: 0;
            line-height: 1.2;
        }
        .logout-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 0.7rem 1.3rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 4px;
            text-decoration: none;
            font-size: 1rem;
            font-weight: 500;
            transition: all 0.3s;
            margin-left: 1rem;
        }
        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            border-color: rgba(255, 255, 255, 0.5);
        }
        
        .nav {
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            position: fixed;
            top: 100px;
            left: 0;
            right: 0;
            z-index: 999;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .nav-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }
        .nav-menu {
            display: flex;
            list-style: none;
            margin: 0;
            padding: 0;
            align-items: center;
        }
        .nav-item {
            margin: 0;
        }
        .nav-item a {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem 1.5rem;
            color: #6c757d;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
        }
        .nav-item a:hover {
            color: #E60012;
            background-color: rgba(230, 0, 18, 0.05);
        }
        .nav-item a.active {
            color: #E60012;
            border-bottom-color: #E60012;
            background-color: rgba(230, 0, 18, 0.05);
        }
        
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-top: 4px solid #E60012;
        }
        
        .card-header {
            padding: 2rem 2rem 1rem 2rem;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-title {
            color: #333;
            font-size: 1.8rem;
            font-weight: 600;
            margin: 0;
        }
        
        .card-body {
            padding: 2rem;
        }
        
        .section-header {
            background: #E60012;
            color: white;
            padding: 0.75rem 1rem;
            margin: 2rem 0 1rem 0;
            border-radius: 0.25rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: relative;
        }
        
        .progress-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 0.25rem;
        }
        
        .progress-step {
            flex: 1;
            text-align: center;
            padding: 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .progress-step.active {
            background: #E60012;
            color: white;
        }
        
        .progress-step.completed {
            background: #28a745;
            color: white;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #333;
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e1e5e9;
            border-radius: 5px;
            transition: border-color 0.3s;
            font-size: 1rem;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #E60012;
            box-shadow: 0 0 0 3px rgba(230, 0, 18, 0.1);
        }
        
        textarea.form-control {
            height: 100px;
            resize: vertical;
        }
        
        /* Updated styling for 2x2 grid layout and better visual distinction */
        .risk-categories-container {
            background: #f8f9fa;
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 5px;
            max-height: 400px;
            overflow-y: auto;
        }

        .category-item {
            margin-bottom: 20px;
            padding: 15px;
            border: 2px solid #e3f2fd;
            border-radius: 8px;
            background: #fafafa;
        }

        .category-item:last-child {
            border-bottom: 2px solid #e3f2fd;
        }

        /* Enhanced category name styling with color distinction */
        .checkbox-label {
            display: flex;
            align-items: center;
            cursor: pointer;
            font-weight: 600;
            font-size: 16px;
            margin-bottom: 0;
            padding: 12px;
            border-radius: 6px;
            background: linear-gradient(135deg,rgb(231, 9, 28),rgb(235, 152, 159));
            color: white;
            transition: all 0.3s ease;
        }

        .checkbox-label:hover {
            background: linear-gradient(135deg,rgb(248, 4, 4),rgb(241, 2, 2));
            transform: translateY(-1px);
        }

        .checkbox-label input[type="checkbox"] {
            margin-right: 12px;
            transform: scale(1.4);
            accent-color: white;
        }

        /* 2x2 grid layout for impact levels */
        .impact-levels {
            display: grid;
            grid-template-columns: 1fr 1fr;
            grid-template-rows: 1fr 1fr;
            gap: 12px;
            margin-top: 15px;
            padding: 10px;
        }

        /* Square-styled radio buttons with distinct colors */
        .radio-label {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 15px;
            border: 2px solid #4caf50;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            background: linear-gradient(135deg, #e8f5e8, #f1f8e9);
            min-height: 80px;
            text-align: center;
            font-size: 13px;
            line-height: 1.3;
        }

        .radio-label:hover {
            background: linear-gradient(135deg, #c8e6c9, #dcedc8);
            border-color: #388e3c;
            transform: scale(1.02);
            box-shadow: 0 4px 8px rgba(76, 175, 80, 0.3);
        }

        .radio-label input[type="radio"] {
            margin-right: 8px;
            transform: scale(1.3);
            accent-color: #4caf50;
        }

        .radio-label input[type="radio"]:checked + span {
            font-weight: 600;
            color: #2e7d32;
        }

        /* Responsive design for smaller screens */
        @media (max-width: 768px) {
            .impact-levels {
                grid-template-columns: 1fr;
                grid-template-rows: repeat(4, 1fr);
            }
        }
        
        .risk-matrix {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin: 1rem 0;
        }
        
        .matrix-section {
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
            padding: 1rem;
            background: #f8f9fa;
        }
        
        .matrix-title {
            font-weight: 600;
            margin-bottom: 1rem;
            color: #E60012;
            text-align: center;
        }
        
        .rating-display {
            text-align: center;
            padding: 0.5rem;
            margin-top: 0.5rem;
            border-radius: 0.25rem;
            font-weight: bold;
        }
        
        .rating-low { background: #d4edda; color: #155724; }
        .rating-medium { background: #fff3cd; color: #856404; }
        .rating-high { background: #f8d7da; color: #721c24; }
        .rating-critical { background: #721c24; color: white; }
        
        .btn {
            background: #E60012;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 1rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn:hover {
            background: #B8000E;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(230, 0, 18, 0.3);
        }
        
        .alert {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            font-weight: 500;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .risk-matrix {
                grid-template-columns: 1fr;
            }
        }

        .impact-levels {
            display: flex;
            flex-direction: column;
        }

        .radio-label {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-bottom: 5px;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .radio-label:hover {
            background-color: rgba(230, 0, 18, 0.05);
        }

        .radio-label input[type="radio"] {
            margin-right: 8px;
            transform: scale(1.2);
            accent-color: #E60012;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="header-left">
                <div class="logo-circle">
                    <img src="image.png" alt="Airtel Logo" />
                </div>
                <div class="header-titles">
                    <h1 class="main-title">Airtel Risk Register System</h1>
                    <p class="sub-title">Risk Management System</p>
                </div>
            </div>
            <div class="header-right">
                <div class="user-avatar"><?php echo isset($_SESSION['full_name']) ? strtoupper(substr($_SESSION['full_name'], 0, 1)) : 'R'; ?></div>
                <div class="user-details">
                    <div class="user-email"><?php echo isset($_SESSION['email']) ? $_SESSION['email'] : 'No Email'; ?></div>
                    <div class="user-role">Risk_owner • <?php echo htmlspecialchars($user['department'] ?? 'No Department'); ?></div>
                </div>
                <a href="logout.php" class="logout-btn">Logout</a>
            </div>
        </div>
    </header>
    
    <nav class="nav">
        <div class="nav-content">
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="risk_owner_dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'risk_owner_dashboard.php' ? 'active' : ''; ?>">
                        🏠 Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a href="report_risk.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'report_risk.php' ? 'active' : ''; ?>">
                        📝 Report Risk
                    </a>
                </li>
                <li class="nav-item">
                    <a href="risk_owner_dashboard.php?tab=my-reports" class="<?php echo isset($_GET['tab']) && $_GET['tab'] == 'my-reports' ? 'active' : ''; ?>">
                        👀 My Reports
                    </a>
                </li>
                <li class="nav-item">
                    <a href="risk-procedures.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'risk-procedures.php' ? 'active' : ''; ?>" target="_blank">
                        📋 Procedures
                    </a>
                </li>
                <li class="nav-item notification-nav-item">
                    <?php
                    if (isset($_SESSION['user_id'])) {
                        renderNotificationBar($all_notifications);
                    }
                    ?>
                </li>
            </ul>
        </div>
    </nav>
    
    <div class="main-content">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Risk Registration Form</h2>
                <div>
                    <a href="risk_owner_dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
                </div>
            </div>
            <div class="card-body">
                
                <div class="progress-indicator">
                    <div class="progress-step active">1. Identify</div>
                    <div class="progress-step">2. Assess</div>
                    <div class="progress-step">3. Treat</div>
                    <div class="progress-step">4. Monitor</div>
                </div>
                
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <form method="POST" enctype="multipart/form-data">
                    <div class="section-header">
                        <i class="fas fa-search"></i> Section 1: Risk Identification
                    </div>
                    
                    <!-- Risk Categories moved to replace Risk Name position -->
                    <div class="form-group">
                        <label class="form-label">Risk Categories * <small>(Select all that apply)</small></label>
                        <div class="risk-categories-container">
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Financial Exposure" onchange="toggleCategoryInput(this)">
                                    <span class="checkmark">Financial Exposure [Revenue, Operating Expenditure, Book value]</span>
                                </label>
                                <!-- Updated with complete detailed descriptions from image matrix -->
                                <div class="category-input" id="input_financial_exposure" style="display: none;">
                                    <div class="impact-levels">
                                        <label class="radio-label">
                                            <input type="radio" name="category_value_financial_exposure" value=">5%">
                                            <span>>5%</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_value_financial_exposure" value="1%-5%">
                                            <span>1%-5%</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_value_financial_exposure" value="0.25%-1%">
                                            <span>0.25%-1%</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_value_financial_exposure" value="<0.25%">
                                            <span>&lt;0.25%</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Decrease in market share" onchange="toggleCategoryInput(this)">
                                    <span class="checkmark">Decrease in market share</span>
                                </label>
                                <!-- Updated with exact percentages from image -->
                                <div class="category-input" id="input_decrease_in_market_share" style="display: none;">
                                    <div class="impact-levels">
                                        <label class="radio-label">
                                            <input type="radio" name="category_value_decrease_in_market_share" value=">2%">
                                            <span>>2%</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_value_decrease_in_market_share" value=">1% but <2%">
                                            <span>>1% but &lt;2%</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_value_decrease_in_market_share" value=">0.50% but <1%">
                                            <span>>0.50% but &lt;1%</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_value_decrease_in_market_share" value="<0.50%">
                                            <span>&lt;0.50%</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Customer Experience" onchange="toggleCategoryInput(this)">
                                    <span class="checkmark">Customer Experience</span>
                                </label>
                                <!-- Removed class indicators and simplified descriptions -->
                                <div class="category-input" id="input_customer_experience" style="display: none;">
                                    <div class="impact-levels">
                                        <label class="radio-label">
                                            <input type="radio" name="category_value_customer_experience" value="Compliance Regulations Breaches Penalties >$1M">
                                            <span>Compliance Regulations Breaches<br>Penalties >$1M</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_value_customer_experience" value="Compliance Sanctions Limited impact Penalties $0.5M-$1M">
                                            <span>Compliance Sanctions<br>Limited impact<br>Penalties $0.5M-$1M</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_value_customer_experience" value="Compliance Sanctions Isolated impact Penalties $0.5M-$1M">
                                            <span>Compliance Sanctions<br>Isolated impact<br>Penalties $0.5M-$1M</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_value_customer_experience" value="Compliance Sanctions No impact Penalties <$0.5M">
                                            <span>Compliance Sanctions<br>No impact<br>Penalties &lt;$0.5M</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Compliance" onchange="toggleCategoryInput(this)">
                                    <span class="checkmark">Compliance</span>
                                </label>
                                <!-- Removed class indicators and simplified descriptions -->
                                <div class="category-input" id="input_compliance" style="display: none;">
                                    <div class="impact-levels">
                                        <label class="radio-label">
                                            <input type="radio" name="category_desc_compliance" value="Sanctions Directors Compliance Regulations Breaches Penalties >$1M">
                                            <span>Sanctions Directors<br>Compliance Regulations<br>Breaches Penalties >$1M</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_desc_compliance" value="Compliance Sanctions Limited impact Penalties $0.5M-$1M">
                                            <span>Compliance Sanctions<br>Limited impact<br>Penalties $0.5M-$1M</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_desc_compliance" value="Compliance Sanctions Isolated impact Penalties $0.5M-$1M">
                                            <span>Compliance Sanctions<br>Isolated impact<br>Penalties $0.5M-$1M</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_desc_compliance" value="Compliance Sanctions No impact Penalties <$0.5M">
                                            <span>Compliance Sanctions<br>No impact<br>Penalties &lt;$0.5M</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Reputation" onchange="toggleCategoryInput(this)">
                                    <span class="checkmark">Reputation</span>
                                </label>
                                <!-- Updated with media coverage descriptions from image -->
                                <div class="category-input" id="input_reputation" style="display: none;">
                                    <div class="impact-levels">
                                        <label class="radio-label">
                                            <input type="radio" name="category_desc_reputation" value="National media coverage">
                                            <span>National media coverage</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_desc_reputation" value="Limited media coverage">
                                            <span>Limited media coverage</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_desc_reputation" value="Local media coverage">
                                            <span>Local media coverage</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_desc_reputation" value="No impact Limited media coverage">
                                            <span>No impact Limited media coverage</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Fraud" onchange="toggleCategoryInput(this)">
                                    <span class="checkmark">Fraud</span>
                                </label>
                                <!-- Updated with monetary ranges from image -->
                                <div class="category-input" id="input_fraud" style="display: none;">
                                    <div class="impact-levels">
                                        <label class="radio-label">
                                            <input type="radio" name="category_value_fraud" value=">$1M">
                                            <span>>$1M</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_value_fraud" value="Code of Conduct Violations">
                                            <span>Code of Conduct Violations</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_value_fraud" value="Isolated impact">
                                            <span>Isolated impact</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_value_fraud" value="No impact Limited media coverage">
                                            <span>No impact Limited media coverage</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Operations" onchange="toggleCategoryInput(this)">
                                    <span class="checkmark">Operations (Business continuity)</span>
                                </label>
                                <!-- Updated Operations with exact detailed descriptions from user's image -->
                                <div class="category-input" id="input_operations" style="display: none;">
                                    <div class="impact-levels">
                                        <label class="radio-label">
                                            <input type="radio" name="category_desc_operations" value="1. Substantial loss of service or business capability 2. Complete operational shutdown at Data centres, warehouses for >3 days 3. Complete loss of data stored in systems or IT downtime >3 days">
                                            <span>1. Substantial loss of service or business capability<br>2. Complete operational shutdown at Data centres, warehouses for >3 days<br>3. Complete loss of data stored in systems or IT downtime >3 days</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_desc_operations" value="1. Major reduction in service or business capability 2. Major operational disruption at data centers, warehouses for 2 - 3 days 3. System data storage and archival impacted or IT downtime from 1-3 days">
                                            <span>1. Major reduction in service or business capability<br>2. Major operational disruption at data centers, warehouses for 2 - 3 days<br>3. System data storage and archival impacted or IT downtime from 1-3 days</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_desc_operations" value="1. Temporary but recoverable loss of service or business capability. May be limited to particular region/part of the country 2. Brief operational disruption 3. IT system downtime <=1 day, data loss averted due to timely data retrieval">
                                            <span>1. Temporary but recoverable loss of service or business capability. May be limited to particular region/part of the country<br>2. Brief operational disruption<br>3. IT system downtime <=1 day, data loss averted due to timely data retrieval</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_desc_operations" value="1. Limited operation disruption, resolved immediately 2. Limited impact on achievement of business objectives">
                                            <span>1. Limited operation disruption, resolved immediately<br>2. Limited impact on achievement of business objectives</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Networks" onchange="toggleCategoryInput(this)">
                                    <span class="checkmark">Networks</span>
                                </label>
                                <!-- Updated to match exact detailed descriptions from image -->
                                <div class="category-input" id="input_networks" style="display: none;">
                                    <div class="impact-levels">
                                        <label class="radio-label">
                                            <input type="radio" name="category_desc_networks" value="1. Network availability >95% but <99% 2. Frustration Index (for voice, data services) throughout 3. Liability, 3-4 seconds the threshold by 1-5">
                                            <span>1. Network availability >95% but &lt;99% 2. Frustration Index (for voice, data services) throughout 3. Liability, 3-4 seconds the threshold by 1-5</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_desc_networks" value="1. Network availability >90% but <95% 2. Frustration Index (for voice, data services) throughout 3. Liability, 3-4 seconds the threshold by 4-5">
                                            <span>1. Network availability >90% but &lt;95% 2. Frustration Index (for voice, data services) throughout 3. Liability, 3-4 seconds the threshold by 4-5</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_desc_networks" value="1. Network availability >85% but <90% 2. Frustration Index (for voice, data services) throughout 3. Liability, 3-4 seconds the threshold by 4-5">
                                            <span>1. Network availability >85% but &lt;90% 2. Frustration Index (for voice, data services) throughout 3. Liability, 3-4 seconds the threshold by 4-5</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_desc_networks" value="1. Network availability <85% 2. Frustration Index (for voice, data services) throughout 3. Liability, 3-4 seconds the threshold by 4-5">
                                            <span>1. Network availability &lt;85% 2. Frustration Index (for voice, data services) throughout 3. Liability, 3-4 seconds the threshold by 4-5</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="People" onchange="toggleCategoryInput(this)">
                                    <span class="checkmark">People</span>
                                </label>
                                <!-- Updated to match exact detailed descriptions from image -->
                                <div class="category-input" id="input_people" style="display: none;">
                                    <div class="impact-levels">
                                        <label class="radio-label">
                                            <input type="radio" name="category_desc_people" value="1. Total employee turnover >15% 2. Succession planning for ECs critical positions <40% 3. 1 fatality injured or 2-5 Accidents">
                                            <span>1. Total employee turnover >15% 2. Succession planning for ECs critical positions &lt;40% 3. 1 fatality injured or 2-5 Accidents</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_desc_people" value="1. Total employee turnover 5-15% 2. Succession planning for ECs critical positions <40% 3. Heavily injured or 2-5 Accidents">
                                            <span>1. Total employee turnover 5-15% 2. Succession planning for ECs critical positions &lt;40% 3. Heavily injured or 2-5 Accidents</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_desc_people" value="1. Total employee turnover <5% 2. Succession planning for ECs critical positions <40% 3. Heavily injured or 2-5 Accidents">
                                            <span>1. Total employee turnover &lt;5% 2. Succession planning for ECs critical positions &lt;40% 3. Heavily injured or 2-5 Accidents</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_desc_people" value="1. Total employee turnover <5% 2. Succession planning for ECs critical positions >40% 3. Heavily injured or 2-5 Accidents">
                                            <span>1. Total employee turnover &lt;5% 2. Succession planning for ECs critical positions >40% 3. Heavily injured or 2-5 Accidents</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="IT" onchange="toggleCategoryInput(this)">
                                    <span class="checkmark">IT (Cybersecurity & Data Privacy)</span>
                                </label>
                                <!-- Updated to match exact detailed descriptions from image -->
                                <div class="category-input" id="input_it" style="display: none;">
                                    <div class="impact-levels">
                                        <label class="radio-label">
                                            <input type="radio" name="category_desc_it" value="Breach of cyber security attempted and prevented Breach of other privacy attempted and prevented Information may be generated from existing">
                                            <span>Breach of cyber security attempted and prevented Breach of other privacy attempted and prevented Information may be generated from existing</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_desc_it" value="Breach of other privacy attempted and prevented Information may be generated from existing Breach of customer reported">
                                            <span>Breach of other privacy attempted and prevented Information may be generated from existing Breach of customer reported</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_desc_it" value="Information may be generated from existing Breach of customer reported">
                                            <span>Information may be generated from existing Breach of customer reported</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_desc_it" value="Breach of customer reported">
                                            <span>Breach of customer reported</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Other" onchange="toggleCategoryInput(this)">
                                    <span class="checkmark">Other</span>
                                </label>
                                <!-- Kept simple impact levels for Other category -->
                                <div class="category-input" id="input_other" style="display: none;">
                                    <div class="impact-levels">
                                        <label class="radio-label">
                                            <input type="radio" name="category_value_other" value="High Impact">
                                            <span>High Impact</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_value_other" value="Medium Impact">
                                            <span>Medium Impact</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_value_other" value="Low Impact">
                                            <span>Low Impact</span>
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="category_value_other" value="Minimal Impact">
                                            <span>Minimal Impact</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Risk Description *</label>
                        <textarea name="risk_description" class="form-control" required placeholder="Provide a detailed description of the risk"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Cause of Risk *</label>
                        <textarea name="cause_of_risk" class="form-control" required placeholder="What causes this risk to occur?"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Existing or New Risk *</label>
                        <select name="existing_or_new" class="form-control" required>
                            <option value="">Select Type</option>
                            <option value="New">New Risk</option>
                            <option value="Existing">Existing Risk</option>
                        </select>
                    </div>
                    
                    <div class="section-header">
                        <i class="fas fa-calculator"></i> Section 2: Risk Assessment
                    </div>
                    
                    <div class="risk-matrix">
                        <div class="matrix-section">
                            <div class="matrix-title">Inherent Risk (Before Controls)</div>
                            
                            <div class="form-group">
                                <label class="form-label">Likelihood (1-5) *</label>
                                <select name="inherent_likelihood" class="form-control" required onchange="calculateRating('inherent')">
                                    <option value="">Select</option>
                                    <option value="1">1 - Rare (0-5%)</option>
                                    <option value="2">2 - Unlikely (6-25%)</option>
                                    <option value="3">3 - Possible (26-50%)</option>
                                    <option value="4">4 - Likely (51-75%)</option>
                                    <option value="5">5 - Almost Certain (76-100%)</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Consequence (1-5) *</label>
                                <select name="inherent_consequence" class="form-control" required onchange="calculateRating('inherent')">
                                    <option value="">Select</option>
                                    <option value="1">1 - Insignificant</option>
                                    <option value="2">2 - Minor</option>
                                    <option value="3">3 - Moderate</option>
                                    <option value="4">4 - Major</option>
                                    <option value="5">5 - Catastrophic</option>
                                </select>
                            </div>
                            
                            <input type="hidden" name="inherent_rating" id="inherent_rating">
                            <div id="inherent_display" class="rating-display">Rating will appear here</div>
                        </div>
                        
                        <div class="matrix-section">
                            <div class="matrix-title">Residual Risk (After Controls)</div>
                            
                            <div class="form-group">
                                <label class="form-label">Likelihood (1-5) *</label>
                                <select name="residual_likelihood" class="form-control" required onchange="calculateRating('residual')">
                                    <option value="">Select</option>
                                    <option value="1">1 - Rare (0-5%)</option>
                                    <option value="2">2 - Unlikely (6-25%)</option>
                                    <option value="3">3 - Possible (26-50%)</option>
                                    <option value="4">4 - Likely (51-75%)</option>
                                    <option value="5">5 - Almost Certain (76-100%)</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Consequence (1-5) *</label>
                                <select name="residual_consequence" class="form-control" required onchange="calculateRating('residual')">
                                    <option value="">Select</option>
                                    <option value="1">1 - Insignificant</option>
                                    <option value="2">2 - Minor</option>
                                    <option value="3">3 - Moderate</option>
                                    <option value="4">4 - Major</option>
                                    <option value="5">5 - Catastrophic</option>
                                </select>
                            </div>
                            
                            <input type="hidden" name="residual_rating" id="residual_rating">
                            <div id="residual_display" class="rating-display">Rating will appear here</div>
                        </div>
                    </div>
                    
                    <div class="section-header">
                        <i class="fas fa-tools"></i> Section 3: Risk Treatment
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Treatment Action *</label>
                        <select name="treatment_action" class="form-control" required>
                            <option value="">Select Treatment</option>
                            <option value="Avoid">Avoid - Eliminate the risk</option>
                            <option value="Mitigate">Mitigate - Reduce likelihood/impact</option>
                            <option value="Transfer">Transfer - Share or transfer risk</option>
                            <option value="Accept">Accept - Accept the risk</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Controls/Action Plans *</label>
                        <textarea name="controls_action_plans" class="form-control" required placeholder="Describe the controls and action plans to manage this risk"></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Target Completion Date</label>
                            <input type="date" name="target_completion_date" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Treatment Status *</label>
                            <select name="treatment_status" class="form-control" required>
                                <option value="">Select Status</option>
                                <option value="Not Started">Not Started</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Completed">Completed</option>
                                <option value="On Hold">On Hold</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="section-header">
                        <i class="fas fa-chart-line"></i> Section 4: Progress Update
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Progress Update</label>
                        <textarea name="progress_update" class="form-control" placeholder="Provide updates on the progress of risk treatment activities"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Risk Status *</label>
                        <select name="risk_status" class="form-control" required>
                            <option value="">Select Status</option>
                            <option value="pending">Open</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    
                    <div style="text-align: center; margin-top: 2rem; padding-top: 2rem; border-top: 1px solid #dee2e6;">
                        <button type="submit" name="submit_comprehensive_risk" class="btn" style="padding: 1rem 3rem; font-size: 1.1rem;">
                            <i class="fas fa-save"></i> Submit Risk Report
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function calculateRating(type) {
            const likelihood = document.querySelector(`select[name="${type}_likelihood"]`).value;
            const consequence = document.querySelector(`select[name="${type}_consequence"]`).value;
            
            if (likelihood && consequence) {
                const rating = parseInt(likelihood) * parseInt(consequence);
                document.getElementById(`${type}_rating`).value = rating;
                
                let ratingText = '';
                let ratingClass = '';
                
                if (rating >= 15) {
                    ratingText = `Critical (${rating})`;
                    ratingClass = 'rating-critical';
                } else if (rating >= 9) {
                    ratingText = `High (${rating})`;
                    ratingClass = 'rating-high';
                } else if (rating >= 4) {
                    ratingText = `Medium (${rating})`;
                    ratingClass = 'rating-medium';
                } else {
                    ratingText = `Low (${rating})`;
                    ratingClass = 'rating-low';
                }
                
                const display = document.getElementById(`${type}_display`);
                display.textContent = ratingText;
                display.className = `rating-display ${ratingClass}`;
            }
        }
        
        function toggleCategoryInput(checkbox) {
            const categoryName = checkbox.value.toLowerCase().replace(/[^a-z0-9]/g, '_');
            const inputDiv = document.getElementById('input_' + categoryName);
            
            if (checkbox.checked) {
                inputDiv.style.display = 'block';
                const input = inputDiv.querySelector('select, textarea');
                if (input) {
                    input.required = true;
                }
            } else {
                inputDiv.style.display = 'none';
                const input = inputDiv.querySelector('select, textarea');
                if (input) {
                    input.required = false;
                    input.value = '';
                }
            }
        }
    </script>
</body>
</html>
