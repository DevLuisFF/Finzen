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
    return '₲ ' . number_format($amount / 100, 0, ',', '.');
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
                COALESCE(SUM(t.monto), 0) AS gastos_actuales
            FROM
                presupuestos p
            LEFT JOIN
                usuarios u ON p.usuario_id = u.id
            LEFT JOIN
                categorias c ON p.categoria_id = c.id
            LEFT JOIN
                transacciones t ON p.categoria_id = t.categoria_id
                AND t.fecha BETWEEN p.fecha_inicio AND IFNULL(p.fecha_fin, CURDATE())
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
        if (!empty($filters['notificacion'])) {
            $query .= " AND p.notificacion = :notificacion";
            $params[':notificacion'] = $filters['notificacion'];
        }

        // Contar total para paginación
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM ($query) AS filtered");
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $total = $countStmt->fetchColumn();

        // Aplicar orden y paginación
        $query .= " GROUP BY p.id ORDER BY p.fecha_inicio DESC LIMIT :limit OFFSET :offset";
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

// Períodos disponibles
$periodos = [
    "mensual" => "Mensual",
    "anual" => "Anual",
];

// Configuración inicial
$budgetRepo = new BudgetRepository($db);

// Obtener categorías del usuario
$stmtCategorias = $db->prepare("SELECT id, nombre FROM categorias WHERE usuario_id = :usuario_id");
$stmtCategorias->bindValue(":usuario_id", $usuario_id, PDO::PARAM_INT);
$stmtCategorias->execute();
$categorias = $stmtCategorias->fetchAll();

// Procesar operaciones CRUD
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $monto = str_replace(['₲', '.', ' '], '', $_POST["monto"] ?? '0');
    $data = [
        'usuario_id' => $usuario_id,
        'categoria_id' => $_POST["categoria_id"] ?? null,
        'monto' => intval($monto) * 100,
        'periodo' => $_POST["periodo"] ?? "mensual",
        'fecha_inicio' => $_POST["fecha_inicio"] ?? null,
        'fecha_fin' => $_POST["fecha_fin"] ?? null,
        'notificacion' => isset($_POST["notificacion"]) ? 1 : 0
    ];

    if (isset($_POST["create"]) && $data['categoria_id']) {
        $budgetRepo->create($data);
    }
    if (isset($_POST["update"]) && isset($_POST["id"])) {
        $budgetRepo->update($_POST["id"], $data);
    }
    header("Location: " . $_SERVER["PHP_SELF"], true, 303);
    exit();
}

