<?php
/**
 * recover.php
 * Restablecimiento directo de contraseña
 */

require "../config/database.php";
session_start();

// Verificar método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['recover_error'] = 'Método no permitido';
    header('Location: ../forgot.php');
    exit();
}

// Obtener datos filtrados
$username = trim(filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING));
$newPassword = $_POST['newPassword'] ?? '';
$confirmPassword = $_POST['confirmPassword'] ?? '';

// Validaciones
$errores = [];
if (empty($username)) {
    $errores[] = "El nombre de usuario es requerido";
}

if (strlen($newPassword) < 8) {
    $errores[] = "La contraseña debe tener mínimo 8 caracteres";
}

if (!preg_match('/[A-Z]/', $newPassword)) {
    $errores[] = "La contraseña debe contener al menos una letra mayúscula";
}

if (!preg_match('/[0-9]/', $newPassword)) {
    $errores[] = "La contraseña debe contener al menos un número";
}

if ($newPassword !== $confirmPassword) {
    $errores[] = "Las contraseñas no coinciden";
}

if (!empty($errores)) {
    $_SESSION['recover_error'] = implode("<br>", $errores);
    $_SESSION['form_data'] = ['username' => $username];
    header('Location: ../forgot.php');
    exit();
}

try {
    $db = Conexion::obtenerInstancia()->obtenerConexion();

    // Verificar si el usuario existe y está activo
    $sqlCheck = "SELECT id, hash_contraseña FROM usuarios WHERE nombre_usuario = ? AND activo = 1 LIMIT 1";
    $stmtCheck = $db->prepare($sqlCheck);
    $stmtCheck->execute([$username]);
    $usuario = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        $_SESSION['recover_error'] = "Usuario no encontrado o cuenta inactiva";
        $_SESSION['form_data'] = ['username' => $username];
        header('Location: ../forgot.php');
        exit();
    }

    // Verificar si la nueva contraseña es diferente a la actual
    if (password_verify($newPassword, $usuario['hash_contraseña'])) {
        $_SESSION['recover_error'] = "La nueva contraseña debe ser diferente a la actual";
        $_SESSION['form_data'] = ['username' => $username];
        header('Location: ../forgot.php');
        exit();
    }

    // Actualizar contraseña
    $sql = "UPDATE usuarios SET 
            hash_contraseña = ?,
            actualizado_en = CURRENT_TIMESTAMP
            WHERE nombre_usuario = ? AND activo = 1";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]),
        $username
    ]);

    if ($stmt->rowCount() > 0) {
        $_SESSION['recover_success'] = "¡Contraseña actualizada correctamente! Ya puedes iniciar sesión con tu nueva contraseña.";
        
        // Limpiar datos del formulario
        unset($_SESSION['form_data']);
        
        header('Location: ../index.php');
        exit();
    } else {
        $_SESSION['recover_error'] = "No se pudo actualizar la contraseña. Por favor intenta nuevamente.";
        $_SESSION['form_data'] = ['username' => $username];
        header('Location: ../forgot.php');
        exit();
    }

} catch (PDOException $e) {
    error_log("Error en recover.php: " . $e->getMessage());
    $_SESSION['recover_error'] = "Error en el sistema. Por favor intenta más tarde.";
    $_SESSION['form_data'] = ['username' => $username];
    header('Location: ../forgot.php');
    exit();
} catch (Exception $e) {
    error_log("Error inesperado en recover.php: " . $e->getMessage());
    $_SESSION['recover_error'] = "Error inesperado. Contacta al administrador.";
    $_SESSION['form_data'] = ['username' => $username];
    header('Location: ../forgot.php');
    exit();
}