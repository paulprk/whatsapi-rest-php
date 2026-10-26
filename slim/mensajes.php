<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** @var \Closure $authMiddleware */
/** @var \Slim\App $app */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/validaciones.php';

function responder(Response $response, array $datos, int $status = 200): Response
{
    $response->getBody()->write(json_encode($datos, JSON_UNESCAPED_UNICODE));
    return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
}

$app->delete('/mensajes/{message_id}', function(Request $request, Response $response, array $args) {
    $messageId = parsearId($args['message_id']);

    if($messageId === null) {
        return responder($response, ['message_id' => 'Debe ser un ID valido'], 400);
    }

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, enviado_por FROM mensaje WHERE id = :id");
        $stmt->execute(['id' => $messageId]);
        $mensaje = $stmt->fetch();

        //mensaje no encontrado => error 404
        if(!$mensaje) {
            return responder($response, ['error' => 'Mensaje no encontrado'], 404);
        }

        //obtener atributos del request
        $esAdmin = (int) $request->getAttribute('auth_admin') === 1;
        $logueado = (int) $request->getAttribute('auth_id');

        //si no es admin, y no es el usuario logueado error 401
        if(!$esAdmin && (int) $mensaje['enviado_por'] !== $logueado) {
            return responder($response, ['error' => 'Solo puede eliminar sus propios mensajes'], 401);
        }

        //eliminar mensaje => 200
        $stmt = $db->prepare("DELETE FROM mensaje WHERE id = :id");
        $stmt->execute(['id' => $messageId]);

        return responder($response, ['mensaje' => 'Mensaje eliminado'], 200);

    } catch (PDOException $e) {
        return responder($response, ['error' => 'No se pudo eliminar el mensaje'], 409);
    }


})->add($authMiddleware);
