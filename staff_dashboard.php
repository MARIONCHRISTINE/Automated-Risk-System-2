<?php
include_once 'includes/auth.php';
requireRole('staff');
include_once 'config/database.php';
include_once 'includes/auto_assignment.php'; // Ensure this is included

$database = new Database();
$db = $database->getConnection();

if (isset($_POST['submit_risk'])) {
    $risk_categories = isset($_POST['risk_categories']) ? $_POST['risk_categories'] : [];
    $category_details = [];
    
    // Process category details
    foreach ($risk_categories as $category) {
        $field_name = 'category_details[' . $category . ']';
        if (isset($_POST['category_details'][$category]) && !empty($_POST['category_details'][$category])) {
            $category_details[$category] = $_POST['category_details'][$category];
        }
    }
    
    // Store JSON-encoded strings in variables before binding
    $risk_categories_json = json_encode($risk_categories);
    $category_details_json = json_encode($category_details);
    
    $user = getCurrentUser();
    if (empty($user['department']) || $user['department'] === null) {
        $dept_query = "SELECT department FROM users WHERE id = :user_id";
        $dept_stmt = $db->prepare($dept_query);
        $dept_stmt->bindParam(':user_id', $_SESSION['user_id']);
        $dept_stmt->execute();
        $dept_result = $dept_stmt->fetch(PDO::FETCH_ASSOC);
        if ($dept_result && !empty($dept_result['department'])) {
            $user['department'] = $dept_result['department'];
            $_SESSION['department'] = $dept_result['department'];
        }
    }
    $department = $user['department'] ?? 'General';

    // Handle file upload (optional)
    $uploaded_file_path = null;
    if (isset($_FILES['risk_document']) && $_FILES['risk_document']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/risk_documents/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $file_extension = strtolower(pathinfo($_FILES['risk_document']['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png'];
        if (in_array($file_extension, $allowed_extensions)) {
            $file_name = uniqid() . '_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $file_name;
            if (move_uploaded_file($_FILES['risk_document']['tmp_name'], $upload_path)) {
                $uploaded_file_path = $upload_path;
            }
        }
    }

    // Verify user session and database user
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $error_message = "Session expired. Please log in again.";
        header("Location: login.php");
        exit();
    }

    $user_check_query = "SELECT id FROM users WHERE id = :user_id";
    $user_check_stmt = $db->prepare($user_check_query);
    $user_check_stmt->bindParam(':user_id', $_SESSION['user_id']);
    $user_check_stmt->execute();
    if ($user_check_stmt->rowCount() == 0) {
        $error_message = "User account not found. Please contact administrator.";
    } else {
        try {
            $query = "INSERT INTO risk_incidents (
                        risk_categories, 
                        category_details, 
                        risk_description, 
                        cause_of_risk, 
                        department, 
                        reported_by, 
                        document_path, 
                        created_at, 
                        updated_at,
                        status,
                        risk_status
                      ) VALUES (
                        :risk_categories, 
                        :category_details, 
                        :risk_description, 
                        :cause_of_risk, 
                        :department, 
                        :reported_by, 
                        :document_path, 
                        NOW(), 
                        NOW(),
                        'open',
                        'Not Started'
                      )";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':risk_categories', $risk_categories_json);
            $stmt->bindParam(':category_details', $category_details_json);
            $stmt->bindParam(':risk_description', $_POST['risk_description']);
            $stmt->bindParam(':cause_of_risk', $_POST['cause_of_risk']);
            $stmt->bindParam(':department', $department);
            $stmt->bindParam(':reported_by', $_SESSION['user_id']);
            $stmt->bindParam(':document_path', $uploaded_file_path);

            if ($stmt->execute()) {
                $risk_id = $db->lastInsertId();
                
                // Call auto-assignment function
                $assignment_result = assignRiskAutomatically($risk_id, $_SESSION['user_id'], $db);

                if ($assignment_result['success']) {
                    header("Location: staff_dashboard.php?success=assigned");
                    exit();
                } else {
                    header("Location: staff_dashboard.php?success=no_owner_designated");
                    exit();
                }
            } else {
                $error_message = "Failed to report risk. Please try again.";
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
            error_log("Risk submission error: " . $e->getMessage());
        }
    }
}

// Handle success messages from redirect
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'assigned':
            $success_message = "Risk reported and immediately assigned to your designated risk owner!";
            break;
        case 'no_owner_designated':
            $success_message = "Risk reported successfully! No designated risk owner found for your account. Please contact your administrator.";
            break;
        case 'reported': // Fallback for previous logic, can be removed if not needed
            $success_message = "Risk reported successfully! Assignment in progress.";
            break;
        case 'no_owner': // Fallback for previous logic, can be removed if not needed
            $success_message = "Risk reported successfully! No risk owners available in your department at the moment.";
            break;
    }
}

