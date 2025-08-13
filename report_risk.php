<?php
include_once 'includes/auth.php';
requireRole('risk_owner');
include_once 'config/database.php';
include_once 'includes/shared_notifications.php'; // Include shared notifications

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
    
    // Create directory if it doesn't exist
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
                
                // Validate file type
                if (!in_array($file_ext, $allowed_types)) {
                    throw new Exception("File type not allowed: $file_name");
                }
                
                // Validate file size
                if ($file_size > $max_size) {
                    throw new Exception("File too large: $file_name (max 10MB)");
                }
                
                // Generate unique filename
                $unique_name = $risk_id . '_' . $section_type . '_' . time() . '_' . $i . '.' . $file_ext;
                $file_path = $upload_dir . $unique_name;
                
                if (move_uploaded_file($file_tmp, $file_path)) {
                    // Save to database
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

if ($_POST && isset($_POST['submit_comprehensive_risk'])) {
    try {
        $db->beginTransaction();
        
        // Validation
        if (empty($_POST['risk_category']) || empty($_POST['risk_description']) || empty($_POST['cause_of_risk'])) {
            throw new Exception('Risk Category, Description, and Cause are required fields');
        }
        
        // Validate category-specific input
        $category_value = null;
        $category_description = null;
        
        switch($_POST['risk_category']) {
            case 'Financial Exposure':
            case 'Decrease in market share':
            case 'Customer Experience':
            case 'Fraud':
            case 'Other':
                if (empty($_POST['category_value']) || !is_numeric($_POST['category_value'])) {
                    throw new Exception('Please enter a valid numeric value for this risk category');
                }
                $category_value = floatval($_POST['category_value']);
                break;
            case 'Compliance':
            case 'Reputation':
            case 'Operations':
            case 'Networks':
            case 'People':
            case 'IT':
                if (empty($_POST['category_description'])) {
                    throw new Exception('Please provide a description for this risk category');
                }
                $category_description = $_POST['category_description'];
                break;
        }
        
        $query = "INSERT INTO risk_incidents (
            risk_category, category_value, category_description, risk_description, cause_of_risk, department, reported_by, risk_owner_id,
            existing_or_new, to_be_reported_to_board,
            inherent_likelihood, inherent_consequence, inherent_rating,
            residual_likelihood, residual_consequence, residual_rating,
            treatment_action, controls_action_plans, target_completion_date,
            progress_update, treatment_status, risk_status,
            created_at, updated_at
        ) VALUES (
            :risk_category, :category_value, :category_description, :risk_description, :cause_of_risk, :department, :reported_by, :risk_owner_id,
            :existing_or_new, :to_be_reported_to_board,
            :inherent_likelihood, :inherent_consequence, :inherent_rating,
            :residual_likelihood, :residual_consequence, :residual_rating,
            :treatment_action, :controls_action_plans, :target_completion_date,
            :progress_update, :treatment_status, :risk_status,
            NOW(), NOW()
        )";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':risk_category', $_POST['risk_category']);
        $stmt->bindParam(':category_value', $category_value);
        $stmt->bindParam(':category_description', $category_description);
        $stmt->bindParam(':risk_description', $_POST['risk_description']);
        $stmt->bindParam(':cause_of_risk', $_POST['cause_of_risk']);
        $stmt->bindParam(':department', $_POST['department']);
        $stmt->bindParam(':reported_by', $_SESSION['user_id']);
        $stmt->bindParam(':risk_owner_id', $_SESSION['user_id']);
        $stmt->bindParam(':existing_or_new', $_POST['existing_or_new']);
        $stmt->bindParam(':to_be_reported_to_board', $_POST['to_be_reported_to_board']);
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
            
            // Handle file uploads for Controls/Action Plans
            if (isset($_FILES['controls_documents']) && !empty($_FILES['controls_documents']['name'][0])) {
                $controls_files = handleFileUploads($_FILES['controls_documents'], $risk_id, 'controls_action_plans', $db);
            }
            
            // Handle file uploads for Progress Update
            if (isset($_FILES['progress_documents']) && !empty($_FILES['progress_documents']['name'][0])) {
                $progress_files = handleFileUploads($_FILES['progress_documents'], $risk_id, 'progress_update', $db);
            }
            
            $db->commit();
            // Redirect to clear form and show success message
            header("Location: report_risk.php?success=1");
            exit();
        } else {
            $db->rollback();
            $error = "Failed to report comprehensive risk.";
        }
    } catch (Exception $e) {
        $db->rollback();
        $error = 'Error: ' . $e->getMessage();
    }
}

