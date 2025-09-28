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
function formatMoney($amount, $withSymbol = true) {
    $formatted = number_format($amount / 100, 0, ',', '.');
    return $withSymbol ? '₲ ' . $formatted : $formatted;
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
                   cat.nombre AS categoria_nombre,
                   cat.tipo AS tipo_categoria,
                   cat.color AS categoria_color
            FROM transacciones t
            LEFT JOIN cuentas c ON t.cuenta_id = c.id
            LEFT JOIN categorias cat ON t.categoria_id = cat.id
            WHERE t.cuenta_id IN (
                SELECT id FROM cuentas WHERE usuario_id = :usuario_id
            )
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
        if (!empty($filters['recurrente'])) {
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
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM ($query) AS filtered");
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
            WHERE id = :id AND cuenta_id IN (SELECT id FROM cuentas WHERE usuario_id = :usuario_id)
        ");
        $data['usuario_id'] = $_SESSION["user_id"];
        return $stmt->execute($data);
    }

    public function delete($id, $userId) {
        $stmt = $this->db->prepare("
            DELETE FROM transacciones
            WHERE id = :id AND cuenta_id IN (SELECT id FROM cuentas WHERE usuario_id = :usuario_id)
        ");
        return $stmt->execute(['id' => $id, 'usuario_id' => $userId]);
    }
}

// Configuración inicial
$transactionRepo = new TransactionRepository($db);

// Obtener cuentas del usuario
$stmtCuentas = $db->prepare("SELECT id, nombre FROM cuentas WHERE usuario_id = :usuario_id");
$stmtCuentas->bindValue(":usuario_id", $usuario_id, PDO::PARAM_INT);
$stmtCuentas->execute();
$cuentas = $stmtCuentas->fetchAll();

// Obtener categorías del usuario
$stmtCategorias = $db->prepare("SELECT id, nombre, tipo FROM categorias WHERE usuario_id = :usuario_id");
$stmtCategorias->bindValue(":usuario_id", $usuario_id, PDO::PARAM_INT);
$stmtCategorias->execute();
$categorias = $stmtCategorias->fetchAll();

// Tipos de transacciones
$tipos = [
    "ingreso" => "Ingreso",
    "gasto" => "Gasto",
];

// Procesar operaciones CRUD
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $monto = str_replace(['₲', '.', ',', ' '], '', $_POST["monto"] ?? '0');
    $data = [
        'cuenta_id' => $_POST["cuenta_id"] ?? null,
        'categoria_id' => $_POST["categoria_id"] ?? null,
        'monto' => intval($monto) * 100,
        'descripcion' => trim($_POST["descripcion"] ?? ""),
        'fecha' => $_POST["fecha"] ?? date("Y-m-d"),
        'recurrente' => isset($_POST["recurrente"]) ? 1 : 0
    ];

    if (isset($_POST["create"])) {
        $transactionRepo->create($data);
    }
    if (isset($_POST["update"])) {
        $data['id'] = $_POST["id"];
        $transactionRepo->update($data['id'], $data);
    }
    header("Location: " . $_SERVER["PHP_SELF"], true, 303);
    exit();
}

