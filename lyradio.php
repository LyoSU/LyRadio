#!/usr/bin/env php
<?php
set_include_path(get_include_path().':'.realpath(dirname(__FILE__).'/MadelineProto/'));

require_once 'vendor/autoload.php';
if (file_exists('web_data.php')) {
    require_once 'web_data.php';
}

echo 'Deserializing MadelineProto from session.madeline...'.PHP_EOL;
$MadelineProto = false;

try {
    $MadelineProto = new \danog\MadelineProto\API('session.madeline');
} catch (\danog\MadelineProto\Exception $e) {
    var_dump($e->getMessage());
}

if (file_exists('.env')) {
    echo 'Loading .env...'.PHP_EOL;
    $dotenv = new Dotenv\Dotenv(getcwd());
    $dotenv->load();
}
echo 'Loading settings...'.PHP_EOL;
$settings = json_decode(getenv('MTPROTO_SETTINGS'), true) ?: [];

if ($MadelineProto === false) {
    echo 'Loading MadelineProto...'.PHP_EOL;
    $MadelineProto = new \danog\MadelineProto\API($settings);
    if (getenv('TRAVIS_COMMIT') == '') {
        $sentCode = $MadelineProto->phone_login(readline('Enter your phone number: '));
        \danog\MadelineProto\Logger::log([$sentCode], \danog\MadelineProto\Logger::NOTICE);
        echo 'Enter the code you received: ';
        $code = fgets(STDIN, (isset($sentCode['type']['length']) ? $sentCode['type']['length'] : 5) + 1);
        $authorization = $MadelineProto->complete_phone_login($code);
        \danog\MadelineProto\Logger::log([$authorization], \danog\MadelineProto\Logger::NOTICE);
        if ($authorization['_'] === 'account.noPassword') {
            throw new \danog\MadelineProto\Exception('2FA is enabled but no password is set!');
        }
        if ($authorization['_'] === 'account.password') {
            \danog\MadelineProto\Logger::log(['2FA is enabled'], \danog\MadelineProto\Logger::NOTICE);
            $authorization = $MadelineProto->complete_2fa_login(readline('Please enter your password (hint '.$authorization['hint'].'): '));
        }
        if ($authorization['_'] === 'account.needSignup') {
            \danog\MadelineProto\Logger::log(['Registering new user'], \danog\MadelineProto\Logger::NOTICE);
            $authorization = $MadelineProto->complete_signup(readline('Please enter your first name: '), readline('Please enter your last name (can be empty): '));
        }

        echo 'Serializing MadelineProto to session.madeline...'.PHP_EOL;
        echo 'Wrote '.\danog\MadelineProto\Serialization::serialize('session.madeline', $MadelineProto).' bytes'.PHP_EOL;
    } else {
        $MadelineProto->bot_login(getenv('BOT_TOKEN'));
    }
}
\danog\MadelineProto\Logger::log(['hey'], \danog\MadelineProto\Logger::ULTRA_VERBOSE);
\danog\MadelineProto\Logger::log(['hey'], \danog\MadelineProto\Logger::VERBOSE);
\danog\MadelineProto\Logger::log(['hey'], \danog\MadelineProto\Logger::NOTICE);
\danog\MadelineProto\Logger::log(['hey'], \danog\MadelineProto\Logger::WARNING);
\danog\MadelineProto\Logger::log(['hey'], \danog\MadelineProto\Logger::ERROR);
\danog\MadelineProto\Logger::log(['hey'], \danog\MadelineProto\Logger::FATAL_ERROR);

