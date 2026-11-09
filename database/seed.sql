-- =====================================================================
--  RetroCloud · Datos iniciales (seed)
--  Ejecutar DESPUES de schema.sql
-- =====================================================================
--  CUENTAS DE PRUEBA CREADAS POR ESTE ARCHIVO
--  -------------------------------------------------------------------
--    Administrador ->  usuario: admin       contrasena: RetroCloud#2026
--    Jugador       ->  usuario: jugador1    contrasena: Jugador#2026
--
--  >>> CAMBIA ESTAS CONTRASENAS ANTES DE PUBLICAR EL SITIO. <<<
--  Los valores almacenados son hashes bcrypt (cost 12) generados con
--  password_hash() de PHP; el texto en claro no existe en la BD.
-- =====================================================================

USE retrocloud;

-- ---------------------------------------------------------------------
-- Usuarios
-- ---------------------------------------------------------------------
INSERT INTO usuarios (nombre_usuario, correo, hash_password, rol) VALUES
  ('admin',    'admin@retrocloud.local',
   '$2y$12$YhdHCPrpUpZ21Sjx55SXlOwwGGNYXUQuCfSiPfoKSzEhrwsMnn9yu', 'admin'),
  ('jugador1', 'jugador1@retrocloud.local',
   '$2y$12$sx9cQT2i/eQgb6/FTNXLcuhXgap2SakTfzBY35ZaGMpuPcZ3hfj9S', 'jugador');

-- ---------------------------------------------------------------------
-- Consolas
--   core_emulador = identificador del nucleo libretro (WebAssembly)
--   que el reproductor inyecta como EJS_core.
-- ---------------------------------------------------------------------
INSERT INTO consolas
  (nombre, slug, core_emulador, fabricante, anio_lanzamiento, extensiones, bits, color_hex, orden) VALUES
  ('Nintendo Entertainment System', 'nes',   'nes',        'Nintendo', 1983, 'nes,fds,unf,unif,zip',          '8-bit',  '#e23b3b', 10),
  ('Super Nintendo',                'snes',  'snes',       'Nintendo', 1990, 'sfc,smc,swc,fig,zip',           '16-bit', '#7d5fff', 20),
  ('Game Boy / Color',              'gb',    'gb',         'Nintendo', 1989, 'gb,gbc,zip',                    '8-bit',  '#8bc34a', 30),
  ('Game Boy Advance',              'gba',   'gba',        'Nintendo', 2001, 'gba,zip',                       '32-bit', '#5a4fcf', 40),
  ('Nintendo 64',                   'n64',   'n64',        'Nintendo', 1996, 'n64,z64,v64,ndd,zip',           '64-bit', '#1ca7a7', 50),
  ('Nintendo DS',                   'nds',   'nds',        'Nintendo', 2004, 'nds,zip',                       '32-bit', '#c0392b', 60),
  ('PlayStation',                   'psx',   'psx',        'Sony',     1994, 'bin,cue,iso,img,pbp,chd,zip',   '32-bit', '#546e7a', 70),
  ('Sega Genesis / Mega Drive',     'segamd','segaMD',     'Sega',     1988, 'md,gen,smd,bin,zip',            '16-bit', '#1565c0', 80),
  ('Sega Master System',            'segams','segaMS',     'Sega',     1985, 'sms,zip',                       '8-bit',  '#00897b', 90),
  ('Virtual Boy',                   'vb',    'vb',         'Nintendo', 1995, 'vb,vboy,zip',                   '32-bit', '#b71c1c', 100),
  ('Atari 2600',                    'a2600', 'atari2600',  'Atari',    1977, 'a26,bin,zip',                   '8-bit',  '#8d6e63', 110),
  ('Arcade (FBNeo)',                'arcade','arcade',     'Varios',   1979, 'zip',                           '-',      '#f39c12', 120);

-- ---------------------------------------------------------------------
-- Generos
-- ---------------------------------------------------------------------
INSERT INTO generos (nombre, slug) VALUES
  ('Plataformas',   'plataformas'),
  ('Accion',        'accion'),
  ('Aventura',      'aventura'),
  ('RPG',           'rpg'),
  ('Peleas',        'peleas'),
  ('Disparos',      'disparos'),
  ('Carreras',      'carreras'),
  ('Deportes',      'deportes'),
  ('Rompecabezas',  'rompecabezas'),
  ('Estrategia',    'estrategia'),
  ('Demo tecnica',  'demo-tecnica'),
  ('Homebrew',      'homebrew');

-- ---------------------------------------------------------------------
-- Logros
--   tipo   = metrica evaluada por el backend
--   umbral = valor a alcanzar
-- ---------------------------------------------------------------------
INSERT INTO logros (codigo, nombre, descripcion, icono, tipo, umbral) VALUES
  ('primera_partida', 'Insert Coin',        'Jugaste tu primera partida en RetroCloud.',        'play',   'partidas',  1),
  ('diez_partidas',   'Habitual',           'Acumulaste 10 partidas.',                          'repeat', 'partidas', 10),
  ('cien_partidas',   'Veterano',           'Acumulaste 100 partidas.',                         'medal',  'partidas',100),
  ('una_hora',        'Una hora perdida',   'Jugaste 60 minutos en total.',                     'clock',  'tiempo',   3600),
  ('diez_horas',      'Sin vida social',    'Jugaste 10 horas en total.',                       'fire',   'tiempo',  36000),
  ('cinco_juegos',    'Curioso',            'Probaste 5 juegos distintos.',                     'grid',   'juegos',    5),
  ('veinte_juegos',   'Coleccionista',      'Probaste 20 juegos distintos.',                    'star',   'juegos',   20),
  ('primer_save',     'Guardado en la nube','Subiste tu primera partida guardada al servidor.', 'cloud',  'saves',     1),
  ('diez_saves',      'Precavido',          'Tienes 10 partidas guardadas en la nube.',         'shield', 'saves',    10);

-- ---------------------------------------------------------------------
-- NOTA SOBRE EL CATALOGO
--   Este seed NO incluye juegos: el catalogo se llena desde el panel
--   de administracion (Admin -> Juegos -> Nuevo juego), que calcula el
--   SHA-256 de la ROM, valida su extension y tamano, y la almacena
--   fuera del document root.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
-- Neo Geo
--   Usa el mismo nucleo que el arcade generico (FBNeo), pero se lista
--   aparte porque es una plataforma reconocible por si misma y su BIOS
--   es distinto del de otras placas.
-- ---------------------------------------------------------------------
INSERT INTO consolas
  (nombre, slug, core_emulador, fabricante, anio_lanzamiento, extensiones,
   bits, color_hex, orden)
VALUES
  ('Neo Geo', 'neogeo', 'arcade', 'SNK', 1990, 'zip', '16-bit', '#d4302f', 115);

-- Plataformas que no arrancan sin la firmware del sistema.
-- El archivo se carga despues desde Panel > Consolas.
UPDATE consolas SET bios_requerido = 1 WHERE slug IN ('psx', 'neogeo');

-- ---------------------------------------------------------------------
-- PSP
--   El nucleo ppsspp emula la consola por alto nivel (HLE), asi que no
--   necesita BIOS. Formatos: ISO, CSO (ISO comprimido, aprox. la mitad
--   de tamano) y PBP.
-- ---------------------------------------------------------------------
INSERT INTO consolas
  (nombre, slug, core_emulador, fabricante, anio_lanzamiento, extensiones,
   bits, color_hex, orden)
VALUES
  ('PSP', 'psp', 'psp', 'Sony', 2004, 'iso,cso,pbp', '32-bit', '#8854d0', 75);
