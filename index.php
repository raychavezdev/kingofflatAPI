<?php

require_once __DIR__ . '/vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$host = $_ENV['DB_HOST'];
$user = $_ENV['DB_USER'];
$pass = $_ENV['DB_PASS'];
$db = $_ENV['DB_NAME'];
$jwt_secret = $_ENV['JWT_SECRET'];

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    respond(500, "Database connection failed: {$conn->connect_error}");
    exit;
}

header("Content-Type: application/json");



$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Añadir estos encabezados CORS antes de cualquier respuesta
function add_cors_headers()
{
    // Configura el encabezado CORS en todas las respuestas
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
}

// Manejo de la solicitud OPTIONS (preflight)
if ($method === 'OPTIONS') {
    add_cors_headers();
    respond(200, 'CORS preflight successful');
    exit;
}

// Añadir encabezados CORS antes de todas las respuestas en el switch
add_cors_headers();

switch (true) {
    case preg_match('/^\/.*\/login$/', $path):
        if ($method === 'POST') {
            login($conn);
        } else {
            respond(405, "Method not allowed.");
        }
        break;
    case preg_match('/^\/kof_api\/(\d+)?$/', $path, $matches):
        $id = $matches[1] ?? null;
        handle_skaters($method, $conn, $id);
        break;
    case preg_match('/^\/kof_api\/verify_token$/', $path):
        if ($method === 'POST') {
            if (authorize()) {
                respond(200, "Token is valid.");
            }
        } else {
            respond(405, "Method not allowed.");
        }
        break;
    default:
        respond(404, "Not Found.$path");
        break;
}

function login($conn)
{
    global $jwt_secret;
    $data = json_decode(file_get_contents('php://input'), true);
    $username = $data['username'] ?? null;
    $password = $data['password'] ?? null;

    if (!$username || !$password) {
        respond(400, "Username and password are required.");
        return;
    }

    $sql = "SELECT * FROM users WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            // Generate JWT
            $payload = [
                'id' => $user['id'],
                'username' => $user['username'],
                'exp' => time() + (60 * 60), // Expires in 1 hour
            ];
            $jwt = JWT::encode($payload, $jwt_secret, 'HS256');
            respond(200, "Login successful.", ['token' => $jwt]);
        } else {
            respond(401, "Invalid password.");
        }
    } else {
        respond(404, "User not found.");
    }
}


function authorize()
{
    global $jwt_secret;
    $headers = getallheaders();
    $auth_header = $headers['Authorization'] ?? null;

    if (!$auth_header || !str_starts_with($auth_header, "Bearer ")) {
        respond(401, "Unauthorized: Missing or invalid token.");
        return false;
    }

    // Extraer el token del encabezado
    $token = substr($auth_header, 7);
    try {
        // Decodificar el token
        $decoded = JWT::decode($token, new Key($jwt_secret, 'HS256'));

        // Verificar si el token ha expirado
        if ($decoded->exp < time()) {
            respond(401, "Unauthorized: Token expired.");
            return false;
        }

        // El token es válido y no ha expirado, se puede acceder
        return true;
    } catch (Exception $e) {
        respond(401, "Unauthorized: Invalid token.");
        return false;
    }
}

function handle_skaters($method, $conn, $id)
{
    if ($method !== 'GET' && !authorize()) {
        return;
    }

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
}




function get_all_skaters($conn)
{
    $sql = "SELECT * FROM skaters";
    $result = $conn->query($sql);

    if ($result) {
        $skaters = $result->fetch_all(MYSQLI_ASSOC);
        respond(200, "Skaters retrieved successfully.", $skaters);
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
        respond(200, "Skater retrieved successfully.", $skater);
    } else {
        respond(404, "Skater not found.");
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
        respond(201, "Skater created successfully.", $data);
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
        respond(200, "Skater updated successfully.");
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
        respond(200, "Skater deleted successfully.");
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