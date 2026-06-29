<?php

namespace Fliix\ZabbixSender\Connection;

use Fliix\ZabbixSender\Resolver\OptionsResolver;
use RuntimeException;

use function is_resource;
use function strlen;

/**
 * Implements no encryption connections from host.
 *
 * @internal
 */
final class UnencryptedConnection implements ConnectionInterface
{
	/**
	 * @var ?resource The resource of the connection.
	 */
	private $socket;

	/**
	 * @var array Connection options
	 */
	private array $options;

	public function __construct(array $options = [])
	{
		$this->options = OptionsResolver::resolve($options);
	}

	/**
	 * @inheritDoc
	 */
	public function write(string $data): false|int
	{
		if ($this->socket === null) {
			return false;
		}

		$bytesWritten = 0;
		$length = strlen($data);
		while ($bytesWritten < $length) {
			$written = @fwrite($this->socket, $data);
			if ($written === false) {
				return false;
			}


			$bytesWritten += $written;
			$data = substr($data, $written);
		}

		return $bytesWritten;
	}

	/**
	 * @inheritDoc
	 */
	public function open(): void
	{
		if ($this->socket !== null) {
			throw new RuntimeException('Connection is already open.');
		}

		$this->socket = @fsockopen(
			$this->options['server'],
			$this->options['port'],
			$error_code,
			$error_message,
			5
		);

		if (!is_resource($this->socket)) {
			$message = !empty($error_message)
				? "Failed to open connection. Error: $error_message"
				: 'Failed to open connection.';
			throw new RuntimeException($message);
		}

		$timeout = (int) ($this->options['timeout'] ?? 30);
		stream_set_timeout($this->socket, max(1, $timeout));
	}

	/**
	 * @inheritDoc
	 */
	public function read(): false|string
	{
		if ($this->socket === null) {
			return false;
		}

		$data = "";
		while (!feof($this->socket)) {
			$buffer = fread($this->socket, 8192);
			if ($buffer === false) {
				return false;
			}
			if ($buffer === '') {
				$meta = stream_get_meta_data($this->socket);
				if ($meta['timed_out'] ?? false) {
					return false;
				}

				break;
			}
			$data .= $buffer;
		}

		return $data !== '' ? $data : false;
	}

	/**
	 * @inheritDoc
	 */
	public function close(): void
	{
		if ($this->socket === null) {
			return;
		}

		fclose($this->socket);
		$this->socket = null;
	}
}
