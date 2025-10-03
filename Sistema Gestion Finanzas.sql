-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 04-10-2025 a las 00:57:14
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `finzen`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `categorias`
--

CREATE TABLE `categorias` (
  `id` int(10) UNSIGNED NOT NULL,
  `usuario_id` int(10) UNSIGNED NOT NULL,
  `nombre` varchar(50) NOT NULL,
  `tipo` enum('ingreso','gasto') NOT NULL,
  `icono` varchar(50) DEFAULT 'category',
  `color` char(7) DEFAULT '#1976D2',
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci COMMENT='Categorías para clasificar transacciones';

--
-- Volcado de datos para la tabla `categorias`
--

INSERT INTO `categorias` (`id`, `usuario_id`, `nombre`, `tipo`, `icono`, `color`, `creado_en`, `actualizado_en`) VALUES
(2, 1, 'test', 'ingreso', 'bi-cash-coin', '#8AC24A', '2025-09-15 01:07:00', '2025-09-28 17:38:00'),
(3, 1, 'transporte', 'gasto', 'bi-car-front', '#FF6384', '2025-09-28 16:35:58', '2025-09-28 17:37:48'),
(4, 1, 'servicios', 'gasto', 'bi-phone', '#FF6384', '2025-09-29 18:03:46', '2025-09-30 02:44:53'),
(5, 3, 'Salario', 'ingreso', 'bi-cash-coin', '#8AC24A', '2025-09-30 04:11:57', '2025-09-30 04:11:57'),
(6, 1, 'test3', 'ingreso', 'bi-cash-coin', '#FF6384', '2025-10-01 23:02:40', '2025-10-01 23:02:40'),
(7, 1, 'Alquiler', 'gasto', 'bi-house', '#9966FF', '2025-10-01 23:17:59', '2025-10-01 23:17:59');

--
-- Disparadores `categorias`
--
DELIMITER $$
CREATE TRIGGER `trg_actualizar_timestamp_categorias` BEFORE UPDATE ON `categorias` FOR EACH ROW BEGIN
    SET NEW.actualizado_en = CURRENT_TIMESTAMP;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_prevenir_categorias_duplicadas` BEFORE INSERT ON `categorias` FOR EACH ROW BEGIN
    DECLARE categoria_existente INT;
    
    SELECT COUNT(*) INTO categoria_existente 
    FROM categorias 
    WHERE usuario_id = NEW.usuario_id 
    AND nombre = NEW.nombre 
    AND tipo = NEW.tipo;
    
    IF categoria_existente > 0 THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Ya existe una categoría con este nombre y tipo para el usuario';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_prevenir_eliminar_categoria_con_transacciones` BEFORE DELETE ON `categorias` FOR EACH ROW BEGIN
    DECLARE transacciones_count INT;
    
    SELECT COUNT(*) INTO transacciones_count 
    FROM transacciones 
    WHERE categoria_id = OLD.id;
    
    IF transacciones_count > 0 THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'No se puede eliminar una categoría con transacciones asociadas';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_validar_formato_color` BEFORE INSERT ON `categorias` FOR EACH ROW BEGIN
    IF NEW.color NOT REGEXP '^#[0-9A-Fa-f]{6}$' THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'El color debe estar en formato hexadecimal (#RRGGBB)';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `presupuestos`
--

CREATE TABLE `presupuestos` (
  `id` int(10) UNSIGNED NOT NULL,
  `usuario_id` int(10) UNSIGNED NOT NULL,
  `categoria_id` int(10) UNSIGNED NOT NULL,
  `monto` bigint(20) NOT NULL COMMENT 'Valor en centavos/unidad mínima',
  `periodo` enum('mensual','anual') NOT NULL,
  `fecha_inicio` date NOT NULL,
  `fecha_fin` date DEFAULT NULL,
  `notificacion` tinyint(1) DEFAULT 1,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `presupuestos`
--

INSERT INTO `presupuestos` (`id`, `usuario_id`, `categoria_id`, `monto`, `periodo`, `fecha_inicio`, `fecha_fin`, `notificacion`, `creado_en`, `actualizado_en`) VALUES
(6, 1, 3, 200000, 'mensual', '2025-09-30', NULL, 1, '2025-09-30 00:04:23', '2025-09-30 03:14:10'),
(7, 1, 4, 500000, 'mensual', '2025-09-30', NULL, 1, '2025-09-30 00:37:08', '2025-09-30 00:37:08');

--
-- Disparadores `presupuestos`
--
DELIMITER $$
CREATE TRIGGER `trg_actualizar_timestamp_presupuestos` BEFORE UPDATE ON `presupuestos` FOR EACH ROW BEGIN
    SET NEW.actualizado_en = CURRENT_TIMESTAMP;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_establecer_fecha_fin_presupuesto` BEFORE INSERT ON `presupuestos` FOR EACH ROW BEGIN
    IF NEW.periodo = 'mensual' AND NEW.fecha_fin IS NULL THEN
        SET NEW.fecha_fin = DATE_ADD(NEW.fecha_inicio, INTERVAL 1 MONTH);
    ELSEIF NEW.periodo = 'anual' AND NEW.fecha_fin IS NULL THEN
        SET NEW.fecha_fin = DATE_ADD(NEW.fecha_inicio, INTERVAL 1 YEAR);
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_validar_fechas_presupuesto` BEFORE INSERT ON `presupuestos` FOR EACH ROW BEGIN
    IF NEW.fecha_fin IS NOT NULL AND NEW.fecha_inicio > NEW.fecha_fin THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'La fecha de inicio no puede ser mayor a la fecha fin';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_validar_presupuesto_positivo` BEFORE INSERT ON `presupuestos` FOR EACH ROW BEGIN
    IF NEW.monto <= 0 THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'El presupuesto debe ser mayor a 0';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_validar_presupuesto_usuario_categoria` BEFORE INSERT ON `presupuestos` FOR EACH ROW BEGIN
    DECLARE categoria_tipo ENUM('ingreso','gasto');
    DECLARE categoria_usuario_id INT;
    
    -- Verificar que la categoría pertenece al usuario
    SELECT usuario_id, tipo INTO categoria_usuario_id, categoria_tipo
    FROM categorias 
    WHERE id = NEW.categoria_id;
    
    IF categoria_usuario_id != NEW.usuario_id THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'La categoría no pertenece al usuario';
    END IF;
    
    -- Solo permitir presupuestos para categorías de gasto
    IF categoria_tipo != 'gasto' THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Solo se pueden crear presupuestos para categorías de gasto';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `roles`
--

CREATE TABLE `roles` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `nombre` varchar(20) NOT NULL COMMENT 'admin|usuario',
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci COMMENT='Roles de usuarios en el sistema';

--
-- Volcado de datos para la tabla `roles`
--

INSERT INTO `roles` (`id`, `nombre`, `creado_en`, `actualizado_en`) VALUES
(1, 'admin', '2025-09-15 01:18:05', NULL),
(2, 'usuario', '2025-09-15 01:18:05', NULL);

--
-- Disparadores `roles`
--
DELIMITER $$
CREATE TRIGGER `trg_actualizar_timestamp_roles` BEFORE UPDATE ON `roles` FOR EACH ROW BEGIN
    SET NEW.actualizado_en = CURRENT_TIMESTAMP;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_prevenir_eliminar_rol_en_uso` BEFORE DELETE ON `roles` FOR EACH ROW BEGIN
    DECLARE usuarios_count INT;
    
    SELECT COUNT(*) INTO usuarios_count 
    FROM usuarios 
    WHERE rol_id = OLD.id;
    
    IF usuarios_count > 0 THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'No se puede eliminar un rol que está siendo usado por usuarios';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `transacciones`
--

CREATE TABLE `transacciones` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `usuario_id` int(10) UNSIGNED NOT NULL,
  `categoria_id` int(10) UNSIGNED NOT NULL,
  `monto` bigint(20) NOT NULL COMMENT 'Valor en centavos/unidad mínima',
  `descripcion` varchar(255) DEFAULT NULL,
  `fecha` date NOT NULL,
  `recurrente` tinyint(1) DEFAULT 0,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `transacciones`
--

INSERT INTO `transacciones` (`id`, `usuario_id`, `categoria_id`, `monto`, `descripcion`, `fecha`, `recurrente`, `creado_en`, `actualizado_en`) VALUES
(1, 0, 2, 1000, 'una prueba', '2025-09-15', 0, '2025-09-15 01:16:23', '2025-09-15 01:16:23'),
(2, 0, 3, 10000, '', '2025-09-28', 0, '2025-09-28 16:36:17', '2025-09-28 16:36:17'),
(3, 0, 2, 100000, '', '2025-09-28', 0, '2025-09-28 16:42:25', '2025-09-28 16:42:25'),
(4, 0, 3, 100000, '', '2025-09-28', 0, '2025-09-28 16:42:54', '2025-09-28 16:42:54'),
(5, 0, 3, 100000, 'Testing', '2025-09-30', 0, '2025-09-30 00:38:20', '2025-09-30 00:38:20'),
(6, 0, 5, 1000000, 'Ingresos', '2025-09-30', 0, '2025-09-30 04:12:43', '2025-09-30 04:12:43'),
(7, 0, 4, 100000, 'un gasto', '2025-10-02', 0, '2025-10-01 23:11:12', '2025-10-01 23:11:12'),
(8, 0, 7, 100000000, 'pago alquiler agosto', '2025-10-02', 0, '2025-10-01 23:20:07', '2025-10-01 23:20:07'),
(11, 0, 4, 300000000, 'serv', '2025-10-02', 0, '2025-10-01 23:24:56', '2025-10-01 23:24:56');

--
-- Disparadores `transacciones`
--
DELIMITER $$
CREATE TRIGGER `trg_actualizar_saldo_usuario_delete` AFTER DELETE ON `transacciones` FOR EACH ROW BEGIN
    DECLARE tipo_categoria ENUM('ingreso','gasto');
    
    SELECT `tipo` INTO tipo_categoria 
    FROM `categorias` 
    WHERE `id` = OLD.`categoria_id`;
    
    IF tipo_categoria = 'ingreso' THEN
        UPDATE `usuarios` 
        SET `saldo` = `saldo` - OLD.`monto`,
            `actualizado_en` = CURRENT_TIMESTAMP
        WHERE `id` = OLD.`usuario_id`;
    ELSE
        UPDATE `usuarios` 
        SET `saldo` = `saldo` + OLD.`monto`,
            `actualizado_en` = CURRENT_TIMESTAMP
        WHERE `id` = OLD.`usuario_id`;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_actualizar_saldo_usuario_insert` AFTER INSERT ON `transacciones` FOR EACH ROW BEGIN
    DECLARE tipo_categoria ENUM('ingreso','gasto');
    
    SELECT `tipo` INTO tipo_categoria 
    FROM `categorias` 
    WHERE `id` = NEW.`categoria_id`;
    
    IF tipo_categoria = 'ingreso' THEN
        UPDATE `usuarios` 
        SET `saldo` = `saldo` + NEW.`monto`,
            `actualizado_en` = CURRENT_TIMESTAMP
        WHERE `id` = NEW.`usuario_id`;
    ELSE
        UPDATE `usuarios` 
        SET `saldo` = `saldo` - NEW.`monto`,
            `actualizado_en` = CURRENT_TIMESTAMP
        WHERE `id` = NEW.`usuario_id`;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_actualizar_saldo_usuario_update` AFTER UPDATE ON `transacciones` FOR EACH ROW BEGIN
    DECLARE tipo_categoria_old ENUM('ingreso','gasto');
    DECLARE tipo_categoria_new ENUM('ingreso','gasto');
    
    SELECT `tipo` INTO tipo_categoria_old FROM `categorias` WHERE `id` = OLD.`categoria_id`;
    SELECT `tipo` INTO tipo_categoria_new FROM `categorias` WHERE `id` = NEW.`categoria_id`;
    
    -- Revertir transacción anterior
    IF tipo_categoria_old = 'ingreso' THEN
        UPDATE `usuarios` 
        SET `saldo` = `saldo` - OLD.`monto`
        WHERE `id` = OLD.`usuario_id`;
    ELSE
        UPDATE `usuarios` 
        SET `saldo` = `saldo` + OLD.`monto`
        WHERE `id` = OLD.`usuario_id`;
    END IF;
    
    -- Aplicar nueva transacción
    IF tipo_categoria_new = 'ingreso' THEN
        UPDATE `usuarios` 
        SET `saldo` = `saldo` + NEW.`monto`,
            `actualizado_en` = CURRENT_TIMESTAMP
        WHERE `id` = NEW.`usuario_id`;
    ELSE
        UPDATE `usuarios` 
        SET `saldo` = `saldo` - NEW.`monto`,
            `actualizado_en` = CURRENT_TIMESTAMP
        WHERE `id` = NEW.`usuario_id`;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_limitar_descripcion` BEFORE INSERT ON `transacciones` FOR EACH ROW BEGIN
    IF LENGTH(NEW.descripcion) > 255 THEN
        SET NEW.descripcion = CONCAT(SUBSTRING(NEW.descripcion, 1, 252), '...');
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_validar_actualizacion_transaccion` BEFORE UPDATE ON `transacciones` FOR EACH ROW BEGIN
    -- Prevenir cambios de usuario_id en transacciones existentes
    IF OLD.usuario_id != NEW.usuario_id THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'No se puede cambiar el usuario de una transacción existente';
    END IF;
    
    -- Validar que la fecha no sea futura
    IF NEW.fecha > CURDATE() THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'La fecha de la transacción no puede ser futura';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_validar_monto_positivo` BEFORE INSERT ON `transacciones` FOR EACH ROW BEGIN
    IF NEW.monto <= 0 THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'El monto debe ser mayor a 0';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int(10) UNSIGNED NOT NULL,
  `nombre_usuario` varchar(100) NOT NULL,
  `correo_electronico` varchar(150) NOT NULL,
  `hash_contraseña` varchar(255) NOT NULL,
  `rol_id` tinyint(3) UNSIGNED NOT NULL DEFAULT 2,
  `saldo` bigint(20) NOT NULL DEFAULT 0 COMMENT 'Valor en centavos/unidad mínima',
  `moneda` char(3) DEFAULT 'PYG',
  `activo` tinyint(1) DEFAULT 1,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `usuarios`
--

INSERT INTO `usuarios` (`id`, `nombre_usuario`, `correo_electronico`, `hash_contraseña`, `rol_id`, `saldo`, `moneda`, `activo`, `creado_en`, `actualizado_en`) VALUES
(1, 'luis', 'luis12ferreirafranco@gmail.com', '$2y$10$gB6b7G1T2pbwdFa6zQ6DIO03QV5bRLuOQPvY4HcP72pxEWMRo/0AK', 2, 0, 'PYG', 1, '2025-09-15 01:18:33', '2025-10-03 22:30:45'),
(2, 'testuser', 'testuser@gmail.com', '$2y$12$YZy9AAVO2zTD4RZcTFYnx.6FYAPCJWFWvfq68ifU4/H5R8SoIsTd2', 1, 0, 'PYG', 1, '2025-09-15 02:07:33', '2025-10-03 22:30:45'),
(3, 'demo', 'demoemail@gmail.com', '$2y$10$1n9MPXZnFKf.kNBZaC28repW//jfwnmiH.ouemmKAcFRbXMZv2aL2', 2, 0, 'PYG', 1, '2025-09-29 17:05:41', '2025-10-03 22:30:45');

--
-- Disparadores `usuarios`
--
DELIMITER $$
CREATE TRIGGER `trg_actualizar_timestamp_usuarios` BEFORE UPDATE ON `usuarios` FOR EACH ROW BEGIN
    SET NEW.actualizado_en = CURRENT_TIMESTAMP;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_prevenir_saldo_negativo` BEFORE UPDATE ON `usuarios` FOR EACH ROW BEGIN
    IF NEW.saldo < 0 THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'El saldo no puede ser negativo';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_validar_email` BEFORE INSERT ON `usuarios` FOR EACH ROW BEGIN
    IF NEW.correo_electronico NOT LIKE '%_@__%.__%' THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Formato de email inválido';
    END IF;
END
$$
DELIMITER ;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `categorias`
--
ALTER TABLE `categorias`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_usuario_nombre_tipo` (`usuario_id`,`nombre`,`tipo`),
  ADD KEY `idx_usuario_tipo_categoria` (`usuario_id`,`tipo`);

--
-- Indices de la tabla `presupuestos`
--
ALTER TABLE `presupuestos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_presupuesto_usuario_categoria` (`usuario_id`,`categoria_id`,`periodo`),
  ADD KEY `fk_presupuesto_categoria` (`categoria_id`);

--
-- Indices de la tabla `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indices de la tabla `transacciones`
--
ALTER TABLE `transacciones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_transacciones_fecha` (`fecha`),
  ADD KEY `idx_transacciones_categoria` (`categoria_id`),
  ADD KEY `idx_transacciones_cuenta_fecha` (`fecha`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre_usuario` (`nombre_usuario`),
  ADD UNIQUE KEY `correo_electronico` (`correo_electronico`),
  ADD KEY `idx_usuario_rol` (`rol_id`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `categorias`
--
ALTER TABLE `categorias`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT de la tabla `presupuestos`
--
ALTER TABLE `presupuestos`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT de la tabla `roles`
--
ALTER TABLE `roles`
  MODIFY `id` tinyint(3) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `transacciones`
--
ALTER TABLE `transacciones`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `presupuestos`
--
ALTER TABLE `presupuestos`
  ADD CONSTRAINT `fk_presupuesto_categoria` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `transacciones`
--
ALTER TABLE `transacciones`
  ADD CONSTRAINT `fk_transaccion_categoria` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD CONSTRAINT `fk_usuario_rol` FOREIGN KEY (`rol_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
