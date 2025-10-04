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
    // Remover caracteres no numéricos excepto puntos
    $clean = preg_replace('/[^\d.]/', '', $input);
    // Convertir a float y luego a centavos
    return (int)round(floatval(str_replace('.', '', $clean)) * 100);
}

// Clase para manejar presupuestos
class BudgetRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function getAll($userId, $filters = [], $limit = 10, $offset = 0) {
        $query = "
            SELECT
                p.*,
                u.nombre_usuario,
                c.nombre AS categoria_nombre,
                c.color AS categoria_color,
                c.tipo AS categoria_tipo,
                c.icono AS categoria_icono,
                COALESCE(SUM(CASE WHEN c.tipo = 'gasto' THEN t.monto ELSE 0 END), 0) AS gastos_actuales
            FROM
                presupuestos p
            INNER JOIN
                usuarios u ON p.usuario_id = u.id
            INNER JOIN
                categorias c ON p.categoria_id = c.id
            LEFT JOIN
                transacciones t ON p.categoria_id = t.categoria_id
                AND t.fecha BETWEEN p.fecha_inicio AND COALESCE(p.fecha_fin, CURDATE())
            WHERE
                p.usuario_id = :usuario_id
        ";

        $params = [':usuario_id' => $userId];

        // Aplicar filtros
        if (!empty($filters['categoria_id'])) {
            $query .= " AND p.categoria_id = :categoria_id";
            $params[':categoria_id'] = $filters['categoria_id'];
        }
        if (!empty($filters['periodo'])) {
            $query .= " AND p.periodo = :periodo";
            $params[':periodo'] = $filters['periodo'];
        }
        if (isset($filters['estado'])) {
            if ($filters['estado'] === 'activo') {
                $query .= " AND (p.fecha_fin IS NULL OR p.fecha_fin >= CURDATE())";
            } elseif ($filters['estado'] === 'expirado') {
                $query .= " AND p.fecha_fin < CURDATE()";
            }
        }

        $query .= " GROUP BY p.id, u.nombre_usuario, c.nombre, c.color, c.tipo, c.icono";

        // Contar total para paginación
        $countQuery = "SELECT COUNT(*) FROM ($query) AS filtered";
        $countStmt = $this->db->prepare($countQuery);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $total = $countStmt->fetchColumn();

        // Aplicar orden y paginación
        $query .= " ORDER BY p.fecha_inicio DESC LIMIT :limit OFFSET :offset";
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

