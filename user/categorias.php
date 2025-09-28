<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit();
}
require "../config/database.php";
$db = Conexion::obtenerInstancia()->obtenerConexion();
$usuario_id = $_SESSION["user_id"];

// Tipos de categorías
$tipos = [
    "ingreso" => "Ingreso",
    "gasto" => "Gasto",
    "transferencia" => "Transferencia",
];

// Iconos disponibles
$iconos = [
    "fa-shopping-cart" => ["nombre" => "Compras", "clase" => "text-primary"],
    "fa-utensils" => ["nombre" => "Comida", "clase" => "text-success"],
    "fa-car" => ["nombre" => "Transporte", "clase" => "text-info"],
    "fa-home" => ["nombre" => "Hogar", "clase" => "text-warning"],
    "fa-tv" => ["nombre" => "Entretenimiento", "clase" => "text-danger"],
    "fa-heartbeat" => ["nombre" => "Salud", "clase" => "text-pink"],
    "fa-graduation-cap" => ["nombre" => "Educación", "clase" => "text-purple"],
    "fa-money-bill-wave" => ["nombre" => "Ingresos", "clase" => "text-success"],
    "fa-piggy-bank" => ["nombre" => "Ahorros", "clase" => "text-indigo"],
    "fa-gift" => ["nombre" => "Regalos", "clase" => "text-orange"],
];

// Colores disponibles
$colores = [
    "#FF6384" => "Rojo",
    "#36A2EB" => "Azul",
    "#FFCE56" => "Amarillo",
    "#4BC0C0" => "Turquesa",
    "#9966FF" => "Morado",
    "#FF9F40" => "Naranja",
    "#8AC24A" => "Verde",
    "#F06292" => "Rosa",
    "#7986CB" => "Índigo",
    "#A1887F" => "Marrón",
];

// Clase para manejar categorías
class CategoryRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function getAll($userId, $filters = [], $limit = 10, $offset = 0) {
        $query = "
            SELECT c.*, u.nombre_usuario
            FROM categorias c
            JOIN usuarios u ON c.usuario_id = u.id
            WHERE c.usuario_id = :usuario_id
        ";
        $params = [':usuario_id' => $userId];

        // Aplicar filtros
        if (!empty($filters['tipo'])) {
            $query .= " AND c.tipo = :tipo";
            $params[':tipo'] = $filters['tipo'];
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
            INSERT INTO categorias (usuario_id, nombre, tipo, icono, color, creado_en, actualizado_en)
            VALUES (:usuario_id, :nombre, :tipo, :icono, :color, NOW(), NOW())
        ");
        return $stmt->execute($data);
    }

    public function update($id, $data) {
        $data['id'] = $id;
        $stmt = $this->db->prepare("
            UPDATE categorias SET
                nombre = :nombre,
                tipo = :tipo,
                icono = :icono,
                color = :color,
                actualizado_en = NOW()
            WHERE id = :id AND usuario_id = :usuario_id
        ");
        return $stmt->execute($data);
    }

    public function delete($id, $userId) {
        $stmt = $this->db->prepare("DELETE FROM categorias WHERE id = :id AND usuario_id = :usuario_id");
        return $stmt->execute(['id' => $id, 'usuario_id' => $userId]);
    }
}

// Procesar operaciones CRUD
$categoryRepo = new CategoryRepository($db);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $data = [
        'usuario_id' => $usuario_id,
        'nombre' => trim($_POST["nombre"] ?? ""),
        'tipo' => $_POST["tipo"] ?? "gasto",
        'icono' => $_POST["icono"] ?? "fa-shopping-cart",
        'color' => $_POST["color"] ?? "#FF6384"
    ];

    if (isset($_POST["create"]) && $data['nombre']) {
        $categoryRepo->create($data);
    }
    if (isset($_POST["update"]) && isset($_POST["id"])) {
        $data['id'] = intval($_POST["id"]);
        $categoryRepo->update($data['id'], $data);
    }
    header("Location: " . $_SERVER["PHP_SELF"], true, 303);
    exit();
}

// Eliminar categoría
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["delete"])) {
    $id = intval($_GET["delete"]);
    $categoryRepo->delete($id, $usuario_id);
    header("Location: " . $_SERVER["PHP_SELF"], true, 303);
    exit();
}

// Obtener filtros de la URL
$filters = [
    'tipo' => $_GET['tipo'] ?? '',
];

// Configuración de paginación
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 6;
$offset = ($page - 1) * $perPage;

