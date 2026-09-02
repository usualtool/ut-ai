<?php
use usualtool\Ai\Ai;
if($_SERVER['REQUEST_METHOD']==='POST'){
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    $messages = $data['messages'] ?? [];
    $model = $data['model'] ?? 'GLM-4.7-FlashX';
    $stream = $data['stream'] ?? false;
    $ai = new Ai();
    echo $ai->Chat($messages, $model, $stream);
}