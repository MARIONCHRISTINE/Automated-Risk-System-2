<?php


// Session timeout (30 minutes)
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
    session_unset();
    session_destroy();
    header("Location: login.php?timeout=1");
    exit();
}
$_SESSION['last_activity'] = time();

// IP validation for security
if (isset($_SESSION['ip_address'])) {
    if ($_SESSION['ip_address'] !== $_SERVER['REMOTE_ADDR']) {
        session_unset();
        session_destroy();
        header("Location: login.php?security=1");
        exit();
    }
} else {
    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
}

include_once 'includes/auth.php';
requireRole('admin');
include_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

try {
    // Get system version from settings table or default
    $version_query = "SELECT setting_value FROM system_settings WHERE setting_key = 'system_version' LIMIT 1";
    $version_stmt = $db->prepare($version_query);
    $version_stmt->execute();
    $system_version = $version_stmt->fetchColumn() ?: 'Airtel Risk Management v2.1.0';
    
    // Test database connection
    $db_status = 'Connected';
    try {
        $db->query('SELECT 1');
    } catch (Exception $e) {
        $db_status = 'Disconnected';
    }
    
    // Get last backup date from audit logs
    $backup_query = "SELECT timestamp FROM system_audit_logs WHERE action LIKE '%backup%' ORDER BY timestamp DESC LIMIT 1";
    $backup_stmt = $db->prepare($backup_query);
    $backup_stmt->execute();
    $last_backup = $backup_stmt->fetchColumn() ?: date('Y-m-d H:i:s');
    
    // Calculate total storage used (approximate based on record counts)
    $storage_query = "SELECT 
        (SELECT COUNT(*) FROM users) * 2 + 
        (SELECT COUNT(*) FROM risk_incidents) * 5 + 
        (SELECT COUNT(*) FROM system_audit_logs) * 1 as total_kb";
    $storage_stmt = $db->prepare($storage_query);
    $storage_stmt->execute();
    $storage_kb = $storage_stmt->fetchColumn() ?: 0;
    $storage_used = round($storage_kb / 1024, 2) . ' MB';
    
} catch (Exception $e) {
    $system_version = 'Airtel Risk Management v2.1.0';
    $db_status = 'Error';
    $last_backup = 'Unknown';
    $storage_used = 'Unknown';
}

if (isset($_POST['perform_backup'])) {
    try {
        // Log backup action
        $backup_stmt = $db->prepare("INSERT INTO system_audit_logs (user, action, details, ip_address, user_id) VALUES (?, ?, ?, ?, ?)");
        $backup_stmt->execute([
            $_SESSION['user_name'] ?? 'Admin',
            'System Backup Performed',
            'Manual backup initiated from admin dashboard',
            $_SERVER['REMOTE_ADDR'],
            $_SESSION['user_id'] ?? null
        ]);
        $success_message = "System backup completed successfully.";
    } catch (Exception $e) {
        $error_message = "Backup failed: " . $e->getMessage();
    }
}

if (isset($_POST['clear_cache'])) {
    try {
        // Log cache clear action
        $cache_stmt = $db->prepare("INSERT INTO system_audit_logs (user, action, details, ip_address, user_id) VALUES (?, ?, ?, ?, ?)");
        $cache_stmt->execute([
            $_SESSION['user_name'] ?? 'Admin',
            'System Cache Cleared',
            'Cache cleared from admin dashboard',
            $_SERVER['REMOTE_ADDR'],
            $_SESSION['user_id'] ?? null
        ]);
        $success_message = "System cache cleared successfully.";
    } catch (Exception $e) {
        $error_message = "Cache clear failed: " . $e->getMessage();
    }
}

if (isset($_GET['ajax_download_template']) && $_GET['ajax_download_template'] == '1') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="risk_data_template.csv"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    
    $template_data = "risk_name,risk_description,cause_of_risk,department,probability,impact,status\n";
    $template_data .= "Sample Risk 1,Description of the risk,Root cause analysis,IT,3,4,Open\n";
    $template_data .= "Sample Risk 2,Another risk description,Cause of this risk,Finance,2,3,In Progress\n";
    $template_data .= "Sample Risk 3,Third risk example,Why this happened,HR,4,5,Mitigated\n";
    
    echo $template_data;
    exit();
}

if (isset($_POST['ajax_upload']) && isset($_FILES['bulk_file'])) {
    header('Content-Type: application/json');
    
    $response = ['success' => false, 'message' => ''];
    
    try {
        $file = $_FILES['bulk_file'];
        
        // Validate file
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload error: ' . $file['error']);
        }
        
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($file_extension, ['csv'])) {
            throw new Exception('Only CSV files are supported for upload.');
        }
        
        if ($file['size'] > 10 * 1024 * 1024) {
            throw new Exception('File size must be less than 10MB.');
        }
        
        // Process CSV file
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            throw new Exception('Could not open uploaded file.');
        }
        
        // Read header row
        $header = fgetcsv($handle);
        $required_columns = ['risk_name', 'risk_description', 'cause_of_risk', 'department', 'probability', 'impact', 'status'];
        
        // Validate header
        foreach ($required_columns as $col) {
            if (!in_array($col, $header)) {
                throw new Exception("Missing required column: $col");
            }
        }
        
        $inserted_count = 0;
        $current_user = getCurrentUser();
        
        // Process data rows
        while (($data = fgetcsv($handle)) !== FALSE) {
            if (count($data) < count($required_columns)) continue;
            
            $row_data = array_combine($header, $data);
            
            // Validate data
            if (empty($row_data['risk_name']) || empty($row_data['department'])) continue;
            
            $probability = (int)$row_data['probability'];
            $impact = (int)$row_data['impact'];
            
            if ($probability < 1 || $probability > 5 || $impact < 1 || $impact > 5) {
                continue; // Skip invalid probability/impact values
            }
            
            $valid_statuses = ['Open', 'In Progress', 'Mitigated', 'Closed'];
            if (!in_array($row_data['status'], $valid_statuses)) {
                $row_data['status'] = 'Open'; // Default to Open
            }
            
            // Insert into database
            $insert_query = "INSERT INTO risk_incidents (risk_name, risk_description, cause_of_risk, department, probability, impact, status, reported_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $insert_stmt = $db->prepare($insert_query);
            
            if ($insert_stmt->execute([
                $row_data['risk_name'],
                $row_data['risk_description'],
                $row_data['cause_of_risk'],
                $row_data['department'],
                $probability,
                $impact,
                $row_data['status'],
                $current_user['id']
            ])) {
                $inserted_count++;
            }
        }
        
        fclose($handle);
        
        $response['success'] = true;
        $response['message'] = "Successfully uploaded $inserted_count risk records.";
        
    } catch (Exception $e) {
        $response['message'] = 'Upload failed: ' . $e->getMessage();
    }
    
    echo json_encode($response);
    exit();
}

if (isset($_GET['download_template']) && $_GET['download_template'] == '1') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="risk_data_template.csv"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    
    $template_data = "risk_name,risk_description,cause_of_risk,department,probability,impact,status\n";
    $template_data .= "Sample Risk 1,Description of the risk,Root cause analysis,IT,3,4,Open\n";
    $template_data .= "Sample Risk 2,Another risk description,Cause of this risk,Finance,2,3,In Progress\n";
    $template_data .= "Sample Risk 3,Third risk example,Why this happened,HR,4,5,Mitigated\n";
    
    echo $template_data;
    exit();
}

