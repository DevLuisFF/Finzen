<?php
// Conexión a la base de datos
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "finzen";

try {
    $conn = new PDO(
        "mysql:host=$servername;dbname=$dbname",
        $username,
        $password
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Conexión fallida: " . $e->getMessage());
}

// Obtener usuarios para el select
$stmtUsuarios = $conn->prepare("SELECT id, nombre_usuario FROM usuarios");
$stmtUsuarios->execute();
$usuarios = $stmtUsuarios->fetchAll(PDO::FETCH_ASSOC);

// Tipos de categorías
$tipos = [
    "ingreso" => "Ingreso",
    "gasto" => "Gasto",
    "transferencia" => "Transferencia",
];

// Iconos disponibles
$iconos = [
    "fa-shopping-cart" => "Compras",
    "fa-utensils" => "Comida",
    "fa-car" => "Transporte",
    "fa-home" => "Hogar",
    "fa-tv" => "Entretenimiento",
    "fa-heartbeat" => "Salud",
    "fa-graduation-cap" => "Educación",
    "fa-money-bill-wave" => "Ingresos",
    "fa-piggy-bank" => "Ahorros",
    "fa-gift" => "Regalos",
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

// Procesar solicitudes
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $usuario_id = filter_input(INPUT_POST, "usuario_id", FILTER_VALIDATE_INT);
    $nombre = filter_input(INPUT_POST, "nombre", FILTER_SANITIZE_STRING);
    $tipo = filter_input(INPUT_POST, "tipo", FILTER_SANITIZE_STRING);
    $icono = filter_input(INPUT_POST, "icono", FILTER_SANITIZE_STRING);
    $color = filter_input(INPUT_POST, "color", FILTER_SANITIZE_STRING);

    if (
        !$usuario_id ||
        empty($nombre) ||
        empty($tipo) ||
        empty($icono) ||
        empty($color)
    ) {
        die("Datos inválidos.");
    }

    if (isset($_POST["create"])) {
        // Crear categoría
        try {
            $stmt = $conn->prepare("INSERT INTO categorias (usuario_id, nombre, tipo, icono, color, creado_en, actualizado_en)
                                    VALUES (:usuario_id, :nombre, :tipo, :icono, :color, NOW(), NOW())");
            $stmt->bindParam(":usuario_id", $usuario_id, PDO::PARAM_INT);
            $stmt->bindParam(":nombre", $nombre, PDO::PARAM_STR);
            $stmt->bindParam(":tipo", $tipo, PDO::PARAM_STR);
            $stmt->bindParam(":icono", $icono, PDO::PARAM_STR);
            $stmt->bindParam(":color", $color, PDO::PARAM_STR);
            $stmt->execute();
        } catch (PDOException $e) {
            die("Error al crear categoría: " . $e->getMessage());
        }
    } elseif (isset($_POST["update"])) {
        // Actualizar categoría
        $id = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);
        if (!$id) {
            die("ID inválido.");
        }

        try {
            $stmt = $conn->prepare("UPDATE categorias SET
                                    usuario_id = :usuario_id,
                                    nombre = :nombre,
                                    tipo = :tipo,
                                    icono = :icono,
                                    color = :color,
                                    actualizado_en = NOW()
                                    WHERE id = :id");
            $stmt->bindParam(":id", $id, PDO::PARAM_INT);
            $stmt->bindParam(":usuario_id", $usuario_id, PDO::PARAM_INT);
            $stmt->bindParam(":nombre", $nombre, PDO::PARAM_STR);
            $stmt->bindParam(":tipo", $tipo, PDO::PARAM_STR);
            $stmt->bindParam(":icono", $icono, PDO::PARAM_STR);
            $stmt->bindParam(":color", $color, PDO::PARAM_STR);
            $stmt->execute();
        } catch (PDOException $e) {
            die("Error al actualizar categoría: " . $e->getMessage());
        }
    }

    header("Location: " . $_SERVER["PHP_SELF"]);
    exit();
}

// Eliminar categoría
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["delete"])) {
    $id = filter_input(INPUT_GET, "delete", FILTER_VALIDATE_INT);
    if ($id) {
        try {
            $stmt = $conn->prepare("DELETE FROM categorias WHERE id = :id");
            $stmt->bindParam(":id", $id, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            die("Error al eliminar categoría: " . $e->getMessage());
        }
    }
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit();
}

// Obtener categorías
try {
    $stmt = $conn->prepare(
        "SELECT c.*, u.nombre_usuario FROM categorias c LEFT JOIN usuarios u ON c.usuario_id = u.id"
    );
    $stmt->execute();
    $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error al obtener categorías: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administración de Categorías - FinZen</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #083D77;
            --secondary: #10b981;
            --error: #ef4444;
            --background: #f9fafb;
            --surface: #ffffff;
            --on-surface: #111827;
            --on-background: #374151;
            --border: #e5e7eb;
            --border-radius: 8px;
            --spacing: 1rem;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--background);
            color: var(--on-background);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        .container {
            display: grid;
            grid-template-columns: 240px 1fr;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            background-color: var(--surface);
            border-right: 1px solid var(--border);
            padding: var(--spacing);
            position: fixed;
            height: 100vh;
            width: 240px;
        }

        .logo {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            color: var(--primary);
            padding: 0 0.5rem;
        }

        .logo i {
            margin-right: 0.5rem;
        }

        .menu-item {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            margin-bottom: 0.25rem;
            border-radius: 6px;
            text-decoration: none;
            color: var(--on-background);
            font-size: 0.875rem;
            font-weight: 500;
        }

        .menu-item i {
            margin-right: 1rem;
            opacity: 0.8;
        }

        .menu-item.active {
            background-color: rgba(8, 61, 119, 0.1);
            color: var(--primary);
        }

        .menu-item:hover:not(.active) {
            background-color: rgba(0, 0, 0, 0.03);
        }

        /* Main content */
        .main-content {
            grid-column: 2;
            padding: 2rem;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--on-surface);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 500;
            cursor: pointer;
            border: none;
            font-size: 0.875rem;
            gap: 0.5rem;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .btn-outlined {
            background-color: transparent;
            border: 1px solid var(--border);
            color: var(--on-background);
        }

        /* Cards */
        .card {
            background-color: var(--surface);
            border-radius: var(--border-radius);
            border: 1px solid var(--border);
            padding: var(--spacing);
            margin-bottom: var(--spacing);
            box-shadow: var(--shadow-sm);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--spacing);
        }

        .card-title {
            font-size: 1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            color: var(--on-surface);
            gap: 0.5rem;
        }

        /* Tables */
        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        .table th, .table td {
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        .table th {
            font-weight: 500;
            color: #6b7280;
            font-size: 0.75rem;
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .badge-success {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--secondary);
        }

        .badge-error {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--error);
        }

        .badge-info {
            background-color: rgba(8, 61, 119, 0.1);
            color: var(--primary);
        }

        /* Layout */
        .columns {
            display: grid;
            grid-template-columns: 1fr;
            gap: var(--spacing);
            margin-bottom: var(--spacing);
        }

        /* Utility classes */
        .text-success {
            color: var(--secondary);
        }

        .text-error {
            color: var(--error);
        }

        .mb-2 {
            margin-bottom: var(--spacing);
        }

        .flex {
            display: flex;
        }

        .items-center {
            align-items: center;
        }

        .justify-between {
            justify-content: space-between;
        }

        .gap-2 {
            gap: 0.5rem;
        }

        /* Modals */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal.is-active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-card {
            background-color: var(--surface);
            border-radius: var(--border-radius);
            width: 800px;
            max-width: 90%;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        }

        .modal-card-head {
            border-bottom: 1px solid var(--border);
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-card-title {
            font-weight: 600;
            font-size: 1.25rem;
        }

        .modal-card-body {
            padding: 1.5rem;
            max-height: calc(100vh - 200px);
            overflow-y: auto;
        }

        .modal-card-foot {
            border-top: 1px solid var(--border);
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }

        .delete {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--on-background);
        }

        /* Form fields */
        .field {
            margin-bottom: 1rem;
        }

        .label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--on-background);
        }

        .input, .select {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid var(--border);
            border-radius: var(--border-radius);
            font-size: 1rem;
        }

        .checkbox {
            display: flex;
            align-items: center;
            color: var(--on-background);
        }

        .checkbox input {
            margin-right: 0.5rem;
        }

        /* Color and icon preview */
        .color-circle {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
            border: 1px solid var(--border);
            vertical-align: middle;
        }

        .icon-preview {
            font-size: 1.2rem;
            margin-right: 5px;
            color: var(--on-background);
            vertical-align: middle;
        }

        /* Color selection */
        .color-options {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 1rem;
            margin-top: 0.5rem;
        }

        .color-option {
            display: flex;
            align-items: center;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 6px;
            transition: background-color 0.3s ease;
        }

        .color-option:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }

        .color-option input {
            margin-right: 0.5rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                grid-template-columns: 1fr;
            }

            .sidebar {
                display: none;
            }

            .main-content {
                grid-column: 1;
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="logo">
                <i class="material-icons">account_balance</i>
                <span>FinZen</span>
            </div>

            <nav>
                <a href="index.php" class="menu-item">
                    <i class="material-icons">dashboard</i>
                    <span>Dashboard</span>
                </a>
                <a href="roles.php" class="menu-item">
                    <i class="material-icons">assignment_ind</i>
                    <span>Roles</span>
                </a>
                <a href="usuarios.php" class="menu-item">
                    <i class="material-icons">people</i>
                    <span>Usuarios</span>
                </a>
                <a href="cuentas.php" class="menu-item">
                    <i class="material-icons">account_balance_wallet</i>
                    <span>Cuentas</span>
                </a>
                <a href="#" class="menu-item active">
                    <i class="material-icons">category</i>
                    <span>Categorías</span>
                </a>
                <a href="presupuestos.php" class="menu-item">
                    <i class="material-icons">pie_chart</i>
                    <span>Presupuestos</span>
                </a>
                <a href="transacciones.php" class="menu-item">
                    <i class="material-icons">swap_horiz</i>
                    <span>Transacciones</span>
                </a>
                <a href="transacciones-recurrentes.php" class="menu-item">
                    <i class="material-icons">autorenew</i>
                    <span>Trans. Recurrentes</span>
                </a>
                <a href="../auth/logout.php" class="menu-item">
                    <i class="material-icons">logout</i>
                    <span>Cerrar Sesión</span>
                </a>
            </nav>
        </div>

        <!-- Main content -->
        <div class="main-content">
            <!-- Header -->
            <header class="header">
                <h1 class="page-title">Administración de Categorías</h1>
                <button class="btn btn-primary modal-button" data-target="addCategoriaModal">
                    <span class="icon"><i class="material-icons">add</i></span>
                    <span>Nueva Categoría</span>
                </button>
            </header>

            <!-- Categories Table -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="material-icons">category</i>
                        <span>Categorías</span>
                    </h2>
                </div>
                <div class="card-content">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nombre</th>
                                <th>Usuario</th>
                                <th>Tipo</th>
                                <th>Icono</th>
                                <th>Color</th>
                                <th>Creado</th>
                                <th>Actualizado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categorias as $categoria): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($categoria["id"]); ?></td>
                                <td><?php echo htmlspecialchars($categoria["nombre"]); ?></td>
                                <td><?php echo htmlspecialchars($categoria["nombre_usuario"] ?? "Sin usuario"); ?></td>
                                <td>
                                    <span class="badge <?php echo $categoria["tipo"] === "ingreso" ? "badge-success" : ($categoria["tipo"] === "gasto" ? "badge-error" : "badge-info"); ?>">
                                        <?php echo htmlspecialchars($tipos[$categoria["tipo"]] ?? $categoria["tipo"]); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($categoria["icono"])): ?>
                                        <i class="fas <?php echo htmlspecialchars($categoria["icono"]); ?> icon-preview"></i>
                                        <?php echo htmlspecialchars($iconos[$categoria["icono"]] ?? $categoria["icono"]); ?>
                                    <?php else: ?>
                                        <span>Sin icono</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="color-circle" style="background-color: <?php echo htmlspecialchars($categoria["color"]); ?>"></span>
                                    <?php echo htmlspecialchars($colores[$categoria["color"]] ?? $categoria["color"]); ?>
                                </td>
                                <td><?php echo htmlspecialchars($categoria["creado_en"]); ?></td>
                                <td><?php echo htmlspecialchars($categoria["actualizado_en"]); ?></td>
                                <td>
                                    <button class="btn btn-outlined btn-action edit-button" data-target="editCategoriaModal"
                                            data-id="<?php echo $categoria["id"]; ?>"
                                            data-usuario_id="<?php echo $categoria["usuario_id"]; ?>"
                                            data-nombre="<?php echo htmlspecialchars($categoria["nombre"]); ?>"
                                            data-tipo="<?php echo $categoria["tipo"]; ?>"
                                            data-icono="<?php echo $categoria["icono"]; ?>"
                                            data-color="<?php echo $categoria["color"]; ?>">
                                        <span class="icon"><i class="material-icons">edit</i></span>
                                    </button>
                                    <a href="?delete=<?php echo $categoria["id"]; ?>" class="btn btn-outlined btn-action"
                                       onclick="return confirm('¿Estás seguro de eliminar esta categoría?')">
                                        <span class="icon"><i class="material-icons">delete</i></span>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Category Modal -->
    <div class="modal" id="addCategoriaModal">
        <div class="modal-background" onclick="closeModal('addCategoriaModal')"></div>
        <div class="modal-card">
            <header class="modal-card-head">
                <p class="modal-card-title">Agregar Nueva Categoría</p>
                <button class="delete" onclick="closeModal('addCategoriaModal')"></button>
            </header>
            <form method="POST" action="">
                <section class="modal-card-body">
                    <div class="field">
                        <label class="label">Nombre de la Categoría</label>
                        <div class="control">
                            <input class="input" type="text" name="nombre" required placeholder="Ej: Comida, Transporte, etc.">
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Usuario</label>
                        <div class="control">
                            <div class="select">
                                <select name="usuario_id" required>
                                    <option value="">Seleccionar Usuario</option>
                                    <?php foreach ($usuarios as $usuario): ?>
                                        <option value="<?php echo $usuario["id"]; ?>">
                                            <?php echo htmlspecialchars($usuario["nombre_usuario"]); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Tipo</label>
                        <div class="control">
                            <div class="select">
                                <select name="tipo" required>
                                    <option value="">Seleccionar Tipo</option>
                                    <?php foreach ($tipos as $valor => $nombre): ?>
                                        <option value="<?php echo $valor; ?>">
                                            <?php echo htmlspecialchars($nombre); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Icono</label>
                        <div class="control">
                            <div class="select">
                                <select name="icono" required>
                                    <option value="">Seleccionar Icono</option>
                                    <?php foreach ($iconos as $valor => $nombre): ?>
                                        <option value="<?php echo $valor; ?>">
                                            <?php echo htmlspecialchars($nombre); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Color</label>
                        <div class="control">
                            <div class="color-options">
                                <?php foreach ($colores as $valor => $nombre): ?>
                                    <label class="color-option">
                                        <input type="radio" name="color" value="<?php echo $valor; ?>" required>
                                        <span class="color-circle" style="background-color: <?php echo $valor; ?>"></span>
                                        <span><?php echo htmlspecialchars($nombre); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </section>
                <footer class="modal-card-foot">
                    <button class="btn btn-outlined" type="button" onclick="closeModal('addCategoriaModal')">Cancelar</button>
                    <button class="btn btn-primary" type="submit" name="create">Guardar</button>
                </footer>
            </form>
        </div>
    </div>

    <!-- Edit Category Modal -->
    <div class="modal" id="editCategoriaModal">
        <div class="modal-background" onclick="closeModal('editCategoriaModal')"></div>
        <div class="modal-card">
            <header class="modal-card-head">
                <p class="modal-card-title">Editar Categoría</p>
                <button class="delete" onclick="closeModal('editCategoriaModal')"></button>
            </header>
            <form method="POST" action="">
                <input type="hidden" id="edit_id" name="id">
                <section class="modal-card-body">
                    <div class="field">
                        <label class="label">Nombre de la Categoría</label>
                        <div class="control">
                            <input class="input" type="text" id="edit_nombre" name="nombre" required>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Usuario</label>
                        <div class="control">
                            <div class="select">
                                <select id="edit_usuario_id" name="usuario_id" required>
                                    <option value="">Seleccionar Usuario</option>
                                    <?php foreach ($usuarios as $usuario): ?>
                                        <option value="<?php echo $usuario["id"]; ?>">
                                            <?php echo htmlspecialchars($usuario["nombre_usuario"]); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Tipo</label>
                        <div class="control">
                            <div class="select">
                                <select id="edit_tipo" name="tipo" required>
                                    <option value="">Seleccionar Tipo</option>
                                    <?php foreach ($tipos as $valor => $nombre): ?>
                                        <option value="<?php echo $valor; ?>">
                                            <?php echo htmlspecialchars($nombre); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Icono</label>
                        <div class="control">
                            <div class="select">
                                <select id="edit_icono" name="icono" required>
                                    <option value="">Seleccionar Icono</option>
                                    <?php foreach ($iconos as $valor => $nombre): ?>
                                        <option value="<?php echo $valor; ?>">
                                            <?php echo htmlspecialchars($nombre); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Color</label>
                        <div class="control">
                            <div class="color-options" id="edit_colores_container">
                                <?php foreach ($colores as $valor => $nombre): ?>
                                    <label class="color-option">
                                        <input type="radio" name="color" value="<?php echo $valor; ?>" required>
                                        <span class="color-circle" style="background-color: <?php echo $valor; ?>"></span>
                                        <span><?php echo htmlspecialchars($nombre); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </section>
                <footer class="modal-card-foot">
                    <button class="btn btn-outlined" type="button" onclick="closeModal('editCategoriaModal')">Cancelar</button>
                    <button class="btn btn-primary" type="submit" name="update">Actualizar</button>
                </footer>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Modal functions
            function openModal(id) {
                const modal = document.getElementById(id);
                modal.classList.add('is-active');
            }

            function closeModal(id) {
                const modal = document.getElementById(id);
                modal.classList.remove('is-active');
            }

            function closeAllModals() {
                (document.querySelectorAll('.modal') || []).forEach(($modal) => {
                    $modal.classList.remove('is-active');
                });
            }

            // Open modals
            (document.querySelectorAll('.modal-button') || []).forEach(($trigger) => {
                const modalId = $trigger.dataset.target;
                $trigger.addEventListener('click', () => {
                    openModal(modalId);
                });
            });

            // Close modals
            (document.querySelectorAll('.modal-background, .delete') || []).forEach(($close) => {
                $close.addEventListener('click', () => {
                    closeAllModals();
                });
            });

            // Close with ESC
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    closeAllModals();
                }
            });

            // Handle category edit
            const editButtons = document.querySelectorAll('.edit-button');
            editButtons.forEach(button => {
                button.addEventListener('click', () => {
                    document.getElementById('edit_id').value = button.dataset.id;
                    document.getElementById('edit_nombre').value = button.dataset.nombre;
                    document.getElementById('edit_usuario_id').value = button.dataset.usuario_id;
                    document.getElementById('edit_tipo').value = button.dataset.tipo;
                    document.getElementById('edit_icono').value = button.dataset.icono;

                    // Set selected color
                    let color = button.dataset.color;
                    if (color) {
                        let colorInput = document.querySelector(`#edit_colores_container input[value="${color}"]`);
                        if (colorInput) {
                            colorInput.checked = true;
                        }
                    }

                    openModal('editCategoriaModal');
                });
            });
        });
    </script>
</body>
</html>
