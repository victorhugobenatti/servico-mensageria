<?php
// test_clients.php - simula 5 clientes para teste automático
$addr = 'tcp://127.0.0.1:12345';
$names = ['alice','bob','carol','dave','eve'];
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

// Alice envia para Bob
fwrite($clients['alice'], json_encode(['type'=>'private','to'=>'bob','message'=>'Oi Bob! Sou Alice (simulação).']) . "\n");
usleep(150000);

// Bob responde
fwrite($clients['bob'], json_encode(['type'=>'private','to'=>'alice','message'=>'Oi Alice! Recebido.']) . "\n");
usleep(150000);

// Criar grupo e enviar mensagem de grupo
fwrite($clients['alice'], json_encode(['type'=>'create_group','name'=>'fam','members'=>['alice','bob','carol']]) . "\n");
usleep(150000);
fwrite($clients['carol'], json_encode(['type'=>'group','group'=>'fam','message'=>'Olá família (simulação)!']) . "\n");
usleep(150000);

// Dave envia um "arquivo" pequeno para Eve
$data = "Conteudo curto de arquivo (simulacao)\n";
fwrite($clients['dave'], json_encode(['type'=>'file','to'=>'eve','name'=>'teste_sim.txt','size'=>strlen($data)]) . "\n");
fwrite($clients['dave'], $data);
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
                echo "[$name] RECEBEU ARQUIVO (" . ($msg['name'] ?? '') . ") tamanho=" . strlen($buf) . "\n";
            }
        }
    }
    usleep(100000);
}

// fechar conexões
foreach ($clients as $fp) fclose($fp);
echo "Simulação finalizada.\n";
