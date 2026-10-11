<?php
require __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/at_ai.php';
$user=require_login();csrf_check();
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Use POST.']);exit;}
try{
    $input=json_decode(file_get_contents('php://input'),true,64,JSON_THROW_ON_ERROR);
    if(!is_array($input)||!is_array($input['values']??null))throw new DomainException('Invalid draft request.');
    $result=at_ai_draft((string)($input['kind']??''),(string)($input['field']??''),$input['values'],(int)$user['id'],(string)($input['instruction']??''),(int)($input['id']??0));
    echo json_encode($result,JSON_THROW_ON_ERROR);
}catch(DomainException $e){http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
catch(Throwable $e){error_log('AT AI draft failed: '.get_class($e).' code='.$e->getCode().' at '.basename($e->getFile()).':'.$e->getLine());http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Could not generate a draft. Your form has not changed.']);}
