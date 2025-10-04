<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit();
}
require "../config/database.php";
$db = Conexion::obtenerInstancia()->obtenerConexion();
$usuario_id = $_SESSION["user_id"];

// Tipos de categorías según esquema de BD
$tipos = [
    "ingreso" => "Ingreso",
    "gasto" => "Gasto"
];

// Iconos disponibles usando Bootstrap Icons (coherente con el esquema)
$iconos = [
    "bi-cart" => ["nombre" => "Compras", "clase" => "text-primary"],
    "bi-cup-straw" => ["nombre" => "Comida", "clase" => "text-success"],
    "bi-car-front" => ["nombre" => "Transporte", "clase" => "text-info"],
    "bi-house" => ["nombre" => "Hogar", "clase" => "text-warning"],
    "bi-tv" => ["nombre" => "Entretenimiento", "clase" => "text-danger"],
    "bi-heart-pulse" => ["nombre" => "Salud", "clase" => "text-pink"],
    "bi-book" => ["nombre" => "Educación", "clase" => "text-purple"],
    "bi-cash-coin" => ["nombre" => "Ingresos", "clase" => "text-success"],
    "bi-piggy-bank" => ["nombre" => "Ahorros", "clase" => "text-indigo"],
    "bi-gift" => ["nombre" => "Regalos", "clase" => "text-orange"],
    "bi-phone" => ["nombre" => "Telefonía", "clase" => "text-blue"],
    "bi-wifi" => ["nombre" => "Internet", "clase" => "text-cyan"],
    "bi-lightning" => ["nombre" => "Energía", "clase" => "text-yellow"],
    "bi-droplet" => ["nombre" => "Agua", "clase" => "text-blue"],
    "bi-bag" => ["nombre" => "Ropa", "clase" => "text-pink"],
    "bi-controller" => ["nombre" => "Juegos", "clase" => "text-purple"],
    "bi-airplane" => ["nombre" => "Viajes", "clase" => "text-info"],
    "bi-tools" => ["nombre" => "Mantenimiento", "clase" => "text-warning"],
    "bi-flower1" => ["nombre" => "Belleza", "clase" => "text-pink"],
    "bi-bank" => ["nombre" => "Impuestos", "clase" => "text-danger"]
];

// Colores disponibles (coherente con valores por defecto del esquema)
$colores = [
    "#1976D2" => "Azul Principal",
    "#FF6384" => "Rojo",
    "#36A2EB" => "Azul Claro", 
    "#FFCE56" => "Amarillo",
    "#4BC0C0" => "Turquesa",
    "#9966FF" => "Morado",
    "#FF9F40" => "Naranja",
    "#8AC24A" => "Verde",
    "#F06292" => "Rosa",
    "#7986CB" => "Índigo",
    "#A1887F" => "Marrón",
    "#4CAF50" => "Verde Success",
    "#F44336" => "Rojo Danger",
    "#FF9800" => "Naranja Warning",
    "#9C27B0" => "Púrpura",
    "#00BCD4" => "Cian",
    "#607D8B" => "Gris Azulado"
];