// Eliminar presupuesto
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["delete"])) {
    $id = intval($_GET["delete"]);
    $budgetRepo->delete($id, $usuario_id);
    header("Location: " . $_SERVER["PHP_SELF"], true, 303);
    exit();
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
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            height: 6px;
            background-color: #e9ecef;
            border-radius: 3px;
            margin-top: 0.5rem;
            overflow: hidden;
        }
        .progress-bar {
            height: 100%;
            border-radius: 3px;
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
        .form-select-custom {
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M2 5l6 6 6-6'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 16px 12px;
        }
        .category-icon {
            width: 24px;
            height: 24px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 8px;
            color: white;
            font-size: 0.8rem;
        }
        @media (max-width: 768px) {
            .navbar-nav {
                flex-direction: row;
            }
            .nav-item {
                margin-right: 0.5rem;
            }
            .budget-card {
                margin-bottom: 1rem;
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
                <p class="text-muted mb-0">Controla tus gastos e ingresos planificados</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBudgetModal">
                <i class="bi bi-plus-circle me-1"></i> Nuevo Presupuesto
            </button>
        </div>

        <!-- Filtros -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" action="" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label for="categoria_id" class="form-label">Categoría</label>
                        <select class="form-select form-select-custom" id="categoria_id" name="categoria_id">
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
                        <select class="form-select form-select-custom" id="periodo" name="periodo">
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
                        <select class="form-select form-select-custom" id="notificacion" name="notificacion">
                            <option value="">Todos</option>
                            <option value="1" <?= ($filters['notificacion'] === 1) ? 'selected' : '' ?>>Con notificación</option>
                            <option value="0" <?= ($filters['notificacion'] === 0) ? 'selected' : '' ?>>Sin notificación</option>
                        </select>
                    </div>
                    <div class="col-md-2">
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
                        <i class="bi bi-pie-chart-fill display-4 text-muted mb-3"></i>
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
                                    $progreso = min(100, ($presupuesto["gastos_actuales"] / $presupuesto["monto"]) * 100);
                                    $progresoClase = $progreso > 90 ? 'progress-danger' :
                                                    ($progreso > 70 ? 'progress-warning' : 'progress-success');
                                ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <?php if (!empty($presupuesto['categoria_color'])): ?>
                                                <span class="category-icon" style="background-color: <?= htmlspecialchars($presupuesto['categoria_color']) ?>">
                                                    <i class="fas fa-tag"></i>
                                                </span>
                                            <?php endif; ?>
                                            <strong><?= htmlspecialchars($presupuesto["categoria_nombre"] ?? "Sin categoría") ?></strong>
                                        </div>
                                    </td>
                                    <td><?= formatMoney($presupuesto["monto"]) ?></td>
                                    <td><?= formatMoney($presupuesto["gastos_actuales"]) ?></td>
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
                                        <?= date("d/m/Y", strtotime($presupuesto["fecha_inicio"])) ?> -
                                        <?= $presupuesto["fecha_fin"] ? date("d/m/Y", strtotime($presupuesto["fecha_fin"])) : 'Indefinido' ?>
                                    </td>
                                    <td>
                                        <?php if ($presupuesto["notificacion"]): ?>
                                            <span class="badge bg-primary badge-custom">
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
                                                            data-monto="<?= formatMoney($presupuesto["monto"]) ?>"
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
                                                       onclick="return confirm('¿Estás seguro de eliminar este presupuesto?')">
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
                            <select class="form-select form-select-custom" id="categoria_id" name="categoria_id" required>
                                <option value="">Seleccionar Categoría</option>
                                <?php foreach ($categorias as $categoria): ?>
                                    <option value="<?= $categoria["id"] ?>"><?= htmlspecialchars($categoria["nombre"]) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="monto" class="form-label">Monto Presupuestado</label>
                            <div class="input-group">
                                <span class="input-group-text">₲</span>
                                <input type="text" class="form-control" id="monto" name="monto" placeholder="Ej: 1.000.000" required>
                            </div>
                            <small class="text-muted">Formato: 1.000.000</small>
                        </div>
                        <div class="mb-3">
                            <label for="periodo" class="form-label">Período</label>
                            <select class="form-select form-select-custom" id="periodo" name="periodo" required>
                                <option value="">Seleccionar Período</option>
                                <?php foreach ($periodos as $codigo => $nombre): ?>
                                    <option value="<?= $codigo ?>"><?= htmlspecialchars($nombre) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="fecha_inicio" class="form-label">Fecha Inicio</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-calendar"></i></span>
                                    <input class="form-control" type="date" id="fecha_inicio" name="fecha_inicio" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="fecha_fin" class="form-label">Fecha Fin</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-calendar"></i></span>
                                    <input class="form-control" type="date" id="fecha_fin" name="fecha_fin">
                                </div>
                            </div>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="notificacion" name="notificacion" checked>
                            <label class="form-check-label" for="notificacion">
                                Activar notificaciones
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
                            <select class="form-select form-select-custom" id="edit_categoria_id" name="categoria_id" required>
                                <option value="">Seleccionar Categoría</option>
                                <?php foreach ($categorias as $categoria): ?>
                                    <option value="<?= $categoria["id"] ?>"><?= htmlspecialchars($categoria["nombre"]) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_monto" class="form-label">Monto Presupuestado</label>
                            <div class="input-group">
                                <span class="input-group-text">₲</span>
                                <input type="text" class="form-control" id="edit_monto" name="monto" required>
                            </div>
                            <small class="text-muted">Formato: 1.000.000</small>
                        </div>
                        <div class="mb-3">
                            <label for="edit_periodo" class="form-label">Período</label>
                            <select class="form-select form-select-custom" id="edit_periodo" name="periodo" required>
                                <option value="">Seleccionar Período</option>
                                <?php foreach ($periodos as $codigo => $nombre): ?>
                                    <option value="<?= $codigo ?>"><?= htmlspecialchars($nombre) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="edit_fecha_inicio" class="form-label">Fecha Inicio</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-calendar"></i></span>
                                    <input class="form-control" type="date" id="edit_fecha_inicio" name="fecha_inicio" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_fecha_fin" class="form-label">Fecha Fin</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-calendar"></i></span>
                                    <input class="form-control" type="date" id="edit_fecha_fin" name="fecha_fin">
                                </div>
                            </div>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="edit_notificacion" name="notificacion">
                            <label class="form-check-label" for="edit_notificacion">
                                Activar notificaciones
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
                    document.getElementById('edit_monto').value = this.dataset.monto;
                    document.getElementById('edit_periodo').value = this.dataset.periodo;
                    document.getElementById('edit_fecha_inicio').value = this.dataset.fecha_inicio;
                    document.getElementById('edit_fecha_fin').value = this.dataset.fecha_fin || '';
                    document.getElementById('edit_notificacion').checked = this.dataset.notificacion === '1';
                });
            });

            // Configurar fechas basadas en el período seleccionado (nuevo presupuesto)
            const periodoSelect = document.getElementById('periodo');
            const fechaInicio = document.getElementById('fecha_inicio');
            const fechaFin = document.getElementById('fecha_fin');

            if (periodoSelect && fechaInicio && fechaFin) {
                periodoSelect.addEventListener('change', function() {
                    const hoy = new Date();
                    let fechaFinDate = new Date(hoy);

                    switch(this.value) {
                        case 'mensual':
                            fechaFinDate.setMonth(fechaFinDate.getMonth() + 1);
                            break;
                        case 'anual':
                            fechaFinDate.setFullYear(fechaFinDate.getFullYear() + 1);
                            break;
                        default:
                            fechaFin.value = '';
                            return;
                    }

                    const formatDate = (date) => {
                        return date.toISOString().split('T')[0];
                    };

                    fechaInicio.value = formatDate(hoy);
                    fechaFin.value = formatDate(fechaFinDate);
                });
            }

            // Formatear input de monto para aceptar puntos como separador de miles
            document.querySelectorAll('input[name="monto"], input[name="edit_monto"]').forEach(input => {
                input.addEventListener('blur', function() {
                    let value = this.value.replace(/\./g, '');
                    if (value.length > 3) {
                        value = value.replace(/\B(?=(\d{3})+(?!\d))/g, ".");
                    }
                    this.value = value;
                });
            });
        });
    </script>
</body>
</html>
