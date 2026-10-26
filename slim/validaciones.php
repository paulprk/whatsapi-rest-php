<?php

function validarUsername($u)
{
    if(!is_string($u)) {
        return "Debe ser un texto";
    }
    if(strlen($u) < 5 || strlen($u) > 12) {
        return "Debe tener entre 5 y 12 caracteres";
    }

    if(substr($u, 0, 1) !== '@') {
        return "Debe empezar con @";
    }

    if(!preg_match("/^[a-zA-Z0-9]+$/",substr($u,1))) {
        return "Solo letras y numeros";
    }

    return null;
}

function validarPassword($p)
{
    if(!is_string($p)) {
        return "Debe ser un texto";
    }
    if(mb_strlen($p) < 8 || mb_strlen($p) > 15) {
        return "Debe contener entre 8 y 15 caracteres";
    }
    if(!preg_match("/[a-z]/",$p)) {
        return "Al menos 1 minuscula";
    }
    if(!preg_match("/[A-Z]/",$p)) {
        return "Al menos 1 mayuscula";
    }
    if(!preg_match("/\d/",$p)) {
        return "Al menos 1 numero";
    }
    if(!preg_match("/[^a-zA-Z0-9]/",$p)) {
        return "Al menos 1 caracter especial";
    }
    return null;
}

function validarNombre($n)
{
    if(!is_string($n)) {
        return "Debe ser un texto";
    }
    if(trim($n) === '') {
        return "El nombre no puede estar vacio";
    }
    if(mb_strlen($n) < 2 || mb_strlen($n) > 50) {
        return "Debe contener entre 2 y 50 caracteres";
    }
    if(!preg_match("/^[\p{L} ]+$/u",$n)) {
        return "Solo letras y espacios";
    }
    return null;
}

function validarRegistro($datos)
{
    $errores = [];
    $error = validarUsername($datos['username'] ?? '');
    if($error !== null) {
        $errores['username'] = $error;
    }

    $error = validarPassword($datos['password'] ?? '');
    if($error !== null) {
        $errores['password'] = $error;
    }

    $error = validarNombre($datos['nombre'] ?? '');
    if($error !== null) {
        $errores['nombre'] = $error;
    }
    return $errores;
}

function validarLogin($data)
{
    $errores = [];
    $u = $data['username'] ?? '';
    $p = $data['password'] ?? '';
    if (!is_string($u) || trim($u) === '') {
        $errores['username'] = 'El username es obligatorio y debe ser texto';
    }
    if (!is_string($p) || trim($p) === '') {
        $errores['password'] = 'La contraseña es obligatoria y debe ser texto';
    }
    return $errores;
}

function puedeAccederAUsurio($userId,$request) 
{
    $logueado = (int) $request->getAttribute('auth_id');
    $esAdmin = (int) $request->getAttribute('auth_admin') === 1;
    return $userId === $logueado || $esAdmin;
}

function parsearId($valor): ?int
{
    // Solo dígitos, sin ceros a la izquierda, sin espacios ni signo.
    if (!is_int($valor) && !(is_string($valor) && preg_match('/^[1-9][0-9]*$/', $valor))) {
        return null;
    }
    $id = filter_var($valor, FILTER_VALIDATE_INT);   // sigue protegiendo contra desborde
    return $id === false ? null : $id;
}

/**
 * Texto de un mensaje de chat: de 1 a 255 caracteres, y no solo espacios.
 */
function validarTextoMensaje($mensaje): ?string
{
    if (!is_string($mensaje) || trim($mensaje) === '' || mb_strlen($mensaje) > 255) {
        return 'Debe ser un texto de 1 a 255 caracteres';
    }
    return null;
}

/**
 * Nombre de un chat: letras y números, de 3 a 15 caracteres (con espacios).
 */
function validarNombreChat($nombre): ?string
{
    if (!is_string($nombre) || trim($nombre) === '' 
    || mb_strlen($nombre) < 3 || mb_strlen($nombre) > 15
    || !preg_match('/^[\p{L}\p{N} ]+$/u', $nombre)) {
        return 'Debe contener entre 3 y 15 letras, numeros o espacios';
    }
    return null;
}

/**
 * Descripción de un chat: letras y números, de 20 a 40 caracteres (con espacios).
 */
function validarDescripcionChat($descripcion): ?string
{
    if (!is_string($descripcion) || trim($descripcion) === ''
        || mb_strlen($descripcion) < 20 || mb_strlen($descripcion) > 40
        || !preg_match('/^[\p{L}\p{N} ]+$/u', $descripcion)) {
        return 'Debe contener entre 20 y 40 letras, numeros o espacios';
    }
    return null;
}

/**
 * Color en hexadecimal de seis dígitos, por ejemplo #FF8800.
 */
function validarColor($color): ?string
{
    if (!is_string($color) || !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        return 'Debe ser un código hexadecimal de seis dígitos, por ejemplo #FF8800';
    }
    return null;
}

/**
 * Convierte un "sí/no" que puede llegar de muchas formas a 1 o 0.
 * Acepta true/false, 0/1, "0"/"1" y "true"/"false". Devuelve 1, 0 o null si no es válido.
 * Sirve para esta_bloqueado (chats) y también serviría para es_publico (usuarios).
 */
function normalizarBooleano($valor): ?int
{
    if (is_bool($valor)) {
        return $valor ? 1 : 0;
    }
    if (in_array($valor, [0, 1, '0', '1'], true)) {
        return (int) $valor;
    }
    if (is_string($valor) && in_array(strtolower($valor), ['true', 'false'], true)) {
        return strtolower($valor) === 'true' ? 1 : 0;
    }
    return null;
}

/**
 * Entero (que llega como texto) dentro de un rango. Pasa $max = null si no tiene tope.
 *   validarEnteroEnRango("5", 1, 10)  → null (ok)
 *   validarEnteroEnRango("0", 0)      → null (ok: sirve para offset)
 */
function validarEnteroEnRango($valor, int $min, ?int $max = null): ?string
{
    $mensaje = $max === null
        ? "Debe ser un entero mayor o igual a $min"
        : "Debe ser un número entre $min y $max";

    if (!is_scalar($valor) || !preg_match('/^\d+$/', (string) $valor)) {
        return $mensaje;
    }
    $n = (int) $valor;
    if ($n < $min || ($max !== null && $n > $max)) {
        return $mensaje;
    }
    return null;
}
