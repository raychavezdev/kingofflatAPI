<?php

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$host = $_ENV['DB_HOST'];
$user = $_ENV['DB_USER'];
$pass = $_ENV['DB_PASS'];
$db = $_ENV['DB_NAME'];

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    respond(500, "Database connection failed: {$conn->connect_error}");
    exit;
}

header("Content-Type: application/json");

$method = $_SERVER['REQUEST_METHOD'];
$id = $_GET['id'] ?? null;


switch ($method) {
    case 'GET':
        if ($id === null) {
            get_all_skaters($conn);
        } else {
            get_skater($conn, $id);
        }
        break;
    case 'POST':
        insert_skater($conn);
        break;
    case 'PUT':
        if ($id !== null) {
            update_skater($conn, $id);
        } else {
            respond(400, "ID is required for updating a skater.");
        }
        break;
    case 'DELETE':
        if ($id !== null) {
            delete_skater($conn, $id);
        } else {
            respond(400, "ID is required for deleting a skater.");
        }
        break;
    default:

        respond(405, "Method not allowed.");
        break;
}

function get_all_skaters($conn)
{
    $sql = "SELECT * FROM skaters";
    $result = $conn->query($sql);

    if ($result) {
        $skaters = $result->fetch_all(MYSQLI_ASSOC);
        respond(200, "skaters retrieved successfully.", $skaters);
    } else {
        respond(500, "Error retrieving skaters.");
    }
}

function get_skater($conn, $id)
{
    $sql = "SELECT * FROM skaters WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $skater = $result->fetch_assoc();
        respond(200, "skater retrieved successfully.", $skater);
    } else {
        respond(404, "skater not found.");
    }
}

function insert_skater($conn)
{
    $data = json_decode(file_get_contents('php://input'), true);
    $name = $data['name'] ?? null;
    $points = $data['points'] ?? 0;
    $instagram = $data['instagram'] ?? null;

    if (!$name) {
        respond(400, "Name is required.");
        return;
    }

    $sql = "INSERT INTO skaters (name, points, instagram) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sis", $name, $points, $instagram);

    if ($stmt->execute()) {
        $data['id'] = $conn->insert_id;
        respond(201, "skater created successfully.", $data);
    } else {
        respond(500, "Error creating skater.");
    }
}

function update_skater($conn, $id)
{
    $data = json_decode(file_get_contents('php://input'), true);
    $name = $data['name'] ?? null;
    $points = $data['points'] ?? null;
    $instagram = $data['instagram'] ?? null;

    if (!$name || $points === null || !$instagram) {
        respond(400, "Name, points, and Instagram are required.");
        return;
    }

    $sql = "UPDATE skaters SET name = ?, points = ?, instagram = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sisi", $name, $points, $instagram, $id);

    if ($stmt->execute()) {
        respond(200, "skater updated successfully.");
    } else {
        respond(500, "Error updating skater.");
    }
}

function delete_skater($conn, $id)
{
    $sql = "DELETE FROM skaters WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        respond(200, "skater deleted successfully.");
    } else {
        respond(500, "Error deleting skater.");
    }
}

function respond($status, $message, $data = null)
{
    http_response_code($status);
    $response = ['status' => $status, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response);
}
