<?php
include_once 'includes/auth.php';
requireAnyRole(['risk_owner', 'admin', 'compliance_team']); // Allow all roles
include_once 'config/database.php';

// Verify session data exists
if (!isset($_SESSION['user_id']) || !isset($_SESSION['email'])) {
    header("Location: login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Get current user info
$user = getCurrentUser();

// Ensure department is available
if (empty($user['department']) && isset($_SESSION['department'])) {
    $user['department'] = $_SESSION['department'];
} elseif (empty($user['department'])) {
    // Get department from database
    $dept_query = "SELECT department FROM users WHERE id = ?";
    $dept_stmt = $db->prepare($dept_query);
    $dept_stmt->execute([$_SESSION['user_id']]);
    $dept_result = $dept_stmt->fetch(PDO::FETCH_ASSOC);
    if ($dept_result && $dept_result['department']) {
        $user['department'] = $dept_result['department'];
        $_SESSION['department'] = $dept_result['department']; // Cache it
    }
}

// Create enhanced chat tables
try {
    // Chat rooms table (enhanced)
    $createRoomsTable = "CREATE TABLE IF NOT EXISTS chat_rooms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        type ENUM('group', 'private') DEFAULT 'group',
        description TEXT,
        created_by INT,
        is_archived TINYINT DEFAULT 0,
        is_permanent TINYINT DEFAULT 0,
        archived_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id)
    )";
    $db->exec($createRoomsTable);

    // Add is_permanent column if it doesn't exist
    $checkPermanentColumn = "SHOW COLUMNS FROM chat_rooms LIKE 'is_permanent'";
    $stmt = $db->prepare($checkPermanentColumn);
    $stmt->execute();
    if (!$stmt->fetch()) {
        $addPermanentColumn = "ALTER TABLE chat_rooms ADD COLUMN is_permanent TINYINT DEFAULT 0";
        $db->exec($addPermanentColumn);
    }

    // Chat messages table (enhanced with file support)
    $createMessagesTable = "CREATE TABLE IF NOT EXISTS chat_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        room_id INT,
        sender_id INT,
        message TEXT,
        message_type ENUM('text', 'file', 'system') DEFAULT 'text',
        file_name VARCHAR(255) NULL,
        file_path VARCHAR(500) NULL,
        file_size INT NULL,
        file_type VARCHAR(100) NULL,
        is_deleted TINYINT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (room_id) REFERENCES chat_rooms(id) ON DELETE CASCADE,
        FOREIGN KEY (sender_id) REFERENCES users(id)
    )";
    $db->exec($createMessagesTable);

    // Chat participants table (enhanced with read status)
    $createParticipantsTable = "CREATE TABLE IF NOT EXISTS chat_participants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        room_id INT,
        user_id INT,
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_message_read INT DEFAULT 0,
        is_archived TINYINT DEFAULT 0,
        FOREIGN KEY (room_id) REFERENCES chat_rooms(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id),
        UNIQUE KEY unique_participant (room_id, user_id)
    )";
    $db->exec($createParticipantsTable);

    // Create uploads directory
    $uploadDir = 'uploads/chat/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Ensure only ONE of each permanent group chat exists based on user role
    $permanentChats = [];

    if ($_SESSION['role'] === 'admin') {
        $permanentChats = [
            'All Admins' => 'General discussion for all administrators',
            'Admins + Risk Owners' => 'Communication channel between admins and risk owners',
            'Admins + Compliance_team' => 'Communication channel between admins and compliance team'
        ];
    } elseif ($_SESSION['role'] === 'risk_owner') {
        $permanentChats = [
            'All Risk Owners' => 'General discussion for all risk owners across departments',
            'Admins + Risk Owners' => 'Communication channel between admins and risk owners',
            'Risk Owners + Compliance_team' => 'Communication channel with compliance team'
        ];
    } elseif ($_SESSION['role'] === 'compliance_team') {
        $permanentChats = [
            'All Compliance' => 'General discussion for all compliance team members',
            'Admins + Compliance' => 'Communication channel between admins and compliance team',
            'Risk Owners + Compliance' => 'Communication channel with risk owners'
        ];
    }

    foreach ($permanentChats as $chatName => $description) {
        // Check if this permanent chat exists
        $checkChat = "SELECT id FROM chat_rooms WHERE name = ? AND type = 'group' LIMIT 1";
        $stmt = $db->prepare($checkChat);
        $stmt->execute([$chatName]);
        $existingChat = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existingChat) {
            // Create the permanent chat
            $insertRoom = "INSERT INTO chat_rooms (name, type, description, created_by, is_permanent) VALUES (?, 'group', ?, ?, 1)";
            $stmt = $db->prepare($insertRoom);
            $stmt->execute([$chatName, $description, $_SESSION['user_id']]);
        } else {
            // Make sure existing chat is marked as permanent
            $updatePermanent = "UPDATE chat_rooms SET is_permanent = 1 WHERE id = ?";
            $stmt = $db->prepare($updatePermanent);
            $stmt->execute([$existingChat['id']]);
        }
    }

    // Remove any duplicate permanent chats (keep only the first one of each)
    foreach (array_keys($permanentChats) as $chatName) {
        $getDuplicates = "SELECT id FROM chat_rooms WHERE name = ? AND type = 'group' ORDER BY id ASC";
        $stmt = $db->prepare($getDuplicates);
        $stmt->execute([$chatName]);
        $allChats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($allChats) > 1) {
            // Keep the first one, delete the rest
            for ($i = 1; $i < count($allChats); $i++) {
                $deleteChat = "DELETE FROM chat_rooms WHERE id = ?";
                $stmt = $db->prepare($deleteChat);
                $stmt->execute([$allChats[$i]['id']]);
            }
        }
    }

} catch (PDOException $e) {
    // Tables might already exist, continue
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'send_message':
            $room_id = (int)$_POST['room_id'];
            $message = trim($_POST['message']);
            $message_type = 'text';
            $file_info = null;
            
            // Process mentions in the message
            $message = processMentions($message, $db);
            
            // Handle file upload
            if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = 'uploads/chat/';
                $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip'];
                $maxSize = 10 * 1024 * 1024; // 10MB
                
                $fileInfo = pathinfo($_FILES['file']['name']);
                $extension = strtolower($fileInfo['extension']);
                
                if (!in_array($extension, $allowedTypes)) {
                    echo json_encode(['success' => false, 'error' => 'File type not allowed']);
                    exit();
                }
                
                if ($_FILES['file']['size'] > $maxSize) {
                    echo json_encode(['success' => false, 'error' => 'File too large (max 10MB)']);
                    exit();
                }
                
                $fileName = uniqid() . '_' . time() . '.' . $extension;
                $filePath = $uploadDir . $fileName;
                
                if (move_uploaded_file($_FILES['file']['tmp_name'], $filePath)) {
                    $message_type = 'file';
                    $file_info = [
                        'name' => $_FILES['file']['name'],
                        'path' => $filePath,
                        'size' => $_FILES['file']['size'],
                        'type' => $_FILES['file']['type']
                    ];
                    
                    if (empty($message)) {
                        $message = 'Shared a file: ' . $_FILES['file']['name'];
                    }
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to upload file']);
                    exit();
                }
            }
            
            if (!empty($message) || $message_type === 'file') {
                $insertMessage = "INSERT INTO chat_messages (room_id, sender_id, message, message_type, file_name, file_path, file_size, file_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $db->prepare($insertMessage);
                $stmt->execute([
                    $room_id, 
                    $_SESSION['user_id'], 
                    $message, 
                    $message_type,
                    $file_info ? $file_info['name'] : null,
                    $file_info ? $file_info['path'] : null,
                    $file_info ? $file_info['size'] : null,
                    $file_info ? $file_info['type'] : null
                ]);
                
                // Update last seen
                $updateLastSeen = "UPDATE chat_participants SET last_seen = NOW() WHERE room_id = ? AND user_id = ?";
                $stmt = $db->prepare($updateLastSeen);
                $stmt->execute([$room_id, $_SESSION['user_id']]);
                
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Message cannot be empty']);
            }
            exit();
            
        case 'get_messages':
            $room_id = (int)$_POST['room_id'];
            $last_message_id = isset($_POST['last_message_id']) ? (int)$_POST['last_message_id'] : 0;
            
            // Check if is_deleted column exists
            $checkColumn = "SHOW COLUMNS FROM chat_messages LIKE 'is_deleted'";
            $stmt = $db->prepare($checkColumn);
            $stmt->execute();
            $hasDeletedColumn = $stmt->fetch() !== false;
            
            if ($hasDeletedColumn) {
                $query = "SELECT cm.*, u.full_name, u.email, u.department, u.role 
                         FROM chat_messages cm 
                         JOIN users u ON cm.sender_id = u.id 
                         WHERE cm.room_id = ? AND cm.id > ? 
                         ORDER BY cm.created_at ASC";
            } else {
                $query = "SELECT cm.*, u.full_name, u.email, u.department, u.role 
                         FROM chat_messages cm 
                         JOIN users u ON cm.sender_id = u.id 
                         WHERE cm.room_id = ? AND cm.id > ? 
                         ORDER BY cm.created_at ASC";
            }
            
            $stmt = $db->prepare($query);
            $stmt->execute([$room_id, $last_message_id]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Update last message read
            if (!empty($messages)) {
                $lastMessageId = end($messages)['id'];
                $updateRead = "UPDATE chat_participants SET last_message_read = ?, last_seen = NOW() WHERE room_id = ? AND user_id = ?";
                $stmt = $db->prepare($updateRead);
                $stmt->execute([$lastMessageId, $room_id, $_SESSION['user_id']]);
            }
            
            echo json_encode(['success' => true, 'messages' => $messages]);
            exit();
            
        case 'create_private_chat':
            $other_user_id = (int)$_POST['other_user_id'];
            
            // Check if private chat already exists
            $checkExisting = "SELECT cr.id FROM chat_rooms cr
                             JOIN chat_participants cp1 ON cr.id = cp1.room_id
                             JOIN chat_participants cp2 ON cr.id = cp2.room_id
                             WHERE cr.type = 'private' AND cr.is_archived = 0
                             AND cp1.user_id = ? AND cp2.user_id = ?
                             AND cp1.is_archived = 0 AND cp2.is_archived = 0";
            $stmt = $db->prepare($checkExisting);
            $stmt->execute([$_SESSION['user_id'], $other_user_id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                echo json_encode(['success' => true, 'room_id' => $existing['id']]);
            } else {
                // Get other user's name
                $getUserName = "SELECT full_name FROM users WHERE id = ?";
                $stmt = $db->prepare($getUserName);
                $stmt->execute([$other_user_id]);
                $otherUser = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Create new private chat
                $chatName = 'Private Chat with ' . $otherUser['full_name'];
                $insertRoom = "INSERT INTO chat_rooms (name, type, description, created_by, is_permanent) VALUES (?, 'private', ?, ?, 0)";
                $stmt = $db->prepare($insertRoom);
                $stmt->execute([$chatName, 'Private conversation', $_SESSION['user_id']]);
                $room_id = $db->lastInsertId();
                
                // Add both participants
                $insertParticipant = "INSERT INTO chat_participants (room_id, user_id) VALUES (?, ?)";
                $stmt = $db->prepare($insertParticipant);
                $stmt->execute([$room_id, $_SESSION['user_id']]);
                $stmt->execute([$room_id, $other_user_id]);
                
                echo json_encode(['success' => true, 'room_id' => $room_id]);
            }
            exit();
            
        case 'get_users':
            $search = isset($_POST['search']) ? trim($_POST['search']) : '';
            $filter = isset($_POST['filter']) ? $_POST['filter'] : 'all';
            
            // Build query based on filter and user role
            $whereClause = "WHERE id != ?";
            $params = [$_SESSION['user_id']];
            
            // Role-based filtering
            if ($_SESSION['role'] === 'admin') {
                // Admins can message anyone
                $whereClause .= " AND role IN ('admin', 'risk_owner', 'compliance_team', 'compliance')";
            } elseif ($_SESSION['role'] === 'risk_owner') {
                // Risk owners can message admins, other risk owners, and compliance
                $whereClause .= " AND role IN ('admin', 'risk_owner')";
            } elseif ($_SESSION['role'] === 'compliance_officer' || $_SESSION['role'] === 'compliance') {
                // Compliance can message admins, risk owners, and other compliance officers
                $whereClause .= " AND role IN ('admin', 'risk_owner', 'compliance_team', 'compliance')";
            }
            
            if ($filter === 'risk_owners') {
                $whereClause .= " AND role = 'risk_owner'";
            } elseif ($filter === 'compliance') {
                $whereClause .= " AND role IN ('compliance_officer', 'compliance')";
            } elseif ($filter === 'admins') {
                $whereClause .= " AND role = 'admin'";
            } elseif ($filter === 'department') {
                $whereClause .= " AND department = ?";
                $params[] = $user['department'];
            }
            
            if (!empty($search)) {
                $whereClause .= " AND (full_name LIKE ? OR email LIKE ? OR department LIKE ?)";
                $searchTerm = "%{$search}%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            $query = "SELECT id, full_name, email, role, department
                      FROM users
                      {$whereClause}
                     ORDER BY full_name";
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'users' => $users]);
            exit();
            
        case 'get_mention_users':
            $room_id = (int)$_POST['room_id'];
            $search = isset($_POST['search']) ? trim($_POST['search']) : '';
            
            // Get room information to determine which users can be mentioned
            $getRoomInfo = "SELECT name, type FROM chat_rooms WHERE id = ?";
            $stmt = $db->prepare($getRoomInfo);
            $stmt->execute([$room_id]);
            $roomInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$roomInfo) {
                echo json_encode(['success' => false, 'error' => 'Room not found']);
                exit();
            }
            
            // Build role filter based on room name
            $roleFilter = "";
            if ($roomInfo['type'] === 'group') {
                switch ($roomInfo['name']) {
                    case 'All Admins':
                        $roleFilter = "AND u.role = 'admin'";
                        break;
                    case 'All Risk Owners':
                        $roleFilter = "AND u.role = 'risk_owner'";
                        break;
                    case 'All Compliance':
                        $roleFilter = "AND u.role IN ('compliance_officer', 'compliance')";
                        break;
                    case 'Admins + Risk Owners':
                        $roleFilter = "AND u.role IN ('admin', 'risk_owner')";
                        break;
                    case 'Admins + Compliance':
                        $roleFilter = "AND u.role IN ('admin', 'compliance_officer', 'compliance')";
                        break;
                    case 'Risk Owners + Compliance':
                        $roleFilter = "AND u.role IN ('risk_owner', 'compliance_officer', 'compliance')";
                        break;
                    default:
                        // For other group chats, show all relevant users
                        $roleFilter = "AND u.role IN ('admin', 'risk_owner', 'compliance_officer', 'compliance')";
                        break;
                }
            } else {
                // For private chats, get participants of the chat
                $roleFilter = "AND u.id IN (SELECT user_id FROM chat_participants WHERE room_id = {$room_id})";
            }
            
            // Get users who can be mentioned in this room
            $query = "SELECT DISTINCT u.id, u.full_name, u.role, u.department
                     FROM users u
                     WHERE u.id != ? {$roleFilter}";
            $params = [$_SESSION['user_id']];
            
            if (!empty($search)) {
                $query .= " AND u.full_name LIKE ?";
                $params[] = "%{$search}%";
            }
            
            $query .= " ORDER BY u.full_name LIMIT 10";
            
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'users' => $users]);
            exit();
            
        case 'clear_chat':
            $room_id = (int)$_POST['room_id'];
            
            // Check if this is a permanent chat
            $checkPermanent = "SELECT is_permanent FROM chat_rooms WHERE id = ?";
            $stmt = $db->prepare($checkPermanent);
            $stmt->execute([$room_id]);
            $room = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($room && $room['is_permanent']) {
                // For permanent chats, only clear messages
                $clearMessages = "DELETE FROM chat_messages WHERE room_id = ?";
                $stmt = $db->prepare($clearMessages);
                $stmt->execute([$room_id]);
                
                // Reset read status for all participants
                $resetRead = "UPDATE chat_participants SET last_message_read = 0 WHERE room_id = ?";
                $stmt = $db->prepare($resetRead);
                $stmt->execute([$room_id]);
                
                echo json_encode(['success' => true, 'action' => 'cleared']);
            } else {
                // For private chats, archive for current user only
                $archiveParticipant = "UPDATE chat_participants SET is_archived = 1 WHERE room_id = ? AND user_id = ?";
                $stmt = $db->prepare($archiveParticipant);
                $stmt->execute([$room_id, $_SESSION['user_id']]);
                
                echo json_encode(['success' => true, 'action' => 'archived']);
            }
            exit();
            
        case 'get_unread_counts':
            // Get unread message counts for all chats
            $query = "SELECT cr.id as room_id, cr.name, cr.type,
                     COALESCE((SELECT COUNT(*) FROM chat_messages cm
                               WHERE cm.room_id = cr.id
                               AND cm.id > COALESCE(cp.last_message_read, 0)
                              AND cm.sender_id != ?), 0) as unread_count
                     FROM chat_rooms cr
                     JOIN chat_participants cp ON cr.id = cp.room_id
                     WHERE cp.user_id = ? AND cp.is_archived = 0 AND cr.is_archived = 0";
            $stmt = $db->prepare($query);
            $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
            $counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'counts' => $counts]);
            exit();

        case 'delete_message':
            $message_id = (int)$_POST['message_id'];
            
            // Check if user owns the message or is admin
            $checkOwnership = "SELECT sender_id FROM chat_messages WHERE id = ?";
            $stmt = $db->prepare($checkOwnership);
            $stmt->execute([$message_id]);
            $message = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($message && ($message['sender_id'] == $_SESSION['user_id'] || $_SESSION['role'] == 'admin')) {
                // Soft delete - mark as deleted but keep in database
                $deleteMessage = "UPDATE chat_messages SET is_deleted = 1, message = '[Message deleted]' WHERE id = ?";
                $stmt = $db->prepare($deleteMessage);
                $stmt->execute([$message_id]);
                
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Not authorized to delete this message']);
            }
            exit();
    }
}

