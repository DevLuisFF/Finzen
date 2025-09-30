<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit();
}
require "../config/database.php";
$db = Conexion::obtenerInstancia()->obtenerConexion();
$usuario_id = $_SESSION["user_id"];

// Función para formatear dinero en guaraníes
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

// Clase para manejar cuentas
class AccountRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function getAll($userId, $filters = [], $limit = 10, $offset = 0) {
        $query = "
            SELECT c.*, u.nombre_usuario
            FROM cuentas c
            JOIN usuarios u ON c.usuario_id = u.id
            WHERE c.usuario_id = :usuario_id
        ";
        $params = [':usuario_id' => $userId];

        // Aplicar filtros
        if (isset($filters['activa'])) {
            $query .= " AND c.activa = :activa";
            $params[':activa'] = $filters['activa'];
        }

        // Contar total para paginación
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM ($query) AS filtered");
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();

        // Aplicar paginación
        $query .= " ORDER BY c.creado_en DESC LIMIT :limit OFFSET :offset";
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

    public function getTotalBalance($userId) {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(saldo), 0) as total_saldo
            FROM cuentas 
            WHERE usuario_id = :usuario_id AND activa = TRUE
        ");
        $stmt->execute([':usuario_id' => $userId]);
        return $stmt->fetchColumn();
    }

    public function create($data) {
        $stmt = $this->db->prepare("
            INSERT INTO cuentas (usuario_id, nombre, saldo, moneda, activa, creado_en, actualizado_en)
            VALUES (:usuario_id, :nombre, :saldo, 'PYG', :activa, NOW(), NOW())
        ");
        return $stmt->execute($data);
    }

    public function update($id, $data) {
        $data['id'] = $id;
        $stmt = $this->db->prepare("
            UPDATE cuentas SET
                nombre = :nombre,
                saldo = :saldo,
                activa = :activa,
                actualizado_en = NOW()
            WHERE id = :id AND usuario_id = :usuario_id
        ");
        return $stmt->execute($data);
    }

    public function delete($id, $userId) {
        // Verificar si la cuenta tiene transacciones antes de eliminar
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM transacciones WHERE cuenta_id = :id
        ");
        $stmt->execute([':id' => $id]);
        $hasTransactions = $stmt->fetchColumn() > 0;

        if ($hasTransactions) {
            return false; // No se puede eliminar cuenta con transacciones
        }

        $stmt = $this->db->prepare("DELETE FROM cuentas WHERE id = :id AND usuario_id = :usuario_id");
        return $stmt->execute(['id' => $id, 'usuario_id' => $userId]);
    }
}

// Procesar operaciones CRUD
$accountRepo = new AccountRepository($db);
$error = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $data = [
        'usuario_id' => $usuario_id,
        'nombre' => trim($_POST["nombre"] ?? ""),
        'saldo' => parseMoneyInput($_POST["saldo"] ?? "0"),
        'activa' => isset($_POST["activa"]) ? 1 : 0
    ];

    try {
        if (isset($_POST["create"]) && $data['nombre']) {
            $accountRepo->create($data);
            $_SESSION['success'] = 'Cuenta creada exitosamente';
        }
        if (isset($_POST["update"]) && isset($_POST["id"])) {
            $data['id'] = intval($_POST["id"]);
            $accountRepo->update($data['id'], $data);
            $_SESSION['success'] = 'Cuenta actualizada exitosamente';
        }
    } catch (Exception $e) {
        $error = 'Error al procesar la operación: ' . $e->getMessage();
    }
    
    header("Location: " . $_SERVER["PHP_SELF"], true, 303);
    exit();
}