    public function getTotalStats($userId) {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_presupuestos,
                COUNT(CASE WHEN fecha_fin IS NULL OR fecha_fin >= CURDATE() THEN 1 END) as presupuestos_activos,
                COUNT(CASE WHEN fecha_fin < CURDATE() THEN 1 END) as presupuestos_expirados,
                COALESCE(SUM(p.monto), 0) as total_presupuestado,
                COALESCE(SUM(
                    CASE WHEN c.tipo = 'gasto' THEN 
                        (SELECT COALESCE(SUM(t.monto), 0) 
                         FROM transacciones t 
                         WHERE t.categoria_id = p.categoria_id 
                         AND t.fecha BETWEEN p.fecha_inicio AND COALESCE(p.fecha_fin, CURDATE()))
                    ELSE 0 END
                ), 0) as total_gastado
            FROM presupuestos p
            INNER JOIN categorias c ON p.categoria_id = c.id
            WHERE p.usuario_id = :usuario_id
        ");
        $stmt->execute([':usuario_id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getBudgetAlerts($userId) {
        $stmt = $this->db->prepare("
            SELECT 
                p.*,
                c.nombre as categoria_nombre,
                c.color as categoria_color,
                COALESCE(SUM(t.monto), 0) as gastos_actuales,
                (COALESCE(SUM(t.monto), 0) / p.monto) * 100 as porcentaje_uso
            FROM presupuestos p
            INNER JOIN categorias c ON p.categoria_id = c.id
            LEFT JOIN transacciones t ON p.categoria_id = t.categoria_id
                AND t.fecha BETWEEN p.fecha_inicio AND COALESCE(p.fecha_fin, CURDATE())
            WHERE p.usuario_id = :usuario_id
            AND p.notificacion = 1
            AND (p.fecha_fin IS NULL OR p.fecha_fin >= CURDATE())
            GROUP BY p.id, c.nombre, c.color
            HAVING porcentaje_uso >= 80
            ORDER BY porcentaje_uso DESC
            LIMIT 5
        ");
        $stmt->execute([':usuario_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function create($data) {
        $stmt = $this->db->prepare("
            INSERT INTO presupuestos
            (usuario_id, categoria_id, monto, periodo, fecha_inicio, fecha_fin, notificacion, creado_en, actualizado_en)
            VALUES
            (:usuario_id, :categoria_id, :monto, :periodo, :fecha_inicio, :fecha_fin, :notificacion, NOW(), NOW())
        ");
        return $stmt->execute($data);
    }

    public function update($id, $data) {
        $data['id'] = $id;
        $stmt = $this->db->prepare("
            UPDATE presupuestos SET
                categoria_id = :categoria_id,
                monto = :monto,
                periodo = :periodo,
                fecha_inicio = :fecha_inicio,
                fecha_fin = :fecha_fin,
                notificacion = :notificacion,
                actualizado_en = NOW()
            WHERE id = :id AND usuario_id = :usuario_id
        ");
        return $stmt->execute($data);
    }

    public function delete($id, $userId) {
        $stmt = $this->db->prepare("DELETE FROM presupuestos WHERE id = :id AND usuario_id = :usuario_id");
        return $stmt->execute(['id' => $id, 'usuario_id' => $userId]);
    }
}

// Períodos disponibles según esquema
$periodos = [
    "mensual" => "Mensual",
    "anual" => "Anual",
];

// Configuración inicial
$budgetRepo = new BudgetRepository($db);
$error = '';
$success = '';

// Obtener categorías del usuario (solo de gastos para presupuestos)
$stmtCategorias = $db->prepare("
    SELECT id, nombre, color, icono, tipo 
    FROM categorias 
    WHERE usuario_id = :usuario_id AND tipo = 'gasto'
    ORDER BY nombre
");
$stmtCategorias->bindValue(":usuario_id", $usuario_id, PDO::PARAM_INT);
$stmtCategorias->execute();
$categorias = $stmtCategorias->fetchAll();

// Obtener información del usuario para la moneda
$stmt = $db->prepare("SELECT moneda FROM usuarios WHERE id = ?");
$stmt->execute([$usuario_id]);
$user_currency = $stmt->fetchColumn();

// Procesar operaciones CRUD
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // CORRECCIÓN: Convertir fecha_fin vacía a NULL
    $fecha_fin = !empty($_POST["fecha_fin"]) ? $_POST["fecha_fin"] : null;
    
    $data = [
        'usuario_id' => $usuario_id,
        'categoria_id' => $_POST["categoria_id"] ?? null,
        'monto' => parseMoneyInput($_POST["monto"] ?? "0"),
        'periodo' => $_POST["periodo"] ?? "mensual",
        'fecha_inicio' => $_POST["fecha_inicio"] ?? null,
        'fecha_fin' => $fecha_fin, // Usamos la variable corregida
        'notificacion' => isset($_POST["notificacion"]) ? 1 : 0
    ];

    try {
        if (isset($_POST["create"]) && $data['categoria_id']) {
            // Validar que no exista un presupuesto activo para la misma categoría
            $stmtCheck = $db->prepare("
                SELECT COUNT(*) FROM presupuestos 
                WHERE usuario_id = :usuario_id 
                AND categoria_id = :categoria_id 
                AND (fecha_fin IS NULL OR fecha_fin >= CURDATE())
            ");
            $stmtCheck->execute([
                ':usuario_id' => $usuario_id,
                ':categoria_id' => $data['categoria_id']
            ]);
            
            if ($stmtCheck->fetchColumn() > 0) {
                $_SESSION['error'] = '❌ Ya existe un presupuesto activo para esta categoría';
            } else {
                if ($budgetRepo->create($data)) {
                    $_SESSION['success'] = '✅ Presupuesto creado exitosamente';
                } else {
                    $_SESSION['error'] = '❌ Error al crear el presupuesto';
                }
            }
        }
        if (isset($_POST["update"]) && isset($_POST["id"])) {
            if ($budgetRepo->update($_POST["id"], $data)) {
                $_SESSION['success'] = '✅ Presupuesto actualizado exitosamente';
            } else {
                $_SESSION['error'] = '❌ Error al actualizar el presupuesto';
            }
        }
    } catch (Exception $e) {
        $error = '❌ Error al procesar la operación: ' . $e->getMessage();
        $_SESSION['error'] = $error;
    }
    
    header("Location: " . $_SERVER["PHP_SELF"] . "?" . http_build_query($_GET));
    exit();
}

// Eliminar presupuesto
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["delete"])) {
    $id = intval($_GET["delete"]);
    if ($budgetRepo->delete($id, $usuario_id)) {
        $_SESSION['success'] = '✅ Presupuesto eliminado exitosamente';
    } else {
        $_SESSION['error'] = '❌ Error al eliminar el presupuesto';
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
    'periodo' => $_GET['periodo'] ?? '',
    'estado' => $_GET['estado'] ?? ''
];

// Configuración de paginación
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 8;
$offset = ($page - 1) * $perPage;

// Obtener presupuestos con filtros y paginación
$result = $budgetRepo->getAll($usuario_id, $filters, $perPage, $offset);
$presupuestos = $result['data'];
$totalPresupuestos = $result['total'];
$totalPages = ceil($totalPresupuestos / $perPage);

// Obtener estadísticas totales
$stats = $budgetRepo->getTotalStats($usuario_id);

// Obtener alertas de presupuesto
$budgetAlerts = $budgetRepo->getBudgetAlerts($usuario_id);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finzen | Mis Presupuestos</title>
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
        
        .stat-card.warning::before {
            background-color: var(--bs-warning);
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
        
        .progress-container {
            height: 12px;
            background-color: var(--bs-light);
            border-radius: 6px;
            margin-top: 0.5rem;
            overflow: hidden;
            position: relative;
        }
        
        .progress-bar {
            height: 100%;
            border-radius: 6px;
            transition: width 0.6s ease;
            position: relative;
        }
        
        .progress-success {
            background: linear-gradient(90deg, var(--bs-success), #20c997);
        }
        
        .progress-warning {
            background: linear-gradient(90deg, var(--bs-warning), #fd7e14);
        }
        
        .progress-danger {
            background: linear-gradient(90deg, var(--bs-danger), #e52535);
        }
        
        .progress-percentage {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 0.7rem;
            font-weight: 600;
            color: #333;
            text-shadow: 0 1px 1px rgba(255,255,255,0.8);
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
        
        .category-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            color: white;
            font-size: 1.25rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
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
        
        .budget-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .status-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        .status-safe {
            background-color: var(--bs-success);
        }
        
        .status-warning {
            background-color: var(--bs-warning);
        }
        
        .status-danger {
            background-color: var(--bs-danger);
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
        
        .restante-amount {
            font-size: 1.25rem;
            font-weight: 700;
            margin-top: 0.5rem;
        }
        
        .restante-safe {
            color: var(--bs-success);
        }
        
        .restante-warning {
            color: var(--bs-warning);
        }
        
        .restante-danger {
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
        
        .alert-budget {
            border-left: 4px solid var(--bs-warning);
            background: linear-gradient(135deg, #fff 0%, #fff9e6 100%);
        }
        
        .alert-budget.danger {
            border-left-color: var(--bs-danger);
            background: linear-gradient(135deg, #fff 0%, #ffe6e6 100%);
        }
        
        .budget-card {
            transition: all 0.3s ease;
            border: 1px solid transparent;
            position: relative;
            overflow: hidden;
        }
        
        .budget-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
        }
        
        .budget-card.safe::before {
            background-color: var(--bs-success);
        }
        
        .budget-card.warning::before {
            background-color: var(--bs-warning);
        }
        
        .budget-card.danger::before {
            background-color: var(--bs-danger);
        }
        
        .budget-card.expired::before {
            background-color: var(--bs-secondary);
        }
        
        .budget-card:hover {
            border-color: var(--bs-border);
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }
        
        .category-actions {
            position: absolute;
            top: 1rem;
            right: 1rem;
            opacity: 0;
            transition: all 0.3s ease;
        }
        
        .budget-card:hover .category-actions {
            opacity: 1;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .category-actions {
                opacity: 1;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
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
                        <a class="nav-link active" href="presupuestos.php">
                            <i class="bi bi-pie-chart me-2"></i> Presupuestos
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="transacciones.php">
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
                <h1 class="mb-1">Mis Presupuestos</h1>
                <p class="text-muted mb-0">Controla y planifica tus gastos mensuales</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBudgetModal">
                <i class="bi bi-plus-circle me-1"></i> Nuevo Presupuesto
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

        <!-- Alertas de Presupuesto -->
        <?php if (!empty($budgetAlerts)): ?>
            <div class="card mb-4 border-warning">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-3">
                        <i class="bi bi-exclamation-triangle-fill text-warning me-2 fs-4"></i>
                        <h5 class="mb-0">Alertas de Presupuesto</h5>
                    </div>
                    <?php foreach ($budgetAlerts as $alert): ?>
                        <div class="alert alert-budget <?= $alert['porcentaje_uso'] > 100 ? 'danger' : '' ?> d-flex align-items-center mb-2">
                            <div class="flex-grow-1">
                                <strong><?= htmlspecialchars($alert['categoria_nombre']) ?></strong> - 
                                <?= round($alert['porcentaje_uso']) ?>% usado 
                                (<?= formatMoney($alert['gastos_actuales'], $user_currency) ?> de <?= formatMoney($alert['monto'], $user_currency) ?>)
                            </div>
                            <?php if ($alert['porcentaje_uso'] > 100): ?>
                                <span class="badge bg-danger">Excedido</span>
                            <?php else: ?>
                                <span class="badge bg-warning">Cerca del límite</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Estadísticas Mejoradas -->
        <div class="stats-grid">
            <div class="stat-card primary">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-value text-primary"><?= $stats['total_presupuestos'] ?></div>
                        <div class="stat-label">Total Presupuestos</div>
                        <small class="text-muted"><?= $stats['presupuestos_activos'] ?> activos</small>
                    </div>
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-pie-chart"></i>
                    </div>
                </div>
            </div>
            
            <div class="stat-card success">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-value text-success"><?= formatMoney($stats['total_presupuestado'], $user_currency) ?></div>
                        <div class="stat-label">Total Presupuestado</div>
                        <small class="text-muted">Presupuesto asignado</small>
                    </div>
                    <div class="stat-icon bg-success bg-opacity-10 text-success">
                        <i class="bi bi-cash-coin"></i>
                    </div>
                </div>
            </div>
            
            <div class="stat-card danger">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-value text-danger"><?= formatMoney($stats['total_gastado'], $user_currency) ?></div>
                        <div class="stat-label">Total Gastado</div>
                        <small class="text-muted">De presupuestos activos</small>
                    </div>
                    <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card warning">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-value text-warning"><?= $stats['presupuestos_expirados'] ?></div>
                        <div class="stat-label">Presupuestos Expirados</div>
                        <small class="text-muted">Necesitan renovación</small>
                    </div>
                    <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                        <i class="bi bi-clock-history"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtros Mejorados -->
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
                    <div class="col-md-3">
                        <label for="periodo" class="form-label">Período</label>
                        <select class="form-select" id="periodo" name="periodo">
                            <option value="">Todos los períodos</option>
                            <?php foreach ($periodos as $codigo => $nombre): ?>
                                <option value="<?= $codigo ?>" <?= ($filters['periodo'] === $codigo) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($nombre) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="estado" class="form-label">Estado</label>
                        <select class="form-select" id="estado" name="estado">
                            <option value="">Todos los estados</option>
                            <option value="activo" <?= ($filters['estado'] === 'activo') ? 'selected' : '' ?>>Activos</option>
                            <option value="expirado" <?= ($filters['estado'] === 'expirado') ? 'selected' : '' ?>>Expirados</option>
                        </select>
                    </div>
                    <div class="col-md-3 text-md-end">
                        <span class="badge bg-primary badge-custom">
                            <?= $totalPresupuestos ?> presupuesto<?= $totalPresupuestos !== 1 ? 's' : '' ?>
                        </span>
                    </div>
                </div>
            </form>
        </div>

        <!-- Lista de presupuestos en cards -->
        <div class="card">
            <div class="card-body">
                <?php if (empty($presupuestos)): ?>
                    <div class="empty-state">
                        <i class="bi bi-pie-chart"></i>
                        <h3 class="mb-2">No se encontraron presupuestos</h3>
                        <p class="text-muted mb-4">No hay presupuestos que coincidan con los filtros seleccionados</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBudgetModal">
                            <i class="bi bi-plus-circle me-1"></i> Agregar Presupuesto
                        </button>
                    </div>
                <?php else: ?>
                    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4 mb-4">
                        <?php foreach ($presupuestos as $presupuesto):
                            $progreso = $presupuesto["monto"] > 0 ? min(100, ($presupuesto["gastos_actuales"] / $presupuesto["monto"]) * 100) : 0;
                            $progresoClase = $progreso > 90 ? 'progress-danger' :
                                            ($progreso > 70 ? 'progress-warning' : 'progress-success');
                            $statusClase = $progreso > 90 ? 'status-danger' :
                                         ($progreso > 70 ? 'status-warning' : 'status-safe');
                            $restante = $presupuesto["monto"] - $presupuesto["gastos_actuales"];
                            $restanteClase = $progreso > 90 ? 'restante-danger' :
                                           ($progreso > 70 ? 'restante-warning' : 'restante-safe');
                            
                            // Determinar si el presupuesto está expirado
                            $isExpired = $presupuesto["fecha_fin"] && strtotime($presupuesto["fecha_fin"]) < time();
                            $cardClass = $isExpired ? 'expired' : ($progreso > 90 ? 'danger' : ($progreso > 70 ? 'warning' : 'safe'));
                        ?>
                        <div class="col">
                            <div class="card budget-card h-100 <?= $cardClass ?>">
                                <div class="card-body">
                                    <div class="category-actions">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary btn-action" type="button" data-bs-toggle="dropdown">
                                                <i class="bi bi-three-dots-vertical"></i>
                                            </button>
                                            <ul class="dropdown-menu">
                                                <li>
                                                    <button class="dropdown-item edit-btn"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#editBudgetModal"
                                                            data-id="<?= $presupuesto["id"] ?>"
                                                            data-categoria_id="<?= $presupuesto["categoria_id"] ?>"
                                                            data-monto="<?= $presupuesto["monto"] / 100 ?>"
                                                            data-periodo="<?= $presupuesto["periodo"] ?>"
                                                            data-fecha_inicio="<?= $presupuesto["fecha_inicio"] ?>"
                                                            data-fecha_fin="<?= $presupuesto["fecha_fin"] ?>"
                                                            data-notificacion="<?= $presupuesto["notificacion"] ?>">
                                                        <i class="bi bi-pencil me-2"></i> Editar
                                                    </button>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item text-danger" 
                                                       href="?<?= http_build_query(array_merge($_GET, ['delete' => $presupuesto["id"]])) ?>"
                                                       onclick="return confirm('¿Estás seguro de eliminar este presupuesto?\n\nEsta acción no se puede deshacer.')">
                                                        <i class="bi bi-trash me-2"></i> Eliminar
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                    
                                    <div class="text-center mb-3">
                                        <div class="category-icon" style="background-color: <?= htmlspecialchars($presupuesto['categoria_color']) ?>">
                                            <i class="bi <?= htmlspecialchars($presupuesto['categoria_icono'] ?? 'bi-tag') ?>"></i>
                                        </div>
                                        <h5 class="card-title mb-2"><?= htmlspecialchars($presupuesto["categoria_nombre"]) ?></h5>
                                        <div class="mb-2">
                                            <span class="badge <?= $presupuesto["periodo"] === 'anual' ? 'bg-info' : 'bg-primary' ?> badge-custom">
                                                <?= htmlspecialchars($periodos[$presupuesto["periodo"]] ?? $presupuesto["periodo"]) ?>
                                            </span>
                                            <?php if ($isExpired): ?>
                                                <span class="badge bg-secondary badge-custom">Expirado</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="text-center mb-3">
                                        <div class="h4 fw-bold text-primary"><?= formatMoney($presupuesto["monto"], $user_currency) ?></div>
                                        <small class="text-muted">Presupuesto total</small>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between mb-1">
                                            <small class="text-muted">Gastado: <?= formatMoney($presupuesto["gastos_actuales"], $user_currency) ?></small>
                                            <small class="fw-bold"><?= round($progreso) ?>%</small>
                                        </div>
                                        <div class="progress-container">
                                            <div class="progress-bar <?= $progresoClase ?>" style="width: <?= $progreso ?>%"></div>
                                        </div>
                                    </div>
                                    
                                    <div class="text-center">
                                        <div class="restante-amount <?= $restanteClase ?>">
                                            <?= formatMoney($restante, $user_currency) ?>
                                        </div>
                                        <small class="text-muted">Restante</small>
                                    </div>
                                    
                                    <div class="text-center mt-3">
                                        <small class="text-muted">
                                            <i class="bi bi-calendar me-1"></i>
                                            <?= date("d/m/Y", strtotime($presupuesto["fecha_inicio"])) ?>
                                            <?php if ($presupuesto["fecha_fin"]): ?>
                                                - <?= date("d/m/Y", strtotime($presupuesto["fecha_fin"])) ?>
                                            <?php else: ?>
                                                (Sin fecha fin)
                                            <?php endif; ?>
                                        </small>
                                        <?php if ($presupuesto["notificacion"]): ?>
                                            <br>
                                            <span class="badge bg-success badge-custom mt-1">
                                                <i class="bi bi-bell-fill me-1"></i> Notificaciones
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
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

    <!-- Modal para agregar nuevo presupuesto -->
    <div class="modal fade" id="addBudgetModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-plus-circle me-2"></i> Agregar Nuevo Presupuesto
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="usuario_id" value="<?= $usuario_id ?>">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="add_categoria_id" class="form-label">Categoría</label>
                            <select class="form-select" id="add_categoria_id" name="categoria_id" required>
                                <option value="">Seleccionar Categoría</option>
                                <?php foreach ($categorias as $categoria): ?>
                                    <option value="<?= $categoria["id"] ?>"><?= htmlspecialchars($categoria["nombre"]) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Solo se muestran categorías de gastos</small>
                        </div>
                        <div class="mb-3">
                            <label for="add_monto" class="form-label">Monto Presupuestado (<?= $user_currency ?>)</label>
                            <input type="text" class="form-control" id="add_monto" name="monto" placeholder="Ej: 1.000.000" required>
                            <small class="text-muted">Ingrese el monto. Use puntos para separar miles.</small>
                        </div>
                        <div class="mb-3">
                            <label for="add_periodo" class="form-label">Período</label>
                            <select class="form-select" id="add_periodo" name="periodo" required>
                                <option value="">Seleccionar Período</option>
                                <?php foreach ($periodos as $codigo => $nombre): ?>
                                    <option value="<?= $codigo ?>"><?= htmlspecialchars($nombre) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="add_fecha_inicio" class="form-label">Fecha Inicio</label>
                                <input class="form-control" type="date" id="add_fecha_inicio" name="fecha_inicio" required>
                            </div>
                            <div class="col-md-6">
                                <label for="add_fecha_fin" class="form-label">Fecha Fin (Opcional)</label>
                                <input class="form-control" type="date" id="add_fecha_fin" name="fecha_fin">
                                <small class="text-muted">Dejar vacío para presupuesto indefinido</small>
                            </div>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="add_notificacion" name="notificacion" checked>
                            <label class="form-check-label" for="add_notificacion">
                                <i class="bi bi-bell me-1"></i>Activar notificaciones cuando se acerque al límite
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" name="create">
                            <i class="bi bi-save me-1"></i> Guardar Presupuesto
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal para editar presupuesto -->
    <div class="modal fade" id="editBudgetModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil me-2"></i> Editar Presupuesto
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="usuario_id" value="<?= $usuario_id ?>">
                    <input type="hidden" id="edit_id" name="id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_categoria_id" class="form-label">Categoría</label>
                            <select class="form-select" id="edit_categoria_id" name="categoria_id" required>
                                <option value="">Seleccionar Categoría</option>
                                <?php foreach ($categorias as $categoria): ?>
                                    <option value="<?= $categoria["id"] ?>"><?= htmlspecialchars($categoria["nombre"]) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_monto" class="form-label">Monto Presupuestado (<?= $user_currency ?>)</label>
                            <input type="text" class="form-control" id="edit_monto" name="monto" required>
                            <small class="text-muted">Ingrese el monto. Use puntos para separar miles.</small>
                        </div>
                        <div class="mb-3">
                            <label for="edit_periodo" class="form-label">Período</label>
                            <select class="form-select" id="edit_periodo" name="periodo" required>
                                <option value="">Seleccionar Período</option>
                                <?php foreach ($periodos as $codigo => $nombre): ?>
                                    <option value="<?= $codigo ?>"><?= htmlspecialchars($nombre) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="edit_fecha_inicio" class="form-label">Fecha Inicio</label>
                                <input class="form-control" type="date" id="edit_fecha_inicio" name="fecha_inicio" required>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_fecha_fin" class="form-label">Fecha Fin (Opcional)</label>
                                <input class="form-control" type="date" id="edit_fecha_fin" name="fecha_fin">
                                <small class="text-muted">Dejar vacío para presupuesto indefinido</small>
                            </div>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="edit_notificacion" name="notificacion">
                            <label class="form-check-label" for="edit_notificacion">
                                <i class="bi bi-bell me-1"></i>Activar notificaciones cuando se acerque al límite
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" name="update">
                            <i class="bi bi-save me-1"></i> Actualizar Presupuesto
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
            // Manejar edición de presupuestos
            document.querySelectorAll('.edit-btn').forEach(button => {
                button.addEventListener('click', function() {
                    document.getElementById('edit_id').value = this.dataset.id;
                    document.getElementById('edit_categoria_id').value = this.dataset.categoria_id;
                    
                    // Formatear monto para mostrar con separadores de miles
                    const monto = parseFloat(this.dataset.monto);
                    document.getElementById('edit_monto').value = monto.toLocaleString('es-PY');
                    
                    document.getElementById('edit_periodo').value = this.dataset.periodo;
                    document.getElementById('edit_fecha_inicio').value = this.dataset.fecha_inicio;
                    document.getElementById('edit_fecha_fin').value = this.dataset.fecha_fin || '';
                    document.getElementById('edit_notificacion').checked = this.dataset.notificacion === '1';
                });
            });

            // Formatear input de monto para aceptar solo números y puntos
            document.querySelectorAll('input[name="monto"]').forEach(input => {
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

            // Configurar fecha de inicio por defecto
            const fechaInicio = document.getElementById('add_fecha_inicio');
            if (fechaInicio && !fechaInicio.value) {
                fechaInicio.value = new Date().toISOString().split('T')[0];
            }

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

            // Auto-submit de filtros
            document.querySelectorAll('#categoria_id, #periodo, #estado').forEach(select => {
                select.addEventListener('change', function() {
                    document.getElementById('filtersForm').submit();
                });
            });
        });
    </script>
</body>
</html>