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
                   c.nombre AS cuenta_nombre,
                   c.moneda AS cuenta_moneda,
                   cat.nombre AS categoria_nombre,
                   cat.tipo AS tipo_categoria,
                   cat.color AS categoria_color,
                   cat.icono AS categoria_icono
            FROM transacciones t
            INNER JOIN cuentas c ON t.cuenta_id = c.id
            INNER JOIN categorias cat ON t.categoria_id = cat.id
            WHERE c.usuario_id = :usuario_id
            AND c.activa = TRUE
        ";

        $params = [':usuario_id' => $userId];

        // Aplicar filtros
        if (!empty($filters['cuenta_id'])) {
            $query .= " AND t.cuenta_id = :cuenta_id";
            $params[':cuenta_id'] = $filters['cuenta_id'];
        }
        if (!empty($filters['categoria_id'])) {
            $query .= " AND t.categoria_id = :categoria_id";
            $params[':categoria_id'] = $filters['categoria_id'];
        }
        if (!empty($filters['tipo'])) {
            $query .= " AND cat.tipo = :tipo";
            $params[':tipo'] = $filters['tipo'];
        }
        if (isset($filters['recurrente'])) {
            $query .= " AND t.recurrente = :recurrente";
            $params[':recurrente'] = $filters['recurrente'];
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
            INNER JOIN cuentas c ON t.cuenta_id = c.id
            INNER JOIN categorias cat ON t.categoria_id = cat.id
            WHERE c.usuario_id = :usuario_id
            AND c.activa = TRUE
        ";

        $params = [':usuario_id' => $userId];

        // Aplicar filtros
        if (!empty($filters['cuenta_id'])) {
            $query .= " AND t.cuenta_id = :cuenta_id";
            $params[':cuenta_id'] = $filters['cuenta_id'];
        }
        if (!empty($filters['categoria_id'])) {
            $query .= " AND t.categoria_id = :categoria_id";
            $params[':categoria_id'] = $filters['categoria_id'];
        }
        if (!empty($filters['tipo'])) {
            $query .= " AND cat.tipo = :tipo";
            $params[':tipo'] = $filters['tipo'];
        }
        if (isset($filters['recurrente'])) {
            $query .= " AND t.recurrente = :recurrente";
            $params[':recurrente'] = $filters['recurrente'];
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
            (cuenta_id, categoria_id, monto, descripcion, fecha, recurrente, creado_en, actualizado_en)
            VALUES
            (:cuenta_id, :categoria_id, :monto, :descripcion, :fecha, :recurrente, NOW(), NOW())
        ");
        return $stmt->execute($data);
    }

    public function update($id, $data) {
        $data['id'] = $id;
        $stmt = $this->db->prepare("
            UPDATE transacciones SET
                cuenta_id = :cuenta_id,
                categoria_id = :categoria_id,
                monto = :monto,
                descripcion = :descripcion,
                fecha = :fecha,
                recurrente = :recurrente,
                actualizado_en = NOW()
            WHERE id = :id 
            AND cuenta_id IN (SELECT id FROM cuentas WHERE usuario_id = :usuario_id)
        ");
        $data['usuario_id'] = $_SESSION["user_id"];
        return $stmt->execute($data);
    }

    public function delete($id, $userId) {
        $stmt = $this->db->prepare("
            DELETE FROM transacciones
            WHERE id = :id 
            AND cuenta_id IN (SELECT id FROM cuentas WHERE usuario_id = :usuario_id)
        ");
        return $stmt->execute(['id' => $id, 'usuario_id' => $userId]);
    }
}

// Configuración inicial
$transactionRepo = new TransactionRepository($db);
$error = '';
$success = '';

// Obtener cuentas activas del usuario
$stmtCuentas = $db->prepare("
    SELECT id, nombre, moneda 
    FROM cuentas 
    WHERE usuario_id = :usuario_id AND activa = TRUE 
    ORDER BY nombre
");
$stmtCuentas->bindValue(":usuario_id", $usuario_id, PDO::PARAM_INT);
$stmtCuentas->execute();
$cuentas = $stmtCuentas->fetchAll();

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
        'cuenta_id' => $_POST["cuenta_id"] ?? null,
        'categoria_id' => $_POST["categoria_id"] ?? null,
        'monto' => parseMoneyInput($_POST["monto"] ?? "0"),
        'descripcion' => trim($_POST["descripcion"] ?? ""),
        'fecha' => $_POST["fecha"] ?? date("Y-m-d"),
        'recurrente' => isset($_POST["recurrente"]) ? 1 : 0
    ];

    try {
        if (isset($_POST["create"]) && $data['cuenta_id'] && $data['categoria_id']) {
            $transactionRepo->create($data);
            $_SESSION['success'] = 'Transacción creada exitosamente';
        }
        if (isset($_POST["update"]) && isset($_POST["id"])) {
            $data['id'] = $_POST["id"];
            $transactionRepo->update($data['id'], $data);
            $_SESSION['success'] = 'Transacción actualizada exitosamente';
        }
    } catch (Exception $e) {
        $error = 'Error al procesar la operación: ' . $e->getMessage();
    }
    
    header("Location: " . $_SERVER["PHP_SELF"], true, 303);
    exit();
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
    'cuenta_id' => $_GET['cuenta_id'] ?? '',
    'categoria_id' => $_GET['categoria_id'] ?? '',
    'tipo' => $_GET['tipo'] ?? '',
    'recurrente' => isset($_GET['recurrente']) ? (int)$_GET['recurrente'] : null,
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
        }
        body {
            font-family: 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            background-color: #f8f9fa;
        }
        .navbar {
            box-shadow: 0 2px 4px rgba(0,0,0,.1);
            background-color: white;
        }
        .card {
            border: none;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        .card:hover {
            transform: translateY(-2px);
        }
        .badge-custom {
            font-size: 0.85em;
            padding: 0.35em 0.65em;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .pagination .page-item.active .page-link {
            background-color: var(--bs-primary);
            border-color: var(--bs-primary);
        }
        .category-icon {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
            color: white;
            font-size: 0.9rem;
        }
        .amount.ingreso {
            color: var(--bs-success);
            font-weight: 600;
        }
        .amount.gasto {
            color: var(--bs-danger);
            font-weight: 600;
        }
        .transaction-item {
            transition: background-color 0.2s;
        }
        .transaction-item:hover {
            background-color: rgba(0,0,0,0.025);
        }
        .stats-card {
            border-left: 4px solid;
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
        @media (max-width: 768px) {
            .navbar-nav {
                flex-direction: row;
            }
            .nav-item {
                margin-right: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light sticky-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <i class="bi bi-cash-stack me-2"></i>
                <strong>Finzen</strong>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">
                            <i class="bi bi-speedometer2 me-1"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="cuentas.php">
                            <i class="bi bi-wallet2 me-1"></i> Cuentas
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="categorias.php">
                            <i class="bi bi-tags me-1"></i> Categorías
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="presupuestos.php">
                            <i class="bi bi-pie-chart me-1"></i> Presupuestos
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="transacciones.php">
                            <i class="bi bi-arrow-left-right me-1"></i> Transacciones
                        </a>
                    </li>
                </ul>
                <div class="d-flex">
                    <a href="?export=csv" class="btn btn-outline-success me-2">
                        <i class="bi bi-download me-1"></i> Exportar CSV
                    </a>
                    <a href="../auth/logout.php" class="btn btn-outline-danger">
                        <i class="bi bi-box-arrow-right me-1"></i> Cerrar Sesión
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
                            <div class="text-primary">
                                <i class="bi bi-list-check fs-2"></i>
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
                            <div class="text-success">
                                <i class="bi bi-arrow-down-circle fs-2"></i>
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
                            <div class="text-danger">
                                <i class="bi bi-arrow-up-circle fs-2"></i>
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
                            <div class="text-warning">
                                <i class="bi bi-graph-up fs-2"></i>
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
                        <label for="cuenta_id" class="form-label">Cuenta</label>
                        <select class="form-select" id="cuenta_id" name="cuenta_id">
                            <option value="">Todas las cuentas</option>
                            <?php foreach ($cuentas as $cuenta): ?>
                                <option value="<?= $cuenta["id"] ?>" <?= ($filters['cuenta_id'] == $cuenta["id"]) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cuenta["nombre"]) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
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
                        <label for="recurrente" class="form-label">Recurrente</label>
                        <select class="form-select" id="recurrente" name="recurrente">
                            <option value="">Todos</option>
                            <option value="1" <?= ($filters['recurrente'] === 1) ? 'selected' : '' ?>>Sí</option>
                            <option value="0" <?= ($filters['recurrente'] === 0) ? 'selected' : '' ?>>No</option>
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
                    <div class="col-md-2 text-md-end">
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
                    <div class="text-center py-5">
                        <i class="bi bi-arrow-left-right display-4 text-muted mb-3"></i>
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
                                    <th>Cuenta</th>
                                    <th>Categoría</th>
                                    <th>Descripción</th>
                                    <th class="text-end">Monto</th>
                                    <th>Recurrente</th>
                                    <th class="text-end">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transacciones as $transaccion):
                                    $tipoClase = $transaccion["tipo_categoria"] === "ingreso" ? "ingreso" : "gasto";
                                ?>
                                <tr class="transaction-item">
                                    <td>
                                        <strong><?= date("d/m/Y", strtotime($transaccion["fecha"])) ?></strong>
                                        <br>
                                        <small class="text-muted"><?= date("H:i", strtotime($transaccion["creado_en"])) ?></small>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="transaction-icon bg-primary bg-opacity-10 text-primary me-2">
                                                <i class="bi bi-wallet2"></i>
                                            </div>
                                            <div>
                                                <strong><?= htmlspecialchars($transaccion["cuenta_nombre"]) ?></strong>
                                                <br>
                                                <small class="text-muted"><?= htmlspecialchars($transaccion["cuenta_moneda"]) ?></small>
                                            </div>
                                        </div>
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
                                    <td>
                                        <?php if ($transaccion["recurrente"]): ?>
                                            <span class="badge bg-primary badge-custom">
                                                <i class="bi bi-arrow-repeat me-1"></i> Sí
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary badge-custom">No</span>
                                        <?php endif; ?>
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
                                                            data-cuenta_id="<?= $transaccion["cuenta_id"] ?>"
                                                            data-categoria_id="<?= $transaccion["categoria_id"] ?>"
                                                            data-monto="<?= $transaccion["monto"] / 100 ?>"
                                                            data-descripcion="<?= htmlspecialchars($transaccion["descripcion"]) ?>"
                                                            data-fecha="<?= $transaccion["fecha"] ?>"
                                                            data-recurrente="<?= $transaccion["recurrente"] ?>">
                                                        <i class="bi bi-pencil me-2"></i> Editar
                                                    </button>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item text-danger"
                                                       href="?delete=<?= $transaccion["id"] ?>&page=<?= $page ?>&cuenta_id=<?= $filters['cuenta_id'] ?>&categoria_id=<?= $filters['categoria_id'] ?>&tipo=<?= $filters['tipo'] ?>&recurrente=<?= $filters['recurrente'] ?>&fecha_desde=<?= $filters['fecha_desde'] ?>&fecha_hasta=<?= $filters['fecha_hasta'] ?>"
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
                                    <a class="page-link" href="?page=<?= $page - 1 ?>&cuenta_id=<?= $filters['cuenta_id'] ?>&categoria_id=<?= $filters['categoria_id'] ?>&tipo=<?= $filters['tipo'] ?>&recurrente=<?= $filters['recurrente'] ?>&fecha_desde=<?= $filters['fecha_desde'] ?>&fecha_hasta=<?= $filters['fecha_hasta'] ?>" aria-label="Anterior">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>&cuenta_id=<?= $filters['cuenta_id'] ?>&categoria_id=<?= $filters['categoria_id'] ?>&tipo=<?= $filters['tipo'] ?>&recurrente=<?= $filters['recurrente'] ?>&fecha_desde=<?= $filters['fecha_desde'] ?>&fecha_hasta=<?= $filters['fecha_hasta'] ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $page + 1 ?>&cuenta_id=<?= $filters['cuenta_id'] ?>&categoria_id=<?= $filters['categoria_id'] ?>&tipo=<?= $filters['tipo'] ?>&recurrente=<?= $filters['recurrente'] ?>&fecha_desde=<?= $filters['fecha_desde'] ?>&fecha_hasta=<?= $filters['fecha_hasta'] ?>" aria-label="Siguiente">
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
                        <div class="mb-3">
                            <label for="cuenta_id" class="form-label">Cuenta</label>
                            <select class="form-select" id="cuenta_id" name="cuenta_id" required>
                                <option value="">Seleccionar Cuenta</option>
                                <?php foreach ($cuentas as $cuenta): ?>
                                    <option value="<?= $cuenta["id"] ?>"><?= htmlspecialchars($cuenta["nombre"]) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="categoria_id" class="form-label">Categoría</label>
                            <select class="form-select" id="categoria_id" name="categoria_id" required>
                                <option value="">Seleccionar Categoría</option>
                                <?php foreach ($categorias as $categoria): ?>
                                    <option value="<?= $categoria["id"] ?>">
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
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="recurrente" name="recurrente">
                            <label class="form-check-label" for="recurrente">
                                Transacción recurrente
                            </label>
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
                        <div class="mb-3">
                            <label for="edit_cuenta_id" class="form-label">Cuenta</label>
                            <select class="form-select" id="edit_cuenta_id" name="cuenta_id" required>
                                <option value="">Seleccionar Cuenta</option>
                                <?php foreach ($cuentas as $cuenta): ?>
                                    <option value="<?= $cuenta["id"] ?>"><?= htmlspecialchars($cuenta["nombre"]) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_categoria_id" class="form-label">Categoría</label>
                            <select class="form-select" id="edit_categoria_id" name="categoria_id" required>
                                <option value="">Seleccionar Categoría</option>
                                <?php foreach ($categorias as $categoria): ?>
                                    <option value="<?= $categoria["id"] ?>">
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
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="edit_recurrente" name="recurrente">
                            <label class="form-check-label" for="edit_recurrente">
                                Transacción recurrente
                            </label>
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
                    document.getElementById('edit_cuenta_id').value = this.dataset.cuenta_id;
                    document.getElementById('edit_categoria_id').value = this.dataset.categoria_id;
                    
                    // Formatear monto para mostrar con separadores de miles
                    const monto = parseFloat(this.dataset.monto);
                    document.getElementById('edit_monto').value = monto.toLocaleString('es-PY');
                    
                    document.getElementById('edit_descripcion').value = this.dataset.descripcion;
                    document.getElementById('edit_fecha').value = this.dataset.fecha;
                    document.getElementById('edit_recurrente').checked = this.dataset.recurrente === '1';
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
        });
    </script>
</body>
</html>