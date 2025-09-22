<?php
/**
 * server.php - Servidor de chat (Arquitetura Transacional com Entrega Offline Confiável para Mensagens e Arquivos)
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

			$stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
			$stmt->execute([$username]);
			$existingUser = $stmt->fetch();

			if ($existingUser) {
				$stmt = $db->prepare("UPDATE users SET ip = ?, port = ? WHERE username = ?");
				$stmt->execute([$clientIp, $port, $username]);
				echo "Usuário $username atualizado com IP $clientIp e Porta $port.\n";
			} else {
				$stmt = $db->prepare("INSERT INTO users (username, ip, port) VALUES (?, ?, ?)");
				$stmt->execute([$username, $clientIp, $port]);
				echo "Usuário $username registrado com IP $clientIp e Porta $port.\n";
			}
			
			fwrite($connection, json_encode(["type"=>"info", "msg"=>"Login/Registro bem-sucedido."]) . "\n");

			deliverOfflineMessages($username, $clientIp, $port);
			break;

		case 'private':
			$from = $msg['from'];
			$to = $msg['to'];
			$text = $msg['message'];
			$recipient = getUserAddress($to);
			$wasDelivered = false;

			if ($recipient) {
				echo "Tentando entregar mensagem de $from para $to em tempo real...\n";
				$payload = ["type"=>"private", "from"=>$from, "msg"=>$text];
				$wasDelivered = sendMessageToClient($recipient['ip'], $recipient['port'], $payload);
			}
			if (!$wasDelivered) {
				echo "Usuário '$to' está offline ou inalcançável. Mensagem armazenada.\n";
			}
			$stmt = $db->prepare("INSERT INTO messages (sender,receiver,type,content,delivered) VALUES (?,?,?,?,?)");
			$stmt->execute([$from, $to, 'text', $text, $wasDelivered ? 1 : 0]);
			break;

		case 'group':
			// Lógica de grupo atualizada para criar uma entrada por destinatário
			$from = $msg['from'];
			$gname = $msg['group'];
			$text = $msg['message'];

			$stmt = $db->prepare("SELECT u.username FROM group_members gm JOIN groups g ON gm.group_id=g.id JOIN users u ON gm.user_id=u.id WHERE g.name=? AND u.username != ?");
			$stmt->execute([$gname, $from]);
			$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

			foreach ($members as $member) {
				$wasDelivered = false;
				$recipient = getUserAddress($member['username']);
				if ($recipient) {
					$payload = ["type"=>"group", "from"=>$from, "group"=>$gname, "msg"=>$text];
					$wasDelivered = sendMessageToClient($recipient['ip'], $recipient['port'], $payload);
				}
				if (!$wasDelivered) {
					echo "Membro '{$member['username']}' do grupo '$gname' está offline. Mensagem armazenada.\n";
				}
				// Insere um registro de mensagem para cada membro
				$db->prepare("INSERT INTO messages (sender,receiver,groupname,type,content,delivered) VALUES (?,?,?,?,?,?)")
				   ->execute([$from, $member['username'], $gname, 'text', $text, $wasDelivered ? 1 : 0]);
			}
			break;
		
		case 'file_private':
			$from = $msg['from'];
			$to = $msg['to'];
			$fname = $msg['name'];
			$fsize = $msg['size'];
			
			$data = readBytes($connection, $fsize);
			if (strlen($data) !== $fsize) { echo "Erro: falha ao receber o arquivo completo.\n"; break; }

			if (!is_dir("uploads/{$from}")) mkdir("uploads/{$from}", 0777, true);
			$path = "uploads/{$from}/".uniqid()."_".$fname;
			file_put_contents($path, $data);
			
			$recipient = getUserAddress($to);
			$wasDelivered = false;

			if ($recipient) {
				echo "Encaminhando arquivo para $to...\n";
				$payload = ["type"=>"file", "from"=>$from, "name"=>$fname, "size"=>$fsize];
				$wasDelivered = sendFileToClient($recipient['ip'], $recipient['port'], $payload, $path);
			}
			if (!$wasDelivered) {
				echo "Usuário '$to' offline. Arquivo armazenado para entrega posterior.\n";
			}
			$db->prepare("INSERT INTO messages (sender,receiver,type,filename,content,delivered) VALUES (?,?,?,?,?,?)")
			   ->execute([$from,$to,'file',$fname,$path, $wasDelivered ? 1 : 0]);
			break;

		case 'file_group':
			$from = $msg['from'];
			$gname = $msg['group'];
			$fname = $msg['name'];
			$fsize = $msg['size'];
			
			$data = readBytes($connection, $fsize);
			if (strlen($data) !== $fsize) { echo "Erro: falha ao receber o arquivo completo.\n"; break; }

			if (!is_dir("uploads/{$from}")) mkdir("uploads/{$from}", 0777, true);
			$path = "uploads/{$from}/".uniqid()."_".$fname;
			file_put_contents($path, $data);
			
			$stmt = $db->prepare("SELECT u.username FROM group_members gm JOIN groups g ON gm.group_id=g.id JOIN users u ON gm.user_id=u.id WHERE g.name=? AND u.username != ?");
			$stmt->execute([$gname, $from]);
			$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

			foreach ($members as $member) {
				$recipient = getUserAddress($member['username']);
				$wasDelivered = false;
				if ($recipient) {
					echo "Encaminhando arquivo para {$member['username']} do grupo $gname...\n";
					$payload = ["type"=>"file", "from"=>$from, "group"=>$gname, "name"=>$fname, "size"=>$fsize];
					$wasDelivered = sendFileToClient($recipient['ip'], $recipient['port'], $payload, $path);
				}
				if (!$wasDelivered) {
					 echo "Membro '{$member['username']}' offline. Arquivo do grupo '$gname' armazenado.\n";
				}
				$db->prepare("INSERT INTO messages (sender,receiver,groupname,type,filename,content,delivered) VALUES (?,?,?,?,?,?,?)")
				   ->execute([$from, $member['username'], $gname, 'file', $fname, $path, $wasDelivered ? 1 : 0]);
			}
			break;

		// ... (case 'create_group' inalterado)
		case 'create_group':
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
	}
}

/**
 * Função ATUALIZADA para entregar mensagens e arquivos pendentes.
 */