// Function to process mentions in messages
function processMentions($message, $db) {
    // Find @mentions in the message
    preg_match_all('/@([A-Za-z\s]+?)(?=\s|$|[^\w\s])/', $message, $matches);
    
    if (!empty($matches[1])) {
        foreach ($matches[1] as $mention) {
            $mention = trim($mention);
            // Try to find user by name
            $query = "SELECT id, full_name FROM users WHERE full_name LIKE ? LIMIT 1";
            $stmt = $db->prepare($query);
            $stmt->execute(["%{$mention}%"]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // Replace the mention with a formatted version (no HTML tags, just mark it)
                $message = str_replace("@{$mention}", "[@{$user['full_name']}]", $message);
            }
        }
    }
    
    return $message;
}

// Get chat rooms for current user based on role (excluding archived)
$roleBasedWhere = "";
$roleParams = [$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']];

if ($_SESSION['role'] === 'admin') {
    $roleBasedWhere = "AND (cr.name IN ('All Admins', 'Admins + Risk Owners', 'Admins + Compliance') OR cr.type = 'private')";
} elseif ($_SESSION['role'] === 'risk_owner') {
    $roleBasedWhere = "AND (cr.name IN ('All Risk Owners', 'Admins + Risk Owners', 'Risk Owners + Compliance') OR cr.type = 'private')";
} elseif ($_SESSION['role'] === 'compliance_team' || $_SESSION['role'] === 'compliance') {
    $roleBasedWhere = "AND (cr.name IN ('All Compliance', 'Admins + Compliance', 'Risk Owners + Compliance') OR cr.type = 'private')";
}

$getRooms = "SELECT DISTINCT cr.*,
             (SELECT COUNT(*) FROM chat_messages WHERE room_id = cr.id) as message_count,
             (SELECT message FROM chat_messages WHERE room_id = cr.id ORDER BY created_at DESC LIMIT 1) as last_message,
             (SELECT created_at FROM chat_messages WHERE room_id = cr.id ORDER BY created_at DESC LIMIT 1) as last_message_time,
             COALESCE((SELECT COUNT(*) FROM chat_messages cm
                       WHERE cm.room_id = cr.id
                       AND cm.id > COALESCE(cp.last_message_read, 0)
                      AND cm.sender_id != ?), 0) as unread_count
             FROM chat_rooms cr
             LEFT JOIN chat_participants cp ON cr.id = cp.room_id AND cp.user_id = ?
             WHERE (cr.type = 'group' OR cp.user_id = ?)
              AND cr.is_archived = 0
              AND (cp.is_archived = 0 OR cp.is_archived IS NULL)
              {$roleBasedWhere}
             ORDER BY cr.is_permanent DESC, last_message_time DESC, cr.created_at DESC";

$stmt = $db->prepare($getRooms);
$stmt->execute($roleParams);
$chat_rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Auto-join user to appropriate group chats based on role
foreach ($chat_rooms as $room) {
    if ($room['type'] === 'group') {
        $shouldJoin = false;
        
        // Check if user should be in this chat based on role
        if ($_SESSION['role'] === 'admin') {
            $shouldJoin = in_array($room['name'], ['All Admins', 'Admins + Risk Owners', 'Admins + Compliance']);
        } elseif ($_SESSION['role'] === 'risk_owner') {
            $shouldJoin = in_array($room['name'], ['All Risk Owners', 'Admins + Risk Owners', 'Risk Owners + Compliance']);
        } elseif ($_SESSION['role'] === 'compliance_team' || $_SESSION['role'] === 'compliance') {
            $shouldJoin = in_array($room['name'], ['All Compliance', 'Admins + Compliance', 'Risk Owners + Compliance']);
        }
        
        if ($shouldJoin) {
            $checkParticipant = "SELECT id FROM chat_participants WHERE room_id = ? AND user_id = ?";
            $stmt = $db->prepare($checkParticipant);
            $stmt->execute([$room['id'], $_SESSION['user_id']]);
            
            if (!$stmt->fetch()) {
                $insertParticipant = "INSERT INTO chat_participants (room_id, user_id) VALUES (?, ?)";
                $stmt = $db->prepare($insertParticipant);
                $stmt->execute([$room['id'], $_SESSION['user_id']]);
            }
        }
    }
}

// Get private chats (excluding archived)
$getPrivateChats = "SELECT cr.*, cp2.user_id as other_user_id, u.full_name as other_user_name,
                   (SELECT COUNT(*) FROM chat_messages WHERE room_id = cr.id) as message_count,
                   (SELECT message FROM chat_messages WHERE room_id = cr.id ORDER BY created_at DESC LIMIT 1) as last_message,
                   (SELECT created_at FROM chat_messages WHERE room_id = cr.id ORDER BY created_at DESC LIMIT 1) as last_message_time,
                   COALESCE((SELECT COUNT(*) FROM chat_messages cm
                             WHERE cm.room_id = cr.id
                             AND cm.id > COALESCE(cp1.last_message_read, 0)
                            AND cm.sender_id != ?), 0) as unread_count
                   FROM chat_rooms cr
                   JOIN chat_participants cp1 ON cr.id = cp1.room_id
                   JOIN chat_participants cp2 ON cr.id = cp2.room_id
                   JOIN users u ON cp2.user_id = u.id
                   WHERE cr.type = 'private' AND cp1.user_id = ? AND cp2.user_id != ?
                   AND cr.is_archived = 0 AND cp1.is_archived = 0
                   ORDER BY last_message_time DESC";
$stmt = $db->prepare($getPrivateChats);
$stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
$private_chats = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Chat - Airtel Risk Management</title>
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
            height: 100vh;
            overflow: hidden;
            padding-top: 150px;
        }

        /* Header styles from dashboard */
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
            text-decoration: none;
            color: white;
        }

        /* Navigation styles from dashboard */
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
            text-decoration: none;
        }

        .nav-item a.active {
            color: #E60012;
            border-bottom-color: #E60012;
            background-color: rgba(230, 0, 18, 0.05);
        }

        .chat-container {
            display: flex;
            height: calc(100vh - 150px);
        }

        .sidebar {
            width: 320px;
            background: white;
            border-right: 1px solid #e1e5e9;
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            padding: 1rem;
            border-bottom: 1px solid #e1e5e9;
            background: #f8f9fa;
        }

        .sidebar-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 0.5rem;
        }

        .new-chat-btn {
            background: #E60012;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            width: 100%;
            transition: background 0.3s;
        }

        .new-chat-btn:hover {
            background: #B8000E;
        }

        .chat-list {
            flex: 1;
            overflow-y: auto;
        }

        .chat-item {
            padding: 1rem;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            transition: background 0.3s;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
        }

        .chat-item:hover {
            background: #f8f9fa;
        }

        .chat-item.active {
            background: #e3f2fd;
            border-left: 4px solid #E60012;
        }

        .chat-item.permanent {
            background: #f8f9fa;
            border-left: 3px solid #28a745;
        }

        .chat-item.permanent.active {
            background: #e3f2fd;
            border-left: 4px solid #E60012;
        }

        .chat-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .chat-icon.group {
            background: #e3f2fd;
            color: #1976d2;
        }

        .chat-icon.private {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .chat-info {
            flex: 1;
            min-width: 0;
        }

        .chat-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .chat-last-message {
            font-size: 0.85rem;
            color: #666;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .chat-actions {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            display: none;
        }

        .chat-item:hover .chat-actions {
            display: flex;
            gap: 0.25rem;
        }

        .chat-action-btn {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 0.25rem 0.5rem;
            cursor: pointer;
            font-size: 0.75rem;
            color: #666;
            transition: all 0.3s;
        }

        .chat-action-btn:hover {
            background: #e9ecef;
            color: #333;
        }

        .unread-badge {
            background: #E60012;
            color: white;
            border-radius: 50%;
            min-width: 20px;
            height: 20px;
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-left: auto;
        }

        .chat-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: white;
        }

        .chat-header {
            padding: 1rem;
            border-bottom: 1px solid #e1e5e9;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .chat-header-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .chat-header-info {
            flex: 1;
        }

        .chat-header-info h3 {
            margin: 0;
            color: #333;
            font-size: 1.1rem;
        }

        .chat-header-info p {
            margin: 0;
            color: #666;
            font-size: 0.9rem;
        }

        .chat-header-actions {
            display: flex;
            gap: 0.5rem;
        }

        .header-action-btn {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 0.5rem;
            cursor: pointer;
            color: #666;
            transition: all 0.3s;
        }

        .header-action-btn:hover {
            background: #e9ecef;
            color: #333;
        }

        .messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 1rem;
            background: #fafafa;
        }

        .message {
            margin-bottom: 1rem;
            display: flex;
            gap: 0.75rem;
        }

        .message.own {
            flex-direction: row-reverse;
        }

        .message-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: #E60012;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        .message.own .message-avatar {
            background: #28a745;
        }

        .message-content {
            max-width: 70%;
            background: white;
            padding: 0.75rem 1rem;
            border-radius: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .message.own .message-content {
            background: #E60012;
            color: white;
        }

        .message-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.25rem;
        }

        .message-sender {
            font-weight: 600;
            font-size: 0.85rem;
        }

        .message.own .message-sender {
            color: rgba(255,255,255,0.9);
        }

        .message-time {
            font-size: 0.75rem;
            color: #666;
        }

        .message.own .message-time {
            color: rgba(255,255,255,0.7);
        }

        .message-text {
            word-wrap: break-word;
            line-height: 1.4;
        }

        .mention {
            background: rgba(230, 0, 18, 0.1);
            color: #E60012;
            padding: 0.1rem 0.3rem;
            border-radius: 3px;
            font-weight: 600;
        }

        .message.own .mention {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }

        .message-file {
            background: rgba(0,0,0,0.1);
            padding: 0.5rem;
            border-radius: 0.5rem;
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .message.own .message-file {
            background: rgba(255,255,255,0.2);
        }

        .file-icon {
            width: 30px;
            height: 30px;
            background: rgba(0,0,0,0.1);
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
        }

        .file-info {
            flex: 1;
            min-width: 0;
        }

        .file-name {
            font-weight: 600;
            font-size: 0.85rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .file-size {
            font-size: 0.75rem;
            opacity: 0.8;
        }

        .file-download {
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 4px;
            transition: background 0.3s;
        }

        .file-download:hover {
            background: rgba(0,0,0,0.1);
        }

        .message-input-container {
            padding: 1rem;
            border-top: 1px solid #e1e5e9;
            background: white;
        }

        .message-input-form {
            display: flex;
            gap: 0.5rem;
            align-items: flex-end;
        }

        .file-input-container {
            position: relative;
        }

        .file-input {
            display: none;
        }

        .file-input-btn {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 50%;
            width: 44px;
            height: 44px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
            transition: all 0.3s;
        }

        .file-input-btn:hover {
            background: #e9ecef;
            color: #333;
        }

        .message-input {
            flex: 1;
            border: 2px solid #e1e5e9;
            border-radius: 20px;
            padding: 0.75rem 1rem;
            resize: none;
            max-height: 100px;
            min-height: 44px;
            font-family: inherit;
            font-size: 0.9rem;
        }

        .message-input:focus {
            outline: none;
            border-color: #E60012;
        }

        .send-btn {
            background: #E60012;
            color: white;
            border: none;
            border-radius: 50%;
            width: 44px;
            height: 44px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s;
        }

        .send-btn:hover {
            background: #B8000E;
        }

        .send-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }

        .empty-chat {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #666;
            text-align: center;
        }

        .empty-chat i {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: #ddd;
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
            border-radius: 10px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-header {
            background: #E60012;
            color: white;
            padding: 1rem;
            border-radius: 10px 10px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
        }

        .close {
            color: white;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .search-filters {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .search-input {
            flex: 1;
            min-width: 200px;
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .filter-select {
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: white;
        }

        .user-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .user-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.3s;
        }

        .user-item:hover {
            background: #f8f9fa;
        }

        .user-avatar-small {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: #E60012;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .user-info-small {
            flex: 1;
        }

        .user-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 0.25rem;
        }

        .user-role {
            font-size: 0.85rem;
            color: #666;
        }

        .file-preview {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            display: none;
            align-items: center;
            gap: 0.5rem;
        }

        .file-preview.show {
            display: flex;
        }

        .file-preview-info {
            flex: 1;
            min-width: 0;
        }

        .file-preview-name {
            font-weight: 600;
            font-size: 0.9rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .file-preview-size {
            font-size: 0.8rem;
            color: #666;
        }

        .file-preview-remove {
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 0.25rem 0.5rem;
            cursor: pointer;
            font-size: 0.8rem;
        }

        /* Mention dropdown */
        .mention-dropdown {
            position: absolute;
            bottom: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            max-height: 200px;
            overflow-y: auto;
            display: none;
            z-index: 1000;
        }

        .mention-dropdown.show {
            display: block;
        }

        .mention-item {
            padding: 0.5rem 0.75rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: background 0.3s;
        }

        .mention-item:hover,
        .mention-item.selected {
            background: #f8f9fa;
        }

        .mention-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #E60012;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .mention-info {
            flex: 1;
        }

        .mention-name {
            font-weight: 600;
            font-size: 0.85rem;
        }

        .mention-role {
            font-size: 0.75rem;
            color: #666;
        }

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
            
            .nav {
                top: 120px;
                padding: 0.25rem 0;
            }
            
            .nav-content {
                padding: 0 0.5rem;
                overflow-x: auto;
            }
            
            .nav-menu {
                flex-wrap: nowrap;
                justify-content: flex-start;
                gap: 0;
                min-width: max-content;
                padding: 0 0.5rem;
            }
            
            .nav-item a {
                padding: 0.75rem 0.5rem;
                font-size: 0.75rem;
                text-align: center;
                min-height: 44px;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 0.25rem;
                white-space: nowrap;
            }

            .chat-container {
                height: calc(100vh - 200px);
            }

            .sidebar {
                width: 100%;
                position: absolute;
                z-index: 100;
                height: 100%;
                transform: translateX(-100%);
                transition: transform 0.3s;
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .chat-main {
                width: 100%;
            }

            .search-filters {
                flex-direction: column;
            }

            .search-input {
                min-width: auto;
            }
        }

        .loading {
            text-align: center;
            padding: 2rem;
            color: #666;
        }

        .typing-indicator {
            display: none;
            padding: 0.5rem 1rem;
            color: #666;
            font-style: italic;
            font-size: 0.85rem;
        }

        .message.deleted .message-content {
            opacity: 0.6;
            font-style: italic;
        }

        .message.deleted .message-text {
            color: #999;
        }

        /* Updated delete button styles with white color */
        .message-delete-btn {
            background: none;
            border: none;
            color: white; /* Changed to white */
            cursor: pointer;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            opacity: 0;
            transition: all 0.3s;
            margin-left: 0.5rem;
            flex-shrink: 0;
        }

        /* For non-own messages, keep the red color */
        .message:not(.own) .message-delete-btn {
            color: #dc3545;
        }

        /* For own messages (red background), use white color */
        .message.own .message-delete-btn {
            color: white;
        }

        .message:hover .message-delete-btn {
            opacity: 1;
        }

        .message.own:hover .message-delete-btn {
            opacity: 1;
        }

        /* Hover effect for delete button */
        .message-delete-btn:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .message:not(.own) .message-delete-btn:hover {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        .message-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.25rem;
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
                    <p class="sub-title">Team Chat</p>
                </div>
            </div>
            <div class="header-right">
                <div class="user-avatar"><?php echo isset($_SESSION['full_name']) ? strtoupper(substr($_SESSION['full_name'], 0, 1)) : 'R'; ?></div>
                <div class="user-details">
                    <div class="user-email"><?php echo isset($_SESSION['full_name']) ? $_SESSION['full_name'] : 'No Name'; ?></div>
                    <div class="user-role">
                        <?php 
                        $roleDisplay = '';
                        switch($_SESSION['role']) {
                            case 'admin':
                                $roleDisplay = 'Administrator';
                                break;
                            case 'risk_owner':
                                $roleDisplay = 'Risk Owner';
                                break;
                            case 'compliance_team':
                            case 'compliance':
                                $roleDisplay = 'Compliance Team';
                                break;
                            default:
                                $roleDisplay = ucfirst($_SESSION['role']);
                        }
                        echo $roleDisplay;
                        if (!empty($user['department'])) {
                            echo ' • ' . htmlspecialchars($user['department']);
                        } elseif (!empty($_SESSION['department'])) {
                            echo ' • ' . htmlspecialchars($_SESSION['department']);
                        }
                        ?>
                    </div>
                </div>
                <a href="logout.php" class="logout-btn">Logout</a>
            </div>
        </div>
    </header>

    <nav class="nav">
        <div class="nav-content">
            <ul class="nav-menu">
                <?php if ($_SESSION['role'] === 'admin'): ?>
                    <!-- Admin Navigation -->
                    <li class="nav-item">
                        <a href="admin_dashboard.php">
                            📊 Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="admin_dashboard.php?tab=users">
                            👥 User Management
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="admin_dashboard.php?tab=database">
                            🗄️ Database Management
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="admin_dashboard.php?tab=notifications">
                            🔔 Notifications
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="teamchat.php" class="active">
                            💬 Team Chat
                        </a>
                    </li>
                <?php elseif ($_SESSION['role'] === 'risk_owner'): ?>
                    <!-- Risk Owner Navigation -->
                    <li class="nav-item">
                        <a href="risk_owner_dashboard.php">
                            🏠 Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="report_risk.php">
                            📝 Report Risk
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="risk_owner_dashboard.php?tab=my-reports">
                            👀 My Reports
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="risk_owner_dashboard.php?tab=procedures">
                            📋 Procedures
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="teamchat.php" class="active">
                            💬 Team Chat
                        </a>
                    </li>
                <?php elseif ($_SESSION['role'] === 'compliance_team' || $_SESSION['role'] === 'compliance'): ?>
                    <!-- Compliance Navigation -->
                    <li class="nav-item">
                        <a href="compliance_dashboard.php">
                            🏠 Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="compliance_dashboard.php?tab=reviews">
                            📋 Risk Reviews
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="compliance_dashboard.php?tab=reports">
                            📊 Reports
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="compliance_dashboard.php?tab=audits">
                            🔍 Audits
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="teamchat.php" class="active">
                            💬 Team Chat
                        </a>
                    </li>
                <?php else: ?>
                    <!-- Default Navigation -->
                    <li class="nav-item">
                        <a href="dashboard.php">
                            🏠 Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="teamchat.php" class="active">
                            💬 Team Chat
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>

    <div class="chat-container">
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3 class="sidebar-title">Conversations</h3>
                <button class="new-chat-btn" onclick="openNewChatModal()">
                    <i class="fas fa-plus"></i> New Chat
                </button>
            </div>
            
            <div class="chat-list">
                <!-- Group Chats -->
                <?php foreach ($chat_rooms as $room): ?>
                    <?php if ($room['type'] === 'group'): ?>
                    <div class="chat-item <?php echo $room['is_permanent'] ? 'permanent' : ''; ?>" onclick="selectChat(<?php echo $room['id']; ?>, 'group', '<?php echo htmlspecialchars($room['name']); ?>', '<?php echo htmlspecialchars($room['description'] ?? ''); ?>', <?php echo $room['is_permanent']; ?>)">
                        <div class="chat-icon group">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="chat-info">
                            <div class="chat-name"><?php echo htmlspecialchars($room['name']); ?></div>
                            <div class="chat-last-message">
                                <?php echo $room['last_message'] ? htmlspecialchars(substr($room['last_message'], 0, 30)) . '...' : 'No messages yet'; ?>
                            </div>
                        </div>
                        <?php if ($room['unread_count'] > 0): ?>
                            <div class="unread-badge"><?php echo $room['unread_count']; ?></div>
                        <?php endif; ?>
                        <div class="chat-actions">
                            <button class="chat-action-btn" onclick="event.stopPropagation(); clearChat(<?php echo $room['id']; ?>, <?php echo $room['is_permanent'] ? 'true' : 'false'; ?>)" title="<?php echo $room['is_permanent'] ? 'Clear Messages' : 'Clear Chat'; ?>">
                                <i class="fas fa-<?php echo $room['is_permanent'] ? 'broom' : 'trash'; ?>"></i>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>

                <!-- Private Chats -->
                <?php if (!empty($private_chats)): ?>
                    <div style="padding: 0.5rem 1rem; background: #f8f9fa; font-size: 0.85rem; font-weight: 600; color: #666; border-bottom: 1px solid #e1e5e9;">
                        Private Messages
                    </div>
                    <?php foreach ($private_chats as $chat): ?>
                    <div class="chat-item" onclick="selectChat(<?php echo $chat['id']; ?>, 'private', '<?php echo htmlspecialchars($chat['other_user_name']); ?>', 'Private conversation', false)">
                        <div class="chat-icon private">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="chat-info">
                            <div class="chat-name"><?php echo htmlspecialchars($chat['other_user_name']); ?></div>
                            <div class="chat-last-message">
                                <?php echo $chat['last_message'] ? htmlspecialchars(substr($chat['last_message'], 0, 30)) . '...' : 'No messages yet'; ?>
                            </div>
                        </div>
                        <?php if ($chat['unread_count'] > 0): ?>
                            <div class="unread-badge"><?php echo $chat['unread_count']; ?></div>
                        <?php endif; ?>
                        <div class="chat-actions">
                            <button class="chat-action-btn" onclick="event.stopPropagation(); clearChat(<?php echo $chat['id']; ?>, false)" title="Clear Chat">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="chat-main">
            <div class="empty-chat" id="emptyChat">
                <i class="fas fa-comments"></i>
                <h3>Welcome to Team Chat</h3>
                <p>Select a conversation to start chatting with your colleagues</p>
                <p style="font-size: 0.9rem; color: #999; margin-top: 1rem;">
                    💡 You can message anyone in the system, attach files, and mention colleagues with @username
                </p>
            </div>

            <div id="chatArea" style="display: none; height: 100%; flex-direction: column;">
                <div class="chat-header" id="chatHeader">
                    <!-- Chat header will be populated by JavaScript -->
                </div>
                
                <div class="messages-container" id="messagesContainer">
                    <!-- Messages will be loaded here -->
                </div>
                
                <div class="message-input-container">
                    <div class="file-preview" id="filePreview">
                        <div class="file-icon">
                            <i class="fas fa-file"></i>
                        </div>
                        <div class="file-preview-info">
                            <div class="file-preview-name" id="filePreviewName"></div>
                            <div class="file-preview-size" id="filePreviewSize"></div>
                        </div>
                        <button class="file-preview-remove" onclick="removeFilePreview()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    
                    <div style="position: relative;">
                        <div class="mention-dropdown" id="mentionDropdown">
                            <!-- Mention suggestions will appear here -->
                        </div>
                        
                        <form class="message-input-form" id="messageForm" enctype="multipart/form-data">
                            <div class="file-input-container">
                                <input type="file" id="fileInput" class="file-input" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip" onchange="handleFileSelect(event)">
                                <label for="fileInput" class="file-input-btn" title="Attach File">
                                    <i class="fas fa-paperclip"></i>
                                </label>
                            </div>
                            <textarea 
                                class="message-input" 
                                id="messageInput" 
                                placeholder="Type your message... (use @ to mention someone)" 
                                rows="1"
                                onkeydown="handleKeyDown(event)"
                                oninput="handleMentionInput(event)"
                            ></textarea>
                            <button type="submit" class="send-btn" id="sendBtn">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- New Chat Modal -->
    <div id="newChatModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Start New Conversation</h3>
                <button class="close" onclick="closeNewChatModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="search-filters">
                    <input type="text" id="userSearch" class="search-input" placeholder="Search by name, email, or department...">
                    <select id="userFilter" class="filter-select">
                        <option value="all">All Users</option>
                        <?php if ($_SESSION['role'] === 'admin'): ?>
                            <option value="admins">Administrators</option>
                            <option value="risk_owners">Risk Owners</option>
                            <option value="compliance">Compliance Team</option>
                        <?php elseif ($_SESSION['role'] === 'risk_owner'): ?>
                            <option value="admins">Administrators</option>
                            <option value="risk_owners">Risk Owners</option>
                            <option value="compliance">Compliance Team</option>
                        <?php elseif ($_SESSION['role'] === 'compliance_team' || $_SESSION['role'] === 'compliance'): ?>
                            <option value="admins">Administrators</option>
                            <option value="risk_owners">Risk Owners</option>
                            <option value="compliance">Compliance Team</option>
                        <?php endif; ?>
                        <option value="department">My Department</option>
                    </select>
                </div>
                <div class="loading" id="usersLoading">Loading users...</div>
                <div class="user-list" id="usersList" style="display: none;">
                    <!-- Users will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentRoomId = null;
        let currentRoomType = null;
        let currentRoomName = null; // Added to track room name for mention filtering
        let currentRoomPermanent = false;
        let lastMessageId = 0;
        let messagePolling = null;
        let selectedFile = null;
        let mentionUsers = [];
        let selectedMentionIndex = -1;

        // Initialize chat
        document.addEventListener('DOMContentLoaded', function() {
            // Don't auto-select any chat - let user choose
            
            // Update unread counts periodically
            setInterval(updateUnreadCounts, 5000);
        });

        function selectChat(roomId, type, name, description, isPermanent) {
            // Update active chat item
            document.querySelectorAll('.chat-item').forEach(item => {
                item.classList.remove('active');
            });
            event.currentTarget.classList.add('active');

            // Set current room
            currentRoomId = roomId;
            currentRoomType = type;
            currentRoomName = name; // Store room name for mention filtering
            currentRoomPermanent = isPermanent;
            lastMessageId = 0;

            // Update chat header
            const chatHeader = document.getElementById('chatHeader');
            const iconClass = type === 'group' ? 'fas fa-users' : 'fas fa-user';
            const iconBg = type === 'group' ? 'background: #e3f2fd; color: #1976d2;' : 'background: #f3e5f5; color: #7b1fa2;';
            const actionText = isPermanent ? 'Clear Messages' : 'Clear Chat';
            const actionIcon = isPermanent ? 'broom' : 'trash';
            
            chatHeader.innerHTML = `
                <div class="chat-header-icon" style="${iconBg}">
                    <i class="${iconClass}"></i>
                </div>
                <div class="chat-header-info">
                    <h3>${name}</h3>
                    <p>${description}</p>
                </div>
                <div class="chat-header-actions">
                    <button class="header-action-btn" onclick="clearChat(${roomId}, ${isPermanent})" title="${actionText}">
                        <i class="fas fa-${actionIcon}"></i>
                    </button>
                </div>
            `;

            // Show chat area
            document.getElementById('emptyChat').style.display = 'none';
            document.getElementById('chatArea').style.display = 'flex';

            // Clear messages and load new ones
            document.getElementById('messagesContainer').innerHTML = '';
            loadMessages();

            // Start polling for new messages
            if (messagePolling) {
                clearInterval(messagePolling);
            }
            messagePolling = setInterval(loadMessages, 2000);

            // Clear unread badge for this chat
            const unreadBadge = event.currentTarget.querySelector('.unread-badge');
            if (unreadBadge) {
                unreadBadge.remove();
            }
        }

        function loadMessages() {
            if (!currentRoomId) return;

            const formData = new FormData();
            formData.append('action', 'get_messages');
            formData.append('room_id', currentRoomId);
            formData.append('last_message_id', lastMessageId);

            fetch('teamchat.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.messages.length > 0) {
                    const messagesContainer = document.getElementById('messagesContainer');
                    
                    data.messages.forEach(message => {
                        const messageDiv = createMessageElement(message);
                        messagesContainer.appendChild(messageDiv);
                        lastMessageId = Math.max(lastMessageId, message.id);
                    });

                    // Scroll to bottom
                    messagesContainer.scrollTop = messagesContainer.scrollHeight;
                }
            })
            .catch(error => {
                console.error('Error loading messages:', error);
            });
        }

        function createMessageElement(message) {
            const messageDiv = document.createElement('div');
            const isOwn = message.sender_id == <?php echo $_SESSION['user_id']; ?>;
            const isDeleted = message.is_deleted == 1;
            messageDiv.className = `message ${isOwn ? 'own' : ''} ${isDeleted ? 'deleted' : ''}`;

            const senderInitial = message.full_name ? message.full_name.charAt(0).toUpperCase() : 'U';
            const messageTime = new Date(message.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            const senderDepartment = message.department ? ` • ${message.department}` : '';

            let fileContent = '';
            if (message.message_type === 'file' && message.file_name && !isDeleted) {
                const fileIcon = getFileIcon(message.file_name);
                const fileSize = formatFileSize(message.file_size);
                fileContent = `
                    <div class="message-file">
                        <div class="file-icon">${fileIcon}</div>
                        <div class="file-info">
                            <div class="file-name">${escapeHtml(message.file_name)}</div>
                            <div class="file-size">${fileSize}</div>
                        </div>
                        <button class="file-download" onclick="downloadFile('${message.file_path}', '${message.file_name}')" title="Download">
                            <i class="fas fa-download"></i>
                        </button>
                    </div>
                `;
            }

            // Process mentions in the message text
            let processedMessage = message.message;
            if (!isDeleted) {
                processedMessage = processedMessage.replace(/\[@([^\]]+)\]/g, '<span class="mention">@$1</span>');
            }

            // Add delete button for own messages or if user is admin
            let deleteButton = '';
            if ((isOwn || <?php echo $_SESSION['role'] == 'admin' ? 'true' : 'false'; ?>) && !isDeleted) {
                deleteButton = `
                    <button class="message-delete-btn" onclick="event.stopPropagation(); deleteMessage(${message.id})" title="Delete Message">
                        <i class="fas fa-trash"></i>
                    </button>
                `;
            }

            messageDiv.innerHTML = `
                <div class="message-avatar">${senderInitial}</div>
                <div class="message-content">
                    <div class="message-header">
                        <span class="message-sender">${message.full_name}${senderDepartment}</span>
                        <span class="message-time">${messageTime}</span>
                        ${deleteButton}
                    </div>
                    <div class="message-text">${processedMessage}</div>
                    ${fileContent}
                </div>
            `;

            return messageDiv;
        }

        function deleteMessage(messageId) {
            if (!confirm('Are you sure you want to delete this message?')) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'delete_message');
            formData.append('message_id', messageId);

            fetch('teamchat.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Reload messages to show the deleted message
                    loadMessages();
                } else {
                    alert('Error deleting message: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error deleting message:', error);
                alert('Error deleting message');
            });
        }

        function sendMessage() {
            if (!currentRoomId) return;

            const messageInput = document.getElementById('messageInput');
            const message = messageInput.value.trim();
            const fileInput = document.getElementById('fileInput');

            if (!message && !selectedFile) return;

            const formData = new FormData();
            formData.append('action', 'send_message');
            formData.append('room_id', currentRoomId);
            formData.append('message', message);

            if (selectedFile) {
                formData.append('file', selectedFile);
            }

            // Disable send button
            const sendBtn = document.getElementById('sendBtn');
            sendBtn.disabled = true;

            fetch('teamchat.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    messageInput.value = '';
                    removeFilePreview();
                    hideMentionDropdown();
                    loadMessages(); // Reload messages immediately
                } else {
                    alert('Error sending message: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error sending message:', error);
                alert('Error sending message');
            })
            .finally(() => {
                sendBtn.disabled = false;
                messageInput.focus();
            });
        }

        function handleKeyDown(event) {
            const mentionDropdown = document.getElementById('mentionDropdown');
            const isDropdownVisible = mentionDropdown.classList.contains('show');

            if (isDropdownVisible) {
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    selectedMentionIndex = Math.min(selectedMentionIndex + 1, mentionUsers.length - 1);
                    updateMentionSelection();
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    selectedMentionIndex = Math.max(selectedMentionIndex - 1, 0);
                    updateMentionSelection();
                } else if (event.key === 'Enter') {
                    event.preventDefault();
                    if (selectedMentionIndex >= 0 && mentionUsers[selectedMentionIndex]) {
                        selectMention(mentionUsers[selectedMentionIndex]);
                    }
                    return;
                } else if (event.key === 'Escape') {
                    hideMentionDropdown();
                    return;
                }
            }

            if (event.key === 'Enter' && !event.shiftKey && !isDropdownVisible) {
                event.preventDefault();
                sendMessage();
            }
        }

        function handleMentionInput(event) {
            const input = event.target;
            const value = input.value;
            const cursorPos = input.selectionStart;
            
            // Find @ symbol before cursor
            const textBeforeCursor = value.substring(0, cursorPos);
            const mentionMatch = textBeforeCursor.match(/@(\w*)$/);
            
            if (mentionMatch) {
                const searchTerm = mentionMatch[1];
                loadMentionUsers(searchTerm);
            } else {
                hideMentionDropdown();
            }
        }

        function loadMentionUsers(searchTerm) {
            if (!currentRoomId) return;

            const formData = new FormData();
            formData.append('action', 'get_mention_users');
            formData.append('room_id', currentRoomId);
            formData.append('search', searchTerm);

            fetch('teamchat.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    mentionUsers = data.users;
                    selectedMentionIndex = 0;
                    displayMentionDropdown();
                }
            })
            .catch(error => {
                console.error('Error loading mention users:', error);
            });
        }

        function displayMentionDropdown() {
            const dropdown = document.getElementById('mentionDropdown');
            dropdown.innerHTML = '';

            mentionUsers.forEach((user, index) => {
                const item = document.createElement('div');
                item.className = `mention-item ${index === selectedMentionIndex ? 'selected' : ''}`;
                item.onclick = () => selectMention(user);

                const initial = user.full_name ? user.full_name.charAt(0).toUpperCase() : 'U';
                const roleText = getRoleDisplayName(user.role);
                const departmentText = user.department ? ` • ${user.department}` : '';

                item.innerHTML = `
                    <div class="mention-avatar">${initial}</div>
                    <div class="mention-info">
                        <div class="mention-name">${user.full_name}</div>
                        <div class="mention-role">${roleText}${departmentText}</div>
                    </div>
                `;

                dropdown.appendChild(item);
            });

            dropdown.classList.add('show');
        }

        function updateMentionSelection() {
            const items = document.querySelectorAll('.mention-item');
            items.forEach((item, index) => {
                item.classList.toggle('selected', index === selectedMentionIndex);
            });
        }

        function selectMention(user) {
            const input = document.getElementById('messageInput');
            const value = input.value;
            const cursorPos = input.selectionStart;
            
            // Find @ symbol before cursor
            const textBeforeCursor = value.substring(0, cursorPos);
            const mentionMatch = textBeforeCursor.match(/@(\w*)$/);
            
            if (mentionMatch) {
                const beforeMention = textBeforeCursor.substring(0, mentionMatch.index);
                const afterCursor = value.substring(cursorPos);
                const newValue = beforeMention + `@${user.full_name} ` + afterCursor;
                
                input.value = newValue;
                const newCursorPos = beforeMention.length + user.full_name.length + 2;
                input.setSelectionRange(newCursorPos, newCursorPos);
            }

            hideMentionDropdown();
            input.focus();
        }

        function hideMentionDropdown() {
            const dropdown = document.getElementById('mentionDropdown');
            dropdown.classList.remove('show');
            mentionUsers = [];
            selectedMentionIndex = -1;
        }

        function handleFileSelect(event) {
            const file = event.target.files[0];
            if (file) {
                selectedFile = file;
                showFilePreview(file);
            }
        }

        function showFilePreview(file) {
            const preview = document.getElementById('filePreview');
            const nameEl = document.getElementById('filePreviewName');
            const sizeEl = document.getElementById('filePreviewSize');

            nameEl.textContent = file.name;
            sizeEl.textContent = formatFileSize(file.size);
            preview.classList.add('show');
        }

        function removeFilePreview() {
            const preview = document.getElementById('filePreview');
            const fileInput = document.getElementById('fileInput');
            
            preview.classList.remove('show');
            fileInput.value = '';
            selectedFile = null;
        }

        function getFileIcon(fileName) {
            const extension = fileName.split('.').pop().toLowerCase();
            switch (extension) {
                case 'pdf': return '<i class="fas fa-file-pdf"></i>';
                case 'doc':
                case 'docx': return '<i class="fas fa-file-word"></i>';
                case 'xls':
                case 'xlsx': return '<i class="fas fa-file-excel"></i>';
                case 'jpg':
                case 'jpeg':
                case 'png':
                case 'gif': return '<i class="fas fa-file-image"></i>';
                case 'zip': return '<i class="fas fa-file-archive"></i>';
                case 'txt': return '<i class="fas fa-file-alt"></i>';
                default: return '<i class="fas fa-file"></i>';
            }
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        function downloadFile(filePath, fileName) {
            const link = document.createElement('a');
            link.href = filePath;
            link.download = fileName;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Message form submission
        document.getElementById('messageForm').addEventListener('submit', function(e) {
            e.preventDefault();
            sendMessage();
        });

        function openNewChatModal() {
            document.getElementById('newChatModal').classList.add('show');
            loadUsers();
        }

        function closeNewChatModal() {
            document.getElementById('newChatModal').classList.remove('show');
        }

        function loadUsers() {
            const search = document.getElementById('userSearch').value;
            const filter = document.getElementById('userFilter').value;

            const formData = new FormData();
            formData.append('action', 'get_users');
            formData.append('search', search);
            formData.append('filter', filter);

            fetch('teamchat.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const usersList = document.getElementById('usersList');
                    const usersLoading = document.getElementById('usersLoading');
                    
                    usersLoading.style.display = 'none';
                    usersList.style.display = 'block';
                    usersList.innerHTML = '';
                    displayUsers(data.users);
                }
            })
            .catch(error => {
                console.error('Error loading users:', error);
            });
        }

        function displayUsers(users) {
            const usersList = document.getElementById('usersList');
            usersList.innerHTML = '';

            if (users.length === 0) {
                usersList.innerHTML = '<div style="text-align: center; padding: 2rem; color: #666;">No users found</div>';
                return;
            }

            users.forEach(user => {
                const userDiv = document.createElement('div');
                userDiv.className = 'user-item';
                userDiv.onclick = () => startPrivateChat(user.id, user.full_name);

                const initial = user.full_name ? user.full_name.charAt(0).toUpperCase() : 'U';
                const roleText = getRoleDisplayName(user.role);
                const departmentText = user.department ? ` • ${user.department}` : '';

                userDiv.innerHTML = `
                    <div class="user-avatar-small">${initial}</div>
                    <div class="user-info-small">
                        <div class="user-name">${user.full_name}</div>
                        <div class="user-role">${roleText}${departmentText}</div>
                    </div>
                `;

                usersList.appendChild(userDiv);
            });
        }

        function getRoleDisplayName(role) {
            switch (role) {
                case 'compliance_officer': return 'Compliance Officer';
                case 'risk_owner': return 'Risk Owner';
                case 'admin': return 'Administrator';
                case 'manager': return 'Manager';
                default: return role.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase());
            }
        }

        // User search and filter functionality
        document.getElementById('userSearch').addEventListener('input', loadUsers);
        document.getElementById('userFilter').addEventListener('change', loadUsers);

        function startPrivateChat(userId, userName) {
            const formData = new FormData();
            formData.append('action', 'create_private_chat');
            formData.append('other_user_id', userId);

            fetch('teamchat.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeNewChatModal();
                    // Refresh the page to show the new chat
                    location.reload();
                }
            })
            .catch(error => {
                console.error('Error creating private chat:', error);
            });
        }

        function clearChat(roomId, isPermanent) {
            const confirmMessage = isPermanent ? 
                'Are you sure you want to clear all messages in this chat? The chat room will remain but all messages will be deleted.' :
                'Are you sure you want to clear this chat? It will be moved to the system bin.';
                
            if (!confirm(confirmMessage)) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'clear_chat');
            formData.append('room_id', roomId);

            fetch('teamchat.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.action === 'cleared') {
                        // For permanent chats, just reload messages
                        if (currentRoomId === roomId) {
                            document.getElementById('messagesContainer').innerHTML = '';
                            lastMessageId = 0;
                            loadMessages();
                        }
                        // Update last message display in sidebar
                        const chatItem = document.querySelector(`[onclick*="selectChat(${roomId}"]`);
                        if (chatItem) {
                            const lastMessageEl = chatItem.querySelector('.chat-last-message');
                            if (lastMessageEl) {
                                lastMessageEl.textContent = 'No messages yet';
                            }
                        }
                    } else {
                        // For private chats, refresh the page
                        location.reload();
                    }
                } else {
                    alert('Error clearing chat');
                }
            })
            .catch(error => {
                console.error('Error clearing chat:', error);
                alert('Error clearing chat');
            });
        }

        function updateUnreadCounts() {
            const formData = new FormData();
            formData.append('action', 'get_unread_counts');

            fetch('teamchat.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    data.counts.forEach(chat => {
                        const chatItems = document.querySelectorAll('.chat-item');
                        chatItems.forEach(item => {
                            const chatId = item.getAttribute('onclick').match(/selectChat\((\d+)/);
                            if (chatId && parseInt(chatId[1]) === chat.room_id) {
                                // Remove existing badge
                                const existingBadge = item.querySelector('.unread-badge');
                                if (existingBadge) {
                                    existingBadge.remove();
                                }
                                // Add new badge if there are unread messages
                                if (chat.unread_count > 0) {
                                    const badge = document.createElement('div');
                                    badge.className = 'unread-badge';
                                    badge.textContent = chat.unread_count;
                                    item.appendChild(badge);
                                }
                            }
                        });
                    });
                }
            })
            .catch(error => {
                console.error('Error updating unread counts:', error);
            });
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Auto-resize textarea
        document.getElementById('messageInput').addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 100) + 'px';
        });

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('newChatModal');
            if (event.target === modal) {
                closeNewChatModal();
            }
        });

        // Close mention dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('mentionDropdown');
            const input = document.getElementById('messageInput');
            if (!dropdown.contains(event.target) && event.target !== input) {
                hideMentionDropdown();
            }
        });

        // Clean up polling when page unloads
        window.addEventListener('beforeunload', function() {
            if (messagePolling) {
                clearInterval(messagePolling);
            }
        });

        // Mobile sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('show');
        }

        // Add mobile menu button for smaller screens
        if (window.innerWidth <= 768) {
            const chatHeader = document.getElementById('chatHeader');
            if (chatHeader) {
                const mobileMenuBtn = document.createElement('button');
                mobileMenuBtn.className = 'mobile-menu-btn';
                mobileMenuBtn.innerHTML = '<i class="fas fa-bars"></i>';
                mobileMenuBtn.onclick = toggleSidebar;
                chatHeader.prepend(mobileMenuBtn);
            }
        }
    </script>
</body>
</html>