// Eliminar transacción
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["delete"])) {
    $id = intval($_GET["delete"]);
    $transactionRepo->delete($id, $usuario_id);
    header("Location: " . $_SERVER["PHP_SELF"], true, 303);
    exit();
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
        .amount.ingreso {
            color: var(--bs-success);
            font-weight: 500;
        }
        .amount.gasto {
            color: var(--bs-danger);
            font-weight: 500;
        }
        .transaction-item {
            transition: background-color 0.2s;
        }
        .transaction-item:hover {
            background-color: rgba(0,0,0,0.025);
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
                    <li class="nav-item">
                        <a class="nav-link" href="transacciones-recurrentes.php">
                            <i class="bi bi-arrow-left-right me-1"></i> Transacciones Recurrentes
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
                <h1 class="mb-1">Mis Transacciones</h1>
                <p class="text-muted mb-0">Registro de ingresos y gastos</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTransactionModal">
                <i class="bi bi-plus-circle me-1"></i> Nueva Transacción
            </button>
        </div>

        <!-- Filtros -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" action="" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label for="cuenta_id" class="form-label">Cuenta</label>
                        <select class="form-select form-select-custom" id="cuenta_id" name="cuenta_id">
                            <option value="">Todas las cuentas</option>
                            <?php foreach ($cuentas as $cuenta): ?>
                                <option value="<?= $cuenta["id"] ?>" <?= ($filters['cuenta_id'] == $cuenta["id"]) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cuenta["nombre"]) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
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
                    <div class="col-md-2">
                        <label for="tipo" class="form-label">Tipo</label>
                        <select class="form-select form-select-custom" id="tipo" name="tipo">
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
                        <select class="form-select form-select-custom" id="recurrente" name="recurrente">
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
                                    <th>Cuenta</th>
                                    <th>Categoría</th>
                                    <th>Monto</th>
                                    <th>Descripción</th>
                                    <th>Fecha</th>
                                    <th>Recurrente</th>
                                    <th class="text-end">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transacciones as $transaccion):
                                    $tipoClase = $transaccion["tipo_categoria"] === "ingreso" ? "ingreso" : "gasto";
                                ?>
                                <tr class="transaction-item">
                                    <td><?= htmlspecialchars($transaccion["cuenta_nombre"] ?? "Sin cuenta") ?></td>
                                    <td>
                                        <?php if (!empty($transaccion['categoria_color'])): ?>
                                            <span class="category-icon" style="background-color: <?= htmlspecialchars($transaccion['categoria_color']) ?>">
                                                <i class="fas fa-tag"></i>
                                            </span>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($transaccion["categoria_nombre"] ?? "Sin categoría") ?>
                                    </td>
                                    <td class="amount <?= $tipoClase ?>">
                                        <?= $transaccion["tipo_categoria"] === "ingreso" ? '+' : '-' ?>
                                        <?= formatMoney($transaccion["monto"]) ?>
                                    </td>
                                    <td><?= htmlspecialchars($transaccion["descripcion"]) ?></td>
                                    <td><?= date("d/m/Y", strtotime($transaccion["fecha"])) ?></td>
                                    <td>
                                        <?php if ($transaccion["recurrente"]): ?>
                                            <span class="badge bg-primary badge-custom">
                                                <i class="bi bi-arrow-clockwise me-1"></i> Sí
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
                                                            data-monto="<?= formatMoney($transaccion["monto"], false) ?>"
                                                            data-descripcion="<?= htmlspecialchars($transaccion["descripcion"]) ?>"
                                                            data-fecha="<?= $transaccion["fecha"] ?>"
                                                            data-recurrente="<?= $transaccion["recurrente"] ?>">
                                                        <i class="bi bi-pencil me-2"></i> Editar
                                                    </button>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item text-danger"
                                                       href="?delete=<?= $transaccion["id"] ?>&page=<?= $page ?>&cuenta_id=<?= $filters['cuenta_id'] ?>&categoria_id=<?= $filters['categoria_id'] ?>&tipo=<?= $filters['tipo'] ?>&recurrente=<?= $filters['recurrente'] ?>&fecha_desde=<?= $filters['fecha_desde'] ?>&fecha_hasta=<?= $filters['fecha_hasta'] ?>"
                                                       onclick="return confirm('¿Estás seguro de eliminar esta transacción?')">
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
                            <select class="form-select form-select-custom" id="cuenta_id" name="cuenta_id" required>
                                <option value="">Seleccionar Cuenta</option>
                                <?php foreach ($cuentas as $cuenta): ?>
                                    <option value="<?= $cuenta["id"] ?>"><?= htmlspecialchars($cuenta["nombre"]) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="categoria_id" class="form-label">Categoría</label>
                            <select class="form-select form-select-custom" id="categoria_id" name="categoria_id" required>
                                <option value="">Seleccionar Categoría</option>
                                <?php foreach ($categorias as $categoria): ?>
                                    <option value="<?= $categoria["id"] ?>">
                                        <?= htmlspecialchars($categoria["nombre"]) ?> (<?= $tipos[$categoria["tipo"]] ?? $categoria["tipo"] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="monto" class="form-label">Monto</label>
                            <div class="input-group">
                                <span class="input-group-text">₲</span>
                                <input type="text" class="form-control" id="monto" name="monto" placeholder="Ej: 1.000.000" required>
                            </div>
                            <small class="text-muted">Formato: 1.000.000</small>
                        </div>
                        <div class="mb-3">
                            <label for="descripcion" class="form-label">Descripción</label>
                            <textarea class="form-control" id="descripcion" name="descripcion" rows="2" placeholder="Descripción de la transacción"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="fecha" class="form-label">Fecha</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-calendar"></i></span>
                                <input class="form-control" type="date" id="fecha" name="fecha" required value="<?= date("Y-m-d") ?>">
                            </div>
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
                            <select class="form-select form-select-custom" id="edit_cuenta_id" name="cuenta_id" required>
                                <option value="">Seleccionar Cuenta</option>
                                <?php foreach ($cuentas as $cuenta): ?>
                                    <option value="<?= $cuenta["id"] ?>"><?= htmlspecialchars($cuenta["nombre"]) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_categoria_id" class="form-label">Categoría</label>
                            <select class="form-select form-select-custom" id="edit_categoria_id" name="categoria_id" required>
                                <option value="">Seleccionar Categoría</option>
                                <?php foreach ($categorias as $categoria): ?>
                                    <option value="<?= $categoria["id"] ?>">
                                        <?= htmlspecialchars($categoria["nombre"]) ?> (<?= $tipos[$categoria["tipo"]] ?? $categoria["tipo"] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_monto" class="form-label">Monto</label>
                            <div class="input-group">
                                <span class="input-group-text">₲</span>
                                <input type="text" class="form-control" id="edit_monto" name="monto" required>
                            </div>
                            <small class="text-muted">Formato: 1.000.000</small>
                        </div>
                        <div class="mb-3">
                            <label for="edit_descripcion" class="form-label">Descripción</label>
                            <textarea class="form-control" id="edit_descripcion" name="descripcion" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="edit_fecha" class="form-label">Fecha</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-calendar"></i></span>
                                <input class="form-control" type="date" id="edit_fecha" name="fecha" required>
                            </div>
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
                    document.getElementById('edit_monto').value = this.dataset.monto;
                    document.getElementById('edit_descripcion').value = this.dataset.descripcion;
                    document.getElementById('edit_fecha').value = this.dataset.fecha;
                    document.getElementById('edit_recurrente').checked = this.dataset.recurrente === '1';
                });
            });

            // Formatear input de monto para aceptar puntos como separador de miles
            document.querySelectorAll('input[name="monto"], #edit_monto').forEach(input => {
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
