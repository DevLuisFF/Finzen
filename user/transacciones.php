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
function formatMoney($amount) {
    return 'Gs ' . number_format($amount / 100, 0, ',', '.');
}

// Función para convertir entrada de dinero a centavos
function parseMoneyInput($input) {
    // Remover caracteres no numéricos excepto puntos
    $clean = preg_replace('/[^\d.]/', '', $input);
    // Convertir a float y luego a centavos
    return (int)round(floatval(str_replace('.', '', $clean)) * 100);
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
        // Aplicar filtros
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
        // Contar total para paginación
        $countQuery = "SELECT COUNT(*) FROM ($query) AS filtered";
        $countStmt = $this->db->prepare($countQuery);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $total = $countStmt->fetchColumn();
        // Aplicar orden y paginación
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
        // Aplicar filtros
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
    
    // Función para obtener el saldo actual del usuario
    public function getSaldoActual($userId) {
        $stmt = $this->db->prepare("SELECT saldo FROM usuarios WHERE id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['saldo'] : 0;
    }
    
    // Función para verificar si una categoría es de gasto
    public function esCategoriaGasto($categoriaId) {
        $stmt = $this->db->prepare("SELECT tipo FROM categorias WHERE id = ?");
        $stmt->execute([$categoriaId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result && $result['tipo'] === 'gasto';
    }
}

// Configuración inicial
$transactionRepo = new TransactionRepository($db);
$error = '';
$success = '';

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

// Tipos de transacciones según esquema
$tipos = [
    "ingreso" => "Ingreso",
    "gasto" => "Gasto",
];

// Procesar operaciones CRUD
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $data = [
        'categoria_id' => $_POST["categoria_id"] ?? null,
        'monto' => parseMoneyInput($_POST["monto"] ?? "0"),
        'descripcion' => trim($_POST["descripcion"] ?? ""),
        'fecha' => $_POST["fecha"] ?? date("Y-m-d")
    ];
    
    try {
        if (isset($_POST["create"]) && $data['categoria_id']) {
            // Verificar saldo antes de intentar la transacción
            $saldoActual = $transactionRepo->getSaldoActual($usuario_id);
            $esGasto = $transactionRepo->esCategoriaGasto($data['categoria_id']);
            
            if ($esGasto && $data['monto'] > $saldoActual) {
                $error = 'Saldo insuficiente para realizar este gasto. ';
                $error .= 'Saldo disponible: ' . formatMoney($saldoActual) . '. ';
                $error .= 'Monto del gasto: ' . formatMoney($data['monto']) . '. ';
                $error .= 'Faltan: ' . formatMoney($data['monto'] - $saldoActual);
            } else {
                $transactionRepo->create($data);
                $_SESSION['success'] = 'Transacción creada exitosamente';
                header("Location: " . $_SERVER["PHP_SELF"], true, 303);
                exit();
            }
        }
        
        if (isset($_POST["update"]) && isset($_POST["id"])) {
            $data['id'] = $_POST["id"];
            
            // Verificar saldo antes de actualizar la transacción
            $saldoActual = $transactionRepo->getSaldoActual($usuario_id);
            $esGasto = $transactionRepo->esCategoriaGasto($data['categoria_id']);
            
            if ($esGasto && $data['monto'] > $saldoActual) {
                $error = 'Saldo insuficiente para actualizar a este gasto. ';
                $error .= 'Saldo disponible: ' . formatMoney($saldoActual) . '. ';
                $error .= 'Monto del gasto: ' . formatMoney($data['monto']) . '. ';
                $error .= 'Faltan: ' . formatMoney($data['monto'] - $saldoActual);
            } else {
                $transactionRepo->update($data['id'], $data);
                $_SESSION['success'] = 'Transacción actualizada exitosamente';
                header("Location: " . $_SERVER["PHP_SELF"], true, 303);
                exit();
            }
        }
    } catch (PDOException $e) {
        // Capturar errores específicos de la base de datos
        if (strpos($e->getMessage(), 'Saldo insuficiente') !== false) {
            $error = 'Saldo insuficiente para realizar esta operación. ';
            $error .= 'Por favor, verifique su saldo disponible antes de registrar gastos.';
        } else if (strpos($e->getMessage(), 'presupuesto mensual') !== false) {
            $error = 'Esta transacción excede el presupuesto mensual asignado para esta categoría.';
        } else {
            $error = 'Error al procesar la operación: ' . $e->getMessage();
        }
    } catch (Exception $e) {
        $error = 'Error al procesar la operación: ' . $e->getMessage();
    }
}

// Eliminar transacción
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["delete"])) {
    $id = intval($_GET["delete"]);
    $transactionRepo->delete($id, $usuario_id);
    $_SESSION['success'] = 'Transacción eliminada exitosamente';
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
    
    // Escribir encabezados
    if (!empty($data)) {
        fputcsv($output, array_keys($data[0]));
        
        // Escribir datos
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
    
    // Formatear datos para exportación
    foreach ($exportData as &$row) {
        $row['monto'] = formatMoney($row['monto']);
    }
    
    exportToCSV($exportData, 'transacciones_' . date('Y-m-d'));
}

// Obtener saldo actual para mostrar en la interfaz
$saldoActual = $transactionRepo->getSaldoActual($usuario_id);
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
        
        .dropdown-menu {
            border: none;
            border-radius: 0.75rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            padding: 0.5rem;
        }
        
        .dropdown-item {
            border-radius: 0.5rem;
            padding: 0.5rem 1rem;
            font-weight: 500;
        }
        
        .dropdown-item:hover {
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            color: var(--bs-primary);
        }
        
        .transaction-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            font-size: 1rem;
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
        
        @media (max-width: 768px) {
            .container {
                padding-left: 1rem;
                padding-right: 1rem;
            }
            
            .table-responsive {
                font-size: 0.875rem;
            }
            
            .category-icon, .transaction-icon {
                width: 32px;
                height: 32px;
                margin-right: 8px;
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
                    <h3 class="mb-1"><?= formatMoney($saldoActual) ?></h3>
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
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>Error:</strong> <?= htmlspecialchars($error) ?>
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
                                <h3 class="text-success mb-0"><?= formatMoney($stats['total_ingresos']) ?></h3>
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
                                <h3 class="text-danger mb-0"><?= formatMoney($stats['total_gastos']) ?></h3>
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
                                <h3 class="text-warning mb-0"><?= formatMoney($stats['balance']) ?></h3>
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
                            <i class="bi bi-plus-circle me-1"></i> Agregar Transacción
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
                                            <?= formatMoney($transaccion["monto"]) ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown">
                                                <i class="bi bi-three-dots-vertical"></i>
                                            </button>
                                            <ul class="dropdown-menu">
                                                <li>
                                                    <button class="dropdown-item edit-btn"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#editTransactionModal"
                                                            data-id="<?= $transaccion["id"] ?>"
                                                            data-categoria_id="<?= $transaccion["categoria_id"] ?>"
                                                            data-monto="<?= $transaccion["monto"] / 100 ?>"
                                                            data-descripcion="<?= htmlspecialchars($transaccion["descripcion"]) ?>"
                                                            data-fecha="<?= $transaccion["fecha"] ?>">
                                                        <i class="bi bi-pencil me-2"></i> Editar
                                                    </button>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item text-danger"
                                                       href="?delete=<?= $transaccion["id"] ?>&page=<?= $page ?>&categoria_id=<?= $filters['categoria_id'] ?>&tipo=<?= $filters['tipo'] ?>&fecha_desde=<?= $filters['fecha_desde'] ?>&fecha_hasta=<?= $filters['fecha_hasta'] ?>"
                                                       onclick="return confirm('¿Estás seguro de eliminar esta transacción?\n\nEsta acción no se puede deshacer.')">
                                                        <i class="bi bi-trash me-2"></i> Eliminar
                                                    </a>
                                                </li>
                                            </ul>
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
                <form method="POST" action="">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Saldo disponible:</strong> <?= formatMoney($saldoActual) ?>
                        </div>
                        <div class="mb-3">
                            <label for="categoria_id" class="form-label">Categoría</label>
                            <select class="form-select" id="categoria_id" name="categoria_id" required>
                                <option value="">Seleccionar Categoría</option>
                                <?php foreach ($categorias as $categoria): ?>
                                    <option value="<?= $categoria["id"] ?>" data-tipo="<?= $categoria["tipo"] ?>">
                                        <?= htmlspecialchars($categoria["nombre"]) ?> (<?= $tipos[$categoria["tipo"]] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="monto" class="form-label">Monto (Guaraníes)</label>
                            <input type="text" class="form-control" id="monto" name="monto" placeholder="Ej: 1.000.000" required>
                            <small class="text-muted">Ingrese el monto en guaraníes. Use puntos para separar miles.</small>
                        </div>
                        <div class="mb-3">
                            <label for="descripcion" class="form-label">Descripción</label>
                            <textarea class="form-control" id="descripcion" name="descripcion" rows="2" placeholder="Descripción de la transacción" maxlength="255"></textarea>
                            <small class="text-muted">Máximo 255 caracteres</small>
                        </div>
                        <div class="mb-3">
                            <label for="fecha" class="form-label">Fecha</label>
                            <input class="form-control" type="date" id="fecha" name="fecha" required value="<?= date("Y-m-d") ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" name="create">
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
                <form method="POST" action="">
                    <input type="hidden" id="edit_id" name="id">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Saldo disponible:</strong> <?= formatMoney($saldoActual) ?>
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
                            <label for="edit_monto" class="form-label">Monto (Guaraníes)</label>
                            <input type="text" class="form-control" id="edit_monto" name="monto" required>
                            <small class="text-muted">Ingrese el monto en guaraníes. Use puntos para separar miles.</small>
                        </div>
                        <div class="mb-3">
                            <label for="edit_descripcion" class="form-label">Descripción</label>
                            <textarea class="form-control" id="edit_descripcion" name="descripcion" rows="2" maxlength="255"></textarea>
                            <small class="text-muted">Máximo 255 caracteres</small>
                        </div>
                        <div class="mb-3">
                            <label for="edit_fecha" class="form-label">Fecha</label>
                            <input class="form-control" type="date" id="edit_fecha" name="fecha" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" name="update">
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
            // Manejar edición de transacciones
            document.querySelectorAll('.edit-btn').forEach(button => {
                button.addEventListener('click', function() {
                    document.getElementById('edit_id').value = this.dataset.id;
                    document.getElementById('edit_categoria_id').value = this.dataset.categoria_id;
                    
                    // Formatear monto para mostrar con separadores de miles
                    const monto = parseFloat(this.dataset.monto);
                    document.getElementById('edit_monto').value = monto.toLocaleString('es-PY');
                    
                    document.getElementById('edit_descripcion').value = this.dataset.descripcion;
                    document.getElementById('edit_fecha').value = this.dataset.fecha;
                });
            });
            
            // Formatear input de monto para aceptar solo números y puntos
            document.querySelectorAll('input[name="monto"], #edit_monto').forEach(input => {
                input.addEventListener('input', function() {
                    // Permitir solo números y puntos
                    this.value = this.value.replace(/[^\d.]/g, '');
                    
                    // Evitar múltiples puntos
                    const parts = this.value.split('.');
                    if (parts.length > 2) {
                        this.value = parts[0] + '.' + parts.slice(1).join('');
                    }
                });
                
                input.addEventListener('blur', function() {
                    if (this.value) {
                        // Formatear con separadores de miles
                        const number = parseFloat(this.value.replace(/\./g, ''));
                        if (!isNaN(number)) {
                            this.value = number.toLocaleString('es-PY');
                        }
                    }
                });
            });
            
            // Validar formulario antes de enviar
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    const montoInput = this.querySelector('input[name="monto"]');
                    if (montoInput) {
                        // Limpiar el valor para enviar solo números
                        montoInput.value = montoInput.value.replace(/\./g, '');
                    }
                });
            });
            
            // Validación en tiempo real para gastos
            const categoriaSelect = document.getElementById('categoria_id');
            const montoInput = document.getElementById('monto');
            const editCategoriaSelect = document.getElementById('edit_categoria_id');
            const editMontoInput = document.getElementById('edit_monto');
            
            function validarGastoEnTiempoReal(categoriaSelect, montoInput) {
                if (!categoriaSelect || !montoInput) return;
                
                const categoriaOption = categoriaSelect.options[categoriaSelect.selectedIndex];
                const esGasto = categoriaOption && categoriaOption.dataset.tipo === 'gasto';
                
                if (esGasto && montoInput.value) {
                    const monto = parseFloat(montoInput.value.replace(/\./g, ''));
                    const saldoActual = <?= $saldoActual ?>;
                    
                    if (monto > saldoActual) {
                        montoInput.classList.add('is-invalid');
                        // Mostrar mensaje de error
                        let errorElement = montoInput.nextElementSibling;
                        if (!errorElement || !errorElement.classList.contains('invalid-feedback')) {
                            errorElement = document.createElement('div');
                            errorElement.className = 'invalid-feedback';
                            montoInput.parentNode.appendChild(errorElement);
                        }
                        errorElement.innerHTML = `Saldo insuficiente. Disponible: ${(saldoActual/100).toLocaleString('es-PY')}. Faltan: ${((monto - saldoActual)/100).toLocaleString('es-PY')}`;
                    } else {
                        montoInput.classList.remove('is-invalid');
                    }
                } else {
                    montoInput.classList.remove('is-invalid');
                }
            }
            
            if (categoriaSelect && montoInput) {
                categoriaSelect.addEventListener('change', () => validarGastoEnTiempoReal(categoriaSelect, montoInput));
                montoInput.addEventListener('input', () => validarGastoEnTiempoReal(categoriaSelect, montoInput));
            }
            
            if (editCategoriaSelect && editMontoInput) {
                editCategoriaSelect.addEventListener('change', () => validarGastoEnTiempoReal(editCategoriaSelect, editMontoInput));
                editMontoInput.addEventListener('input', () => validarGastoEnTiempoReal(editCategoriaSelect, editMontoInput));
            }
        });
    </script>
</body>
</html>