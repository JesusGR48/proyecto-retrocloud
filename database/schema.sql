-- =====================================================================
--  RetroCloud · Esquema de base de datos
--  Proyecto final · Desarrollo y Programacion Web
--  Motor: MySQL 5.7+ / MariaDB 10.2+   ·   InnoDB   ·   utf8mb4
-- =====================================================================
--  Importar desde phpMyAdmin (pestana Importar) o por consola:
--    mysql -u root -p < schema.sql
-- =====================================================================

DROP DATABASE IF EXISTS retrocloud;
CREATE DATABASE retrocloud
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE retrocloud;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- 1. usuarios
--    Autenticacion y control de acceso basado en roles (RBAC).
--    La contrasena NUNCA se almacena en claro: solo el hash bcrypt
--    generado por password_hash() de PHP (60 caracteres, con salt).
-- ---------------------------------------------------------------------
CREATE TABLE usuarios (
  id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  nombre_usuario   VARCHAR(32)   NOT NULL,
  correo           VARCHAR(190)  NOT NULL,
  hash_password    VARCHAR(255)  NOT NULL,
  rol              ENUM('jugador','admin') NOT NULL DEFAULT 'jugador',
  avatar           VARCHAR(255)  DEFAULT NULL,
  activo           TINYINT(1)    NOT NULL DEFAULT 1,
  creado_en        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_acceso    DATETIME      DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuario_nombre (nombre_usuario),
  UNIQUE KEY uq_usuario_correo (correo),
  KEY idx_usuario_rol (rol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. consolas
--    Catalogo de plataformas emulables. 'core_emulador' es el nombre
--    del nucleo libretro compilado a WebAssembly que EmulatorJS carga
--    en el navegador (se inyecta como EJS_core en el reproductor).
-- ---------------------------------------------------------------------
CREATE TABLE consolas (
  id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  nombre           VARCHAR(60)   NOT NULL,
  slug             VARCHAR(40)   NOT NULL,
  core_emulador    VARCHAR(40)   NOT NULL,
  fabricante       VARCHAR(60)   DEFAULT NULL,
  anio_lanzamiento SMALLINT UNSIGNED DEFAULT NULL,
  extensiones      VARCHAR(140)  NOT NULL COMMENT 'extensiones ROM validas, separadas por coma',
  -- Firmware del sistema. Plataformas como Neo Geo o PlayStation no
  -- ejecutan el juego por si solas: lo corren sobre el BIOS de la
  -- maquina. Se guarda uno por consola, con los mismos datos de
  -- integridad que una ROM.
  bios_requerido   TINYINT(1)    NOT NULL DEFAULT 0 COMMENT 'no arranca sin BIOS',
  archivo_bios     VARCHAR(255)  DEFAULT NULL COMMENT 'nombre interno en storage/bios',
  bios_hash        CHAR(64)      DEFAULT NULL COMMENT 'SHA-256 del BIOS',
  bios_bytes       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  bios_nombre_original VARCHAR(255) DEFAULT NULL
                   COMMENT 'nombre con el que el emulador debe verlo',
  bits             VARCHAR(10)   DEFAULT NULL,
  color_hex        CHAR(7)       NOT NULL DEFAULT '#6c5ce7',
  activa           TINYINT(1)    NOT NULL DEFAULT 1,
  orden            SMALLINT      NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_consola_slug (slug),
  KEY idx_consola_activa (activa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. generos
-- ---------------------------------------------------------------------
CREATE TABLE generos (
  id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(60)  NOT NULL,
  slug   VARCHAR(60)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_genero_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. juegos
--    El archivo ROM se guarda FUERA del document root (storage/roms) y
--    se sirve mediante un script PHP que valida la sesion. En la BD se
--    guarda solo el nombre interno, el SHA-256 y el tamano en bytes,
--    lo que permite verificar integridad y rechazar duplicados.
-- ---------------------------------------------------------------------
CREATE TABLE juegos (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  consola_id     INT UNSIGNED  NOT NULL,
  genero_id      INT UNSIGNED  DEFAULT NULL,
  titulo         VARCHAR(150)  NOT NULL,
  slug           VARCHAR(170)  NOT NULL,
  descripcion    TEXT          DEFAULT NULL,
  desarrollador  VARCHAR(120)  DEFAULT NULL,
  anio           SMALLINT UNSIGNED DEFAULT NULL,
  archivo_rom    VARCHAR(255)  NOT NULL,
  rom_hash       CHAR(64)      NOT NULL COMMENT 'SHA-256 del archivo ROM',
  rom_bytes      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  portada        VARCHAR(255)  DEFAULT NULL,
  jugadores      TINYINT UNSIGNED NOT NULL DEFAULT 1,
  licencia       VARCHAR(140)  NOT NULL DEFAULT 'No especificada',
  publicado      TINYINT(1)    NOT NULL DEFAULT 1,
  veces_jugado   INT UNSIGNED  NOT NULL DEFAULT 0,
  creado_por     INT UNSIGNED  DEFAULT NULL,
  creado_en      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_juego_slug (slug),
  UNIQUE KEY uq_juego_hash (rom_hash),
  KEY idx_juego_consola (consola_id),
  KEY idx_juego_genero (genero_id),
  KEY idx_juego_publicado (publicado),
  KEY idx_juego_titulo (titulo),
  CONSTRAINT fk_juego_consola FOREIGN KEY (consola_id)
    REFERENCES consolas (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_juego_genero FOREIGN KEY (genero_id)
    REFERENCES generos (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_juego_autor FOREIGN KEY (creado_por)
    REFERENCES usuarios (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. save_states
--    Nucleo funcional del sistema. Un "save state" es el volcado
--    binario completo de la memoria del emulador en un instante dado.
--    slot 0 = autoguardado; slots 1..4 = ranuras manuales.
--    La restriccion UNIQUE (usuario, juego, slot) garantiza que cada
--    ranura contenga a lo sumo una partida y habilita el UPSERT.
-- ---------------------------------------------------------------------
CREATE TABLE save_states (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  usuario_id     INT UNSIGNED  NOT NULL,
  juego_id       INT UNSIGNED  NOT NULL,
  slot           TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '0 = autosave, 1-4 = manuales',
  nombre         VARCHAR(80)   DEFAULT NULL,
  archivo        VARCHAR(255)  NOT NULL,
  bytes          INT UNSIGNED  NOT NULL DEFAULT 0,
  hash           CHAR(64)      NOT NULL,
  captura        VARCHAR(255)  DEFAULT NULL COMMENT 'miniatura PNG del momento guardado',
  segundos_juego INT UNSIGNED  NOT NULL DEFAULT 0,
  creado_en      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_save_slot (usuario_id, juego_id, slot),
  KEY idx_save_juego (juego_id),
  CONSTRAINT fk_save_usuario FOREIGN KEY (usuario_id)
    REFERENCES usuarios (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_save_juego FOREIGN KEY (juego_id)
    REFERENCES juegos (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. sesiones_juego   (historial / telemetria de uso)
-- ---------------------------------------------------------------------
CREATE TABLE sesiones_juego (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id       INT UNSIGNED NOT NULL,
  juego_id         INT UNSIGNED NOT NULL,
  iniciada_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finalizada_en    DATETIME     DEFAULT NULL,
  segundos_jugados INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_sesion_usuario (usuario_id),
  KEY idx_sesion_juego (juego_id),
  KEY idx_sesion_fecha (iniciada_en),
  CONSTRAINT fk_sesion_usuario FOREIGN KEY (usuario_id)
    REFERENCES usuarios (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_sesion_juego FOREIGN KEY (juego_id)
    REFERENCES juegos (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. favoritos   (relacion N:M usuario <-> juego)
-- ---------------------------------------------------------------------
CREATE TABLE favoritos (
  usuario_id INT UNSIGNED NOT NULL,
  juego_id   INT UNSIGNED NOT NULL,
  creado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (usuario_id, juego_id),
  KEY idx_fav_juego (juego_id),
  CONSTRAINT fk_fav_usuario FOREIGN KEY (usuario_id)
    REFERENCES usuarios (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_fav_juego FOREIGN KEY (juego_id)
    REFERENCES juegos (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. intentos_login   (mitigacion de ataques de fuerza bruta)
--    Se registra cada intento; el backend bloquea temporalmente la IP
--    o la cuenta tras N fallos dentro de una ventana de tiempo.
-- ---------------------------------------------------------------------
CREATE TABLE intentos_login (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  identificador VARCHAR(190)    NOT NULL COMMENT 'correo o usuario tecleado',
  ip            VARCHAR(45)     NOT NULL,
  exito         TINYINT(1)      NOT NULL DEFAULT 0,
  user_agent    VARCHAR(255)    DEFAULT NULL,
  creado_en     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_intento_ip (ip, creado_en),
  KEY idx_intento_ident (identificador, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. logros  /  usuario_logros   (gamificacion)
-- ---------------------------------------------------------------------
CREATE TABLE logros (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo      VARCHAR(40)  NOT NULL,
  nombre      VARCHAR(80)  NOT NULL,
  descripcion VARCHAR(190) NOT NULL,
  icono       VARCHAR(16)  NOT NULL DEFAULT '*',
  tipo        ENUM('partidas','tiempo','juegos','saves') NOT NULL,
  umbral      INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_logro_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE usuario_logros (
  usuario_id  INT UNSIGNED NOT NULL,
  logro_id    INT UNSIGNED NOT NULL,
  obtenido_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (usuario_id, logro_id),
  CONSTRAINT fk_ul_usuario FOREIGN KEY (usuario_id)
    REFERENCES usuarios (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ul_logro FOREIGN KEY (logro_id)
    REFERENCES logros (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. VISTAS  (consultas de solo lectura reutilizables)
-- ---------------------------------------------------------------------

-- Catalogo enriquecido: evita repetir el JOIN en cada consulta.
CREATE OR REPLACE VIEW vista_catalogo AS
SELECT
  j.id, j.titulo, j.slug, j.descripcion, j.desarrollador, j.anio,
  j.portada, j.jugadores, j.licencia, j.publicado, j.veces_jugado,
  j.rom_bytes, j.creado_en,
  c.id            AS consola_id,
  c.nombre        AS consola,
  c.slug          AS consola_slug,
  c.core_emulador AS core_emulador,
  c.color_hex     AS color_hex,
  g.nombre        AS genero
FROM juegos j
JOIN consolas c ON c.id = j.consola_id
LEFT JOIN generos g ON g.id = j.genero_id;

-- Ranking de juegos por tiempo real acumulado.
CREATE OR REPLACE VIEW vista_ranking AS
SELECT
  j.id,
  j.titulo,
  c.nombre AS consola,
  COUNT(DISTINCT s.usuario_id)         AS jugadores_unicos,
  COUNT(s.id)                          AS total_partidas,
  COALESCE(SUM(s.segundos_jugados), 0) AS segundos_totales
FROM juegos j
JOIN consolas c ON c.id = j.consola_id
LEFT JOIN sesiones_juego s ON s.juego_id = j.id
GROUP BY j.id, j.titulo, c.nombre;

-- Resumen estadistico por usuario (perfil).
CREATE OR REPLACE VIEW vista_perfil AS
SELECT
  u.id,
  u.nombre_usuario,
  u.correo,
  u.rol,
  u.creado_en,
  (SELECT COUNT(*) FROM sesiones_juego s WHERE s.usuario_id = u.id)              AS partidas,
  (SELECT COALESCE(SUM(s.segundos_jugados),0) FROM sesiones_juego s WHERE s.usuario_id = u.id) AS segundos_jugados,
  (SELECT COUNT(DISTINCT s.juego_id) FROM sesiones_juego s WHERE s.usuario_id = u.id) AS juegos_distintos,
  (SELECT COUNT(*) FROM save_states v WHERE v.usuario_id = u.id)                 AS partidas_guardadas,
  (SELECT COUNT(*) FROM favoritos f WHERE f.usuario_id = u.id)                   AS favoritos
FROM usuarios u;

-- ---------------------------------------------------------------------
-- 11. PROCEDIMIENTO ALMACENADO
--     Cierra una sesion de juego y acumula el contador del juego en un
--     solo viaje a la base de datos (menos round-trips desde PHP).
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_cerrar_sesion;
DELIMITER $$
CREATE PROCEDURE sp_cerrar_sesion (
  IN p_sesion_id  INT UNSIGNED,
  IN p_usuario_id INT UNSIGNED,
  IN p_segundos   INT UNSIGNED
)
BEGIN
  UPDATE sesiones_juego
     SET finalizada_en    = NOW(),
         segundos_jugados = p_segundos
   WHERE id = p_sesion_id
     AND usuario_id = p_usuario_id;

  UPDATE juegos j
     JOIN sesiones_juego s ON s.juego_id = j.id
     SET j.veces_jugado = j.veces_jugado + 1
   WHERE s.id = p_sesion_id;
END$$
DELIMITER ;

-- ---------------------------------------------------------------------
-- 12. TRIGGER
--     Rechaza un save_state dirigido a una ranura inexistente.
-- ---------------------------------------------------------------------
DROP TRIGGER IF EXISTS tr_save_slot_valido;
DELIMITER $$
CREATE TRIGGER tr_save_slot_valido
BEFORE INSERT ON save_states
FOR EACH ROW
BEGIN
  IF NEW.slot > 4 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Slot invalido: debe estar entre 0 y 4';
  END IF;
END$$
DELIMITER ;
