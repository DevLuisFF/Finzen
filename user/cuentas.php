<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit();
}
require "../config/database.php";
$db = Conexion::obtenerInstancia()->obtenerConexion();
$usuario_id = $_SESSION["user_id"];

// Monedas disponibles
$monedas = [
    "PYG" => "Guaraní Paraguayo",
    "USD" => "Dólar Estadounidense",
    "EUR" => "Euro",
    "BRL" => "Real Brasileño",
    "MXN" => "Peso Mexicano",
    "CAD" => "Dólar Canadiense",
    "GBP" => "Libra Esterlina",
];

// Función para formatear dinero
function formatMoney($amount, $currency = 'PYG') {
    return $currency . ' ' . number_format($amount / 100, 0, ',', '.');
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
        if (!empty($filters['moneda'])) {
            $query .= " AND c.moneda = :moneda";
            $params[':moneda'] = $filters['moneda'];
        }
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

    public function create($data) {
        $stmt = $this->db->prepare("
            INSERT INTO cuentas (usuario_id, nombre, saldo, moneda, activa, creado_en, actualizado_en)
            VALUES (:usuario_id, :nombre, :saldo, :moneda, :activa, NOW(), NOW())
        ");
        return $stmt->execute($data);
    }

    public function update($id, $data) {
        $data['id'] = $id;
        $stmt = $this->db->prepare("
            UPDATE cuentas SET
                nombre = :nombre,
                saldo = :saldo,
                moneda = :moneda,
                activa = :activa,
                actualizado_en = NOW()
            WHERE id = :id AND usuario_id = :usuario_id
        ");
        return $stmt->execute($data);
    }

    public function delete($id, $userId) {
        $stmt = $this->db->prepare("DELETE FROM cuentas WHERE id = :id AND usuario_id = :usuario_id");
        return $stmt->execute(['id' => $id, 'usuario_id' => $userId]);
    }
}

// Procesar operaciones CRUD
$accountRepo = new AccountRepository($db);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $data = [
        'usuario_id' => $usuario_id,
        'nombre' => trim($_POST["nombre"] ?? ""),
        'saldo' => floatval(str_replace(['.', 'PYG', 'USD', ' ', '$'], '', $_POST["saldo"] ?? 0)) * 100,
        'moneda' => $_POST["moneda"] ?? "PYG",
        'activa' => isset($_POST["activa"]) ? 1 : 0
    ];

    if (isset($_POST["create"]) && $data['nombre']) {
        $accountRepo->create($data);
    }
    if (isset($_POST["update"]) && isset($_POST["id"])) {
        $data['id'] = intval($_POST["id"]);
        $accountRepo->update($data['id'], $data);
    }
    header("Location: " . $_SERVER["PHP_SELF"], true, 303);
    exit();
}

// Eliminar cuenta
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["delete"])) {
    $id = intval($_GET["delete"]);
    $accountRepo->delete($id, $usuario_id);
    header("Location: " . $_SERVER["PHP_SELF"], true, 303);
    exit();
}