// Obtener categorías con filtros y paginación
$result = $categoryRepo->getAll($usuario_id, $filters, $perPage, $offset);
$categorias = $result['data'];
$totalCategorias = $result['total'];
$totalPages = ceil($totalCategorias / $perPage);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finzen | Mis Categorías</title>
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
            --bs-info: #0dcaf0;
            --bs-pink: #d63384;
            --bs-purple: #6f42c1;
            --bs-indigo: #6610f2;
            --bs-orange: #fd7e14;
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
        .color-circle {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
            border: 2px solid #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .color-option {
            display: flex;
            align-items: center;
            padding: 0.5rem;
            border-radius: 8px;
            transition: all 0.2s;
            cursor: pointer;
        }
        .color-option:hover {
            background-color: #f8f9fa;
        }
        .color-option input[type="radio"]:checked + .color-selector {
            border: 2px solid var(--bs-primary);
            padding: 0.25rem;
            border-radius: 8px;
        }
        .color-selector {
            display: flex;
            align-items: center;
            width: 100%;
        }
        .icon-preview {
            font-size: 1.2rem;
            margin-right: 8px;
        }
        .text-pink { color: #d63384 !important; }
        .text-purple { color: #6f42c1 !important; }
        .text-indigo { color: #6610f2 !important; }
        .text-orange { color: #fd7e14 !important; }
        @media (max-width: 768px) {
            .navbar-nav {
                flex-direction: row;
            }
            .nav-item {
                margin-right: 0.5rem;
            }
            .category-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 576px) {
            .category-grid {
                grid-template-columns: 1fr;
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
                        <a class="nav-link active" href="categorias.php">
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
                <h1 class="mb-1">Mis Categorías</h1>
                <p class="text-muted mb-0">Organiza tus ingresos y gastos</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                <i class="bi bi-plus-circle me-1"></i> Nueva Categoría
            </button>
        </div>

        <!-- Filtros -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" action="" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label for="tipo" class="form-label">Tipo de Categoría</label>
                        <select class="form-select form-select-custom" id="tipo" name="tipo">
                            <option value="">Todos los tipos</option>
                            <?php foreach ($tipos as $codigo => $nombre): ?>
                                <option value="<?= $codigo ?>" <?= ($filters['tipo'] === $codigo) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($nombre) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-funnel me-1"></i> Filtrar
                        </button>
                    </div>
                    <div class="col-md-5 text-md-end">
                        <span class="badge bg-primary badge-custom">
                            <?= $totalCategorias ?> categoría<?= $totalCategorias !== 1 ? 's' : '' ?>
                        </span>
                    </div>
                </form>
            </div>
        </div>

        <!-- Lista de categorías -->
        <div class="card">
            <div class="card-body">
                <?php if (empty($categorias)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-tags display-4 text-muted mb-3"></i>
                        <h3 class="mb-2">No se encontraron categorías</h3>
                        <p class="text-muted mb-4">No hay categorías que coincidan con los filtros seleccionados</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                            <i class="bi bi-plus-circle me-1"></i> Agregar Categoría
                        </button>
                    </div>
                <?php else: ?>
                    <div class="category-grid row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4 mb-4">
                        <?php foreach ($categorias as $categoria): ?>
                        <div class="col">
                            <div class="card h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div>
                                            <span class="badge
                                                <?= $categoria["tipo"] === 'ingreso' ? 'bg-success' :
                                                   ($categoria["tipo"] === 'gasto' ? 'bg-danger' : 'bg-primary') ?>
                                                badge-custom">
                                                <?= htmlspecialchars($tipos[$categoria["tipo"]] ?? $categoria["tipo"]) ?>
                                            </span>
                                        </div>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown">
                                                <i class="bi bi-three-dots-vertical"></i>
                                            </button>
                                            <ul class="dropdown-menu">
                                                <li>
                                                    <button class="dropdown-item edit-btn"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#editCategoryModal"
                                                            data-id="<?= $categoria["id"] ?>"
                                                            data-nombre="<?= htmlspecialchars($categoria["nombre"]) ?>"
                                                            data-tipo="<?= $categoria["tipo"] ?>"
                                                            data-icono="<?= $categoria["icono"] ?>"
                                                            data-color="<?= $categoria["color"] ?>">
                                                        <i class="bi bi-pencil me-2"></i> Editar
                                                    </button>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item text-danger"
                                                       href="?delete=<?= $categoria["id"] ?>&page=<?= $page ?>&tipo=<?= $filters['tipo'] ?>"
                                                       onclick="return confirm('¿Estás seguro de eliminar esta categoría?')">
                                                        <i class="bi bi-trash me-2"></i> Eliminar
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                    <div class="text-center mb-3">
                                        <div class="icon-preview mb-2">
                                            <i class="fas <?= htmlspecialchars($categoria["icono"]) ?> <?= $iconos[$categoria["icono"]]['clase'] ?> fa-2x"></i>
                                        </div>
                                        <h5 class="card-title mb-2"><?= htmlspecialchars($categoria["nombre"]) ?></h5>
                                        <div class="color-selector justify-content-center">
                                            <span class="color-circle" style="background-color: <?= htmlspecialchars($categoria["color"]) ?>"></span>
                                            <span><?= htmlspecialchars($colores[$categoria["color"]] ?? $categoria["color"]) ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Paginación -->
                    <nav class="mt-4">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $page - 1 ?>&tipo=<?= $filters['tipo'] ?>" aria-label="Anterior">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>&tipo=<?= $filters['tipo'] ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $page + 1 ?>&tipo=<?= $filters['tipo'] ?>" aria-label="Siguiente">
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

    <!-- Modal para agregar nueva categoría -->
    <div class="modal fade" id="addCategoryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-plus-circle me-2"></i> Agregar Nueva Categoría
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="usuario_id" value="<?= $usuario_id ?>">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="nombre" class="form-label">Nombre de la Categoría</label>
                            <input type="text" class="form-control" id="nombre" name="nombre" placeholder="Ej: Comida, Transporte, Salario" required>
                        </div>
                        <div class="mb-3">
                            <label for="tipo" class="form-label">Tipo</label>
                            <select class="form-select form-select-custom" id="tipo" name="tipo" required>
                                <option value="">Seleccionar Tipo</option>
                                <?php foreach ($tipos as $codigo => $nombre): ?>
                                    <option value="<?= $codigo ?>"><?= htmlspecialchars($nombre) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="icono" class="form-label">Icono</label>
                            <select class="form-select form-select-custom" id="icono" name="icono" required>
                                <option value="">Seleccionar Icono</option>
                                <?php foreach ($iconos as $codigo => $icono): ?>
                                    <option value="<?= $codigo ?>">
                                        <i class="fas <?= $codigo ?> me-2"></i> <?= htmlspecialchars($icono['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Color</label>
                            <div class="row g-2">
                                <?php foreach ($colores as $codigo => $nombre): ?>
                                <div class="col-4">
                                    <label class="color-option w-100">
                                        <input type="radio" name="color" value="<?= $codigo ?>" required class="d-none">
                                        <span class="color-selector">
                                            <span class="color-circle" style="background-color: <?= $codigo ?>"></span>
                                            <span><?= htmlspecialchars($nombre) ?></span>
                                        </span>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" name="create">
                            <i class="bi bi-save me-1"></i> Guardar Categoría
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal para editar categoría -->
    <div class="modal fade" id="editCategoryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil me-2"></i> Editar Categoría
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="usuario_id" value="<?= $usuario_id ?>">
                    <input type="hidden" id="edit_id" name="id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_nombre" class="form-label">Nombre de la Categoría</label>
                            <input type="text" class="form-control" id="edit_nombre" name="nombre" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_tipo" class="form-label">Tipo</label>
                            <select class="form-select form-select-custom" id="edit_tipo" name="tipo" required>
                                <option value="">Seleccionar Tipo</option>
                                <?php foreach ($tipos as $codigo => $nombre): ?>
                                    <option value="<?= $codigo ?>"><?= htmlspecialchars($nombre) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_icono" class="form-label">Icono</label>
                            <select class="form-select form-select-custom" id="edit_icono" name="icono" required>
                                <option value="">Seleccionar Icono</option>
                                <?php foreach ($iconos as $codigo => $icono): ?>
                                    <option value="<?= $codigo ?>">
                                        <?= htmlspecialchars($icono['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Color</label>
                            <div class="row g-2" id="edit_colores_container">
                                <?php foreach ($colores as $codigo => $nombre): ?>
                                <div class="col-4">
                                    <label class="color-option w-100">
                                        <input type="radio" name="color" value="<?= $codigo ?>" required class="d-none">
                                        <span class="color-selector">
                                            <span class="color-circle" style="background-color: <?= $codigo ?>"></span>
                                            <span><?= htmlspecialchars($nombre) ?></span>
                                        </span>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" name="update">
                            <i class="bi bi-save me-1"></i> Actualizar Categoría
                        </button>
                    </div>
                </form>
            </div>
        </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Manejar edición de categorías
            document.querySelectorAll('.edit-btn').forEach(button => {
                button.addEventListener('click', function() {
                    document.getElementById('edit_id').value = this.dataset.id;
                    document.getElementById('edit_nombre').value = this.dataset.nombre;
                    document.getElementById('edit_tipo').value = this.dataset.tipo;
                    document.getElementById('edit_icono').value = this.dataset.icono;

                    // Seleccionar el color correcto
                    const color = this.dataset.color;
                    if (color) {
                        const colorInput = document.querySelector(`#edit_colores_container input[value="${color}"]`);
                        if (colorInput) {
                            colorInput.checked = true;
                            colorInput.closest('.color-option').classList.add('active');
                        }
                    }
                });
            });

            // Seleccionar color al hacer clic
            document.querySelectorAll('.color-option').forEach(option => {
                option.addEventListener('click', function() {
                    const radio = this.querySelector('input[type="radio"]');
                    if (radio) {
                        radio.checked = true;
                    }
                });
            });
        });
    </script>
</body>
</html>
