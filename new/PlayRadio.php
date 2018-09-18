<?
$file = $argv[1].".raw";
exec("mkfifo $file");
exec("ffmpeg -itsoffset 1 -i http://air.radiorecord.ru:805/rr_64 -f s16le -ac 1 -ar 48000 -acodec pcm_s16le pipe:1 > $file &");
unlink($file);