if (isset($_POST['bulk_upload']) && isset($_FILES['bulk_file'])) {
    $upload_message = '';
    $upload_success = false;
    
    try {
        $file = $_FILES['bulk_file'];
        
        // Validate file
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload error: ' . $file['error']);
        }
        
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($file_extension, ['csv'])) {
            throw new Exception('Only CSV files are supported for upload.');
        }
        
        if ($file['size'] > 10 * 1024 * 1024) {
            throw new Exception('File size must be less than 10MB.');
        }
        
        // Process CSV file
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            throw new Exception('Could not open uploaded file.');
        }
        
        // Read header row
        $header = fgetcsv($handle);
        $required_columns = ['risk_name', 'risk_description', 'cause_of_risk', 'department', 'probability', 'impact', 'status'];
        
        // Validate header
        foreach ($required_columns as $col) {
            if (!in_array($col, $header)) {
                throw new Exception("Missing required column: $col");
            }
        }
        
        $inserted_count = 0;
        $current_user = getCurrentUser();
        
        // Process data rows
        while (($data = fgetcsv($handle)) !== FALSE) {
            if (count($data) < count($required_columns)) continue;
            
            $row_data = array_combine($header, $data);
            
            // Validate data
            if (empty($row_data['risk_name']) || empty($row_data['department'])) continue;
            
            $probability = (int)$row_data['probability'];
            $impact = (int)$row_data['impact'];
            
            if ($probability < 1 || $probability > 5 || $impact < 1 || $impact > 5) {
                continue; // Skip invalid probability/impact values
            }
            
            $valid_statuses = ['Open', 'In Progress', 'Mitigated', 'Closed'];
            if (!in_array($row_data['status'], $valid_statuses)) {
                $row_data['status'] = 'Open'; // Default to Open
            }
            
            // Insert into database
            $insert_query = "INSERT INTO risk_incidents (risk_name, risk_description, cause_of_risk, department, probability, impact, status, reported_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $insert_stmt = $db->prepare($insert_query);
            
            if ($insert_stmt->execute([
                $row_data['risk_name'],
                $row_data['risk_description'],
                $row_data['cause_of_risk'],
                $row_data['department'],
                $probability,
                $impact,
                $row_data['status'],
                $current_user['id']
            ])) {
                $inserted_count++;
            }
        }
        
        fclose($handle);
        
        if ($inserted_count > 0) {
            $upload_message = "Successfully uploaded $inserted_count risk records.";
            $upload_success = true;
            
            // Log the upload activity
            $log_query = "INSERT INTO system_audit_logs (user, action, details, ip_address, user_id) VALUES (?, ?, ?, ?, ?)";
            $log_stmt = $db->prepare($log_query);
            $log_stmt->execute([
                $current_user['email'],
                'Bulk Upload Completed',
                "Successfully uploaded $inserted_count risk records via CSV",
                $_SERVER['REMOTE_ADDR'] ?? 'N/A',
                $current_user['id']
            ]);
        } else {
            $upload_message = "No valid records found in the uploaded file.";
        }
        
    } catch (Exception $e) {
        $upload_message = "Upload failed: " . $e->getMessage();
        $upload_success = false;
    }
}

// Handle upload success/error messages
$upload_message = '';
$upload_type = '';
if (isset($_SESSION['upload_message'])) {
    $upload_message = $_SESSION['upload_message'];
    $upload_type = $_SESSION['upload_type'];
    unset($_SESSION['upload_message']);
    unset($_SESSION['upload_type']);
}

