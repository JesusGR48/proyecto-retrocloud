# Configuración de HTTPS / SSL

Documento complementario · RetroCloud

Este documento cubre el requisito **"Uso o explicación de HTTPS / SSL"** del
proyecto: primero qué es y por qué importa, después cómo activarlo en el
servidor local.

---

## 1. Qué problema resuelve

HTTP envía los datos en texto plano. Cualquiera con acceso a la red por la que
viaja el tráfico —el WiFi de la cafetería, el router del campus, el proveedor
de internet— puede leerlos y modificarlos.

En RetroCloud eso significaría exponer:

| Dato | Riesgo si viaja en claro |
|---|---|
| Contraseña del formulario de login | Robo directo de la cuenta |
| Cookie de sesión `retrocloud_sid` | Suplantación sin necesidad de la contraseña |
| Token CSRF | Permite falsificar peticiones |
| Partidas guardadas | Un atacante puede sustituirlas al vuelo |

Nótese un matiz importante: la contraseña se almacena como *hash* bcrypt en la
base de datos, pero **viaja en claro desde el formulario hasta el servidor**.
El hash protege la base de datos si alguien la roba; no protege el tránsito.
Eso solo lo hace TLS.

---

## 2. Qué es TLS exactamente

**SSL** (*Secure Sockets Layer*) es el nombre histórico. El protocolo que se
usa hoy es **TLS** (*Transport Layer Security*), versión 1.2 o 1.3. Por
costumbre se sigue diciendo "certificado SSL".

TLS aporta tres garantías:

1. **Confidencialidad** — el contenido viaja cifrado.
2. **Integridad** — si alguien altera un byte en el camino, se detecta.
3. **Autenticidad** — el certificado prueba que el servidor es quien dice ser.

### Cómo se establece la conexión (*handshake*)

```
   CLIENTE                                      SERVIDOR
      │                                             │
      │ ── 1. ClientHello ────────────────────────► │
      │      versiones de TLS y cifrados que acepta │
      │                                             │
      │ ◄── 2. ServerHello + certificado ────────── │
      │      elige el cifrado y envía su clave      │
      │         pública firmada por una CA          │
      │                                             │
   3. Valida el certificado                         │
      (¿lo firma una CA de confianza?               │
       ¿el dominio coincide? ¿sigue vigente?)       │
      │                                             │
      │ ── 4. Intercambio de claves (ECDHE) ──────► │
      │      ambos derivan la MISMA clave de sesión │
      │      sin que esta llegue a viajar por la red│
      │                                             │
      │ ◄═══ 5. Tráfico cifrado simétrico ════════► │
```

El detalle fino: la criptografía **asimétrica** (lenta) se usa solo para
acordar la clave; a partir de ahí todo va con cifrado **simétrico** (rápido).
Con ECDHE se obtiene además *forward secrecy*: aunque la clave privada del
servidor se filtrara mañana, el tráfico capturado hoy seguiría siendo ilegible.

---

## 3. Activar HTTPS en MAMP

### Paso 1 · Generar el certificado

MAMP incluye OpenSSL. Abre una terminal y ejecuta:

```bash
cd C:/MAMP/conf/apache
C:/MAMP/bin/apache/bin/openssl.exe req -x509 -nodes -days 825 -newkey rsa:2048 -keyout retrocloud.key -out retrocloud.crt -subj "/C=MX/ST=Estado/L=Ciudad/O=RetroCloud/CN=localhost" -addext "subjectAltName=DNS:localhost,IP:127.0.0.1"
```

Qué significa cada parte:

| Opción | Significado |
|---|---|
| `req -x509` | Genera un certificado autofirmado, sin pasar por una CA |
| `-nodes` | No cifra la clave privada (si no, Apache pediría contraseña al arrancar) |
| `-days 825` | Vigencia; 825 días es el máximo que aceptan los navegadores |
| `-newkey rsa:2048` | Crea a la vez una clave RSA de 2048 bits |
| `-keyout` / `-out` | Archivos de salida: clave privada y certificado |
| `CN=localhost` | *Common Name*: el dominio que ampara el certificado |
| `subjectAltName` | **Imprescindible.** Los navegadores actuales ignoran `CN` y solo miran este campo |

