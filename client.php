<?php
/**
 * client.php - Cliente de chat estilo WhatsApp em PHP (CLI)
 *
 * Comandos suportados:
 * /login USER
 * /msg USER TEXTO
 * /creategroup NOME USER1 USER2 ...
 * /group NOME TEXTO
 * /sendfile USER CAMINHO_ARQUIVO
 */

$addr = 'tcp://127.0.0.1:12345';
$fp = stream_socket_client($addr, $errno, $errstr, 5);
if (!$fp) die("Erro: $errstr ($errno)\n");
stream_set_blocking($fp, false);

$stdin = fopen('php://stdin','r');
echo "Cliente conectado ao servidor. Digite comandos. (/login USER)\n";

while (true) {
    $read = [$stdin, $fp];
    $write = $except = null;
    if (stream_select($read, $write, $except, 0, 200000) === false) break;

    // leitura do servidor
    if (in_array($fp,$read,true)) {
        $line = fgets($fp);
        if ($line === false) {
            echo "Servidor desconectou.\n";
            break;
        }
        $msg = json_decode(trim($line),true);
        if ($msg) {
            if ($msg['type']==='file') {
                $data = readBytes($fp, $msg['size']);
                if (!is_dir("downloads")) mkdir("downloads");
                $path = "downloads/".uniqid()."_".$msg['name'];
                file_put_contents($path,$data);
                echo "Arquivo recebido de {$msg['from']} salvo em $path\n";
            } else {
                echo "Servidor: ".print_r($msg,true)."\n";
            }
        }
    }

    // entrada do usuário
    if (in_array($stdin,$read,true)) {
        $line = trim(fgets($stdin));
        if ($line==='') continue;
        if (strpos($line,'/login')===0) {
            [$cmd,$user] = explode(' ',$line,2);
            sendJson(["type"=>"login","username"=>$user]);
        } elseif (strpos($line,'/msg')===0) {
            [$cmd,$to,$text] = explode(' ',$line,3);
            sendJson(["type"=>"private","to"=>$to,"message"=>$text]);
        } elseif (strpos($line,'/creategroup')===0) {
            $parts = explode(' ',$line);
            $gname = $parts[1];
            $members = array_slice($parts,2);
            sendJson(["type"=>"create_group","name"=>$gname,"members"=>$members]);
        } elseif (strpos($line,'/group')===0) {
            [$cmd,$gname,$text] = explode(' ',$line,3);
            sendJson(["type"=>"group","group"=>$gname,"message"=>$text]);
        } elseif (strpos($line,'/sendfile')===0) {
            [$cmd,$to,$file] = explode(' ',$line,3);
            if (!file_exists($file)) { echo "Arquivo não encontrado.\n"; continue; }
            $data = file_get_contents($file);
            $header = ["type"=>"file","to"=>$to,"name"=>basename($file),"size"=>strlen($data)];
            sendJson($header);
            fwrite($fp,$data);
        }
    }
}

function sendJson($arr) {
    global $fp;
    fwrite($fp,json_encode($arr)."\n");
}

function readBytes($stream, $len) {
    $data = '';
    $remaining = $len;
    while ($remaining>0) {
        $chunk = fread($stream,min(8192,$remaining));
        if ($chunk===false||$chunk==='') break;
        $data .= $chunk;
        $remaining -= strlen($chunk);
    }
    return $data;
}