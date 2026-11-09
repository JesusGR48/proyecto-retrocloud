/* =====================================================================
   RetroCloud · Formularios de acceso
   =====================================================================
   Logica de login.php y registro.php. Los dos formularios se envian por
   fetch() contra api/auth.php, de modo que los errores se pintan junto
   a cada campo sin recargar la pagina.
   ===================================================================== */

'use strict';

import { api, avisar, cargando, ErrorApi, pintarErrores } from './api.js';

/* ---------------------------------------------------------------------
   Mostrar / ocultar contrasena
   --------------------------------------------------------------------- */
document.querySelectorAll('[data-ver-password]').forEach((boton) => {
  boton.addEventListener('click', () => {
    const campo = document.getElementById(boton.dataset.verPassword);
    if (!campo) return;

    const oculta = campo.type === 'password';
    campo.type = oculta ? 'text' : 'password';
    boton.setAttribute('aria-label', oculta ? 'Ocultar contrasena' : 'Mostrar contrasena');
    boton.style.color = oculta ? 'var(--primario-claro)' : '';

    // Devolvemos el foco al campo para no romper el flujo de escritura.
    campo.focus();
    campo.setSelectionRange(campo.value.length, campo.value.length);
  });
});

/* ---------------------------------------------------------------------
   Aviso general del formulario
   --------------------------------------------------------------------- */
function mostrarAviso(idBase, mensaje) {
  const caja = document.getElementById(`aviso-${idBase}`);
  const texto = document.getElementById(`aviso-${idBase}-texto`);
  if (!caja || !texto) {
    avisar(mensaje, 'error');
    return;
  }
  texto.textContent = mensaje;
  caja.hidden = false;
  caja.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function ocultarAviso(idBase) {
  const caja = document.getElementById(`aviso-${idBase}`);
  if (caja) caja.hidden = true;
}

/* =====================================================================
   LOGIN
   ===================================================================== */
const formLogin = document.getElementById('form-login');

formLogin?.addEventListener('submit', async (evento) => {
  evento.preventDefault();
  ocultarAviso('login');
  pintarErrores(formLogin, {});

  const usuario = formLogin.usuario.value.trim();
  const password = formLogin.password.value;

  if (usuario === '' || password === '') {
    mostrarAviso('login', 'Escribe tu usuario y tu contrasena.');
    return;
  }

  const boton = formLogin.querySelector('button[type="submit"]');
  cargando(boton, true, 'Entrando...');

  try {
    const r = await api.auth.login(usuario, password);
    avisar(`Hola de nuevo, ${r.usuario}.`, 'exito', 2200);

    // El destino ya viene validado por el servidor en login.php.
    const destino = formLogin.dataset.volver || 'index.php';
    setTimeout(() => { location.href = destino; }, 320);
  } catch (e) {
    cargando(boton, false);

    if (e instanceof ErrorApi && e.esValidacion) {
      pintarErrores(formLogin, e.campos);
    }
    mostrarAviso('login', e.message);

    // Tras un fallo, limpiamos la contrasena y dejamos el foco ahi.
    formLogin.password.value = '';
    formLogin.password.focus();
  }
});

/* =====================================================================
   REGISTRO
   ===================================================================== */
const formRegistro = document.getElementById('form-registro');

/* ---- Medidor de fuerza de contrasena ----
   No bloquea nada: solo informa. La regla real la impone el servidor.
   Puntua longitud y variedad de caracteres, que es lo que de verdad
   encarece un ataque por diccionario. */
function evaluarFuerza(password) {
  if (password === '') return { pct: 0, texto: '', color: '' };

  let puntos = 0;
  if (password.length >= 8)  puntos += 1;
  if (password.length >= 12) puntos += 1;
  if (password.length >= 16) puntos += 1;
  if (/[a-z]/.test(password) && /[A-Z]/.test(password)) puntos += 1;
  if (/[0-9]/.test(password)) puntos += 1;
  if (/[^A-Za-z0-9]/.test(password)) puntos += 1;

  // Penaliza repeticiones obvias: "aaaaaaaa", "12121212".
  if (/^(.)\1+$/.test(password) || /^(..)\1+$/.test(password)) {
    puntos = Math.min(puntos, 1);
  }

  const niveles = [
    { pct: 15,  texto: 'Muy debil',  color: 'var(--error)' },
    { pct: 30,  texto: 'Debil',      color: 'var(--error)' },
    { pct: 50,  texto: 'Mejorable',  color: 'var(--aviso)' },
    { pct: 70,  texto: 'Aceptable',  color: 'var(--aviso)' },
    { pct: 85,  texto: 'Buena',      color: 'var(--exito)' },
    { pct: 100, texto: 'Excelente',  color: 'var(--exito)' },
  ];

  return niveles[Math.min(puntos, niveles.length - 1)];
}

const campoPassword = formRegistro?.password;
const barraFuerza = document.getElementById('fuerza-barra');
const textoFuerza = document.getElementById('fuerza-texto');

campoPassword?.addEventListener('input', () => {
  const r = evaluarFuerza(campoPassword.value);

  if (barraFuerza) {
    barraFuerza.style.width = `${r.pct}%`;
    barraFuerza.style.background = r.color || '';
  }

  if (textoFuerza) {
    textoFuerza.textContent = r.texto
      ? `Fuerza: ${r.texto}`
      : 'Minimo 8 caracteres, con letras y numeros.';
    textoFuerza.style.color = r.color || '';
  }
});

/* ---- Confirmacion en vivo ---- */
const campoPassword2 = formRegistro?.password2;

campoPassword2?.addEventListener('input', () => {
  const destino = formRegistro.querySelector('[data-error-de="password2"]');
  if (!destino) return;

  if (campoPassword2.value === '' || campoPassword2.value === campoPassword.value) {
    destino.textContent = '';
    campoPassword2.removeAttribute('aria-invalid');
  } else {
    destino.textContent = 'Las contrasenas no coinciden.';
    campoPassword2.setAttribute('aria-invalid', 'true');
  }
});

formRegistro?.addEventListener('submit', async (evento) => {
  evento.preventDefault();
  ocultarAviso('registro');
  pintarErrores(formRegistro, {});

  const datos = {
    usuario:   formRegistro.usuario.value.trim(),
    correo:    formRegistro.correo.value.trim(),
    password:  formRegistro.password.value,
    password2: formRegistro.password2.value,
  };

  if (datos.password !== datos.password2) {
    pintarErrores(formRegistro, { password2: 'Las contrasenas no coinciden.' });
    return;
  }

  const boton = formRegistro.querySelector('button[type="submit"]');
  cargando(boton, true, 'Creando cuenta...');

  try {
    const r = await api.auth.registro(datos);
    avisar(r.mensaje ?? 'Cuenta creada.', 'exito', 2200);
    setTimeout(() => { location.href = 'index.php'; }, 420);
  } catch (e) {
    cargando(boton, false);

    if (e instanceof ErrorApi && e.esValidacion) {
      pintarErrores(formRegistro, e.campos);
      if (Object.keys(e.campos).length === 0) {
        mostrarAviso('registro', e.message);
      }
    } else {
      mostrarAviso('registro', e.message);
    }
  }
});
