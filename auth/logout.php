<?php
/**
 * Cierre de Sesión Seguro
 * 
 * Destruye completamente la sesión y cookies asociadas
 */

// Iniciar sesión si no está activa
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Regenerar ID de sesión para prevenir fixation
session_regenerate_id(true);

// Destruir todas las variables de sesión
$_SESSION = [];

// Borrar cookies de sesión
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), 
        '', 
        time() - 42000,
        $params["path"], 
        $params["domain"],
        $params["secure"], 
        $params["httponly"]
    );
}

// Destruir la sesión
session_destroy();

// Redireccionar a login con mensaje
header('Location: ../index.php?logout=1');
exit();