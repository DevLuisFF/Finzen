<?php
// pagina raiz del sistema
session_start();

// Verificar si hay mensajes de error o éxito
$login_error = $_SESSION['login_error'] ?? '';
$login_success = $_SESSION['login_success'] ?? false;

// Limpiar mensajes después de mostrarlos
unset($_SESSION['login_error']);
unset($_SESSION['login_success']);

// Si ya está logueado, redirigir
if (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0) {
    switch ($_SESSION['rol_id'] ?? 2) {
        case 1: header('Location: admin/index.php'); break;
        case 2: header('Location: user/index.php'); break;
        default: header('Location: user/index.php'); break;
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión | Finzen</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f8f9fa;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            width: 100%;
            max-width: 400px;
        }
        
        .card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        
        .card-body {
            padding: 2rem;
        }
        
        .login-icon {
            font-size: 3rem;
            color: #0d6efd;
        }
        
        .form-control, .form-check-input {
            border-radius: 50px;
        }
        
        .btn {
            border-radius: 50px;
            padding: 0.5rem 1.5rem;
        }
        
        .form-check-input:checked {
            background-color: #0d6efd;
            border-color: #0d6efd;
        }
        
        .login-footer a {
            text-decoration: none;
            font-size: 0.9rem;
        }
        
        .alert {
            border-radius: 15px;
            border: none;
        }
        
        .shake-animation {
            animation: shake 0.5s ease-in-out;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        
        .success-animation {
            animation: success 0.6s ease-in-out;
        }
        
        @keyframes success {
            0% { transform: scale(0.95); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="card">
            <div class="card-body">
                <div class="text-center mb-4">
                    <i class="bi bi-person-circle login-icon"></i>
                    <h2 class="mt-3 mb-1">Iniciar Sesión</h2>
                    <p class="text-muted">Ingresa tus credenciales para continuar</p>
                </div>

                <!-- Mostrar mensajes de error -->
                <?php if (!empty($login_error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show shake-animation" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?php echo htmlspecialchars($login_error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Mostrar mensaje de éxito (si se redirige desde logout, por ejemplo) -->
                <?php if ($login_success): ?>
                    <div class="alert alert-success alert-dismissible fade show success-animation" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        ¡Inicio de sesión exitoso!
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form id="loginForm" method="POST" action="/auth/login.php" novalidate>
                    <div class="mb-3">
                        <label for="username" class="form-label">Usuario</label>
                        <input type="text" class="form-control rounded-pill" name="username" id="username" 
                               placeholder="Ingresa tu usuario" required 
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                        <div class="invalid-feedback">
                            Por favor ingresa tu usuario
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Contraseña</label>
                        <input type="password" class="form-control rounded-pill" name="password" id="password" 
                               placeholder="Ingresa tu contraseña" required>
                        <div class="invalid-feedback">
                            Por favor ingresa tu contraseña
                        </div>
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="remember" name="remember">
                        <label class="form-check-label" for="remember">Recordarme</label>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 rounded-pill">
                        <i class="bi bi-box-arrow-in-right me-2"></i>
                        Ingresar
                    </button>
                </form>

                <div class="login-footer mt-4 text-center">
                    <a href="register.php" class="me-3">Crear cuenta</a>
                    <a href="forgot.php">¿Olvidaste tu contraseña?</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('loginForm');
            const usernameInput = document.getElementById('username');
            const passwordInput = document.getElementById('password');
            
            // Validación del formulario con Bootstrap
            form.addEventListener('submit', function(event) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                    
                    // Agregar animación shake a campos inválidos
                    if (!usernameInput.checkValidity()) {
                        usernameInput.classList.add('shake-animation');
                        setTimeout(() => usernameInput.classList.remove('shake-animation'), 500);
                    }
                    
                    if (!passwordInput.checkValidity()) {
                        passwordInput.classList.add('shake-animation');
                        setTimeout(() => passwordInput.classList.remove('shake-animation'), 500);
                    }
                }
                
                form.classList.add('was-validated');
            });

            // Limpiar validación al escribir
            usernameInput.addEventListener('input', function() {
                if (this.checkValidity()) {
                    this.classList.remove('is-invalid');
                    this.classList.add('is-valid');
                } else {
                    this.classList.remove('is-valid');
                }
            });

            passwordInput.addEventListener('input', function() {
                if (this.checkValidity()) {
                    this.classList.remove('is-invalid');
                    this.classList.add('is-valid');
                } else {
                    this.classList.remove('is-valid');
                }
            });

            // Auto-focus en el campo de usuario
            usernameInput.focus();
        });
    </script>
</body>
</html>