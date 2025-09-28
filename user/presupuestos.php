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
        if (isset($filters['notificacion'])) {
            $query .= " AND p.notificacion = :notificacion";
            $params[':notificacion'] = $filters['notificacion'];
        }

        $query .= " GROUP BY p.id, u.nombre_usuario, c.nombre, c.color, c.tipo";

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
    SELECT id, nombre, color, tipo 
    FROM categorias 
    WHERE usuario_id = :usuario_id AND tipo = 'gasto'
    ORDER BY nombre
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
        'periodo' => $_POST["periodo"] ?? "mensual",
        'fecha_inicio' => $_POST["fecha_inicio"] ?? null,
        'fecha_fin' => $_POST["fecha_fin"] ?? null,
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
                $_SESSION['error'] = 'Ya existe un presupuesto activo para esta categoría';
            } else {
                $budgetRepo->create($data);
                $_SESSION['success'] = 'Presupuesto creado exitosamente';
            }
        }
        if (isset($_POST["update"]) && isset($_POST["id"])) {
            $budgetRepo->update($_POST["id"], $data);
            $_SESSION['success'] = 'Presupuesto actualizado exitosamente';
        }
    } catch (Exception $e) {
        $error = 'Error al procesar la operación: ' . $e->getMessage();
    }
    
    header("Location: " . $_SERVER["PHP_SELF"], true, 303);
    exit();
}

// Eliminar presupuesto
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["delete"])) {
    $id = intval($_GET["delete"]);
    $budgetRepo->delete($id, $usuario_id);
    $_SESSION['success'] = 'Presupuesto eliminado exitosamente';
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
    'periodo' => $_GET['periodo'] ?? '',
    'notificacion' => isset($_GET['notificacion']) ? (int)$_GET['notificacion'] : null
];

// Configuración de paginación
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 5;
$offset = ($page - 1) * $perPage;

// Obtener presupuestos con filtros y paginación
$result = $budgetRepo->getAll($usuario_id, $filters, $perPage, $offset);
$presupuestos = $result['data'];
$totalPresupuestos = $result['total'];
$totalPages = ceil($totalPresupuestos / $perPage);

