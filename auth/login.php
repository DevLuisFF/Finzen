<?php
/**
 * Sistema de Autenticación y Control de Acceso
 */

require "../config/database.php";

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php?error=metodo_no_permitido');
    exit();
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    header('Location: ../index.php?error=campos_vacios');
    exit();
}

try {
    $db = Conexion::obtenerInstancia()->obtenerConexion();

    $sql = "SELECT id, nombre_usuario, hash_contraseña, rol_id 
            FROM usuarios 
            WHERE nombre_usuario = :username LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':username', $username, PDO::PARAM_STR);
    $stmt->execute();

    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario || !password_verify($password, $usuario['hash_contraseña'])) {
        usleep(500000);
        header('Location: ../index.php?error=credenciales_incorrectas');
        exit();
    }

    $_SESSION['user_id'] = (int)$usuario['id'];
    $_SESSION['username'] = $usuario['nombre_usuario'];
    $_SESSION['rol_id'] = (int)$usuario['rol_id'];

    switch ($_SESSION['rol_id']) {
        case 1: header('Location: ../admin/index.php'); break;
        case 2: header('Location: ../user/index.php'); break;
        default:
            session_destroy();
            header('Location: ../index.php?error=rol_no_valido');
            break;
    }
    exit();

} catch (PDOException $e) {
    error_log('Error en login: ' . $e->getMessage());
    header('Location: ../index.php?error=error_servidor');
    exit();
}
