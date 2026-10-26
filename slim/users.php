<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

/** @var \Slim\App $app */
/** @var \Closure $authMiddleware */
/** @var \Closure $authOpcional */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/validaciones.php';

$app->post('/usuarios', function (Request $request, Response $response) {
    $data = $request->getParsedBody();

    $errores = validarRegistro($data ?? []);

    if (!empty($errores)) {
        $response->getBody()->write(json_encode($errores, JSON_UNESCAPED_UNICODE));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    $password = $data['password'];

    try {
        $db = getDB();

        // verifica que el username sea unico
        $stmt = $db->prepare("SELECT id FROM usuario WHERE username = :username");
        $stmt->execute(['username' => $data['username']]);
        if ($stmt->fetch()) {
            $response->getBody()->write(json_encode(["username" => "El username ya existe"], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        }

        // hashea la contraseña antes de guardarla (nunca se guarda en texto plano)
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO usuario (username, password, nombre, es_publico) VALUES 
        (:username, :password, :nombre, 0)");
        $stmt->execute([
            'username' => $data['username'],
            'password' => $passwordHash,
            'nombre' => $data['nombre'],
        ]);

        $response->getBody()->write(json_encode([
            "mensaje" => "Usuario creado correctamente",
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');

    } catch (PDOException $e) {
        $response->getBody()->write(json_encode(["error" => "Error al crear el usuario"], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(409)->withHeader('Content-Type', 'application/json'); //consultar fallo 409 o 500
    }

});

$app->get('/usuarios/{user_id}', function (Request $request, Response $response,array $args) {
    //id estricto: solo digitos (rechaza "10abc", " 10", "+10", "010")
    $userId = parsearId($args['user_id']);
    if($userId === null) {
        $response->getBody()->write(json_encode(["user_id" => "Debe ser un ID valido"], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    if(!puedeAccederAUsurio($userId,$request)) {
        $response->getBody()->write(json_encode(["error" => "No es el usuario, o no es Admin"], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT username, nombre, es_publico FROM usuario WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $perfil = $stmt->fetch();

        if(!$perfil) {
            $response->getBody()->write(json_encode(["error" => "Usuario no encontrado"], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode($perfil, JSON_UNESCAPED_UNICODE));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');

    } catch(PDOException $e) {
        $response->getBody()->write(json_encode(["error" => "Error de conexion"]));
        return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
    }
})->add($authMiddleware);

$app->put('/usuarios/{user_id}', function (Request $request,Response $response,array $args) {
    //id estricto: solo digitos (rechaza "10abc", " 10", "+10", "010")
    $userId = parsearId($args['user_id']);
    if($userId === null) {
        $response->getBody()->write(json_encode(["user_id" => "Debe ser un ID valido"], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    //validar q sea usuario o admin
    if (!puedeAccederAUsurio($userId, $request)) {
        $response->getBody()->write(json_encode(["error" => "No es el usuario, o no es Admin"], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    $data = $request->getParsedBody() ?? [];

    //validar que no esten vacios los campos
    if (!isset($data['nombre']) && !isset($data['password']) && !isset($data['es_publico'])) {
        $response->getBody()->write(json_encode(["error" => "No hay nada que modificar"], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    //validar datos por dato que coincidan con las reglas de cada uno
    $errores = [];
    if(isset($data['nombre'])) {
        $error = validarNombre($data['nombre']);
        if($error !== null) {
            $errores['nombre'] = $error;
        }
    }
    if(isset($data['password'])) {
        $error = validarPassword($data['password']);
        if($error !== null) {
            $errores['password'] = $error;
        }
    }
    //es_publico: acepta true/false, 1/0, "1"/"0" y "true"/"false" (misma regla que esta_bloqueado de chats)
    $esPublicoNuevo = null;
    if(isset($data['es_publico'])) {
        $esPublicoNuevo = normalizarBooleano($data['es_publico']);   //1, 0 o null si no es valido
        if($esPublicoNuevo === null) {
            $errores['es_publico'] = "Debe ser true o false (o 1 o 0)";
        }
    }

    //si no esta vacio el array errores imprime todos los errores encontrados
    if(!empty($errores)) {
        $response->getBody()->write(json_encode($errores, JSON_UNESCAPED_UNICODE));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT nombre,password,es_publico FROM usuario WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $actual = $stmt->fetch();

        if(!$actual) {
            $response->getBody()->write(json_encode(["error" => "Usuario no encontrado"], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $nombre = $data['nombre'] ?? $actual['nombre'];
        $password = isset($data['password']) ? password_hash($data['password'], PASSWORD_DEFAULT) : $actual['password'];
        //se usa el valor ya convertido (con (int) el texto "true" se guardaria como 0)
        $es_publico = $esPublicoNuevo ?? $actual['es_publico'];

        $stmt = $db->prepare("UPDATE usuario SET nombre = :nombre, password = :password, es_publico = :es_publico WHERE id = :id");
        $stmt->execute([
            'nombre' => $nombre,
            'password' => $password,
            'es_publico' => $es_publico,
            'id' => $userId,
        ]);

        $response->getBody()->write(json_encode(["mensaje" => "Usuario actualizado"], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');

    } catch(PDOException $e) {
        $response->getBody()->write(json_encode(["error" => "Error de conexion"], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
    }



})->add($authMiddleware);

$app->get('/usuarios', function (Request $request, Response $response) {
    $esAdmin = (int) $request->getAttribute('auth_admin') === 1;
    $logueado = $request->getAttribute('auth_id');
    $condiciones = [];
    $params = [];

    //quien pide
    if($logueado === null) {
        $condiciones[] = "es_publico = 1";
    } else {
        $condiciones[] = "id != :logueado";
        $params['logueado'] = (int) $logueado;
    }

    //search
    $query = $request->getQueryParams();
    $search = $query['search'] ?? '';
    if(!is_string($search)) {
        $response->getBody()->write(json_encode(["search" => "Debe ser texto"], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    if($search !== '') {
        $condiciones[] = "(username LIKE :search OR nombre LIKE :search)";
        $params['search'] = "%" . $search . "%";
    }

    //order
    $order = $query['order'] ?? 'asc'; //por defecto ascendente
    $errores = [];
    if(!is_string($order) || !in_array(strtolower($order), ['asc', 'desc'], true)){ //verifica que $order sea string y que sea asc o desc
        $errores['order'] = "Debe ser asc o desc";
    } else {
        $order = strtolower($order);
    }

    //limit y offset
    $limit = $query['limit'] ?? '10';
    $offset = $query['offset'] ?? '0';
    if(!is_string($limit) || !ctype_digit($limit) || (int) $limit < 1 ) {
        $errores['limit'] = "Debe ser un entero mayor a 0";
    }
    if (!is_string($offset) || !ctype_digit($offset) ) {
        $errores['offset'] = "Debe ser un entero mayor o igual a 0";
    }
    $limit = (int) $limit;
    $offset = (int) $offset;

    //imprimir errores(si hay)
    if(!empty($errores)) {
        $response->getBody()->write(json_encode($errores, JSON_UNESCAPED_UNICODE));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    $columnas = "id,nombre, es_publico, is_admin";      //si no es admin se devuelve esto

    //si es admin devolver cantidad de chats en los que participa el usuario buscado
    if($esAdmin) {
        $columnas .= ", (SELECT COUNT(*) FROM chat WHERE chat.creado_por = usuario.id OR 
        chat.usuario_id = usuario.id) AS cantidad_chats";
    }

    //sql 
    $where = "WHERE " . implode(" AND ",$condiciones);
    $sql = "SELECT $columnas FROM usuario $where ORDER BY username $order LIMIT $limit OFFSET $offset";
    
    //db
    try {
        $db = getDB();
        $stmtTotal = $db->prepare("SELECT COUNT(*) FROM usuario $where");
        $stmtTotal->execute($params);
        $total = (int) $stmtTotal->fetchColumn();
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $usuarios = $stmt->fetchAll();
        $response->getBody()->write(json_encode(["total" => $total, "usuarios" => $usuarios], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    } catch(PDOException $e) {
        $response->getBody()->write(json_encode(["error" => "Error de conexion"], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
}})->add($authOpcional);

$app->delete('/usuarios/{user_id}', function (Request $request,Response $response,array $args) {
    $esAdmin = (int) $request->getAttribute('auth_admin') === 1;

    //id estricto: solo digitos (rechaza "10abc", " 10", "+10", "010")
    $userId = parsearId($args['user_id']);
    if($userId === null) {
        $response->getBody()->write(json_encode(["user_id" => "Debe ser un ID valido"], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    //consultar si es el admin
    if(!$esAdmin) {
        $response->getBody()->write(json_encode(["error" => "No es admin"], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    //admin no se pueda borrar a si mismo
    if($userId === (int) $request->getAttribute('auth_id')) {
        $response->getBody()->write(json_encode(["error" => "Un administrador no puede eliminarse a si mismo"], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
    }

    //deletes
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id FROM usuario WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        //consultar si existe el usuario
        if(!$stmt->fetch()) {
            $response->getBody()->write(json_encode(["error" => "No existe el usuario"], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        //eliminar usuario
        $db->beginTransaction();
        $stmt = $db->prepare("DELETE FROM mensaje WHERE chat_id IN (SELECT id FROM chat WHERE creado_por = :id OR usuario_id = :id)");
        $stmt->execute(['id' => $userId]);
        $stmt = $db->prepare("DELETE FROM chat WHERE creado_por = :id OR usuario_id = :id");
        $stmt->execute(['id' => $userId]);
        $stmt = $db->prepare("DELETE FROM usuario WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $db->commit();
        $response->getBody()->write(json_encode(["mensaje" => "Usuario eliminado"], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    } catch(PDOException $e) {
        if(isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        $response->getBody()->write(json_encode(["error" => "Error de conexion"], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
    }


})->add($authMiddleware);