// Obtener estadísticas totales
$stats = $budgetRepo->getTotalStats($usuario_id);
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
        .progress-container {
            height: 8px;
            background-color: #e9ecef;
            border-radius: 4px;
            margin-top: 0.5rem;
            overflow: hidden;
        }
        .progress-bar {
            height: 100%;
            border-radius: 4px;
            transition: width 0.6s ease;
        }
        .progress-success {
            background-color: var(--bs-success);
        }
        .progress-warning {
            background-color: var(--bs-warning);
        }
        .progress-danger {
            background-color: var(--bs-danger);
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
        .stats-card {
            border-left: 4px solid var(--bs-primary);
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
                        <a class="nav-link active" href="presupuestos.php">
                            <i class="bi bi-pie-chart me-1"></i> Presupuestos
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="transacciones.php">
                            <i class="bi bi-arrow-left-right me-1"></i> Transacciones
                        </a>
                    </li>
                </ul>
                <div class="d-flex">
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
                <h1 class="mb-1">Mis Presupuestos</h1>
                <p class="text-muted mb-0">Controla tus gastos planificados</p>
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

        <!-- Estadísticas -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title text-muted mb-1">Total Presupuestos</h6>
                                <h3 class="text-primary mb-0"><?= $stats['total_presupuestos'] ?></h3>
                            </div>
                            <div class="text-primary">
                                <i class="bi bi-pie-chart fs-2"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stats-card success">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title text-muted mb-1">Total Presupuestado</h6>
                                <h3 class="text-success mb-0"><?= formatMoney($stats['total_presupuestado']) ?></h3>
                            </div>
                            <div class="text-success">
                                <i class="bi bi-cash-coin fs-2"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stats-card danger">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title text-muted mb-1">Total Gastado</h6>
                                <h3 class="text-danger mb-0"><?= formatMoney($stats['total_gastado']) ?></h3>
                            </div>
                            <div class="text-danger">
                                <i class="bi bi-graph-up-arrow fs-2"></i>
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
                        <label for="notificacion" class="form-label">Notificación</label>
                        <select class="form-select" id="notificacion" name="notificacion">
                            <option value="">Todos</option>
                            <option value="1" <?= ($filters['notificacion'] === 1) ? 'selected' : '' ?>>Con notificación</option>
                            <option value="0" <?= ($filters['notificacion'] === 0) ? 'selected' : '' ?>>Sin notificación</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-funnel me-1"></i> Filtrar
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Lista de presupuestos -->
        <div class="card">
            <div class="card-body">
                <?php if (empty($presupuestos)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-pie-chart display-4 text-muted mb-3"></i>
                        <h3 class="mb-2">No se encontraron presupuestos</h3>
                        <p class="text-muted mb-4">No hay presupuestos que coincidan con los filtros seleccionados</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBudgetModal">
                            <i class="bi bi-plus-circle me-1"></i> Agregar Presupuesto
                        </button>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Categoría</th>
                                    <th>Presupuesto</th>
                                    <th>Gastado</th>
                                    <th>Progreso</th>
                                    <th>Período</th>
                                    <th>Vigencia</th>
                                    <th>Notificación</th>
                                    <th class="text-end">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($presupuestos as $presupuesto):
                                    $progreso = $presupuesto["monto"] > 0 ? min(100, ($presupuesto["gastos_actuales"] / $presupuesto["monto"]) * 100) : 0;
                                    $progresoClase = $progreso > 90 ? 'progress-danger' :
                                                    ($progreso > 70 ? 'progress-warning' : 'progress-success');
                                    $restante = $presupuesto["monto"] - $presupuesto["gastos_actuales"];
                                ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <?php if (!empty($presupuesto['categoria_color'])): ?>
                                                <span class="category-icon" style="background-color: <?= htmlspecialchars($presupuesto['categoria_color']) ?>">
                                                    <i class="bi bi-tag"></i>
                                                </span>
                                            <?php endif; ?>
                                            <div>
                                                <strong><?= htmlspecialchars($presupuesto["categoria_nombre"]) ?></strong>
                                                <br>
                                                <small class="text-muted">Restante: <?= formatMoney($restante) ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="fw-bold"><?= formatMoney($presupuesto["monto"]) ?></span>
                                    </td>
                                    <td>
                                        <span class="<?= $presupuesto["gastos_actuales"] > 0 ? 'text-danger' : 'text-muted' ?>">
                                            <?= formatMoney($presupuesto["gastos_actuales"]) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="progress-container">
                                            <div class="progress-bar <?= $progresoClase ?>" style="width: <?= $progreso ?>%">
                                                <span class="visually-hidden"><?= round($progreso) ?>%</span>
                                            </div>
                                        </div>
                                        <small class="text-muted"><?= round($progreso) ?>%</small>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary badge-custom">
                                            <?= htmlspecialchars($periodos[$presupuesto["periodo"]] ?? $presupuesto["periodo"]) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small>
                                            <?= date("d/m/Y", strtotime($presupuesto["fecha_inicio"])) ?>
                                            <?php if ($presupuesto["fecha_fin"]): ?>
                                                <br>al <?= date("d/m/Y", strtotime($presupuesto["fecha_fin"])) ?>
                                            <?php else: ?>
                                                <br><span class="text-muted">Sin fecha fin</span>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php if ($presupuesto["notificacion"]): ?>
                                            <span class="badge bg-success badge-custom">
                                                <i class="bi bi-bell-fill me-1"></i> Activa
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary badge-custom">
                                                <i class="bi bi-bell-slash me-1"></i> Inactiva
                                            </span>
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
                                                       href="?delete=<?= $presupuesto["id"] ?>&page=<?= $page ?>&categoria_id=<?= $filters['categoria_id'] ?>&periodo=<?= $filters['periodo'] ?>&notificacion=<?= $filters['notificacion'] ?>"
                                                       onclick="return confirm('¿Estás seguro de eliminar este presupuesto?\n\nEsta acción no se puede deshacer.')">
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
                                    <a class="page-link" href="?page=<?= $page - 1 ?>&categoria_id=<?= $filters['categoria_id'] ?>&periodo=<?= $filters['periodo'] ?>&notificacion=<?= $filters['notificacion'] ?>" aria-label="Anterior">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>&categoria_id=<?= $filters['categoria_id'] ?>&periodo=<?= $filters['periodo'] ?>&notificacion=<?= $filters['notificacion'] ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $page + 1 ?>&categoria_id=<?= $filters['categoria_id'] ?>&periodo=<?= $filters['periodo'] ?>&notificacion=<?= $filters['notificacion'] ?>" aria-label="Siguiente">
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
                            <label for="categoria_id" class="form-label">Categoría</label>
                            <select class="form-select" id="categoria_id" name="categoria_id" required>
                                <option value="">Seleccionar Categoría</option>
                                <?php foreach ($categorias as $categoria): ?>
                                    <option value="<?= $categoria["id"] ?>"><?= htmlspecialchars($categoria["nombre"]) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Solo se muestran categorías de gastos</small>
                        </div>
                        <div class="mb-3">
                            <label for="monto" class="form-label">Monto Presupuestado (Guaraníes)</label>
                            <input type="text" class="form-control" id="monto" name="monto" placeholder="Ej: 1.000.000" required>
                            <small class="text-muted">Ingrese el monto en guaraníes. Use puntos para separar miles.</small>
                        </div>
                        <div class="mb-3">
                            <label for="periodo" class="form-label">Período</label>
                            <select class="form-select" id="periodo" name="periodo" required>
                                <option value="">Seleccionar Período</option>
                                <?php foreach ($periodos as $codigo => $nombre): ?>
                                    <option value="<?= $codigo ?>"><?= htmlspecialchars($nombre) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="fecha_inicio" class="form-label">Fecha Inicio</label>
                                <input class="form-control" type="date" id="fecha_inicio" name="fecha_inicio" required>
                            </div>
                            <div class="col-md-6">
                                <label for="fecha_fin" class="form-label">Fecha Fin (Opcional)</label>
                                <input class="form-control" type="date" id="fecha_fin" name="fecha_fin">
                                <small class="text-muted">Dejar vacío para presupuesto indefinido</small>
                            </div>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="notificacion" name="notificacion" checked>
                            <label class="form-check-label" for="notificacion">
                                Activar notificaciones cuando se acerque al límite
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
                            <label for="edit_monto" class="form-label">Monto Presupuestado (Guaraníes)</label>
                            <input type="text" class="form-control" id="edit_monto" name="monto" required>
                            <small class="text-muted">Ingrese el monto en guaraníes. Use puntos para separar miles.</small>
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
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="edit_notificacion" name="notificacion">
                            <label class="form-check-label" for="edit_notificacion">
                                Activar notificaciones cuando se acerque al límite
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
            document.querySelectorAll('input[name="monto"], input[name="edit_monto"]').forEach(input => {
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
            const fechaInicio = document.getElementById('fecha_inicio');
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
        });
    </script>
</body>
</html>