<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

require_once __DIR__ . '/db.php';

function buscarUsuarioPorToken($token)
{
    $db = getDB();
    $stmt = $db->prepare("SELECT id, is_admin, token_expired_at FROM usuario WHERE token = :token");
    $stmt->execute(['token' => $token]);
    $usuario = $stmt->fetch();

    if (!$usuario) {    //no existe
        return null;
    }

    if (strtotime($usuario['token_expired_at']) < time()) { //vencio
        return null;
    }

    $vence = date('Y-m-d H:i:s', strtotime('+5 minutes'));
    $stmt = $db->prepare("UPDATE usuario SET token_expired_at = :vence WHERE id = :id");
    $stmt->execute([
        'vence' => $vence,
        'id' => $usuario['id']
    ]);

    return $usuario;        //valido y renovado
}

// Token OBLIGATORIO: sin token, o con token inválido/vencido, responde 401.
// Si es válido: renueva 5 min y deja auth_id y auth_admin en el request.
$authMiddleware = function (Request $request, RequestHandler $handler): Response {
    $authHeader = $request->getHeaderLine('Authorization');
    $respuesta = new \Slim\Psr7\Response();

    if($authHeader === '') {
        $respuesta->getBody()->write(json_encode(["token" => "Token requerido"], JSON_UNESCAPED_UNICODE));
        return $respuesta->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    $token = trim(str_replace('Bearer', '', $authHeader));

    try {
        $usuario = buscarUsuarioPorToken($token);   //llama a para buscar el usuario por su token
    } catch(PDOException $e) {
        $respuesta->getBody()->write(json_encode(["base_de_datos" => "Error de conexion"], JSON_UNESCAPED_UNICODE));
        return $respuesta->withStatus(409)->withHeader('Content-Type', 'application/json');
    }

    if($usuario === null) {
        $respuesta->getBody()->write(json_encode(["token" => "Token invalido o expirado"], JSON_UNESCAPED_UNICODE));
        return $respuesta->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    $request = $request->withAttribute('auth_id', $usuario['id']);
    $request = $request->withAttribute('auth_admin', $usuario['is_admin']);
    return $handler->handle($request);   // deja pasar al endpoint
};

// Token OPCIONAL: sin token, o con token inválido/vencido, deja pasar como anónimo (sin auth_id).
// Si es válido: renueva 5 min y deja auth_id y auth_admin en el request.
$authOpcional = function (Request $request, RequestHandler $handler): Response {
    $authHeader = $request->getHeaderLine('Authorization');

    if($authHeader === '') {        //si token esta vacio, retornar
        return $handler->handle($request);  //anonimo, sin tocar la base
    }

    $token = trim(str_replace('Bearer', '', $authHeader));

    try {
        $usuario = buscarUsuarioPorToken($token);
    } catch (PDOException $e) {
        $respuesta = new \Slim\Psr7\Response();
        $respuesta->getBody()->write(json_encode(["base_de_datos" => "Error de conexion"], JSON_UNESCAPED_UNICODE));
        return $respuesta->withStatus(409)->withHeader('Content-Type', 'application/json');
    }

    if($usuario !== null) {
        $request = $request->withAttribute('auth_id', $usuario['id']);
        $request = $request->withAttribute('auth_admin', $usuario['is_admin']);
    }

    return $handler->handle($request);
};