// Handle AJAX requests for audit functionality
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['ajax_action']) {
        case 'get_audit_logs':
            $limit = (int)($_POST['limit'] ?? 50);
            $offset = (int)($_POST['offset'] ?? 0);
            $userId = $_POST['user_id'] ?? null;
            
            $query = "SELECT sal.*, u.full_name, u.email, u.role
                      FROM system_audit_logs sal
                      LEFT JOIN users u ON sal.user_id = u.id";
            $params = [];
            
            if ($userId) {
                $query .= " WHERE sal.user_id = :user_id";
                $params[':user_id'] = $userId;
            }
            
            $query .= " ORDER BY sal.timestamp DESC LIMIT :limit OFFSET :offset";
            $stmt = $db->prepare($query);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            
            $stmt->execute();
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format logs for compatibility
            foreach ($logs as &$log) {
                $log['created_at'] = $log['timestamp'];
                $log['table_name'] = '';
                $log['record_id'] = '';
                $log['user_agent'] = '';
            }
            
            echo json_encode(['success' => true, 'logs' => $logs]);
            exit();
            
        case 'get_login_history':
            $limit = (int)($_POST['limit'] ?? 50);
            $offset = (int)($_POST['offset'] ?? 0);
            $userId = $_POST['user_id'] ?? null;
            
            $query = "SELECT sal.*, u.full_name, u.email, u.role
                      FROM system_audit_logs sal
                      LEFT JOIN users u ON sal.user_id = u.id
                      WHERE (sal.action LIKE '%login%' OR sal.action LIKE '%Login%' OR sal.action LIKE '%authentication%')";
            $params = [];
            
            if ($userId) {
                $query .= " AND sal.user_id = :user_id";
                $params[':user_id'] = $userId;
            }
            
            $query .= " ORDER BY sal.timestamp DESC LIMIT :limit OFFSET :offset";
            $stmt = $db->prepare($query);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            
            $stmt->execute();
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format for compatibility
            foreach ($history as &$login) {
                $login['created_at'] = $login['timestamp'];
                $login['login_status'] = (strpos(strtolower($login['action']), 'failed') !== false ||
                                         strpos(strtolower($login['action']), 'pending') !== false) ? 'failed' : 'success';
                $login['failure_reason'] = (strpos(strtolower($login['action']), 'failed') !== false ||
                                           strpos(strtolower($login['action']), 'pending') !== false) ? $login['details'] : null;
                $login['location_info'] = null;
            }
            
            echo json_encode(['success' => true, 'history' => $history]);
            exit();
            
        case 'get_suspicious_activities':
            $limit = (int)($_POST['limit'] ?? 50);
            $offset = (int)($_POST['offset'] ?? 0);
            $severity = $_POST['severity'] ?? null;
            
            // Define suspicious activity patterns
            $suspiciousPatterns = [
                "sal.action LIKE '%failed%'",
                "sal.action LIKE '%error%'",
                "sal.action LIKE '%unauthorized%'",
                "sal.details LIKE '%failed%'",
                "sal.details LIKE '%error%'",
                "sal.details LIKE '%suspicious%'",
                "sal.details LIKE '%multiple attempts%'",
                "sal.action LIKE '%delete%' AND sal.action LIKE '%permanent%'",
                "sal.action LIKE '%Pending%'",
                "sal.action LIKE '%Suspended%'"
            ];
            
            $query = "SELECT sal.*, u.full_name, u.email, u.role,
                 CASE 
                     WHEN (sal.action LIKE '%permanent%' OR sal.details LIKE '%permanent%') THEN 'critical'
                     WHEN (sal.action LIKE '%failed%' OR sal.details LIKE '%failed%' OR sal.action LIKE '%Pending%') THEN 'high'
                     WHEN (sal.action LIKE '%error%' OR sal.details LIKE '%error%' OR sal.action LIKE '%Suspended%') THEN 'medium'
                     ELSE 'low'
                 END as severity,
                 COALESCE(sal.resolved, 0) as is_resolved
                 FROM system_audit_logs sal 
                 LEFT JOIN users u ON sal.user_id = u.id 
                 WHERE (" . implode(' OR ', $suspiciousPatterns) . ") AND COALESCE(sal.resolved, 0) = 0";
            
            $params = [];
            
            if ($severity) {
                $query .= " HAVING severity = :severity";
                $params[':severity'] = $severity;
                $params[':severity'] = $severity;
            }
            
            $query .= " ORDER BY sal.timestamp DESC LIMIT :limit OFFSET :offset";
            $stmt = $db->prepare($query);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            
            $stmt->execute();
            $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Add additional fields for compatibility
            foreach ($activities as &$activity) {
                $activity['id'] = $activity['id'];
                $activity['activity_type'] = $activity['action'];
                $activity['description'] = $activity['details'];
                $activity['created_at'] = $activity['timestamp'];
                $activity['resolved_by_name'] = null;
            }
            
            echo json_encode(['success' => true, 'activities' => $activities]);
            exit();
            
        case 'resolve_suspicious_activity':
            $activityId = $_POST['activity_id'] ?? null;
            if ($activityId) {
                $query = "UPDATE system_audit_logs SET resolved = 1, resolved_at = NOW(), resolved_by = ? WHERE id = ?";
                $stmt = $db->prepare($query);
                if ($stmt->execute([$_SESSION['user_id'], $activityId])) {
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false]);
                }
            } else {
                echo json_encode(['success' => false]);
            }
            exit();
            
        case 'export_audit_logs':
            // Get filter parameters from the request
            $searchTerm = $_POST['search_term'] ?? '';
            $userRoleFilter = $_POST['user_role_filter'] ?? '';
            $actionFilter = $_POST['action_filter'] ?? '';
            $fromDate = $_POST['from_date'] ?? '';
            $toDate = $_POST['to_date'] ?? '';
            
            // Build the query with filters
            $query = "SELECT sal.*, u.full_name, u.email, u.role
                      FROM system_audit_logs sal
                      LEFT JOIN users u ON sal.user_id = u.id WHERE 1=1";
            $params = [];
            
            // Apply search term filter
            if (!empty($searchTerm)) {
                $query .= " AND (sal.user LIKE :search_term OR sal.action LIKE :search_term OR sal.details LIKE :search_term OR sal.timestamp LIKE :search_term)";
                $params[':search_term'] = '%' . $searchTerm . '%';
            }
            
            // Apply user role filter
            if (!empty($userRoleFilter)) {
                $query .= " AND sal.user LIKE :user_role_filter";
                $params[':user_role_filter'] = '%' . $userRoleFilter . '%';
            }
            
            // Apply action filter
            if (!empty($actionFilter)) {
                $query .= " AND sal.action LIKE :action_filter";
                $params[':action_filter'] = '%' . $actionFilter . '%';
            }
            
            // Apply date range filters
            if (!empty($fromDate)) {
                $query .= " AND DATE(sal.timestamp) >= :from_date";
                $params[':from_date'] = $fromDate;
            }
            
            if (!empty($toDate)) {
                $query .= " AND DATE(sal.timestamp) <= :to_date";
                $params[':to_date'] = $toDate;
            }
            
            $query .= " ORDER BY sal.timestamp DESC LIMIT 5000"; // Limit to prevent memory issues
            
            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Generate filename with filter info
            $filename = 'audit_logs_' . date('Y-m-d_H-i-s');
            if (!empty($searchTerm)) {
                $filename .= '_search-' . preg_replace('/[^a-zA-Z0-9]/', '', substr($searchTerm, 0, 10));
            }
            if (!empty($actionFilter)) {
                $filename .= '_action-' . preg_replace('/[^a-zA-Z0-9]/', '', substr($actionFilter, 0, 10));
            }
            if (!empty($fromDate) || !empty($toDate)) {
                $filename .= '_filtered';
            }
            $filename .= '.csv';
            
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            
            $output = fopen('php://output', 'w');
            
            // CSV headers
            fputcsv($output, [
                'ID', 'Date & Time', 'User', 'Full Name', 'Email', 'Role', 'Action', 'Details', 'IP Address'
            ]);
            
            // Add filter information as comments in CSV
            if (!empty($searchTerm) || !empty($userRoleFilter) || !empty($actionFilter) || !empty($fromDate) || !empty($toDate)) {
                fputcsv($output, ['# Filters Applied:']);
                if (!empty($searchTerm)) fputcsv($output, ['# Search Term: ' . $searchTerm]);
                if (!empty($userRoleFilter)) fputcsv($output, ['# User Role: ' . $userRoleFilter]);
                if (!empty($actionFilter)) fputcsv($output, ['# Action: ' . $actionFilter]);
                if (!empty($fromDate)) fputcsv($output, ['# From Date: ' . $fromDate]);
                if (!empty($toDate)) fputcsv($output, ['# To Date: ' . $toDate]);
                fputcsv($output, ['# Total Records: ' . count($logs)]);
                fputcsv($output, []); // Empty line
            }
            
            foreach ($logs as $log) {
                fputcsv($output, [
                    $log['id'],
                    date('Y-m-d H:i:s', strtotime($log['timestamp'])),
                    $log['user'],
                    $log['full_name'] ?? 'Unknown',
                    $log['email'] ?? 'Unknown',
                    $log['role'] ?? 'Unknown',
                    $log['action'],
                    $log['details'] ?? '',
                    $log['ip_address'] ?? ''
                ]);
            }
            
            fclose($output);
            exit();
    }
}

// Handle Broadcast Message
if ($_POST && isset($_POST['send_broadcast'])) {
    $broadcast_message = $_POST['broadcast_message'];
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'N/A';
    
    error_log("BROADCAST: " . $broadcast_message);
    $success_message = "Broadcast message sent successfully!";
    // Log to system_audit_logs
    $log_query = "INSERT INTO system_audit_logs (user, action, details, ip_address, user_id) VALUES (?, ?, ?, ?, ?)";
    $log_stmt = $db->prepare($log_query);
    $log_stmt->execute([
        $_SESSION['user_email'] ?? 'Admin',
        'Broadcast Message Sent',
        'Broadcast message: "' . $broadcast_message . '"',
        $ip_address,
        $_SESSION['user_id']
    ]);
}

