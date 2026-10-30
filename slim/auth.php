<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** @var \Slim\App $app */
/** @var \Closure $authMiddleware */
require_once __DIR__ . '/db.php';
require_once __DIR__ . "/validaciones.php";

//recibe username y password, genera un token de 5 min de expiracion
$app->post('/login',function (Request $request,Response $response) {
    $data = $request->getParsedBody() ?? [];

    $errores = validarLogin($data);
    if(!empty($errores)) {
        $response->getBody()->write(json_encode($errores, JSON_UNESCAPED_UNICODE));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, username, nombre, password, is_admin FROM usuario WHERE username = :username");
        $stmt->execute(['username' => $data['username']]);
        $usuario = $stmt->fetch();
        if(!$usuario || !password_verify($data['password'], $usuario['password'])) {
            $response->getBody()->write(json_encode(["username" => "Credenciales invalidas", "password" => "Credenciales invalidas"], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $token = bin2hex(random_bytes(16));
        $vence = date('Y-m-d H:i:s', strtotime('+5 minutes'));
        $stmt = $db->prepare("UPDATE usuario SET token = :token, token_expired_at = :vence WHERE id = :id");
        $stmt->execute([
            'token' => $token,
            'vence' => $vence,
            'id' => $usuario['id']
        ]);

        //el token va solo en el header; en el cuerpo van los datos del usuario logueado (sin password)
        $response->getBody()->write(json_encode([
            "mensaje" => "Login exitoso",
            "usuario" => [
                "id" => (int) $usuario['id'],
                "username" => $usuario['username'],
                "nombre" => $usuario['nombre'],
                "is_admin" => (int) $usuario['is_admin'],
            ],
        ], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json')->withHeader('Authorization', 'Bearer ' . $token);

    } catch(PDOException $e) {
        $response->getBody()->write(json_encode(["base_de_datos" => "Error de conexion"], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
    }
});

$app->post('/logout', function(Request $request,Response $response) {
    $userId = $request->getAttribute('auth_id');
    try {
        $db = getDB();
        $stmt = $db->prepare("UPDATE usuario SET token = NULL, token_expired_at = NULL WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $response->getBody()->write(json_encode(["mensaje" => "Sesion cerrada"], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');

    } catch(PDOException $e) {
        $response->getBody()->write(json_encode(["base_de_datos" => "Error de conexion"], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
    }
})->add($authMiddleware);

