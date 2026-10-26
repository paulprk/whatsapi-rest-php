# WhatsApi — API REST de mensajería instantánea

Seminario de Lenguajes, opción PHP, React y API REST — 2026
Primera entrega: API REST con Slim 4

## Grupo 2

### Integrantes

- Paul Falcon
- Matias Lissalde

## Tecnologías y librerías externas

El proyecto corre en contenedores Docker (PHP 8, MySQL 8 y phpMyAdmin). Las librerías de PHP se instalan con Composer y son solo dos; el resto son dependencias que Composer trae automáticamente.

| Librería | Versión instalada | Para qué se usa |
|---|---|---|
| `slim/slim` | 4.15.3 | Framework del proyecto: define las rutas (`$app->get`, `$app->post`, ...), los middlewares y el manejo de errores |
| `slim/psr7` | 1.8.0 | Implementación de los objetos `Request` y `Response` (PSR-7) que usa Slim para leer el pedido y armar la respuesta |

Dependencias que instala Composer solas (no se usan directamente en el código): `nikic/fast-route` (router de Slim), `psr/http-message`, `psr/http-factory`, `psr/http-server-handler`, `psr/http-server-middleware`, `psr/container`, `psr/log`, `fig/http-message-util` y `ralouphie/getallheaders`.

No se usan librerías para la base de datos: se accede con **PDO**, que viene incluido en PHP.

## Instalación

Requisitos: Docker y Docker Compose.

1. Crear el archivo `.env` a partir de `.env.dist`:

   ```bash
   cp .env.dist .env
   ```

2. Crear el volumen para la base de datos (el nombre es el valor de `DB_VOLUME`):

   ```bash
   docker volume create seminariophp
   ```

3. Levantar los servicios:

   ```bash
   docker compose up -d
   ```

   El servicio `composer` ejecuta `composer install` automáticamente la primera vez, por lo que no hace falta instalar las dependencias a mano. Si la carpeta `slim/vendor` no existe, se crea sola.