function deliverOfflineMessages($username, $ip, $port) {
	global $db;
	sleep(1);
	echo "Verificando mensagens e arquivos offline para $username...\n";

	// Busca TODAS as mensagens/arquivos não entregues destinados a este usuário
	$stmt = $db->prepare("SELECT * FROM messages WHERE receiver = ? AND delivered = 0");
	$stmt->execute([$username]);

	while ($msg = $stmt->fetch(PDO::FETCH_ASSOC)) {
		$wasDelivered = false;

		// Verifica o tipo de item a ser entregue (texto ou arquivo)
		if ($msg['type'] === 'text') {
			echo "Tentando entregar mensagem pendente de {$msg['sender']}...\n";
			// Reconstrói o payload baseado se é uma mensagem de grupo ou privada
			if ($msg['groupname']) { // É uma mensagem de grupo
				$payload = ["type"=>"group", "from"=>$msg['sender'], "group"=>$msg['groupname'], "msg"=>$msg['content']];
			} else { // É uma mensagem privada
				$payload = ["type"=>"private", "from"=>$msg['sender'], "msg"=>$msg['content']];
			}
			$wasDelivered = sendMessageToClient($ip, $port, $payload);

		} elseif ($msg['type'] === 'file') {
			echo "Tentando entregar arquivo pendente '{$msg['filename']}' de {$msg['sender']}...\n";
			$filePath = $msg['content'];
			if (file_exists($filePath)) {
				 if ($msg['groupname']) { // É um arquivo de grupo
					$payload = ["type"=>"file", "from"=>$msg['sender'], "group"=>$msg['groupname'], "name"=>$msg['filename'], "size"=>filesize($filePath)];
				} else { // É um arquivo privado
					$payload = ["type"=>"file", "from"=>$msg['sender'], "name"=>$msg['filename'], "size"=>filesize($filePath)];
				}
				$wasDelivered = sendFileToClient($ip, $port, $payload, $filePath);
			} else {
				 echo "ERRO: Arquivo '{$filePath}' não encontrado no servidor para reenvio.\n";
			}
		}
		
		// Se a entrega foi bem-sucedida, atualiza o status no banco
		if ($wasDelivered) {
			$db->prepare("UPDATE messages SET delivered = 1 WHERE id = ?")->execute([$msg['id']]);
			echo "Entrega bem-sucedida.\n";
		} else {
			echo "Falha na entrega. O item permanecerá pendente.\n";
		}
	}
}

function sendMessageToClient($ip, $port, $payload) {
	$clientAddr = "tcp://$ip:$port";
	$fp = @stream_socket_client($clientAddr, $errno, $errstr, 3);
	if ($fp) {
		fwrite($fp, json_encode($payload) . "\n");
		fclose($fp);
		return true;
	} else {
		echo "Falha ao conectar no cliente $ip:$port - $errstr ($errno)\n";
		return false;
	}
}

function sendFileToClient($ip, $port, $payload, $filePath) {
	$clientAddr = "tcp://$ip:$port";
	$fp = @stream_socket_client($clientAddr, $errno, $errstr, 10);
	if ($fp) {
		fwrite($fp, json_encode($payload) . "\n");
		$fileStream = fopen($filePath, 'r');
		stream_copy_to_stream($fileStream, $fp);
		fclose($fileStream);
		fclose($fp);
		return true;
	} else {
		echo "Falha ao enviar arquivo para $ip:$port - $errstr ($errno)\n";
		return false;
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