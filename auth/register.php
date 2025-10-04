<?php
/**
 * register.php
 * Proceso de registro de usuarios
 */

require "../config/database.php";
session_start();

// Validar método POST
if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    $_SESSION['register_error'] = 'Método no permitido';
    header('Location: ../register.php');
    exit();
}

// Obtener y limpiar datos del formulario
$username = trim(filter_var($_POST['username'] ?? '', FILTER_SANITIZE_STRING));
$email    = trim(filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL));
$password = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirmPassword'] ?? '';

// Validaciones básicas
if (empty($username) || empty($email) || empty($password) || empty($confirmPassword)) {
    $_SESSION['register_error'] = 'Todos los campos son requeridos';
    header('Location: ../register.php');
    exit();
}

// Validar que las contraseñas coincidan
if ($password !== $confirmPassword) {
    $_SESSION['register_error'] = 'Las contraseñas no coinciden';
    header('Location: ../register.php');
    exit();
}

// Validar formato de email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['register_error'] = 'Formato de email inválido';
    header('Location: ../register.php');
    exit();
}

// Validar fortaleza de contraseña
if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
    $_SESSION['register_error'] = 'La contraseña debe tener al menos 8 caracteres, una letra mayúscula y un número';
    header('Location: ../register.php');
    exit();
}

// Validar nombre de usuario
if (!preg_match('/^[a-zA-Z0-9_-]{3,20}$/', $username)) {
    $_SESSION['register_error'] = 'El nombre de usuario debe tener entre 3 y 20 caracteres y solo puede contener letras, números, guiones y guiones bajos';
    header('Location: ../register.php');
    exit();
}

try {
    $db = Conexion::obtenerInstancia()->obtenerConexion();

    // Verificar duplicados
    $sqlCheck = "SELECT id FROM usuarios WHERE nombre_usuario = :username OR correo_electronico = :email LIMIT 1";
    $stmtCheck = $db->prepare($sqlCheck);
    $stmtCheck->bindParam(':username', $username, PDO::PARAM_STR);
    $stmtCheck->bindParam(':email', $email, PDO::PARAM_STR);
    $stmtCheck->execute();

    if ($stmtCheck->fetch()) {
        // Especificar cuál está duplicado
        $sqlUser = "SELECT id FROM usuarios WHERE nombre_usuario = :username LIMIT 1";
        $stmtUser = $db->prepare($sqlUser);
        $stmtUser->bindParam(':username', $username, PDO::PARAM_STR);
        $stmtUser->execute();
        $_SESSION['register_error'] = $stmtUser->fetch() ? 'El nombre de usuario ya está en uso' : 'El correo electrónico ya está en uso';
        header('Location: ../register.php');
        exit();
    }

    // Hash seguro de la contraseña
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    // Insertar usuario
    $sqlInsert = "INSERT INTO usuarios (nombre_usuario, correo_electronico, hash_contraseña, rol_id, activo, creado_en)
                  VALUES (:username, :email, :password, 2, 1, NOW())";
    $stmtInsert = $db->prepare($sqlInsert);
    $stmtInsert->bindParam(':username', $username, PDO::PARAM_STR);
    $stmtInsert->bindParam(':email', $email, PDO::PARAM_STR);
    $stmtInsert->bindParam(':password', $hashedPassword, PDO::PARAM_STR);

    if ($stmtInsert->execute()) {
        // Obtener el ID del usuario recién insertado
        $userId = $db->lastInsertId();
        
        // Iniciar sesión automáticamente
        $_SESSION['user_id'] = (int)$userId;
        $_SESSION['username'] = $username;
        $_SESSION['rol_id'] = 2; // Rol de usuario normal
        $_SESSION['register_success'] = '¡Cuenta creada exitosamente! Bienvenido a Finzen.';
        
        // Redirigir directamente al dashboard del usuario
        header('Location: ../user/index.php');
        exit();
    } else {
        $_SESSION['register_error'] = 'Error al registrar el usuario';
        header('Location: ../register.php');
        exit();
    }

} catch (PDOException $e) {
    error_log('Error en registro: ' . $e->getMessage());
    $_SESSION['register_error'] = 'Error en el servidor. Por favor intente más tarde.';
    header('Location: ../register.php');
    exit();
}