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

// Obtener filtros de la URL
$filters = [
    'categoria_id' => $_GET['categoria_id'] ?? '',
    'tipo' => $_GET['tipo'] ?? '',
    'fecha_desde' => $_GET['fecha_desde'] ?? '',
    'fecha_hasta' => $_GET['fecha_hasta'] ?? '',
    'formato_exportacion' => $_GET['formato_exportacion'] ?? 'csv',
];

// Construir consulta base para reportes
$whereConditions = ["t.usuario_id = :usuario_id"];
$params = [":usuario_id" => $usuario_id];

if (!empty($filters['categoria_id'])) {
    $whereConditions[] = "t.categoria_id = :categoria_id";
    $params[":categoria_id"] = $filters['categoria_id'];
}

if (!empty($filters['tipo'])) {
    $whereConditions[] = "cat.tipo = :tipo";
    $params[":tipo"] = $filters['tipo'];
}

if (!empty($filters['fecha_desde'])) {
    $whereConditions[] = "t.fecha >= :fecha_desde";
    $params[":fecha_desde"] = $filters['fecha_desde'];
}

if (!empty($filters['fecha_hasta'])) {
    $whereConditions[] = "t.fecha <= :fecha_hasta";
    $params[":fecha_hasta"] = $filters['fecha_hasta'];
}

$whereClause = implode(" AND ", $whereConditions);

// Función para exportar datos
function exportData($data, $filename, $format = 'csv') {
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Escribir encabezados
        if (!empty($data)) {
            fputcsv($output, array_keys($data[0]));
            
            // Escribir datos
            foreach ($data as $row) {
                fputcsv($output, $row);
            }
        }
        
        fclose($output);
    } elseif ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.json"');
        
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    exit();
}

