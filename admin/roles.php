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

// Función para validar datos de entrada
function validateInput($data, $filter)
{
    return filter_var($data, $filter);
}

// Función para manejar errores
function handleError($message)
{
    die($message);
}

// Función genérica para manejar operaciones CRUD
function executeCrudOperation($conn, $operation, $data)
{
    $sql = "";
    $params = [];

    switch ($operation) {
        case "create":
            $sql =
                "INSERT INTO roles (nombre, creado_en, actualizado_en) VALUES (:nombre, NOW(), NOW())";
            $params = [
                "nombre" => validateInput(
                    $data["nombre"],
                    FILTER_SANITIZE_STRING
                ),
            ];
            break;
        case "update":
            $sql =
                "UPDATE roles SET nombre = :nombre, actualizado_en = NOW() WHERE id = :id";
            $params = [
                "id" => validateInput($data["id"], FILTER_VALIDATE_INT),
                "nombre" => validateInput(
                    $data["nombre"],
                    FILTER_SANITIZE_STRING
                ),
            ];
            break;
        case "delete":
            $sql = "DELETE FROM roles WHERE id = :id";
            $params = ["id" => validateInput($data["id"], FILTER_VALIDATE_INT)];
            break;
    }

    try {
        $stmt = $conn->prepare($sql);
        foreach ($params as $param => $value) {
            $stmt->bindParam(":$param", $value);
        }
        $stmt->execute();
        return ucfirst($operation) . " rol exitosamente.";
    } catch (PDOException $e) {
        return "Error al $operation rol: " . $e->getMessage();
    }
}

// Operaciones CRUD
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST["create"])) {
        $message = executeCrudOperation($conn, "create", $_POST);
    } elseif (isset($_POST["update"])) {
        $message = executeCrudOperation($conn, "update", $_POST);
    }
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit();
} elseif (isset($_GET["delete"])) {
    $message = executeCrudOperation($conn, "delete", ["id" => $_GET["delete"]]);
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit();
}

// Obtener todos los roles
try {
    $stmt = $conn->prepare("SELECT * FROM roles");
    $stmt->execute();
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    handleError("Error al obtener roles: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administración de Roles - FinZen</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
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
            width: 500px;
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

        .input {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid var(--border);
            border-radius: var(--border-radius);
            font-size: 1rem;
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
                <a href="#" class="menu-item active">
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
                <a href="categorias.php" class="menu-item">
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
                <h1 class="page-title">Administración de Roles</h1>
                <button class="btn btn-primary modal-button" data-target="addRoleModal">
                    <span class="icon"><i class="material-icons">add</i></span>
                    <span>Nuevo Rol</span>
                </button>
            </header>

            <!-- Tabla de roles -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="material-icons">assignment_ind</i>
                        <span>Roles</span>
                    </h2>
                </div>
                <div class="card-content">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nombre</th>
                                <th>Creado en</th>
                                <th>Actualizado en</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($roles as $rol): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($rol["id"]); ?></td>
                                <td><?php echo htmlspecialchars($rol["nombre"]); ?></td>
                                <td><?php echo htmlspecialchars($rol["creado_en"]); ?></td>
                                <td><?php echo htmlspecialchars($rol["actualizado_en"]); ?></td>
                                <td>
                                    <button class="btn btn-outlined btn-action" data-target="editRoleModal" data-id="<?php echo $rol["id"]; ?>" data-nombre="<?php echo htmlspecialchars($rol["nombre"]); ?>">
                                        <span class="icon"><i class="material-icons">edit</i></span>
                                    </button>
                                    <a href="?delete=<?php echo $rol["id"]; ?>" class="btn btn-outlined btn-action" onclick="return confirm('¿Estás seguro de eliminar este rol?')">
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

    <!-- Modal para agregar nuevo rol -->
    <div class="modal" id="addRoleModal">
        <div class="modal-background" onclick="closeModal('addRoleModal')"></div>
        <div class="modal-card">
            <header class="modal-card-head">
                <p class="modal-card-title">Agregar Nuevo Rol</p>
                <button class="delete" onclick="closeModal('addRoleModal')"></button>
            </header>
            <form method="POST" action="">
                <section class="modal-card-body">
                    <div class="field">
                        <label class="label">Nombre del Rol</label>
                        <div class="control">
                            <input class="input" type="text" name="nombre" required placeholder="Ej: Administrador">
                        </div>
                    </div>
                </section>
                <footer class="modal-card-foot">
                    <button class="btn btn-outlined" type="button" onclick="closeModal('addRoleModal')">Cancelar</button>
                    <button class="btn btn-primary" type="submit" name="create">Guardar</button>
                </footer>
            </form>
        </div>
    </div>

    <!-- Modal para editar rol -->
    <div class="modal" id="editRoleModal">
        <div class="modal-background" onclick="closeModal('editRoleModal')"></div>
        <div class="modal-card">
            <header class="modal-card-head">
                <p class="modal-card-title">Editar Rol</p>
                <button class="delete" onclick="closeModal('editRoleModal')"></button>
            </header>
            <form method="POST" action="">
                <input type="hidden" id="edit_id" name="id">
                <section class="modal-card-body">
                    <div class="field">
                        <label class="label">Nombre del Rol</label>
                        <div class="control">
                            <input class="input" type="text" id="edit_nombre" name="nombre" required>
                        </div>
                    </div>
                </section>
                <footer class="modal-card-foot">
                    <button class="btn btn-outlined" type="button" onclick="closeModal('editRoleModal')">Cancelar</button>
                    <button class="btn btn-primary" type="submit" name="update">Actualizar</button>
                </footer>
            </form>
        </div>
    </div>

    <script>
        // Script para manejar los modales
        document.addEventListener('DOMContentLoaded', () => {
            // Abrir modales
            (document.querySelectorAll('.modal-button') || []).forEach(($trigger) => {
                const modal = $trigger.dataset.target;
                const $target = document.getElementById(modal);

                $trigger.addEventListener('click', () => {
                    openModal($target);
                });
            });

            // Manejar la edición de roles
            const editButtons = document.querySelectorAll('[data-target="editRoleModal"]');
            editButtons.forEach(button => {
                button.addEventListener('click', () => {
                    document.getElementById('edit_id').value = button.dataset.id;
                    document.getElementById('edit_nombre').value = button.dataset.nombre;

                    const modal = document.getElementById('editRoleModal');
                    openModal(modal);
                });
            });
        });

        function openModal($el) {
            $el.classList.add('is-active');
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

        // Cerrar con ESC
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeAllModals();
            }
        });
    </script>
</body>
</html>
