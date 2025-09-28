<?php
// Conexión a la base de datos
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "finzen";

try {
    $conn = new PDO(
        "mysql:host=$servername;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    exit("Conexión fallida: " . $e->getMessage());
}

// Obtener roles para el select
function getRoles(PDO $conn): array
{
    try {
        $stmt = $conn->query("SELECT id, nombre FROM roles");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        exit("Error al obtener roles: " . $e->getMessage());
    }
}

$roles = getRoles($conn);
$message = ""; // Para mensajes de éxito o error

// Valida y filtra entradas
function validateInput($value, $filter)
{
    return filter_var($value, $filter);
}

// Error handler simple
function handleError(string $msg): void
{
    exit($msg);
}

// Crear usuario
function createUser(PDO $conn, array $data, array $roles): string
{
    $nombre_usuario = validateInput(
        $data["nombre_usuario"] ?? "",
        FILTER_SANITIZE_STRING
    );
    $correo_electronico = validateInput(
        $data["correo_electronico"] ?? "",
        FILTER_SANITIZE_EMAIL
    );
    $contraseña = $data["contraseña"] ?? "";
    $rol_id = validateInput($data["rol_id"] ?? null, FILTER_VALIDATE_INT);
    $activo = !empty($data["activo"]) ? 1 : 0;

    // Validaciones
    if (!$nombre_usuario || !$correo_electronico || empty($contraseña)) {
        return "Todos los campos son obligatorios.";
    }
    if (!filter_var($correo_electronico, FILTER_VALIDATE_EMAIL)) {
        return "Correo electrónico no válido.";
    }
    if (!$rol_id || !in_array($rol_id, array_column($roles, "id"), true)) {
        return "Rol no válido.";
    }

    $hash_contraseña = password_hash($contraseña, PASSWORD_DEFAULT);

    try {
        $sql = "
            INSERT INTO usuarios
            (nombre_usuario, correo_electronico, hash_contraseña, rol_id, activo, creado_en, actualizado_en)
            VALUES
            (:nombre_usuario, :correo_electronico, :hash_contrasena, :rol_id, :activo, NOW(), NOW())
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ":nombre_usuario" => $nombre_usuario,
            ":correo_electronico" => $correo_electronico,
            ":hash_contrasena" => $hash_contraseña,
            ":rol_id" => $rol_id,
            ":activo" => $activo,
        ]);

        return "Usuario creado exitosamente.";
    } catch (PDOException $e) {
        return "Error al crear usuario: " . $e->getMessage();
    }
}

// Actualizar usuario
function updateUser(PDO $conn, array $data, array $roles): string
{
    $id = validateInput($data["id"] ?? null, FILTER_VALIDATE_INT);
    $nombre_usuario = validateInput(
        $data["nombre_usuario"] ?? "",
        FILTER_SANITIZE_STRING
    );
    $correo_electronico = validateInput(
        $data["correo_electronico"] ?? "",
        FILTER_SANITIZE_EMAIL
    );
    $rol_id = validateInput($data["rol_id"] ?? null, FILTER_VALIDATE_INT);
    $activo = !empty($data["activo"]) ? 1 : 0;

    // Validaciones
    if (!$id || !$nombre_usuario || !$correo_electronico) {
        return "Todos los campos son obligatorios.";
    }
    if (!filter_var($correo_electronico, FILTER_VALIDATE_EMAIL)) {
        return "Correo electrónico no válido.";
    }
    if (!$rol_id || !in_array($rol_id, array_column($roles, "id"), true)) {
        return "Rol no válido.";
    }

    try {
        $conn->beginTransaction();

        $sql = "
            UPDATE usuarios SET
                nombre_usuario     = :nombre_usuario,
                correo_electronico = :correo_electronico,
                rol_id             = :rol_id,
                activo             = :activo,
                actualizado_en     = NOW()
            WHERE id = :id
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ":id" => $id,
            ":nombre_usuario" => $nombre_usuario,
            ":correo_electronico" => $correo_electronico,
            ":rol_id" => $rol_id,
            ":activo" => $activo,
        ]);

        // Si viene nueva contraseña, la actualiza
        if (!empty($data["contraseña"])) {
            $hash_contraseña = password_hash(
                $data["contraseña"],
                PASSWORD_DEFAULT
            );
            $stmtPass = $conn->prepare("
                UPDATE usuarios
                SET hash_contraseña = :hash_contrasena
                WHERE id = :id
            ");
            $stmtPass->execute([
                ":hash_contrasena" => $hash_contraseña,
                ":id" => $id,
            ]);
        }

        $conn->commit();
        return "Usuario actualizado exitosamente.";
    } catch (PDOException $e) {
        $conn->rollBack();
        return "Error al actualizar usuario: " . $e->getMessage();
    }
}