4. La base de datos se crea sola. El archivo `db/seminariophp.sql` está montado en `/docker-entrypoint-initdb.d`, y MySQL lo ejecuta automáticamente **la primera vez** que se crea el volumen, generando las tablas `usuario`, `chat` y `mensaje`. No hace falta importar nada a mano.

   Si más adelante se quiere empezar de cero (por ejemplo, porque cambió el esquema), hay que borrar y volver a crear el volumen:

   ```bash
   docker compose down
   docker volume rm seminariophp
   docker volume create seminariophp
   docker compose up -d
   ```

   También se puede importar a mano desde phpMyAdmin (<http://localhost:8080>), eligiendo la base `seminariophp` e importando `db/seminariophp.sql`.

5. La API queda disponible en <http://localhost> (puerto `SLIM_PORT` del `.env`).

### Otros comandos

```bash
docker compose down -v          # terminar los servicios
docker volume rm seminariophp   # eliminar la base de datos
```

### Usuario administrador

El enunciado indica que existe un usuario administrador, pero el script SQL no trae ninguno. Para crearlo: registrar un usuario con `POST /usuarios` y luego, en phpMyAdmin, poner `is_admin = 1` en su fila de la tabla `usuario`.

## Autenticación

1. `POST /login` con `username` y `password`. Si son correctos, la respuesta incluye el header `Authorization: Bearer <token>`, y en el cuerpo los datos del usuario logueado (sin el token ni la password):

   ```json
   {"mensaje": "Login exitoso", "usuario": {"id": 10, "username": "@ana", "nombre": "Ana Perez", "is_admin": 0}}
   ```

2. En cada pedido que requiera login, enviar ese mismo header `Authorization: Bearer <token>`.
3. El token vence a los **5 minutos** y se renueva otros 5 minutos con cada pedido autorizado.
4. `POST /logout` borra el token.

El header `Authorization` de la respuesta está habilitado para el navegador (`Access-Control-Expose-Headers: Authorization`), así un cliente web (React) puede leer el token.

## Endpoints

La API responde siempre JSON, incluidos los errores de rutas inexistentes (404) y de métodos no permitidos (405). Las fechas se devuelven en UTC con formato ISO 8601, por ejemplo `"2026-10-26T15:03:24Z"`. Los errores de validación se devuelven como un objeto indexado por el nombre del campo, por ejemplo `{"username": "Debe empezar con @", "password": "..."}`.

| Método y ruta | Login | Quién puede | Descripción |
|---|---|---|---|
| `POST /login` | No | Cualquiera | Devuelve el token en el header `Authorization` |
| `POST /logout` | Sí | Usuario logueado | Borra el token |
| `POST /usuarios` | No | Cualquiera | Registra un usuario (`es_publico = 0`) |
| `GET /usuarios` | Opcional | Cualquiera | Lista usuarios con `search`, `order`, `limit`, `offset` y `total` |
| `GET /usuarios/{user_id}` | Sí | El mismo usuario o admin | Devuelve `username`, `nombre` y `es_publico` |
| `PUT /usuarios/{user_id}` | Sí | El mismo usuario o admin | Modifica `nombre`, `password` y/o `es_publico` |
| `DELETE /usuarios/{user_id}` | Sí | Solo admin | Elimina el usuario, sus chats y sus mensajes |
| `POST /chats/{user_id}` | Sí | Usuario logueado | Envía un mensaje a `user_id`; crea el chat si todavía no existe |
| `GET /chats` | Sí | Usuario logueado | Lista sus chats con el último mensaje de cada uno |
| `PUT /chats/{user_id}` | Sí | Participante del chat | Modifica `nombre`, `descripcion`, `color` y/o `esta_bloqueado` |
| `GET /chats/{user_id}/historia/{quantity}` | Sí | Participante del chat | Devuelve los mensajes del chat (también existe `/historia` sin cantidad) |
| `DELETE /mensajes/{message_id}` | Sí | Autor del mensaje o admin | Elimina un mensaje |

### Códigos de estado

| Código | Significado |
|---|---|
| 200 | La solicitud se procesó correctamente |
| 400 | Faltan datos, o son inválidos |
| 401 | Sin token, token inválido o vencido, o sin permiso para la acción |
| 404 | El recurso no existe |
| 409 | La operación no se pudo realizar |

### Listado de usuarios: `GET /usuarios`

Parámetros opcionales en la URL:

| Parámetro | Valor | Por defecto |
|---|---|---|
| `search` | Texto que debe contener el `username` o el `nombre` | sin filtro |
| `order` | `asc` o `desc`, aplicado sobre el `username` | `asc` |
| `limit` | Entero mayor a 0 | `10` |
| `offset` | Entero mayor o igual a 0 | `0` |

- Sin token (o con token inválido) se listan solo los usuarios con `es_publico = 1`.
- Con token válido se listan todos los usuarios, excepto el logueado.
- La respuesta incluye `total` (cantidad de registros que cumplen el filtro, independiente de `limit`) y `usuarios`.
- Si quien consulta es admin, cada usuario incluye además `cantidad_chats`.

### Chats y mensajes

Un chat une a dos usuarios (`creado_por` y `usuario_id`) y se busca sin importar quién lo creó. Las características del chat (nombre, descripción, color y bloqueo) se comparten entre los dos participantes.

**`POST /chats/{user_id}`**: cuerpo `{"mensaje": "..."}` (1 a 255 caracteres).

- Si no existe un chat entre los dos, se crea con el nombre del destinatario como nombre del chat y la descripción vacía, y se guarda el primer mensaje.
- Si el chat tiene **un solo mensaje**, solo puede escribir el otro usuario hasta que responda (409 si vuelve a escribir quien envió el primero).
- Si el chat tiene dos mensajes o más, cualquiera de los dos puede escribir.
- Si el chat está bloqueado no se pueden enviar mensajes (409).
- No se puede enviar un mensaje a uno mismo (400). Si `user_id` no existe, 404.
- Responde con `chat_id` y `mensaje_id` del mensaje creado.

**`GET /chats`**: lista los chats donde participa el usuario logueado (los que creó y los que le abrieron), ordenados por la fecha del último mensaje, del más reciente al más antiguo. Cada chat incluye `id`, `nombre`, `descripcion`, `esta_bloqueado`, `creado_por`, `usuario_id`, `nombre_otro_usuario` y el último mensaje (`ultimo_mensaje`, `ultimo_mensaje_enviado_por` y `fecha_ultimo_mensaje`), sea enviado o recibido. Si un chat no tiene mensajes, el último mensaje es `null`.

**`PUT /chats/{user_id}`**: se envían solo los campos a modificar (al menos uno).

| Campo | Regla |
|---|---|
| `nombre` | Letras, números y espacios. Entre 3 y 15 caracteres |
| `descripcion` | Letras, números y espacios. Entre 20 y 40 caracteres |
| `color` | Hexadecimal de seis dígitos, por ejemplo `#FF8800` |
| `esta_bloqueado` | `true` o `false` (también `1` o `0`). Una vez bloqueado, un chat **no se puede desbloquear** (409) |

Si no existe un chat entre el usuario logueado y `user_id`, responde 404.

**`GET /chats/{user_id}/historia/{quantity}`**: devuelve hasta `quantity` mensajes del chat entre el usuario logueado y `user_id`, del más antiguo al más reciente, **tanto enviados como recibidos**. Cada mensaje incluye `id`, `texto`, `fecha_creacion` y `enviado_por`.

| Parámetro | Regla | Por defecto |
|---|---|---|
| `quantity` | Entero entre 1 y 10 (en la ruta) | `5` (ruta `/chats/{user_id}/historia`, sin cantidad) |
| `offset` | Entero mayor o igual a 0 (en la URL, opcional) | `0` |

Si no existe un chat entre los dos usuarios, responde 404. Con `offset` se pasa a los mensajes anteriores.

**`DELETE /mensajes/{message_id}`**: elimina el mensaje de la tabla. Un administrador puede eliminar cualquier mensaje; un usuario común solo los que **envió él** (401 si intenta eliminar uno recibido o de otro). Si el mensaje no existe, 404.

## Decisiones de diseño

Cosas que el enunciado no define y que se resolvieron así:

- **Token en el header.** El login devuelve el token en el header `Authorization: Bearer <token>` y no en el cuerpo de la respuesta. En el cuerpo devuelve los datos del usuario logueado (`id`, `username`, `nombre`, `is_admin`) para que el cliente sepa quién es sin hacer otro pedido.
- **El username no distingue mayúsculas.** `@Ana` y `@ana` son el mismo usuario, tanto para registrarse (no se puede repetir con otras mayúsculas) como para iniciar sesión. Confirmado por la cátedra.
- **Una sola sesión por usuario.** Cada login genera un token nuevo y el anterior deja de servir.
- **Fechas en UTC.** Se guardan y se devuelven en UTC con formato ISO 8601 (terminan en `Z`), para que cada cliente las muestre en su zona horaria.
- **Un administrador no puede eliminarse a sí mismo** (409), para evitar dejar al sistema sin administradores.
- **Errores de base de datos.** Si falla la conexión o una consulta, se responde 409 (la operación no se pudo realizar).
- **Valores por defecto de la paginación:** `limit = 10` y `offset = 0`.
- **Un usuario no puede enviarse mensajes a sí mismo** (400).
- **Chat sin mensajes.** Si se eliminan todos los mensajes de un chat, el chat sigue existiendo (vacío) y `GET /chats` devuelve el último mensaje como `null`.
- **Nombre y descripción de un chat admiten espacios** (letras, números y espacios), porque el enunciado habla de "letras y números" y una descripción de 20 a 40 caracteres sin espacios no es razonable.
- **Cantidad de la historia fuera de rango.** Un `quantity` menor a 1 o mayor a 10 se rechaza con 400 en lugar de recortarse.
- **Identificadores estrictos.** Los ids de la URL deben ser enteros positivos escritos solo con dígitos; valores como `" 5"`, `"+5"` o `"05"` se rechazan con 400.
- **Contraseñas.** Se guardan con `password_hash` (nunca en texto plano) y se comparan con `password_verify`.
- **Dos middlewares de autenticación.** `$authMiddleware` exige token válido (401 si falta o es inválido). `$authOpcional` deja pasar al pedido como anónimo si no hay token o es inválido; se usa solo en `GET /usuarios`.

## Probar con Postman

1. `POST http://localhost/login` con el cuerpo JSON `{"username": "...", "password": "..."}` (en Body, `raw`, tipo `JSON`).
2. Copiar el valor del header `Authorization` de la respuesta (`Bearer <token>`).
3. En los demás pedidos, agregar el header `Authorization` con ese mismo valor.

Para no copiarlo a mano, se puede agregar al pedido de login, en la pestaña *Scripts → Post-response*:

```javascript
pm.environment.set("token", pm.response.headers.get("Authorization"));
```

y usar `{{token}}` como valor del header `Authorization` en el resto de los pedidos.

## Estructura del proyecto

```
slim/
├── index.php          Configuración de Slim y carga de las rutas
├── db.php             Conexión a la base con PDO
├── middleware.php     Autenticación por token
├── auth.php           Rutas /login y /logout
├── users.php          Rutas de /usuarios
├── chats.php          Rutas de /chats y de la historia de mensajes
├── mensajes.php       Ruta DELETE /mensajes/{message_id}
├── validaciones.php   Funciones de validación y de permisos
└── composer.json      Dependencias
```
