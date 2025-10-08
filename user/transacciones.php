<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
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

// Función para validar monto
function validarMontoTransaccion($monto, $tipo, $saldoUsuario) {
    $montoNumerico = parseMoneyInput($monto);
    
    if ($montoNumerico <= 0) {
        return "El monto debe ser mayor a cero";
    }
    
    if ($tipo === 'gasto' && $montoNumerico > $saldoUsuario) {
        return "Saldo insuficiente para realizar este gasto";
    }
    
    if ($montoNumerico > 1000000000000) {
        return "El monto es demasiado alto";
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
            SELECT
                t.*,
                c.nombre AS categoria_nombre,
                c.color AS categoria_color,
                c.tipo AS categoria_tipo,
                c.icono AS categoria_icono
            FROM
                transacciones t
            INNER JOIN
                categorias c ON t.categoria_id = c.id
            WHERE
                t.usuario_id = :usuario_id
        ";
        
        $params = [':usuario_id' => $userId];
        
        // Aplicar filtros
        if (!empty($filters['categoria_id'])) {
            $query .= " AND t.categoria_id = :categoria_id";
            $params[':categoria_id'] = $filters['categoria_id'];
        }
        
        if (!empty($filters['tipo'])) {
            $query .= " AND c.tipo = :tipo";
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
    
    public function getTotalStats($userId, $filters = []) {
        $query = "
            SELECT
                COUNT(*) as total_transacciones,
                COUNT(CASE WHEN c.tipo = 'ingreso' THEN 1 END) as total_ingresos,
                COUNT(CASE WHEN c.tipo = 'gasto' THEN 1 END) as total_gastos,
                COALESCE(SUM(CASE WHEN c.tipo = 'ingreso' THEN t.monto ELSE 0 END), 0) as monto_ingresos,
                COALESCE(SUM(CASE WHEN c.tipo = 'gasto' THEN t.monto ELSE 0 END), 0) as monto_gastos
            FROM transacciones t
            INNER JOIN categorias c ON t.categoria_id = c.id
            WHERE t.usuario_id = :usuario_id
        ";
        
        $params = [':usuario_id' => $userId];
        
        // Aplicar mismos filtros
        if (!empty($filters['categoria_id'])) {
            $query .= " AND t.categoria_id = :categoria_id";
            $params[':categoria_id'] = $filters['categoria_id'];
        }
        
        if (!empty($filters['tipo'])) {
            $query .= " AND c.tipo = :tipo";
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
            (usuario_id, categoria_id, monto, descripcion, fecha, recurrente, creado_en)
            VALUES
            (:usuario_id, :categoria_id, :monto, :descripcion, :fecha, :recurrente, NOW())
        ");
        
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
                recurrente = :recurrente,
                actualizado_en = NOW()
            WHERE id = :id AND usuario_id = :usuario_id
        ");
        
        return $stmt->execute($data);
    }
    
    public function delete($id, $userId) {
        $stmt = $this->db->prepare("DELETE FROM transacciones WHERE id = :id AND usuario_id = :usuario_id");
        return $stmt->execute(['id' => $id, 'usuario_id' => $userId]);
    }
}

// Configuración inicial
$transactionRepo = new TransactionRepository($db);
$error = '';
$success = '';

// Obtener saldo y moneda del usuario
$stmt = $db->prepare("SELECT saldo, moneda FROM usuarios WHERE id = ?");
$stmt->execute([$usuario_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$user_saldo = $user['saldo'];
$user_currency = $user['moneda'];

// Obtener categorías del usuario
$stmtCategorias = $db->prepare("
    SELECT id, nombre, color, icono, tipo
    FROM categorias
    WHERE usuario_id = :usuario_id
    ORDER BY tipo, nombre
");
$stmtCategorias->bindValue(":usuario_id", $usuario_id, PDO::PARAM_INT);
$stmtCategorias->execute();
$categorias = $stmtCategorias->fetchAll();

// Procesar operaciones CRUD
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $data = [
        'usuario_id' => $usuario_id,
        'categoria_id' => $_POST["categoria_id"] ?? null,
        'monto' => parseMoneyInput($_POST["monto"] ?? "0"),
        'descripcion' => $_POST["descripcion"] ?? '',
        'fecha' => $_POST["fecha"] ?? date('Y-m-d'),
        'recurrente' => isset($_POST["recurrente"]) ? 1 : 0
    ];

    // Obtener tipo de categoría para validación
    $tipoCategoria = '';
    foreach ($categorias as $cat) {
        if ($cat['id'] == $data['categoria_id']) {
            $tipoCategoria = $cat['tipo'];
            break;
        }
    }

    // Validar monto
    $montoError = validarMontoTransaccion($_POST["monto"] ?? "0", $tipoCategoria, $user_saldo);
    if ($montoError) {
        $_SESSION['error'] = $montoError;
        header("Location: " . $_SERVER["PHP_SELF"]);
        exit();
    }

    try {
        if (isset($_POST["create"]) && $data['categoria_id']) {
            if ($transactionRepo->create($data)) {
                $_SESSION['success'] = 'Transacción creada exitosamente';
            } else {
                $_SESSION['error'] = 'Error al crear la transacción';
            }
        }
        
        if (isset($_POST["update"]) && isset($_POST["id"])) {
            if ($transactionRepo->update($_POST["id"], $data)) {
                $_SESSION['success'] = 'Transacción actualizada exitosamente';
            } else {
                $_SESSION['error'] = 'Error al actualizar la transacción';
            }
        }
    } catch (PDOException $e) {
        $errorMessage = $e->getMessage();
        
        if (strpos($errorMessage, 'Saldo insuficiente') !== false) {
            $_SESSION['error'] = 'No tienes suficiente saldo para realizar este gasto';
        } elseif (strpos($errorMessage, 'excede el presupuesto mensual') !== false) {
            $_SESSION['error'] = 'Esta transacción excede el presupuesto mensual asignado para esta categoría';
        } elseif (strpos($errorMessage, 'Gasto demasiado elevado') !== false) {
            $_SESSION['error'] = 'El gasto es demasiado elevado en relación a tu saldo actual';
        } else {
            $_SESSION['error'] = 'Error al procesar la transacción. Por favor, verifica los datos.';
        }
        
        header("Location: " . $_SERVER["PHP_SELF"]);
        exit();
    }

    header("Location: " . $_SERVER["PHP_SELF"] . "?" . http_build_query($_GET));
    exit();
}

// Eliminar transacción
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["delete"])) {
    $id = intval($_GET["delete"]);
    try {
        if ($transactionRepo->delete($id, $usuario_id)) {
            $_SESSION['success'] = 'Transacción eliminada exitosamente';
        } else {
            $_SESSION['error'] = 'Error al eliminar la transacción';
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Error al eliminar la transacción';
    }
    header("Location: " . $_SERVER["PHP_SELF"] . "?" . http_build_query(array_diff_key($_GET, ['delete' => ''])));
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
    'fecha_hasta' => $_GET['fecha_hasta'] ?? ''
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
$stats = $transactionRepo->getTotalStats($usuario_id, $filters);
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
        /* Estilos similares a la vista de presupuestos para mantener consistencia */
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            border-radius: 1rem;
            padding: 1.5rem;
            border: 1px solid var(--bs-border);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
        }

        .stat-card.primary::before {
            background-color: var(--bs-primary);
        }

        .stat-card.success::before {
            background-color: var(--bs-success);
        }

        .stat-card.danger::before {
            background-color: var(--bs-danger);
        }

        .stat-card.info::before {
            background-color: var(--bs-info);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1.2;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.875rem;
            color: #6c757d;
            font-weight: 500;
        }

        .badge-custom {
            font-size: 0.75rem;
            padding: 0.375rem 0.75rem;
            border-radius: 0.75rem;
            font-weight: 500;
        }

        .transaction-type-ingreso {
            border-left: 4px solid var(--bs-success);
        }

        .transaction-type-gasto {
            border-left: 4px solid var(--bs-danger);
        }

        .transaction-amount {
            font-size: 1.1rem;
            font-weight: 600;
        }

        .transaction-amount.ingreso {
            color: var(--bs-success);
        }

        .transaction-amount.gasto {
            color: var(--bs-danger);
        }

        .category-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
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

        .filters-card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
        }

        .btn-action {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 0.5rem;
            transition: all 0.2s ease;
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

        .quick-actions .btn {
            border-radius: 0.75rem;
            padding: 1rem;
            text-align: center;
            transition: all 0.3s ease;
        }

        .quick-actions .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar (igual que en presupuestos) -->
    <nav class="navbar navbar-expand-lg navbar-light sticky-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <i class="bi bi-wallet2 me-2"></i>Finzen
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
                <div class="d-flex align-items-center">
                    <span class="text-muted me-3"><?= htmlspecialchars($_SESSION['username']) ?></span>
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
                <p class="text-muted mb-0">Registro de todos tus ingresos y gastos</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTransactionModal">
                <i class="bi bi-plus-circle me-1"></i> Nueva Transacción
            </button>
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
                <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="row g-3 mb-4 quick-actions">
            <div class="col-md-3">
                <button class="btn btn-primary w-100 d-flex flex-column align-items-center" data-bs-toggle="modal" data-bs-target="#addTransactionModal">
                    <i class="bi bi-plus-circle fs-2 mb-2"></i>
                    <span>Nueva Transacción</span>
                </button>
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
        <div class="stats-grid">
            <div class="stat-card primary">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-value text-primary"><?= $stats['total_transacciones'] ?></div>
                        <div class="stat-label">Total Transacciones</div>
                        <small class="text-muted"><?= $stats['total_ingresos'] ?> ingresos, <?= $stats['total_gastos'] ?> gastos</small>
                    </div>
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-arrow-left-right"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card success">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-value text-success"><?= formatMoney($stats['monto_ingresos'], $user_currency) ?></div>
                        <div class="stat-label">Total Ingresos</div>
                        <small class="text-muted">Dinero recibido</small>
                    </div>
                    <div class="stat-icon bg-success bg-opacity-10 text-success">
                        <i class="bi bi-arrow-down-left"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card danger">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-value text-danger"><?= formatMoney($stats['monto_gastos'], $user_currency) ?></div>
                        <div class="stat-label">Total Gastos</div>
                        <small class="text-muted">Dinero gastado</small>
                    </div>
                    <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                        <i class="bi bi-arrow-up-right"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card info">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-value text-info"><?= formatMoney($user_saldo, $user_currency) ?></div>
                        <div class="stat-label">Saldo Actual</div>
                        <small class="text-muted">Balance disponible</small>
                    </div>
                    <div class="stat-icon bg-info bg-opacity-10 text-info">
                        <i class="bi bi-wallet2"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="filters-card">
            <form method="GET" action="" id="filtersForm">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
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
                            <option value="">Todos los tipos</option>
                            <option value="ingreso" <?= ($filters['tipo'] === 'ingreso') ? 'selected' : '' ?>>Ingresos</option>
                            <option value="gasto" <?= ($filters['tipo'] === 'gasto') ? 'selected' : '' ?>>Gastos</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="fecha_desde" class="form-label">Fecha Desde</label>
                        <input type="date" class="form-control" id="fecha_desde" name="fecha_desde" value="<?= htmlspecialchars($filters['fecha_desde']) ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="fecha_hasta" class="form-label">Fecha Hasta</label>
                        <input type="date" class="form-control" id="fecha_hasta" name="fecha_hasta" value="<?= htmlspecialchars($filters['fecha_hasta']) ?>">
                    </div>
                    <div class="col-md-1 text-md-end">
                        <span class="badge bg-primary badge-custom">
                            <?= $totalTransacciones ?> transaccion<?= $totalTransacciones !== 1 ? 'es' : '' ?>
                        </span>
                    </div>
                </div>
            </form>
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
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Categoría</th>
                                    <th>Descripción</th>
                                    <th class="text-end">Monto</th>
                                    <th class="text-center">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transacciones as $transaccion): ?>
                                    <tr class="transaction-type-<?= $transaccion['categoria_tipo'] ?>">
                                        <td>
                                            <div class="fw-bold"><?= date("d/m/Y", strtotime($transaccion['fecha'])) ?></div>
                                            <small class="text-muted"><?= date("H:i", strtotime($transaccion['creado_en'])) ?></small>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="category-icon me-2" style="background-color: <?= htmlspecialchars($transaccion['categoria_color']) ?>">
                                                    <i class="bi <?= htmlspecialchars($transaccion['categoria_icono'] ?? 'bi-tag') ?>"></i>
                                                </div>
                                                <div>
                                                    <div class="fw-bold"><?= htmlspecialchars($transaccion['categoria_nombre']) ?></div>
                                                    <span class="badge <?= $transaccion['categoria_tipo'] === 'ingreso' ? 'bg-success' : 'bg-danger' ?> badge-custom">
                                                        <?= $transaccion['categoria_tipo'] === 'ingreso' ? 'Ingreso' : 'Gasto' ?>
                                                    </span>
                                                    <?php if ($transaccion['recurrente']): ?>
                                                        <span class="badge bg-info badge-custom">
                                                            <i class="bi bi-arrow-repeat"></i> Recurrente
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($transaccion['descripcion'] ?: 'Sin descripción') ?>
                                        </td>
                                        <td class="text-end">
                                            <span class="transaction-amount <?= $transaccion['categoria_tipo'] ?>">
                                                <?= $transaccion['categoria_tipo'] === 'ingreso' ? '+' : '-' ?>
                                                <?= formatMoney($transaccion['monto'], $user_currency) ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <div class="d-flex justify-content-center gap-1">
                                                <button class="btn btn-sm btn-outline-primary btn-action edit-btn"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#editTransactionModal"
                                                        data-id="<?= $transaccion["id"] ?>"
                                                        data-categoria_id="<?= $transaccion["categoria_id"] ?>"
                                                        data-monto="<?= $transaccion["monto"] / 100 ?>"
                                                        data-descripcion="<?= htmlspecialchars($transaccion["descripcion"]) ?>"
                                                        data-fecha="<?= $transaccion["fecha"] ?>"
                                                        data-recurrente="<?= $transaccion["recurrente"] ?>">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <a class="btn btn-sm btn-outline-danger btn-action"
                                                   href="?<?= http_build_query(array_merge($_GET, ['delete' => $transaccion["id"]])) ?>"
                                                   onclick="return confirm('¿Estás seguro de eliminar esta transacción?\n\nEsta acción no se puede deshacer.')">
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
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" aria-label="Anterior">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" aria-label="Siguiente">
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
                        <i class="bi bi-plus-circle me-2"></i> Nueva Transacción
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="" id="addTransactionForm">
                    <input type="hidden" name="usuario_id" value="<?= $usuario_id ?>">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="add_categoria_id" class="form-label">Categoría</label>
                            <select class="form-select" id="add_categoria_id" name="categoria_id" required>
                                <option value="">Seleccionar Categoría</option>
                                <?php foreach ($categorias as $categoria): ?>
                                    <option value="<?= $categoria["id"] ?>" data-tipo="<?= $categoria["tipo"] ?>">
                                        <?= htmlspecialchars($categoria["nombre"]) ?> (<?= $categoria["tipo"] === 'ingreso' ? 'Ingreso' : 'Gasto' ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="add_monto" class="form-label">Monto (<?= $user_currency ?>)</label>
                            <input type="text" class="form-control money-input" id="add_monto" name="monto" placeholder="Ej: 1.000.000" required>
                            <div id="montoValidation" class="validation-message"></div>
                            <small class="text-muted" id="montoHelp">Ingresa el monto de la transacción</small>
                        </div>
                        <div class="mb-3">
                            <label for="add_descripcion" class="form-label">Descripción</label>
                            <textarea class="form-control" id="add_descripcion" name="descripcion" rows="3" placeholder="Descripción opcional de la transacción"></textarea>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-8">
                                <label for="add_fecha" class="form-label">Fecha</label>
                                <input class="form-control" type="date" id="add_fecha" name="fecha" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">&nbsp;</label>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" id="add_recurrente" name="recurrente">
                                    <label class="form-check-label" for="add_recurrente">
                                        <i class="bi bi-arrow-repeat me-1"></i>Recurrente
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" name="create" id="submitBtn">
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
                    <input type="hidden" name="usuario_id" value="<?= $usuario_id ?>">
                    <input type="hidden" id="edit_id" name="id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_categoria_id" class="form-label">Categoría</label>
                            <select class="form-select" id="edit_categoria_id" name="categoria_id" required>
                                <option value="">Seleccionar Categoría</option>
                                <?php foreach ($categorias as $categoria): ?>
                                    <option value="<?= $categoria["id"] ?>" data-tipo="<?= $categoria["tipo"] ?>">
                                        <?= htmlspecialchars($categoria["nombre"]) ?> (<?= $categoria["tipo"] === 'ingreso' ? 'Ingreso' : 'Gasto' ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_monto" class="form-label">Monto (<?= $user_currency ?>)</label>
                            <input type="text" class="form-control money-input" id="edit_monto" name="monto" required>
                            <div id="editMontoValidation" class="validation-message"></div>
                        </div>
                        <div class="mb-3">
                            <label for="edit_descripcion" class="form-label">Descripción</label>
                            <textarea class="form-control" id="edit_descripcion" name="descripcion" rows="3"></textarea>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-8">
                                <label for="edit_fecha" class="form-label">Fecha</label>
                                <input class="form-control" type="date" id="edit_fecha" name="fecha" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">&nbsp;</label>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" id="edit_recurrente" name="recurrente">
                                    <label class="form-check-label" for="edit_recurrente">
                                        <i class="bi bi-arrow-repeat me-1"></i>Recurrente
                                    </label>
                                </div>
                            </div>
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
            // Función para formatear número con separadores de miles
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

            // Función para validar monto en tiempo real
            function validarMontoEnTiempo(input, tipo, validationDiv, submitBtn) {
                const value = input.value.replace(/\./g, '');
                const montoNumerico = parseFloat(value) || 0;
                const userSaldo = <?= $user_saldo ?>;
                
                input.classList.remove('is-invalid', 'is-valid');
                validationDiv.innerHTML = '';
                if (submitBtn) submitBtn.disabled = false;
                
                if (montoNumerico > 0) {
                    if (montoNumerico < 1) {
                        validationDiv.innerHTML = `<div class="validation-error">
                            <i class="bi bi-exclamation-triangle"></i> 
                            El monto debe ser mayor a cero
                        </div>`;
                        input.classList.add('is-invalid');
                        if (submitBtn) submitBtn.disabled = true;
                        return false;
                    }
                    
                    if (montoNumerico > 1000000000000) {
                        validationDiv.innerHTML = `<div class="validation-error">
                            <i class="bi bi-exclamation-triangle"></i> 
                            El monto es demasiado alto
                        </div>`;
                        input.classList.add('is-invalid');
                        if (submitBtn) submitBtn.disabled = true;
                        return false;
                    }
                    
                    if (tipo === 'gasto' && montoNumerico > userSaldo) {
                        validationDiv.innerHTML = `<div class="validation-error">
                            <i class="bi bi-exclamation-triangle"></i> 
                            Saldo insuficiente. Tu saldo actual es <?= formatMoney($user_saldo, $user_currency) ?>
                        </div>`;
                        input.classList.add('is-invalid');
                        if (submitBtn) submitBtn.disabled = true;
                        return false;
                    }
                    
                    validationDiv.innerHTML = `<div class="validation-success">
                        <i class="bi bi-check-circle"></i> 
                        Monto válido
                    </div>`;
                    input.classList.add('is-valid');
                    return true;
                }
                
                return false;
            }

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
                    document.getElementById('edit_recurrente').checked = this.dataset.recurrente === '1';
                    
                    // Obtener tipo de categoría seleccionada
                    const selectedOption = document.querySelector(`#edit_categoria_id option[value="${this.dataset.categoria_id}"]`);
                    const tipo = selectedOption ? selectedOption.dataset.tipo : '';
                    
                    setTimeout(() => {
                        validarMontoEnTiempo(
                            montoInput,
                            tipo,
                            document.getElementById('editMontoValidation'),
                            document.getElementById('editSubmitBtn')
                        );
                    }, 100);
                });
            });

            // Formatear input de monto automáticamente
            document.querySelectorAll('.money-input').forEach(input => {
                input.addEventListener('input', function(e) {
                    formatNumberWithDots(this);
                    
                    // Obtener tipo de categoría seleccionada
                    const categoriaSelect = this.closest('form').querySelector('select[name="categoria_id"]');
                    const selectedOption = categoriaSelect ? categoriaSelect.options[categoriaSelect.selectedIndex] : null;
                    const tipo = selectedOption ? selectedOption.dataset.tipo : '';
                    
                    if (this.id === 'add_monto') {
                        validarMontoEnTiempo(
                            this,
                            tipo,
                            document.getElementById('montoValidation'),
                            document.getElementById('submitBtn')
                        );
                    } else if (this.id === 'edit_monto') {
                        validarMontoEnTiempo(
                            this,
                            tipo,
                            document.getElementById('editMontoValidation'),
                            document.getElementById('editSubmitBtn')
                        );
                    }
                });

                input.addEventListener('blur', function() {
                    const categoriaSelect = this.closest('form').querySelector('select[name="categoria_id"]');
                    const selectedOption = categoriaSelect ? categoriaSelect.options[categoriaSelect.selectedIndex] : null;
                    const tipo = selectedOption ? selectedOption.dataset.tipo : '';
                    
                    if (this.id === 'add_monto') {
                        validarMontoEnTiempo(
                            this,
                            tipo,
                            document.getElementById('montoValidation'),
                            document.getElementById('submitBtn')
                        );
                    } else if (this.id === 'edit_monto') {
                        validarMontoEnTiempo(
                            this,
                            tipo,
                            document.getElementById('editMontoValidation'),
                            document.getElementById('editSubmitBtn')
                        );
                    }
                });

                if (input.value) {
                    formatNumberWithDots(input);
                }
            });

            // Actualizar ayuda de monto según tipo de categoría
            document.querySelectorAll('select[name="categoria_id"]').forEach(select => {
                select.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    const tipo = selectedOption ? selectedOption.dataset.tipo : '';
                    const montoHelp = document.getElementById('montoHelp');
                    
                    if (montoHelp) {
                        if (tipo === 'gasto') {
                            montoHelp.textContent = `Tu saldo actual es <?= formatMoney($user_saldo, $user_currency) ?>. No puedes gastar más de esta cantidad.`;
                            montoHelp.className = 'text-muted text-warning';
                        } else {
                            montoHelp.textContent = 'Ingresa el monto del ingreso';
                            montoHelp.className = 'text-muted';
                        }
                    }
                    
                    // Validar monto actual si hay uno ingresado
                    const montoInput = this.closest('form').querySelector('.money-input');
                    if (montoInput && montoInput.value) {
                        if (montoInput.id === 'add_monto') {
                            validarMontoEnTiempo(
                                montoInput,
                                tipo,
                                document.getElementById('montoValidation'),
                                document.getElementById('submitBtn')
                            );
                        } else if (montoInput.id === 'edit_monto') {
                            validarMontoEnTiempo(
                                montoInput,
                                tipo,
                                document.getElementById('editMontoValidation'),
                                document.getElementById('editSubmitBtn')
                            );
                        }
                    }
                });
            });

            // Configurar fecha por defecto
            const fechaInput = document.getElementById('add_fecha');
            if (fechaInput && !fechaInput.value) {
                fechaInput.value = new Date().toISOString().split('T')[0];
            }

            // Validar formularios antes de enviar
            document.getElementById('addTransactionForm')?.addEventListener('submit', function(e) {
                const montoInput = this.querySelector('#add_monto');
                if (montoInput) {
                    montoInput.value = montoInput.value.replace(/\./g, '');
                }
            });

            document.getElementById('editTransactionForm')?.addEventListener('submit', function(e) {
                const montoInput = this.querySelector('#edit_monto');
                if (montoInput) {
                    montoInput.value = montoInput.value.replace(/\./g, '');
                }
            });

            // Auto-submit de filtros
            document.querySelectorAll('#categoria_id, #tipo, #fecha_desde, #fecha_hasta').forEach(select => {
                select.addEventListener('change', function() {
                    document.getElementById('filtersForm').submit();
                });
            });
        });
    </script>
</body>
</html>