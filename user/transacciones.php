<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit();
}
require "../config/database.php";
$db = Conexion::obtenerInstancia()->obtenerConexion();
$usuario_id = $_SESSION["user_id"];

// Función para formatear dinero
function formatMoney($amount, $moneda = 'PYG') {
    $symbols = [
        'PYG' => 'Gs ',
        'USD' => '$ ',
        'EUR' => '€ '
    ];
    $symbol = $symbols[$moneda] ?? 'Gs ';
    return $symbol . number_format($amount / 100, 0, ',', '.');
}

// Función para convertir entrada de dinero a centavos
function parseMoneyInput($input) {
    $clean = preg_replace('/[^\d.]/', '', $input);
    return (int)round(floatval(str_replace('.', '', $clean)) * 100);
}

// Función para validar monto con mensajes de error amigables
function validarMontoTransaccion($monto, $saldoUsuario, $moneda, $esGasto = false) {
    $montoNumerico = parseMoneyInput($monto);

    if ($montoNumerico <= 0) {
        return "El monto debe ser mayor a cero";
    }

    if ($montoNumerico > 1000000000000) {
        return "El monto es demasiado alto. Por favor, verifica el valor ingresado";
    }

    if ($esGasto && $montoNumerico > $saldoUsuario) {
        $faltante = $montoNumerico - $saldoUsuario;
        return "Saldo insuficiente. Disponible: " . formatMoney($saldoUsuario, $moneda) .
               ". Faltan: " . formatMoney($faltante, $moneda);
    }

    return null;
}

// Función para validar fecha según triggers de la base de datos
function validarFechaTransaccion($fecha) {
    $fechaTransaccion = new DateTime($fecha);
    $fechaActual = new DateTime();
    $fechaUnAnioAtras = (new DateTime())->modify('-1 year');
    $fechaSieteDiasDespues = (new DateTime())->modify('+7 days');

    if ($fechaTransaccion < $fechaUnAnioAtras) {
        return "No se pueden registrar transacciones con más de 1 año de antigüedad";
    }

    if ($fechaTransaccion > $fechaSieteDiasDespues) {
        return "No se pueden registrar transacciones con más de 7 días de anticipación";
    }

    return null;
}

// Clase para manejar transacciones
class TransactionRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function getAll($userId, $filters = [], $limit = 10, $offset = 0) {
        $query = "
            SELECT t.*,
                   cat.nombre AS categoria_nombre,
                   cat.tipo AS tipo_categoria,
                   cat.color AS categoria_color,
                   cat.icono AS categoria_icono
            FROM transacciones t
            INNER JOIN categorias cat ON t.categoria_id = cat.id
            WHERE t.usuario_id = :usuario_id
        ";
        $params = [':usuario_id' => $userId];

        if (!empty($filters['categoria_id'])) {
            $query .= " AND t.categoria_id = :categoria_id";
            $params[':categoria_id'] = $filters['categoria_id'];
        }
        if (!empty($filters['tipo'])) {
            $query .= " AND cat.tipo = :tipo";
            $params[':tipo'] = $filters['tipo'];
        }
        if (!empty($filters['fecha_desde'])) {
            $query .= " AND t.fecha >= :fecha_desde";
            $params[':fecha_desde'] = $filters['fecha_desde'];
        }
        if (!empty($filters['fecha_hasta'])) {
            $query .= " AND t.fecha <= :fecha_hasta";
            $params[':fecha_hasta'] = $filters['fecha_hasta'];
        }

        $countQuery = "SELECT COUNT(*) FROM ($query) AS filtered";
        $countStmt = $this->db->prepare($countQuery);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $total = $countStmt->fetchColumn();

