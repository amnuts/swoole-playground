<?php

use Swoole\Websocket\Server;
use Swoole\WebSocket\Frame;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Table;

$host = getenv('HOST');
$hostname = getenv('HOSTNAME');
$port = getenv('PORT');

// Table is a shared memory table that can be used across connections
$messages = new Table(1024);
// we need to set the types that the table columns support - just like a RDB
$messages->column('id', Table::TYPE_INT, 11);
$messages->column('client', Table::TYPE_INT, 4);
$messages->column('username', Table::TYPE_STRING, 64);
$messages->column('message', Table::TYPE_STRING, 255);
$messages->column('room', Table::TYPE_STRING, 64);
$messages->create();

$connections = new Table(1024);
$connections->column('client', Table::TYPE_INT, 4);
$connections->column('room', Table::TYPE_STRING, 64);
$connections->create();

$server = new Server($host, $port, SWOOLE_PROCESS, SWOOLE_SOCK_TCP | SWOOLE_SSL);
$server->set([
    'ssl_cert_file' => __DIR__ . '/certs/self-signed-example.crt',
    'ssl_key_file' => __DIR__ . '/certs/self-signed-example.key',
    'open_http2_protocol' => true
]);


$server->on('start', function (Server $server) use ($hostname, $port) {
    echo sprintf('Swoole HTTP server is started at http://%s:%s' . PHP_EOL, $hostname, $port);
});

$server->on('open', function (Server $server, Request $request) use ($messages, $connections) {
    echo "connection open: {$request->fd}\n";

    // store the client on our memory table
    $connections->set($request->fd, ['client' => $request->fd]);

    // update all the client with the existing messages
    foreach ($messages as $row) {
        if (empty($row['room'])) {
            $server->push($request->fd, json_encode($row));
        }
    }
});

// we can also run a regular HTTP server at the same time!
$server->on('request', function (Request $request, Response $response) {
    $response->header('Content-Type', 'text/html');
    $response->end(file_get_contents(__DIR__ . '/public/websocket.html'));
});

$server->on('message', function (Server $server, Frame $frame) use ($messages, $connections) {
    echo "receive from {$frame->fd}:{$frame->data},opcode:{$frame->opcode},fin:{$frame->finish}\n";

    // frame data comes in as a string
    $output = json_decode($frame->data, true);

    $connections->set($frame->fd, ['room' => $output['room']]);

    // assign a "unique" id for this message
    $output['id'] = time();
    $output['client'] = $frame->fd;

    // now we can store the message in the Table
    $messages->set($output['username'] . time(), $output);

    // now we notify any of the connected clients
    foreach ($connections as $client) {
        if (empty($output['room']) || $client['room'] === $output['room']) {
            $server->push($client['client'], json_encode($output));
        }
    }
});

$server->on('close', function (Server $server, int $client) use ($connections) {
    echo "client {$client} closed\n";
    $connections->del($client);
});

$server->start();

