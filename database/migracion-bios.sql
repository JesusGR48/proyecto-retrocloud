-- =====================================================================
--  RetroCloud · Migracion 001 · Soporte de BIOS
-- =====================================================================
--  Algunas plataformas no arrancan solo con el juego: necesitan ademas
--  la firmware original del sistema (el "BIOS"). Es el caso de Neo Geo,
--  PlayStation, Sega CD y PSP, entre otras.
--
--  Esta migracion anade a `consolas` las columnas para almacenar ese
--  archivo, con los mismos datos de integridad que se guardan de una
--  ROM: nombre interno, SHA-256 y tamano.
--
--  Aplicar sobre una base ya creada:
--      mysql -u root -p retrocloud < migracion-bios.sql
-- =====================================================================

USE retrocloud;

ALTER TABLE consolas
  ADD COLUMN bios_requerido TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'La consola no arranca sin BIOS'
      AFTER extensiones,
  ADD COLUMN archivo_bios VARCHAR(255) DEFAULT NULL
      COMMENT 'nombre interno en storage/bios'
      AFTER bios_requerido,
  ADD COLUMN bios_hash CHAR(64) DEFAULT NULL
      COMMENT 'SHA-256 del archivo de BIOS'
      AFTER archivo_bios,
  ADD COLUMN bios_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0
      AFTER bios_hash,
  ADD COLUMN bios_nombre_original VARCHAR(255) DEFAULT NULL
      COMMENT 'nombre con el que debe verlo el emulador'
      AFTER bios_bytes;

-- ---------------------------------------------------------------------
-- Plataformas que exigen BIOS
-- ---------------------------------------------------------------------
UPDATE consolas SET bios_requerido = 1 WHERE slug IN ('psx');

-- ---------------------------------------------------------------------
-- Neo Geo como entrada propia
--
-- Usa el mismo nucleo que el arcade generico (FBNeo), pero se separa
-- en el catalogo porque es una plataforma reconocible por si misma y
-- porque su BIOS es distinto del de otras placas arcade.
-- ---------------------------------------------------------------------
INSERT INTO consolas
  (nombre, slug, core_emulador, fabricante, anio_lanzamiento, extensiones,
   bios_requerido, bits, color_hex, activa, orden)
VALUES
  ('Neo Geo', 'neogeo', 'arcade', 'SNK', 1990, 'zip', 1, '16-bit', '#d4302f', 1, 115)
ON DUPLICATE KEY UPDATE
  core_emulador  = VALUES(core_emulador),
  bios_requerido = VALUES(bios_requerido);

-- ---------------------------------------------------------------------
-- Vista del catalogo: se anade el indicador de BIOS para que el
-- reproductor sepa si debe cargarlo sin consultar otra tabla.
-- ---------------------------------------------------------------------
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
  c.bios_requerido AS bios_requerido,
  c.archivo_bios   AS archivo_bios,
  g.nombre        AS genero
FROM juegos j
JOIN consolas c ON c.id = j.consola_id
LEFT JOIN generos g ON g.id = j.genero_id;
