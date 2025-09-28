-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 28, 2025 at 04:09 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `finzen`
--

-- --------------------------------------------------------

--
-- Table structure for table `categorias`
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
-- Dumping data for table `categorias`
--

INSERT INTO `categorias` (`id`, `usuario_id`, `nombre`, `tipo`, `icono`, `color`, `creado_en`, `actualizado_en`) VALUES
(2, 1, 'test', 'ingreso', 'fa-money-bill-wave', '#8AC24A', '2025-09-15 01:07:00', '2025-09-15 01:07:00');

--
-- Triggers `categorias`
--
DELIMITER $$
CREATE TRIGGER `trg_actualizar_timestamp_categorias` BEFORE UPDATE ON `categorias` FOR EACH ROW BEGIN
    SET NEW.actualizado_en = CURRENT_TIMESTAMP;
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

-- --------------------------------------------------------

--
-- Table structure for table `cuentas`
--

CREATE TABLE `cuentas` (
  `id` int(10) UNSIGNED NOT NULL,
  `usuario_id` int(10) UNSIGNED NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `saldo` bigint(20) NOT NULL DEFAULT 0 COMMENT 'Valor en centavos/unidad mínima',
  `moneda` char(3) DEFAULT 'USD',
  `activa` tinyint(1) DEFAULT 1,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci COMMENT='Cuentas financieras de usuarios';

--
-- Dumping data for table `cuentas`
--

INSERT INTO `cuentas` (`id`, `usuario_id`, `nombre`, `saldo`, `moneda`, `activa`, `creado_en`, `actualizado_en`) VALUES
(1, 1, 'test', 12000, 'PYG', 1, '2025-09-15 00:53:33', '2025-09-15 01:16:23');

--
-- Triggers `cuentas`
--
DELIMITER $$
CREATE TRIGGER `trg_actualizar_timestamp_cuentas` BEFORE UPDATE ON `cuentas` FOR EACH ROW BEGIN
    SET NEW.actualizado_en = CURRENT_TIMESTAMP;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_desactivar_cuenta_saldo_negativo` AFTER UPDATE ON `cuentas` FOR EACH ROW BEGIN
    IF NEW.saldo < 0 AND NEW.activa = TRUE THEN
        UPDATE cuentas 
        SET activa = FALSE,
            actualizado_en = CURRENT_TIMESTAMP
        WHERE id = NEW.id;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_nombre_cuenta_default` BEFORE INSERT ON `cuentas` FOR EACH ROW BEGIN
    IF NEW.nombre IS NULL OR NEW.nombre = '' THEN
        SET NEW.nombre = CONCAT('Cuenta ', DATE_FORMAT(NOW(), '%Y%m%d'));
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_prevenir_eliminar_cuenta_con_transacciones` BEFORE DELETE ON `cuentas` FOR EACH ROW BEGIN
    DECLARE transacciones_count INT;
    
    SELECT COUNT(*) INTO transacciones_count 
    FROM transacciones 
    WHERE cuenta_id = OLD.id;
    
    IF transacciones_count > 0 THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'No se puede eliminar una cuenta con transacciones asociadas';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_reactivar_cuenta_saldo_positivo` BEFORE UPDATE ON `cuentas` FOR EACH ROW BEGIN
    IF NEW.saldo >= 0 AND OLD.saldo < 0 AND NEW.activa = FALSE THEN
        SET NEW.activa = TRUE;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `presupuestos`
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
-- Triggers `presupuestos`
--
DELIMITER $$
CREATE TRIGGER `trg_actualizar_timestamp_presupuestos` BEFORE UPDATE ON `presupuestos` FOR EACH ROW BEGIN
    SET NEW.actualizado_en = CURRENT_TIMESTAMP;
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

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `nombre` varchar(20) NOT NULL COMMENT 'admin|usuario',
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci COMMENT='Roles de usuarios en el sistema';

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `nombre`, `creado_en`, `actualizado_en`) VALUES
(1, 'admin', '2025-09-15 01:18:05', NULL),
(2, 'usuario', '2025-09-15 01:18:05', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `transacciones`
--

CREATE TABLE `transacciones` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `cuenta_id` int(10) UNSIGNED NOT NULL,
  `categoria_id` int(10) UNSIGNED NOT NULL,
  `monto` bigint(20) NOT NULL COMMENT 'Valor en centavos/unidad mínima',
  `descripcion` varchar(255) DEFAULT NULL,
  `fecha` date NOT NULL,
  `recurrente` tinyint(1) DEFAULT 0,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Dumping data for table `transacciones`
--

INSERT INTO `transacciones` (`id`, `cuenta_id`, `categoria_id`, `monto`, `descripcion`, `fecha`, `recurrente`, `creado_en`, `actualizado_en`) VALUES
(1, 1, 2, 1000, 'una prueba', '2025-09-15', 0, '2025-09-15 01:16:23', '2025-09-15 01:16:23');

--
-- Triggers `transacciones`
--
DELIMITER $$
CREATE TRIGGER `trg_actualizar_saldo_insert` AFTER INSERT ON `transacciones` FOR EACH ROW BEGIN
    DECLARE tipo_categoria ENUM('ingreso','gasto');
    
    -- Obtener el tipo de categoría
    SELECT tipo INTO tipo_categoria 
    FROM categorias 
    WHERE id = NEW.categoria_id;
    
    -- Actualizar saldo según el tipo de transacción
    IF tipo_categoria = 'ingreso' THEN
        UPDATE cuentas 
        SET saldo = saldo + NEW.monto,
            actualizado_en = CURRENT_TIMESTAMP
        WHERE id = NEW.cuenta_id;
    ELSE
        UPDATE cuentas 
        SET saldo = saldo - NEW.monto,
            actualizado_en = CURRENT_TIMESTAMP
        WHERE id = NEW.cuenta_id;
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
CREATE TRIGGER `trg_transaccion_delete` AFTER DELETE ON `transacciones` FOR EACH ROW UPDATE cuentas
SET saldo = saldo - OLD.monto
WHERE id = OLD.cuenta_id
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
-- Table structure for table `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int(10) UNSIGNED NOT NULL,
  `nombre_usuario` varchar(100) NOT NULL,
  `correo_electronico` varchar(150) NOT NULL,
  `hash_contraseña` varchar(255) NOT NULL,
  `rol_id` tinyint(3) UNSIGNED NOT NULL DEFAULT 2,
  `activo` tinyint(1) DEFAULT 1,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Dumping data for table `usuarios`
--

INSERT INTO `usuarios` (`id`, `nombre_usuario`, `correo_electronico`, `hash_contraseña`, `rol_id`, `activo`, `creado_en`, `actualizado_en`) VALUES
(1, 'luis', 'luis12ferreirafranco@gmail.com', '$2y$10$gB6b7G1T2pbwdFa6zQ6DIO03QV5bRLuOQPvY4HcP72pxEWMRo/0AK', 2, 1, '2025-09-15 01:18:33', '2025-09-15 02:09:11'),
(2, 'testuser', 'testuser@gmail.com', '$2y$12$YZy9AAVO2zTD4RZcTFYnx.6FYAPCJWFWvfq68ifU4/H5R8SoIsTd2', 2, 1, '2025-09-15 02:07:33', NULL);

--
-- Triggers `usuarios`
--
DELIMITER $$
CREATE TRIGGER `trg_actualizar_timestamp_usuarios` BEFORE UPDATE ON `usuarios` FOR EACH ROW BEGIN
    SET NEW.actualizado_en = CURRENT_TIMESTAMP;
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
-- Indexes for dumped tables
--

--
-- Indexes for table `categorias`
--
ALTER TABLE `categorias`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_usuario_nombre_tipo` (`usuario_id`,`nombre`,`tipo`),
  ADD KEY `idx_usuario_tipo_categoria` (`usuario_id`,`tipo`);

--
-- Indexes for table `cuentas`
--
ALTER TABLE `cuentas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_usuario_nombre_cuenta` (`usuario_id`,`nombre`),
  ADD KEY `idx_cuenta_usuario` (`usuario_id`);

--
-- Indexes for table `presupuestos`
--
ALTER TABLE `presupuestos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_presupuesto_usuario_categoria` (`usuario_id`,`categoria_id`,`periodo`),
  ADD KEY `fk_presupuesto_categoria` (`categoria_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indexes for table `transacciones`
--
ALTER TABLE `transacciones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_transacciones_fecha` (`cuenta_id`,`fecha`),
  ADD KEY `idx_transacciones_categoria` (`categoria_id`),
  ADD KEY `idx_transacciones_cuenta_fecha` (`cuenta_id`,`fecha`);

--
-- Indexes for table `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre_usuario` (`nombre_usuario`),
  ADD UNIQUE KEY `correo_electronico` (`correo_electronico`),
  ADD KEY `idx_usuario_rol` (`rol_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `categorias`
--
ALTER TABLE `categorias`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `cuentas`
--
ALTER TABLE `cuentas`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `presupuestos`
--
ALTER TABLE `presupuestos`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` tinyint(3) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `transacciones`
--
ALTER TABLE `transacciones`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `categorias`
--
ALTER TABLE `categorias`
  ADD CONSTRAINT `fk_categoria_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `cuentas`
--
ALTER TABLE `cuentas`
  ADD CONSTRAINT `fk_cuenta_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `presupuestos`
--
ALTER TABLE `presupuestos`
  ADD CONSTRAINT `fk_presupuesto_categoria` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_presupuesto_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `transacciones`
--
ALTER TABLE `transacciones`
  ADD CONSTRAINT `fk_transaccion_categoria` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_transaccion_cuenta` FOREIGN KEY (`cuenta_id`) REFERENCES `cuentas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `usuarios`
--
ALTER TABLE `usuarios`
  ADD CONSTRAINT `fk_usuario_rol` FOREIGN KEY (`rol_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
