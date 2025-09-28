<?php
// pagina raiz del sistema
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

                <form id="loginForm" method="POST" action="/auth/login.php" novalidate>
                    <div class="mb-3">
                        <label for="username" class="form-label">Usuario</label>
                        <input type="text" class="form-control rounded-pill" name="username" id="username" 
                               placeholder="Ingresa tu usuario" required>
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
                        <input type="checkbox" class="form-check-input" id="remember">
                        <label class="form-check-label" for="remember">Recordarme</label>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 rounded-pill">Ingresar</button>
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
        // Validación del formulario con Bootstrap
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('loginForm');
            
            form.addEventListener('submit', function(event) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                
                form.classList.add('was-validated');
            });
        });
    </script>
</body>
</html>