// Clase para manejar categorías
class CategoryRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function getAll($userId, $filters = [], $limit = 12, $offset = 0) {
        $query = "
            SELECT c.*, 
                   COUNT(t.id) as total_transacciones,
                   COALESCE(SUM(CASE WHEN t.fecha >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH) THEN t.monto ELSE 0 END), 0) as monto_mes
            FROM categorias c
            LEFT JOIN transacciones t ON c.id = t.categoria_id
            WHERE c.usuario_id = :usuario_id
        ";
        $params = [':usuario_id' => $userId];

        // Aplicar filtros
        if (!empty($filters['tipo'])) {
            $query .= " AND c.tipo = :tipo";
            $params[':tipo'] = $filters['tipo'];
        }

        if (!empty($filters['search'])) {
            $query .= " AND c.nombre LIKE :search";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $query .= " GROUP BY c.id";

        // Contar total para paginación
        $countQuery = "SELECT COUNT(*) FROM ($query) AS filtered";
        $countStmt = $this->db->prepare($countQuery);
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();

        // Aplicar ordenamiento
        $orderBy = "c.creado_en DESC";
        if (!empty($filters['sort'])) {
            switch ($filters['sort']) {
                case 'nombre_asc':
                    $orderBy = "c.nombre ASC";
                    break;
                case 'nombre_desc':
                    $orderBy = "c.nombre DESC";
                    break;
                case 'transacciones':
                    $orderBy = "total_transacciones DESC";
                    break;
                case 'reciente':
                    $orderBy = "c.creado_en DESC";
                    break;
            }
        }

        // Aplicar paginación
        $query .= " ORDER BY $orderBy LIMIT :limit OFFSET :offset";
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

    public function getStatsByType($userId) {
        $stmt = $this->db->prepare("
            SELECT 
                tipo,
                COUNT(*) as total_categorias,
                COALESCE(SUM(
                    (SELECT COUNT(*) FROM transacciones t WHERE t.categoria_id = c.id)
                ), 0) as total_transacciones
            FROM categorias c
            WHERE c.usuario_id = :usuario_id 
            GROUP BY tipo
        ");
        $stmt->execute([':usuario_id' => $userId]);
        
        $stats = ['ingreso' => ['categorias' => 0, 'transacciones' => 0], 'gasto' => ['categorias' => 0, 'transacciones' => 0]];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $stats[$row['tipo']] = [
                'categorias' => (int)$row['total_categorias'],
                'transacciones' => (int)$row['total_transacciones']
            ];
        }
        return $stats;
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
        // Verificar si la categoría tiene transacciones antes de eliminar
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM transacciones WHERE categoria_id = :id
        ");
        $stmt->execute([':id' => $id]);
        $hasTransactions = $stmt->fetchColumn() > 0;

        if ($hasTransactions) {
            return false; // No se puede eliminar categoría con transacciones
        }

        $stmt = $this->db->prepare("DELETE FROM categorias WHERE id = :id AND usuario_id = :usuario_id");
        return $stmt->execute(['id' => $id, 'usuario_id' => $userId]);
    }

    public function getMostUsedCategories($userId, $limit = 5) {
        $stmt = $this->db->prepare("
            SELECT c.*, COUNT(t.id) as total_transacciones
            FROM categorias c
            LEFT JOIN transacciones t ON c.id = t.categoria_id
            WHERE c.usuario_id = :usuario_id
            GROUP BY c.id
            ORDER BY total_transacciones DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':usuario_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}

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

// Procesar operaciones CRUD
$categoryRepo = new CategoryRepository($db);
$error = '';
$success = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $data = [
        'usuario_id' => $usuario_id,
        'nombre' => trim($_POST["nombre"] ?? ""),
        'tipo' => $_POST["tipo"] ?? "gasto",
        'icono' => $_POST["icono"] ?? "bi-cart",
        'color' => $_POST["color"] ?? "#1976D2"
    ];

    try {
        if (isset($_POST["create"]) && $data['nombre']) {
            $categoryRepo->create($data);
            $_SESSION['success'] = 'Categoría creada exitosamente';
        }
        if (isset($_POST["update"]) && isset($_POST["id"])) {
            $data['id'] = intval($_POST["id"]);
            $categoryRepo->update($data['id'], $data);
            $_SESSION['success'] = 'Categoría actualizada exitosamente';
        }
    } catch (Exception $e) {
        $error = 'Error al procesar la operación: ' . $e->getMessage();
    }
    
    header("Location: " . $_SERVER["PHP_SELF"] . "?" . http_build_query($_GET));
    exit();
}

// Eliminar categoría
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["delete"])) {
    $id = intval($_GET["delete"]);
    $successDelete = $categoryRepo->delete($id, $usuario_id);
    
    if ($successDelete) {
        $_SESSION['success'] = 'Categoría eliminada exitosamente';
    } else {
        $_SESSION['error'] = 'No se puede eliminar una categoría que tiene transacciones asociadas';
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
    'tipo' => $_GET['tipo'] ?? '',
    'search' => $_GET['search'] ?? '',
    'sort' => $_GET['sort'] ?? 'reciente'
];

// Configuración de paginación
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 12;
$offset = ($page - 1) * $perPage;

// Obtener categorías con filtros y paginación
$result = $categoryRepo->getAll($usuario_id, $filters, $perPage, $offset);
$categorias = $result['data'];
$totalCategorias = $result['total'];
$totalPages = ceil($totalCategorias / $perPage);

// Obtener estadísticas por tipo
$stats = $categoryRepo->getStatsByType($usuario_id);

// Obtener categorías más usadas
$mostUsedCategories = $categoryRepo->getMostUsedCategories($usuario_id, 5);

// Obtener información del usuario para la moneda
$stmt = $db->prepare("SELECT moneda FROM usuarios WHERE id = ?");
$stmt->execute([$usuario_id]);
$user_currency = $stmt->fetchColumn();
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
            --bs-blue: #0d6efd;
            --bs-cyan: #0dcaf0;
            --bs-yellow: #ffc107;
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
        
        .stat-card.ingreso::before {
            background-color: var(--bs-success);
        }
        
        .stat-card.gasto::before {
            background-color: var(--bs-danger);
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
        
        .category-badge {
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
            border-radius: 0.75rem;
            transition: all 0.2s;
            cursor: pointer;
            margin-bottom: 0.5rem;
            border: 2px solid transparent;
        }
        
        .color-option:hover {
            background-color: var(--bs-light);
            transform: translateY(-1px);
        }
        
        .color-option.active {
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            border-color: var(--bs-primary);
        }
        
        .color-selector {
            display: flex;
            align-items: center;
            width: 100%;
        }
        
        .icon-preview {
            font-size: 2rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }
        
        .category-card {
            transition: all 0.3s ease;
            border: 1px solid transparent;
            position: relative;
            overflow: hidden;
        }
        
        .category-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
        }
        
        .category-card.ingreso::before {
            background-color: var(--bs-success);
        }
        
        .category-card.gasto::before {
            background-color: var(--bs-danger);
        }
        
        .category-card:hover {
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
        
        .category-card:hover .category-actions {
            opacity: 1;
        }
        
        .btn-action {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 0.5rem;
            transition: all 0.2s ease;
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
        
        .transactions-badge {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
            border-radius: 0.5rem;
        }
        
        .search-box {
            position: relative;
        }
        
        .search-box .form-control {
            padding-left: 2.5rem;
        }
        
        .search-box i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }
        
        .most-used-category {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            border-radius: 0.75rem;
            transition: all 0.2s ease;
            margin-bottom: 0.5rem;
        }
        
        .most-used-category:hover {
            background-color: var(--bs-light);
        }
        
        .usage-badge {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
            border-radius: 0.5rem;
        }
        
        .text-pink { color: var(--bs-pink) !important; }
        .text-purple { color: var(--bs-purple) !important; }
        .text-indigo { color: var(--bs-indigo) !important; }
        .text-orange { color: var(--bs-orange) !important; }
        .text-blue { color: var(--bs-blue) !important; }
        .text-cyan { color: var(--bs-cyan) !important; }
        .text-yellow { color: var(--bs-yellow) !important; }
        
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
                        <a class="nav-link active" href="categorias.php">
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
                <h1 class="mb-1">Mis Categorías</h1>
                <p class="text-muted mb-0">Organiza tus ingresos y gastos con categorías personalizadas</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                <i class="bi bi-plus-circle me-1"></i> Nueva Categoría
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

        <div class="row">
            <!-- Sidebar con estadísticas -->
            <div class="col-lg-3 mb-4">
                <!-- Estadísticas -->
                <div class="stats-grid">
                    <div class="stat-card ingreso">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-value text-success"><?= $stats['ingreso']['categorias'] ?></div>
                                <div class="stat-label">Categorías de Ingreso</div>
                                <small class="text-muted"><?= $stats['ingreso']['transacciones'] ?> transacciones</small>
                            </div>
                            <div class="stat-icon bg-success bg-opacity-10 text-success">
                                <i class="bi bi-arrow-down-circle"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card gasto">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-value text-danger"><?= $stats['gasto']['categorias'] ?></div>
                                <div class="stat-label">Categorías de Gasto</div>
                                <small class="text-muted"><?= $stats['gasto']['transacciones'] ?> transacciones</small>
                            </div>
                            <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                                <i class="bi bi-arrow-up-circle"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Categorías más usadas -->
                <?php if (!empty($mostUsedCategories)): ?>
                <div class="card mb-4">
                    <div class="card-body">
                        <h6 class="card-title mb-3">
                            <i class="bi bi-star me-2"></i>Categorías Más Usadas
                        </h6>
                        <?php foreach ($mostUsedCategories as $category): ?>
                            <?php if ($category['total_transacciones'] > 0): ?>
                            <div class="most-used-category">
                                <div class="me-3">
                                    <i class="bi <?= htmlspecialchars($category['icono']) ?> fs-5" style="color: <?= htmlspecialchars($category['color']) ?>"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="fw-medium"><?= htmlspecialchars($category['nombre']) ?></div>
                                    <small class="text-muted"><?= $category['total_transacciones'] ?> transacciones</small>
                                </div>
                                <span class="badge <?= $category['tipo'] === 'ingreso' ? 'bg-success' : 'bg-danger' ?> usage-badge">
                                    <?= $category['tipo'] === 'ingreso' ? 'Ingreso' : 'Gasto' ?>
                                </span>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Contenido principal -->
            <div class="col-lg-9">
                <!-- Filtros Mejorados -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label for="search" class="form-label">Buscar Categoría</label>
                                <div class="search-box">
                                    <i class="bi bi-search"></i>
                                    <input type="text" class="form-control" id="search" name="search" 
                                           placeholder="Buscar por nombre..." value="<?= htmlspecialchars($filters['search']) ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label for="tipo" class="form-label">Tipo</label>
                                <select class="form-select" id="tipo" name="tipo">
                                    <option value="">Todos los tipos</option>
                                    <?php foreach ($tipos as $codigo => $nombre): ?>
                                        <option value="<?= $codigo ?>" <?= ($filters['tipo'] === $codigo) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($nombre) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="sort" class="form-label">Ordenar por</label>
                                <select class="form-select" id="sort" name="sort">
                                    <option value="reciente" <?= $filters['sort'] === 'reciente' ? 'selected' : '' ?>>Más recientes</option>
                                    <option value="nombre_asc" <?= $filters['sort'] === 'nombre_asc' ? 'selected' : '' ?>>Nombre A-Z</option>
                                    <option value="nombre_desc" <?= $filters['sort'] === 'nombre_desc' ? 'selected' : '' ?>>Nombre Z-A</option>
                                    <option value="transacciones" <?= $filters['sort'] === 'transacciones' ? 'selected' : '' ?>>Más transacciones</option>
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

                <!-- Lista de categorías mejorada -->
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="card-title mb-0">Mis Categorías</h5>
                            <span class="badge bg-primary">
                                <?= $totalCategorias ?> categoría<?= $totalCategorias !== 1 ? 's' : '' ?>
                            </span>
                        </div>

                        <?php if (empty($categorias)): ?>
                            <div class="empty-state">
                                <i class="bi bi-tags"></i>
                                <h3 class="mb-2">No se encontraron categorías</h3>
                                <p class="text-muted mb-4">No hay categorías que coincidan con los filtros seleccionados</p>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                                    <i class="bi bi-plus-circle me-1"></i> Agregar Categoría
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4 mb-4">
                                <?php foreach ($categorias as $categoria): ?>
                                <div class="col">
                                    <div class="card category-card h-100 <?= $categoria["tipo"] ?>">
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
                                                               href="?<?= http_build_query(array_merge($_GET, ['delete' => $categoria["id"]])) ?>"
                                                               onclick="return confirm('¿Estás seguro de eliminar esta categoría?\n\n<?= $categoria['total_transacciones'] > 0 ? 'ADVERTENCIA: Esta categoría tiene ' . $categoria['total_transacciones'] . ' transacción(es) asociada(s) y no se puede eliminar.' : 'Esta acción no se puede deshacer.' ?>')">
                                                                <i class="bi bi-trash me-2"></i> Eliminar
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                            
                                            <div class="text-center mb-3">
                                                <div class="icon-preview" style="color: <?= htmlspecialchars($categoria["color"]) ?>">
                                                    <i class="bi <?= htmlspecialchars($categoria["icono"]) ?>"></i>
                                                </div>
                                                <h5 class="card-title mb-2"><?= htmlspecialchars($categoria["nombre"]) ?></h5>
                                                <div class="mb-2">
                                                    <span class="badge <?= $categoria["tipo"] === 'ingreso' ? 'bg-success' : 'bg-danger' ?> category-badge">
                                                        <?= htmlspecialchars($tipos[$categoria["tipo"]]) ?>
                                                    </span>
                                                </div>
                                                <div class="color-selector justify-content-center">
                                                    <span class="color-circle" style="background-color: <?= htmlspecialchars($categoria["color"]) ?>"></span>
                                                    <small class="text-muted"><?= htmlspecialchars($colores[$categoria["color"]] ?? $categoria["color"]) ?></small>
                                                </div>
                                            </div>
                                            
                                            <div class="text-center">
                                                <small class="text-muted">
                                                    <i class="bi bi-clock me-1"></i>
                                                    Creada: <?= date('d/m/Y', strtotime($categoria["creado_en"])) ?>
                                                </small>
                                                <?php if ($categoria["total_transacciones"] > 0): ?>
                                                    <br>
                                                    <span class="badge bg-info transactions-badge">
                                                        <i class="bi bi-list-check me-1"></i>
                                                        <?= $categoria["total_transacciones"] ?> transacción(es)
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
                            <select class="form-select" id="tipo" name="tipo" required>
                                <option value="">Seleccionar Tipo</option>
                                <?php foreach ($tipos as $codigo => $nombre): ?>
                                    <option value="<?= $codigo ?>"><?= htmlspecialchars($nombre) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="icono" class="form-label">Icono</label>
                            <select class="form-select" id="icono" name="icono" required>
                                <option value="">Seleccionar Icono</option>
                                <?php foreach ($iconos as $codigo => $icono): ?>
                                    <option value="<?= $codigo ?>" data-class="<?= $icono['clase'] ?>">
                                        <i class="bi <?= $codigo ?> me-2"></i> <?= htmlspecialchars($icono['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Color</label>
                            <div class="row g-2">
                                <?php foreach ($colores as $codigo => $nombre): ?>
                                <div class="col-6 col-sm-4">
                                    <label class="color-option w-100">
                                        <input type="radio" name="color" value="<?= $codigo ?>" required class="d-none">
                                        <span class="color-selector">
                                            <span class="color-circle" style="background-color: <?= $codigo ?>"></span>
                                            <small><?= htmlspecialchars($nombre) ?></small>
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
                            <select class="form-select" id="edit_tipo" name="tipo" required>
                                <option value="">Seleccionar Tipo</option>
                                <?php foreach ($tipos as $codigo => $nombre): ?>
                                    <option value="<?= $codigo ?>"><?= htmlspecialchars($nombre) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_icono" class="form-label">Icono</label>
                            <select class="form-select" id="edit_icono" name="icono" required>
                                <option value="">Seleccionar Icono</option>
                                <?php foreach ($iconos as $codigo => $icono): ?>
                                    <option value="<?= $codigo ?>">
                                        <i class="bi <?= $codigo ?> me-2"></i> <?= htmlspecialchars($icono['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Color</label>
                            <div class="row g-2" id="edit_colores_container">
                                <?php foreach ($colores as $codigo => $nombre): ?>
                                <div class="col-6 col-sm-4">
                                    <label class="color-option w-100">
                                        <input type="radio" name="color" value="<?= $codigo ?>" required class="d-none">
                                        <span class="color-selector">
                                            <span class="color-circle" style="background-color: <?= $codigo ?>"></span>
                                            <small><?= htmlspecialchars($nombre) ?></small>
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
                        document.querySelectorAll('#edit_colores_container .color-option').forEach(option => {
                            option.classList.remove('active');
                            const radio = option.querySelector('input[type="radio"]');
                            if (radio && radio.value === color) {
                                option.classList.add('active');
                                radio.checked = true;
                            }
                        });
                    }
                });
            });

            // Seleccionar color al hacer clic
            document.querySelectorAll('.color-option').forEach(option => {
                option.addEventListener('click', function() {
                    const radio = this.querySelector('input[type="radio"]');
                    if (radio) {
                        // Remover selección anterior en el mismo grupo
                        const group = this.closest('.modal-body') || this.closest('#edit_colores_container');
                        group.querySelectorAll('.color-option').forEach(opt => {
                            opt.classList.remove('active');
                        });
                        // Marcar como seleccionado
                        this.classList.add('active');
                        radio.checked = true;
                    }
                });
            });

            // Inicializar selección de color en modal de agregar
            document.getElementById('addCategoryModal').addEventListener('show.bs.modal', function() {
                const firstColor = document.querySelector('#addCategoryModal .color-option:first-child');
                if (firstColor) {
                    firstColor.classList.add('active');
                    const radio = firstColor.querySelector('input[type="radio"]');
                    if (radio) radio.checked = true;
                }
            });

            // Resetear modales al cerrar
            document.querySelectorAll('.modal').forEach(modal => {
                modal.addEventListener('hidden.bs.modal', function() {
                    const form = this.querySelector('form');
                    if (form) form.reset();
                    this.querySelectorAll('.color-option').forEach(opt => {
                        opt.classList.remove('active');
                    });
                });
            });

            // Auto-focus en búsqueda
            const searchInput = document.getElementById('search');
            if (searchInput && !searchInput.value) {
                searchInput.focus();
            }
        });
    </script>
</body>
</html>