<?php
// test_clients.php - simulação de 5 clientes para teste automático
$addr = 'tcp://127.0.0.1:8888';
$names = ['brenda','carol','halys','vagner','victor'];
$clients = [];

// conectar e logar cada cliente
foreach ($names as $name) {
	$fp = @stream_socket_client($addr, $errno, $errstr, 2);
	if (!$fp) { echo "Erro ao conectar $name: $errstr ($errno)\\n"; exit(1); }
	stream_set_blocking($fp, false);
	$clients[$name] = $fp;
	fwrite($fp, json_encode(['type'=>'login','username'=>$name]) . "\n");
	usleep(150000);
}

// Brenda envia para Carol
fwrite($clients['brenda'], json_encode(['type'=>'private','to'=>'carol','message'=>'Oi Carol! Sou Brenda (simulação).']) . "\n\n\n");
usleep(150000);

// Halys responde
fwrite($clients['halys'], json_encode(['type'=>'private','to'=>'brenda','message'=>'Oi Brenda! Recebido.']) . "\n\n\n");
usleep(150000);

// Criar grupo e enviar mensagem de grupo
fwrite($clients['brenda'], json_encode(['type'=>'create_group','name'=>'fam','members'=>['brenda','carol','halys']]) . "\n\n\n");
usleep(150000);
fwrite($clients['carol'], json_encode(['type'=>'group','group'=>'fam','message'=>'Olá família (simulação)!']) . "\n\n\n");
usleep(150000);

// Vagner envia um "arquivo" pequeno para Victor!
$data = "Conteudo curto de arquivo (simulacao)\n";
fwrite($clients['vagner'], json_encode(['type'=>'file','to'=>'victor','name'=>'teste_sim.txt','size'=>strlen($data)]) . "\n\n\n");
fwrite($clients['vagner'], $data);
usleep(300000);

// Ler respostas por 3 segundos
$start = time();
while (time() - $start < 3) {
	foreach ($clients as $name => $fp) {
		$line = @fgets($fp);
		if ($line !== false && trim($line) !== '') {
			echo "[$name] RECV: " . trim($line) . "\n";
			// se receber header de arquivo, tentar ler o corpo (opcional)
			$msg = @json_decode(trim($line), true);
			if ($msg && isset($msg['type']) && $msg['type'] === 'file' && isset($msg['size'])) {
				$size = (int)$msg['size'];
				$buf = '';
				$remaining = $size;
				while ($remaining > 0) {
					$chunk = fread($fp, min(8192, $remaining));
					if ($chunk === false || $chunk === '') break;
					$buf .= $chunk;
					$remaining -= strlen($chunk);
				}
				echo "[$name] RECEBEU ARQUIVO (" . ($msg['name'] ?? '') . ") tamanho=" . strlen($buf) . "\n\n\n";
			}
		}
	}
	usleep(100000);
}

// fechar conexões
foreach ($clients as $fp) fclose($fp);
echo "Simulação finalizada.\n\n\n";