### Paso 2 · Habilitar el módulo SSL

En `C:/MAMP/conf/apache/httpd.conf`, descomenta estas dos líneas (quita el `#`):

```apache
LoadModule ssl_module modules/mod_ssl.so
Include conf/extra/httpd-ssl.conf
```

### Paso 3 · Configurar el *virtual host*

En `C:/MAMP/conf/apache/extra/httpd-ssl.conf`, deja el bloque así:

```apache
Listen 443

<VirtualHost _default_:443>
    DocumentRoot "C:/MAMP/htdocs/retrocloud/public"
    ServerName localhost:443

    SSLEngine on
    SSLCertificateFile      "C:/MAMP/conf/apache/retrocloud.crt"
    SSLCertificateKeyFile   "C:/MAMP/conf/apache/retrocloud.key"

    # Solo versiones modernas: TLS 1.0 y 1.1 están obsoletos
    SSLProtocol             -all +TLSv1.2 +TLSv1.3
    SSLCipherSuite          HIGH:!aNULL:!MD5:!3DES
    SSLHonorCipherOrder     on

    <Directory "C:/MAMP/htdocs/retrocloud/public">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### Paso 4 · Reiniciar y probar

Reinicia Apache desde MAMP y abre:

```
https://localhost/
```

El navegador mostrará una advertencia de seguridad. **Es lo esperado** y no
indica un fallo: el certificado es autofirmado, así que ninguna autoridad
certificadora respalda su identidad. El cifrado funciona igual; lo que falta
es la verificación de identidad por un tercero. Pulsa *Avanzado → Continuar*.

### Paso 5 · Forzar HTTPS en la aplicación

Una vez funcionando, en `src/config.php`:

```php
define('FORZAR_HTTPS', true);
```

Y descomenta el bloque de reescritura en `public/.htaccess`:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</IfModule>
```

---

## 4. Qué cambia en RetroCloud al activar HTTPS

El código ya está preparado y reacciona solo:

**`src/bootstrap.php`** marca la cookie de sesión como `Secure`, de modo que
el navegador deja de enviarla por HTTP:

```php
$seguro = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'secure'   => $seguro,
    'httponly' => true,
    'samesite' => 'Lax',
]);
```

Y emite **HSTS**, que ordena al navegador no volver a intentar HTTP durante un
año:

```php
if ($seguro) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
```

HSTS cierra una ventana real: sin él, el primer acceso del día escribiendo
`localhost` en la barra de direcciones viaja por HTTP antes de la redirección,
y ese salto es interceptable (*SSL stripping*).

`public/diagnostico.php` refleja el estado en la sección **Seguridad**: la
comprobación *HTTPS activo* pasa de aviso a correcto.

---

## 5. En producción real

Un certificado autofirmado sirve para desarrollo y para demostrar el concepto,
pero ningún visitante aceptaría la advertencia.

En un servidor con dominio propio se usa **Let's Encrypt**, que emite
certificados gratuitos y reconocidos por todos los navegadores:

```bash
sudo certbot --apache -d midominio.com -d www.midominio.com
```

Certbot genera el certificado, ajusta la configuración de Apache y programa la
renovación automática (vencen cada 90 días). La mayoría de los *hostings*
compartidos lo ofrecen con un clic desde su panel.

---

## 6. Comprobaciones

| Qué verificar | Cómo |
|---|---|
| El candado aparece | Barra de direcciones del navegador |
| Versión de TLS y cifrado | DevTools → pestaña *Security* |
| La cookie es `Secure` | DevTools → *Application* → *Cookies* |
| HSTS se envía | DevTools → *Network* → cabeceras de respuesta |
| HTTP redirige a HTTPS | `curl -I http://localhost/` → debe responder `301` |
| Calidad general (servidor público) | [ssllabs.com/ssltest](https://www.ssllabs.com/ssltest/) |
