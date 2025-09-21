<?php
/**
 * client.php - Cliente de chat (Arquitetura Transacional com Suporte a Arquivos)
 */

if ($argc < 2) {
    die("Uso: php client.php SUA_PORTA_DE_ESCUTA\n");
}

$listenPort = (int)$argv[1];
$serverAddr = 'tcp://127.0.0.1:8888';
$currentUser = null;

$clientServer = stream_socket_server("tcp://0.0.0.0:$listenPort", $errno, $errstr);
if (!$clientServer) die("Erro ao criar servidor de escuta: $errstr ($errno)\n");
stream_set_blocking($clientServer, false);

if (!is_dir("downloads")) mkdir("downloads");

echo "Cliente escutando na porta $listenPort. Use '/login SEU_NOME' para começar.\n";
echo "Comandos de arquivo: /sendfile USER ARQUIVO | /sendfilegroup GRUPO ARQUIVO\n";

$stdin = fopen('php://stdin', 'r');

while (true) {
    $read = [$stdin, $clientServer];
    $write = $except = null;

    if (stream_select($read, $write, $except, null) === false) break;

    // Trata conexões recebidas
    if (in_array($clientServer, $read, true)) {
        $conn = stream_socket_accept($clientServer, 0);
        if ($conn) {
            $line = fgets($conn);
            $msg = json_decode(trim($line), true);
            if ($msg) {
                // Passa a conexão para o handler poder ler os bytes do arquivo
                handleReceivedMessage($msg, $conn);
            }
            fclose($conn);
        }
    }

    // Trata entrada do usuário
    if (in_array($stdin, $read, true)) {
        $line = trim(fgets($stdin));
        if ($line === '') continue;
        handleUserInput($line);
    }
}

function handleReceivedMessage($msg, $stream) {
	global $currentUser;
	
    switch ($msg['type']) {
        case 'private':
            echo "\n" . ucfirst($msg['from']) . ": {$msg['msg']}\n";
            break;
        case 'group':
            echo "\nMensagem do grupo {$msg['group']}:\n" . ucfirst($msg['from']) . ": {$msg['msg']}\n";
            break;
        case 'file':
            echo "\nRecebendo arquivo '{$msg['name']}' de {$msg['from']}...";
            $data = readBytes($stream, $msg['size']);
            if (strlen($data) !== $msg['size']) {
                echo " ERRO AO BAIXAR.\n";
                break;
            }
			if (!is_dir("downloads/{$currentUser}")) mkdir("downloads/{$currentUser}");
            $path = "downloads/{$currentUser}/" . uniqid() . "_" . $msg['name'];
            file_put_contents($path, $data);
            echo " Salvo em $path\n";
            break;
        case 'info':
            echo "\nServidor: {$msg['msg']}\n";
            break;
    }
}

function handleUserInput($line) {
    global $currentUser, $listenPort;

    if (strpos($line, '/login') === 0) {
        [$cmd, $user] = explode(' ', $line, 2);
        $currentUser = $user;
        sendCommandToServer(["type" => "login", "username" => $currentUser, "port" => $listenPort]);
    } elseif (!$currentUser) {
        echo "Você precisa fazer login primeiro. Use /login SEU_NOME\n";
        return;
    }
    elseif (strpos($line, '/msg') === 0) {
        [$cmd, $to, $text] = explode(' ', $line, 3);
        sendCommandToServer(["type" => "private", "from" => $currentUser, "to" => $to, "message" => $text]);
    } elseif (strpos($line, '/creategroup') === 0) {
        $parts = explode(' ', $line);
        $gname = $parts[1];
        $members = array_slice($parts, 2);
        sendCommandToServer(["type" => "create_group", "creator" => $currentUser, "name" => $gname, "members" => $members]);
    } elseif (strpos($line, '/group') === 0) {
        [$cmd, $gname, $text] = explode(' ', $line, 3);
        sendCommandToServer(["type" => "group", "from" => $currentUser, "group" => $gname, "message" => $text]);
    }
    elseif (strpos($line, '/sendfilegroup') === 0) {
        [$cmd, $gname, $file] = explode(' ', $line, 3);
        if (!file_exists($file)) { echo "Arquivo não encontrado: $file\n"; return; }
        
        $data = file_get_contents($file);
        $header = [
            "type" => "file_group",
            "from" => $currentUser,
            "group" => $gname,
            "name" => basename($file),
            "size" => strlen($data)
        ];
        sendFileToServer($header, $data);
    } 
    elseif (strpos($line, '/sendfile') === 0) {
        [$cmd, $to, $file] = explode(' ', $line, 3);
        if (!file_exists($file)) { echo "Arquivo não encontrado: $file\n"; return; }
        
        $data = file_get_contents($file);
        $header = [
            "type" => "file_private",
            "from" => $currentUser,
            "to" => $to,
            "name" => basename($file),
            "size" => strlen($data)
        ];
        sendFileToServer($header, $data);
    }
	elseif (strpos($line, '/help') === 0) {
		echo "Comandos disponíveis:\n";
		echo "/login NOME - Faz login no chat com o nome especificado.\n";
		echo "/msg DESTINATARIO MENSAGEM - Envia uma mensagem privada.\n";
		echo "/creategroup NOME MEMBRO1 MEMBRO2 MEMBRO3 - Cria um novo grupo.\n";
		echo "/group NOME MENSAGEM - Envia uma mensagem para o grupo.\n";
		echo "/sendfile DESTINATARIO CAMINHO_ARQUIVO - Envia um arquivo para um usuário.\n";
		echo "/sendfilegroup NOME_GRUPO CAMINHO_ARQUIVO - Envia um arquivo para um grupo.\n";
	}
}

function sendCommandToServer($arr) {
    global $serverAddr;
    $fp = @stream_socket_client($serverAddr, $errno, $errstr, 5);
    if (!$fp) {
        echo "Erro: Não foi possível conectar ao servidor principal ($errstr)\n";
        return;
    }
    fwrite($fp, json_encode($arr) . "\n");
    $response = fgets($fp);
    if ($response) {
        $msg = json_decode(trim($response), true);
        if ($msg) handleReceivedMessage($msg, $fp);
    }
    fclose($fp);
}

function sendFileToServer($header, $data) {
    global $serverAddr;
    $fp = @stream_socket_client($serverAddr, $errno, $errstr, 10);
    if (!$fp) {
        echo "Erro: Não foi possível conectar ao servidor principal para enviar arquivo ($errstr)\n";
        return;
    }
    // Envia o cabeçalho
    fwrite($fp, json_encode($header) . "\n");
    // Envia os dados do arquivo
    fwrite($fp, $data);
    fclose($fp);
    echo "Arquivo enviado para o servidor.\n";
}

function readBytes($stream, $len) {
    $data = '';
    $remaining = $len;
    while ($remaining > 0 && !feof($stream)) {
        $chunk = fread($stream, min(8192, $remaining));
        if ($chunk === false || $chunk === '') break;
        $data .= $chunk;
        $remaining -= strlen($chunk);
    }
    return $data;
}