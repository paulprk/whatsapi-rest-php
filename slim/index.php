<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/vendor/autoload.php';   //carga slim
//require __DIR__ . '/db.php';


$app = AppFactory::create();        //crea la app
$app->addRoutingMiddleware();
$app->addBodyParsingMiddleware();   //hace que el JSON que manda el cliente llegue como array en $request->GetParsedBody();
// false = no mostrar detalles internos (archivo, linea, trace) en los errores
$errorMiddleware = $app->addErrorMiddleware(false, true, true);
// los errores de Slim (404 de ruta, 405 de metodo) tambien salen en JSON
$errorMiddleware->getDefaultErrorHandler()->forceContentType('application/json');
$app->add( function ($request, $handler) {
    $response = $handler->handle($request);

    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')    //agrega headers a todas las respuestas
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'OPTIONS, GET, POST, PUT, PATCH, DELETE')
        ->withHeader('Access-Control-Expose-Headers', 'Authorization')   //permite que el navegador (React) lea el token del login
        ->withHeader('Content-Type', 'application/json')
    ;
});

// carga endpoints
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/chats.php';
require_once __DIR__ . '/mensajes.php';

$app->run();