// Manejar exportación
if (isset($_GET['export'])) {
    $stmt = $db->prepare("
        SELECT
            t.id,
            t.fecha,
            t.descripcion,
            cat.nombre as categoria,
            cat.tipo,
            t.monto,
            CASE 
                WHEN cat.tipo = 'ingreso' THEN t.monto
                ELSE -t.monto
            END as monto_signed,
            t.creado_en,
            t.actualizado_en
        FROM transacciones t
        INNER JOIN categorias cat ON t.categoria_id = cat.id
        WHERE $whereClause
        ORDER BY t.fecha DESC, t.creado_en DESC
    ");
    
    foreach ($params as $key => $value) {
        if (is_int($value)) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    
    $stmt->execute();
    $exportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatear datos para exportación
    foreach ($exportData as &$row) {
        $row['monto_formateado'] = formatMoney($row['monto']);
        $row['monto_signed_formateado'] = formatMoney(abs($row['monto_signed']));
        $row['tipo_es'] = $row['tipo'] === 'ingreso' ? 'Ingreso' : 'Gasto';
    }
    
    $filename = 'reporte_finzen_' . date('Y-m-d_H-i');
    if (!empty($filters['fecha_desde'])) {
        $filename .= '_desde_' . $filters['fecha_desde'];
    }
    if (!empty($filters['fecha_hasta'])) {
        $filename .= '_hasta_' . $filters['fecha_hasta'];
    }
    
    exportData($exportData, $filename, $filters['formato_exportacion']);
}

// Obtener estadísticas para el resumen
$stmtStats = $db->prepare("
    SELECT
        COUNT(*) as total_transacciones,
        COALESCE(SUM(CASE WHEN cat.tipo = 'ingreso' THEN t.monto ELSE 0 END), 0) as total_ingresos,
        COALESCE(SUM(CASE WHEN cat.tipo = 'gasto' THEN t.monto ELSE 0 END), 0) as total_gastos,
        COALESCE(SUM(CASE WHEN cat.tipo = 'ingreso' THEN t.monto ELSE -t.monto END), 0) as balance,
        MIN(t.fecha) as fecha_minima,
        MAX(t.fecha) as fecha_maxima
    FROM transacciones t
    INNER JOIN categorias cat ON t.categoria_id = cat.id
    WHERE $whereClause
");

foreach ($params as $key => $value) {
    if (is_int($value)) {
        $stmtStats->bindValue($key, $value, PDO::PARAM_INT);
    } else {
        $stmtStats->bindValue($key, $value);
    }
}

$stmtStats->execute();
$stats = $stmtStats->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finzen | Reportes y Exportación</title>
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
        
        .stats-card {
            border-left: 4px solid;
            position: relative;
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
        
        .stats-card.info {
            border-left-color: var(--bs-info);
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
        
        .category-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            color: white;
            font-size: 1rem;
        }
        
        .amount.ingreso {
            color: var(--bs-success);
            font-weight: 600;
        }
        
        .amount.gasto {
            color: var(--bs-danger);
            font-weight: 600;
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
        
        .dropdown-menu {
            border: none;
            border-radius: 0.75rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            padding: 0.5rem;
        }
        
        .dropdown-item {
            border-radius: 0.5rem;
            padding: 0.5rem 1rem;
            font-weight: 500;
        }
        
        .dropdown-item:hover {
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            color: var(--bs-primary);
        }
        
        .transaction-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            font-size: 1rem;
        }
        
        .export-preview {
            background: #f8f9fa;
            border: 2px dashed #dee2e6;
            border-radius: 1rem;
            padding: 2rem;
            text-align: center;
        }
        
        .feature-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.5rem;
        }
        
        @media (max-width: 768px) {
            .container {
                padding-left: 1rem;
                padding-right: 1rem;
            }
            
            .table-responsive {
                font-size: 0.875rem;
            }
            
            .category-icon, .transaction-icon {
                width: 32px;
                height: 32px;
                margin-right: 8px;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light sticky-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <i class="bi bi-wallet2 me-2"></i> Finzen
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
                        <a class="nav-link" href="transacciones.php">
                            <i class="bi bi-arrow-left-right me-2"></i> Transacciones
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reportes.php" class="nav-link active">
                            <i class="bi bi-graph-up me-2"></i>Reportes
                        </a>
                    </li>
                </ul>
                <div class="d-flex align-items-center gap-2">
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
                <h1 class="mb-1">Reportes y Exportación</h1>
                <p class="text-muted mb-0">Exporta tus datos financieros en diferentes formatos</p>
            </div>
        </div>

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

        <!-- Estadísticas -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card stats-card info">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title text-muted mb-1">Transacciones</h6>
                                <h3 class="text-info mb-0"><?= number_format($stats['total_transacciones']) ?></h3>
                                <small class="text-muted">Total encontradas</small>
                            </div>
                            <div class="metric-icon bg-info bg-opacity-10 text-info">
                                <i class="bi bi-list-check"></i>
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
                                <small class="text-muted">Acumulado</small>
                            </div>
                            <div class="metric-icon bg-success bg-opacity-10 text-success">
                                <i class="bi bi-arrow-down-circle"></i>
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
                                <small class="text-muted">Acumulado</small>
                            </div>
                            <div class="metric-icon bg-danger bg-opacity-10 text-danger">
                                <i class="bi bi-arrow-up-circle"></i>
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
                                <small class="text-muted">Resultado neto</small>
                            </div>
                            <div class="metric-icon bg-warning bg-opacity-10 text-warning">
                                <i class="bi bi-graph-up"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-4">
                    <i class="bi bi-funnel me-2"></i>Filtrar Datos para Exportación
                </h5>
                <form method="GET" action="" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label for="categoria_id" class="form-label">Categoría</label>
                        <select class="form-select" id="categoria_id" name="categoria_id">
                            <option value="">Todas las categorías</option>
                            <?php foreach ($categorias as $categoria): ?>
                                <option value="<?= $categoria["id"] ?>" <?= ($filters['categoria_id'] == $categoria["id"]) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($categoria["nombre"]) ?> (<?= $categoria["tipo"] === 'ingreso' ? 'Ingreso' : 'Gasto' ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="tipo" class="form-label">Tipo</label>
                        <select class="form-select" id="tipo" name="tipo">
                            <option value="">Todos</option>
                            <option value="ingreso" <?= ($filters['tipo'] === 'ingreso') ? 'selected' : '' ?>>Ingreso</option>
                            <option value="gasto" <?= ($filters['tipo'] === 'gasto') ? 'selected' : '' ?>>Gasto</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="fecha_desde" class="form-label">Desde</label>
                        <input type="date" class="form-control" id="fecha_desde" name="fecha_desde" 
                               value="<?= $filters['fecha_desde'] ?>" 
                               max="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="fecha_hasta" class="form-label">Hasta</label>
                        <input type="date" class="form-control" id="fecha_hasta" name="fecha_hasta" 
                               value="<?= $filters['fecha_hasta'] ?: date('Y-m-d') ?>"
                               max="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="formato_exportacion" class="form-label">Formato de Exportación</label>
                        <select class="form-select" id="formato_exportacion" name="formato_exportacion">
                            <option value="csv" <?= ($filters['formato_exportacion'] === 'csv') ? 'selected' : '' ?>>CSV (Excel)</option>
                            <option value="json" <?= ($filters['formato_exportacion'] === 'json') ? 'selected' : '' ?>>JSON</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <div class="d-flex gap-2 justify-content-end">
                            <button type="reset" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-clockwise me-1"></i> Limpiar Filtros
                            </button>
                            <button type="submit" name="apply_filters" class="btn btn-primary">
                                <i class="bi bi-funnel me-1"></i> Aplicar Filtros
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Información del Reporte -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-4">
                    <i class="bi bi-info-circle me-2"></i>Resumen del Reporte
                </h5>
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Período:</strong> 
                            <?php if ($stats['fecha_minima'] && $stats['fecha_maxima']): ?>
                                <?= date('d/m/Y', strtotime($stats['fecha_minima'])) ?> - <?= date('d/m/Y', strtotime($stats['fecha_maxima'])) ?>
                            <?php else: ?>
                                Sin datos
                            <?php endif; ?>
                        </p>
                        <p><strong>Transacciones incluidas:</strong> <?= number_format($stats['total_transacciones']) ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Ingresos totales:</strong> <?= formatMoney($stats['total_ingresos']) ?></p>
                        <p><strong>Gastos totales:</strong> <?= formatMoney($stats['total_gastos']) ?></p>
                        <p><strong>Balance neto:</strong> 
                            <span class="<?= $stats['balance'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                <?= formatMoney($stats['balance']) ?>
                            </span>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Opciones de Exportación -->
        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-4">
                    <i class="bi bi-download me-2"></i>Exportar Datos
                </h5>
                
                <div class="export-preview mb-4">
                    <div class="feature-icon bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-file-earmark-arrow-down"></i>
                    </div>
                    <h4>Listo para exportar</h4>
                    <p class="text-muted mb-4">
                        Tu reporte incluye <?= number_format($stats['total_transacciones']) ?> transacciones 
                        con un balance total de <?= formatMoney($stats['balance']) ?>
                    </p>
                    
                    <div class="d-flex gap-2 justify-content-center flex-wrap">
                        <button type="button" class="btn btn-success btn-lg" 
                                onclick="exportData('csv')">
                            <i class="bi bi-file-earmark-spreadsheet me-2"></i>
                            Exportar como CSV
                        </button>
                        <button type="button" class="btn btn-info btn-lg" 
                                onclick="exportData('json')">
                            <i class="bi bi-file-code me-2"></i>
                            Exportar como JSON
                        </button>
                    </div>
                    
                    <div class="mt-3 text-muted small">
                        <i class="bi bi-info-circle me-1"></i>
                        El archivo incluirá: ID, Fecha, Descripción, Categoría, Tipo, Monto y Fechas de creación/actualización
                    </div>
                </div>

                <!-- Información de formatos -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card border-0 bg-light">
                            <div class="card-body">
                                <h6><i class="bi bi-file-earmark-spreadsheet text-success me-2"></i>Formato CSV</h6>
                                <p class="small mb-0">Ideal para Excel, Google Sheets y análisis de datos. Formato tabular compatible con la mayoría de aplicaciones.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card border-0 bg-light">
                            <div class="card-body">
                                <h6><i class="bi bi-file-code text-info me-2"></i>Formato JSON</h6>
                                <p class="small mb-0">Perfecto para desarrolladores, integraciones con otras aplicaciones y procesamiento programático.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function exportData(format) {
            // Actualizar el formato seleccionado
            document.getElementById('formato_exportacion').value = format;
            
            // Crear un formulario temporal para enviar la solicitud de exportación
            const form = document.createElement('form');
            form.method = 'GET';
            form.action = '';
            
            // Agregar todos los parámetros actuales
            const params = new URLSearchParams(window.location.search);
            params.forEach((value, key) => {
                if (key !== 'export') {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    input.value = value;
                    form.appendChild(input);
                }
            });
            
            // Agregar parámetro de exportación
            const exportInput = document.createElement('input');
            exportInput.type = 'hidden';
            exportInput.name = 'export';
            exportInput.value = '1';
            form.appendChild(exportInput);
            
            // Agregar formato seleccionado
            const formatInput = document.createElement('input');
            formatInput.type = 'hidden';
            formatInput.name = 'formato_exportacion';
            formatInput.value = format;
            form.appendChild(formatInput);
            
            document.body.appendChild(form);
            form.submit();
        }
        
        // Validación de fechas
        document.getElementById('fecha_desde').addEventListener('change', function() {
            const fechaHasta = document.getElementById('fecha_hasta');
            if (this.value && fechaHasta.value && this.value > fechaHasta.value) {
                fechaHasta.value = this.value;
            }
        });
        
        document.getElementById('fecha_hasta').addEventListener('change', function() {
            const fechaDesde = document.getElementById('fecha_desde');
            if (this.value && fechaDesde.value && this.value < fechaDesde.value) {
                fechaDesde.value = this.value;
            }
        });
    </script>
</body>
</html>