// Eliminar usuario
function deleteUser(PDO $conn, $id): string
{
    $id = validateInput($id, FILTER_VALIDATE_INT);
    if (!$id) {
        return "ID no válido.";
    }

    try {
        $stmt = $conn->prepare("DELETE FROM usuarios WHERE id = :id");
        $stmt->execute([":id" => $id]);
        return "Usuario eliminado exitosamente.";
    } catch (PDOException $e) {
        return "Error al eliminar usuario: " . $e->getMessage();
    }
}

// Procesamiento de formularios y acciones
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST["create"])) {
        $message = createUser($conn, $_POST, $roles);
    } elseif (isset($_POST["update"])) {
        $message = updateUser($conn, $_POST, $roles);
    }

    if (strpos($message, "exitosamente") !== false) {
        header("Location: " . $_SERVER["PHP_SELF"]);
        exit();
    } else {
        echo "<div class='error'>{$message}</div>";
    }
} elseif (isset($_GET["delete"])) {
    $message = deleteUser($conn, $_GET["delete"]);

    if (strpos($message, "exitosamente") !== false) {
        header("Location: " . $_SERVER["PHP_SELF"]);
        exit();
    } else {
        echo "<div class='error'>{$message}</div>";
    }
}

// Obtener todos los usuarios con su rol para la vista
try {
    $stmt = $conn->query("
        SELECT u.*, r.nombre AS rol_nombre
        FROM usuarios u
        LEFT JOIN roles r ON u.rol_id = r.id
        ORDER BY u.id DESC
    ");
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    handleError("Error al obtener usuarios: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administración de Usuarios - FinZen</title>
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

        /* Notification */
        .notification {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: var(--border-radius);
            background-color: rgba(8, 61, 119, 0.1);
            color: var(--primary);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .delete {
            background: none;
            border: none;
            cursor: pointer;
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
                <a href="#" class="menu-item active">
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
                <a href="logout.php" class="menu-item">
                    <i class="material-icons">logout</i>
                    <span>Cerrar Sesión</span>
                </a>
            </nav>
        </div>

        <!-- Main content -->
        <div class="main-content">
            <!-- Header -->
            <header class="header">
                <h1 class="page-title">Administración de Usuarios</h1>
                <button class="btn btn-primary modal-button" data-target="addUserModal">
                    <span class="icon"><i class="material-icons">add</i></span>
                    <span>Nuevo Usuario</span>
                </button>
            </header>

            <?php if ($message): ?>
                <div class="notification">
                    <span><?php echo htmlspecialchars($message); ?></span>
                    <button class="delete" onclick="this.parentNode.remove();"></button>
                </div>
            <?php endif; ?>

            <!-- Users Table -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="material-icons">people</i>
                        <span>Usuarios</span>
                    </h2>
                </div>
                <div class="card-content">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Usuario</th>
                                <th>Correo</th>
                                <th>Rol</th>
                                <th>Estado</th>
                                <th>Creado</th>
                                <th>Actualizado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usuarios as $usuario): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($usuario["id"]); ?></td>
                                    <td><?php echo htmlspecialchars($usuario["nombre_usuario"]); ?></td>
                                    <td><?php echo htmlspecialchars($usuario["correo_electronico"]); ?></td>
                                    <td><?php echo htmlspecialchars($usuario["rol_nombre"] ?? "Sin rol"); ?></td>
                                    <td>
                                        <span class="badge <?php echo $usuario["activo"] ? "badge-success" : "badge-error"; ?>">
                                            <?php echo $usuario["activo"] ? "Activo" : "Inactivo"; ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($usuario["creado_en"]); ?></td>
                                    <td><?php echo htmlspecialchars($usuario["actualizado_en"]); ?></td>
                                    <td>
                                        <button class="btn btn-outlined btn-action edit-button" data-target="editUserModal"
                                                data-id="<?php echo $usuario["id"]; ?>"
                                                data-nombre_usuario="<?php echo htmlspecialchars($usuario["nombre_usuario"]); ?>"
                                                data-correo_electronico="<?php echo htmlspecialchars($usuario["correo_electronico"]); ?>"
                                                data-rol_id="<?php echo $usuario["rol_id"]; ?>"
                                                data-activo="<?php echo $usuario["activo"]; ?>">
                                            <span class="icon"><i class="material-icons">edit</i></span>
                                        </button>
                                        <a href="?delete=<?php echo $usuario["id"]; ?>" class="btn btn-outlined btn-action"
                                           onclick="return confirm('¿Estás seguro de eliminar este usuario?')">
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

    <!-- Add User Modal -->
    <div class="modal" id="addUserModal">
        <div class="modal-background" onclick="closeModal('addUserModal')"></div>
        <div class="modal-card">
            <header class="modal-card-head">
                <p class="modal-card-title">Agregar Nuevo Usuario</p>
                <button class="delete" onclick="closeModal('addUserModal')"></button>
            </header>
            <form method="POST" action="">
                <section class="modal-card-body">
                    <div class="field">
                        <label class="label">Nombre de Usuario</label>
                        <div class="control">
                            <input class="input" type="text" name="nombre_usuario" required placeholder="usuario123">
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Correo Electrónico</label>
                        <div class="control">
                            <input class="input" type="email" name="correo_electronico" required placeholder="usuario@ejemplo.com">
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Contraseña</label>
                        <div class="control">
                            <input class="input" type="password" name="contraseña" required placeholder="Mínimo 8 caracteres">
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Rol</label>
                        <div class="control">
                            <div class="select">
                                <select name="rol_id" required>
                                    <option value="">Seleccionar Rol</option>
                                    <?php foreach ($roles as $rol): ?>
                                        <option value="<?php echo $rol["id"]; ?>">
                                            <?php echo htmlspecialchars($rol["nombre"]); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <div class="control">
                            <label class="checkbox">
                                <input type="checkbox" name="activo" checked>
                                Usuario Activo
                            </label>
                        </div>
                    </div>
                </section>
                <footer class="modal-card-foot">
                    <button class="btn btn-outlined" type="button" onclick="closeModal('addUserModal')">Cancelar</button>
                    <button class="btn btn-primary" type="submit" name="create">Guardar</button>
                </footer>
            </form>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal" id="editUserModal">
        <div class="modal-background" onclick="closeModal('editUserModal')"></div>
        <div class="modal-card">
            <header class="modal-card-head">
                <p class="modal-card-title">Editar Usuario</p>
                <button class="delete" onclick="closeModal('editUserModal')"></button>
            </header>
            <form method="POST" action="">
                <input type="hidden" name="id" id="edit_id">
                <section class="modal-card-body">
                    <div class="field">
                        <label class="label">Nombre de Usuario</label>
                        <div class="control">
                            <input class="input" type="text" name="nombre_usuario" id="edit_nombre_usuario" required>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Correo Electrónico</label>
                        <div class="control">
                            <input class="input" type="email" name="correo_electronico" id="edit_correo_electronico" required>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Nueva Contraseña (dejar vacío para no cambiar)</label>
                        <div class="control">
                            <input class="input" type="password" name="contraseña" id="edit_contraseña" placeholder="Nueva contraseña">
                        </div>
                    </div>
                    <div class="field">
                        <label class="label">Rol</label>
                        <div class="control">
                            <div class="select">
                                <select name="rol_id" id="edit_rol_id" required>
                                    <option value="">Seleccionar Rol</option>
                                    <?php foreach ($roles as $rol): ?>
                                        <option value="<?php echo $rol["id"]; ?>">
                                            <?php echo htmlspecialchars($rol["nombre"]); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <div class="control">
                            <label class="checkbox">
                                <input type="checkbox" name="activo" id="edit_activo">
                                Usuario Activo
                            </label>
                        </div>
                    </div>
                </section>
                <footer class="modal-card-foot">
                    <button class="btn btn-outlined" type="button" onclick="closeModal('editUserModal')">Cancelar</button>
                    <button class="btn btn-primary" type="submit" name="update">Actualizar</button>
                </footer>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Modal functions
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

            // Open modals
            (document.querySelectorAll('.modal-button') || []).forEach(($trigger) => {
                const modal = $trigger.dataset.target;
                const $target = document.getElementById(modal);

                $trigger.addEventListener('click', () => {
                    openModal($target);
                });
            });

            // Handle user edit
            const editButtons = document.querySelectorAll('.edit-button');
            editButtons.forEach(button => {
                button.addEventListener('click', () => {
                    document.getElementById('edit_id').value = button.dataset.id;
                    document.getElementById('edit_nombre_usuario').value = button.dataset.nombre_usuario;
                    document.getElementById('edit_correo_electronico').value = button.dataset.correo_electronico;
                    document.getElementById('edit_rol_id').value = button.dataset.rol_id;
                    document.getElementById('edit_activo').checked = button.dataset.activo === '1';

                    const modal = document.getElementById('editUserModal');
                    openModal(modal);
                });
            });

            // Close with ESC
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    closeAllModals();
                }
            });
        });
    </script>
</body>
</html>