// Obtener filtros de la URL
$filters = [
    'moneda' => $_GET['moneda'] ?? '',
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
                        <a class="nav-link active" href="cuentas.php">
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
                <h1 class="mb-1">Mis Cuentas</h1>
                <p class="text-muted mb-0">Gestiona tus cuentas financieras</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAccountModal">
                <i class="bi bi-plus-circle me-1"></i> Nueva Cuenta
            </button>
        </div>

        <!-- Filtros -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" action="" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label for="moneda" class="form-label">Moneda</label>
                        <select class="form-select form-select-custom" id="moneda" name="moneda">
                            <option value="">Todas las monedas</option>
                            <?php foreach ($monedas as $codigo => $nombre): ?>
                                <option value="<?= $codigo ?>" <?= ($filters['moneda'] === $codigo) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($nombre) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="activa" class="form-label">Estado</label>
                        <select class="form-select form-select-custom" id="activa" name="activa">
                            <option value="">Todos los estados</option>
                            <option value="1" <?= ($filters['activa'] === 1) ? 'selected' : '' ?>>Activas</option>
                            <option value="0" <?= ($filters['activa'] === 0) ? 'selected' : '' ?>>Inactivas</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-funnel me-1"></i> Filtrar
                        </button>
                    </div>
                    <div class="col-md-3 text-md-end">
                        <span class="badge bg-primary badge-custom">
                            <?= $totalCuentas ?> cuenta<?= $totalCuentas !== 1 ? 's' : '' ?>
                        </span>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tabla de cuentas -->
        <div class="card">
            <div class="card-body">
                <?php if (empty($cuentas)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-wallet2 display-4 text-muted mb-3"></i>
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
                                    <th>Nombre</th>
                                    <th>Saldo</th>
                                    <th>Moneda</th>
                                    <th>Estado</th>
                                    <th class="text-end">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cuentas as $cuenta): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($cuenta["nombre"]) ?></strong></td>
                                    <td><?= formatMoney($cuenta["saldo"], $cuenta["moneda"]) ?></td>
                                    <td>
                                        <span class="badge bg-primary badge-custom">
                                            <?= htmlspecialchars($cuenta["moneda"]) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?= $cuenta["activa"] ? 'bg-success' : 'bg-danger' ?> badge-custom">
                                            <?= $cuenta["activa"] ? 'Activa' : 'Inactiva' ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex justify-content-end gap-2">
                                            <button class="btn btn-sm btn-outline-primary edit-btn"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#editAccountModal"
                                                    data-id="<?= $cuenta["id"] ?>"
                                                    data-nombre="<?= htmlspecialchars($cuenta["nombre"]) ?>"
                                                    data-saldo="<?= formatMoney($cuenta["saldo"], $cuenta["moneda"]) ?>"
                                                    data-moneda="<?= $cuenta["moneda"] ?>"
                                                    data-activa="<?= $cuenta["activa"] ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <a href="?delete=<?= $cuenta["id"] ?>&page=<?= $page ?>&moneda=<?= $filters['moneda'] ?>&activa=<?= $filters['activa'] ?>"
                                               class="btn btn-sm btn-outline-danger"
                                               onclick="return confirm('¿Estás seguro de eliminar esta cuenta?')">
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
                    <nav class="mt-4">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $page - 1 ?>&moneda=<?= $filters['moneda'] ?>&activa=<?= $filters['activa'] ?>" aria-label="Anterior">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>&moneda=<?= $filters['moneda'] ?>&activa=<?= $filters['activa'] ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $page + 1 ?>&moneda=<?= $filters['moneda'] ?>&activa=<?= $filters['activa'] ?>" aria-label="Siguiente">
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
                            <input type="text" class="form-control" id="nombre" name="nombre" placeholder="Ej: Cuenta Corriente" required>
                        </div>
                        <div class="mb-3">
                            <label for="saldo" class="form-label">Saldo Inicial</label>
                            <input type="text" class="form-control" id="saldo" name="saldo" placeholder="Ej: 1.000.000" required>
                            <small class="text-muted">Formato: 1.000.000</small>
                        </div>
                        <div class="mb-3">
                            <label for="moneda" class="form-label">Moneda</label>
                            <select class="form-select form-select-custom" id="moneda" name="moneda" required>
                                <option value="">Seleccionar Moneda</option>
                                <?php foreach ($monedas as $codigo => $nombre): ?>
                                    <option value="<?= $codigo ?>"><?= htmlspecialchars($nombre) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-check mb-3">
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
                            <label for="edit_saldo" class="form-label">Saldo</label>
                            <input type="text" class="form-control" id="edit_saldo" name="saldo" required>
                            <small class="text-muted">Formato: 1.000.000</small>
                        </div>
                        <div class="mb-3">
                            <label for="edit_moneda" class="form-label">Moneda</label>
                            <select class="form-select form-select-custom" id="edit_moneda" name="moneda" required>
                                <option value="">Seleccionar Moneda</option>
                                <?php foreach ($monedas as $codigo => $nombre): ?>
                                    <option value="<?= $codigo ?>"><?= htmlspecialchars($nombre) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-check mb-3">
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

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Manejar edición de cuentas
            document.querySelectorAll('.edit-btn').forEach(button => {
                button.addEventListener('click', function() {
                    document.getElementById('edit_id').value = this.dataset.id;
                    document.getElementById('edit_nombre').value = this.dataset.nombre;
                    document.getElementById('edit_saldo').value = this.dataset.saldo;
                    document.getElementById('edit_moneda').value = this.dataset.moneda;
                    document.getElementById('edit_activa').checked = this.dataset.activa === '1';
                });
            });

            // Formatear input de saldo para aceptar puntos como separador de miles
            document.querySelectorAll('input[name="saldo"], input[name="edit_saldo"]').forEach(input => {
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
