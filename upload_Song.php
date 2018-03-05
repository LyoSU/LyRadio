#!/usr/bin/env php
<?php
set_include_path(get_include_path().':'.realpath(dirname(__FILE__).'/MadelineProto/'));
require_once 'vendor/autoload.php';
$MadelineProto = new \danog\MadelineProto\API('session.madeline');
$MadelineProto->session = 'session.madeline';
$dotenv = new Dotenv\Dotenv(getcwd());
$dotenv->load();

function getDurationSeconds($file){
    $dur = shell_exec("ffmpeg -i ".$file." 2>&1");
    if(preg_match("/: Invalid /", $dur)){
        return false;
    }
    preg_match("/Duration: (.{2}):(.{2}):(.{2})/", $dur, $duration);
    if(!isset($duration[1])){
        return false;
    }
    $hours = $duration[1];
    $minutes = $duration[2];
    $seconds = $duration[3];
    return $seconds + ($minutes*60) + ($hours*60*60);
}

$data = json_decode( base64_decode($argv[1]) ,true);

$mp3 = 'music/'.$data['file_id'].'.mp3';

if( $data['user'] !== 318027185 ) $messages_id = $MadelineProto->messages->sendMessage(['peer' => $data['user'], 'message' => "Загружаю...", 'parse_mode' => 'Markdown'])['id'];
else $messages_id = 0;

$songs = json_decode( file_get_contents("queue.json"), true);
$u = 0;
$i = 0;
foreach ($songs as &$v){
    $i++;
    if( $v['user'] == $data['user'] && $i !== 1 ) $u = 1;
}

if( $u == 0 ){
    $songs[$data['file_id']] = [ 'user' => $data['user'], 'name' => $data['name'], 'duration' => 0 ];
    file_put_contents("queue.json", json_encode($songs, JSON_PRETTY_PRINT));
    $MadelineProto->download_to_file($data['file_id'], $mp3);
    $duration = getDurationSeconds($mp3);
    if( $duration < 420 ){
        $songs = json_decode( file_get_contents("queue.json"), true);
        $songs[$data['file_id']] = [ 'user' => $data['user'], 'name' => $data['name'], 'duration' => $duration ];
        file_put_contents("queue.json", json_encode($songs, JSON_PRETTY_PRINT));
        $message = "Твоя песня добавлена в очередь";
    }else{
        unset($songs[$data['file_id']]);
        file_put_contents("queue.json", json_encode($songs, JSON_PRETTY_PRINT));
        $message = "Песня должна быть коротче 7 минут";
    }
}else{
    $message = "Может быть только 1 твоя песня в очереди";
}

$MadelineProto->messages->editMessage(['peer' => $data['user'], 'id' => $messages_id, 'message' => $message, 'parse_mode' => 'Markdown'])['id'];