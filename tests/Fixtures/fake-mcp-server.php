<?php

// A tiny MCP server on stdio, for the gateway tests. FAKE_DESCRIPTION changes the description of send_email;
// every call that reaches it is written to FAKE_LOG.

$tools = [
    ['name' => 'send_email', 'description' => getenv('FAKE_DESCRIPTION') ?: 'Send an email.', 'inputSchema' => ['type' => 'object', 'properties' => ['to' => ['type' => 'string'], 'body' => ['type' => 'string']]]],
    ['name' => 'delete_email', 'description' => 'Delete an email.', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]]],
    ['name' => 'pay', 'description' => 'Pay an invoice.', 'inputSchema' => ['type' => 'object', 'properties' => ['amount' => ['type' => 'number']]]],
];
while (false !== ($line = fgets(\STDIN))) {
    $msg = json_decode($line, true);
    if (!isset($msg['id'])) {
        continue;
    }
    $result = match ($msg['method'] ?? '') {
        'initialize' => ['protocolVersion' => '2025-06-18', 'capabilities' => ['tools' => (object) []], 'serverInfo' => ['name' => 'fake', 'version' => '1']],
        'tools/list' => ['tools' => $tools],
        'tools/call' => (static function (array $p): array {
            file_put_contents((string) getenv('FAKE_LOG'), json_encode($p)."\n", \FILE_APPEND);
            $args = (array) ($p['arguments'] ?? []);
            ksort($args);

            return ['content' => [['type' => 'text', 'text' => 'done '.$p['name'].' '.json_encode($args)]]];
        })($msg['params']),
        default => (object) [],
    };
    fwrite(\STDOUT, json_encode(['jsonrpc' => '2.0', 'id' => $msg['id'], 'result' => $result])."\n");
    fflush(\STDOUT);
}
