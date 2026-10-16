<?php

namespace Tests\Feature;

use App\Support\Documentos\Antivirus;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AntivirusProtocolTest extends TestCase
{
    public function test_clamd_protocol_accepts_only_clean_and_recognizes_infected_responses(): void
    {
        foreach (['stream: OK' => 'limpio', 'stream: Test FOUND' => 'infectado', 'stream: size limit ERROR' => 'error'] as $reply => $expected) {
            $server = new Process([PHP_BINARY, '-r', <<<'PHP'
$server = stream_socket_server('tcp://127.0.0.1:0');
echo stream_socket_get_name($server, false)."\n";
flush();
$client = stream_socket_accept($server, 5);
if (!$client) exit(2);
stream_set_timeout($client, 3);
$header = '';
while (strlen($header) < 10) $header .= fread($client, 10 - strlen($header));
if ($header !== "zINSTREAM\0") exit(3);
do {
    $prefix = '';
    while (strlen($prefix) < 4) $prefix .= fread($client, 4 - strlen($prefix));
    $length = unpack('N', $prefix)[1];
    $read = 0;
    while ($read < $length) $read += strlen(fread($client, $length - $read));
} while ($length > 0);
fwrite($client, $argv[1]."\0");
fclose($client);
fclose($server);
PHP, $reply]);
            try {
                $server->setTimeout(8)->start();
                $server->waitUntil(fn () => str_contains($server->getOutput(), "\n"));
                config(['antivirus.endpoint' => 'tcp://'.trim($server->getOutput()), 'antivirus.timeout_seconds' => 3, 'antivirus.max_bytes' => 20480]);
                $stream = fopen('php://temp', 'w+b');
                try {
                    fwrite($stream, str_repeat('x', 16384));
                    rewind($stream);
                    $this->assertSame($expected, app(Antivirus::class)->analizar($stream));
                } finally {
                    fclose($stream);
                }
                $this->assertSame(0, $server->wait(), $server->getErrorOutput());
            } finally {
                $server->stop();
            }
        }
    }
}