// Eliminar cuenta
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["delete"])) {
    $id = intval($_GET["delete"]);
    $success = $accountRepo->delete($id, $usuario_id);
    
    if ($success) {
        $_SESSION['success'] = 'Cuenta eliminada exitosamente';
    } else {
        $_SESSION['error'] = 'No se puede eliminar una cuenta que tiene transacciones asociadas';
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

// Obtener filtros de la URL
$filters = [
    'activa' => isset($_GET['activa']) ? (int)$_GET['activa'] : null
];

// Configuración de paginación
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 5;
$offset = ($page - 1) * $perPage;

// Obtener cuentas con filtros y paginación
$result = $accountRepo->getAll($usuario_id, $filters, $perPage, $offset);
$cuentas = $result['data'];
$totalCuentas = $result['total'];
$totalPages = ceil($totalCuentas / $perPage);

// Obtener saldo total
$saldoTotal = $accountRepo->getTotalBalance($usuario_id);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finzen | Mis Cuentas</title>
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
        
        .saldo-total-card {
            border-left: 4px solid var(--bs-success);
            position: relative;
        }
        
        .account-card {
            border-left: 4px solid var(--bs-primary);
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
        
        .form-check-input:checked {
            background-color: var(--bs-primary);
            border-color: var(--bs-primary);
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
        
        /* Nuevos estilos mejorados */
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
            font-size: 1.75rem;
            font-weight: 700;
            line-height: 1.2;
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            font-size: 0.875rem;
            color: #6c757d;
            font-weight: 500;
        }
        
        .account-status {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            font-weight: 500;
            font-size: 0.875rem;
        }
        
        .status-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        
        .status-active {
            background-color: var(--bs-success);
            color: var(--bs-success);
        }
        
        .status-inactive {
            background-color: var(--bs-danger);
            color: var(--bs-danger);
        }
        
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
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
        
        .filters-card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
        }
        
        .account-summary {
            background: linear-gradient(135deg, var(--bs-primary) 0%, #0a58ca 100%);
            color: white;
            border-radius: 1rem;
            padding: 2rem;
            margin-bottom: 2rem;
        }
        
        .summary-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .summary-label {
            font-size: 1rem;
            opacity: 0.9;
        }
        
        @media (max-width: 768px) {
            .container {
                padding-left: 1rem;
                padding-right: 1rem;
            }
            
            .table-responsive {
                font-size: 0.875rem;
            }
            
            .btn-group-vertical .btn {
                margin-bottom: 0.5rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn-action {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light sticky-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="#">
                Finzen
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">
                            <i class="bi bi-speedometer2 me-2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="cuentas.php">
                            <i class="bi bi-wallet2 me-2"></i> Cuentas
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
                        <a class="nav-link" href="transacciones.php">
                            <i class="bi bi-arrow-left-right me-2"></i> Transacciones
                        </a>
                    </li>
                </ul>
                <div class="d-flex align-items-center">
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
                <h1 class="mb-1">Mis Cuentas</h1>
                <p class="text-muted mb-0">Gestiona tus cuentas financieras en Guaraníes</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAccountModal">
                <i class="bi bi-plus-circle me-1"></i> Nueva Cuenta
            </button>
        </div>

        <!-- Alertas -->
        <?php if (isset($success)): ?>
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

        <!-- Resumen de estadísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                    <i class="bi bi-wallet2"></i>
                </div>
                <div class="stat-value"><?= $totalCuentas ?></div>
                <div class="stat-label">Total de Cuentas</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon bg-success bg-opacity-10 text-success">
                    <i class="bi bi-cash-stack"></i>
                </div>
                <div class="stat-value"><?= formatMoney($saldoTotal) ?></div>
                <div class="stat-label">Saldo Total Activo</div>
            </div>
            
            <?php
            // Calcular cuentas activas
            $cuentasActivas = array_filter($cuentas, function($cuenta) {
                return $cuenta['activa'];
            });
            $totalActivas = count($cuentasActivas);
            ?>
            
            <div class="stat-card">
                <div class="stat-icon bg-info bg-opacity-10 text-info">
                    <i class="bi bi-check-circle"></i>
                </div>
                <div class="stat-value"><?= $totalActivas ?></div>
                <div class="stat-label">Cuentas Activas</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                    <i class="bi bi-pie-chart"></i>
                </div>
                <div class="stat-value"><?= $totalCuentas - $totalActivas ?></div>
                <div class="stat-label">Cuentas Inactivas</div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="filters-card">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label for="activa" class="form-label">Estado de la Cuenta</label>
                    <select class="form-select" id="activa" name="activa" onchange="this.form.submit()">
                        <option value="">Todas las cuentas</option>
                        <option value="1" <?= ($filters['activa'] === 1) ? 'selected' : '' ?>>Solo activas</option>
                        <option value="0" <?= ($filters['activa'] === 0) ? 'selected' : '' ?>>Solo inactivas</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-funnel me-1"></i> Aplicar Filtros
                    </button>
                </div>
                <div class="col-md-4 text-md-end">
                    <span class="badge bg-primary badge-custom">
                        <?= $totalCuentas ?> cuenta<?= $totalCuentas !== 1 ? 's' : '' ?> encontrada<?= $totalCuentas !== 1 ? 's' : '' ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Tabla de cuentas -->
        <div class="card">
            <div class="card-body">
                <?php if (empty($cuentas)): ?>
                    <div class="empty-state">
                        <i class="bi bi-wallet2"></i>
                        <h3 class="mb-2">No se encontraron cuentas</h3>
                        <p class="text-muted mb-4">No hay cuentas que coincidan con los filtros seleccionados</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAccountModal">
                            <i class="bi bi-plus-circle me-1"></i> Agregar Cuenta
                        </button>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Cuenta</th>
                                    <th>Saldo Actual</th>
                                    <th>Estado</th>
                                    <th>Última Actualización</th>
                                    <th class="text-end">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cuentas as $cuenta): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="metric-icon bg-primary bg-opacity-10 text-primary me-3">
                                                <i class="bi bi-wallet2"></i>
                                            </div>
                                            <div>
                                                <strong><?= htmlspecialchars($cuenta["nombre"]) ?></strong>
                                                <br>
                                                <small class="text-muted">Creada: <?= date('d/m/Y', strtotime($cuenta["creado_en"])) ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="fw-bold h5 <?= $cuenta['saldo'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                            <?= formatMoney($cuenta["saldo"]) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="account-status <?= $cuenta["activa"] ? 'bg-success bg-opacity-10 text-success' : 'bg-danger bg-opacity-10 text-danger' ?>">
                                            <span class="status-indicator <?= $cuenta["activa"] ? 'status-active' : 'status-inactive' ?>"></span>
                                            <?= $cuenta["activa"] ? 'Activa' : 'Inactiva' ?>
                                        </div>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?= $cuenta["actualizado_en"] ? date('d/m/Y H:i', strtotime($cuenta["actualizado_en"])) : 'Nunca' ?>
                                        </small>
                                    </td>
                                    <td class="text-end">
                                        <div class="action-buttons">
                                            <button class="btn btn-sm btn-outline-primary btn-action edit-btn"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#editAccountModal"
                                                    data-id="<?= $cuenta["id"] ?>"
                                                    data-nombre="<?= htmlspecialchars($cuenta["nombre"]) ?>"
                                                    data-saldo="<?= $cuenta["saldo"] / 100 ?>"
                                                    data-activa="<?= $cuenta["activa"] ?>"
                                                    title="Editar cuenta">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <a href="?delete=<?= $cuenta["id"] ?>&page=<?= $page ?>&activa=<?= $filters['activa'] ?>"
                                               class="btn btn-sm btn-outline-danger btn-action"
                                               onclick="return confirm('¿Estás seguro de eliminar esta cuenta?\n\nEsta acción no se puede deshacer.')"
                                               title="Eliminar cuenta">
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
                                    <a class="page-link" href="?page=<?= $page - 1 ?>&activa=<?= $filters['activa'] ?>" aria-label="Anterior">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>&activa=<?= $filters['activa'] ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $page + 1 ?>&activa=<?= $filters['activa'] ?>" aria-label="Siguiente">
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

    <!-- Modal para agregar nueva cuenta -->
    <div class="modal fade" id="addAccountModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-plus-circle me-2"></i> Agregar Nueva Cuenta
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="usuario_id" value="<?= $usuario_id ?>">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="nombre" class="form-label">Nombre de la Cuenta</label>
                            <input type="text" class="form-control" id="nombre" name="nombre" placeholder="Ej: Cuenta Corriente, Efectivo, etc." required>
                        </div>
                        <div class="mb-3">
                            <label for="saldo" class="form-label">Saldo Inicial (Guaraníes)</label>
                            <input type="text" class="form-control" id="saldo" name="saldo" placeholder="Ej: 1.000.000" required>
                            <small class="text-muted">Ingrese el saldo inicial en guaraníes. Use puntos para separar miles.</small>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="activa" name="activa" checked>
                            <label class="form-check-label" for="activa">
                                Cuenta Activa
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" name="create">
                            <i class="bi bi-save me-1"></i> Guardar Cuenta
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal para editar cuenta -->
    <div class="modal fade" id="editAccountModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil me-2"></i> Editar Cuenta
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="usuario_id" value="<?= $usuario_id ?>">
                    <input type="hidden" id="edit_id" name="id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_nombre" class="form-label">Nombre de la Cuenta</label>
                            <input type="text" class="form-control" id="edit_nombre" name="nombre" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_saldo" class="form-label">Saldo (Guaraníes)</label>
                            <input type="text" class="form-control" id="edit_saldo" name="saldo" required>
                            <small class="text-muted">Saldo actual en guaraníes. Use puntos para separar miles.</small>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="edit_activa" name="activa">
                            <label class="form-check-label" for="edit_activa">
                                Cuenta Activa
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" name="update">
                            <i class="bi bi-save me-1"></i> Actualizar Cuenta
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
            // Manejar edición de cuentas
            document.querySelectorAll('.edit-btn').forEach(button => {
                button.addEventListener('click', function() {
                    document.getElementById('edit_id').value = this.dataset.id;
                    document.getElementById('edit_nombre').value = this.dataset.nombre;
                    
                    // Formatear saldo para mostrar con separadores de miles
                    const saldo = parseFloat(this.dataset.saldo);
                    document.getElementById('edit_saldo').value = saldo.toLocaleString('es-PY');
                    
                    document.getElementById('edit_activa').checked = this.dataset.activa === '1';
                });
            });

            // Formatear input de saldo para aceptar solo números y puntos
            document.querySelectorAll('input[name="saldo"], input[name="edit_saldo"]').forEach(input => {
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
                    const saldoInput = this.querySelector('input[name="saldo"], input[name="edit_saldo"]');
                    if (saldoInput) {
                        // Limpiar el valor para enviar solo números
                        saldoInput.value = saldoInput.value.replace(/\./g, '');
                    }
                });
            });

            // Auto-submit del filtro cuando cambia
            document.getElementById('activa').addEventListener('change', function() {
                this.form.submit();
            });
        });
    </script>
</body>
</html>