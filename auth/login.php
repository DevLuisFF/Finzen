<?php
/**
 * Sistema de Autenticación y Control de Acceso
 */

require "../config/database.php";

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['login_error'] = 'Método no permitido';
    header('Location: ../index.php');
    exit();
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    $_SESSION['login_error'] = 'Por favor completa todos los campos';
    header('Location: ../index.php');
    exit();
}

try {
    $db = Conexion::obtenerInstancia()->obtenerConexion();

    $sql = "SELECT id, nombre_usuario, hash_contraseña, rol_id, activo 
            FROM usuarios 
            WHERE nombre_usuario = :username LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':username', $username, PDO::PARAM_STR);
    $stmt->execute();

    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        usleep(500000);
        $_SESSION['login_error'] = 'Usuario no encontrado';
        header('Location: ../index.php');
        exit();
    }

    if (!$usuario['activo']) {
        $_SESSION['login_error'] = 'Tu cuenta está desactivada. Contacta al administrador.';
        header('Location: ../index.php');
        exit();
    }

    if (!password_verify($password, $usuario['hash_contraseña'])) {
        usleep(500000);
        $_SESSION['login_error'] = 'Contraseña incorrecta';
        header('Location: ../index.php');
        exit();
    }

    // Login exitoso
    $_SESSION['user_id'] = (int)$usuario['id'];
    $_SESSION['username'] = $usuario['nombre_usuario'];
    $_SESSION['rol_id'] = (int)$usuario['rol_id'];
    $_SESSION['login_success'] = true;

    // Redirección según rol
    switch ($_SESSION['rol_id']) {
        case 1: 
            header('Location: ../admin/index.php');
            break;
        case 2: 
            header('Location: ../user/index.php');
            break;
        default:
            session_destroy();
            $_SESSION['login_error'] = 'Rol de usuario no válido';
            header('Location: ../index.php');
            break;
    }
    exit();

} catch (PDOException $e) {
    error_log('Error en login: ' . $e->getMessage());
    $_SESSION['login_error'] = 'Error del servidor. Por favor intenta más tarde.';
    header('Location: ../index.php');
    exit();
}