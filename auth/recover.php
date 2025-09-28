<?php
/**
 * reset_password.php
 * Restablecimiento directo de contraseña
 */

require "../config/database.php";
session_start();

// Verificar método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Acceso no autorizado");
}

// Obtener datos filtrados
$username    = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
$newPassword = $_POST['newPassword'] ?? '';

// Validaciones
$errores = [];
if (empty($username)) $errores[] = "El usuario es requerido";
if (strlen($newPassword) < 8) $errores[] = "La contraseña debe tener mínimo 8 caracteres";

if (!empty($errores)) {
    $_SESSION['error'] = implode("<br>", $errores);
    header('Location: ../forgot.php');
    exit;
}

try {
    $db = Conexion::obtenerInstancia()->obtenerConexion();

    $sql = "UPDATE usuarios SET 
            hash_contraseña = ?,
            actualizado_en = CURRENT_TIMESTAMP
            WHERE nombre_usuario = ?";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        password_hash($newPassword, PASSWORD_BCRYPT),
        $username
    ]);

    if ($stmt->rowCount() > 0) {
        $_SESSION['success'] = "Contraseña actualizada correctamente";
    } else {
        $_SESSION['error'] = "Usuario no encontrado o contraseña idéntica";
    }

    header('Location: ../index.php');
    exit;

} catch (Exception $e) {
    error_log("Error en reset_password: " . $e->getMessage());
    $_SESSION['error'] = "Error en el sistema. Contacte al administrador.";
    header('Location: ../forgot.php');
    exit;
}