$message = (getenv('TRAVIS_COMMIT') == '') ? 'I iz works always (io laborare sembre) (yo lavorar siempre) (mi labori ĉiam) (я всегда работать) (Ik werkuh altijd) (Ngimbonga ngaso sonke isikhathi ukusebenza)' : ('Travis ci tests in progress: commit '.getenv('TRAVIS_COMMIT').', job '.getenv('TRAVIS_JOB_NUMBER').', PHP version: '.getenv('TRAVIS_PHP_VERSION'));
$MadelineProto->session = 'session.madeline';

    $MadelineProto->serialize();
    $times = [];
    $calls = [];
    $users = [];
    $songs = [];
    $skip = [];
    $start = 0;
    $gone = 0;
    $popular = 0;

    $lastUpdate = $MadelineProto->API->get_updates();

    //file_put_contents("log.txt", json_encode($lastUpdate,JSON_PRETTY_PRINT), FILE_APPEND | LOCK_EX);
    
    $offset = end($lastUpdate)['update_id']+1;

    while (1) {
        $updates = $MadelineProto->API->get_updates(['offset' => $offset, 'limit' => 5000, 'timeout' => 0]); // Just like in the bot API, you can specify an offset, a limit and a timeout
        $songs = json_decode( file_get_contents("queue.json"), true);
        if( !isset($song) OR count($songs) == 0 ){
            $song = 'music/0.raw';
        }

        if( count($songs) <= 1 && $popular+60 < time() ){
            $popular = time();
            $messages = $MadelineProto->messages->sendMessage(['peer' => "@vkm4bot", 'message' => "/popular", 'parse_mode' => 'Markdown']);
        }

        foreach ($songs as $key => $value) if( $value['duration'] > 0 ){
            $raw = "music/$key.raw";
            $mp3 = "music/$key.mp3";
            if( $raw != $song ) $start = time();
            if( count($skip) > (count($calls)*50/100) OR ($start+$value['duration']-3) < time() ){
                $gone = time();
                unset($songs[$key]);
                file_put_contents("queue.json", json_encode($songs, JSON_PRETTY_PRINT));
                if( file_exists($raw) ) unlink($raw);
                if( file_exists($mp3) ) unlink($mp3);
                $skip = [];
            }else{
                if( ($gone+1) < time() ){
                    $gone = time();
                    if( file_exists($raw) ) unlink($raw);
                    exec("ffmpeg -ss ".( $gone-$start )." -i $mp3 -f s16le -ac 1 -ar 48000 -acodec pcm_s16le music/$key.raw");
                    $song = $raw;
                }else{
                    $song = $raw;
                }
                break;
            }
        }

        foreach ($calls as $key => $call) {
            
            if( (!isset($call->storage["song"]) OR $call->storage["song"] != $song) && file_exists($song) ){
                $call->storage["song"] = $song;
                $call->play($song);
            }

            if ($call->getCallState() === \danog\MadelineProto\VoIP::CALL_STATE_ENDED) {
                unset($calls[$key]);
            } elseif (isset($times[$call->getOtherID()]) && $times[$call->getOtherID()][0] < time()) {
                $times[$call->getOtherID()][0] += 10;
                try {
                    if(count($songs) > 0){
                        $i = 0;
                        $list = "\n<b>Очередь:</b>";
                        foreach ($songs as $key => $value) if( isset( $value['duration'] ) ){
                            $i++;
                            $list .= "\n<b>".$i.".</b> <code>".$value['name']."</code>";
                            if($i == 1) $end = "<b>До конца текущей песни:</b> ".($start+$value['duration']-time())."с.";
                        }
                        $list .= "\n".$end;
                    }else{
                        $list = '';
                    }
                    //\n<b>Пропуск песни:</b> ".count($skip)." (<code>/skip</code>)
                    $message = "<b>Слушателей:</b> ".count($calls)."\n$list\n\nДля заказа песни, просто оправь мне её";

                    $MadelineProto->messages->editMessage(['id' => $times[$call->getOtherID()][1], 'peer' => $call->getOtherID(), 'message' => $message, 'parse_mode' => 'HTML' ]);
                } catch (\danog\MadelineProto\RPCErrorException $e) {
                    echo $e;
                }
            }
        }
        
        foreach ($updates as $update) {
            \danog\MadelineProto\Logger::log([$update]);
            $offset = $update['update_id'] + 1; // Just like in the bot API, the offset must be set to the last update_id
            switch ($update['update']['_']) {
                case 'updateNewMessage':
                    if ($update['update']['message']['out'] || $update['update']['message']['to_id']['_'] !== 'peerUser' || !isset($update['update']['message']['from_id'])) {
                        continue;
                    }

                    if (isset($update['update']['message']['message']) && $update['update']['message']['message'] === '/1skip') {
                        $skip[$update['update']['message']['from_id']] = 1;
                        $MadelineProto->messages->sendMessage(['peer' => $update['update']['message']['from_id'], 'message' => "Ты проголосовал за пропуск песни", 'parse_mode' => 'Markdown']);
                    }elseif (isset($update['update']['message']['media']) && $update['update']['message']['media']['_'] == 'messageMediaDocument' && in_array($update['update']['message']['media']['document']['mime_type'], ['audio/mpeg', 'audio/mp3']) ) {
                        if( $update['update']['message']['media']['document']['size'] < 20971520){
                            if( isset($update['update']['message']['media']['document']['attributes'][0]['title']) && isset($update['update']['message']['media']['document']['attributes'][0]['performer']) ) $name = $update['update']['message']['media']['document']['attributes'][0]['title'] . ' — ' . $update['update']['message']['media']['document']['attributes'][0]['performer'];
                            else $name = "без названия";
                            $file = $MadelineProto->API->MTProto_to_botAPI($update['update']['message']['media'])['audio']['file_id'];
                            $data = base64_encode(json_encode([ 'file_id' => $file, 'name' => htmlspecialchars($name), 'user' => $update['update']['message']['from_id'] ]));
                            //echo $data;exit;
                            exec("php upload_Song.php ".$data." > /dev/null 2>&1 &");
                        }else{
                            $MadelineProto->messages->sendMessage(['peer' => $update['update']['message']['from_id'], 'message' => "Песня должна быть меньше 20 мб", 'parse_mode' => 'Markdown']);
                        }
                    }elseif (isset($update['update']['message']['message']) && $update['update']['message']['from_id'] !== 318027185 ) {
                        $users[$update['update']['message']['from_id']] = true;
                        $MadelineProto->messages->sendMessage(['peer' => $update['update']['message']['from_id'], 'message' => "Привет✌️\nЯ радио бот в телеграме.\nПозвони мне.\n\n<b>Разработчик:</b> @LyoSU\nОсновано на @MadelineProto", 'parse_mode' => 'HTML']);
                    }
                    break;
                case 'updatePhoneCall':

                    if (is_object($update['update']['phone_call']) && isset($update['update']['phone_call']->madeline) && $update['update']['phone_call']->getCallState() === \danog\MadelineProto\VoIP::CALL_STATE_INCOMING) {
                        $update['update']['phone_call']->configuration['enable_NS'] = false;
                        $update['update']['phone_call']->configuration['enable_AGC'] = false;
                        $update['update']['phone_call']->configuration['enable_AEC'] = false;
                        $update['update']['phone_call']->configuration['shared_config'] = [
                            'audio_init_bitrate' => 70 * 1000,
                            'audio_max_bitrate'  => 100 * 1000,
                            'audio_min_bitrate'  => 15 * 1000,
                            //'audio_bitrate_step_decr' => 0,
                            //'audio_bitrate_step_incr' => 2000,
                        ];
                        $update['update']['phone_call']->parseConfig();
                        if ($update['update']['phone_call']->accept() === false) {
                            echo 'DID NOT ACCEPT A CALL';
                        }
                        $calls[$update['update']['phone_call']->getOtherID()] = $update['update']['phone_call'];

                        try {
                            $times[$update['update']['phone_call']->getOtherID()] = [time(), $MadelineProto->messages->sendMessage(['peer' => $update['update']['phone_call']->getOtherID(), 'message' => 'Слушателей: '.count($calls).PHP_EOL.PHP_EOL])['id']];
                        } catch (\danog\MadelineProto\RPCErrorException $e) {
                        }
                        if( file_exists($song) ){
                            $update['update']['phone_call']->storage["song"] = $song;
                            $update['update']['phone_call']->play($song)->playOnHold(["music/1.raw"]);
                        }
                    }
                    break;
           }
        }
    }
