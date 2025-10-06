<?php
/**
 * database.php
 * Clase Singleton para manejar la conexión a la base de datos
 */

class Conexion {
    private static $instancia = null;
    private $conexion;

    private function __construct() {
        try {
            $this->conexion = new PDO(
                'mysql:host=localhost;dbname=finzen;charset=utf8',
                'root',  // Reemplaza con tu usuario de MySQL
                ''       // Reemplaza con tu contraseña de MySQL
            );
            $this->conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conexion->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Error de conexión: ' . $e->getMessage());
            throw $e;
        }
    }

    public static function obtenerInstancia() {
        if (!self::$instancia) {
            self::$instancia = new Conexion();
        }
        return self::$instancia;
    }

    public function obtenerConexion() {
        return $this->conexion;
    }
}

/**
 * 🔹 Cómo usar en otros archivos:
 * 
 * require_once __DIR__ . '/database.php';
 * $db = Conexion::obtenerInstancia()->obtenerConexion();
 * 
 * Ejemplo:
 * $stmt = $db->prepare("SELECT * FROM usuarios");
 * $stmt->execute();
 * $usuarios = $stmt->fetchAll();
 */