        $query .= " ORDER BY t.fecha DESC, t.creado_en DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->execute();
        return [
            'data' => $stmt->fetchAll(),
            'total' => $total
        ];
    }

    public function getStats($userId, $filters = []) {
        $query = "
            SELECT
                COUNT(*) as total_transacciones,
                COALESCE(SUM(CASE WHEN cat.tipo = 'ingreso' THEN t.monto ELSE 0 END), 0) as total_ingresos,
                COALESCE(SUM(CASE WHEN cat.tipo = 'gasto' THEN t.monto ELSE 0 END), 0) as total_gastos,
                COALESCE(SUM(CASE WHEN cat.tipo = 'ingreso' THEN t.monto ELSE -t.monto END), 0) as balance
            FROM transacciones t
            INNER JOIN categorias cat ON t.categoria_id = cat.id
            WHERE t.usuario_id = :usuario_id
        ";
        $params = [':usuario_id' => $userId];

        if (!empty($filters['categoria_id'])) {
            $query .= " AND t.categoria_id = :categoria_id";
            $params[':categoria_id'] = $filters['categoria_id'];
        }
        if (!empty($filters['tipo'])) {
            $query .= " AND cat.tipo = :tipo";
            $params[':tipo'] = $filters['tipo'];
        }
        if (!empty($filters['fecha_desde'])) {
            $query .= " AND t.fecha >= :fecha_desde";
            $params[':fecha_desde'] = $filters['fecha_desde'];
        }
        if (!empty($filters['fecha_hasta'])) {
            $query .= " AND t.fecha <= :fecha_hasta";
            $params[':fecha_hasta'] = $filters['fecha_hasta'];
        }

        $stmt = $this->db->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create($data) {
        $stmt = $this->db->prepare("
            INSERT INTO transacciones
            (usuario_id, categoria_id, monto, descripcion, fecha, creado_en, actualizado_en)
            VALUES
            (:usuario_id, :categoria_id, :monto, :descripcion, :fecha, NOW(), NOW())
        ");
        $data['usuario_id'] = $_SESSION["user_id"];
        return $stmt->execute($data);
    }

    public function update($id, $data) {
        $data['id'] = $id;
        $stmt = $this->db->prepare("
            UPDATE transacciones SET
                categoria_id = :categoria_id,
                monto = :monto,
                descripcion = :descripcion,
                fecha = :fecha,
                actualizado_en = NOW()
            WHERE id = :id AND usuario_id = :usuario_id
        ");
        $data['usuario_id'] = $_SESSION["user_id"];
        return $stmt->execute($data);
    }

    public function delete($id, $userId) {
        $stmt = $this->db->prepare("
            DELETE FROM transacciones
            WHERE id = :id AND usuario_id = :usuario_id
        ");
        return $stmt->execute(['id' => $id, 'usuario_id' => $userId]);
    }

    public function getSaldoActual($userId) {
        $stmt = $this->db->prepare("SELECT saldo, moneda FROM usuarios WHERE id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function esCategoriaGasto($categoriaId) {
        $stmt = $this->db->prepare("SELECT tipo FROM categorias WHERE id = ?");
        $stmt->execute([$categoriaId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result && $result['tipo'] === 'gasto';
    }

    public function verificarPresupuesto($userId, $categoriaId, $monto, $fecha) {
        $stmt = $this->db->prepare("
            SELECT
                p.monto as presupuesto,
                COALESCE(SUM(t.monto), 0) as gasto_actual,
                (COALESCE(SUM(t.monto), 0) + :monto) as nuevo_gasto,
                ((COALESCE(SUM(t.monto), 0) + :monto) / p.monto) * 100 as porcentaje
            FROM presupuestos p
            LEFT JOIN transacciones t ON p.categoria_id = t.categoria_id
                AND t.fecha BETWEEN p.fecha_inicio AND COALESCE(p.fecha_fin, CURDATE())
                AND YEAR(t.fecha) = YEAR(:fecha)
                AND MONTH(t.fecha) = MONTH(:fecha)
            WHERE p.usuario_id = :usuario_id
            AND p.categoria_id = :categoria_id
            AND (p.fecha_fin IS NULL OR p.fecha_fin >= CURDATE())
            GROUP BY p.id, p.monto
        ");

        $stmt->execute([
            ':usuario_id' => $userId,
            ':categoria_id' => $categoriaId,
            ':monto' => $monto,
            ':fecha' => $fecha
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Configuración inicial
$transactionRepo = new TransactionRepository($db);
$error = '';
$success = '';
$warning = '';

// Obtener categorías del usuario
$stmtCategorias = $db->prepare("
    SELECT id, nombre, tipo, color, icono
    FROM categorias
    WHERE usuario_id = :usuario_id
    ORDER BY tipo, nombre
");
$stmtCategorias->bindValue(":usuario_id", $usuario_id, PDO::PARAM_INT);
$stmtCategorias->execute();
$categorias = $stmtCategorias->fetchAll();

$tipos = [
    "ingreso" => "Ingreso",
    "gasto" => "Gasto",
];

// Obtener información del usuario
$user_data = $transactionRepo->getSaldoActual($usuario_id);
$saldoActual = $user_data['saldo'];
$user_currency = $user_data['moneda'];

// Procesar operaciones CRUD
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $data = [
        'categoria_id' => $_POST["categoria_id"] ?? null,
        'monto' => parseMoneyInput($_POST["monto"] ?? "0"),
        'descripcion' => trim($_POST["descripcion"] ?? ""),
        'fecha' => $_POST["fecha"] ?? date("Y-m-d")
    ];

    try {
        $esGasto = $transactionRepo->esCategoriaGasto($data['categoria_id']);

        $montoError = validarMontoTransaccion($_POST["monto"] ?? "0", $saldoActual, $user_currency, $esGasto);
        if ($montoError) {
            throw new Exception($montoError);
        }

        $fechaError = validarFechaTransaccion($data['fecha']);
        if ($fechaError) {
            throw new Exception($fechaError);
        }

        if (isset($_POST["create"]) && $data['categoria_id']) {
            if ($esGasto) {
                $infoPresupuesto = $transactionRepo->verificarPresupuesto($usuario_id, $data['categoria_id'], $data['monto'], $data['fecha']);

                if ($infoPresupuesto && $infoPresupuesto['nuevo_gasto'] > $infoPresupuesto['presupuesto']) {
                    throw new Exception("Esta transacción excede el presupuesto mensual asignado para esta categoría. " .
                                      "Presupuesto: " . formatMoney($infoPresupuesto['presupuesto'], $user_currency) .
                                      ", Gastado: " . formatMoney($infoPresupuesto['gasto_actual'], $user_currency));
                }

                if ($infoPresupuesto && $infoPresupuesto['porcentaje'] > 80) {
                    $_SESSION['warning'] = "⚠️ Advertencia: Esta transacción hará que uses el " .
                                          round($infoPresupuesto['porcentaje']) .
                                          "% de tu presupuesto mensual para esta categoría";
                }
            }

            $transactionRepo->create($data);
            $_SESSION['success'] = '✅ Transacción creada exitosamente';
            header("Location: " . $_SERVER["PHP_SELF"], true, 303);
            exit();
        }

        if (isset($_POST["update"]) && isset($_POST["id"])) {
            $data['id'] = $_POST["id"];
            $transactionRepo->update($data['id'], $data);
            $_SESSION['success'] = '✅ Transacción actualizada exitosamente';
            header("Location: " . $_SERVER["PHP_SELF"], true, 303);
            exit();
        }
    } catch (PDOException $e) {
        $errorMessage = $e->getMessage();

        if (strpos($errorMessage, 'Saldo insuficiente') !== false) {
            $error = '❌ Saldo insuficiente para realizar esta operación.';
        } else if (strpos($errorMessage, 'presupuesto mensual') !== false) {
            $error = '❌ Esta transacción excede el presupuesto mensual asignado para esta categoría.';
        } else if (strpos($errorMessage, 'Transacción duplicada') !== false) {
            $error = '❌ Transacción duplicada detectada.';
        } else if (strpos($errorMessage, 'fecha de la transacción') !== false) {
            $error = '❌ La fecha de la transacción no puede ser futura.';
        } else if (strpos($errorMessage, 'más de 1 año') !== false) {
            $error = '❌ No se pueden registrar transacciones con más de 1 año de antigüedad.';
        } else if (strpos($errorMessage, 'más de 7 días') !== false) {
            $error = '❌ No se pueden registrar transacciones con más de 7 días de anticipación.';
        } else if (strpos($errorMessage, 'debe ser mayor a 0') !== false) {
            $error = '❌ El monto debe ser mayor a cero.';
        } else if (strpos($errorMessage, 'Gasto demasiado elevado') !== false) {
            $error = '❌ El gasto es demasiado elevado en relación a su saldo actual.';
        } else {
            $error = '❌ Error del sistema: ' . $errorMessage;
        }
    } catch (Exception $e) {
        $error = '❌ ' . $e->getMessage();
    }
}

// Eliminar transacción
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["delete"])) {
    $id = intval($_GET["delete"]);
    try {
        $transactionRepo->delete($id, $usuario_id);
        $_SESSION['success'] = '✅ Transacción eliminada exitosamente';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'No se puede eliminar') !== false) {
            $_SESSION['error'] = '❌ No se puede eliminar la transacción.';
        } else {
            $_SESSION['error'] = '❌ Error al eliminar la transacción: ' . $e->getMessage();
        }
    }
    header("Location: " . $_SERVER["PHP_SELF"], true, 303);
    exit();
}

// Mostrar mensajes de éxito/error
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}
if (isset($_SESSION['warning'])) {
    $warning = $_SESSION['warning'];
    unset($_SESSION['warning']);
}

// Obtener filtros de la URL
$filters = [
    'categoria_id' => $_GET['categoria_id'] ?? '',
    'tipo' => $_GET['tipo'] ?? '',
    'fecha_desde' => $_GET['fecha_desde'] ?? '',
    'fecha_hasta' => $_GET['fecha_hasta'] ?? '',
];

// Configuración de paginación
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Obtener transacciones con filtros y paginación
$result = $transactionRepo->getAll($usuario_id, $filters, $perPage, $offset);
$transacciones = $result['data'];
$totalTransacciones = $result['total'];
$totalPages = ceil($totalTransacciones / $perPage);

// Obtener estadísticas
$stats = $transactionRepo->getStats($usuario_id, $filters);

// Función para exportar datos a CSV
function exportToCSV($data, $filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');

    $output = fopen('php://output', 'w');

    if (!empty($data)) {
        fputcsv($output, array_keys($data[0]));
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
    }

    fclose($output);
    exit();
}

// Manejar exportación de transacciones
if (isset($_GET['export']) && $_GET['export'] === 'transacciones') {
    $stmt = $db->prepare("
        SELECT
            t.fecha,
            t.descripcion,
            cat.nombre as categoria,
            cat.tipo,
            t.monto
        FROM transacciones t
        INNER JOIN categorias cat ON t.categoria_id = cat.id
        WHERE t.usuario_id = ?
        ORDER BY t.fecha DESC
    ");
    $stmt->execute([$usuario_id]);
    $exportData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($exportData as &$row) {
        $row['monto'] = formatMoney($row['monto'], $user_currency);
    }

    exportToCSV($exportData, 'transacciones_' . date('Y-m-d'));
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finzen | Mis Transacciones</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        :root {
            --bs-primary: #0d6efd;
            --bs-success: #198754;
            --bs-danger: #dc3545;
            --bs-warning: #ffc107;
            --bs-info: #0dcaf0;
            --bs-light: #f8f9fa;
            --bs-dark: #212529;
            --bs-border: #e9ecef;
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background-color: #fafbfc;
            color: #333;
            line-height: 1.5;
        }

        .navbar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--bs-border);
            padding: 0.75rem 0;
        }

        .navbar-brand {
            font-weight: 700;
            font-size: 1.5rem;
            color: var(--bs-primary) !important;
        }

        .nav-link {
            font-weight: 500;
            padding: 0.5rem 1rem !important;
            border-radius: 0.5rem;
            transition: all 0.2s ease;
            color: #666 !important;
        }

        .nav-link:hover, .nav-link.active {
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            color: var(--bs-primary) !important;
        }

        .card {
            border: none;
            border-radius: 1rem;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            transition: all 0.3s ease;
            background: white;
            overflow: hidden;
        }

        .card:hover {
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            transform: translateY(-2px);
        }

        .stats-card {
            border-left: 4px solid;
            position: relative;
        }

        .stats-card.primary {
            border-left-color: var(--bs-primary);
        }

        .stats-card.success {
            border-left-color: var(--bs-success);
        }

        .stats-card.danger {
            border-left-color: var(--bs-danger);
        }

        .stats-card.warning {
            border-left-color: var(--bs-warning);
        }

        .metric-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-size: 1.25rem;
        }

        .badge-custom {
            font-size: 0.75rem;
            padding: 0.375rem 0.75rem;
            border-radius: 0.75rem;
            font-weight: 500;
        }

        .btn {
            border-radius: 0.75rem;
            font-weight: 500;
            padding: 0.5rem 1.25rem;
            transition: all 0.2s ease;
        }

        .btn-sm {
            padding: 0.375rem 0.875rem;
            font-size: 0.875rem;
        }

        .table {
            border-radius: 1rem;
            overflow: hidden;
        }

        .table th {
            border: none;
            background-color: var(--bs-light);
            font-weight: 600;
            color: #495057;
            padding: 1rem;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table td {
            border: none;
            padding: 1rem;
            vertical-align: middle;
        }

        .table tbody tr {
            border-bottom: 1px solid var(--bs-border);
            transition: all 0.2s ease;
        }

        .table tbody tr:hover {
            background-color: rgba(0, 0, 0, 0.02);
            transform: translateX(4px);
        }

        .table tbody tr:last-child {
            border-bottom: none;
        }

        .category-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            color: white;
            font-size: 1rem;
        }

        .amount.ingreso {
            color: var(--bs-success);
            font-weight: 600;
        }

        .amount.gasto {
            color: var(--bs-danger);
            font-weight: 600;
        }

        h1, h2, h3, h4, h5, h6 {
            font-weight: 700;
            color: var(--bs-dark);
        }

        .card-title {
            font-weight: 600;
            color: var(--bs-dark);
            margin-bottom: 1.5rem;
        }

        .container {
            max-width: 1400px;
        }

        .modal-content {
            border: none;
            border-radius: 1rem;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }

        .modal-header {
            border-bottom: 1px solid var(--bs-border);
            padding: 1.5rem;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            border-top: 1px solid var(--bs-border);
            padding: 1.5rem;
        }

        .form-control, .form-select {
            border-radius: 0.75rem;
            border: 1px solid var(--bs-border);
            padding: 0.75rem 1rem;
            transition: all 0.2s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--bs-primary);
            box-shadow: 0 0 0 0.2rem rgba(var(--bs-primary-rgb), 0.1);
        }

        .alert {
            border: none;
            border-radius: 0.75rem;
            padding: 1rem 1.25rem;
        }

        .pagination .page-link {
            border: none;
            border-radius: 0.5rem;
            margin: 0 0.25rem;
            color: #666;
            font-weight: 500;
        }

        .pagination .page-item.active .page-link {
            background-color: var(--bs-primary);
            color: white;
        }

        .pagination .page-link:hover {
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            color: var(--bs-primary);
        }

        .empty-state {
            padding: 3rem 1rem;
            text-align: center;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .transaction-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }

        .btn-action-individual {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            transition: all 0.2s ease;
        }

        .saldo-info {
            background: var(--bs-primary);
            color: white;
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .saldo-info h3 {
            color: white;
            margin-bottom: 0.5rem;
        }

        .saldo-info .badge {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            font-size: 0.875rem;
        }

        .money-input {
            text-align: right;
            font-family: 'Courier New', monospace;
            font-weight: 600;
        }

        .money-input::placeholder {
            font-weight: normal;
        }

        .presupuesto-alert {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            border: 1px solid #ffecb5;
            border-radius: 0.5rem;
            padding: 0.75rem;
            margin-top: 0.5rem;
            font-size: 0.875rem;
        }

        .validation-message {
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }

        .validation-success {
            color: var(--bs-success);
        }

        .validation-error {
            color: var(--bs-danger);
        }

        @media (max-width: 768px) {
            .container {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .table-responsive {
                font-size: 0.875rem;
            }

            .category-icon {
                width: 32px;
                height: 32px;
                margin-right: 8px;
            }

            .transaction-actions {
                flex-direction: column;
            }

            .btn-action-individual {
                width: 36px;
                height: 36px;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light sticky-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <i class="bi bi-wallet2 me-2"></i> Finzen
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">
                            <i class="bi bi-speedometer2 me-2"></i> Resumen
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="categorias.php">
                            <i class="bi bi-tags me-2"></i> Categorías
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="presupuestos.php">
                            <i class="bi bi-pie-chart me-2"></i> Presupuestos
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="transacciones.php">
                            <i class="bi bi-arrow-left-right me-2"></i> Transacciones
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reportes.php" class="nav-link">
                            <i class="bi bi-graph-up me-2"></i>Reportes
                        </a>
                    </li>
                </ul>
                <div class="d-flex align-items-center gap-2">
                    <span class="text-muted me-3"><?= htmlspecialchars($_SESSION['username']) ?></span>
                    <a href="?export=transacciones" class="btn btn-outline-success btn-sm">
                        <i class="bi bi-download me-1"></i> Exportar CSV
                    </a>
                    <a href="../auth/logout.php" class="btn btn-outline-danger btn-sm">
                        <i class="bi bi-box-arrow-right me-1"></i> Salir
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="mb-1">Mis Transacciones</h1>
                <p class="text-muted mb-0">Registro de ingresos y gastos</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTransactionModal">
                <i class="bi bi-plus-circle me-1"></i> Nueva Transacción
            </button>
        </div>

        <!-- Información de saldo -->
        <div class="saldo-info">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h3 class="mb-1"><?= formatMoney($saldoActual, $user_currency) ?></h3>
                    <p class="mb-0">Saldo disponible actual</p>
                </div>
                <div class="col-md-4 text-end">
                    <span class="badge">
                        <i class="bi bi-wallet2 me-1"></i> Estado de cuenta
                    </span>
                </div>
            </div>
        </div>

        <!-- Alertas -->
        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle me-2"></i>
                <?= htmlspecialchars($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($warning)): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <?= htmlspecialchars($warning) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="row g-3 mb-4 quick-actions">
            <div class="col-md-3">
                <a href="transacciones.php?action=new" class="btn btn-primary w-100 d-flex flex-column align-items-center">
                    <i class="bi bi-plus-circle fs-2 mb-2"></i>
                    <span>Nueva Transacción</span>
                </a>
            </div>
            <div class="col-md-3">
                <a href="presupuestos.php" class="btn btn-success w-100 d-flex flex-column align-items-center">
                    <i class="bi bi-pie-chart fs-2 mb-2"></i>
                    <span>Gestionar Presupuestos</span>
                </a>
            </div>
            <div class="col-md-3">
                <a href="reportes.php" class="btn btn-info w-100 d-flex flex-column align-items-center">
                    <i class="bi bi-graph-up fs-2 mb-2"></i>
                    <span>Ver Reportes</span>
                </a>
            </div>
            <div class="col-md-3">
                <button class="btn btn-warning w-100 d-flex flex-column align-items-center" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                    <i class="bi bi-tag fs-2 mb-2"></i>
                    <span>Agregar Categoría</span>
                </button>
            </div>
        </div>

        <!-- Estadísticas -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card stats-card primary">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title text-muted mb-1">Total Transacciones</h6>
                                <h3 class="text-primary mb-0"><?= $stats['total_transacciones'] ?></h3>
                            </div>
                            <div class="metric-icon bg-primary bg-opacity-10 text-primary">
                                <i class="bi bi-list-check"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card success">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title text-muted mb-1">Total Ingresos</h6>
                                <h3 class="text-success mb-0"><?= formatMoney($stats['total_ingresos'], $user_currency) ?></h3>
                            </div>
                            <div class="metric-icon bg-success bg-opacity-10 text-success">
                                <i class="bi bi-arrow-down-circle"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card danger">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title text-muted mb-1">Total Gastos</h6>
                                <h3 class="text-danger mb-0"><?= formatMoney($stats['total_gastos'], $user_currency) ?></h3>
                            </div>
                            <div class="metric-icon bg-danger bg-opacity-10 text-danger">
                                <i class="bi bi-arrow-up-circle"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card warning">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title text-muted mb-1">Balance</h6>
                                <h3 class="text-warning mb-0"><?= formatMoney($stats['balance'], $user_currency) ?></h3>
                            </div>
                            <div class="metric-icon bg-warning bg-opacity-10 text-warning">
                                <i class="bi bi-graph-up"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" action="" class="row g-3 align-items-end">
                    <div class="col-md-2">
                        <label for="categoria_id" class="form-label">Categoría</label>
                        <select class="form-select" id="categoria_id" name="categoria_id">
                            <option value="">Todas las categorías</option>
                            <?php foreach ($categorias as $categoria): ?>
                                <option value="<?= $categoria["id"] ?>" <?= ($filters['categoria_id'] == $categoria["id"]) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($categoria["nombre"]) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="tipo" class="form-label">Tipo</label>
                        <select class="form-select" id="tipo" name="tipo">
                            <option value="">Todos</option>
                            <?php foreach ($tipos as $codigo => $nombre): ?>
                                <option value="<?= $codigo ?>" <?= ($filters['tipo'] === $codigo) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($nombre) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="fecha_desde" class="form-label">Desde</label>
                        <input type="date" class="form-control" id="fecha_desde" name="fecha_desde" value="<?= $filters['fecha_desde'] ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="fecha_hasta" class="form-label">Hasta</label>
                        <input type="date" class="form-control" id="fecha_hasta" name="fecha_hasta" value="<?= $filters['fecha_hasta'] ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-funnel me-1"></i> Filtrar
                        </button>
                    </div>
                    <div class="col-12 text-end">
                        <span class="badge bg-primary badge-custom">
                            <?= $totalTransacciones ?> transacción<?= $totalTransacciones !== 1 ? 'es' : '' ?>
                        </span>
                    </div>
                </form>
            </div>
        </div>

        <!-- Lista de transacciones -->
        <div class="card">
            <div class="card-body">
                <?php if (empty($transacciones)): ?>
                    <div class="empty-state">
                        <i class="bi bi-arrow-left-right"></i>
                        <h3 class="mb-2">No se encontraron transacciones</h3>
                        <p class="text-muted mb-4">No hay transacciones que coincidan con los filtros seleccionados</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTransactionModal">
                             Agregar Transacción
                        </button>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Fecha</th>
                                    <th>Categoría</th>
                                    <th>Descripción</th>
                                    <th class="text-end">Monto</th>
                                    <th class="text-end">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transacciones as $transaccion):
                                    $tipoClase = $transaccion["tipo_categoria"] === "ingreso" ? "ingreso" : "gasto";
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= date("d/m/Y", strtotime($transaccion["fecha"])) ?></strong>
                                        <br>
                                        <small class="text-muted"><?= date("H:i", strtotime($transaccion["creado_en"])) ?></small>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <?php if (!empty($transaccion['categoria_color'])): ?>
                                                <span class="category-icon" style="background-color: <?= htmlspecialchars($transaccion['categoria_color']) ?>">
                                                    <i class="bi <?= htmlspecialchars($transaccion['categoria_icono'] ?? 'bi-tag') ?>"></i>
                                                </span>
                                            <?php endif; ?>
                                            <div>
                                                <span class="badge <?= $transaccion["tipo_categoria"] === 'ingreso' ? 'bg-success' : 'bg-danger' ?> badge-custom">
                                                    <?= htmlspecialchars($tipos[$transaccion["tipo_categoria"]]) ?>
                                                </span>
                                                <br>
                                                <small><?= htmlspecialchars($transaccion["categoria_nombre"]) ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($transaccion["descripcion"] ?: 'Sin descripción') ?>
                                    </td>
                                    <td class="text-end">
                                        <span class="amount <?= $tipoClase ?>">
                                            <?= $transaccion["tipo_categoria"] === "ingreso" ? '+' : '-' ?>
                                            <?= formatMoney($transaccion["monto"], $user_currency) ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <div class="transaction-actions">
                                            <button class="btn btn-outline-primary btn-action-individual edit-btn"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#editTransactionModal"
                                                    data-id="<?= $transaccion["id"] ?>"
                                                    data-categoria_id="<?= $transaccion["categoria_id"] ?>"
                                                    data-monto="<?= $transaccion["monto"] / 100 ?>"
                                                    data-descripcion="<?= htmlspecialchars($transaccion["descripcion"]) ?>"
                                                    data-fecha="<?= $transaccion["fecha"] ?>"
                                                    title="Editar transacción">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <a class="btn btn-outline-danger btn-action-individual"
                                               href="?delete=<?= $transaccion["id"] ?>&page=<?= $page ?>&categoria_id=<?= $filters['categoria_id'] ?>&tipo=<?= $filters['tipo'] ?>&fecha_desde=<?= $filters['fecha_desde'] ?>&fecha_hasta=<?= $filters['fecha_hasta'] ?>"
                                               onclick="return confirm('¿Estás seguro de eliminar esta transacción?\n\nEsta acción no se puede deshacer.')"
                                               title="Eliminar transacción">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Paginación -->
                    <?php if ($totalPages > 1): ?>
                    <nav class="mt-4">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $page - 1 ?>&categoria_id=<?= $filters['categoria_id'] ?>&tipo=<?= $filters['tipo'] ?>&fecha_desde=<?= $filters['fecha_desde'] ?>&fecha_hasta=<?= $filters['fecha_hasta'] ?>" aria-label="Anterior">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>&categoria_id=<?= $filters['categoria_id'] ?>&tipo=<?= $filters['tipo'] ?>&fecha_desde=<?= $filters['fecha_desde'] ?>&fecha_hasta=<?= $filters['fecha_hasta'] ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $page + 1 ?>&categoria_id=<?= $filters['categoria_id'] ?>&tipo=<?= $filters['tipo'] ?>&fecha_desde=<?= $filters['fecha_desde'] ?>&fecha_hasta=<?= $filters['fecha_hasta'] ?>" aria-label="Siguiente">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal para agregar nueva transacción -->
    <div class="modal fade" id="addTransactionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-plus-circle me-2"></i> Agregar Nueva Transacción
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="" id="addTransactionForm">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Saldo disponible:</strong> <?= formatMoney($saldoActual, $user_currency) ?>
                        </div>

                        <div class="mb-3">
                            <label for="add_categoria_id" class="form-label">Categoría</label>
                            <select class="form-select" id="add_categoria_id" name="categoria_id" required>
                                <option value="">Seleccionar Categoría</option>
                                <?php foreach ($categorias as $categoria): ?>
                                    <option value="<?= $categoria["id"] ?>" data-tipo="<?= $categoria["tipo"] ?>">
                                        <?= htmlspecialchars($categoria["nombre"]) ?> (<?= $tipos[$categoria["tipo"]] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="add_monto" class="form-label">Monto (<?= $user_currency ?>)</label>
                            <input type="text" class="form-control money-input" id="add_monto" name="monto" placeholder="Ej: 1.000.000" required>
                            <small class="text-muted">Ingrese el monto. Los puntos se agregarán automáticamente.</small>
                            <div id="montoValidation" class="validation-message"></div>
                            <div id="presupuestoAlert" class="presupuesto-alert" style="display: none;"></div>
                        </div>

                        <div class="mb-3">
                            <label for="add_descripcion" class="form-label">Descripción</label>
                            <textarea class="form-control" id="add_descripcion" name="descripcion" rows="2" placeholder="Descripción de la transacción" maxlength="255"></textarea>
                            <small class="text-muted">Máximo 255 caracteres</small>
                        </div>

                        <div class="mb-3">
                            <label for="add_fecha" class="form-label">Fecha</label>
                            <input class="form-control" type="date" id="add_fecha" name="fecha" required value="<?= date("Y-m-d") ?>">
                            <small class="text-muted">No se permiten transacciones con más de 1 año de antigüedad o 7 días de anticipación</small>
                            <div id="fechaValidation" class="validation-message"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" name="create" id="submitTransaction">
                            <i class="bi bi-save me-1"></i> Guardar Transacción
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal para editar transacción -->
    <div class="modal fade" id="editTransactionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil me-2"></i> Editar Transacción
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="" id="editTransactionForm">
                    <input type="hidden" id="edit_id" name="id">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Saldo disponible:</strong> <?= formatMoney($saldoActual, $user_currency) ?>
                        </div>

                        <div class="mb-3">
                            <label for="edit_categoria_id" class="form-label">Categoría</label>
                            <select class="form-select" id="edit_categoria_id" name="categoria_id" required>
                                <option value="">Seleccionar Categoría</option>
                                <?php foreach ($categorias as $categoria): ?>
                                    <option value="<?= $categoria["id"] ?>" data-tipo="<?= $categoria["tipo"] ?>">
                                        <?= htmlspecialchars($categoria["nombre"]) ?> (<?= $tipos[$categoria["tipo"]] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="edit_monto" class="form-label">Monto (<?= $user_currency ?>)</label>
                            <input type="text" class="form-control money-input" id="edit_monto" name="monto" required>
                            <small class="text-muted">Ingrese el monto. Los puntos se agregarán automáticamente.</small>
                            <div id="editMontoValidation" class="validation-message"></div>
                        </div>

                        <div class="mb-3">
                            <label for="edit_descripcion" class="form-label">Descripción</label>
                            <textarea class="form-control" id="edit_descripcion" name="descripcion" rows="2" maxlength="255"></textarea>
                            <small class="text-muted">Máximo 255 caracteres</small>
                        </div>

                        <div class="mb-3">
                            <label for="edit_fecha" class="form-label">Fecha</label>
                            <input class="form-control" type="date" id="edit_fecha" name="fecha" required>
                            <div id="editFechaValidation" class="validation-message"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" name="update" id="editSubmitBtn">
                            <i class="bi bi-save me-1"></i> Actualizar Transacción
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Función para formatear número con separadores de miles mientras se escribe
            function formatNumberWithDots(input) {
                const cursorPosition = input.selectionStart;
                let value = input.value.replace(/\./g, '');
                value = value.replace(/[^\d]/g, '');

                let formattedValue = '';
                for (let i = value.length - 1, j = 0; i >= 0; i--, j++) {
                    if (j > 0 && j % 3 === 0) {
                        formattedValue = '.' + formattedValue;
                    }
                    formattedValue = value[i] + formattedValue;
                }

                input.value = formattedValue;
                const dotsAdded = (formattedValue.match(/\./g) || []).length;
                const newCursorPosition = cursorPosition + dotsAdded;
                input.setSelectionRange(newCursorPosition, newCursorPosition);
            }

            // Función para validar monto vs saldo y presupuesto
            function validateTransaction(categoriaSelect, montoInput, fechaInput, validationDiv, submitBtn) {
                const categoriaOption = categoriaSelect.options[categoriaSelect.selectedIndex];
                const esGasto = categoriaOption && categoriaOption.dataset.tipo === 'gasto';
                const montoValue = parseFloat(montoInput.value.replace(/\./g, '') || 0);
                const userBalance = <?= $saldoActual ?>;

                montoInput.classList.remove('is-invalid', 'is-valid');
                validationDiv.innerHTML = '';
                if (submitBtn) submitBtn.disabled = false;

                if (montoValue > 0) {
                    if (montoValue < 1) {
                        validationDiv.innerHTML = `<div class="validation-error">
                            <i class="bi bi-exclamation-triangle"></i>
                            El monto debe ser mayor a cero
                        </div>`;
                        montoInput.classList.add('is-invalid');
                        if (submitBtn) submitBtn.disabled = true;
                        return false;
                    }

                    if (montoValue > 1000000000000) {
                        validationDiv.innerHTML = `<div class="validation-error">
                            <i class="bi bi-exclamation-triangle"></i>
                            El monto es demasiado alto. Por favor, verifica el valor ingresado
                        </div>`;
                        montoInput.classList.add('is-invalid');
                        if (submitBtn) submitBtn.disabled = true;
                        return false;
                    }

                    if (esGasto && montoValue > userBalance) {
                        const faltante = montoValue - userBalance;
                        validationDiv.innerHTML = `<div class="validation-error">
                            <i class="bi bi-exclamation-triangle"></i>
                            Saldo insuficiente. Disponible: ${(userBalance/100).toLocaleString('es-PY')} <?= $user_currency ?>.
                            Faltan: ${(faltante/100).toLocaleString('es-PY')} <?= $user_currency ?>
                        </div>`;
                        montoInput.classList.add('is-invalid');
                        if (submitBtn) submitBtn.disabled = true;
                        return false;
                    }

                    if (fechaInput && fechaInput.value) {
                        const fechaTransaccion = new Date(fechaInput.value);
                        const fechaActual = new Date();
                        const fechaUnAnioAtras = new Date();
                        fechaUnAnioAtras.setFullYear(fechaActual.getFullYear() - 1);
                        const fechaSieteDiasDespues = new Date();
                        fechaSieteDiasDespues.setDate(fechaActual.getDate() + 7);

                        if (fechaTransaccion < fechaUnAnioAtras) {
                            validationDiv.innerHTML = `<div class="validation-error">
                                <i class="bi bi-exclamation-triangle"></i>
                                No se pueden registrar transacciones con más de 1 año de antigüedad
                            </div>`;
                            if (submitBtn) submitBtn.disabled = true;
                            return false;
                        }

                        if (fechaTransaccion > fechaSieteDiasDespues) {
                            validationDiv.innerHTML = `<div class="validation-error">
                                <i class="bi bi-exclamation-triangle"></i>
                                No se pueden registrar transacciones con más de 7 días de anticipación
                            </div>`;
                            if (submitBtn) submitBtn.disabled = true;
                            return false;
                        }
                    }

                    if (esGasto && montoValue <= userBalance) {
                        validationDiv.innerHTML = `<div class="validation-success">
                            <i class="bi bi-check-circle"></i>
                            Saldo suficiente para esta transacción
                        </div>`;
                        montoInput.classList.add('is-valid');
                    } else if (!esGasto) {
                        validationDiv.innerHTML = `<div class="validation-success">
                            <i class="bi bi-check-circle"></i>
                            Monto válido para ingreso
                        </div>`;
                        montoInput.classList.add('is-valid');
                    }

                    return true;
                }

                return false;
            }

            // Aplicar formato automático a los inputs de dinero mientras se escribe
            document.querySelectorAll('.money-input').forEach(input => {
                input.addEventListener('input', function(e) {
                    formatNumberWithDots(this);

                    if (this.id === 'add_monto') {
                        validateTransaction(
                            document.getElementById('add_categoria_id'),
                            this,
                            document.getElementById('add_fecha'),
                            document.getElementById('montoValidation'),
                            document.getElementById('submitTransaction')
                        );
                    } else if (this.id === 'edit_monto') {
                        validateTransaction(
                            document.getElementById('edit_categoria_id'),
                            this,
                            document.getElementById('edit_fecha'),
                            document.getElementById('editMontoValidation'),
                            document.getElementById('editSubmitBtn')
                        );
                    }
                });

                input.addEventListener('blur', function() {
                    if (this.id === 'add_monto') {
                        validateTransaction(
                            document.getElementById('add_categoria_id'),
                            this,
                            document.getElementById('add_fecha'),
                            document.getElementById('montoValidation'),
                            document.getElementById('submitTransaction')
                        );
                    } else if (this.id === 'edit_monto') {
                        validateTransaction(
                            document.getElementById('edit_categoria_id'),
                            this,
                            document.getElementById('edit_fecha'),
                            document.getElementById('editMontoValidation'),
                            document.getElementById('editSubmitBtn')
                        );
                    }
                });

                if (input.value) {
                    formatNumberWithDots(input);
                }
            });

            // Manejar edición de transacciones
            document.querySelectorAll('.edit-btn').forEach(button => {
                button.addEventListener('click', function() {
                    document.getElementById('edit_id').value = this.dataset.id;
                    document.getElementById('edit_categoria_id').value = this.dataset.categoria_id;

                    const monto = parseFloat(this.dataset.monto);
                    const montoInput = document.getElementById('edit_monto');
                    montoInput.value = monto.toLocaleString('es-PY');

                    document.getElementById('edit_descripcion').value = this.dataset.descripcion;
                    document.getElementById('edit_fecha').value = this.dataset.fecha;

                    setTimeout(() => {
                        validateTransaction(
                            document.getElementById('edit_categoria_id'),
                            montoInput,
                            document.getElementById('edit_fecha'),
                            document.getElementById('editMontoValidation'),
                            document.getElementById('editSubmitBtn')
                        );
                    }, 100);
                });
            });

            // Validar cambios en categoría y fecha
            document.getElementById('add_categoria_id')?.addEventListener('change', function() {
                validateTransaction(
                    this,
                    document.getElementById('add_monto'),
                    document.getElementById('add_fecha'),
                    document.getElementById('montoValidation'),
                    document.getElementById('submitTransaction')
                );
            });

            document.getElementById('add_fecha')?.addEventListener('change', function() {
                validateTransaction(
                    document.getElementById('add_categoria_id'),
                    document.getElementById('add_monto'),
                    this,
                    document.getElementById('montoValidation'),
                    document.getElementById('submitTransaction')
                );

                const fechaValidation = document.getElementById('fechaValidation');
                if (this.value) {
                    const fechaTransaccion = new Date(this.value);
                    const fechaActual = new Date();
                    const fechaUnAnioAtras = new Date();
                    fechaUnAnioAtras.setFullYear(fechaActual.getFullYear() - 1);
                    const fechaSieteDiasDespues = new Date();
                    fechaSieteDiasDespues.setDate(fechaActual.getDate() + 7);

                    if (fechaTransaccion < fechaUnAnioAtras) {
                        fechaValidation.innerHTML = `<div class="validation-error">
                            <i class="bi bi-exclamation-triangle"></i>
                            No se pueden registrar transacciones con más de 1 año de antigüedad
                        </div>`;
                    } else if (fechaTransaccion > fechaSieteDiasDespues) {
                        fechaValidation.innerHTML = `<div class="validation-error">
                            <i class="bi bi-exclamation-triangle"></i>
                            No se pueden registrar transacciones con más de 7 días de anticipación
                        </div>`;
                    } else {
                        fechaValidation.innerHTML = `<div class="validation-success">
                            <i class="bi bi-check-circle"></i>
                            Fecha válida
                        </div>`;
                    }
                }
            });

            // Validar formulario antes de enviar
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    const montoInput = this.querySelector('input[name="monto"]');
                    if (montoInput) {
                        montoInput.value = montoInput.value.replace(/\./g, '');
                    }

                    if (this.id === 'addTransactionForm') {
                        const isValid = validateTransaction(
                            document.getElementById('add_categoria_id'),
                            document.getElementById('add_monto'),
                            document.getElementById('add_fecha'),
                            document.getElementById('montoValidation'),
                            document.getElementById('submitTransaction')
                        );

                        if (!isValid) {
                            e.preventDefault();
                            alert('Por favor, corrige los errores antes de enviar el formulario.');
                        }
                    }
                });
            });

            // Configurar fecha máxima y mínima según triggers
            const fechaInput = document.getElementById('add_fecha');
            const editFechaInput = document.getElementById('edit_fecha');

            if (fechaInput) {
                const hoy = new Date();
                const maxFecha = new Date();
                maxFecha.setDate(hoy.getDate() + 7);
                const minFecha = new Date();
                minFecha.setFullYear(hoy.getFullYear() - 1);

                fechaInput.max = maxFecha.toISOString().split('T')[0];
                fechaInput.min = minFecha.toISOString().split('T')[0];
            }

            if (editFechaInput) {
                const hoy = new Date();
                const maxFecha = new Date();
                maxFecha.setDate(hoy.getDate() + 7);
                const minFecha = new Date();
                minFecha.setFullYear(hoy.getFullYear() - 1);

                editFechaInput.max = maxFecha.toISOString().split('T')[0];
                editFechaInput.min = minFecha.toISOString().split('T')[0];
            }
        });
    </script>
</body>
</html>
