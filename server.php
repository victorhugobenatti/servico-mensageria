<?php
/**
 * server.php - Servidor de chat (Arquitetura Transacional com Suporte a Arquivos)
 */

set_time_limit(0);
$addr = 'tcp://0.0.0.0:8888';
$server = stream_socket_server($addr, $errno, $errstr);
if (!$server) die("Erro ao iniciar servidor: $errstr ($errno)\n");

if (!is_dir("uploads")) mkdir("uploads");

$db = new PDO('sqlite:chat.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
initDb($db);

echo "Servidor ouvindo em $addr...\n";

while (true) {
    $connection = @stream_socket_accept($server, -1);
    if (!$connection) continue;
    
    $peer = stream_socket_get_name($connection, true);
    $clientIp = parse_url("tcp://$peer", PHP_URL_HOST);

    $line = fgets($connection);
    if ($line) {
        $msg = json_decode(trim($line), true);
        if ($msg) {
            // Passamos a conexão para o handler, para que ele possa ler os bytes do arquivo se necessário
            handleMessage($msg, $clientIp, $connection);
        }
    }
    @fclose($connection);
}

// ---------------- Funções ----------------

function handleMessage($msg, $clientIp, $connection) {
    global $db;

    switch ($msg['type']) {
        case 'login':
            $username = $msg['username'];
			$port = $msg['port'];

			// 1. Verifica se o usuário já existe
			$stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
			$stmt->execute([$username]);
			$existingUser = $stmt->fetch();

			if ($existingUser) {
				// 2. Se existe, apenas atualiza o IP e a Porta (preserva o ID)
				$stmt = $db->prepare("UPDATE users SET ip = ?, port = ? WHERE username = ?");
				$stmt->execute([$clientIp, $port, $username]);
				echo "Usuário $username atualizado com IP $clientIp e Porta $port.\n";
			} else {
				// 3. Se não existe, insere um novo usuário
				$stmt = $db->prepare("INSERT INTO users (username, ip, port) VALUES (?, ?, ?)");
				$stmt->execute([$username, $clientIp, $port]);
				echo "Usuário $username registrado com IP $clientIp e Porta $port.\n";
			}
			
			fwrite($connection, json_encode(["type"=>"info", "msg"=>"Login/Registro bem-sucedido."]) . "\n");
			break;

        case 'private':
            $from = $msg['from'];
            $to = $msg['to'];
            $text = $msg['message'];
            $db->prepare("INSERT INTO messages (sender,receiver,type,content) VALUES (?,?,?,?)")->execute([$from,$to,'text',$text]);
            
            $recipient = getUserAddress($to);
            if ($recipient) {
                $payload = ["type"=>"private", "from"=>$from, "msg"=>$text];
                sendMessageToClient($recipient['ip'], $recipient['port'], $payload);
            }
            break;

        case 'group':
            // ... (lógica inalterada)
            $from = $msg['from'];
            $gname = $msg['group'];
            $text = $msg['message'];
            $db->prepare("INSERT INTO messages (sender,groupname,type,content) VALUES (?,?,?,?)")->execute([$from,$gname,'text',$text]);

            $stmt = $db->prepare("SELECT u.username FROM group_members gm JOIN groups g ON gm.group_id=g.id JOIN users u ON gm.user_id=u.id WHERE g.name=? AND u.username != ?");
            $stmt->execute([$gname, $from]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $recipient = getUserAddress($row['username']);
                if ($recipient) {
                    $payload = ["type"=>"group", "from"=>$from, "group"=>$gname, "msg"=>$text];
                    sendMessageToClient($recipient['ip'], $recipient['port'], $payload);
                }
            }
            break;

        case 'create_group':
            // ... (lógica inalterada)
            $gname = $msg['name'];
            $members = $msg['members'];
            $creator = $msg['creator'];
            
            if (!in_array($creator, $members)) $members[] = $creator;

            $db->prepare("INSERT OR IGNORE INTO groups (name) VALUES (?)")->execute([$gname]);
            $stmt_gid = $db->prepare("SELECT id FROM groups WHERE name = ?");
            $stmt_gid->execute([$gname]);
            $gid = $stmt_gid->fetchColumn();

            foreach ($members as $m) {
                $uid = getUserId($m);
                if ($uid) {
                    $db->prepare("INSERT OR IGNORE INTO group_members (group_id,user_id) VALUES (?,?)")->execute([$gid,$uid]);
                }
            }
            fwrite($connection, json_encode(["type"=>"info","msg"=>"Grupo '$gname' criado com sucesso."]) . "\n");
            break;

        case 'file_private':
        case 'file_group':
            $from = $msg['from'];
            $fname = $msg['name'];
            $fsize = $msg['size'];
            
            echo "Recebendo arquivo '$fname' ($fsize bytes) de $from...\n";
            $data = readBytes($connection, $fsize);
            
            if (strlen($data) !== $fsize) {
                echo "Erro: falha ao receber o arquivo completo.\n";
                break;
            }
			if (!is_dir("uploads/{$from}")) mkdir("uploads/{$from}");
            $path = "uploads/{$from}/".uniqid()."_".$fname;
            file_put_contents($path, $data);
            echo "Arquivo salvo em $path.\n";
            
            $payload = ["type"=>"file", "from"=>$from, "name"=>$fname, "size"=>$fsize];

            if ($msg['type'] === 'file_private') {
                $to = $msg['to'];
                $db->prepare("INSERT INTO messages (sender,receiver,type,filename,content) VALUES (?,?,?,?,?)")->execute([$from,$to,'file',$fname,$path]);
                $recipient = getUserAddress($to);
                if ($recipient) {
                    echo "Encaminhando para $to...\n";
                    $payload['context'] = $to; // Privado
                    sendFileToClient($recipient['ip'], $recipient['port'], $payload, $path);
                }
            } else { // file_group
                $gname = $msg['group'];
                $db->prepare("INSERT INTO messages (sender,groupname,type,filename,content) VALUES (?,?,?,?,?)")->execute([$from,$gname,'file',$fname,$path]);
                $payload['context'] = $gname; // Grupo
                $stmt = $db->prepare("SELECT u.username FROM group_members gm JOIN groups g ON gm.group_id=g.id JOIN users u ON gm.user_id=u.id WHERE g.name=? AND u.username != ?");
                $stmt->execute([$gname, $from]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $recipient = getUserAddress($row['username']);
                    if ($recipient) {
                        echo "Encaminhando para {$row['username']} do grupo $gname...\n";
                        sendFileToClient($recipient['ip'], $recipient['port'], $payload, $path);
                    }
                }
            }
            break;
    }
}

function sendMessageToClient($ip, $port, $payload) {
    $clientAddr = "tcp://$ip:$port";
    $fp = @stream_socket_client($clientAddr, $errno, $errstr, 5);
    if ($fp) {
        fwrite($fp, json_encode($payload) . "\n");
        fclose($fp);
    } else {
        echo "Falha ao conectar no cliente $ip:$port - $errstr ($errno)\n";
    }
}

function sendFileToClient($ip, $port, $payload, $filePath) {
    $clientAddr = "tcp://$ip:$port";
    $fp = @stream_socket_client($clientAddr, $errno, $errstr, 10);
    if ($fp) {
        // Envia o cabeçalho
        fwrite($fp, json_encode($payload) . "\n");
        // Envia o conteúdo do arquivo
        $fileStream = fopen($filePath, 'r');
        stream_copy_to_stream($fileStream, $fp);
        fclose($fileStream);
        fclose($fp);
    } else {
        echo "Falha ao enviar arquivo para $ip:$port - $errstr ($errno)\n";
    }
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

// ... (funções getUserAddress, getUserId, initDb inalteradas)
function getUserAddress($username) {
    global $db;
    $stmt = $db->prepare("SELECT ip, port FROM users WHERE username = ?");
    $stmt->execute([$username]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getUserId($username) {
    global $db;
    $stmt = $db->prepare("SELECT id FROM users WHERE username=?");
    $stmt->execute([$username]);
    $r = $stmt->fetch();
    return $r ? $r['id'] : null;
}

function initDb($db) {
	$schema = file_get_contents('schema.sql');
    $db->exec($schema);
}