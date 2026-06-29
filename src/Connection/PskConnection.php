<?php

namespace Fliix\ZabbixSender\Connection;

use Fliix\ZabbixSender\Resolver\OptionsResolver;
use RuntimeException;

use function is_resource;
use function sprintf;
use function strlen;
use function substr;
use function trim;

/**
 * Implements PSK (Pre-Shared Key) TLS connections to Zabbix Server.
 *
 * Uses TLS 1.2 with PSK cipher suites via the system's openssl binary.
 * TLS 1.3 is explicitly disabled because PSK cipher suites (RFC 4279) are
 * not supported in TLS 1.3 and are disabled by default in OpenSSL 3.x.
 *
 * Compatible with Zabbix 7.x.
 *
 * @internal
 */
final class PskConnection implements ConnectionInterface
{
	private const DEFAULT_PSK_CIPHER_LIST = 'PSK-AES128-GCM-SHA256:PSK-AES256-GCM-SHA384:PSK-AES128-CBC-SHA256:PSK-AES256-CBC-SHA384:PSK-AES128-CBC-SHA:PSK-AES256-CBC-SHA';

	/**
	 * @var resource|null
	 */
	private $process = null;

	private array $pipes = [];

	private string $command;

	private string $endpoint;

	private string $cipherList;

	private array $options;


	public function __construct(array $options)
	{
		$this->options = OptionsResolver::resolve($options);
		$options = $this->options;

		// Use TLS 1.2 explicitly: PSK cipher suites are part of TLS 1.2 (RFC 4279)
		// and are not available in TLS 1.3. OpenSSL 3.x disables them by default,
		// so we force TLS 1.2 and specify an accepted cipher for Zabbix compatibility.
		$this->endpoint = sprintf('%s:%d', $options['server'], (int) $options['port']);
		$this->cipherList = $options['tls-cipher'] ?? self::DEFAULT_PSK_CIPHER_LIST;

		$this->command = sprintf(
			'openssl s_client -quiet -connect %s -psk_identity %s -psk %s -tls1_2 -cipher %s',
			escapeshellarg($this->endpoint),
			escapeshellarg($options['tls-psk-identity']),
			escapeshellarg($options['tls-psk']),
			escapeshellarg($this->cipherList)
		);
	}

	/**
	 * @inheritDoc
	 */
	public function open(): void
	{
		if ($this->process !== null) {
			throw new RuntimeException('Connection is already open.');
		}

		$descriptors = [
			0 => ['pipe', 'r'], // stdin
			1 => ['pipe', 'w'], // stdout
			2 => ['pipe', 'w'], // stderr
		];

		$this->process = proc_open($this->command, $descriptors, $this->pipes);

		if (!is_resource($this->process)) {
			throw new RuntimeException('Failed to open connection.');
		}

		$timeout = max(1, (int) ($this->options['timeout'] ?? 30));
		foreach ($this->pipes as $pipe) {
			if (is_resource($pipe)) {
				stream_set_timeout($pipe, $timeout);
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function read(): false|string
	{
		if ($this->process === null || !isset($this->pipes[1])) {
			return false;
		}

		$output = $this->readStream($this->pipes[1]);

		$errorOutput = '';
		if (isset($this->pipes[2])) {
			$errorOutput = trim($this->readStream($this->pipes[2]) ?: '');
		}

		if ($output === false || $output === '') {
			if ($errorOutput !== '') {
				$message = sprintf(
					'Failed to read PSK response from %s using TLS 1.2 and cipher list "%s": %s',
					$this->endpoint,
					$this->cipherList,
					$errorOutput
				);

				if (
					str_contains($errorOutput, 'alert handshake failure')
					|| str_contains($errorOutput, 'no cipher match')
					|| str_contains($errorOutput, 'no ciphers available')
				) {
					$message .= ' Check whether Zabbix frontend/server TLS PSK settings match (tls_connect=PSK, same PSK identity/key) and, if needed, set option "tls-cipher" to the cipher accepted by your Zabbix server.';
				}

				throw new RuntimeException($message);
			}

			return false;
		}

		return $output;
	}

	/**
	 * @inheritDoc
	 */
	public function write(string $data): false|int
	{
		if ($this->process === null || !isset($this->pipes[0])) {
			return false;
		}

		$bytesWritten = 0;
		$dataLength = strlen($data);

		while ($bytesWritten < $dataLength) {
			$written = fwrite($this->pipes[0], substr($data, $bytesWritten));
			if ($written === false || $written === 0) {
				return false;
			}

			$bytesWritten += $written;
		}

		fflush($this->pipes[0]);
		fclose($this->pipes[0]);
		unset($this->pipes[0]);

		return $bytesWritten;
	}

	/**
	 * @inheritDoc
	 */
	public function close(): void
	{
		if ($this->process === null) {
			return;
		}

		foreach ($this->pipes as $pipe) {
			if (is_resource($pipe)) {
				fclose($pipe);
			}
		}

		proc_close($this->process);
		$this->process = null;
		$this->pipes = [];
	}

	private function readStream(mixed $stream): false|string
	{
		if (!is_resource($stream)) {
			return false;
		}

		$data = '';
		while (!feof($stream)) {
			$chunk = fread($stream, 8192);
			if ($chunk === false) {
				return false;
			}
			if ($chunk === '') {
				$meta = stream_get_meta_data($stream);
				if ($meta['timed_out'] ?? false) {
					break;
				}

				continue;
			}
			$data .= $chunk;
		}

		return $data;
	}
}