// Get user's reported risks
$query = "SELECT * FROM risk_incidents WHERE reported_by = :user_id ORDER BY created_at DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$user_risks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current user info with department from database
$user = getCurrentUser();
// If department is not in session, fetch from database
if (empty($user['department']) || $user['department'] === null) {
    $dept_query = "SELECT department FROM users WHERE id = :user_id";
    $dept_stmt = $db->prepare($dept_query);
    $dept_stmt->bindParam(':user_id', $_SESSION['user_id']);
    $dept_stmt->execute();
    $dept_result = $dept_stmt->fetch(PDO::FETCH_ASSOC);
    if ($dept_result && !empty($dept_result['department'])) {
        $user['department'] = $dept_result['department'];
        // Store in session for future use
        $_SESSION['department'] = $dept_result['department'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard - Airtel Risk Management</title>
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
            padding-top: 100px; /* Add padding for fixed header */
        }
        .dashboard {
            min-height: 100vh;
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
        /* Main Content */
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }
        /* Main Cards Layout */
        .main-cards-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }
        /* Hero Section */
        .hero {
            text-align: center;
            padding: 5rem 3rem;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 300px;
        }
        .cta-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #E60012;
            color: white;
            padding: 1.5rem 2.5rem;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 600;
            font-size: 1.1rem;
            transition: background 0.3s;
            border: none;
            cursor: pointer;
        }
        .cta-button:hover {
            background: #B8000E;
        }
        /* Stats Card */
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 3rem 2rem;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-height: 300px;
            position: relative;
            border-left: 6px solid #E60012;
        }
        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        .stat-number {
            font-size: 4.5rem;
            font-weight: 800;
            color: #E60012;
            margin-bottom: 1rem;
        }
        .stat-label {
            font-size: 1.4rem;
            font-weight: 600;
            color: #666;
            margin-bottom: 1.5rem;
        }
        .stat-hint {
            color: #E60012;
            font-size: 1rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        /* Reports Section */
        .reports-section {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            display: none;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .reports-section.show {
            display: block;
        }
        .reports-header {
            background: #E60012;
            padding: 1.5rem;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .reports-title {
            font-size: 1.3rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .hide-reports-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 0.5rem 1rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 4px;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s;
        }
        .hide-reports-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        .reports-content {
            max-height: 400px;
            overflow-y: auto;
        }
        .risk-item {
            padding: 1.5rem;
            border-bottom: 1px solid #eee;
        }
        .risk-item:hover {
            background: #f8f9fa;
        }
        .risk-item:last-child {
            border-bottom: none;
        }
        .risk-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .risk-name {
            font-weight: 600;
            color: #333;
            flex: 1;
        }
        .view-btn {
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
            background: #E60012;
            color: white;
            border: none;
            cursor: pointer;
            transition: background 0.3s;
        }
        .view-btn:hover {
            background: #B8000E;
        }
        .risk-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 0.9rem;
            color: #666;
        }
        .empty-state {
            padding: 3rem 2rem;
            text-align: center;
        }
        .empty-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        .empty-state h3 {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #333;
        }
        .empty-state p {
            color: #666;
            margin-bottom: 1.5rem;
        }
        .success {
            background: #d4edda;
            color: #155724;
            padding: 1.2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            border-left: 4px solid #28a745;
            font-weight: 500;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 1.2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            border-left: 4px solid #dc3545;
            font-weight: 500;
        }
        .chatbot {
            position: fixed;
            bottom: 25px;
            right: 25px;
            width: 65px;
            height: 65px;
            background: linear-gradient(135deg, #E60012 0%, #B8000E 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 8px 25px rgba(230, 0, 18, 0.3);
            color: white;
            font-size: 1.6rem;
            transition: transform 0.3s;
            z-index: 1000;
        }
        .chatbot:hover {
            transform: scale(1.1);
        }
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background: white;
            border-radius: 8px;
            width: 95%;
            max-width: 900px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        .modal-header {
            background: #E60012;
            color: white;
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin: 0;
        }
        .close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
        }
        .close:hover {
            opacity: 0.7;
        }
        .modal-body {
            padding: 2rem;
        }
        .form-group {
            margin-bottom: 1.8rem;
        }
        label {
            display: block;
            margin-bottom: 0.6rem;
            color: #333;
            font-weight: 500;
            font-size: 1rem;
        }
        input, textarea, select {
            width: 100%;
            padding: 0.9rem;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: #E60012;
            box-shadow: 0 0 0 3px rgba(230, 0, 18, 0.1);
        }
        textarea {
            height: 120px;
            resize: vertical;
        }
        .btn {
            background: #E60012;
            color: white;
            border: none;
            padding: 0.9rem 2rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: background 0.3s;
        }
        .btn:hover {
            background: #B8000E;
        }
        /* File upload styles */
        .file-upload-area {
            border: 2px dashed #e1e5e9;
            border-radius: 8px;
            padding: 1.2rem; /* Reduced padding */
            text-align: center;
            transition: border-color 0.3s;
            background: #f8f9fa;
        }
        .file-upload-area:hover {
            border-color: #E60012;
        }
        .file-upload-area.dragover {
            border-color: #E60012;
            background: rgba(230, 0, 18, 0.05);
        }
        .file-upload-icon {
            font-size: 1.8rem; /* Adjusted font size */
            color: #666;
            margin-bottom: 0.8rem; /* Adjusted margin */
        }
        .file-upload-text {
            color: #666;
            margin-bottom: 0.4rem; /* Adjusted margin */
            font-size: 0.95rem; /* Adjusted font size */
        }
        .file-upload-hint {
            font-size: 0.8rem; /* Adjusted font size */
            color: #999;
        }
        .file-input {
            display: none;
        }
        .file-selected {
            background: #d4edda;
            border-color: #28a745;
            color: #155724;
        }
        /* Responsive */
        @media (max-width: 768px) {
            body {
                padding-top: 120px; /* Increased padding for mobile header */
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
            .main-cards-layout {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            .hero {
                padding: 3rem 2rem;
                min-height: 250px;
            }
            .stat-card {
                padding: 2.5rem 1.5rem;
                min-height: 250px;
            }
            .stat-number {
                font-size: 3.5rem;
            }
            .stat-label {
                font-size: 1.2rem;
            }
            .stat-hint {
                font-size: 0.9rem;
            }
            .reports-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            .main-content {
                padding: 1rem;
            }
            .risk-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            .logout-btn {
                margin-left: 0;
                margin-top: 0.5rem;
            }
            .modal-content {
                width: 95%;
                margin: 1rem;
            }
            .modal-body {
                padding: 1.5rem;
            }
        }
        /* Added styles for risk categories */
        /* Updated CSS to match report_risk.php exactly */
        /* Completely replacing CSS to match report_risk.php beautiful design */
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
    </style>
</head>
<body>
    <div class="dashboard">
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
                    <div class="user-avatar"><?php echo isset($_SESSION['full_name']) ? strtoupper(substr($_SESSION['full_name'], 0, 1)) : 'S'; ?></div>
                    <div class="user-details">
                        <div class="user-email"><?php echo $_SESSION['email']; ?></div>
                        <div class="user-role">Staff • <?php echo $user['department'] ?? 'General'; ?></div>
                    </div>
                    <a href="logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
        </header>
        <!-- Main Content -->
        <main class="main-content">
            <?php if (isset($success_message)): ?><div class="success">
    ✅ <?php echo $success_message; ?></div><?php endif; ?>
            <?php if (isset($error_message)): ?>
                <div class="error">❌ <?php echo $error_message; ?></div>
            <?php endif; ?>
            <!-- Main Cards Layout -->
            <div class="main-cards-layout">
                <!-- Hero Section -->
                <section class="hero">
    <div style="text-align: center;">
        <button class="cta-button" onclick="openReportModal()">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
            </svg>
            Report New Risk
        </button>
    </div></section>
                <!-- Stats Card -->
                <div class="stat-card" id="statsCard" onclick="scrollToReports()">
                    <div class="stat-number"><?php echo count($user_risks); ?></div>
                    <div class="stat-label">Risks Reported</div>
                    <div class="stat-hint">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                        </svg>
                        Click to view details
                    </div>
                </div>
            </div>
            <!-- Workflow Explanation Section -->
            <!-- Info Section -->
            <!-- Reports Section -->
            <section class="reports-section" id="reportsSection">
                <div class="reports-header">
                    <h2 class="reports-title">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        Your Recent Reports
                    </h2>
                    <button class="hide-reports-btn" onclick="closeReports()">Click to Hide</button>
                </div>
                <div class="reports-content">
                    <?php if (count($user_risks) > 0): ?>
                        <?php foreach (array_slice($user_risks, 0, 10) as $risk): ?>
    <div class="risk-item">
        <div class="risk-header">
            <!-- Display risk categories instead of risk_name -->
            <div class="risk-name"><?php echo htmlspecialchars($risk['risk_categories']); ?></div>
            <div style="display: flex; gap: 0.5rem; align-items: center;">
                <?php if ($risk['risk_owner_id']): ?>
                    <span style="background: #d4edda; color: #155724; padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem; font-weight: 600;">
                        ✅ Assigned
                    </span>
                <?php else: ?>
                    <span style="background: #fff3cd; color: #856404; padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem; font-weight: 600;">
                        🔄 Assigning...
                    </span>
                <?php endif; ?>
                <button class="view-btn" onclick="viewRisk(<?php echo $risk['id']; ?>, '<?php echo htmlspecialchars($risk['risk_categories']); ?>', '<?php echo htmlspecialchars($risk['risk_description']); ?>', '<?php echo htmlspecialchars($risk['cause_of_risk']); ?>', '<?php echo $risk['created_at']; ?>', <?php echo $risk['risk_owner_id'] ? 'true' : 'false'; ?>)">
                    View
                </button>
            </div>
        </div>
        <div class="risk-meta">
            <span><?php echo date('M d, Y', strtotime($risk['created_at'])); ?></span>
            <?php if ($risk['risk_owner_id']): ?>
                <span style="color:rgb(204, 11, 11);">• Risk Owner Assigned</span>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-icon">📋</div>
                            <h3>No risks reported yet</h3>
                            <p>Start by reporting your first risk using the button above.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
    <!-- Risk Report Modal -->
    <div id="reportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Report New Risk</h3>
                <button class="close" onclick="closeReportModal()">&times;</button>
            </div>
            <div class="modal-body">
                <!-- Removed staff risk reporting process explanation -->
                
                <form id="riskForm" method="POST" enctype="multipart/form-data" style="background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <!-- Simplified form - only 4 fields for staff -->
                    <div class="form-group">
                        <!-- Completely replaced risk categories structure to match report_risk.php -->
                        
                        <!-- Updated risk categories to match the detailed structure from new attachment -->
                        <label class="form-label">Risk Categories * <small>(Select all that apply)</small></label>
                        <div class="risk-categories-container">
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Financial Exposure" onchange="toggleCategoryInput(this)">
                                    <span class="checkmark">Financial Exposure [Revenue, Operating Expenditure, Book value]</span>
                                </label>
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

                    <div class="form-group">
                        <label for="risk_description"><strong>2. Risk Description</strong> <span style="color: red;">*</span></label>
                        <textarea id="risk_description" name="risk_description" required placeholder="Describe the risk in detail - what exactly is the problem or potential issue?"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="cause_of_risk"><strong>3. Cause of Risk</strong> <span style="color: red;">*</span></label>
                        <textarea id="cause_of_risk" name="cause_of_risk" required placeholder="What causes this risk? What are the root causes or contributing factors?"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="risk_document"><strong>4. Supporting Document</strong> (Optional)</label>
                        <div class="file-upload-area" id="fileUploadArea" onclick="document.getElementById('risk_document').click()">
                            <div class="file-upload-icon">📄</div>
                            <div class="file-upload-text">Click to upload a document</div>
                            <div class="file-upload-hint">Supports: PDF, DOC, DOCX, TXT, JPG, PNG (Max 10MB)</div>
                        </div>
                        <input type="file" id="risk_document" name="risk_document" class="file-input" accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png" onchange="handleFileSelect(this)">
                    </div>
                    
                    <!-- Removed "what happens next" explanation -->
                    
                    <button type="submit" name="submit_risk" style="background:rgb(245, 6, 6); color: white; padding: 0.75rem 1.5rem; border: none; border-radius: 4px; cursor: pointer; font-size: 1rem; width: 100%;">
                        Submit Risk Report
                    </button>
                </form>
            </div>
        </div>
    </div>
    <!-- Risk Details Modal -->
    <div id="riskModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Risk Details</h3>
                <button class="close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <!-- Changed label from "Risk Name" to "Risk Category" -->
                    <label>Risk Category:</label>
                    <div style="background: #f8f9fa; padding: 1rem; border-radius: 4px; border-left: 3px solid #E60012;" id="modalRiskCategory"></div>
                </div>
                <div class="form-group">
                    <label>Risk Description:</label>
                    <div style="background: #f8f9fa; padding: 1rem; border-radius: 4px; border-left: 3px solid #E60012;" id="modalRiskDescription"></div>
                </div>
                <div class="form-group">
                    <label>Cause of Risk:</label>
                    <div style="background: #f8f9fa; padding: 1rem; border-radius: 4px; border-left: 3px solid #E60012;" id="modalCauseOfRisk"></div>
                </div>
                <div class="form-group">
                    <label>Date Submitted:</label>
                    <div style="background: #f8f9fa; padding: 1rem; border-radius: 4px; border-left: 3px solid #E60012;" id="modalDateSubmitted"></div>
                </div><div class="form-group">
    <label>Assignment Status:</label>
    <div style="background: #f8f9fa; padding: 1rem; border-radius: 4px; border-left: 3px solid #E60012;" id="modalAssignmentStatus"></div>
</div>
            </div>
        </div>
    </div>
    <div class="chatbot" onclick="openChatbot()" title="Need help? Click to chat">💬</div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const statsCard = document.getElementById('statsCard');
            const reportsSection = document.getElementById('reportsSection');
            // Hide reports section by default - user must click to view
            if (reportsSection) {
                reportsSection.style.display = 'none';
                reportsSection.classList.remove('show');
            }
            if (statsCard && <?php echo count($user_risks); ?> > 0) {
                statsCard.addEventListener('click', function() {
                    toggleReports();
                });
            }
        });
        function handleFileSelect(input) {
            const fileUploadArea = document.getElementById('fileUploadArea');
            const file = input.files[0];
            if (file) {
                // Check file size (10MB limit)
                if (file.size > 10 * 1024 * 1024) {
                    alert('File size must be less than 10MB');
                    input.value = '';
                    return;
                }
                fileUploadArea.classList.add('file-selected');
                fileUploadArea.innerHTML = `
                    <div class="file-upload-icon">✅</div>
                    <div class="file-upload-text">File selected: ${file.name}</div>
                    <div class="file-upload-hint">Click to change file</div>
                `;
            } else {
                fileUploadArea.classList.remove('file-selected');
                fileUploadArea.innerHTML = `
                    <div class="file-upload-icon">📄</div>
                    <div class="file-upload-text">Click to upload a document</div>
                    <div class="file-upload-hint">Supports: PDF, DOC, DOCX, TXT, JPG, PNG (Max 10MB)</div>
                `;
            }
        }
        // Drag and drop functionality
        document.addEventListener('DOMContentLoaded', function() {
            const fileUploadArea = document.getElementById('fileUploadArea');
            const fileInput = document.getElementById('risk_document');
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                fileUploadArea.addEventListener(eventName, preventDefaults, false);
            });
            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }
            ['dragenter', 'dragover'].forEach(eventName => {
                fileUploadArea.addEventListener(eventName, highlight, false);
            });
            ['dragleave', 'drop'].forEach(eventName => {
                fileUploadArea.addEventListener(eventName, unhighlight, false);
            });
            function highlight(e) {
                fileUploadArea.classList.add('dragover');
            }
            function unhighlight(e) {
                fileUploadArea.classList.remove('dragover');
            }
            fileUploadArea.addEventListener('drop', handleDrop, false);
            function handleDrop(e) {
                const dt = e.dataTransfer;
                const files = dt.files;
                if (files.length > 0) {
                    fileInput.files = files;
                    handleFileSelect(fileInput);
                }
            }
        });
        function toggleReports() {
            const reportsSection = document.getElementById('reportsSection');
            if (reportsSection.classList.contains('show')) {
                closeReports();
            } else {
                showReports();
            }
        }
        function showReports() {
            const reportsSection = document.getElementById('reportsSection');
            reportsSection.style.display = 'block';
            setTimeout(() => reportsSection.classList.add('show'), 10);
        }
        function closeReports() {
            const reportsSection = document.getElementById('reportsSection');
            reportsSection.classList.remove('show');
            setTimeout(() => reportsSection.style.display = 'none', 300);
        }
        function openReportModal() {
            document.getElementById('reportModal').classList.add('show');
        }
        function closeReportModal() {
            document.getElementById('reportModal').classList.remove('show');
        }
        function viewRisk(id, categories, description, cause, date, isAssigned) {
            document.getElementById('modalRiskCategory').textContent = categories || 'No categories specified';
            document.getElementById('modalRiskDescription').textContent = description;
            document.getElementById('modalCauseOfRisk').textContent = cause;
            const dateObj = new Date(date);
            document.getElementById('modalDateSubmitted').textContent = dateObj.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            
            // Add assignment status to modal
            const assignmentStatus = document.getElementById('modalAssignmentStatus');
            if (assignmentStatus) {
                if (isAssigned) {
                    assignmentStatus.innerHTML = '<span style="color: #28a745; font-weight: 600;">✅ Assigned to Risk Owner</span><br><small style="color: #666;">Risk owner is completing the full assessment and treatment plans.</small>';
                } else {
                    assignmentStatus.innerHTML = '<span style="color: #ffc107; font-weight: 600;">🔄 Assignment in Progress</span><br><small style="color: #666;">System is assigning this risk to a qualified risk owner.</small>';
                }
            }
            
            document.getElementById('riskModal').classList.add('show');
        }
        function closeModal() {
            document.getElementById('riskModal').classList.remove('show');
        }
        
        // Users can now only close the modal by clicking the X button
        /*
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.classList.remove('show');
                }
            });
        }
        */

        function openChatbot() {
            const responses = [
                "Hello! I'm here to help with risk reporting. What would you like to know?",
                "You can report risks using the 'Report New Risk' button. Make sure to be detailed in your descriptions.",
                "If you need help with risk categories, contact your risk owner or compliance team.",
                "For technical issues, please contact IT support at support@airtel.africa"
            ];
            const message = prompt("Hi! I'm your Airtel Risk Assistant. How can I help you today?\n\n• Risk reporting guidance\n• System help\n• Contact information\n\nType your question:");
            if (message) {
                const randomResponse = responses[Math.floor(Math.random() * responses.length)];
                alert("Thank you for your question: '" + message + "'\n\n" + randomResponse + "\n\nFor more detailed assistance, please contact the compliance team.");
            }
        }
        
        function scrollToReports() {
            const reportsSection = document.getElementById('reportsSection');
            if (reportsSection) {
                // Show reports if hidden
                if (!reportsSection.classList.contains('show')) {
                    showReports();
                }
                // Smooth scroll to reports section
                setTimeout(() => {
                    reportsSection.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }, 100);
            }
        }
        
        /*
        function toggleCategory(categoryId) {
            const checkbox = document.getElementById(categoryId);
            const categoryItem = checkbox.closest('.category-item');
            const inputDiv = document.getElementById(categoryId + '_input');
            
            // Toggle checkbox
            checkbox.checked = !checkbox.checked;
            
            // Toggle visual state
            if (checkbox.checked) {
                categoryItem.classList.add('selected');
                inputDiv.classList.add('show');
                setTimeout(() => {
                    const input = inputDiv.querySelector('input, textarea');
                    if (input) input.focus();
                }, 300);
            } else {
                categoryItem.classList.remove('selected');
                inputDiv.classList.remove('show');
                // Clear input value when unchecked
                const input = inputDiv.querySelector('input, textarea');
                if (input) input.value = '';
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const categoryInputs = document.querySelectorAll('.category-input input, .category-input textarea');
            categoryInputs.forEach(input => {
                input.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
                input.addEventListener('focus', function(e) {
                    e.stopPropagation();
                });
            });
            
            const categoryInputDivs = document.querySelectorAll('.category-input');
            categoryInputDivs.forEach(div => {
                div.classList.remove('show');
            });
        });
        */

        /* Updated JavaScript to work with radio buttons instead of dropdowns */
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
