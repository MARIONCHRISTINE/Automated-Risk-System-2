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

if (!isset($_SESSION['temp_treatments'])) {
    $_SESSION['temp_treatments'] = [];
}

$treatments = $_SESSION['temp_treatments']; // Load treatments from session instead of database
$treatment_docs = [];
$risk_owners = [];

// Get risk owners for user assignment
$risk_owners_query = "SELECT id, full_name, department FROM users WHERE role IN ('risk_owner', 'admin') ORDER BY full_name";
$risk_owners_stmt = $db->prepare($risk_owners_query);
$risk_owners_stmt->execute();
$risk_owners = $risk_owners_stmt->fetchAll(PDO::FETCH_ASSOC);

$success = '';
$error = '';

if (isset($_GET['remove_treatment']) && isset($_GET['treatment_id'])) {
    $treatment_id = $_GET['treatment_id'];
    
    foreach ($_SESSION['temp_treatments'] as $key => $treatment) {
        if ($treatment['id'] === $treatment_id) {
            // Remove uploaded files
            foreach ($treatment['files'] as $file) {
                if (file_exists($file['file_path'])) {
                    unlink($file['file_path']);
                }
            }
            unset($_SESSION['temp_treatments'][$key]);
            $_SESSION['temp_treatments'] = array_values($_SESSION['temp_treatments']); // Reindex array
            break;
        }
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

if ($_POST && isset($_POST['cancel_risk'])) {
    // Clear session treatments and uploaded files
    if (isset($_SESSION['temp_treatments'])) {
        foreach ($_SESSION['temp_treatments'] as $treatment) {
            if (isset($treatment['files'])) {
                foreach ($treatment['files'] as $file) {
                    if (file_exists($file['file_path'])) {
                        unlink($file['file_path']);
                    }
                }
            }
        }
        unset($_SESSION['temp_treatments']);
    }
    
    // Clear any other session data related to this form
    unset($_SESSION['form_data']);
    
    // Redirect to dashboard or reload page to clear form
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

if ($_POST && isset($_POST['add_treatment'])) {
    try {
        $treatment_title = $_POST['treatment_title'] ?? '';
        $treatment_description = $_POST['treatment_description'] ?? '';
        $assigned_to = $_POST['assigned_to'] ?? $_SESSION['user_id'];
        $target_date = $_POST['treatment_target_date'] ?? null;

        if (empty($treatment_title) || empty($treatment_description)) {
            throw new Exception("Treatment title and description are required");
        }

        // Get assigned user info for display
        $assigned_user_query = "SELECT full_name FROM users WHERE id = :user_id";
        $assigned_user_stmt = $db->prepare($assigned_user_query);
        $assigned_user_stmt->bindParam(':user_id', $assigned_to);
        $assigned_user_stmt->execute();
        $assigned_user = $assigned_user_stmt->fetch(PDO::FETCH_ASSOC);

        // Create treatment array for session storage
        $treatment_data = [
            'id' => uniqid(), // Temporary ID for session management
            'treatment_title' => $treatment_title,
            'treatment_description' => $treatment_description,
            'assigned_to' => $assigned_to,
            'assigned_user_name' => $assigned_user['full_name'] ?? 'Unknown User',
            'target_completion_date' => $target_date,
            'status' => 'pending',
            'created_by' => $_SESSION['user_id'],
            'created_at' => date('Y-m-d H:i:s'),
            'files' => []
        ];

        // Handle file uploads for the new treatment
        if (isset($_FILES['treatment_files']) && !empty($_FILES['treatment_files']['name'][0])) {
            $files = $_FILES['treatment_files'];

            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $original_filename = $files['name'][$i];
                    $file_size = $files['size'][$i];
                    $file_type = $files['type'][$i];
                    $tmp_name = $files['tmp_name'][$i];

                    $file_extension = pathinfo($original_filename, PATHINFO_EXTENSION);
                    $unique_filename = uniqid() . '_' . time() . '.' . $file_extension;
                    $upload_path = 'uploads/temp_treatments/' . $unique_filename;

                    if (!file_exists('uploads/temp_treatments/')) {
                        mkdir('uploads/temp_treatments/', 0755, true);
                    }

                    if (move_uploaded_file($tmp_name, $upload_path)) {
                        $treatment_data['files'][] = [
                            'original_filename' => $original_filename,
                            'file_path' => $upload_path,
                            'file_size' => $file_size,
                            'file_type' => $file_type
                        ];
                    }
                }
            }
        }

        // Add treatment to session
        $_SESSION['temp_treatments'][] = $treatment_data;
        
        $_SESSION['success_message'] = "Treatment method added successfully!";
        header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
        exit();

    } catch (Exception $e) {
        $error_message = "Error adding treatment: " . $e->getMessage();
    }
}

if ($_POST && isset($_POST['submit_risk'])) {
    try {
        $db->beginTransaction();

        // Validate required fields
        if (empty($_POST['risk_description']) || empty($_POST['cause_of_risk'])) {
            throw new Exception('Risk description and cause are required');
        }
        
        $inherent_likelihood = $_POST['inherent_likelihood_level'] ?? null;
        $inherent_impact = $_POST['inherent_impact_level'] ?? null;
        
        if (empty($inherent_likelihood) || empty($inherent_impact)) {
            throw new Exception('Likelihood and impact must be selected');
        }
        
        $primary_risk_category = $_POST['risk_categories'] ?? '';
        $impact_descriptions = [
            '1' => 'Minor Impact',
            '2' => 'Moderate Impact', 
            '3' => 'Significant Impact',
            '4' => 'Extreme Impact'
        ];
        $impact_description = $impact_descriptions[$inherent_impact] ?? 'Unknown Impact';
        $risk_name = $primary_risk_category . ' - ' . $impact_description;
        
        $all_risk_categories = [];
        
        // Add primary risk category
        if (!empty($_POST['risk_categories'])) {
            $all_risk_categories[] = $_POST['risk_categories'];
        }
        
        // Add secondary risk categories
        $secondary_index = 1;
        while (isset($_POST["secondary_risk_category_$secondary_index"])) {
            $secondary_category = $_POST["secondary_risk_category_$secondary_index"];
            if (!empty($secondary_category)) {
                $all_risk_categories[] = $secondary_category;
            }
            $secondary_index++;
        }
        
        $risk_categories_json = json_encode($all_risk_categories);
        
        $all_likelihood_values = [];
        if (!empty($inherent_likelihood)) {
            $all_likelihood_values[] = $inherent_likelihood;
        }
        
        $secondary_index = 1;
        while (isset($_POST["secondary_likelihood_$secondary_index"])) {
            $secondary_likelihood = $_POST["secondary_likelihood_$secondary_index"];
            if (!empty($secondary_likelihood)) {
                $all_likelihood_values[] = $secondary_likelihood;
            }
            $secondary_index++;
        }
        
        $inherent_likelihood_values = implode(',', $all_likelihood_values);
        
        $all_consequence_values = [];
        if (!empty($inherent_impact)) {
            $all_consequence_values[] = $inherent_impact;
        }
        
        $secondary_index = 1;
        while (isset($_POST["secondary_impact_$secondary_index"])) {
            $secondary_impact = $_POST["secondary_impact_$secondary_index"];
            if (!empty($secondary_impact)) {
                $all_consequence_values[] = $secondary_impact;
            }
            $secondary_index++;
        }
        
        $inherent_consequence_values = implode(',', $all_consequence_values);
        
        $all_risk_ratings = [];
        $primary_risk_rating = intval($inherent_likelihood) * intval($inherent_impact);
        $all_risk_ratings[] = $primary_risk_rating;
        
        $secondary_index = 1;
        while (isset($_POST["secondary_likelihood_$secondary_index"]) && isset($_POST["secondary_impact_$secondary_index"])) {
            $sec_likelihood = $_POST["secondary_likelihood_$secondary_index"];
            $sec_impact = $_POST["secondary_impact_$secondary_index"];
            if (!empty($sec_likelihood) && !empty($sec_impact)) {
                $secondary_risk_rating = intval($sec_likelihood) * intval($sec_impact);
                $all_risk_ratings[] = $secondary_risk_rating;
            }
            $secondary_index++;
        }
        
        $risk_rating_values = implode(',', $all_risk_ratings);
        
        $control_assessment_values = implode(',', array_fill(0, count($all_risk_ratings), '1'));
        
        $all_residual_ratings = [];
        foreach ($all_risk_ratings as $rating) {
            $all_residual_ratings[] = $rating * 1; // control assessment is 1
        }
        $residual_rating_values = implode(',', $all_residual_ratings);
        
        $general_inherent_risk_score = $_POST['general_inherent_risk_score'] ?? null;
        $general_residual_risk_score = $_POST['general_residual_risk_score'] ?? null;
        
        $cause_of_risk_json = json_encode($_POST['cause_of_risk']);
        
        $query = "INSERT INTO risk_incidents (
            risk_name, risk_description, cause_of_risk, department, reported_by, risk_owner_id,
            existing_or_new, risk_categories,
            inherent_likelihood, inherent_consequence, 
            risk_rating, inherent_risk_level, residual_risk_level,
            control_assessment, residual_rating,
            general_inherent_risk_score, general_residual_risk_score,
            general_inherent_risk_level, general_residual_risk_level,
            involves_money_loss, money_amount,
            created_at, updated_at
        ) VALUES (
            :risk_name, :risk_description, :cause_of_risk, :department, :reported_by, :risk_owner_id,
            :existing_or_new, :risk_categories,
            :inherent_likelihood, :inherent_consequence,
            :risk_rating, :inherent_risk_level, :residual_risk_level,
            :control_assessment, :residual_rating,
            :general_inherent_risk_score, :general_residual_risk_score,
            :general_inherent_risk_level, :general_residual_risk_level,
            :involves_money_loss, :money_amount,
            NOW(), NOW()
        )";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':risk_name', $risk_name);
        $stmt->bindParam(':risk_description', $_POST['risk_description']);
        $stmt->bindParam(':cause_of_risk', $cause_of_risk_json);
        $stmt->bindParam(':department', $user['department']);
        $stmt->bindParam(':reported_by', $_SESSION['user_id']);
        $stmt->bindParam(':risk_owner_id', $_SESSION['user_id']);
        $stmt->bindParam(':existing_or_new', $_POST['existing_or_new']);
        $stmt->bindParam(':risk_categories', $risk_categories_json);
        $stmt->bindParam(':inherent_likelihood', $inherent_likelihood_values);
        $stmt->bindParam(':inherent_consequence', $inherent_consequence_values);
        $stmt->bindParam(':risk_rating', $risk_rating_values);
        $stmt->bindParam(':inherent_risk_level', $_POST['inherent_risk_level']);
        $stmt->bindParam(':residual_risk_level', $_POST['residual_risk_level']);
        $stmt->bindParam(':control_assessment', $control_assessment_values);
        $stmt->bindParam(':residual_rating', $residual_rating_values);
        $stmt->bindParam(':general_inherent_risk_score', $general_inherent_risk_score);
        $stmt->bindParam(':general_residual_risk_score', $general_residual_risk_score);
        $stmt->bindParam(':general_inherent_risk_level', $_POST['general_inherent_risk_level']);
        $stmt->bindParam(':general_residual_risk_level', $_POST['general_residual_risk_level']);
        $stmt->bindParam(':involves_money_loss', $_POST['involves_money_loss']);
        $stmt->bindParam(':money_amount', $_POST['money_amount']);
        
        if ($stmt->execute()) {
            $risk_id = $db->lastInsertId();
            
            
            if (isset($_FILES['controls_documents']) && !empty($_FILES['controls_documents']['name'][0])) {
                $controls_files = handleFileUploads($_FILES['controls_documents'], $risk_id, 'controls_action_plans', $db);
            }
            
            if (isset($_FILES['progress_documents']) && !empty($_FILES['progress_documents']['name'][0])) {
                $progress_files = handleFileUploads($_FILES['progress_documents'], $risk_id, 'progress_update', $db);
            }
            
            if (isset($_FILES['supporting_document']) && $_FILES['supporting_document']['error'] == UPLOAD_ERR_OK) {
                $supporting_files = handleFileUploads($_FILES['supporting_document'], $risk_id, 'supporting_documents', $db);
            }
            
            // After successful risk insertion, insert all treatments
            if (!empty($_SESSION['temp_treatments'])) {
                foreach ($_SESSION['temp_treatments'] as $treatment) {
                    $treatment_query = "INSERT INTO risk_treatments
                                       (risk_id, treatment_title, treatment_description, assigned_to, target_completion_date, status, created_by, created_at)
                                       VALUES (:risk_id, :title, :description, :assigned_to, :target_date, :status, :created_by, :created_at)";

                    $treatment_stmt = $db->prepare($treatment_query);
                    $treatment_stmt->bindParam(':risk_id', $risk_id); // Use the newly created risk_id
                    $treatment_stmt->bindParam(':title', $treatment['treatment_title']);
                    $treatment_stmt->bindParam(':description', $treatment['treatment_description']);
                    $treatment_stmt->bindParam(':assigned_to', $treatment['assigned_to']);
                    $treatment_stmt->bindParam(':target_date', $treatment['target_completion_date']);
                    $treatment_stmt->bindParam(':status', $treatment['status']);
                    $treatment_stmt->bindParam(':created_by', $treatment['created_by']);
                    $treatment_stmt->bindParam(':created_at', $treatment['created_at']);

                    if ($treatment_stmt->execute()) {
                        $treatment_id = $db->lastInsertId();

                        // Insert treatment files
                        foreach ($treatment['files'] as $file) {
                            // Move file from temp to permanent location
                            $permanent_path = str_replace('temp_treatments', 'treatments', $file['file_path']);
                            if (!file_exists('uploads/treatments/')) {
                                mkdir('uploads/treatments/', 0755, true);
                            }
                            
                            if (rename($file['file_path'], $permanent_path)) {
                                $file_query = "INSERT INTO treatment_documents
                                             (treatment_id, original_filename, file_path, file_size, file_type, uploaded_at)
                                             VALUES (:treatment_id, :original_filename, :file_path, :file_size, :file_type, NOW())";
                                $file_stmt = $db->prepare($file_query);
                                $file_stmt->bindParam(':treatment_id', $treatment_id);
                                $file_stmt->bindParam(':original_filename', $file['original_filename']);
                                $file_stmt->bindParam(':file_path', $permanent_path);
                                $file_stmt->bindParam(':file_size', $file['file_size']);
                                $file_stmt->bindParam(':file_type', $file['file_type']);
                                $file_stmt->execute();
                            }
                        }
                    }
                }
            }
            
            // Clear session treatments after successful submission
            unset($_SESSION['temp_treatments']);
            
            $db->commit();
            $_SESSION['success_message'] = "Risk and all treatment methods submitted successfully!";
            header("Location: risk_owner_dashboard.php?success=1");
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
        
        /* Updated styling for single selection radio buttons */
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

        /* Enhanced category name styling with color distinction for radio buttons */
        .radio-category-label {
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

        .radio-category-label:hover {
            background: linear-gradient(135deg,rgb(248, 4, 4),rgb(241, 2, 2));
            transform: translateY(-1px);
        }

        /* Updated radio-category-label to handle both radio and checkbox inputs */
        .radio-category-label input[type="radio"],
        .radio-category-label input[type="checkbox"] {
            margin-right: 12px;
            transform: scale(1.4);
            accent-color: white;
        }

        /* Cause of Risk checkbox styling */
        .cause-checkboxes {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 10px;
        }

        .cause-checkbox-item {
            padding: 15px;
            border: 2px solid #4caf50;
            border-radius: 8px;
            background: linear-gradient(135deg, #e8f5e8, #f1f8e9);
            transition: all 0.3s ease;
        }

        .cause-checkbox-item:hover {
            background: linear-gradient(135deg, #c8e6c9, #dcedc8);
            border-color: #388e3c;
            transform: scale(1.02);
            box-shadow: 0 4px 8px rgba(76, 175, 80, 0.3);
        }

        .cause-checkbox-label {
            display: flex;
            align-items: center;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            color: #2e7d32;
        }

        .cause-checkbox-label input[type="checkbox"] {
            margin-right: 10px;
            transform: scale(1.3);
            accent-color: #4caf50;
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
            
            .cause-checkboxes {
                grid-template-columns: 1fr;
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

        /* Added comprehensive treatment styling from manage-risk.php */
        /* Treatment Methods Section */
        .treatments-section {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border-top: 4px solid #17a2b8;
        }

        .treatment-card {
            background: #f8f9fa;
            border: 2px solid #dee2e6;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
        }

        .treatment-card:hover {
            border-color: #17a2b8;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(23, 162, 184, 0.2);
        }

        .treatment-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .treatment-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #17a2b8;
            margin: 0;
        }

        .treatment-status {
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-pending { background: #fff3cd; color: #856404; }
        .status-in-progress { background: #cce5ff; color: #004085; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-cancelled { background: #f8d7da; color: #721c24; }

        .treatment-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
            padding: 1rem;
            background: white;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }

        .meta-item {
            display: flex;
            flex-direction: column;
        }

        .meta-label {
            font-size: 0.8rem;
            color: #666;
            font-weight: 500;
            margin-bottom: 0.25rem;
        }

        .meta-value {
            font-weight: 600;
            color: #333;
        }

        .treatment-description {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid #17a2b8;
            margin: 1rem 0;
            line-height: 1.6;
        }

        .treatment-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }

        .add-treatment-btn {
            background: linear-gradient(135deg, #17a2b8, #138496);
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(23, 162, 184, 0.3);
        }

        .add-treatment-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(23, 162, 184, 0.4);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 0;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }

        .modal-header {
            background: linear-gradient(135deg, #17a2b8, #138496);
            color: white;
            padding: 1.5rem;
            border-radius: 12px 12px 0 0;
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
            transition: opacity 0.3s;
        }

        .close:hover {
            opacity: 0.7;
        }

        .modal-body {
            padding: 2rem;
        }

        /* User Selection Styles */
        .user-search-container {
            position: relative;
            margin-bottom: 1rem;
        }

        .user-search-input {
            width: 100%;
            padding: 0.9rem;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }

        .user-search-input:focus {
            outline: none;
            border-color: #17a2b8;
            box-shadow: 0 0 0 3px rgba(23, 162, 184, 0.1);
        }

        .user-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 2px solid #17a2b8;
            border-top: none;
            border-radius: 0 0 8px 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1001;
            display: none;
        }

        .user-dropdown.show {
            display: block;
        }

        .user-option {
            padding: 1rem;
            cursor: pointer;
            border-bottom: 1px solid #f8f9fa;
            transition: background-color 0.2s;
        }

        .user-option:hover {
            background-color: #f8f9fa;
        }

        .user-option:last-child {
            border-bottom: none;
        }

        .user-name {
            font-weight: 600;
            color: #333;
        }

        .user-dept {
            font-size: 0.9rem;
            color: #666;
            margin-top: 0.25rem;
        }

        /* File Upload Styles */
        .file-upload-area {
            border: 2px dashed #17a2b8;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }

        .file-upload-area:hover {
            border-color: #138496;
            background: #e9ecef;
        }

        .file-upload-area.dragover {
            border-color: #138496;
            background: #e3f2fd;
        }

        .upload-icon {
            font-size: 2.5rem;
            color: #17a2b8;
            margin-bottom: 1rem;
        }

        .file-upload-area h4 {
            color: #17a2b8;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .file-upload-area p {
            color: #666;
            margin-bottom: 0.5rem;
        }

        .file-upload-area small {
            color: #999;
        }

        .existing-files {
            margin: 1rem 0;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }

        .file-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e9ecef;
        }

        .file-item:last-child {
            border-bottom: none;
        }

        .file-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .file-icon {
            color: #17a2b8;
            font-size: 1.2rem;
        }

        .btn-info {
            background: #17a2b8;
            color: white;
        }

        .btn-info:hover {
            background: #138496;
        }

        .btn-outline {
            background: transparent;
            color: #17a2b8;
            border: 1px solid #17a2b8;
        }

        .btn-outline:hover {
            background: #17a2b8;
            color: white;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #545b62;
        }

        .readonly-display {
            padding: 0.75rem;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 5px;
            color: #333;
            font-weight: 500;
        }

        .required {
            color: #dc3545;
        }

        /* New styling for Risk Description textarea to match the category styling */
        .styled-textarea-container {
            background: #f8f9fa;
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 5px;
        }

        .styled-textarea {
            width: 100%;
            min-height: 120px;
            padding: 15px;
            border: 2px solid #e3f2fd;
            border-radius: 8px;
            background: linear-gradient(135deg, #fafafa, #f5f5f5);
            font-size: 16px;
            font-weight: 500;
            color: #333;
            resize: vertical;
            transition: all 0.3s ease;
        }

        .styled-textarea:focus {
            outline: none;
            border-color: #E60012;
            background: linear-gradient(135deg, #fff, #fafafa);
            box-shadow: 0 4px 8px rgba(230, 0, 18, 0.1);
            transform: translateY(-1px);
        }

        .styled-textarea:hover {
            border-color: #c5c5c5;
            background: linear-gradient(135deg, #fff, #fafafa);
        }

        /* New styling for file upload to match the category styling */
        .styled-file-container {
            background: #f8f9fa;
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 5px;
        }

        .styled-file-upload {
            position: relative;
            display: inline-block;
            width: 100%;
        }

        .styled-file-input {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .styled-file-label {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            border: 2px solid #e3f2fd;
            border-radius: 8px;
            background: linear-gradient(135deg, #fafafa, #f5f5f5);
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            font-size: 16px;
            color: #333;
            text-align: center;
        }

        .styled-file-label:hover {
            background: linear-gradient(135deg, #fff, #fafafa);
            border-color: #c5c5c5;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .styled-file-label i {
            margin-right: 10px;
            font-size: 20px;
            color: #17a2b8;
        }

        /* Risk badges */
        .risk-badge {
            display: inline-block;
            padding: 0.4em 0.6em;
            font-size: 0.8em;
            font-weight: 700;
            line-height: 1;
            color: #fff;
            text-align: center;
            white-space: nowrap;
            vertical-align: baseline;
            border-radius: 0.25rem;
        }

        .risk-badge.low {
            color: #000;
            background-color: #88dd88;
        }

        .risk-badge.medium {
            color: #000;
            background-color: #ffdd00;
        }

        .risk-badge.high {
            background-color: #ff8800;
        }

        .risk-badge.critical {
            background-color: #ff4444;
        }

        /* Added unified secondary risk assessment styling */
        .secondary-risk-assessment {
            margin: 20px 0;
            padding: 20px;
            background: #fff;
            border: 2px solid #E60012;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(230, 0, 18, 0.1);
        }

        .secondary-risk-header {
            color: #E60012;
            margin-bottom: 15px;
            font-weight: 600;
            font-size: 18px;
        }

        .secondary-risk-content {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .secondary-likelihood-section,
        .secondary-impact-section {
            flex: 1;
            min-width: 300px;
        }

        .secondary-likelihood-boxes,
        .secondary-impact-boxes {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 10px;
        }

        .secondary-likelihood-box,
        .secondary-impact-box {
            padding: 15px;
            border-radius: 8px;
            cursor: pointer;
            border: 3px solid transparent;
            text-align: center;
            font-weight: bold;
            transition: all 0.3s ease;
        }

        .secondary-likelihood-box:hover,
        .secondary-impact-box:hover {
            transform: scale(1.02);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .secondary-risk-rating-display {
            text-align: center;
            padding: 10px;
            margin-top: 15px;
            border-radius: 4px;
            font-weight: bold;
        }

        .next-risk-question {
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 6px;
        }

        .next-risk-options {
            display: flex;
            gap: 20px;
            margin-top: 10px;
        }

        .next-risk-option {
            display: flex;
            align-items: center;
            cursor: pointer;
            padding: 10px 15px;
            border: 2px solid #ddd;
            border-radius: 6px;
            transition: all 0.3s;
        }

        .next-risk-option:hover {
            border-color: #E60012;
            background-color: rgba(230, 0, 18, 0.05);
        }

        .next-risk-option input[type="radio"] {
            margin-right: 8px;
        }

        /* New styles for treatment section */
        .treatments-container {
            margin-top: 20px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            background: #f8f9fa;
        }

        .empty-treatments {
            text-align: center;
            padding: 20px;
            color: #666;
        }

        .empty-icon {
            font-size: 3em;
            margin-bottom: 10px;
        }

        .treatments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 15px;
        }

        .treatment-card {
            padding: 15px;
            border: 1px solid #ccc;
            border-radius: 6px;
            background: #fff;
        }

        .treatment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .status-badge {
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.8em;
            font-weight: bold;
            color: #fff;
        }

        .status-pending { background-color: #ffc107; }
        .status-in-progress { background-color: #007bff; }
        .status-completed { background-color: #28a745; }
        .status-cancelled { background-color: #dc3545; }

        .treatment-meta {
            margin-bottom: 10px;
            font-size: 0.9em;
            color: #666;
        }

        .meta-item {
            margin-bottom: 5px;
        }

        .treatment-description {
            margin-bottom: 10px;
            font-style: italic;
        }

        .treatment-actions {
            text-align: right;
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
                    // if (isset($_SESSION['user_id'])) {
                    //     renderNotificationBar($all_notifications);
                    // }
                    ?>
                </li>
            </ul>
        </div>
    </nav>
    
    <div class="main-content">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Risk Registration Form</h2>
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
                    
                    
                    <div class="form-group">
                        <label class="form-label">Risk Categories * <small>(Select one primary risk)</small></label>
                        <div class="risk-categories-container">
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="Financial Exposure" required>
                                    <span class="checkmark">Financial Exposure [Revenue, Operating Expenditure, Book value]</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="Decrease in market share" required>
                                    <span class="checkmark">Decrease in market share</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="Customer Experience" required>
                                    <span class="checkmark">Customer Experience</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="Compliance" required>
                                    <span class="checkmark">Compliance</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="Reputation" required>
                                    <span class="checkmark">Reputation</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="Fraud" required>
                                    <span class="checkmark">Fraud</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="Operations" required>
                                    <span class="checkmark">Operations (Business continuity)</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="Networks" required>
                                    <span class="checkmark">Networks</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="People" required>
                                    <span class="checkmark">People</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="IT" required>
                                    <span class="checkmark">IT (Cybersecurity & Data Privacy)</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="Other" required>
                                    <span class="checkmark">Other</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Does your risk involves loss of money? *</label>
                        <div class="risk-categories-container">
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="involves_money_loss" value="yes" onchange="toggleMoneyAmount(this)" required>
                                    <span class="checkmark">Yes</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="involves_money_loss" value="no" onchange="toggleMoneyAmount(this)" required>
                                    <span class="checkmark">No</span>
                                </label>
                            </div>
                        </div>
                        
                        <div id="money-amount-section" style="display: none; margin-top: 15px;">
                            <label class="form-label">Amount (in your local currency) *</label>
                            <input type="number" name="money_amount" class="form-control" placeholder="Enter the estimated amount" step="0.01" min="0">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Risk Description *</label>
                        <div class="styled-textarea-container">
                            <textarea name="risk_description" class="styled-textarea" required placeholder="Provide a detailed description of the risk"></textarea>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Cause of Risk *</label>
                        <!-- Updated cause of risk to use same styling as risk categories -->
                        <div class="risk-categories-container">
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="checkbox" name="cause_of_risk[]" value="People">
                                    <span class="checkmark">People</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="checkbox" name="cause_of_risk[]" value="Process">
                                    <span class="checkmark">Process</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="checkbox" name="cause_of_risk[]" value="IT Systems">
                                    <span class="checkmark">IT Systems</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="checkbox" name="cause_of_risk[]" value="External Environment">
                                    <span class="checkmark">External Environment</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Upload supporting document <small>(Optional)</small></label>
                        <div class="styled-file-container">
                            <div class="styled-file-upload">
                                <input type="file" name="supporting_document" class="styled-file-input" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.txt">
                                <label class="styled-file-label">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <span>Click to upload supporting document</span>
                                </label>
                            </div>
                            <small class="form-text text-muted" style="display: block; margin-top: 10px; color: #666; text-align: center;">Accepted formats: PDF, DOC, DOCX, JPG, JPEG, PNG, TXT (Max size: 10MB)</small>
                        </div>
                    </div>
                    
                    
                    
                    <div class="section-header">
                        <i class="fas fa-calculator"></i> Section 2: Risk Assessment
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">a. Existing or New Risk *</label>
                        <select name="existing_or_new" class="form-control" required>
                            <option value="">Select Type</option>
                            <option value="New">New Risk</option>
                            <option value="Existing">Existing Risk</option>
                        </select>
                    </div>
                    
                    <!-- Updated Section 2b to show dynamic risk category and restructured risk assessment -->
                    <div class="form-group">
                        <label class="form-label">b. Risk Rating</label>
                        
                        <!-- Dynamic sub-header showing selected risk category -->
                        <div id="selected-risk-category-header" style="display: none; margin: 15px 0; padding: 10px; background: #f8f9fa; border-left: 4px solid #E60012; border-radius: 4px;">
                            <h4 style="margin: 0; color: #E60012; font-size: 16px;">i. <span id="selected-category-name"></span></h4>
                        </div>
                        
                        <!-- Inherent Risk Section -->
                        <div style="margin: 20px 0; padding: 20px; background: #fff; border: 2px solid #28a745; border-radius: 8px;">
                            <h5 style="color: #28a745; margin-bottom: 15px; font-weight: 600;">Inherent Risk</h5>
                            
                            <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                                <div style="flex: 1; min-width: 300px;">
                                    <div class="form-group">
                                        <label class="form-label">Likelihood *</label>
                                        <div class="likelihood-boxes" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px;">
                                            
                                            <div class="likelihood-box" 
                                                 style="background-color: #ff4444; color: white; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                                                 onclick="selectInherentLikelihood(this, 4)"
                                                 data-value="4">
                                                <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">ALMOST CERTAIN</div>
                                                <div style="font-size: 12px; line-height: 1.3;">
                                                    1. Guaranteed to happen<br>
                                                    2. Has been happening<br>
                                                    3. Continues to happen
                                                </div>
                                            </div>
                                            
                                            <div class="likelihood-box" 
                                                 style="background-color: #ff8800; color: white; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                                                 onclick="selectInherentLikelihood(this, 3)"
                                                 data-value="3">
                                                <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">LIKELY</div>
                                                <div style="font-size: 12px; line-height: 1.3;">
                                                    A history of happening at certain intervals/seasons/events
                                                </div>
                                            </div>
                                            
                                            <div class="likelihood-box" 
                                                 style="background-color: #ffdd00; color: black; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                                                 onclick="selectInherentLikelihood(this, 2)"
                                                 data-value="2">
                                                <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">POSSIBLE</div>
                                                <div style="font-size: 12px; line-height: 1.3;">
                                                    1. More than 1 year from last occurrence<br>
                                                    2. Circumstances indicating or allowing possibility of happening
                                                </div>
                                            </div>
                                            
                                            <div class="likelihood-box" 
                                                 style="background-color: #88dd88; color: black; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                                                 onclick="selectInherentLikelihood(this, 1)"
                                                 data-value="1">
                                                <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">UNLIKELY</div>
                                                <div style="font-size: 12px; line-height: 1.3;">
                                                    1. Not occurred before<br>
                                                    2. This is the first time its happening<br>
                                                    3. Not expected to happen for sometime
                                                </div>
                                            </div>
                                            
                                        </div>
                                        <input type="hidden" name="inherent_likelihood_level" id="inherent_likelihood_value" required>
                                    </div>
                                </div>
                                
                                <div style="flex: 1; min-width: 300px;">
                                    <div class="form-group">
                                        <label class="form-label">Impact *</label>
                                        
                                        <!-- Removed dropdown menu, impact boxes now show based on selected risk category -->
                                        <div class="impact-boxes" id="inherent_impact_boxes_container" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px;">
                                            
                                            <div class="impact-box" id="inherent_extreme_box"
                                                 style="background-color: #ff4444; color: white; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                                                 onclick="selectInherentImpact(this, 4)"
                                                 data-value="4">
                                                <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">EXTREME</div>
                                                <div id="inherent_extreme_text" style="font-size: 12px; line-height: 1.3;"></div>
                                            </div>
                                            
                                            <div class="impact-box" id="inherent_significant_box"
                                                 style="background-color: #ff8800; color: white; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                                                 onclick="selectInherentImpact(this, 3)"
                                                 data-value="3">
                                                <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">SIGNIFICANT</div>
                                                <div id="inherent_significant_text" style="font-size: 12px; line-height: 1.3;"></div>
                                            </div>
                                            
                                            <div class="impact-box" id="inherent_moderate_box"
                                                 style="background-color: #ffdd00; color: black; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                                                 onclick="selectInherentImpact(this, 2)"
                                                 data-value="2">
                                                <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">MODERATE</div>
                                                <div id="inherent_moderate_text" style="font-size: 12px; line-height: 1.3;"></div>
                                            </div>
                                            
                                            <div class="impact-box" id="inherent_minor_box"
                                                 style="background-color: #88dd88; color: black; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                                                 onclick="selectInherentImpact(this, 1)"
                                                 data-value="1">
                                                <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">MINOR</div>
                                                <div id="inherent_minor_text" style="font-size: 12px; line-height: 1.3;"></div>
                                            </div>
                                            
                                        </div>
                                        <input type="hidden" name="inherent_impact_level" id="inherent_impact_value" required>
                                    </div>
                                </div>
                            </div>
                            
                            <input type="hidden" name="inherent_risk_rating" id="inherent_risk_rating">
                            <!-- Added hidden fields to capture risk level words -->
                            <input type="hidden" name="inherent_risk_level" id="inherent_risk_level">
                            <input type="hidden" name="residual_risk_level" id="residual_risk_level">
                            <div id="inherent_risk_display" class="rating-display" style="margin-top: 15px;">Inherent Risk Rating will appear here</div>
                        </div>
                        
                        <!-- Residual Risk Section (placeholder for future implementation) -->
                        <div style="margin: 20px 0; padding: 20px; background: #fff; border: 2px solid #ffc107; border-radius: 8px;">
                            <h5 style="color: #ffc107; margin-bottom: 15px; font-weight: 600;">Residual Risk</h5>
                            <input type="hidden" name="residual_risk_rating" id="residual_risk_rating">
                            <div id="residual_risk_display" class="rating-display" style="margin-top: 15px;">Residual Risk Rating will appear here</div>
                        </div>
                        
                        <!-- Completely replaced secondary risk section with unified system -->
                        <div style="margin: 20px 0; padding: 20px; background: #fff; border: 2px solid #6f42c1; border-radius: 8px;">
                            <h5 style="color: #6f42c1; margin-bottom: 15px; font-weight: 600;">Additional Risk Assessment</h5>
                            
                            <!-- Container for all secondary risks -->
                            <div id="secondary-risks-container"></div>
                            
                            <!-- Initial question for first secondary risk -->
                            <div id="initial-secondary-question" class="next-risk-question">
                                <label style="font-weight: 600; color: #333; margin-bottom: 10px; display: block;">Is there any other triggered risk?</label>
                                <div class="next-risk-options">
                                    <label class="next-risk-option">
                                        <input type="radio" name="has_secondary_risk" value="yes" onclick="showSecondaryRiskSelection()">
                                        <span style="font-weight: 500;">Yes</span>
                                    </label>
                                    <label class="next-risk-option">
                                        <input type="radio" name="has_secondary_risk" value="no" onclick="hideSecondaryRiskSelection()">
                                        <span style="font-weight: 500;">No</span>
                                    </label>
                                </div>
                            </div>
                            
                            <!-- Secondary risk selection (initially hidden) -->
                            <div id="secondary-risk-selection" style="display: none; margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee;">
                                <div class="form-group">
                                    <label class="form-label">Risk Categories * <small>(Select one secondary risk)</small></label>
                                    <div class="risk-categories-container" id="secondary-risk-categories">
                                        <!-- Categories will be populated dynamically -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Added Section 2c: GENERAL RISK SCORE -->
                    <div class="form-group">
                        <label class="form-label">c. GENERAL RISK SCORE</label>
                        
                        <!-- General Inherent Risk Score Display -->
                        <div style="margin: 20px 0; padding: 20px; background: #fff; border: 2px solid #17a2b8; border-radius: 8px;">
                            <h5 style="color: #17a2b8; margin-bottom: 15px; font-weight: 600;">General Inherent Risk Score</h5>
                            <div id="general_inherent_risk_display" class="rating-display" style="margin-top: 15px; font-size: 16px; font-weight: 600; padding: 15px; background: #f8f9fa; border-radius: 4px; text-align: center;">
                                General Inherent Risk Score will appear here
                            </div>
                            <input type="hidden" name="general_inherent_risk_score" id="general_inherent_risk_score">
                        </div>
                        
                        <!-- General Residual Risk Score Display -->
                        <div style="margin: 20px 0; padding: 20px; background: #fff; border: 2px solid #dc3545; border-radius: 8px;">
                            <h5 style="color: #dc3545; margin-bottom: 15px; font-weight: 600;">General Residual Risk Score</h5>
                            <div id="general_residual_risk_display" class="rating-display" style="margin-top: 15px; font-size: 16px; font-weight: 600; padding: 15px; background: #f8f9fa; border-radius: 4px; text-align: center;">
                                General Residual Risk Score will appear here
                            </div>
                            <input type="hidden" name="general_residual_risk_score" id="general_residual_risk_score">
                            </div>
                            <!-- Added hidden fields for general risk level words -->
                            <input type="hidden" name="general_inherent_risk_level" id="general_inherent_risk_level">
                            <input type="hidden" name="general_residual_risk_level" id="general_residual_risk_level">
                        </div>
                    </div>

                    <!-- Reordered sections - Section 3 is now General Risk Status -->
                    <div class="form-section">
                        <div class="section-header">
                            <i class="fas fa-chart-line"></i> SECTION 3: GENERAL RISK STATUS
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Overall Risk Status *</label>
                            <select name="general_risk_status" class="form-control" required>
                                <option value="">Select Status</option>
                                <option value="Open">Open</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Closed">Closed</option>
                                <option value="On Hold">On Hold</option>
                            </select>
                        </div>
                    </div>

                    <!-- Replaced treatment section with exact implementation from manage-risk.php -->
                    <!-- Section 4 is now Risk Treatments with treatment management subsection -->
                    <div class="form-section">
                        <div class="section-header">
                            <i class="fas fa-cogs"></i> SECTION 4: RISK TREATMENTS
                        </div>

                        <!-- Section 4: Risk Treatment Methods -->
                        <div class="form-section">
                            <div class="section-header">
                                <h3><i class="fas fa-shield-alt"></i> Section 4: Risk Treatment Methods</h3>
                                <p>Define how this risk will be managed and mitigated</p>
                            </div>

                            <div class="treatments-container">
                                <?php if (empty($treatments)): ?>
                                    <div class="empty-treatments">
                                        <div class="empty-icon">
                                            <i class="fas fa-plus-circle"></i>
                                        </div>
                                        <h4>No Treatment Methods Added</h4>
                                        <p>Add your first treatment method to begin risk mitigation planning</p>
                                        <button type="button" class="btn btn-info" onclick="openAddTreatmentModal()">
                                            <i class="fas fa-plus"></i> Add First Treatment Method
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div class="treatments-grid">
                                        <?php foreach ($treatments as $treatment): ?>
                                            <div class="treatment-card">
                                                <div class="treatment-header">
                                                    <h4><?php echo htmlspecialchars($treatment['treatment_title']); ?></h4>
                                                    <span class="status-badge status-<?php echo $treatment['status']; ?>">
                                                        <?php echo ucfirst($treatment['status']); ?>
                                                    </span>
                                                </div>
                                                
                                                <div class="treatment-meta">
                                                    <div class="meta-item">
                                                        <i class="fas fa-user"></i>
                                                        <span>Assigned to: <?php echo htmlspecialchars($treatment['assigned_user_name']); ?></span>
                                                    </div>
                                                    
                                                    <?php if (!empty($treatment['target_completion_date'])): ?>
                                                        <div class="meta-item">
                                                            <i class="fas fa-calendar"></i>
                                                            <span>Target: <?php echo date('M j, Y', strtotime($treatment['target_completion_date'])); ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (!empty($treatment['files'])): ?>
                                                        <div class="meta-item">
                                                            <i class="fas fa-paperclip"></i>
                                                            <span><?php echo count($treatment['files']); ?> file(s) attached</span>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <div class="treatment-description">
                                                    <?php 
                                                    $description = $treatment['treatment_description'];
                                                    echo htmlspecialchars(strlen($description) > 100 ? substr($description, 0, 100) . '...' : $description);
                                                    ?>
                                                </div>
                                                
                                                <div class="treatment-actions">
                                                    <!-- Changed to simple link to avoid form nesting issues -->
                                                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>?remove_treatment=1&treatment_id=<?php echo urlencode($treatment['id']); ?>" 
                                                       class="btn btn-danger btn-sm" 
                                                       onclick="return confirm('Are you sure you want to remove this treatment?');">
                                                        <i class="fas fa-trash"></i> Remove
                                                    </a>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <div style="text-align: center; margin-top: 2rem;">
                                        <button type="button" class="btn btn-info" onclick="openAddTreatmentModal()">
                                            <i class="fas fa-plus"></i> Add Another Treatment Method
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Added treatment modals from manage-risk.php -->
                    <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                        <!-- Separate form for cancel button to avoid main form validation -->
                        <form method="POST" style="display: inline;">
                            <!-- Added JavaScript to clear all form fields before submission -->
                            <button type="button" class="btn btn-secondary" onclick="clearEntireForm()">Cancel</button>
                        </form>
                        <!-- Added value attribute to submit button to fix form submission -->
                        <button type="submit" name="submit_risk" value="1" class="btn">
                            <i class="fas fa-save"></i> Submit Risk Report
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Treatment Modal -->
    <div id="addTreatmentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Add New Treatment Method</h3>
                <button type="button" class="close" onclick="closeAddTreatmentModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" enctype="multipart/form-data" id="addTreatmentForm">
                    <div class="form-group">
                        <label for="treatment_title">Treatment Title <span class="required">*</span></label>
                        <input type="text" id="treatment_title" name="treatment_title" class="form-control" required placeholder="e.g., Implement Security Controls">
                    </div>

                    <div class="form-group">
                        <label for="treatment_description">Treatment Description <span class="required">*</span></label>
                        <textarea id="treatment_description" name="treatment_description" class="form-control" required placeholder="Describe the specific treatment approach and actions to be taken..."></textarea>
                    </div>

                    <div class="form-group">
                        <label for="assigned_to">Assign To</label>
                        <div class="user-search-container">
                            <input type="text" id="user_search" class="user-search-input" placeholder="Search by name or department..." autocomplete="off">
                            <input type="hidden" id="assigned_to" name="assigned_to" value="<?php echo htmlspecialchars($_SESSION['user_id']); ?>">
                            <div id="user_dropdown" class="user-dropdown"></div>
                        </div>
                        <small style="color: #666; margin-top: 0.5rem; display: block;">
                            Currently assigned to: <strong id="selected_user_display"><?php echo htmlspecialchars($user['full_name']); ?> (You)</strong>
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="treatment_target_date">Target Completion Date</label>
                        <input type="date" id="treatment_target_date" name="treatment_target_date" class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="treatment_files">Supporting Documents</label>
                        <div class="file-upload-area" onclick="document.getElementById('treatment_files').click()">
                            <div class="upload-icon">
                                <i class="fas fa-cloud-upload-alt"></i>
                            </div>
                            <h4>Upload Supporting Documents</h4>
                            <p>Click to upload or drag and drop files here</p>
                            <small>Supported formats: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG</small>
                            <input type="file" id="treatment_files" name="treatment_files[]" multiple style="display: none;" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">
                        </div>
                    </div>

                    <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                        <button type="button" class="btn btn-secondary" onclick="closeAddTreatmentModal()">Cancel</button>
                        <button type="submit" name="add_treatment" class="btn btn-info">
                            <i class="fas fa-plus"></i> Add Treatment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Update Treatment Modal -->
    <div id="updateTreatmentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Update Treatment Progress</h3>
                <button type="button" class="close" onclick="closeUpdateTreatmentModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" enctype="multipart/form-data" id="updateTreatmentForm">
                    <input type="hidden" id="update_treatment_id" name="treatment_id">

                    <div class="form-group">
                        <label>Treatment Title</label>
                        <div id="update_treatment_title_display" class="readonly-display"></div>
                    </div>

                    <div class="form-group">
                        <label for="treatment_status">Status</label>
                        <select id="treatment_status" name="treatment_status" class="form-control">
                            <option value="pending">Pending</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="progress_notes">Progress Notes</label>
                        <textarea id="progress_notes" name="progress_notes" class="form-control" placeholder="Add progress updates, challenges, or completion notes..."></textarea>
                    </div>

                    <div class="form-group">
                        <label for="treatment_update_files">Additional Documents</label>
                        <div class="file-upload-area" onclick="document.getElementById('treatment_update_files').click()">
                            <div class="upload-icon">
                                <i class="fas fa-chart-bar"></i>
                            </div>
                            <h4>Upload Progress Evidence</h4>
                            <p>Reports, screenshots, certificates, or other progress documentation</p>
                            <small>Evidence of progress, completion certificates, reports, etc.</small>
                            <input type="file" id="treatment_update_files" name="treatment_update_files[]" multiple style="display: none;" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">
                        </div>
                    </div>

                    <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                        <button type="button" class="btn btn-secondary" onclick="closeUpdateTreatmentModal()">Cancel</button>
                        <button type="submit" name="update_treatment" class="btn btn-info">
                            <i class="fas fa-save"></i> Update Treatment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
function confirmRemoveTreatment() {
    return true; // Allow immediate removal
}

function confirmCancelRisk() {
    return confirm('Are you sure you want to cancel? All risk treatments will be lost.');
}

document.addEventListener('DOMContentLoaded', function() {
    console.log('Risk form JavaScript loaded');
    
    // Track form submissions for debugging
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            console.log('Form submitted:', this);
        });
    });
});
        const riskOwners = <?php echo json_encode($risk_owners); ?>;

        let selectedRisks = [];
        let currentSecondaryRiskIndex = 0;
        
        const allRiskCategories = [
            { value: 'Financial Exposure', display: 'Financial Exposure [Revenue, Operating Expenditure, Book value]' },
            { value: 'Decrease in market share', display: 'Decrease in market share' },
            { value: 'Customer Experience', display: 'Customer Experience' },
            { value: 'Compliance', display: 'Compliance' },
            { value: 'Reputation', display: 'Reputation' },
            { value: 'Fraud', display: 'Fraud' },
            { value: 'Operations', display: 'Operations (Business continuity)' },
            { value: 'Networks', display: 'Networks' },
            { value: 'People', display: 'People' },
            { value: 'IT', display: 'IT (Cybersecurity & Data Privacy)' },
            { value: 'Other', display: 'Other' }
        ];

        // Enhanced file upload handling
        document.addEventListener('DOMContentLoaded', function() {
            const fileInputs = document.querySelectorAll('input[type="file"]');

            fileInputs.forEach(input => {
                input.addEventListener('change', function() {
                    const files = this.files;
                    const uploadArea = this.parentElement;

                    if (files.length > 0) {
                        let fileNames = [];
                        let totalSize = 0;

                        for (let i = 0; i < files.length; i++) {
                            fileNames.push(files[i].name);
                            totalSize += files[i].size;
                        }

                        const totalSizeMB = (totalSize / (1024 * 1024)).toFixed(2);

                        uploadArea.innerHTML = `
                            <div class="upload-icon">
                                <i class="fas fa-check-circle" style="color: #28a745;"></i>
                            </div>
                            <h4 style="color: #28a745;">${files.length} File(s) Selected</h4>
                            <p style="font-size: 0.9rem; color: #666; margin: 0.5rem 0;">${fileNames.join(', ')}</p>
                            <small style="color: #999;">Total size: ${totalSizeMB} MB • Click to change files</small>
                        `;

                        uploadArea.style.transform = 'scale(1.02)';
                        setTimeout(() => {
                            uploadArea.style.transform = 'scale(1)';
                        }, 200);
                    }
                });
            });

            // Enhanced drag and drop functionality
            const uploadAreas = document.querySelectorAll('.file-upload-area');

            uploadAreas.forEach(area => {
                area.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    this.classList.add('dragover');
                });

                area.addEventListener('dragleave', function(e) {
                    e.preventDefault();
                    this.classList.remove('dragover');
                });

                area.addEventListener('drop', function(e) {
                    e.preventDefault();
                    this.classList.remove('dragover');

                    const fileInput = this.querySelector('input[type="file"]');
                    fileInput.files = e.dataTransfer.files;

                    const event = new Event('change', { bubbles: true });
                    fileInput.dispatchEvent(event);
                });
            });

            // User search functionality
            const userSearchInput = document.getElementById('user_search');
            const userDropdown = document.getElementById('user_dropdown');
            const assignedToInput = document.getElementById('assigned_to');
            const selectedUserDisplay = document.getElementById('selected_user_display');

            if (userSearchInput) {
                userSearchInput.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();

                    if (searchTerm.length < 2) {
                        userDropdown.classList.remove('show');
                        return;
                    }

                    const filteredUsers = riskOwners.filter(user =>
                        user.full_name.toLowerCase().includes(searchTerm) ||
                        user.department.toLowerCase().includes(searchTerm)
                    );

                    if (filteredUsers.length > 0) {
                        userDropdown.innerHTML = filteredUsers.map(user => `
                            <div class="user-option" onclick="selectUser(${user.id}, '${user.full_name}', '${user.department}')">
                                <div class="user-name">${user.full_name}</div>
                                <div class="user-dept">${user.department}</div>
                            </div>
                        `).join('');
                        userDropdown.classList.add('show');
                    } else {
                        userDropdown.innerHTML = '<div class="user-option">No users found</div>';
                        userDropdown.classList.add('show');
                    }
                });

                userSearchInput.addEventListener('blur', function() {
                    setTimeout(() => {
                        userDropdown.classList.remove('show');
                    }, 200);
                });
            }

            // Add event listeners to primary risk category radio buttons
            const riskCategoryRadios = document.querySelectorAll('input[name="risk_categories"]');
            riskCategoryRadios.forEach(radio => {
                radio.addEventListener('change', function() {
                    if (this.checked) {
                        selectedRisks = [this.value]; // Reset and add primary risk
                        updateRiskCategoryDisplay(this.value);
                        updateImpactBoxes(this.value);
                        updateSecondaryRiskCategories();
                    }
                });
            });
        });

        function selectUser(userId, userName, userDept) {
            document.getElementById('assigned_to').value = userId;
            document.getElementById('user_search').value = userName;
            document.getElementById('selected_user_display').textContent = `${userName} (${userDept})`;
            document.getElementById('user_dropdown').classList.remove('show');
        }

        // Treatment Modal Functions
        function openAddTreatmentModal() {
            document.getElementById('addTreatmentModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeAddTreatmentModal() {
            document.getElementById('addTreatmentModal').classList.remove('show');
            document.body.style.overflow = 'auto';
            document.getElementById('addTreatmentForm').reset();
        }

        function openUpdateTreatmentModal(treatmentId, title, status, progressNotes) {
            document.getElementById('update_treatment_id').value = treatmentId;
            document.getElementById('update_treatment_title_display').textContent = title;
            document.getElementById('treatment_status').value = status;
            document.getElementById('progress_notes').value = progressNotes || '';
            document.getElementById('updateTreatmentModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeUpdateTreatmentModal() {
            document.getElementById('updateTreatmentModal').classList.remove('show');
            document.body.style.overflow = 'auto';
            document.getElementById('updateTreatmentForm').reset();
        }

        function reassignTreatment(treatmentId) {
            // Implementation for reassigning treatment
            alert('Reassignment functionality to be implemented');
        }

        // Close modals when clicking outside
        window.addEventListener('click', function(event) {
            const addModal = document.getElementById('addTreatmentModal');
            const updateModal = document.getElementById('updateTreatmentModal');
            
            if (event.target === addModal) {
                closeAddTreatmentModal();
            }
            if (event.target === updateModal) {
                closeUpdateTreatmentModal();
            }
        });

        
        function toggleMoneyAmount(radio) {
            var moneyAmountSection = document.getElementById('money-amount-section');
            if (radio.value === 'yes') {
                moneyAmountSection.style.display = 'block';
            } else {
                moneyAmountSection.style.display = 'none';
            }
        }
        
        function selectLikelihood(element, value) {
            document.querySelectorAll('.likelihood-box').forEach(box => {
                box.style.border = '3px solid transparent';
            });
            element.style.border = '3px solid #E60012';
            document.getElementById('likelihood_value').value = value;
            calculateRiskRating();
        }
        
        function selectImpact(element, value) {
            console.log("Impact selected:", value);
            document.querySelectorAll('.impact-box').forEach(box => {
                box.style.border = '3px solid transparent';
            });
            element.style.border = '3px solid #E60012';
            document.getElementById('inherent_impact_value').value = value;
            calculateInherentRiskRating();
        }

        function selectInherentLikelihood(element, value) {
            document.querySelectorAll('.likelihood-box').forEach(box => {
                box.style.border = '3px solid transparent';
            });
            element.style.border = '3px solid #E60012';
            document.getElementById('inherent_likelihood_value').value = value;
            calculateInherentRiskRating();
        }
        
        function selectInherentImpact(element, value) {
            document.querySelectorAll('.impact-box').forEach(box => {
                box.style.border = '3px solid transparent';
            });
            element.style.border = '3px solid #E60012';
            document.getElementById('inherent_impact_value').value = value;
            calculateInherentRiskRating();
        }

        function calculateInherentRiskRating() {
            var likelihood = document.getElementById('inherent_likelihood_value').value;
            var impact = document.getElementById('inherent_impact_value').value;
            
            if (likelihood && impact) {
                var inherentRating = likelihood * impact;
                document.getElementById('inherent_risk_rating').value = inherentRating;
                
                displayInherentRiskRating(inherentRating);
                
                // Calculate residual risk: Inherent Risk × Control Assessment
                var controlAssessment = getControlAssessmentValue();
                var residualRating = inherentRating * controlAssessment;
                document.getElementById('residual_risk_rating').value = residualRating;
                
                displayResidualRiskRating(residualRating);
                
                updateRiskSummary(inherentRating, residualRating);
            }
        }

        function displayInherentRiskRating(rating) {
            var display = document.getElementById('inherent_risk_display');
            var ratingText = '';
            var riskLevel = ''; // Added variable to capture risk level word
            
            if (rating >= 12) {
                ratingText = 'CRITICAL (' + rating + ')';
                riskLevel = 'CRITICAL'; // Extract risk level word
                display.style.backgroundColor = '#ff4444';
                display.style.color = 'white';
            } else if (rating >= 8) {
                ratingText = 'HIGH (' + rating + ')';
                riskLevel = 'HIGH'; // Extract risk level word
                display.style.backgroundColor = '#ff8800';
                display.style.color = 'white';
            } else if (rating >= 4) {
                ratingText = 'MEDIUM (' + rating + ')';
                riskLevel = 'MEDIUM'; // Extract risk level word
                display.style.backgroundColor = '#ffdd00';
                display.style.color = 'black';
            } else {
                ratingText = 'LOW (' + rating + ')';
                riskLevel = 'LOW'; // Extract risk level word
                display.style.backgroundColor = '#88dd88';
                display.style.color = 'black';
            }
            
            display.textContent = 'Inherent Risk Rating: ' + ratingText;
            display.style.padding = '10px';
            display.style.borderRadius = '4px';
            display.style.fontWeight = 'bold';
            display.style.textAlign = 'center';
            display.style.fontStyle = 'normal';
            
            document.getElementById('inherent_risk_level').value = riskLevel;
        }

        function displayResidualRiskRating(rating) {
            var display = document.getElementById('residual_risk_display');
            var ratingText = '';
            var riskLevel = ''; // Added variable to capture risk level word
            
            if (rating >= 12) {
                ratingText = 'CRITICAL (' + rating + ')';
                riskLevel = 'CRITICAL'; // Extract risk level word
                display.style.backgroundColor = '#ff4444';
                display.style.color = 'white';
            } else if (rating >= 8) {
                ratingText = 'HIGH (' + rating + ')';
                riskLevel = 'HIGH'; // Extract risk level word
                display.style.backgroundColor = '#ff8800';
                display.style.color = 'white';
            } else if (rating >= 4) {
                ratingText = 'MEDIUM (' + rating + ')';
                riskLevel = 'MEDIUM'; // Extract risk level word
                display.style.backgroundColor = '#ffdd00';
                display.style.color = 'black';
            } else {
                ratingText = 'LOW (' + rating + ')';
                riskLevel = 'LOW'; // Extract risk level word
                display.style.backgroundColor = '#88dd88';
                display.style.color = 'black';
            }
            
            display.textContent = 'Residual Risk Rating: ' + ratingText;
            display.style.padding = '10px';
            display.style.borderRadius = '4px';
            display.style.fontWeight = 'bold';
            display.style.textAlign = 'center';
            
            document.getElementById('residual_risk_level').value = riskLevel;
            
            calculateGeneralRiskScores();
        }

        function updateRiskSummary(inherentRating, residualRating) {
            var summaryDiv = document.getElementById('overall_risk_summary');
            if (summaryDiv) {
                var summaryText = 'Final Risk Assessment: ';
                
                if (residualRating >= 12) {
                    summaryText += 'CRITICAL (' + residualRating + ')';
                } else if (residualRating >= 8) {
                    summaryText += 'HIGH (' + residualRating + ')';
                } else if (residualRating >= 4) {
                    summaryText += 'MEDIUM (' + residualRating + ')';
                } else {
                    summaryText += 'LOW (' + residualRating + ')';
                }
                
                summaryText += '<br><small>Inherent Risk: ' + inherentRating + ' × Control Assessment: 1 = Residual Risk: ' + residualRating + '</small>';
                summaryDiv.innerHTML = summaryText;
                summaryDiv.style.fontStyle = 'normal';
                summaryDiv.style.color = '#333';
            }
            
            calculateGeneralRiskScores();
        }
        
        function getControlAssessmentValue() {
            return 1; // Default value, can be modified later
        }

        function calculateGeneralRiskScores() {
            let totalInherentScore = 0;
            let totalResidualScore = 0;
            let riskCount = 0;
            
            // Get primary risk scores
            const primaryInherent = parseInt(document.getElementById('inherent_risk_rating').value) || 0;
            const primaryResidual = parseInt(document.getElementById('residual_risk_rating').value) || 0;
            
            if (primaryInherent > 0) {
                totalInherentScore += primaryInherent;
                totalResidualScore += primaryResidual;
                riskCount++;
            }
            
            // Get all secondary risk scores
            const secondaryRiskElements = document.querySelectorAll('[id^="secondary_risk_rating_"]');
            secondaryRiskElements.forEach(function(element) {
                const rating = parseInt(element.value) || 0;
                if (rating > 0) {
                    totalInherentScore += rating;
                    totalResidualScore += rating; // Using same value as inherent for residual (control assessment = 1)
                    riskCount++;
                }
            });
            
            // Calculate maximum possible score based on number of risks
            const maxScale = 16 * riskCount;
            
            // Update displays
            if (riskCount > 0) {
                displayGeneralInherentRisk(totalInherentScore, maxScale);
                displayGeneralResidualRisk(totalResidualScore, maxScale);
                
                // Store values in hidden inputs
                document.getElementById('general_inherent_risk_score').value = totalInherentScore;
                document.getElementById('general_residual_risk_score').value = totalResidualScore;
            }
        }
        
        function displayGeneralInherentRisk(score, maxScale) {
            const display = document.getElementById('general_inherent_risk_display');
            const ratingText = getGeneralRiskRating(score, maxScale);
            const riskLevel = ratingText.split(' ')[0];
            
            display.textContent = 'General Inherent Risk Score: ' + ratingText;
            applyGeneralRiskStyling(display, score, maxScale);
            
            document.getElementById('general_inherent_risk_level').value = riskLevel;
        }
        
        function displayGeneralResidualRisk(score, maxScale) {
            const display = document.getElementById('general_residual_risk_display');
            const ratingText = getGeneralRiskRating(score, maxScale);
            const riskLevel = ratingText.split(' ')[0];
            
            display.textContent = 'General Residual Risk Score: ' + ratingText;
            applyGeneralRiskStyling(display, score, maxScale);
            
            document.getElementById('general_residual_risk_level').value = riskLevel;
        }
        
        function getGeneralRiskRating(score, maxScale) {
            const lowThreshold = Math.floor(maxScale * 3 / 16);
            const mediumThreshold = Math.floor(maxScale * 7 / 16);
            const highThreshold = Math.floor(maxScale * 11 / 16);
            
            if (score > highThreshold) {
                return 'CRITICAL (' + score + ')';
            } else if (score > mediumThreshold) {
                return 'HIGH (' + score + ')';
            } else if (score > lowThreshold) {
                return 'MEDIUM (' + score + ')';
            } else {
                return 'LOW (' + score + ')';
            }
        }
        
        function applyGeneralRiskStyling(display, score, maxScale) {
            const lowThreshold = Math.floor(maxScale * 3 / 16);
            const mediumThreshold = Math.floor(maxScale * 7 / 16);
            const highThreshold = Math.floor(maxScale * 11 / 16);
            
            if (score > highThreshold) {
                display.style.backgroundColor = '#ff4444';
                display.style.color = 'white';
            } else if (score > mediumThreshold) {
                display.style.backgroundColor = '#ff8800';
                display.style.color = 'white';
            } else if (score > lowThreshold) {
                display.style.backgroundColor = '#ffdd00';
                display.style.color = 'black';
            } else {
                display.style.backgroundColor = '#88dd88';
                display.style.color = 'black';
            }
            
            display.style.padding = '10px';
            display.style.borderRadius = '4px';
            display.style.fontWeight = 'bold';
            display.style.textAlign = 'center';
        }
        
        function showSecondaryRiskSelection() {
            console.log("showSecondaryRiskSelection called");
            
            if (selectedRisks.length === 0) {
                alert("Please select a primary risk first before adding secondary risks.");
                // Reset the radio button to "No" since we're not showing the secondary selection
                const noRadio = document.querySelector('input[name="has_secondary_risk"][value="no"]');
                if (noRadio) {
                    noRadio.checked = true;
                }
                return;
            }
            
            const secondaryRiskSelection = document.getElementById('secondary-risk-selection');
            if (secondaryRiskSelection) {
                secondaryRiskSelection.style.display = 'block';
                updateSecondaryRiskCategories();
            }
        }

        function hideSecondaryRiskSelection() {
            console.log("hideSecondaryRiskSelection called");
            const secondaryRiskSelection = document.getElementById('secondary-risk-selection');
            if (secondaryRiskSelection) {
                secondaryRiskSelection.style.display = 'none';
            }
        }

        function updateSecondaryRiskCategories() {
            console.log("updateSecondaryRiskCategories called, selectedRisks:", selectedRisks);
            const container = document.getElementById('secondary-risk-categories');
            if (!container) return;

            // Filter out already selected risks
            const availableCategories = allRiskCategories.filter(category => 
                !selectedRisks.includes(category.value)
            );

            console.log("Available categories:", availableCategories);

            if (availableCategories.length === 0) {
                container.innerHTML = '<p style="text-align: center; color: #666; padding: 20px;">All risk categories have been selected.</p>';
                return;
            }

            // Generate category options
            container.innerHTML = availableCategories.map(category => `
                <div class="category-item" data-value="${category.value}">
                    <label class="radio-category-label">
                        <input type="radio" name="current_secondary_risk" value="${category.value}" onclick="selectSecondaryRisk('${category.value}', '${category.display}')">
                        <span class="checkmark">${category.display}</span>
                    </label>
                </div>
            `).join('');
        }

        function selectSecondaryRisk(value, displayText) {
            console.log("selectSecondaryRisk called with:", value, displayText);
            
            // Add to selected risks
            selectedRisks.push(value);
            currentSecondaryRiskIndex++;
            
            // Create secondary risk assessment
            createSecondaryRiskAssessment(value, displayText, currentSecondaryRiskIndex);
            
            // Hide the selection interface
            document.getElementById('secondary-risk-selection').style.display = 'none';
            
            // Reset the radio button
            const radioButtons = document.querySelectorAll('input[name="has_secondary_risk"]');
            radioButtons.forEach(radio => radio.checked = false);
            
            document.getElementById('initial-secondary-question').style.display = 'block';
        }

        function createSecondaryRiskAssessment(riskValue, riskDisplay, riskIndex) {
            console.log("createSecondaryRiskAssessment called for:", riskValue, riskIndex);
            
            const container = document.getElementById('secondary-risks-container');
            const romanNumerals = ['', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x', 'xi'];
            const romanNumeral = romanNumerals[riskIndex] || `risk_${riskIndex}`;
            
            const assessmentHtml = `
                <div class="secondary-risk-assessment" id="secondary-risk-${riskIndex}">
                    <div class="secondary-risk-header">
                        ${romanNumeral}. ${riskDisplay}
                    </div>
                    
                    <div class="secondary-risk-content">
                        <div class="secondary-likelihood-section">
                            <label class="form-label">Likelihood *</label>
                            <div class="secondary-likelihood-boxes">
                                <div class="secondary-likelihood-box" 
                                     style="background-color: #ff4444; color: white;"
                                     onclick="selectSecondaryLikelihood(${riskIndex}, this, 4)"
                                     data-value="4">
                                    <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">ALMOST CERTAIN</div>
                                    <div style="font-size: 12px; line-height: 1.3;">
                                        1. Guaranteed to happen<br>
                                        2. Has been happening<br>
                                        3. Continues to happen
                                    </div>
                                </div>
                                
                                <div class="secondary-likelihood-box" 
                                     style="background-color: #ff8800; color: white;"
                                     onclick="selectSecondaryLikelihood(${riskIndex}, this, 3)"
                                     data-value="3">
                                    <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">LIKELY</div>
                                    <div style="font-size: 12px; line-height: 1.3;">
                                        A history of happening at certain intervals/seasons/events
                                    </div>
                                </div>
                                
                                <div class="secondary-likelihood-box" 
                                     style="background-color: #ffdd00; color: black;"
                                     onclick="selectSecondaryLikelihood(${riskIndex}, this, 2)"
                                     data-value="2">
                                    <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">POSSIBLE</div>
                                    <div style="font-size: 12px; line-height: 1.3;">
                                        1. More than 1 year from last occurrence<br>
                                        2. Circumstances indicating or allowing possibility of happening
                                    </div>
                                </div>
                                
                                <div class="secondary-likelihood-box" 
                                     style="background-color: #88dd88; color: black;"
                                     onclick="selectSecondaryLikelihood(${riskIndex}, this, 1)"
                                     data-value="1">
                                    <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">UNLIKELY</div>
                                    <div style="font-size: 12px; line-height: 1.3;">
                                        1. Not occurred before<br>
                                        2. This is the first time its happening<br>
                                        3. Not expected to happen for sometime
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" name="secondary_likelihood_${riskIndex}" id="secondary_likelihood_${riskIndex}" required>
                        </div>
                        
                        <div class="secondary-impact-section">
                            <label class="form-label">Impact *</label>
                            <div class="secondary-impact-boxes" id="secondary_impact_boxes_${riskIndex}">
                                <!-- Impact boxes will be populated based on risk category -->
                            </div>
                            <input type="hidden" name="secondary_impact_${riskIndex}" id="secondary_impact_${riskIndex}" required>
                        </div>
                    </div>
                    
                    <input type="hidden" name="secondary_risk_rating_${riskIndex}" id="secondary_risk_rating_${riskIndex}">
                    <input type="hidden" name="secondary_risk_category_${riskIndex}" value="${riskValue}">
                    <div id="secondary_risk_display_${riskIndex}" class="secondary-risk-rating-display">Secondary Risk Rating will appear here</div>
                </div>
            `;
            
            container.innerHTML += assessmentHtml;
            
            // Update impact boxes for this secondary risk
            updateSecondaryImpactBoxes(riskIndex, riskValue);
        }

        function selectSecondaryLikelihood(riskIndex, element, value) {
            console.log("selectSecondaryLikelihood called:", riskIndex, value);
            
            // Reset all likelihood boxes for this risk
            const likelihoodBoxes = document.querySelectorAll(`#secondary-risk-${riskIndex} .secondary-likelihood-box`);
            likelihoodBoxes.forEach(box => {
                box.style.border = '3px solid transparent';
            });
            
            // Highlight selected box
            element.style.border = '3px solid #E60012';
            
            // Set hidden input value
            document.getElementById(`secondary_likelihood_${riskIndex}`).value = value;
            
            // Calculate risk rating
            calculateSecondaryRiskRating(riskIndex);
        }

        function selectSecondaryImpact(riskIndex, element, value) {
            console.log("selectSecondaryImpact called:", riskIndex, value);
            
            // Reset all impact boxes for this risk
            const impactBoxes = document.querySelectorAll(`#secondary-risk-${riskIndex} .secondary-impact-box`);
            impactBoxes.forEach(box => {
                box.style.border = '3px solid transparent';
            });
            
            // Highlight selected box
            element.style.border = '3px solid #E60012';
            
            // Set hidden input value
            document.getElementById(`secondary_impact_${riskIndex}`).value = value;
            
            // Calculate risk rating
            calculateSecondaryRiskRating(riskIndex);
        }

        function calculateSecondaryRiskRating(riskIndex) {
            console.log("calculateSecondaryRiskRating called for:", riskIndex);
            
            const likelihood = document.getElementById(`secondary_likelihood_${riskIndex}`).value;
            const impact = document.getElementById(`secondary_impact_${riskIndex}`).value;
            
            if (likelihood && impact) {
                const rating = likelihood * impact;
                document.getElementById(`secondary_risk_rating_${riskIndex}`).value = rating;
                displaySecondaryRiskRating(riskIndex, rating);
            }
        }

        function displaySecondaryRiskRating(riskIndex, rating) {
            const display = document.getElementById(`secondary_risk_display_${riskIndex}`);
            const controlAssessment = 1.0; // Default control assessment
            const residualRating = Math.round(rating * controlAssessment);
            
            let inherentRatingText = '';
            let residualRatingText = '';
            
            // Inherent Risk Rating
            if (rating >= 12) {
                inherentRatingText = 'CRITICAL (' + rating + ')';
            } else if (rating >= 8) {
                inherentRatingText = 'HIGH (' + rating + ')';
            } else if (rating >= 4) {
                inherentRatingText = 'MEDIUM (' + rating + ')';
            } else {
                inherentRatingText = 'LOW (' + rating + ')';
            }
            
            // Residual Risk Rating
            if (residualRating >= 12) {
                residualRatingText = 'CRITICAL (' + residualRating + ')';
                display.style.backgroundColor = '#ff4444';
                display.style.color = 'white';
            } else if (residualRating >= 8) {
                residualRatingText = 'HIGH (' + residualRating + ')';
                display.style.backgroundColor = '#ff8800';
                display.style.color = 'white';
            } else if (residualRating >= 4) {
                residualRatingText = 'MEDIUM (' + residualRating + ')';
                display.style.backgroundColor = '#ffdd00';
                display.style.color = 'black';
            } else {
                residualRatingText = 'LOW (' + residualRating + ')';
                display.style.backgroundColor = '#88dd88';
                display.style.color = 'black';
            }
            
            display.innerHTML = 'Secondary Inherent Risk Rating: ' + inherentRatingText + '<br>Secondary Residual Risk Rating: ' + residualRatingText;
            display.style.padding = '10px';
            display.style.borderRadius = '4px';
            display.style.fontWeight = 'bold';
            display.style.textAlign = 'center';
            
            calculateGeneralRiskScores();
        }

        function updateSecondaryImpactBoxes(riskIndex, categoryName) {
            console.log("updateSecondaryImpactBoxes called:", riskIndex, categoryName);
            
            const impacts = impactDefinitions[categoryName];
            if (!impacts) return;
            
            const container = document.getElementById(`secondary_impact_boxes_${riskIndex}`);
            if (!container) return;
            
            container.innerHTML = `
                <div class="secondary-impact-box" 
                     style="background-color: #ff4444; color: white;"
                     onclick="selectSecondaryImpact(${riskIndex}, this, 4)"
                     data-value="4">
                    <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">EXTREME</div>
                    <div style="font-size: 12px; line-height: 1.3;">${impacts.extreme.text}</div>
                </div>
                
                <div class="secondary-impact-box" 
                     style="background-color: #ff8800; color: white;"
                     onclick="selectSecondaryImpact(${riskIndex}, this, 3)"
                     data-value="3">
                    <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">SIGNIFICANT</div>
                    <div style="font-size: 12px; line-height: 1.3;">${impacts.significant.text}</div>
                </div>
                
                <div class="secondary-impact-box" 
                     style="background-color: #ffdd00; color: black;"
                     onclick="selectSecondaryImpact(${riskIndex}, this, 2)"
                     data-value="2">
                    <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">MODERATE</div>
                    <div style="font-size: 12px; line-height: 1.3;">${impacts.moderate.text}</div>
                </div>
                
                <div class="secondary-impact-box" 
                     style="background-color: #88dd88; color: black;"
                     onclick="selectSecondaryImpact(${riskIndex}, this, 1)"
                     data-value="1">
                    <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">MINOR</div>
                    <div style="font-size: 12px; line-height: 1.3;">${impacts.minor.text}</div>
                </div>
            `;
        }
        
        // Impact definitions for each risk category
        const impactDefinitions = {
            'Financial Exposure': {
                extreme: { text: 'EXTREME\n> $10M or > 10% of annual revenue', value: 4 },
                significant: { text: 'SIGNIFICANT\n$1M - $10M or 1 - 10% of annual revenue', value: 3 },
                moderate: { text: 'MODERATE\n$100K - $1M or 0.1-1% of annual revenue', value: 2 },
                minor: { text: 'MINOR\n< $100K or < 0.1% of annual revenue', value: 1 }
            },
            'Decrease in market share': {
                extreme: { text: 'EXTREME\n> 10% market share loss', value: 4 },
                significant: { text: 'SIGNIFICANT\n5-10% market share loss', value: 3 },
                moderate: { text: 'MODERATE\n1-5% market share loss', value: 2 },
                minor: { text: 'MINOR\n< 1% market share loss', value: 1 }
            },
            'Customer Experience': {
                extreme: { text: 'EXTREME\nMass customer exodus', value: 4 },
                significant: { text: 'SIGNIFICANT\nSignificant customer complaints', value: 3 },
                moderate: { text: 'MODERATE\nModerate customer dissatisfaction', value: 2 },
                minor: { text: 'MINOR\nMinimal customer inconvenience', value: 1 }
            },
            'Compliance': {
                extreme: { text: 'EXTREME\nPenalties > $1M', value: 4 },
                significant: { text: 'SIGNIFICANT\nPenalties $0.5M - $1M', value: 3 },
                moderate: { text: 'MODERATE\nPenalties $0.1M - $0.5M', value: 2 },
                minor: { text: 'MINOR\n< $0.1M', value: 1 }
            },
            'Reputation': {
                extreme: { text: 'EXTREME\nNational/International media coverage', value: 4 },
                significant: { text: 'SIGNIFICANT\nRegional media coverage', value: 3 },
                moderate: { text: 'MODERATE\nLocal media coverage', value: 2 },
                minor: { text: 'MINOR\nMinimal public awareness', value: 1 }
            },
            'Fraud': {
                extreme: { text: 'EXTREME\n> $1M fraudulent activity', value: 4 },
                significant: { text: 'SIGNIFICANT\n$100K - $1M fraudulent activity', value: 3 },
                moderate: { text: 'MODERATE\n$10K - $100K fraudulent activity', value: 2 },
                minor: { text: 'MINOR\n< $10K fraudulent activity', value: 1 }
            },
            'Operations': {
                extreme: { text: 'EXTREME\n> 7 days business disruption', value: 4 },
                significant: { text: 'SIGNIFICANT\n3-7 days business disruption', value: 3 },
                moderate: { text: 'MODERATE\n1-3 days business disruption', value: 2 },
                minor: { text: 'MINOR\n< 1 day business disruption', value: 1 }
            },
            'Networks': {
                extreme: { text: 'EXTREME\nComplete network failure', value: 4 },
                significant: { text: 'SIGNIFICANT\nMajor network disruption', value: 3 },
                moderate: { text: 'MODERATE\nModerate network issues', value: 2 },
                minor: { text: 'MINOR\nMinimal network glitches', value: 1 }
            },
            'People': {
                extreme: { text: 'EXTREME\nMass employee exodus', value: 4 },
                significant: { text: 'SIGNIFICANT\nKey personnel loss', value: 3 },
                moderate: { text: 'MODERATE\nModerate staff turnover', value: 2 },
                minor: { text: 'MINOR\nMinimal staff impact', value: 1 }
            },
            'IT': {
                extreme: { text: 'EXTREME\nMajor data breach/system failure', value: 4 },
                significant: { text: 'SIGNIFICANT\nSignificant security incident', value: 3 },
                moderate: { text: 'MODERATE\nModerate IT disruption', value: 2 },
                minor: { text: 'MINOR\nMinimal technical issues', value: 1 }
            },
            'Other': {
                extreme: { text: 'EXTREME\nSevere impact on operations', value: 4 },
                significant: { text: 'SIGNIFICANT\nMajor operational impact', value: 3 },
                moderate: { text: 'MODERATE\nModerate operational impact', value: 2 },
                minor: { text: 'MINOR\nMinimal operational impact', value: 1 }
            }
        };

        function updateRiskCategoryDisplay(categoryName) {
            const header = document.getElementById('selected-risk-category-header');
            const categoryNameSpan = document.getElementById('selected-category-name');
            
            if (header && categoryNameSpan) {
                categoryNameSpan.textContent = categoryName;
                header.style.display = 'block';
            }
        }

        function updateImpactBoxes(categoryName) {
            const impacts = impactDefinitions[categoryName];
            if (!impacts) return;
            
            // Update each impact box with category-specific content
            const extremeBox = document.getElementById('inherent_extreme_box');
            const significantBox = document.getElementById('inherent_significant_box');
            const moderateBox = document.getElementById('inherent_moderate_box');
            const minorBox = document.getElementById('inherent_minor_box');
            
            if (extremeBox) {
                extremeBox.innerHTML = `<div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">${impacts.extreme.text}</div>`;
                extremeBox.setAttribute('data-value', impacts.extreme.value);
            }
            
            if (significantBox) {
                significantBox.innerHTML = `<div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">${impacts.significant.text}</div>`;
                significantBox.setAttribute('data-value', impacts.significant.value);
            }
            
            if (moderateBox) {
                moderateBox.innerHTML = `<div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">${impacts.moderate.text}</div>`;
                moderateBox.setAttribute('data-value', impacts.moderate.value);
            }
            
            if (minorBox) {
                minorBox.innerHTML = `<div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">${impacts.minor.text}</div>`;
                minorBox.setAttribute('data-value', impacts.minor.value);
            }
        }
        
        
        // window.addEventListener('beforeunload', function(e) {
        //     // Only show warning if there are unsaved treatments
        //     if (<?php echo count($_SESSION['temp_treatments'] ?? []); ?> > 0) {
        //         e.preventDefault();
        //         e.returnValue = 'You have unsaved risk treatments. Are you sure you want to leave?';
        //         return e.returnValue;
        //     }
        // });

        function clearEntireForm() {
            // Clear all text inputs
            document.querySelectorAll('input[type="text"], input[type="number"], input[type="email"], input[type="tel"]').forEach(function(input) {
                input.value = '';
            });
            
            // Clear all textareas
            document.querySelectorAll('textarea').forEach(function(textarea) {
                textarea.value = '';
            });
            
            // Clear all select dropdowns
            document.querySelectorAll('select').forEach(function(select) {
                select.selectedIndex = 0;
            });
            
            // Clear all radio buttons
            document.querySelectorAll('input[type="radio"]').forEach(function(radio) {
                radio.checked = false;
            });
            
            // Clear all checkboxes
            document.querySelectorAll('input[type="checkbox"]').forEach(function(checkbox) {
                checkbox.checked = false;
            });
            
            // Clear all file inputs
            document.querySelectorAll('input[type="file"]').forEach(function(fileInput) {
                fileInput.value = '';
            });
            
            // Clear all hidden inputs (except CSRF tokens if any)
            document.querySelectorAll('input[type="hidden"]').forEach(function(hidden) {
                if (!hidden.name.includes('csrf') && !hidden.name.includes('token')) {
                    hidden.value = '';
                }
            });
            
            // Reset likelihood and impact selections
            document.querySelectorAll('.likelihood-box, .impact-box').forEach(function(box) {
                box.style.border = '3px solid transparent';
            });
            
            // Hide conditional sections
            document.getElementById('money-amount-section').style.display = 'none';
            document.getElementById('selected-risk-category-header').style.display = 'none';
            document.getElementById('secondary-risk-selection').style.display = 'none';
            
            // Clear risk rating displays
            document.getElementById('inherent_risk_display').innerHTML = 'Inherent Risk Rating will appear here';
            document.getElementById('residual_risk_display').innerHTML = 'Residual Risk Rating will appear here';
            document.getElementById('general_inherent_risk_display').innerHTML = 'General Inherent Risk Score will appear here';
            document.getElementById('general_residual_risk_display').innerHTML = 'General Residual Risk Score will appear here';
            
            selectedRisks = [];
            currentSecondaryRiskIndex = 0;
            
            // Only submit form to clear session data when explicitly called (not on page load)
            if (arguments[0] === 'submit') {
                var form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'cancel_risk';
                input.value = '1';
                
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            }
        }

        // that was causing the infinite loading loop

        document.addEventListener('DOMContentLoaded', function() {
            const submitButton = document.querySelector('button[name="submit_risk"]');
            if (submitButton) {
                submitButton.addEventListener('click', function(e) {
                    console.log('[v0] Submit button clicked');
                    // Let the form submit naturally without any preventDefault
                });
            }
        });

    </script>
</body>
</html>
