<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Source RCON client (Valve protocol) with a short timeout.
 *
 * Why it matters: the original panel blocks the request while waiting for RCON,
 * which leads to timeouts and 500 errors when the server does not respond. Here we use
 * an explicit socket timeout, so a dead server no longer blocks the panel.
 */
final class Rcon
{
    private const SERVERDATA_AUTH = 3;
    private const SERVERDATA_EXECCOMMAND = 2;
    private const SERVERDATA_AUTH_RESPONSE = 2;
    private const SERVERDATA_RESPONSE_VALUE = 0;

    private $socket = null;
    private int $requestId = 0;
    private bool $authed = false;

    public function __construct(
        private string $host,
        private int $port,
        private string $password,
        private int $timeout = 3
    ) {
    }

    /**
     * Open the connection and authenticate.
     * @throws \RuntimeException
     */
    public function connect(): void
    {
        $errno = 0;
        $errstr = '';
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        if (!$this->socket) {
            throw new \RuntimeException("RCON: cannot connect to {$this->host}:{$this->port} ({$errstr})");
        }
        stream_set_timeout($this->socket, $this->timeout);

        $authId = $this->write(self::SERVERDATA_AUTH, $this->password);

        // Source answers AUTH with an empty SERVERDATA_RESPONSE_VALUE first and
        // only then the real SERVERDATA_AUTH_RESPONSE. Reading a single packet
        // here left the second one buffered, so the first command() consumed it
        // and always returned an empty body.
        $response = null;
        $lastPacket = null;
        for ($i = 0; $i < 8; $i++) {
            $packet = $this->read();
            if ($packet === null) {
                break;
            }
            $lastPacket = $packet;
            if ($packet['type'] === self::SERVERDATA_AUTH_RESPONSE) {
                $response = $packet;
                break;
            }
        }

        if ($response === null) {
            $this->disconnect();
            $last = $lastPacket === null
                ? 'no authentication response before timeout'
                : 'unexpected packet id=' . $lastPacket['id'] . ' type=' . $lastPacket['type'];
            throw new \RuntimeException('RCON: ' . $last . '. Check that TCP RCON is enabled on this server and that the configured address/port uses the RCON listener.');
        }
        if ($response['id'] === -1) {
            $this->disconnect();
            throw new \RuntimeException('RCON: the server rejected authentication (auth response id -1).');
        }
        if ($response['id'] !== $authId) {
            $this->disconnect();
            throw new \RuntimeException('RCON: unexpected authentication response id=' . $response['id'] . ' type=' . $response['type'] . ' (expected id=' . $authId . ').');
        }
        $this->authed = true;
    }

    /**
     * Execute a command and return the server response.
     */
    public function command(string $command): string
    {
        if (!$this->authed) {
            throw new \RuntimeException('RCON: not authenticated.');
        }
        $cmdId = $this->write(self::SERVERDATA_EXECCOMMAND, $command);
        // A long reply is split over several packets with no length hint, so an
        // empty follow-up request is sent: its echo marks the end of the reply.
        // CS2 may answer that marker before the command output, so the marker
        // only ends reading once output arrived; then a short grace period
        // collects trailing packets.
        $sentinelId = $this->write(self::SERVERDATA_RESPONSE_VALUE, '');

        $body = '';
        $sentinelSeen = false;
        $deadline = microtime(true) + $this->timeout;
        try {
            while (true) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    break;
                }
                $wait = $sentinelSeen && $body !== '' ? min(0.3, $remaining) : $remaining;
                stream_set_timeout($this->socket, (int) $wait, (int) (($wait - floor($wait)) * 1000000));
                $packet = $this->read();
                if ($packet === null) {
                    break;
                }
                if ($packet['id'] === $sentinelId) {
                    $sentinelSeen = true;
                    continue;
                }
                if ($packet['id'] === $cmdId || $packet['type'] === self::SERVERDATA_RESPONSE_VALUE) {
                    $body .= $packet['body'];
                }
            }
        } finally {
            if (is_resource($this->socket)) {
                stream_set_timeout($this->socket, $this->timeout);
            }
        }
        return $body;
    }

    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
        $this->authed = false;
    }

    /** Sends a packet and returns the request id it was sent with. */
    private function write(int $type, string $body): int
    {
        $this->requestId++;
        $data = pack('VV', $this->requestId, $type) . $body . "\x00\x00";
        $packet = pack('V', strlen($data)) . $data;
        $offset = 0;
        while ($offset < strlen($packet)) {
            $written = fwrite($this->socket, substr($packet, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('RCON: failed to write the request packet.');
            }
            $offset += $written;
        }
        return $this->requestId;
    }

    /**
     * @return array{id:int, type:int, body:string}|null
     */
    private function read(): ?array
    {
        $sizeData = '';
        while (strlen($sizeData) < 4) {
            $chunk = fread($this->socket, 4 - strlen($sizeData));
            if ($chunk === false || $chunk === '' || stream_get_meta_data($this->socket)['timed_out']) {
                return null;
            }
            $sizeData .= $chunk;
        }
        $size = unpack('V', $sizeData)[1];
        // CS2 can exceed the classic 4096-byte Source body limit.
        if ($size < 10 || $size > 1048576) {
            return null;
        }

        $data = '';
        $remaining = $size;
        while ($remaining > 0) {
            $chunk = fread($this->socket, $remaining);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);

            $meta = stream_get_meta_data($this->socket);
            if ($meta['timed_out']) {
                break;
            }
        }

        if (strlen($data) < 8) {
            return null;
        }
        $id = unpack('V', substr($data, 0, 4))[1];
        if ($id > 0x7FFFFFFF) {
            $id -= 0x100000000;
        }
        $type = unpack('V', substr($data, 4, 4))[1];
        $body = substr($data, 8, -2);

        return ['id' => $id, 'type' => $type, 'body' => $body];
    }
}
