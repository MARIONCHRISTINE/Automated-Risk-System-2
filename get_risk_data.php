<?php
// API endpoint to fetch risk data for the matrix
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Database connection (adjust these credentials)
$host = 'localhost';
$dbname = 'airtel_risk_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Query to get risks with their categories
    $stmt = $pdo->prepare("
        SELECT 
            id,
            risk_title,
            risk_category,
            department,
            risk_level,
            created_at,
            CONCAT(risk_category, ',', department) as categories
        FROM risk_incidents 
        WHERE status != 'Resolved'
        ORDER BY created_at DESC
    ");
    
    $stmt->execute();
    $risks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process risks to create category arrays
    $processedRisks = [];
    foreach ($risks as $risk) {
        $categories = array_filter(array_map('trim', explode(',', $risk['categories'])));
        $processedRisks[] = [
            'id' => 'RISK_' . $risk['id'],
            'title' => $risk['risk_title'],
            'categories' => $categories,
            'level' => $risk['risk_level'],
            'created_at' => $risk['created_at']
        ];
    }
    
    echo json_encode($processedRisks);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
}
?>
