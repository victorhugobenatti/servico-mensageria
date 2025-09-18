<?php
/**
 * server.php - Servidor de chat estilo WhatsApp em PHP + SQLite
 *
 * Funcionalidades:
 * - Login de usuários
 * - Mensagens privadas
 * - Mensagens em grupo
 * - Envio de arquivos (via header JSON + bytes)
 * - Persistência em SQLite
 *
 * Rodar com: php server.php
 */

set_time_limit(0);
$addr = 'tcp://0.0.0.0:8888';
$server = @stream_socket_server($addr, $errno, $errstr);
if (!$server) die("Erro: $errstr ($errno)\n");
stream_set_blocking($server, false);

$clients = [];
$userMap = [];

// Conexão ao banco SQLite
$db = new PDO('sqlite:chat.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

initDb($db);

echo "Servidor ouvindo $addr...\n";

while (true) {
	$reads = [$server];

	foreach ($clients as $c){
		$reads[] = $c['stream'];
	}

	$write = $except = null;

	if (stream_select($reads, $write, $except, 0, 200000) === false) 
		break;

	// nova conexão
	if (in_array($server, $reads, true)) {
		$new = @stream_socket_accept($server, 0);
		
		if ($new) {
			stream_set_blocking($new, false);
			$id = (int)$new;
			$clients[$id] = ['stream'=>$new, 'username'=>null];
			fwrite($new, json_encode(["type"=>"welcome","msg"=>"Conectado"]) . "\n");
			echo "Novo cliente $id conectado.\n";
		}
		$idx = array_search($server, $reads, true);
		unset($reads[$idx]);
	}

	// tratar mensagens dos clientes
	foreach ($reads as $read) {
		$id = (int)$read;
		$line = fgets($read);

		// cliente desconectou-se
		if ($line === false) {
			disconnectClient($id);
			continue;
		}

		$line = trim($line);

		if ($line === '') 
			continue;

		$msg = json_decode($line, true);

		if (!$msg) 
			continue;

		handleMessage($id, $msg);
	}
}

// ---------------- Funções auxiliares ----------------

function initDb($db) {
	$db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE)");
	$db->exec("CREATE TABLE IF NOT EXISTS groups (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE)");
	$db->exec("CREATE TABLE IF NOT EXISTS group_members (group_id INTEGER, user_id INTEGER, PRIMARY KEY(group_id,user_id))");
	$db->exec("CREATE TABLE IF NOT EXISTS messages (id INTEGER PRIMARY KEY AUTOINCREMENT, sender TEXT, receiver TEXT, groupname TEXT, type TEXT, content TEXT, filename TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, delivered INTEGER DEFAULT 0)");
}

function disconnectClient($id) {
	global $clients, $userMap;
	if (isset($clients[$id]['username'])) {
		$u = $clients[$id]['username'];
		unset($userMap[$u]);
	}
	fclose($clients[$id]['stream']);
	unset($clients[$id]);
	echo "Cliente $id desconectado.\n";
}

function handleMessage($id, $msg) {
	global $clients, $userMap, $db;

	$stream = $clients[$id]['stream'];
	switch ($msg['type']) {
		case 'login':
			$username = $msg['username'];
			$clients[$id]['username'] = $username;
			$userMap[$username] = $id;
			$db->prepare("INSERT OR IGNORE INTO users (username) VALUES (?)")->execute([$username]);
			fwrite($stream, json_encode(["type"=>"login_ok"]) . "\n");
			echo "Usuário $username logado no cliente $id.\n";
			break;

		case 'private':
			$from = $clients[$id]['username'];
			$to = $msg['to'];
			$text = $msg['message'];
			$db->prepare("INSERT INTO messages (sender,receiver,type,content) VALUES (?,?,?,?)")
			   ->execute([$from,$to,'text',$text]);
			if (isset($userMap[$to])) {
				$toId = $userMap[$to];
				fwrite($clients[$toId]['stream'], json_encode(["type"=>"private","from"=>$from,"message"=>$text]) . "\n");
			}
			break;

		case 'group':
			$from = $clients[$id]['username'];
			$gname = $msg['group'];
			$text = $msg['message'];
			$db->prepare("INSERT INTO messages (sender,groupname,type,content) VALUES (?,?,?,?)")
			   ->execute([$from,$gname,'text',$text]);
			// buscar membros do grupo
			$stmt = $db->prepare("SELECT u.username FROM group_members gm JOIN groups g ON gm.group_id=g.id JOIN users u ON gm.user_id=u.id WHERE g.name=?");
			$stmt->execute([$gname]);
			while ($row = $stmt->fetch()) {
				$to = $row['username'];
				if (isset($userMap[$to])) {
					$toId = $userMap[$to];
					fwrite($clients[$toId]['stream'], json_encode(["type"=>"group","from"=>$from,"group"=>$gname,"message"=>$text]) . "\n");
				}
			}
			break;

		case 'create_group':
			$gname = $msg['name'];
			$members = $msg['members'];
			$db->prepare("INSERT OR IGNORE INTO groups (name) VALUES (?)")->execute([$gname]);
			$gid = $db->lastInsertId();
			foreach ($members as $m) {
				$uid = getUserId($m);
				if ($uid) {
					$db->prepare("INSERT OR IGNORE INTO group_members (group_id,user_id) VALUES (?,?)")->execute([$gid,$uid]);
				}
			}
			fwrite($stream, json_encode(["type"=>"group_created","name"=>$gname]) . "\n");
			break;

		case 'file':
			$from = $clients[$id]['username'];
			$to = $msg['to'];
			$fname = $msg['name'];
			$size = $msg['size'];
			$data = readBytes($stream,$size);
			if (!is_dir("uploads")) mkdir("uploads");
			$path = "uploads/".uniqid()."_".$fname;
			file_put_contents($path,$data);
			$db->prepare("INSERT INTO messages (sender,receiver,type,filename,content) VALUES (?,?,?,?,?)")
			   ->execute([$from,$to,'file',$fname,$path]);
			if (isset($userMap[$to])) {
				$toId = $userMap[$to];
				fwrite($clients[$toId]['stream'], json_encode(["type"=>"file","from"=>$from,"name"=>$fname,"size"=>$size]) . "\n");
				fwrite($clients[$toId]['stream'], $data);
			}
			break;
	}
}

function readBytes($stream, $len) {
	$data = '';
	$remaining = $len;
	while ($remaining > 0) {
		$chunk = fread($stream, min(8192, $remaining));
		if ($chunk === false || $chunk === '') break;
		$data .= $chunk;
		$remaining -= strlen($chunk);
	}
	return $data;
}

function getUserId($username) {
	global $db;
	$stmt = $db->prepare("SELECT id FROM users WHERE username=?");
	$stmt->execute([$username]);
	$r = $stmt->fetch();
	return $r ? $r['id'] : null;
}