// Get notifications using shared component
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
        
        /* Header */
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
        
        /* Navigation Bar */
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
        
        /* Notification styles */
        .notification-nav-item {
            position: relative;
        }
        .nav-notification-container {
            position: relative;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            padding: 1rem 1.5rem;
            border-radius: 0.25rem;
            transition: all 0.3s ease;
            color: #6c757d;
            text-decoration: none;
        }
        .nav-notification-container:hover {
            background-color: rgba(230, 0, 18, 0.05);
            color: #E60012;
            text-decoration: none;
        }
        .nav-notification-container.nav-notification-empty {
            opacity: 0.6;
            cursor: default;
        }
        .nav-notification-container.nav-notification-empty:hover {
            background-color: transparent;
            color: #6c757d;
        }
        .nav-notification-bell {
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }
        .nav-notification-container:hover .nav-notification-bell {
            transform: scale(1.1);
        }
        .nav-notification-bell.has-notifications {
            color: #ffc107;
            animation: navBellRing 2s infinite;
        }
        @keyframes navBellRing {
            0%, 50%, 100% { transform: rotate(0deg); }
            10%, 30% { transform: rotate(-10deg); }
            20%, 40% { transform: rotate(10deg); }
        }
        .nav-notification-text {
            font-size: 0.9rem;
            font-weight: 500;
        }
        .nav-notification-badge {
            background: #dc3545;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            animation: navPulse 2s infinite;
            margin-left: auto;
        }
        @keyframes navPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }
        
        /* Notification Dropdown Styles */
        .nav-notification-dropdown {
            position: fixed !important;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            width: 400px;
            max-height: 500px;
            z-index: 1000;
            display: none;
            transition: all 0.3s ease;
            transform: translateY(-10px);
        }
        .nav-notification-dropdown.show {
            display: block;
            transform: translateY(0);
            opacity: 1;
        }
        .nav-notification-header {
            padding: 1rem;
            border-bottom: 1px solid #dee2e6;
            font-weight: bold;
            color: #495057;
            background: #f8f9fa;
            border-radius: 0.5rem 0.5rem 0 0;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .nav-notification-content {
            max-height: 350px;
            overflow-y: auto;
            overflow-x: hidden;
            transition: max-height 0.3s ease;
            scrollbar-width: thin;
            scrollbar-color: #cbd5e0 #f7fafc;
            -webkit-overflow-scrolling: touch;
            scroll-behavior: smooth;
        }
        .nav-notification-content::-webkit-scrollbar {
            width: 8px;
        }
        .nav-notification-content::-webkit-scrollbar-track {
            background: #f7fafc;
            border-radius: 4px;
        }
        .nav-notification-content::-webkit-scrollbar-thumb {
            background: #cbd5e0;
            border-radius: 4px;
            transition: background 0.3s ease;
        }
        .nav-notification-content::-webkit-scrollbar-thumb:hover {
            background: #a0aec0;
        }
        .nav-notification-item {
            padding: 1rem;
            border-bottom: 1px solid #f8f9fa;
            transition: all 0.3s ease;
            position: relative;
        }
        .nav-notification-item:hover {
            background-color: #f8f9fa;
        }
        .nav-notification-item:last-child {
            border-bottom: none;
        }
        .nav-notification-item.read {
            display: none !important;
        }
        .nav-notification-item.unread {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
        }
        .nav-notification-title {
            font-weight: bold;
            color: #495057;
            margin-bottom: 0.25rem;
        }
        .nav-notification-risk {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }
        .nav-notification-message {
            color: #495057;
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
            background: #f8f9fa;
            padding: 0.5rem;
            border-radius: 0.25rem;
            border-left: 3px solid #007bff;
        }
        .nav-notification-date {
            color: #6c757d;
            font-size: 0.8rem;
            margin-bottom: 0.5rem;
        }
        .nav-notification-actions {
            margin-top: 0.5rem;
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .nav-notification-actions .btn {
            font-size: 0.8rem;
            padding: 0.25rem 0.5rem;
        }
        .flex {
            display: flex;
        }
        .justify-between {
            justify-content: space-between;
        }
        .items-center {
            align-items: center;
        }
        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }
        .btn-primary {
            background: #007bff;
        }
        .btn-primary:hover {
            background: #0056b3;
        }
        .btn-outline {
            background: transparent;
            color: #E60012;
            border: 1px solid #E60012;
        }
        .btn-outline:hover {
            background: #E60012;
            color: white;
        }
        .btn-secondary {
            background: #6c757d;
        }
        .btn-secondary:hover {
            background: #545b62;
        }
        .btn-warning {
            background: #ffc107;
            color: #212529;
        }
        .btn-warning:hover {
            background: #e0a800;
        }
        .btn-success {
            background: #28a745;
        }
        .btn-success:hover {
            background: #218838;
        }
        
        /* Main Content */
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
        
        /* File Upload Styles */
        .file-upload-section {
            background: #f8f9fa;
            border: 2px dashed #dee2e6;
            border-radius: 0.25rem;
            padding: 0.5rem;
            margin-top: 0.5rem;
            transition: all 0.3s;
        }
        .file-upload-section:hover {
            border-color: #E60012;
            background: #fff5f5;
        }
        .file-upload-section.dragover {
            border-color: #E60012;
            background: #fff5f5;
            transform: scale(1.01);
        }
        .file-input-wrapper {
            position: relative;
            display: inline-block;
            width: 100%;
        }
        .file-input {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        .file-input-label {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.4rem;
            border: 1px dashed #ccc;
            border-radius: 0.25rem;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
            font-size: 0.85rem;
        }
        .file-input-label:hover {
            border-color: #E60012;
            background: #fff5f5;
        }
        .file-list {
            margin-top: 0.5rem;
        }
        .file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.4rem;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
            margin-bottom: 0.4rem;
            font-size: 0.85rem;
        }
        .file-info {
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .file-name {
            font-weight: 500;
        }
        .file-size {
            color: #666;
            font-size: 0.8rem;
        }
        .file-remove {
            background: #dc3545;
            color: white;
            border: none;
            padding: 0.2rem 0.4rem;
            border-radius: 0.2rem;
            cursor: pointer;
            font-size: 0.75rem;
        }
        .file-remove:hover {
            background: #c82333;
        }
        .file-types-info {
            font-size: 0.75rem;
            color: #666;
            margin-top: 0.4rem;
            text-align: center;
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
        
        /* Responsive */
        @media (max-width: 768px) {
            body {
                padding-top: 200px;
            }
            
            .header {
                padding: 1.2rem 1.5rem;
            }
            
            .header-content {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .header-right {
                align-self: flex-end;
            }
            
            .main-title {
                font-size: 1.3rem;
            }
            
            .sub-title {
                font-size: 0.9rem;
            }
            
            .logout-btn {
                margin-left: 0;
                margin-top: 0.5rem;
            }
            
            .nav {
                top: 120px;
                padding: 0.25rem 0;
            }
            
            .nav-content {
                padding: 0 0.5rem;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            .nav-menu {
                flex-wrap: nowrap;
                justify-content: flex-start;
                gap: 0;
                min-width: max-content;
                padding: 0 0.5rem;
            }
            
            .nav-item {
                flex: 0 0 auto;
                min-width: 80px;
            }
            
            .nav-item a {
                padding: 0.75rem 0.5rem;
                font-size: 0.75rem;
                text-align: center;
                border-bottom: 3px solid transparent;
                border-left: none;
                width: 100%;
                white-space: nowrap;
                min-height: 44px;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 0.25rem;
            }
            
            .nav-item a.active {
                border-bottom-color: #E60012;
                border-left-color: transparent;
                background-color: rgba(230, 0, 18, 0.1);
            }
            
            .nav-item a:hover {
                background-color: rgba(230, 0, 18, 0.05);
            }
            
            .nav-notification-container {
                padding: 0.75rem 0.5rem;
                font-size: 0.75rem;
                min-width: 80px;
            }
            
            .nav-notification-text {
                font-size: 0.65rem;
            }
            
            .nav-notification-bell {
                font-size: 1rem;
            }
            
            .nav-notification-badge {
                width: 16px;
                height: 16px;
                font-size: 0.6rem;
            }
            
            .nav-notification-dropdown {
                width: 95vw;
                left: 2.5vw !important;
                right: 2.5vw !important;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .risk-matrix {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
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
    
    <!-- Navigation Bar -->
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
                    // Use shared notifications component
                    if (isset($_SESSION['user_id'])) {
                        renderNotificationBar($all_notifications);
                    }
                    ?>
                </li>
            </ul>
        </div>
    </nav>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Risk Registration Form</h2>
                <div>
                    <a href="risk_owner_dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
                </div>
            </div>
            <div class="card-body">
                
                <!-- Progress Indicator -->
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
                    <!-- Section 1: Risk Identification -->
                    <div class="section-header">
                        <i class="fas fa-search"></i> Section 1: Risk Identification
                    </div>
                    
                    <!-- Replaced Risk Name with Risk Category dropdown -->
                    <div class="form-group">
                        <label class="form-label">Risk Category *</label>
                        <select name="risk_category" id="risk_category" class="form-control" required onchange="showCategoryInput()">
                            <option value="">Select Risk Category</option>
                            <option value="Financial Exposure">Financial Exposure [Revenue, Operating Expenditure, Book value]</option>
                            <option value="Decrease in market share">Decrease in market share</option>
                            <option value="Customer Experience">Customer Experience</option>
                            <option value="Compliance">Compliance</option>
                            <option value="Reputation">Reputation</option>
                            <option value="Fraud">Fraud</option>
                            <option value="Operations">Operations (Business continuity)</option>
                            <option value="Networks">Networks</option>
                            <option value="People">People</option>
                            <option value="IT">IT (Cybersecurity & Data Privacy)</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <!-- Added dynamic input field based on category selection -->
                    <div id="category_input_container" style="display: none;">
                        <div class="form-group" id="value_input" style="display: none;">
                            <label class="form-label">Value/Amount *</label>
                            <input type="number" name="category_value" class="form-control" step="0.01" placeholder="Enter numeric value">
                        </div>
                        
                        <div class="form-group" id="description_input" style="display: none;">
                            <label class="form-label">Description *</label>
                            <textarea name="category_description" class="form-control" rows="3" placeholder="Provide detailed description"></textarea>
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
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Department *</label>
                            <select name="department" class="form-control" required>
                                <option value="">Select Department</option>
                                <option value="Finance & Accounting" <?php echo ($user['department'] == 'Finance & Accounting') ? 'selected' : ''; ?>>Finance & Accounting</option>
                                <option value="Operations" <?php echo ($user['department'] == 'Operations') ? 'selected' : ''; ?>>Operations</option>
                                <option value="Technology & Security" <?php echo ($user['department'] == 'Technology & Security') ? 'selected' : ''; ?>>Technology & Security</option>
                                <option value="Human Resources" <?php echo ($user['department'] == 'Human Resources') ? 'selected' : ''; ?>>Human Resources</option>
                                <option value="Legal & Compliance" <?php echo ($user['department'] == 'Legal & Compliance') ? 'selected' : ''; ?>>Legal & Compliance</option>
                                <option value="Marketing & Sales" <?php echo ($user['department'] == 'Marketing & Sales') ? 'selected' : ''; ?>>Marketing & Sales</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Existing or New Risk *</label>
                            <select name="existing_or_new" class="form-control" required>
                                <option value="">Select Type</option>
                                <option value="New">New Risk</option>
                                <option value="Existing">Existing Risk</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Report to Board *</label>
                            <select name="to_be_reported_to_board" class="form-control" required>
                                <option value="">Select Option</option>
                                <option value="Yes">Yes</option>
                                <option value="No">No</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Section 2: Risk Assessment -->
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
                    
                    <!-- Section 3: Risk Treatment -->
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
                        
                        <!-- File Upload for Controls -->
                        <div class="file-upload-section">
                            <div class="file-input-wrapper">
                                <input type="file" name="controls_documents[]" class="file-input" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.jpg,.jpeg,.png" onchange="handleFileSelect(this, 'controls-files')">
                                <label class="file-input-label">
                                    <i class="fas fa-upload"></i>
                                    Upload Supporting Documents (Optional)
                                </label>
                            </div>
                            <div id="controls-files" class="file-list"></div>
                            <div class="file-types-info">
                                Supported: PDF, DOC, XLS, PPT, TXT, JPG, PNG (Max 10MB each)
                            </div>
                        </div>
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
                    
                    <!-- Section 4: Progress Update -->
                    <div class="section-header">
                        <i class="fas fa-chart-line"></i> Section 4: Progress Update
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Progress Update</label>
                        <textarea name="progress_update" class="form-control" placeholder="Provide updates on the progress of risk treatment activities"></textarea>
                        
                        <!-- File Upload for Progress -->
                        <div class="file-upload-section">
                            <div class="file-input-wrapper">
                                <input type="file" name="progress_documents[]" class="file-input" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.jpg,.jpeg,.png" onchange="handleFileSelect(this, 'progress-files')">
                                <label class="file-input-label">
                                    <i class="fas fa-upload"></i>
                                    Upload Progress Documents (Optional)
                                </label>
                            </div>
                            <div id="progress-files" class="file-list"></div>
                            <div class="file-types-info">
                                Supported: PDF, DOC, XLS, PPT, TXT, JPG, PNG (Max 10MB each)
                            </div>
                        </div>
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
                    
                    <!-- Submit Button -->
                    <div style="text-align: center; margin-top: 2rem; padding-top: 2rem; border-top: 1px solid #dee2e6;">
                        <button type="submit" name="submit_comprehensive_risk" class="btn" style="padding: 1rem 3rem; font-size: 1.1rem;">
                            <i class="fas fa-save"></i> Submit Risk Report
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Added JavaScript for dynamic form behavior -->
    <script>
    function showCategoryInput() {
        const category = document.getElementById('risk_category').value;
        const container = document.getElementById('category_input_container');
        const valueInput = document.getElementById('value_input');
        const descriptionInput = document.getElementById('description_input');
        
        // Hide all inputs first
        container.style.display = 'none';
        valueInput.style.display = 'none';
        descriptionInput.style.display = 'none';
        
        // Clear previous values
        document.querySelector('input[name="category_value"]').value = '';
        document.querySelector('textarea[name="category_description"]').value = '';
        
        if (category) {
            container.style.display = 'block';
            
            // Categories that require numeric values
            const valueCategories = ['Financial Exposure', 'Decrease in market share', 'Customer Experience', 'Fraud', 'Other'];
            
            // Categories that require text descriptions
            const descriptionCategories = ['Compliance', 'Reputation', 'Operations', 'Networks', 'People', 'IT'];
            
            if (valueCategories.includes(category)) {
                valueInput.style.display = 'block';
                document.querySelector('input[name="category_value"]').required = true;
                document.querySelector('textarea[name="category_description"]').required = false;
            } else if (descriptionCategories.includes(category)) {
                descriptionInput.style.display = 'block';
                document.querySelector('textarea[name="category_description"]').required = true;
                document.querySelector('input[name="category_value"]').required = false;
            }
        }
    }
    </script>

    <script>
        // Risk rating calculation
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
        
        // File handling
        function handleFileSelect(input, containerId) {
            const container = document.getElementById(containerId);
            container.innerHTML = '';
            
            if (input.files.length > 0) {
                for (let i = 0; i < input.files.length; i++) {
                    const file = input.files[i];
                    const fileItem = document.createElement('div');
                    fileItem.className = 'file-item';
                    
                    const fileInfo = document.createElement('div');
                    fileInfo.className = 'file-info';
                    
                    const fileName = document.createElement('span');
                    fileName.className = 'file-name';
                    fileName.textContent = file.name;
                    
                    const fileSize = document.createElement('span');
                    fileSize.className = 'file-size';
                    fileSize.textContent = `(${(file.size / 1024 / 1024).toFixed(2)} MB)`;
                    
                    const removeBtn = document.createElement('button');
                    removeBtn.className = 'file-remove';
                    removeBtn.textContent = '×';
                    removeBtn.type = 'button';
                    removeBtn.onclick = function() {
                        // Reset the input
                        input.value = '';
                        container.innerHTML = '';
                    };
                    
                    fileInfo.appendChild(fileName);
                    fileInfo.appendChild(fileSize);
                    fileItem.appendChild(fileInfo);
                    fileItem.appendChild(removeBtn);
                    container.appendChild(fileItem);
                }
            }
        }
        
        // Drag and drop functionality
        document.querySelectorAll('.file-upload-section').forEach(section => {
            section.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.classList.add('dragover');
            });
            
            section.addEventListener('dragleave', function(e) {
                e.preventDefault();
                this.classList.remove('dragover');
            });
            
            section.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('dragover');
                
                const input = this.querySelector('.file-input');
                const files = e.dataTransfer.files;
                
                // Create a new FileList-like object
                const dt = new DataTransfer();
                for (let i = 0; i < files.length; i++) {
                    dt.items.add(files[i]);
                }
                input.files = dt.files;
                
                // Trigger the change event
                const containerId = input.getAttribute('onchange').match(/'([^']+)'/)[1];
                handleFileSelect(input, containerId);
            });
        });
    </script>
</body>
</html>