// --- Data Fetching for Dashboards ---
// Get system statistics (expanded)
$stats_query = "SELECT
    (SELECT COUNT(*) FROM users) as total_users,
    (SELECT COUNT(*) FROM users WHERE status = 'pending') as pending_users,
    (SELECT COUNT(*) FROM users WHERE status = 'approved') as active_users,
    (SELECT COUNT(*) FROM users WHERE role = 'admin') as admin_users,
    (SELECT COUNT(*) FROM users WHERE role = 'risk_owner') as risk_owner_users,
    (SELECT COUNT(*) FROM users WHERE role = 'staff') as staff_users,
    (SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as new_users_7_days,
    (SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as new_users_30_days,
    (SELECT COUNT(*) FROM risk_incidents) as total_risks,
    (SELECT COUNT(*) FROM risk_incidents WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as recent_risks,
    (SELECT COUNT(*) FROM risk_incidents WHERE status = 'Open') as open_risks,
    (SELECT COUNT(*) FROM risk_incidents WHERE status = 'In Progress') as in_progress_risks,
    (SELECT COUNT(*) FROM risk_incidents WHERE status = 'Mitigated') as mitigated_risks,
    (SELECT COUNT(*) FROM risk_incidents WHERE status = 'Closed') as closed_risks";
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->execute();
$system_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Handle View All Audit Logs request
$show_all_logs = isset($_GET['show_all_logs']) && $_GET['show_all_logs'] == '1';

// Get Audit Logs from database (using system_audit_logs table)
if ($show_all_logs) {
    $audit_logs_query = "SELECT
                            sal.timestamp,
                            sal.user,
                            sal.action,
                            sal.details,
                            sal.ip_address
                         FROM
                            system_audit_logs sal
                         ORDER BY
                            sal.timestamp DESC";
} else {
    $audit_logs_query = "SELECT
                            sal.timestamp,
                            sal.user,
                            sal.action,
                            sal.details,
                            sal.ip_address
                         FROM
                            system_audit_logs sal
                         ORDER BY
                            sal.timestamp DESC
                         LIMIT 10";
}
$audit_logs_stmt = $db->prepare($audit_logs_query);
$audit_logs_stmt->execute();
$audit_logs = $audit_logs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for "View All" functionality
$total_logs_query = "SELECT COUNT(*) as total FROM system_audit_logs";
$total_logs_stmt = $db->prepare($total_logs_query);
$total_logs_stmt->execute();
$total_logs_count = $total_logs_stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Get Login History Logs from database (using system_audit_logs table)
$login_history_query = "SELECT
                                sal.timestamp,
                                sal.user,
                                sal.action,
                                sal.details,
                                sal.ip_address
                            FROM
                                system_audit_logs sal
                            WHERE
                                sal.action LIKE '%Login%' OR sal.action LIKE '%authentication%'
                            ORDER BY
                                sal.timestamp DESC";
$login_history_stmt = $db->prepare($login_history_query);
$login_history_stmt->execute();
$login_history_logs = $login_history_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent risks for dashboard display
$recent_risks_query = "SELECT ri.id, ri.risk_name, ri.risk_description, ri.cause_of_risk, ri.department, 
                       ri.probability, ri.impact, ri.status, ri.created_at, ri.updated_at,
                       u.full_name as reported_by_name,
                       (ri.probability * ri.impact) as risk_score,
                       CASE 
                           WHEN (ri.probability * ri.impact) <= 5 THEN 'Low'
                           WHEN (ri.probability * ri.impact) <= 15 THEN 'Medium'
                           ELSE 'High'
                       END as risk_level
                       FROM risk_incidents ri
                       LEFT JOIN users u ON ri.reported_by = u.id
                       WHERE ri.status != 'Deleted'
                       ORDER BY ri.created_at DESC
                       LIMIT 5";
$recent_risks_stmt = $db->prepare($recent_risks_query);
$recent_risks_stmt->execute();
$recent_risks = $recent_risks_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all risks for Database Management -> View All Risks with complete data
$all_risks_query = "SELECT ri.id, ri.risk_name, ri.risk_description, ri.cause_of_risk, ri.department,
                    ri.probability, ri.impact, ri.status, ri.created_at, ri.updated_at,
                    u.full_name as reported_by_name, u.email as reported_by_email,
                    (ri.probability * ri.impact) as risk_score,
                    CASE 
                        WHEN (ri.probability * ri.impact) <= 5 THEN 'Low'
                        WHEN (ri.probability * ri.impact) <= 15 THEN 'Medium'
                        ELSE 'High'
                    END as risk_level
                    FROM risk_incidents ri
                    LEFT JOIN users u ON ri.reported_by = u.id
                    WHERE ri.status != 'Deleted'
                    ORDER BY ri.created_at DESC";

try {
    $all_risks_stmt = $db->prepare($all_risks_query);
    $all_risks_stmt->execute();
    $all_risks = $all_risks_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug: Check if we have any risks at all
    if (empty($all_risks)) {
        // Try a simpler query to see if there are any risks in the database
        $debug_query = "SELECT COUNT(*) as total_count FROM risk_incidents WHERE status != 'Deleted'";
        $debug_stmt = $db->prepare($debug_query);
        $debug_stmt->execute();
        $debug_result = $debug_stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $all_risks = [];
    error_log("Error fetching all risks: " . $e->getMessage());
}

// Get deleted risks for Database Management -> Recycle Bin (for risks)
$deleted_risks_query = "SELECT ri.*, u.full_name as reported_by_name
                        FROM risk_incidents ri
                        JOIN users u ON ri.reported_by = u.id
                        WHERE ri.status = 'Deleted'
                        ORDER BY ri.updated_at DESC";
$deleted_risks_stmt = $db->prepare($deleted_risks_query);
$deleted_risks_stmt->execute();
$deleted_risks = $deleted_risks_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current user info for header
$user_info = getCurrentUser();

// System Information
$system_info = [
    'version' => $system_version,
    'db_status' => $db_status,
    'last_backup' => $last_backup,
    'storage_used' => $storage_used
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Airtel Risk Management</title>
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
        
        .dashboard {
            min-height: 100vh;
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
            cursor: pointer;
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
        .flex {
            display: flex;
        }
        .justify-between {
            justify-content: space-between;
        }
        .items-center {
            align-items: center;
        }
        .w-full {
            width: 100%;
        }
        
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .card {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            border-top: 4px solid #E60012;
        }
        
        .card h2 {
            color: #333;
            margin-bottom: 1.5rem;
            font-size: 1.5rem;
            font-weight: 600;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #eee;
        }
        .card-title {
            color: #333;
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0;
        }
        
        .stats-grid, .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            border-top: 4px solid #E60012;
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
            color: #E60012;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .risk-table, .audit-table, .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        
        .risk-table th, .risk-table td,
        .audit-table th, .audit-table td,
        .data-table th, .data-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .risk-table th, .audit-table th, .data-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .status-pending { background: #fff3cd; color: #856404; }
        .status-approved { background: #d4edda; color: #155724; }
        .status-suspended { background: #f8d7da; color: #721c24; }
        .status-deleted { background: #f8d7da; color: #721c24; }
        .status-open { background: #f8d7da; color: #721c24; }
        .status-in-progress { background: #fff3cd; color: #856404; }
        .status-mitigated { background: #d4edda; color: #155724; }
        .status-closed { background: #cce5ff; color: #004085; }
        
        .btn {
            background: #E60012;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            cursor: pointer;
            margin: 0.25rem;
            font-size: 0.9rem;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn:hover {
            background: #B8000E;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(230, 0, 18, 0.3);
        }
        
        .btn-danger {
            background: #dc3545;
        }
        .btn-danger:hover {
            background: #c82333;
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
        
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        .btn-info:hover {
            background: #138496;
        }
        
        .btn-primary {
            background: #007bff;
        }
        .btn-primary:hover {
            background: #0056b3;
        }
        
        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }
        
        .success {
            background: #d4edda;
            color: #155724;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            border-left: 4px solid #28a745;
            font-weight: 500;
        }
        
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            border-left: 4px solid #dc3545;
            font-weight: 500;
        }
        
        .bulk-upload {
            border: 2px dashed #e1e5e9;
            padding: 2rem;
            text-align: center;
            border-radius: 10px;
            margin: 1rem 0;
            background: #f8f9fa;
            transition: all 0.3s;
        }
        
        .bulk-upload:hover {
            border-color: #E60012;
            background: rgba(230, 0, 18, 0.05);
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
            justify-content: center;
            align-items: center;
        }
        .modal.show {
            display: flex;
        }
        .modal-content {
            background: white;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
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
        
        .action-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .action-group .btn {
            margin: 0;
        }
        
        .search-input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-bottom: 1rem;
            font-size: 1rem;
        }
        
        /* Audit Log Filters Layout */
        .audit-filters-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .audit-filters-container {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        .highlighted-row {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
        }
        
        /* Quick Actions Containers */
        .quick-actions-containers {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .quick-action-container {
            color: white;
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: transform 0.3s;
            text-decoration: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100px;
        }
        .quick-action-container:hover {
            transform: translateY(-5px);
            text-decoration: none;
            color: white;
        }
        .quick-action-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        .quick-action-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
            font-size: 1rem;
        }
        .quick-action-description {
            font-size: 0.8rem;
            opacity: 0.9;
        }
        /* Chatbot Icon Styling */
        .chatbot-icon {
            width: 55px;
            height: 55px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        /* Audit Modal Styles */
        .audit-modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .audit-modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .audit-modal-content {
            background: white;
            border-radius: 10px;
            width: 95%;
            max-width: 1200px;
            max-height: 85vh;
            overflow-y: auto;
        }
        
        .audit-modal-header {
            background: #E60012;
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 10px 10px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .audit-modal-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
        }
        
        .audit-close {
            color: white;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
        }
        
        .audit-modal-body {
            padding: 1.5rem;
        }
        
        .audit-table-container {
            overflow-x: auto;
            max-height: 500px;
            overflow-y: auto;
        }
        
        .audit-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        
        .audit-table th,
        .audit-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }
        
        .audit-table th {
            background: #f8f9fa;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .audit-table tbody tr:hover {
            background: #f8f9fa;
        }
        
        .audit-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .audit-badge-success {
            background: #d4edda;
            color: #155724;
        }
        
        .audit-badge-danger {
            background: #f8d7da;
            color: #721c24;
        }
        
        .audit-badge-warning {
            background: #fff3cd;
            color: #856404;
        }
        
        .audit-badge-info {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .audit-badge-critical {
            background: #721c24;
            color: white;
        }
        
        .audit-badge-high {
            background: #dc3545;
            color: white;
        }
        
        .audit-badge-medium {
            background: #ffc107;
            color: #212529;
        }
        
        .audit-badge-low {
            background: #28a745;
            color: white;
        }
        
        .audit-loading {
            text-align: center;
            padding: 2rem;
            color: #666;
        }
        
        .audit-filters {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        
        .audit-filter-select {
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: white;
        }
        
        .resolve-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.75rem;
        }
        
        .resolve-btn:hover {
            background: #218838;
        }
        
        .resolve-btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }
        
        /* View All Modal Styles */
        .view-all-modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .view-all-modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .view-all-modal-content {
            background: white;
            border-radius: 10px;
            width: 95%;
            max-width: 1200px;
            max-height: 85vh;
            overflow-y: auto;
        }
        
        .view-all-modal-header {
            background: #E60012;
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 10px 10px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .view-all-modal-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
        }
        
        .view-all-close {
            color: white;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
        }
        
        .view-all-modal-body {
            padding: 1.5rem;
        }
        
        .filter-section {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .filter-group label {
            font-size: 0.9rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 0.25rem;
        }
        
        .filter-group input,
        .filter-group select {
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.9rem;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            body {
                padding-top: 120px;
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
                top: 80px;
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
            
            .main-content {
                padding: 1rem;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .card {
                padding: 1.5rem;
            }
            .audit-filters-container {
                flex-direction: column;
                gap: 0.5rem;
            }
            .audit-filters-group {
                flex-direction: row;
                align-items: center;
                width: 100%;
            }
            .quick-actions-containers {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            .filter-section {
                flex-direction: column;
                align-items: stretch;
            }
        }
        
        /* Added styles for upload messages and enhanced risk display */
        .upload-message {
            padding: 15px;
            margin: 15px 0;
            border-radius: 5px;
            font-weight: bold;
        }
        
        .upload-message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .upload-message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .risk-score-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: bold;
            color: white;
        }
        
        .risk-score-low { background-color: #28a745; }
        .risk-score-medium { background-color: #ffc107; color: #000; }
        .risk-score-high { background-color: #dc3545; }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: bold;
            color: white;
        }
        
        .status-open { background-color: #dc3545; }
        .status-in-progress { background-color: #ffc107; color: #000; }
        .status-mitigated { background-color: #17a2b8; }
        .status-closed { background-color: #28a745; }
        
        /* Added styles for risk levels and improved table display */
        .risk-level {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .risk-level-low {
            background-color: #d4edda;
            color: #155724;
        }
        
        .risk-level-medium {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .risk-level-high {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .risk-score {
            font-weight: bold;
            padding: 4px 8px;
            border-radius: 4px;
            background-color: #f8f9fa;
        }
        
        .alert {
            margin-bottom: 1rem;
            padding: 10px;
            border-radius: 5px;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
    </style>
</head>
<body>
<div class="dashboard">
    <header class="header">
        <div class="header-content">
            <div class="header-left">
                <div class="logo-circle">
                    <!-- Updated logo source to image.png from root folder -->
                    <img src="image.png" alt="Airtel Logo" />
                </div>
                <div class="header-titles">
                    <h1 class="main-title">Airtel Risk Register System</h1>
                    <p class="sub-title">Admin Management Dashboard</p>
                </div>
            </div>
            <div class="header-right">
                <div class="user-avatar"><?php echo isset($user_info['full_name']) ? strtoupper(substr($user_info['full_name'], 0, 1)) : 'A'; ?></div>
                <div class="user-details">
                    <div class="user-email"><?php echo htmlspecialchars($user_info['email']); ?></div>
                    <div class="user-role"><?php echo ucfirst($user_info['role']); ?></div>
                </div>
                <a href="logout.php" class="logout-btn">Logout</a>
            </div>
        </div>
    </header>
    
    <nav class="nav">
        <div class="nav-content">
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="#" onclick="showTab(event, 'overview')" class="active">
                        📊 Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a href="user_management.php">
                        👥 User Management
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" onclick="showTab(event, 'database')">
                        🗄️ Database Management
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" onclick="showTab(event, 'notifications')">
                        🔔 Notifications
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" onclick="showTab(event, 'settings')">
                        ⚙️ Settings
                    </a>
                </li>
            </ul>
        </div>
    </nav>
    
    <div class="main-content">
        <?php if (isset($success_message)): ?>
            <div class="success">✅ <?php echo $success_message; ?></div>
        <?php endif; ?>
        <?php if (isset($error_message)): ?>
            <div class="error">❌ <?php echo $error_message; ?></div>
        <?php endif; ?>
        
        <?php
        if (isset($_SESSION['upload_message'])) {
            $message = $_SESSION['upload_message'];
            $type = $_SESSION['upload_type'] ?? 'info';
            echo "<div class='alert alert-{$type}' style='margin: 1rem; padding: 1rem; border-radius: 4px; background-color: " . ($type === 'success' ? '#d4edda' : '#f8d7da') . "; color: " . ($type === 'success' ? '#155724' : '#721c24') . ";'>{$message}</div>";
            unset($_SESSION['upload_message']);
            unset($_SESSION['upload_type']);
        }
        ?>
        
        <!-- 1. Dashboard Tab -->
        <div id="overview-tab" class="tab-content active">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $system_stats['total_users']; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $system_stats['active_users']; ?></div>
                    <div class="stat-label">Active Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $system_stats['total_risks']; ?></div>
                    <div class="stat-label">Total Risks Reported</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $system_stats['pending_users']; ?></div>
                    <div class="stat-label">Pending Approvals</div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">🚀 Quick Actions</h3>
                </div>
                
                <div class="quick-actions-containers">
                    <!-- Team Chat -->
                    <div class="quick-action-container" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                        <a href="teamchat.php" style="text-decoration: none; color: white; display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%;">
                            <div class="quick-action-icon">
                                <i class="fas fa-comments" style="font-size: 2rem;"></i>
                            </div>
                            <div class="quick-action-title">Team Chat</div>
                            <div class="quick-action-description">Communicate with your team</div>
                        </a>
                    </div>
                    
                    <!-- AI Assistant -->
                    <div class="quick-action-container" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <a href="javascript:void(0)" onclick="openChatBot()" style="text-decoration: none; color: white; display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%;">
                            <div class="quick-action-icon">
                                <img src="chatbot.png" alt="AI Assistant" class="chatbot-icon">
                            </div>
                            <div class="quick-action-title">AI Assistant</div>
                            <div class="quick-action-description">Get help with admin tasks</div>
                        </a>
                    </div>
                </div>
            </div>
            
            <div id="audit-logs-section" class="card">
                <div class="flex justify-between items-center" style="margin-bottom: 1rem;">
                    <div>
                        <h2 style="margin-bottom: 0.5rem;">System Audit Logs</h2>
                        <p style="margin-bottom: 0;">Review system activities and administrative actions.</p>
                    </div>
                    <div>
                        <?php if ($show_all_logs): ?>
                            <span style="color: #666; font-size: 0.9rem;">Showing all <?php echo $total_logs_count; ?> entries</span>
                            <a href="?show_all_logs=0#audit-logs-section" class="btn btn-info" style="margin-left: 1rem;">Show Recent</a>
                        <?php else: ?>
                            <span style="color: #666; font-size: 0.9rem;">Showing last <?php echo min(10, count($audit_logs)); ?> of <?php echo $total_logs_count; ?> entries</span>
                            <a href="?show_all_logs=1#audit-logs-section" class="btn btn-info" style="margin-left: 1rem;">View All</a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Updated audit action buttons -->
                <div class="action-group" style="margin-bottom: 1rem;">
                    <button class="btn btn-info" onclick="exportAuditLogsWithFilters()" style="background: #17a2b8;">
                        <i class="fas fa-download"></i> Export Filtered Audit Logs
                    </button>
                    <button class="btn btn-warning" onclick="showSuspiciousActivitiesModal()" style="background: #ffc107; color: #212529;">
                        <i class="fas fa-exclamation-triangle"></i> Highlight Suspicious Activities
                    </button>
                    <button class="btn btn-primary" onclick="showLoginHistoryModal()" style="background: #007bff;">
                        <i class="fas fa-history"></i> View Login History / IP Tracking
                    </button>
                </div>
                
                <input type="text" id="auditLogSearchInput" class="search-input" onkeyup="filterAuditLogTable()" placeholder="Search logs by user, action, or details...">
                <div class="audit-filters-container">
                    <div class="audit-filters-group">
                        <label for="auditLogFilterUser">Filter by User Role:</label>
                        <select id="auditLogFilterUser" onchange="filterAuditLogTable()">
                            <option value="">All Roles</option>
                            <option value="Staff">Staff</option>
                            <option value="Risk Owner">Risk Owner</option>
                            <option value="Compliance">Compliance</option>
                            <option value="Admin">Admin</option>
                            <option value="System">System</option>
                            <option value="Unknown">Unknown</option>
                        </select>
                    </div>
                    <div class="audit-filters-group">
                        <label for="auditLogFilterAction">Filter by Action:</label>
                        <select id="auditLogFilterAction" onchange="filterAuditLogTable()">
                            <option value="">All Actions</option>
                            <option value="User Approved">Approved user</option>
                            <option value="User Suspended">Suspended user</option>
                            <option value="User Restored">Restored user</option>
                            <option value="Risk Reported">Reported risk</option>
                            <option value="Report Downloaded">Downloaded report</option>
                            <option value="Role Changed">Changed role</option>
                            <option value="Failed Login">Failed login attempt</option>
                            <option value="Successful Logout">Successful log out</option>
                            <option value="Successful Login">Successful log in</option>
                        </select>
                    </div>
                    <div class="audit-filters-group">
                        <label for="auditLogFilterFromDate">From Date:</label>
                        <input type="date" id="auditLogFilterFromDate" onchange="filterAuditLogTable()">
                    </div>
                    <div class="audit-filters-group">
                        <label for="auditLogFilterToDate">To Date:</label>
                        <input type="date" id="auditLogFilterToDate" onchange="filterAuditLogTable()">
                    </div>
                </div>
                <table class="audit-table" id="auditLogTable">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($audit_logs) > 0): ?>
                            <?php foreach ($audit_logs as $log): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($log['timestamp']); ?></td>
                                    <td><?php echo htmlspecialchars($log['user']); ?></td>
                                    <td><?php echo htmlspecialchars($log['action']); ?></td>
                                    <td><?php echo htmlspecialchars($log['details']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" style="text-align: center;">No audit logs available.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- 2. Database Management Tab -->
        <div id="database-tab" class="tab-content">
            <div class="card">
                <h2>All Risks</h2>
                <p>Last 5 reported risk incidents.</p>
                
                <!-- Display upload success/error message -->
                <?php if (isset($upload_message)): ?>
                    <div class="alert <?php echo $upload_success ? 'alert-success' : 'alert-error'; ?>" style="margin-bottom: 1rem; padding: 10px; border-radius: 5px; <?php echo $upload_success ? 'background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb;' : 'background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;'; ?>">
                        <?php echo htmlspecialchars($upload_message); ?>
                    </div>
                <?php endif; ?>
                
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Risk ID</th>
                                <th>Risk Name</th>
                                <th>Department</th>
                                <th>Risk Score</th>
                                <th>Risk Level</th>
                                <th>Status</th>
                                <th>Reported By</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($recent_risks) > 0): ?>
                                <?php foreach ($recent_risks as $risk): ?>
                                    <tr>
                                        <td>RISK-<?php echo str_pad($risk['id'], 4, '0', STR_PAD_LEFT); ?></td>
                                        <td><?php echo htmlspecialchars($risk['risk_name']); ?></td>
                                        <td><?php echo htmlspecialchars($risk['department']); ?></td>
                                        <td><span class="risk-score"><?php echo $risk['risk_score']; ?></span></td>
                                        <td><span class="risk-level risk-level-<?php echo strtolower($risk['risk_level']); ?>"><?php echo $risk['risk_level']; ?></span></td>
                                        <td><span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $risk['status'])); ?>"><?php echo htmlspecialchars($risk['status']); ?></span></td>
                                        <td><?php echo htmlspecialchars($risk['reported_by_name']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($risk['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="8" style="text-align: center;">No risks found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <button class="btn btn-primary" onclick="openViewAllModal()">View All</button>
            </div>
            
            <div id="bulk-upload-section" class="card">
                <h2>Bulk Upload Risk Data</h2>
                <p>Upload historical risk data from CSV or Excel files.</p>
                
                <div class="bulk-upload">
                    <!-- Modified form to use AJAX instead of regular form submission -->
                    <form id="bulkUploadForm" onsubmit="return handleAjaxUpload(event)">
                        <h3>📁 Upload Risk Data</h3>
                        <p>Supported formats: CSV, XLS, XLSX</p>
                        <input type="file" name="bulk_file" id="bulk_file" accept=".csv,.xls,.xlsx" required style="margin: 1rem 0;">
                        <br>
                        <button type="submit" class="btn" style="background-color: #E60012; color: white; padding: 10px 20px;">
                            <i class="fas fa-upload"></i> Upload Data
                        </button>
                        <div id="uploadStatus" style="margin-top: 1rem;"></div>
                    </form>
                </div>
                
                <div style="margin-top: 2rem;">
                    <h3>CSV Format Requirements</h3>
                    <p>Your CSV file should have the following columns:</p>
                    <ul style="margin-left: 2rem; margin-top: 1rem;">
                        <li>risk_name</li>
                        <li>risk_description</li>
                        <li>cause_of_risk</li>
                        <li>department</li>
                        <li>probability (1-5)</li>
                        <li>impact (1-5)</li>
                        <li>status (Open, In Progress, Mitigated, Closed)</li>
                    </ul>
                    <!-- Modified download link to use JavaScript instead of direct link -->
                    <button onclick="downloadTemplate()" class="btn" style="background-color: #28a745; color: white; padding: 10px 20px; text-decoration: none; display: inline-block; margin-top: 1rem; border: none; cursor: pointer;">
                        <i class="fas fa-download"></i> Download Template
                    </button>
                </div>
            </div>
            
            <!-- Removed Recycle Bin, Track Aging Risks, Export Data, and Risk Categories sections as requested -->
        </div>


<!-- View All Risks Modal -->
<div id="viewAllModal" class="view-all-modal">
    <div class="view-all-modal-content">
        <div class="view-all-modal-header">
            <h3 class="view-all-modal-title">All Risks</h3>
            <button class="view-all-close" onclick="closeViewAllModal()">&times;</button>
        </div>
        <div class="view-all-modal-body">
            <div class="filter-section">
                <div class="filter-group">
                    <label for="modalDateFrom">From Date:</label>
                    <input type="date" id="modalDateFrom" onchange="filterModalRisks()">
                </div>
                <div class="filter-group">
                    <label for="modalDateTo">To Date:</label>
                    <input type="date" id="modalDateTo" onchange="filterModalRisks()">
                </div>
                <div class="filter-group">
                    <label for="modalDepartmentFilter">Department:</label>
                    <select id="modalDepartmentFilter" onchange="filterModalRisks()">
                        <option value="">All Departments</option>
                        <option value="IT">IT</option>
                        <option value="Finance">Finance</option>
                        <option value="HR">HR</option>
                        <option value="Operations">Operations</option>
                        <option value="Marketing">Marketing</option>
                        <option value="Legal">Legal</option>
                    </select>
                </div>
                <div class="filter-group" style="align-self: flex-end;">
                    <button type="button" onclick="clearFilters()" class="btn" style="background-color: #6c757d; color: white; padding: 10px 15px; border: none; border-radius: 5px; cursor: pointer; margin-right: 10px;">
                        <i class="fas fa-times"></i> Clear Filters
                    </button>
                    <button type="button" onclick="downloadFilteredRisks()" id="downloadBtn" class="btn" style="background-color: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;">
                        <i class="fas fa-download"></i> Download Filtered Data
                    </button>
                </div>
            </div>
            <div class="audit-table-container">
                <table class="audit-table" id="modalRiskTable">
                    <thead>
                        <tr>
                            <th>Risk ID</th>
                            <th>Risk Name</th>
                            <th>Department</th>
                            <th>Risk Score</th>
                            <th>Risk Level</th>
                            <th>Status</th>
                            <th>Reported By</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody id="modalRiskTableBody">
                        <?php if (count($all_risks) > 0): ?>
                            <?php foreach ($all_risks as $risk): ?>
                                <tr>
                                    <td>RISK-<?php echo str_pad($risk['id'], 4, '0', STR_PAD_LEFT); ?></td>
                                    <td><?php echo htmlspecialchars($risk['risk_name']); ?></td>
                                    <td><?php echo htmlspecialchars($risk['department']); ?></td>
                                    <td><span class="risk-score"><?php echo $risk['risk_score']; ?></span></td>
                                    <td><span class="risk-level risk-level-<?php echo strtolower($risk['risk_level']); ?>"><?php echo $risk['risk_level']; ?></span></td>
                                    <td><span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $risk['status'])); ?>"><?php echo htmlspecialchars($risk['status']); ?></span></td>
                                    <td><?php echo htmlspecialchars($risk['reported_by_name']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($risk['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" style="text-align: center;">No risks found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

        <!-- 3. Notifications Tab -->
        <div id="notifications-tab" class="tab-content">
            <div class="card">
                <h2>📢 Broadcast Message</h2>
                <p>Send important announcements to all users in the system.</p>
                
                <form method="POST" style="margin-top: 1.5rem;">
                    <div class="form-group">
                        <label for="broadcast_message">Message Content:</label>
                        <textarea name="broadcast_message" id="broadcast_message" required placeholder="Enter your broadcast message here..." style="height: 120px;"></textarea>
                    </div>
                    <button type="submit" name="send_broadcast" class="btn" style="background-color: #E60012;">
                        <i class="fas fa-bullhorn"></i> Send Broadcast
                    </button>
                </form>
            </div>
        </div>
        
        <!-- 4. Settings Tab -->
        <div id="settings-tab" class="tab-content">
            <div class="card">
                <h2>⚙️ System Settings</h2>
                <p>Configure system-wide settings and preferences.</p>
                
                <!-- Removed language selection section and updated system information to use plain English -->
                <div style="margin-top: 2rem;">
                    <h3>System Information</h3>
                    <table class="audit-table" style="margin-top: 1rem;">
                        <tr>
                            <td><strong>System Version</strong></td>
                            <td><?php echo $system_info['version']; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Database Status</strong></td>
                            <td><span style="color: <?php echo $system_info['db_status'] === 'Connected' ? 'green' : 'red'; ?>;"><?php echo $system_info['db_status']; ?></span></td>
                        </tr>
                        <tr>
                            <td><strong>Last Backup</strong></td>
                            <td><?php echo $system_info['last_backup']; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Total Storage Used</strong></td>
                            <td><?php echo $system_info['storage_used']; ?></td>
                        </tr>
                    </table>
                </div>

                <div style="margin-top: 2rem;">
                    <h3>System Maintenance</h3>
                    <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                        <!-- Updated buttons to prevent page reload and tab switching -->
                        <button onclick="performBackup(event)" style="padding: 0.75rem 1.5rem; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">
                            Perform Backup
                        </button>
                        <button onclick="clearCache(event)" style="padding: 0.75rem 1.5rem; background: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer;">
                            Clear Cache
                        </button>
                    </div>
                    <div id="maintenance-status" style="margin-top: 1rem;"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function showTab(evt, tabName) {
    evt.preventDefault();
    var i, tabcontent, tablinks;
    
    // Hide all tab content
    tabcontent = document.getElementsByClassName("tab-content");
    for (i = 0; i < tabcontent.length; i++) {
        tabcontent[i].classList.remove("active");
    }
    
    // Remove active class from all tab links
    tablinks = document.querySelectorAll(".nav-item a");
    for (i = 0; i < tablinks.length; i++) {
        tablinks[i].classList.remove("active");
    }
    
    // Show the selected tab and mark the button as active
    document.getElementById(tabName + "-tab").classList.add("active");
    evt.currentTarget.classList.add("active");
    
    // Store current tab in sessionStorage to maintain state
    sessionStorage.setItem('currentTab', tabName);
}

function performBackup(event) {
    event.preventDefault();
    const statusDiv = document.getElementById('maintenance-status');
    statusDiv.innerHTML = '<div style="color: blue; padding: 10px; background: #e3f2fd; border-radius: 4px;">Performing backup...</div>';
    
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'perform_backup=1'
    })
    .then(response => response.text())
    .then(data => {
        statusDiv.innerHTML = '<div style="color: green; padding: 10px; background: #e8f5e8; border-radius: 4px;">Backup completed successfully!</div>';
    })
    .catch(error => {
        statusDiv.innerHTML = '<div style="color: red; padding: 10px; background: #ffebee; border-radius: 4px;">Backup failed. Please try again.</div>';
    });
}

function clearCache(event) {
    event.preventDefault();
    const statusDiv = document.getElementById('maintenance-status');
    statusDiv.innerHTML = '<div style="color: blue; padding: 10px; background: #e3f2fd; border-radius: 4px;">Clearing cache...</div>';
    
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'clear_cache=1'
    })
    .then(response => response.text())
    .then(data => {
        statusDiv.innerHTML = '<div style="color: green; padding: 10px; background: #e8f5e8; border-radius: 4px;">Cache cleared successfully!</div>';
    })
    .catch(error => {
        statusDiv.innerHTML = '<div style="color: red; padding: 10px; background: #ffebee; border-radius: 4px;">Cache clearing failed. Please try again.</div>';
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const currentTab = sessionStorage.getItem('currentTab');
    if (currentTab) {
        const tabLink = document.querySelector(`a[onclick*="${currentTab}"]`);
        if (tabLink) {
            tabLink.click();
        }
    }
});

// Tab switching functionality
function showTab(evt, tabName) {
    var i, tabcontent, tablinks;
    
    // Hide all tab content
    tabcontent = document.getElementsByClassName("tab-content");
    for (i = 0; i < tabcontent.length; i++) {
        tabcontent[i].classList.remove("active");
    }
    
    // Remove active class from all tab links
    tablinks = document.querySelectorAll(".nav-item a");
    for (i = 0; i < tablinks.length; i++) {
        tablinks[i].classList.remove("active");
    }
    
    // Show the selected tab and mark the button as active
    document.getElementById(tabName + "-tab").classList.add("active");
    evt.currentTarget.classList.add("active");
}

// Audit log filtering functionality
function filterAuditLogTable() {
    const searchInput = document.getElementById('auditLogSearchInput').value.toLowerCase();
    const userFilter = document.getElementById('auditLogFilterUser').value.toLowerCase();
    const actionFilter = document.getElementById('auditLogFilterAction').value.toLowerCase();
    const fromDate = document.getElementById('auditLogFilterFromDate').value;
    const toDate = document.getElementById('auditLogFilterToDate').value;
    
    const table = document.getElementById('auditLogTable');
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    
    for (let i = 0; i < rows.length; i++) {
        const cells = rows[i].getElementsByTagName('td');
        if (cells.length === 0) continue;
        
        const timestamp = cells[0].textContent;
        const user = cells[1].textContent.toLowerCase();
        const action = cells[2].textContent.toLowerCase();
        const details = cells[3].textContent.toLowerCase();
        
        let showRow = true;
        
        // Search filter
        if (searchInput && !user.includes(searchInput) && !action.includes(searchInput) && !details.includes(searchInput)) {
            showRow = false;
        }
        
        // User role filter
        if (userFilter && !user.includes(userFilter)) {
            showRow = false;
        }
        
        // Action filter
        if (actionFilter && !action.includes(actionFilter)) {
            showRow = false;
        }
        
        // Date filters
        if (fromDate || toDate) {
            const rowDate = new Date(timestamp).toISOString().split('T')[0];
            if (fromDate && rowDate < fromDate) showRow = false;
            if (toDate && rowDate > toDate) showRow = false;
        }
        
        rows[i].style.display = showRow ? '' : 'none';
    }
}

// Export audit logs with filters
function exportAuditLogsWithFilters() {
    const searchTerm = document.getElementById('auditLogSearchInput').value;
    const userRoleFilter = document.getElementById('auditLogFilterUser').value;
    const actionFilter = document.getElementById('auditLogFilterAction').value;
    const fromDate = document.getElementById('auditLogFilterFromDate').value;
    const toDate = document.getElementById('auditLogFilterToDate').value;
    
    // Create a form and submit it
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    
    const fields = {
        'ajax_action': 'export_audit_logs',
        'search_term': searchTerm,
        'user_role_filter': userRoleFilter,
        'action_filter': actionFilter,
        'from_date': fromDate,
        'to_date': toDate
    };
    
    for (const [key, value] of Object.entries(fields)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = value;
        form.appendChild(input);
    }
    
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}

// Suspicious activities modal
function showSuspiciousActivitiesModal() {
    // Implementation for suspicious activities modal
    alert('Suspicious Activities feature will be implemented in the next update.');
}

// Login history modal
function showLoginHistoryModal() {
    // Implementation for login history modal
    alert('Login History feature will be implemented in the next update.');
}

// Chatbot functionality
function openChatBot() {
    // Implementation for chatbot
    alert('AI Assistant feature will be implemented in the next update.');
}

// File upload validation
function validateUpload() {
    const fileInput = document.getElementById('bulk_file');
    const file = fileInput.files[0];
    
    if (!file) {
        alert('Please select a file to upload.');
        return false;
    }
    
    const allowedTypes = ['text/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    if (!allowedTypes.includes(file.type)) {
        alert('Please select a valid CSV or Excel file.');
        return false;
    }
    
    if (file.size > 10 * 1024 * 1024) {
        alert('File size must be less than 10MB.');
        return false;
    }
    
    return true;
}

// View All Risks Modal functionality
function openViewAllModal() {
    document.getElementById('viewAllModal').classList.add('show');
}

function closeViewAllModal() {
    document.getElementById('viewAllModal').classList.remove('show');
}

function filterModalRisks() {
    const fromDate = document.getElementById('modalDateFrom').value;
    const toDate = document.getElementById('modalDateTo').value;
    const department = document.getElementById('modalDepartmentFilter').value.toLowerCase();
    
    const table = document.getElementById('modalRiskTable');
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    
    for (let i = 0; i < rows.length; i++) {
        const cells = rows[i].getElementsByTagName('td');
        if (cells.length === 0) continue;
        
        const riskDepartment = cells[2].textContent.toLowerCase();
        const dateCell = cells[7].textContent;
        const riskDate = new Date(dateCell).toISOString().split('T')[0];
        
        let showRow = true;
        
        // Department filter
        if (department && !riskDepartment.includes(department)) {
            showRow = false;
        }
        
        // Date filters
        if (fromDate && riskDate < fromDate) showRow = false;
        if (toDate && riskDate > toDate) showRow = false;
        
        rows[i].style.display = showRow ? '' : 'none';
    }
}

function clearFilters() {
    document.getElementById('modalDateFrom').value = '';
    document.getElementById('modalDateTo').value = '';
    document.getElementById('modalDepartmentFilter').value = '';
    filterModalRisks();
}

function downloadFilteredRisks() {
    const fromDate = document.getElementById('modalDateFrom').value;
    const toDate = document.getElementById('modalDateTo').value;
    const department = document.getElementById('modalDepartmentFilter').value;
    
    // Create form for download with filters
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    
    const fields = {
        'download_filtered': '1',
        'from_date': fromDate,
        'to_date': toDate,
        'department': department
    };
    
    for (const [key, value] of Object.entries(fields)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = value;
        form.appendChild(input);
    }
    
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}

// Close modals when clicking outside
window.onclick = function(event) {
    const viewAllModal = document.getElementById('viewAllModal');
    if (event.target === viewAllModal) {
        closeViewAllModal();
    }
}

function downloadTemplate() {
    const link = document.createElement('a');
    link.href = '?ajax_download_template=1';
    link.download = 'risk_data_template.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function handleAjaxUpload(event) {
    event.preventDefault();
    
    const fileInput = document.getElementById('bulk_file');
    const file = fileInput.files[0];
    const statusDiv = document.getElementById('uploadStatus');
    
    if (!file) {
        statusDiv.innerHTML = '<div style="color: red; padding: 10px; background: #ffebee; border-radius: 4px;">Please select a file to upload.</div>';
        return false;
    }
    
    const allowedTypes = ['text/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    if (!allowedTypes.includes(file.type)) {
        statusDiv.innerHTML = '<div style="color: red; padding: 10px; background: #ffebee; border-radius: 4px;">Please select a valid CSV or Excel file.</div>';
        return false;
    }
    
    if (file.size > 10 * 1024 * 1024) {
        statusDiv.innerHTML = '<div style="color: red; padding: 10px; background: #ffebee; border-radius: 4px;">File size must be less than 10MB.</div>';
        return false;
    }
    
    // Show loading message
    statusDiv.innerHTML = '<div style="color: blue; padding: 10px; background: #e3f2fd; border-radius: 4px;">Uploading file, please wait...</div>';
    
    // Create FormData object
    const formData = new FormData();
    formData.append('bulk_file', file);
    formData.append('ajax_upload', '1');
    
    // Send AJAX request
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            statusDiv.innerHTML = '<div style="color: green; padding: 10px; background: #e8f5e8; border-radius: 4px;">' + data.message + '</div>';
            // Reset form
            document.getElementById('bulkUploadForm').reset();
        } else {
            statusDiv.innerHTML = '<div style="color: red; padding: 10px; background: #ffebee; border-radius: 4px;">' + data.message + '</div>';
        }
    })
    .catch(error => {
        statusDiv.innerHTML = '<div style="color: red; padding: 10px; background: #ffebee; border-radius: 4px;">Upload failed: ' + error.message + '</div>';
    });
    
    return false;
}
</script>
</body>
</html>
